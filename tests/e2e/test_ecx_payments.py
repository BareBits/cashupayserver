"""eCash (ECX) rail end to end: admin card -> invoice with an ECX-OnChain
method -> payment page eCash tab -> a payment seen by the (mock) ECX explorer
settles the invoice over the 'ecx' rail -> dashboard / stats report it.

The ECX chain is Bitcoin-shaped, so the rail runs against a MockEsplora
standing in for explorer.ecash.com (CASHUPAY_ECX_ESPLORA_DEFAULT_URLS) and a
MockCmc standing in for CoinMarketCap (CASHUPAY_CMC_API_BASE). Both seams are
honoured only because the rig allows private endpoints.

Covers:
  - save_ecx with a mainnet xpub (the Bitcoin tpub re-encoded), the shared
    per-xpub counter with the Bitcoin rail, and the admin dashboard payload;
  - a USD invoice quoted in szats at the mocked ECX price, with the
    ecash:<addr>?amount=<ECX> payment link;
  - the payment page's eCash tab (QR payload, szat / ECX amounts, copy value);
  - settlement: mempool sighting -> Processing with the eCash confirmation
    copy, confirmed -> Settled with settled_rail 'ecx', ecx_payments row, no
    melts row, invoice detail + stats carrying the ECX figures;
  - no ECX price -> the invoice is created WITHOUT the eCash option;
  - the admin card's "Use my Bitcoin xpub" shortcut (Playwright).
"""
from __future__ import annotations

import json
import time
from typing import Iterator

import pytest
import requests

from conftest import (
    DEFAULT_ADMIN_PASSWORD,
    ConfiguredPayserver,
    _add_test_store,
    _start_shared_server,
)
from fixtures.browser import expand_settings_cards, wait_for_store_settings_loaded
from fixtures.cmc_mock import MockCmc
from fixtures.esplora_mock import MockEsplora
from fixtures.onchain import _MAINNET_VECTOR_XPUB, TEST_TPUB

ECX_TIP = 984_800
ECX_USD = 40.0
BTC_USD = 100_000.0
# A second mainnet key for the eCash-only store.
ECX_ONLY_XPUB = (
    "xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7"
    "Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ"
)


# ---------------------------------------------------------------------------
# fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def ecx_explorer() -> Iterator[MockEsplora]:
    """Stands in for explorer.ecash.com / esplora.ecash.ninja."""
    s = MockEsplora(tip=ECX_TIP)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def cmc() -> Iterator[MockCmc]:
    s = MockCmc(price=ECX_USD)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def ecx_server(request, mint, backup_mint, ecx_explorer: MockEsplora, cmc: MockCmc) -> Iterator[ConfiguredPayserver]:
    yield from _start_shared_server(
        request, mint, backup_mint,
        extra_env={
            "CASHUPAY_ECX_ESPLORA_DEFAULT_URLS": ecx_explorer.url,
            # The Bitcoin leg of the dual-rail store only ever reads a chain
            # tip here; keep that off the public explorers too.
            "CASHUPAY_ESPLORA_DEFAULT_URLS": ecx_explorer.url,
            "CASHUPAY_CMC_API_BASE": cmc.url,
        },
    )


@pytest.fixture
def cfg(ecx_server: ConfiguredPayserver, mint, backup_mint, request) -> ConfiguredPayserver:
    c = _add_test_store(ecx_server, mint, backup_mint, request)
    _seed_usd_rate(c, BTC_USD)
    _clear_ecx_price_cache(c)
    return c


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------


def _config_set(cfg: ConfiguredPayserver, key: str, payload: str) -> None:
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, payload, now, now),
        )


def _seed_usd_rate(cfg: ConfiguredPayserver, btc_usd: float) -> None:
    """ExchangeRates' fresh BTC/USD cache (the fiat leg never phones a provider)."""
    now = int(time.time())
    payload = json.dumps({"rate": btc_usd, "timestamp": now, "provider": "e2e"})
    for key in ("rate_usd", "rate_lastgood_usd"):
        _config_set(cfg, key, payload)


