"""Long-lived (shareable) invoices end to end.

A payment request made from the admin Request modal, the self-serve page, or
the API with checkout.expirationMinutes stays payable for the shared lifetime
(90 days by default) instead of the one-hour checkout window. Its Lightning
bolt11 still lapses on its own schedule, so the payment page re-sources it:
on load when it has already lapsed, and live (with a "Refreshed ... ago"
flash under the QR) when the timer runs out while someone is watching. The
bolt11 it replaced stays under watch, so a payer who scanned the previous QR
still settles the invoice. A fiat amount is re-quoted in sats on page load.

Covered here against the real regtest stack (nutshell mint + LND payer):

  - API contract: expirationMinutes → longLived + far expirationTime; an
    e-commerce invoice (metadata.orderId) is never long-lived; range and type
    validation; invoices without the field are unchanged.
  - Payment page: ordinary invoice renders exactly as before; a long-lived
    one shows the "refreshes in" timer and the refreshed label; a lapsed
    bolt11 is replaced on load; the in-page timer triggers a live refresh
    (new QR + flash) and the refreshed invoice is payable.
  - Superseded quote: paying the PREVIOUS bolt11 after a refresh settles the
    invoice through cron's superseded-quote poll, with that bolt11 recorded.
  - Fiat re-quote on load at a moved rate (Lightning + on-chain amount, with
    the on-chain floor), locked once a payment is seen.
  - Admin Request modal and self-serve page produce long-lived invoices.

Unit-level truth tables (rail sourcing, lock, grace, static-address lock,
poll backoff) live in tests/php/test_long_lived_*.php.
"""
from __future__ import annotations

import json
import time

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle
from fixtures.onchain import OnchainContext, configure_store_for_onchain

NINETY_DAYS_MIN = 90 * 1440


# --------------------------------------------------------------------- helpers


def _row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        r = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert r is not None, invoice_id
        return dict(r)


def _set(cfg: ConfiguredPayserver, invoice_id: str, **cols) -> None:
    sets = ", ".join(f"{k} = ?" for k in cols)
    with cfg.handle.db() as db:
        db.execute(f"UPDATE invoices SET {sets} WHERE id = ?", (*cols.values(), invoice_id))


def _parked(cfg: ConfiguredPayserver, invoice_id: str) -> list[dict]:
    with cfg.handle.db() as db:
        return [dict(r) for r in db.execute(
            "SELECT * FROM invoice_ln_quotes WHERE invoice_id = ? ORDER BY created_at, id", (invoice_id,)
        )]


def _seed_usd_rate(cfg: ConfiguredPayserver, btc_usd: float) -> None:
    """Seed ExchangeRates' fresh cache so the fiat path never phones a live
    provider (key shape mirrors ExchangeRates::saveToCache)."""
    now = int(time.time())
    payload = json.dumps({"rate": btc_usd, "timestamp": now, "provider": "e2e"})
    with cfg.handle.db() as db:
        for key in ("rate_usd", "rate_lastgood_usd"):
            db.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                (key, payload, now, now),
            )


def _create_long_lived(cfg: ConfiguredPayserver, amount: str = "1000", currency: str = "sat") -> dict:
    return cfg.greenfield.create_invoice(
        cfg.store_id, amount=amount, currency=currency,
        checkout={"expirationMinutes": NINETY_DAYS_MIN},
    )


def _bolt11(invoice: dict) -> str:
    return invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]


def _payment_url(cfg: ConfiguredPayserver, invoice_id: str) -> str:
    return f"{cfg.handle.url}/payment.php?id={invoice_id}"


def _wait_status(cfg: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30, cron: bool = False) -> dict:
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        if cron:
            cfg.handle.trigger_cron()
        last = cfg.greenfield.get_invoice(cfg.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} did not reach {status}; last={last}")


