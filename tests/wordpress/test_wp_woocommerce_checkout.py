"""Real WooCommerce → BareBits checkout, end to end.

Every other WordPress test drives BareBits' own routes directly. This one puts
the *actual* third-party BTCPay Greenfield WooCommerce plugin in front of it and
plays the whole customer journey:

    seed a BareBits store
      -> run BareBits' own auto-wiring (cashupay_configure_btcpay_plugin)
      -> a guest places a real order through the WooCommerce Store API, paying
         with the BTCPay gateway
      -> the gateway calls BareBits' Greenfield API and creates a Lightning
         invoice (loopback, same php -S server — hence multi-worker)
      -> we pay the bolt11 from a second LND node
      -> BareBits settles and fires the signed `InvoiceSettled` webhook back to
         WooCommerce's wc-api endpoint
      -> the order flips to a paid state.

Nothing here is simulated: the invoice is created by the real plugin, the
webhook signature is produced by BareBits and verified by the real plugin, and
the order status transition is WooCommerce's own `payment_complete()`. This is
the seam the standalone and WP-onboarding tiers cannot cover, because it needs
the foreign plugin actually installed and talking to us.
"""
from __future__ import annotations

import json
import time

import pytest
import requests

from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

STORE_API = "/wp-json/wc/store/v1"
GATEWAY_ID = "btcpaygf_default"


def _flush_rewrites(wp: WordPressHandle) -> None:
    """WooCommerce's Store API and BareBits' /cashupay/api routes both need
    pretty permalinks active."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def _seed_store(wp: WordPressHandle, mint_url: str) -> tuple[str, str]:
    """Create a configured, sat-denominated BareBits store + API key directly in
    the plugin's DB. Returns (store_id, api_key).

    The seed is generated fresh per run via the same Mnemonic::generate() the
    setup wizard uses. A *fixed* seed would be fatal here: the cashu wallet
    derives deterministic blinded secrets from (seed, counter), so two stores
    sharing a seed against the one session-scoped mint would re-request outputs
    the mint has already signed — minting fails and the invoice hangs at
    Processing instead of settling."""
    snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/auth.php';
require_once CASHUPAY_PLUGIN_DIR . '/cashu-wallet-php/CashuWallet.php';
Database::initialize();
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'WooCommerce Store',
    'mint_url' => {mint_url!r},
    'mint_unit' => 'sat',
    'seed_phrase' => \\Cashu\\Mnemonic::generate(),
    'created_at' => Database::timestamp(),
]);
Config::set('setup_complete', true);
$key = Auth::createApiKey($storeId, 'woocommerce');
echo $storeId . '|' . $key['key'];
"""
    result = wp.wp_cli("eval", snippet)
    line = [ln for ln in result.stdout.strip().splitlines() if "|" in ln][-1]
    store_id, api_key = line.strip().split("|", 1)
    return store_id, api_key


def _autowire_btcpay(wp: WordPressHandle, store_id: str, api_key: str) -> None:
    """Run BareBits' *own* WooCommerce wiring — the code path the setup wizard's
    completion screen triggers — then enable the gateway the way a merchant
    would. cashupay_configure_btcpay_plugin writes the btcpay_gf_* options and
    inserts the webhook row (targeting ?wc-api=btcpaygf_default) into BareBits'
    DB."""
    snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/btcpay-integration.php';