def _clear_ecx_price_cache(cfg: ConfiguredPayserver) -> None:
    with cfg.handle.db() as db:
        db.execute("DELETE FROM config WHERE key IN ('rate_ECX_USD', 'rate_lastgood_ECX_USD')")


def _admin_post(cfg: ConfiguredPayserver, **data) -> requests.Response:
    return cfg.admin.s.post(
        f"{cfg.handle.url}/admin", data=data,
        headers={"X-CSRF-Token": cfg.admin.csrf_token}, timeout=30,
    )


def _save_ecx(cfg: ConfiguredPayserver, xpub: str, **over) -> requests.Response:
    data = {
        "action": "save_ecx", "store_id": cfg.store_id, "mode": "xpub", "xpub": xpub,
        "address_type": "P2WPKH", "min_confs": "1", "confirm_timeout_sec": "86400", "provider_url": "",
    }
    data.update(over)
    return _admin_post(cfg, **data)


def _save_btc(cfg: ConfiguredPayserver, xpub: str) -> requests.Response:
    return _admin_post(
        cfg, action="save_onchain", store_id=cfg.store_id, mode="xpub", xpub=xpub, network="mainnet",
        address_type="P2WPKH", min_confs="0", confirm_timeout_sec="7200", provider_url="",
    )


def _dashboard(cfg: ConfiguredPayserver) -> dict:
    r = cfg.admin.s.get(cfg.admin._admin_url, params={"api": "dashboard", "store_id": cfg.store_id}, timeout=30)
    r.raise_for_status()
    return r.json()


def _invoice_row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        row = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row is not None
    return dict(row)


def _poll_until(cfg: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 40) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        cfg.handle.trigger_cron()
        last = cfg.greenfield.get_invoice(cfg.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status}; last={last}")