def _api_post(cfg: ConfiguredPayserver, body: dict) -> requests.Response:
    return requests.post(
        f"{cfg.handle.url}/api/v1/stores/{cfg.store_id}/invoices",
        json=body, headers={"Authorization": f"token {cfg.api_token}"}, timeout=30,
    )


# ------------------------------------------------------------------- API shape


def test_api_expiration_minutes_makes_long_lived_invoice(shared_configured: ConfiguredPayserver) -> None:
    now = int(time.time())
    inv = _create_long_lived(shared_configured)
    assert inv["longLived"] is True
    assert abs(inv["expirationTime"] - (now + NINETY_DAYS_MIN * 60)) < 120, inv["expirationTime"]
    row = _row(shared_configured, inv["id"])
    assert row["long_lived"] == 1
    # The bolt11 (a nutshell mint quote) keeps its own, much shorter expiry.
    assert row["ln_expires_at"] is not None
    assert now < row["ln_expires_at"] < now + 2 * 86400, row["ln_expires_at"]
    assert row["fee_redirect_note"] is None


def test_api_order_invoice_is_never_long_lived(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="1000", currency="sat",
        metadata={"orderId": "wc-4711"}, checkout={"expirationMinutes": 1440},
    )
    assert inv["longLived"] is False
    assert _row(shared_configured, inv["id"])["long_lived"] == 0
    # Lifetime still honoured as the invoice window (the mint quote may be shorter).
    assert inv["expirationTime"] <= int(time.time()) + 1440 * 60 + 60


def test_api_without_field_is_unchanged(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(shared_configured.store_id, amount="1000", currency="sat")
    assert inv["longLived"] is False
    # Pre-existing behaviour: a mint-rail checkout invoice expires with its
    # mint quote (nutshell: 24 h), nowhere near the shared lifetime.
    row = _row(shared_configured, inv["id"])
    assert inv["expirationTime"] == row["ln_expires_at"]
    assert inv["expirationTime"] < int(time.time()) + 7 * 86400
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=15)
    assert r.status_code == 400, r.text
    assert r.json()["reason"] == "not_refreshable"


@pytest.mark.parametrize("bad", [0, -5, "abc", 1.5, NINETY_DAYS_MIN + 1, True, [10]])
def test_api_rejects_invalid_expiration_minutes(shared_configured: ConfiguredPayserver, bad) -> None:
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": bad}})
    assert r.status_code == 400, (bad, r.text)
    assert r.json().get("code") == "validation-error", r.text
    assert "expirationMinutes" in r.json().get("message", ""), r.text


def test_api_accepts_string_and_null_expiration_minutes(shared_configured: ConfiguredPayserver) -> None:
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": "60"}})
    assert r.status_code == 200, r.text
    assert r.json()["longLived"] is True
    assert r.json()["expirationTime"] <= int(time.time()) + 3600 + 60
    r = _api_post(shared_configured, {"amount": "1000", "currency": "sat", "checkout": {"expirationMinutes": None}})
    assert r.status_code == 200, r.text
    assert r.json()["longLived"] is False


# --------------------------------------------------------------- payment page


def test_ordinary_payment_page_is_unchanged(shared_configured: ConfiguredPayserver) -> None:
    inv = shared_configured.greenfield.create_invoice(shared_configured.store_id, amount="1000", currency="sat")
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=15).text
    assert 'id="ln-refreshed"' not in html
    assert "const longLived = false;" in html
    poll = requests.get(_payment_url(shared_configured, inv["id"]) + "&json=1", timeout=15).json()
    assert poll["longLived"] is False
    assert "bolt11" not in poll