$res = cashupay_configure_btcpay_plugin({store_id!r}, {api_key!r});
// Enable the gateway itself (the merchant's one manual step in WooCommerce).
$settings = get_option('woocommerce_{GATEWAY_ID}_settings', []);
$settings['enabled'] = 'yes';
update_option('woocommerce_{GATEWAY_ID}_settings', $settings);
echo json_encode($res);
"""
    result = wp.wp_cli("eval", snippet)
    body = result.stdout.strip().splitlines()[-1]
    assert '"success":true' in body, f"auto-wiring failed: {body!r} / {result.stderr!r}"


def _place_order(wp: WordPressHandle, product_id: int) -> dict:
    """Drive a real guest checkout through the WooCommerce Store API, selecting
    the BTCPay gateway. Returns the checkout response JSON (order_id, status,
    payment_result)."""
    s = requests.Session()
    base = wp.url + STORE_API

    # Priming GET issues the Store API nonce + a Cart-Token that carries the
    # guest cart across the stateless requests that follow.
    r = s.get(f"{base}/cart", timeout=30)
    r.raise_for_status()
    nonce = r.headers.get("Nonce") or r.headers.get("X-WC-Store-API-Nonce")
    cart_token = r.headers.get("Cart-Token")

    def headers() -> dict:
        h = {}
        if nonce:
            h["Nonce"] = nonce
        if cart_token:
            h["Cart-Token"] = cart_token
        return h

    r = s.post(
        f"{base}/cart/add-item",
        json={"id": product_id, "quantity": 1},
        headers=headers(),
        timeout=30,
    )
    assert r.status_code in (200, 201), f"add-item failed: {r.status_code} {r.text}"
    nonce = r.headers.get("Nonce", nonce)
    cart_token = r.headers.get("Cart-Token", cart_token)

    payload = {
        "billing_address": {
            "first_name": "Sat", "last_name": "Oshi",
            "address_1": "1 Genesis Block", "city": "Cypherpunk",
            "state": "CA", "postcode": "94016", "country": "US",
            "email": "buyer@example.test", "phone": "5551234567",
        },
        "payment_method": GATEWAY_ID,
    }
    r = s.post(f"{base}/checkout", json=payload, headers=headers(), timeout=60)
    assert r.status_code in (200, 201), f"checkout failed: {r.status_code} {r.text}"
    return r.json()


def _cron_key(wp: WordPressHandle) -> str:
    """The install's cron authorization key, seeded at Database::initialize."""
    snippet = (
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';"
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';"
        "Database::initialize(); echo Config::get('cron_key');"
    )
    return wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1].strip()


def _order_field(wp: WordPressHandle, order_id: int, method: str) -> str:
    """Read a single field off a WooCommerce order via wp-cli."""
    snippet = f"$o = wc_get_order({order_id}); echo $o ? (string)$o->{method} : 'NO_ORDER';"
    return wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1].strip()


def _ensure_integration(wp: WordPressHandle, store_id: str, api_key: str) -> dict:
    """Drive the one entry point the setup wizard's completion screen now calls
    in WordPress mode: install-if-needed + configure + enable, all unattended.
    Returns the function's status plus the resulting gateway-enabled flag and the
    BTCPay URL the plugin ended up pointed at."""
    snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/btcpay-integration.php';