def _page_json(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    r = requests.get(f"{cfg.handle.url}/payment.php", params={"id": invoice_id, "json": "1"}, timeout=15)
    r.raise_for_status()
    return r.json()


# ---------------------------------------------------------------------------
# admin card + allocation
# ---------------------------------------------------------------------------


def test_save_ecx_shares_the_counter_with_bitcoin(cfg: ConfiguredPayserver, cmc: MockCmc) -> None:
    # Bitcoin first (mainnet xpub), then the same key for eCash.
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    r = _save_ecx(cfg, _MAINNET_VECTOR_XPUB)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True and body["xpubChanged"] is True and body["priceAvailable"] is True

    ex = _dashboard(cfg)["ecx"]
    assert ex["enabled"] and ex["offered"] and ex["sameAsBtc"]
    assert ex["xpub"] == _MAINNET_VECTOR_XPUB and ex["btcXpub"] == _MAINNET_VECTOR_XPUB
    assert ex["unit"] == "szat" and ex["unitPlural"] == "szats"
    assert ex["priceAvailable"] is True and abs(ex["priceUsd"] - ECX_USD) < 1e-9
    assert ex["publicExplorers"] == ["https://explorer.ecash.com/api", "https://esplora.ecash.ninja"]
    assert cmc.hit_count() >= 1, "the price came from the (mock) CoinMarketCap endpoint"

    # $10 at $40/ECX = 0.25 ECX; the Bitcoin leg is 10,000 sats at $100k.
    inv = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
    pm = inv["checkout"]["paymentMethods"]
    assert "BTC-OnChain" in pm and "ECX-OnChain" in pm
    ecx_pm = pm["ECX-OnChain"]
    assert ecx_pm["amount"] == "25000000" and ecx_pm["due"] == "25000000"
    assert ecx_pm["paymentLink"] == f"ecash:{ecx_pm['destination']}?amount=0.25"
    assert abs(float(ecx_pm["rate"]) - ECX_USD) < 1e-9
    assert ecx_pm["destination"].startswith("bc1q")
    assert ecx_pm["destination"] != pm["BTC-OnChain"]["destination"], "one address is never shown for both chains"
    row = _invoice_row(cfg, inv["id"])
    assert row["ecx_amount_szat"] == 25_000_000 and row["onchain_amount_sat"] == 10_000
    assert {row["onchain_address_index"], row["ecx_address_index"]} == {0, 1}, "shared per-xpub counter"
    assert row["ecx_created_tip_height"] == ECX_TIP, "tip stamped from the ECX explorer"
    assert row["ecx_fee_note"] is None


def test_no_ecx_price_withholds_the_ecash_option(cfg: ConfiguredPayserver, cmc: MockCmc) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    cmc.mode = "down"
    _clear_ecx_price_cache(cfg)
    try:
        ex = _dashboard(cfg)["ecx"]
        assert ex["enabled"] and ex["offerEnabled"] and ex["priceAvailable"] is False
        inv = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
        assert "ECX-OnChain" not in inv["checkout"]["paymentMethods"], "no price -> no eCash on the invoice"
        row = _invoice_row(cfg, inv["id"])
        assert row["ecx_address"] is None and row["ecx_amount_szat"] is None
        page = requests.get(f"{cfg.handle.url}/payment.php", params={"id": inv["id"]}, timeout=15).text
        assert 'data-method-block="ecx"' not in page
    finally:
        cmc.mode = "ok"
    # Price back: the next invoice offers eCash again (cooldown is per host;
    # the cache was empty so the next quote is a live one).
    _clear_ecx_price_cache(cfg)
    with cfg.handle.db() as db:
        db.execute("DELETE FROM config WHERE key LIKE 'endpoint_health%' OR key LIKE '%rates:cmc%'")
    deadline = time.monotonic() + 70
    while time.monotonic() < deadline:
        inv = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
        if "ECX-OnChain" in inv["checkout"]["paymentMethods"]:
            break
        time.sleep(5)
    assert "ECX-OnChain" in inv["checkout"]["paymentMethods"], "eCash offered again once the price is back"


def test_offer_toggle_hides_ecash_without_losing_settings(cfg: ConfiguredPayserver) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    r = _admin_post(cfg, action="save_ecx_offer", store_id=cfg.store_id, offer_enabled="0")
    assert r.status_code == 200 and r.json()["offerEnabled"] is False
    inv = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
    assert "ECX-OnChain" not in inv["checkout"]["paymentMethods"]
    ex = _dashboard(cfg)["ecx"]
    assert ex["enabled"] and not ex["offered"] and ex["xpub"] == ECX_ONLY_XPUB
    r = _admin_post(cfg, action="save_ecx_offer", store_id=cfg.store_id, offer_enabled="1")
    assert r.status_code == 200 and r.json()["offered"] is True


# ---------------------------------------------------------------------------
# payment page + settlement
# ---------------------------------------------------------------------------


def test_ecash_payment_settles_over_the_ecx_rail(cfg: ConfiguredPayserver, ecx_explorer: MockEsplora) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
    ecx_pm = inv["checkout"]["paymentMethods"]["ECX-OnChain"]
    addr = ecx_pm["destination"]
    assert inv["paymentRail"] in ("mint", "ecx"), inv["paymentRail"]

    # Payment page: eCash tab with the ecash: QR payload and szat amounts.
    page = requests.get(f"{cfg.handle.url}/payment.php", params={"id": inv["id"]}, timeout=15).text
    assert 'data-method-block="ecx"' in page
    assert ">eCash<" in page
    assert f"ecash:{addr}?amount=0.25" in page
    assert "25,000,000 szats" in page and "(0.25 ECX)" in page
    assert "Pay with ECX wallet" in page
    assert "pay this address with an <strong>ECX</strong> wallet only" in page

    # Mempool sighting on the ECX chain -> Processing, eCash confirmation copy.
    txid = "e" * 63 + "1"
    ecx_explorer.pay(addr, 25_000_000, txid, None)
    got = _poll_until(cfg, inv["id"], "Processing")
    assert got["status"] == "Processing"
    poll = _page_json(cfg, inv["id"])
    assert poll["onchainPending"] is True and poll["chainKind"] == "ecx"
    assert poll["onchainTxUrl"] == f"https://explorer.ecash.com/tx/{txid}"
    row = _invoice_row(cfg, inv["id"])
    assert row["ecx_first_seen_at"] is not None and row["onchain_first_seen_at"] is None
    with cfg.handle.db() as db:
        assert db.execute("SELECT COUNT(*) FROM ecx_payments WHERE invoice_id = ?", (inv["id"],)).fetchone()[0] == 1
        assert db.execute("SELECT COUNT(*) FROM onchain_payments WHERE invoice_id = ?", (inv["id"],)).fetchone()[0] == 0

    # Confirmed -> Settled over the eCash rail.
    ecx_explorer.txs[addr] = []
    ecx_explorer.pay(addr, 25_000_000, txid, ECX_TIP)
    got = _poll_until(cfg, inv["id"], "Settled")
    assert got["paymentRail"] == "ecx"
    assert got["destination"] == addr and got["destinationChain"] == "ecx"
    assert got["txid"] == txid and got["txidChain"] == "ecx"
    assert got["ecx"]["settled"] is True and got["ecx"]["settledToFee"] is False
    assert got["ecx"]["amountSzat"] == 25_000_000
    row = _invoice_row(cfg, inv["id"])
    assert row["settled_rail"] == "ecx"
    with cfg.handle.db() as db:
        assert db.execute("SELECT COUNT(*) FROM melts").fetchone()[0] == 0, "no sats-ledger entry for an ECX payment"

    # Complete screen shows what was received in ECX.
    page = requests.get(f"{cfg.handle.url}/payment.php", params={"id": inv["id"]}, timeout=15).text
    assert "0.25 ECX" in page
    assert "View transaction on explorer.ecash.com" in page

    # Admin: invoice detail carries the eCash block; stats carry the ledger.
    det = cfg.admin.s.get(cfg.admin._admin_url, params={"api": "invoice_detail", "id": inv["id"]}, timeout=30).json()["detail"]
    assert det["ecx"]["address"] == addr and det["ecx"]["amountSzat"] == 25_000_000
    assert det["ecx"]["payments"][0]["txid"] == txid and det["ecx"]["payments"][0]["amountSzat"] == 25_000_000
    assert det.get("onchain") is None
    stats = cfg.admin.s.get(cfg.admin._admin_url, params={"api": "stats_summary", "store_id": cfg.store_id, "range": "all"}, timeout=30).json()
    assert stats["ecx"]["revenue_szats"] == 25_000_000 and stats["ecx"]["count"] == 1
    assert stats["revenue_sats"] == 0, "ECX revenue is not sats revenue"
    # 25,000,000 szats = 0.25 ECX = $10 = 10,000 sats at $100k.
    assert stats["ecx"]["price_available"] is True
    assert stats["ecx"]["revenue_sats_equiv"] == 10_000
    assert stats["revenue_total_sats"] == 10_000
    assert stats["ecx"]["fees_owed"]["dev"] == 250_000, "1% of 25,000,000 szats owed in ECX"
    chart = cfg.admin.s.get(cfg.admin._admin_url, params={"api": "stats_chart", "store_id": cfg.store_id, "range": "all", "type": "count"}, timeout=30).json()
    assert chart["labels"] == ["Lightning", "On-chain", "eCash"] and chart["data"][2] == 1


def test_ecx_static_address_mode(cfg: ConfiguredPayserver, ecx_explorer: MockEsplora) -> None:
    static_addr = "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"
    r = _save_ecx(cfg, "", mode="static", static_address=static_addr, static_tweak_range="500")
    assert r.status_code == 200, r.text
    assert r.json()["mode"] == "static"
    inv1 = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
    inv2 = cfg.greenfield.create_invoice(cfg.store_id, amount="10.00", currency="USD")
    a1 = inv1["checkout"]["paymentMethods"]["ECX-OnChain"]
    a2 = inv2["checkout"]["paymentMethods"]["ECX-OnChain"]
    assert a1["destination"] == static_addr == a2["destination"]
    assert int(a1["amount"]) == 25_000_000 and int(a2["amount"]) == 25_000_001, "per-invoice szat tweak"
    # Exact-amount attribution settles the right invoice.
    ecx_explorer.pay(static_addr, 25_000_001, "d" * 64, ECX_TIP)
    got = _poll_until(cfg, inv2["id"], "Settled")
    assert got["paymentRail"] == "ecx"
    assert cfg.greenfield.get_invoice(cfg.store_id, inv1["id"])["status"] == "New"


# ---------------------------------------------------------------------------
# admin UI (Playwright)
# ---------------------------------------------------------------------------


@pytest.fixture
def admin_page(cfg: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.add_init_script(
        "window.localStorage.setItem('selectedStoreId', " f"{json.dumps(cfg.store_id)});"
    )
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, cfg.handle.url
    ctx.close()


def _open_ecx_card(page, base: str, store_id: str) -> None:
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    wait_for_store_settings_loaded(page, store_id)
    page.wait_for_function(
        "() => typeof dashboardLoadSeq === 'number' && dashboardRenderedSeq === dashboardLoadSeq"
    )
    page.wait_for_selector("#ecx-xpub", state="visible")
    # The confirmation policy lives in the collapsed Advanced subsection.
    page.evaluate("() => document.getElementById('ecx-advanced').classList.remove('collapsed')")
    page.wait_for_selector("#ecx-min-confs", state="visible")


@pytest.mark.ui
def test_admin_card_use_bitcoin_xpub_shortcut(cfg: ConfiguredPayserver, admin_page) -> None:
    page, base = admin_page
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    _open_ecx_card(page, base, cfg.store_id)

    assert "ECX price: $40" in page.inner_text("#ecx-price-line")
    assert page.input_value("#ecx-xpub") == ""
    # The Bitcoin card's confirmation policy is offered as the starting point.
    assert page.input_value("#ecx-min-confs") == "0"
    assert page.input_value("#ecx-confirm-timeout") == "7200"

    page.click("#btn-ecx-use-btc-xpub")
    assert page.input_value("#ecx-xpub") == _MAINNET_VECTOR_XPUB
    page.click("#btn-validate-ecx")
    page.wait_for_function("() => document.getElementById('ecx-validation-box').innerText.includes('Valid')")
    box = page.inner_text("#ecx-validation-box")
    assert "m/0/0 = bc1q" in box, box

    page.fill("#ecx-min-confs", "2")
    page.click("#btn-save-ecx")
    page.wait_for_function("() => document.getElementById('ecx-validation-box').innerText.includes('Saved')")
    with cfg.handle.db() as db:
        row = dict(db.execute(
            "SELECT ecx_xpub, ecx_min_confs, ecx_confirm_timeout_sec, ecx_address_mode FROM stores WHERE id = ?",
            (cfg.store_id,),
        ).fetchone())
    assert row["ecx_xpub"] == _MAINNET_VECTOR_XPUB and row["ecx_address_mode"] == "xpub"
    assert row["ecx_min_confs"] == 2 and row["ecx_confirm_timeout_sec"] == 7200

    # Reload: the card reports the saved key as shared with Bitcoin.
    _open_ecx_card(page, base, cfg.store_id)
    assert "same as your Bitcoin xpub" in page.inner_text("#ecx-xpub-meta")
    assert page.input_value("#ecx-min-confs") == "2"
    assert page.inner_text("#ecx-offer-effective").strip() == "on"