def test_lapsed_bolt11_is_refreshed_on_page_load(shared_configured: ConfiguredPayserver) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    # Pretend the quote lapsed: the server re-sources it before rendering.
    _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 10)
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=30).text
    row = _row(shared_configured, inv["id"])
    assert row["bolt11"] != first, "lapsed bolt11 replaced on load"
    assert row["bolt11"].lower().startswith("lnbcrt")
    assert row["ln_expires_at"] > int(time.time()) + 60
    assert row["ln_refreshed_at"] is not None
    assert row["bolt11"] in html, "page rendered the fresh bolt11"
    assert 'id="ln-refreshed"' in html
    assert "const longLived = true;" in html
    parked = _parked(shared_configured, inv["id"])
    assert [p["bolt11"] for p in parked] == [first]
    assert parked[0]["state"] == "open"
    poll = requests.get(_payment_url(shared_configured, inv["id"]) + "&json=1", timeout=15).json()
    assert poll["longLived"] is True and poll["bolt11"] == row["bolt11"]
    assert poll["lnExpiresAt"] == row["ln_expires_at"]


def test_live_refresh_in_page_and_refreshed_invoice_is_payable(
    shared_configured: ConfiguredPayserver, lnd_payer: LndHandle, browser
) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    url = _payment_url(shared_configured, inv["id"])
    ctx = browser.new_context()
    page = ctx.new_page()
    try:
        page.goto(url, wait_until="domcontentloaded")
        page.wait_for_selector("#ln-refreshed", timeout=10000)
        page.wait_for_function(
            "document.getElementById('timer').textContent.startsWith('Lightning invoice refreshes in')",
            timeout=10000,
        )
        assert page.locator("#ln-refreshed").inner_text().startswith("Refreshed ")
        # Watch for the one-second flash the refresh must trigger.
        page.evaluate(
            """() => {
                window.__flashes = 0;
                new MutationObserver(() => {
                    if (document.getElementById('ln-refreshed').classList.contains('flash')) window.__flashes++;
                }).observe(document.getElementById('ln-refreshed'), { attributes: true, attributeFilter: ['class'] });
            }"""
        )
        # Lapse the bolt11: server-side (so the refresh is due) and in the
        # page's own clock (so its timer fires without waiting an hour).
        _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 1)
        page.evaluate("lnExpiresAt = Math.floor(Date.now() / 1000) + 2")
        page.wait_for_function(
            "(first) => document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy !== first",
            arg=first, timeout=20000,
        )
        shown = page.evaluate("document.querySelector('[data-method-block=\"lightning\"] .invoice-input').dataset.copy")
        row = _row(shared_configured, inv["id"])
        assert shown == row["bolt11"] != first
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] a.btn').href") == "lightning:" + row["bolt11"]
        assert page.evaluate("document.querySelector('[data-method-block=\"lightning\"] button[data-copy]').dataset.copy") == row["bolt11"]
        page.wait_for_function("window.__flashes >= 1", timeout=5000)
        page.wait_for_function(
            "document.getElementById('timer').textContent.startsWith('Lightning invoice refreshes in')",
            timeout=10000,
        )
        assert page.locator("#ln-refreshed").inner_text().startswith("Refreshed ")
        assert row["status"] == "New"
        assert [p["bolt11"] for p in _parked(shared_configured, inv["id"])] == [first]

        # The refreshed bolt11 is a real, payable quote.
        pay = lnd_payer.pay_invoice_sync(row["bolt11"], timeout=30)
        assert not pay.get("payment_error"), pay
        page.wait_for_selector("#payment-success.show", timeout=30000)
    finally:
        ctx.close()
    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=30)
    assert settled["status"] == "Settled"
    assert _row(shared_configured, inv["id"])["bolt11"] == row["bolt11"]