$res = cashupay_ensure_woocommerce_integration({store_id!r}, {api_key!r});
$gw = get_option('woocommerce_{GATEWAY_ID}_settings', []);
echo json_encode([
    'res' => $res,
    'enabled' => $gw['enabled'] ?? null,
    'url' => get_option('btcpay_gf_url', ''),
]);
"""
    out = wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1]
    return json.loads(out)


def test_completion_screen_autowires_and_enables_gateway(woocommerce) -> None:
    """The completion screen (WordPress mode) wires WooCommerce up with no manual
    steps: it points the BTCPay gateway at BareBits, registers the webhook, and —
    the piece that used to be a manual click — flips the gateway to enabled so it
    shows at checkout. The gateway plugin is already active in this fixture, so
    nothing should report as auto-installed."""
    wp, _info = woocommerce
    _flush_rewrites(wp)
    store_id, api_key = _seed_store(wp, "https://mint.example")

    data = _ensure_integration(wp, store_id, api_key)
    assert data["res"]["status"] == "ready", data
    assert data["res"]["auto_installed"] is False, "an already-active plugin must not report as auto-installed"
    assert data["enabled"] == "yes", "the BTCPay gateway must be enabled at checkout"
    assert data["url"].endswith("/cashupay"), data["url"]


def test_completion_screen_never_clobbers_a_real_btcpay_server(woocommerce) -> None:
    """If a merchant already points the BTCPay plugin at a real BTCPay Server, the
    completion screen must refuse to overwrite it rather than silently hijack
    their payments."""
    wp, _info = woocommerce
    store_id, api_key = _seed_store(wp, "https://mint.example")

    real = "https://btcpay.example.com"
    wp.wp_cli("eval", f"update_option('btcpay_gf_url', {real!r});")

    data = _ensure_integration(wp, store_id, api_key)
    assert data["res"]["status"] == "existing_btcpay", data
    assert data["url"] == real, "the real BTCPay URL must be left untouched"


def test_woocommerce_stack_installs(woocommerce) -> None:
    """Smoke test: WooCommerce + the BTCPay gateway activate under the SQLite
    fixture and the fixture product exists. Guards the heavier flow below from
    failing for mundane install reasons."""
    wp, info = woocommerce
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    assert "woocommerce" in active, active
    assert "btcpay-greenfield-for-woocommerce" in active, active
    assert "cashupay" in active, active

    product_type = wp.wp_cli(
        "eval", f"echo wc_get_product({info['product_id']})->get_type();"
    ).stdout.strip().splitlines()[-1]
    assert product_type == "simple", product_type


def test_woocommerce_order_settles_via_btcpay_gateway(
    woocommerce,
    mint: MintHandle,
    lnd_payer: LndHandle,
) -> None:
    wp, info = woocommerce
    _flush_rewrites(wp)
    store_id, api_key = _seed_store(wp, mint.url)
    _autowire_btcpay(wp, store_id, api_key)

    # --- customer places the order; the gateway creates a BareBits invoice ---
    checkout = _place_order(wp, info["product_id"])
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", checkout

    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", f"gateway did not store a BareBits invoice id: {checkout}"

    # --- fetch the bolt11 the gateway had BareBits generate, and pay it ---
    api = f"{wp.url}/cashupay/api/v1"
    auth = {"Authorization": f"token {api_key}"}
    inv = requests.get(f"{api}/stores/{store_id}/invoices/{invoice_id}", headers=auth, timeout=15)
    inv.raise_for_status()
    bolt11 = inv.json()["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    assert bolt11.lower().startswith("lnbcrt"), bolt11

    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay

    # --- let BareBits notice settlement, then drain the webhook outbox so the
    #     signed InvoiceSettled reaches WooCommerce's wc-api endpoint ---
    _await_settled(wp, api, store_id, invoice_id, auth)

    # The webhook outbox is drained by the real cron endpoint, which is
    # authenticated with the install's cron_key. Hitting it POSTs the signed
    # InvoiceSettled back to WooCommerce's wc-api endpoint (a same-server
    # loopback, hence the multi-worker php -S).
    cron_key = _cron_key(wp)
    deadline = time.monotonic() + 30
    status = None
    while time.monotonic() < deadline:
        # Draining is idempotent; run it each tick until the order flips.
        r = requests.get(f"{wp.url}/cashupay/cron", params={"key": cron_key}, timeout=15)
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            break
        time.sleep(1)

    assert status in ("processing", "completed"), (
        f"order never reached a paid state (last status {status!r}); "
        f"the BTCPay plugin should have run payment_complete() on the signed InvoiceSettled webhook"
    )

    # Corroborate from BareBits' side: the InvoiceSettled delivery is recorded
    # as accepted (HTTP 2xx). The plugin wp_die()s on a bad signature *before*
    # touching the order, so a 200 here is also proof the HMAC verified.
    with wp.db() as db:
        row = db.execute(
            "SELECT status, status_code FROM webhook_deliveries "
            "WHERE invoice_id = ? AND event_type = 'InvoiceSettled'",
            (invoice_id,),
        ).fetchone()
    assert row is not None, "no InvoiceSettled delivery was recorded"
    assert row["status"] == "delivered" and 200 <= row["status_code"] < 300, dict(row)


def test_expired_invoice_fails_order_and_offers_retry(
    woocommerce,
    mint: MintHandle,
) -> None:
    """Invoice expiry must reach WooCommerce and leave the customer a way to
    pay again:

      - the cron sweep fires the signed InvoiceExpired webhook, and — via the
        Expired→wc-failed mapping BareBits configures — the order flips
        pending→failed instead of silently rotting at pending;
      - the payment page's expired screen links /cashupay/retry/{invoiceId},
        which resolves the invoice back to its order and bounces to
        WooCommerce's order-pay page, where clicking Pay mints a fresh
        invoice. Failed orders keep that affordance; the gateway plugin's
        stock wc-cancelled mapping would have removed it.
    """
    wp, info = woocommerce
    _flush_rewrites(wp)
    store_id, api_key = _seed_store(wp, mint.url)

    # The full wizard path: configure + webhook + enable + order-state map.
    data = _ensure_integration(wp, store_id, api_key)
    assert data["res"]["status"] == "ready", data

    states_out = wp.wp_cli(
        "eval", "echo json_encode(get_option('btcpay_gf_order_states'));"
    ).stdout.strip().splitlines()[-1]
    assert json.loads(states_out)["Expired"] == "wc-failed", states_out

    # --- customer places the order; the gateway creates a BareBits invoice ---
    checkout = _place_order(wp, info["product_id"])
    order_id = checkout["order_id"]
    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", checkout
    assert _order_field(wp, order_id, "get_status()") == "pending"

    # --- backdate so the cron sweep expires the invoice, then loop the real
    #     cron endpoint: it sweeps (firing InvoiceExpired) and drains the
    #     outbox to WooCommerce's wc-api endpoint ---
    with wp.db() as db:
        db.execute(
            "UPDATE invoices SET expiration_time = ? WHERE id = ?",
            (int(time.time()) - 60, invoice_id),
        )

    cron_key = _cron_key(wp)
    deadline = time.monotonic() + 30
    status = None
    while time.monotonic() < deadline:
        r = requests.get(f"{wp.url}/cashupay/cron", params={"key": cron_key}, timeout=15)
        assert r.status_code == 200, f"cron refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status == "failed":
            break
        time.sleep(1)
    assert status == "failed", (
        f"order never flipped to failed on InvoiceExpired (last status {status!r})"
    )

    # Delivery accepted with 2xx => the real plugin verified the HMAC before
    # touching the order. partiallyPaid=false is what routes the plugin to the
    # plain-Expired branch (not expired-paid-partial).
    with wp.db() as db:
        row = db.execute(
            "SELECT status, status_code, payload FROM webhook_deliveries "
            "WHERE invoice_id = ? AND event_type = 'InvoiceExpired'",
            (invoice_id,),
        ).fetchone()
    assert row is not None, "no InvoiceExpired delivery was recorded"
    assert row["status"] == "delivered" and 200 <= row["status_code"] < 300, dict(row)
    assert json.loads(row["payload"]).get("partiallyPaid") is False

    # --- the expired payment page offers the retry link ---
    page = requests.get(f"{wp.url}/cashupay/payment/{invoice_id}", timeout=15)
    page.raise_for_status()
    assert f"/cashupay/retry/{invoice_id}" in page.text, (
        "expired payment page should link the retry endpoint"
    )

    # --- the retry endpoint bounces to WooCommerce's order-pay page, carrying
    #     the order key so a guest can pay without an account ---
    r = requests.get(
        f"{wp.url}/cashupay/retry/{invoice_id}", allow_redirects=False, timeout=15
    )
    assert r.status_code in (301, 302), (r.status_code, r.text[:200])
    location = r.headers.get("Location", "")
    assert "order-pay" in location, location
    assert str(order_id) in location, location
    assert "key=" in location, location

    # An unresolvable invoice id falls back to the front page, never an error
    # page or an order it doesn't own.
    r = requests.get(
        f"{wp.url}/cashupay/retry/inv_nonexistent", allow_redirects=False, timeout=15
    )
    assert r.status_code in (301, 302), (r.status_code, r.text[:200])
    assert "order-pay" not in r.headers.get("Location", ""), r.headers.get("Location")


def _await_settled(wp, api, store_id, invoice_id, auth) -> None:
    """Poll the invoice until BareBits marks it Settled (the GET drives
    Invoice::pollSingleQuote, which is what flips the state)."""
    deadline = time.monotonic() + 30
    last = None
    while time.monotonic() < deadline:
        r = requests.get(f"{api}/stores/{store_id}/invoices/{invoice_id}", headers=auth, timeout=10)
        r.raise_for_status()
        last = r.json()
        if last.get("status") == "Settled":
            return
        time.sleep(0.5)
    raise AssertionError(
        f"BareBits invoice {invoice_id} never settled; last status "
        f"{(last or {}).get('status')!r}"
    )