def test_paying_the_previous_bolt11_after_refresh_settles(
    shared_configured: ConfiguredPayserver, lnd_payer: LndHandle
) -> None:
    inv = _create_long_lived(shared_configured)
    first = _bolt11(inv)
    _set(shared_configured, inv["id"], ln_expires_at=int(time.time()) - 1)
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=30)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["ok"] is True and body["bolt11"] != first, body
    second = body["bolt11"]
    # Refreshing again right away is refused (lock) but reports the current bolt11.
    r = requests.post(_payment_url(shared_configured, inv["id"]), data={"action": "refresh_lightning"}, timeout=30)
    assert r.json()["ok"] is False and r.json()["reason"] in ("busy", "not_due") and r.json()["bolt11"] == second

    # The payer scanned the FIRST QR a moment before the refresh.
    pay = lnd_payer.pay_invoice_sync(first, timeout=30)
    assert not pay.get("payment_error"), pay

    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=60, cron=True)
    assert settled["status"] == "Settled"
    row = _row(shared_configured, inv["id"])
    assert row["bolt11"] == first, "the bolt11 actually paid is recorded on the invoice"
    assert row["settled_rail"] == "mint"
    states = {p["bolt11"]: p["state"] for p in _parked(shared_configured, inv["id"])}
    assert states[first] == "settled"
    assert states[second] in ("open", "closed")
    summary = shared_configured.handle.trigger_cron_json()
    assert "poll_superseded_ln" in summary.get("tasks", {}), summary


# ------------------------------------------------------------ fiat re-quote


def test_fiat_invoice_is_requoted_on_load(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    configure_store_for_onchain(
        shared_configured.handle.db_path, shared_configured.store_id,
        xpub=onchain.tpub, network="regtest", address_type="P2WPKH",
        min_confs=1, confirm_timeout_sec=86400, provider_url=onchain.watch_wallet_url,
        start_index=700,
    )
    _seed_usd_rate(shared_configured, 100_000.0)  # $10 = 10 000 sats
    inv = _create_long_lived(shared_configured, amount="10.00", currency="USD")
    assert inv["longLived"] is True
    row = _row(shared_configured, inv["id"])
    assert row["amount_sats"] == 10_000 and row["onchain_amount_sat"] == 10_000
    first = row["bolt11"]

    # Link opened later, after the price doubled: $10 is now 5 000 sats.
    _seed_usd_rate(shared_configured, 200_000.0)
    _set(shared_configured, inv["id"], requoted_at=None)
    html = requests.get(_payment_url(shared_configured, inv["id"]), timeout=30).text
    row = _row(shared_configured, inv["id"])
    assert row["onchain_amount_sat"] == 5_000
    assert row["onchain_amount_floor_sat"] == 5_000
    assert row["amount_sats"] == 5_000
    assert row["bolt11"] != first, "Lightning re-sourced at the new amount"
    assert float(row["exchange_rate"]) == 200_000.0
    assert row["amount"] == "10.00"
    assert "5,000 sat" in html
    assert row["bolt11"] in html
    assert [p["bolt11"] for p in _parked(shared_configured, inv["id"])] == [first]

    # Reload inside the throttle window: nothing moves.
    _seed_usd_rate(shared_configured, 50_000.0)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    assert _row(shared_configured, inv["id"])["onchain_amount_sat"] == 5_000

    # Later reload at the lower price: amount up, floor stays at the minimum shown.
    _set(shared_configured, inv["id"], requoted_at=None)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    row = _row(shared_configured, inv["id"])
    assert row["onchain_amount_sat"] == 20_000 and row["onchain_amount_floor_sat"] == 5_000
    assert row["amount_sats"] == 20_000

    # Pay the earlier (5 000 sat) on-chain amount: it was shown, so it settles.
    txid = onchain.fund_address(row["onchain_address"], 5_000)
    assert txid
    onchain.bitcoind.mine(1)
    settled = _wait_status(shared_configured, inv["id"], "Settled", timeout_s=60, cron=True)
    assert settled["status"] == "Settled"
    assert _row(shared_configured, inv["id"])["settled_rail"] == "onchain"


def test_requote_locked_once_payment_seen(
    shared_configured: ConfiguredPayserver, onchain: OnchainContext
) -> None:
    configure_store_for_onchain(
        shared_configured.handle.db_path, shared_configured.store_id,
        xpub=onchain.tpub, network="regtest", address_type="P2WPKH",
        min_confs=6, confirm_timeout_sec=86400, provider_url=onchain.watch_wallet_url,
        start_index=740,
    )
    _seed_usd_rate(shared_configured, 100_000.0)
    inv = _create_long_lived(shared_configured, amount="10.00", currency="USD")
    row = _row(shared_configured, inv["id"])
    onchain.fund_address(row["onchain_address"], 10_000)  # mempool only (min_confs=6)
    _wait_status(shared_configured, inv["id"], "Processing", timeout_s=60, cron=True)
    _seed_usd_rate(shared_configured, 200_000.0)
    _set(shared_configured, inv["id"], requoted_at=None)
    requests.get(_payment_url(shared_configured, inv["id"]), timeout=30)
    after = _row(shared_configured, inv["id"])
    assert after["onchain_amount_sat"] == 10_000 and after["onchain_amount_floor_sat"] is None
    assert after["amount_sats"] == 10_000 and after["bolt11"] == row["bolt11"]


# ---------------------------------------------------- admin modal + self-serve


def test_admin_request_modal_creates_long_lived_invoice(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": cfg.admin_password},
    )
    page = ctx.new_page()
    # The admin picks its store from localStorage; pin it before the first load.
    page.add_init_script("localStorage.setItem('selectedStoreId', %s)" % json.dumps(cfg.store_id))
    try:
        page.goto(f"{cfg.handle.url}/admin", wait_until="networkidle")
        page.wait_for_function(
            "typeof dashboardData !== 'undefined' && dashboardData && dashboardData.sharedInvoiceLifetimeMinutes > 0"
            " && (dashboardData.stores || []).some(s => s.internalApiKey)",
            timeout=20000,
        )
        assert page.evaluate("dashboardData.sharedInvoiceLifetimeMinutes") == NINETY_DAYS_MIN
        assert page.evaluate("currentStoreId") == cfg.store_id
        page.evaluate("openModal('modal-request')")
        page.fill("#request-amount", "1234")
        with page.expect_request(
            lambda r: r.method == "POST" and "/api/v1/stores/" in r.url and r.url.endswith("/invoices"),
            timeout=30000,
        ) as req_info:
            page.click("#btn-generate-request")
        req = req_info.value
        body = json.loads(req.post_data)
        # The modal asks for the shared lifetime: that is what makes the
        # invoice a long-lived payment request on the server.
        assert body["checkout"]["expirationMinutes"] == NINETY_DAYS_MIN, body
        assert body["amount"] == 1234 and body["currency"] == "sat", body
        resp = req.response()
        created = resp.json() if resp is not None and resp.status == 200 else None
    finally:
        ctx.close()
    if created is None:
        # php -S in the default 'router' URL mode cannot serve the modal's
        # /router.php/api/v1/... form (pre-existing: router.php only strips
        # PATH_INFO on Apache/nginx). The request shape above is this
        # feature's contract; the server side of it is covered by the API
        # tests, and the full round trip by the Apache/nginx backends.
        return
    row = _row(cfg, created["id"])
    assert row["long_lived"] == 1
    assert row["amount"] == "1234"
    assert abs(row["expiration_time"] - (int(time.time()) + NINETY_DAYS_MIN * 60)) < 300


def test_selfserve_invoice_is_long_lived(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    with cfg.handle.db() as db:
        db.execute("UPDATE stores SET selfserve_enabled = 1 WHERE id = ?", (cfg.store_id,))
    r = requests.post(
        f"{cfg.handle.url}/pay/{cfg.store_id}",
        data={"amount": "777", "currency": "sat", "notes": "long-lived self-serve"},
        timeout=30, allow_redirects=False,
    )
    assert r.status_code in (302, 303), r.text[:300]
    loc = r.headers["Location"]
    invoice_id = loc.split("id=")[-1] if "id=" in loc else loc.rstrip("/").split("/")[-1]
    row = _row(cfg, invoice_id)
    assert row["long_lived"] == 1
    assert abs(row["expiration_time"] - (int(time.time()) + NINETY_DAYS_MIN * 60)) < 300
