"""eCash (ECX) rail end to end: admin card -> invoice with an ECX-OnChain
method -> payment page eCash tab -> a payment seen by the (mock) ECX explorer
settles the invoice over the 'ecx' rail -> dashboard / stats / emails /
webhooks report it.

The ECX chain is Bitcoin-shaped, so the rail runs against TWO independent
MockEsplora instances — one standing in for mempool.space (the Bitcoin
chain, CASHUPAY_ESPLORA_DEFAULT_URLS) and one for explorer.ecash.com (the
ECX chain, CASHUPAY_ECX_ESPLORA_DEFAULT_URLS) — plus a MockCmc standing in
for CoinMarketCap (CASHUPAY_CMC_API_BASE). Two explorers matter: an address
is the same string on both chains, so only separate mocks can prove that each
poller watches its own chain and that coins sent on the wrong chain never
settle an invoice. All three seams are honoured only because the rig allows
private endpoints.

Covers:
  - save_ecx with a mainnet xpub (the Bitcoin tpub re-encoded), the shared
    per-xpub counter with the Bitcoin rail, each leg stamped with ITS chain's
    tip, and the admin dashboard payload;
  - save_ecx validation (bad xpub, testnet key, bad / testnet static address)
    writes nothing; an empty key disables the rail but keeps the policy;
  - the admin "next address" preview sits at the shared counter;
  - a USD invoice quoted in szats at the mocked ECX price, with the
    ecash:<addr>?amount=<ECX> payment link;
  - cross-chain misdirection: BTC sent to the eCash address, or ECX sent to
    the Bitcoin address, never settles and never queries the wrong explorer;
    a dual-rail invoice paid in Bitcoin settles 'onchain' and a later eCash
    payment cannot re-settle it;
  - the payment page's eCash tab (QR payload, szat / ECX amounts, copy value);
  - settlement: mempool sighting -> Processing with the eCash confirmation
    copy, confirmed -> Settled with settled_rail 'ecx', ecx_payments row, no
    melts row, invoice detail + stats carrying the ECX figures, the webhook
    delivered, the cron summary's poll_ecx task;
  - static-address mode (per-invoice szat tweak) and a forced amount
    collision: manual confirmation on the ECX columns, listed and resolved
    per chain;
  - hosting fee owed in ECX: xpub validation, the eCash address routed to the
    hosting payee, the ecx_fee_credits row on settlement;
  - a custom ECX explorer URL: probe on save, exclusivity during an outage,
    opt-in public fallback;
  - the merchant "invoice paid" email names the szat / ECX amount and the rail;
  - no ECX price -> the invoice is created WITHOUT the eCash option;
  - Playwright: the admin card's "Use my Bitcoin xpub" shortcut, the payment
    page eCash tab + QR + live pending/settled copy, the admin invoice list /
    detail modal / stats rows / manual-confirmation banner for eCash.
"""
from __future__ import annotations

import json
import re
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
from fixtures.smtp_sink import SmtpSink, start_smtp_sink, stop_smtp_sink
from fixtures.webhook_sink import WebhookSink

BTC_TIP = 900_000
ECX_TIP = 984_800
ECX_USD = 40.0
BTC_USD = 100_000.0
# A second mainnet key for the eCash-only store.
ECX_ONLY_XPUB = (
    "xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7"
    "Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ"
)
# A third mainnet key (native segwit) standing in for a hosting provider's.
HOSTING_ZPUB = (
    "zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8"
    "b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA"
)
STATIC_ADDR = "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"


# ---------------------------------------------------------------------------
# fixtures
# ---------------------------------------------------------------------------


@pytest.fixture(scope="module")
def btc_explorer() -> Iterator[MockEsplora]:
    """Stands in for mempool.space / blockstream.info (the Bitcoin chain)."""
    s = MockEsplora(tip=BTC_TIP)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def ecx_explorer() -> Iterator[MockEsplora]:
    """Stands in for explorer.ecash.com / esplora.ecash.ninja (the ECX chain)."""
    s = MockEsplora(tip=ECX_TIP)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def cmc() -> Iterator[MockCmc]:
    s = MockCmc(price=ECX_USD)
    yield s
    s.stop()


@pytest.fixture(scope="module")
def ecx_server(
    request, mint, backup_mint, btc_explorer: MockEsplora, ecx_explorer: MockEsplora, cmc: MockCmc
) -> Iterator[ConfiguredPayserver]:
    yield from _start_shared_server(
        request, mint, backup_mint,
        extra_env={
            "CASHUPAY_ESPLORA_DEFAULT_URLS": btc_explorer.url,
            "CASHUPAY_ECX_ESPLORA_DEFAULT_URLS": ecx_explorer.url,
            "CASHUPAY_CMC_API_BASE": cmc.url,
        },
    )


@pytest.fixture
def cfg(ecx_server: ConfiguredPayserver, mint, backup_mint, request) -> ConfiguredPayserver:
    c = _add_test_store(ecx_server, mint, backup_mint, request)
    _seed_usd_rate(c, BTC_USD)
    _clear_ecx_price_cache(c)
    return c


@pytest.fixture
def custom_ecx_explorer() -> Iterator[MockEsplora]:
    """The operator's own ECX explorer (web root + /api like explorer.ecash.com);
    one per test so cooldown state and hit logs never leak."""
    s = MockEsplora(tip=ECX_TIP, api_prefix="/api")
    yield s
    s.stop()


@pytest.fixture
def smtp_sink() -> Iterator[SmtpSink]:
    sink = start_smtp_sink()
    yield sink
    stop_smtp_sink(sink)


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


def _admin_get(cfg: ConfiguredPayserver, **params) -> dict:
    r = cfg.admin.s.get(cfg.admin._admin_url, params=params, timeout=30)
    r.raise_for_status()
    return r.json()


def _save_ecx(cfg: ConfiguredPayserver, xpub: str, **over) -> requests.Response:
    data = {
        "action": "save_ecx", "store_id": cfg.store_id, "mode": "xpub", "xpub": xpub,
        "address_type": "P2WPKH", "min_confs": "1", "confirm_timeout_sec": "86400", "provider_url": "",
    }
    data.update(over)
    return _admin_post(cfg, **data)


def _save_btc(cfg: ConfiguredPayserver, xpub: str, **over) -> requests.Response:
    data = {
        "action": "save_onchain", "store_id": cfg.store_id, "mode": "xpub", "xpub": xpub, "network": "mainnet",
        "address_type": "P2WPKH", "min_confs": "0", "confirm_timeout_sec": "7200", "provider_url": "",
    }
    data.update(over)
    return _admin_post(cfg, **data)


def _dashboard(cfg: ConfiguredPayserver) -> dict:
    return _admin_get(cfg, api="dashboard", store_id=cfg.store_id)


def _store_row(cfg: ConfiguredPayserver) -> dict:
    with cfg.handle.db() as db:
        row = db.execute("SELECT * FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()
    assert row is not None
    return dict(row)


def _invoice_row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        row = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row is not None
    return dict(row)


def _count(cfg: ConfiguredPayserver, sql: str, params: tuple = ()) -> int:
    with cfg.handle.db() as db:
        return int(db.execute(sql, params).fetchone()[0])


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


def _drive_cron(cfg: ConfiguredPayserver, passes: int = 4, pause_s: float = 0.4) -> None:
    """A few cron passes with the per-invoice poll throttle in mind: the first
    pass polls an invoice (NULL stamp), later passes within 60 s skip it, so
    the invoice's own API read (which polls inline) is what re-checks."""
    for _ in range(passes):
        cfg.handle.trigger_cron()
        time.sleep(pause_s)


def _page_json(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    r = requests.get(f"{cfg.handle.url}/payment.php", params={"id": invoice_id, "json": "1"}, timeout=15)
    r.raise_for_status()
    return r.json()


def _page_html(cfg: ConfiguredPayserver, invoice_id: str) -> str:
    r = requests.get(f"{cfg.handle.url}/payment.php", params={"id": invoice_id}, timeout=15)
    r.raise_for_status()
    return r.text


def _ecx_pm(inv: dict) -> dict:
    return inv["checkout"]["paymentMethods"]["ECX-OnChain"]


def _btc_pm(inv: dict) -> dict:
    return inv["checkout"]["paymentMethods"]["BTC-OnChain"]


def _usd_invoice(cfg: ConfiguredPayserver, amount: str = "10.00") -> dict:
    return cfg.greenfield.create_invoice(cfg.store_id, amount=amount, currency="USD")


_txid_seq = 0


def _txid(prefix: str = "e") -> str:
    """A unique 64-hex txid per call (the mocks are module-wide; (txid, vout)
    is UNIQUE per observations table)."""
    global _txid_seq
    _txid_seq += 1
    tail = f"{_txid_seq:08x}"
    return (prefix * (64 - len(tail))) + tail


def _seed_ecx_revenue(cfg: ConfiguredPayserver, szats: int) -> None:
    """A settled eCash invoice written directly: the ECX ledger's revenue base."""
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO invoices (id, store_id, status, settled_rail, amount, currency, amount_sats,"
            " ecx_address, ecx_amount_szat, created_at, expiration_time, paid_at)"
            " VALUES (?, ?, 'Settled', 'ecx', '1', 'USD', 1, 'bc1qseededrevenue', ?, ?, ?, ?)",
            (f"seed_{cfg.store_id[-6:]}_{now}_{szats}", cfg.store_id, szats, now - 60, now + 3600, now - 30),
        )


# ---------------------------------------------------------------------------
# admin card + allocation
# ---------------------------------------------------------------------------


def test_save_ecx_shares_the_counter_with_bitcoin(
    cfg: ConfiguredPayserver, cmc: MockCmc, btc_explorer: MockEsplora, ecx_explorer: MockEsplora
) -> None:
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
    inv = _usd_invoice(cfg)
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
    # Each leg read ITS chain's tip: the two explorers sit at different heights.
    assert row["onchain_created_tip_height"] == BTC_TIP, "Bitcoin tip from the Bitcoin explorer"
    assert row["ecx_created_tip_height"] == ECX_TIP, "ECX tip from the ECX explorer"
    assert row["ecx_fee_note"] is None


def test_save_ecx_rejects_bad_keys_and_addresses_and_writes_nothing(cfg: ConfiguredPayserver) -> None:
    before = _store_row(cfg)
    assert before["ecx_xpub"] is None and before["ecx_address_mode"] == "xpub"

    bad_keys = {
        "garbage": "not-an-xpub",
        "testnet key (ECX is mainnet-only)": TEST_TPUB,
        "truncated": _MAINNET_VECTOR_XPUB[:-6],
    }
    for label, key in bad_keys.items():
        r = _save_ecx(cfg, key)
        assert r.status_code == 400, f"{label}: {r.status_code} {r.text}"
        assert "error" in r.json(), label
    bad_static = {
        "garbage": "bc1qnotanaddress",
        "testnet address": "tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx",
        "regtest address": "bcrt1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080",
    }
    for label, addr in bad_static.items():
        r = _save_ecx(cfg, "", mode="static", static_address=addr)
        assert r.status_code == 400, f"{label}: {r.status_code} {r.text}"
    r = _save_ecx(cfg, _MAINNET_VECTOR_XPUB, mode="sideways")
    assert r.status_code == 400 and "mode" in r.json()["error"].lower()

    after = _store_row(cfg)
    for col in ("ecx_xpub", "ecx_address_mode", "ecx_static_address", "ecx_min_confs", "ecx_confirm_timeout_sec"):
        assert after[col] == before[col], f"{col} must be untouched by a refused save"
    assert _dashboard(cfg)["ecx"]["enabled"] is False
    inv = _usd_invoice(cfg)
    assert "ECX-OnChain" not in inv["checkout"]["paymentMethods"]

    # An empty key disables the rail but the confirmation policy still persists.
    r = _save_ecx(cfg, "", min_confs="3", confirm_timeout_sec="3600")
    assert r.status_code == 200 and r.json().get("disabled") is True
    row = _store_row(cfg)
    assert row["ecx_xpub"] is None and row["ecx_min_confs"] == 3 and row["ecx_confirm_timeout_sec"] == 3600
    assert _dashboard(cfg)["ecx"]["enabled"] is False
    # Policy floors: confirmations never negative, window never below a minute.
    assert _save_ecx(cfg, ECX_ONLY_XPUB, min_confs="-5", confirm_timeout_sec="5").status_code == 200
    row = _store_row(cfg)
    assert row["ecx_min_confs"] == 0 and row["ecx_confirm_timeout_sec"] == 60


def test_next_address_preview_sits_at_the_shared_counter(cfg: ConfiguredPayserver) -> None:
    """test_ecx_xpub previews the address the shared per-xpub counter will hand
    out next — whichever chain asks first consumes it."""
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    assert _save_ecx(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    r = _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id)
    assert r.status_code == 200, r.text
    first = r.json()
    # The counter is per KEY, shared by every store on this server that uses
    # it (earlier tests in this module did), so reason relative to it.
    base = int(first["index"])
    assert first["address"].startswith("bc1q")

    inv = _usd_invoice(cfg)  # Bitcoin takes base, eCash takes base + 1
    assert _btc_pm(inv)["destination"] == first["address"], "the previewed address went to the Bitcoin leg"
    row = _invoice_row(cfg, inv["id"])
    assert row["onchain_address_index"] == base and row["ecx_address_index"] == base + 1

    r = _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id)
    second = r.json()
    assert second["index"] == base + 2, "preview advanced past BOTH chains' allocations"
    assert second["address"] not in (_btc_pm(inv)["destination"], _ecx_pm(inv)["destination"])
    # The Bitcoin card's preview of the same key agrees: one counter, one next address.
    r = _admin_post(cfg, action="test_onchain_xpub", store_id=cfg.store_id)
    assert r.status_code == 200, r.text
    assert r.json() == second, "both cards preview the same next address of the shared key"
    dash = _dashboard(cfg)
    assert dash["ecx"]["nextIndex"] == base + 2 and dash["onchain"]["nextIndex"] == base + 2, (
        "both cards' m/0/<n> labels read the shared counter, not the stale per-store mirror"
    )

    inv2 = _usd_invoice(cfg)
    assert _btc_pm(inv2)["destination"] == second["address"]
    # Without a key the preview refuses.
    assert _save_ecx(cfg, "").status_code == 200
    r = _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id)
    assert r.status_code == 400 and "No eCash xpub" in r.json()["error"]


def test_no_ecx_price_withholds_the_ecash_option(cfg: ConfiguredPayserver, cmc: MockCmc) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    cmc.mode = "down"
    _clear_ecx_price_cache(cfg)
    try:
        ex = _dashboard(cfg)["ecx"]
        assert ex["enabled"] and ex["offerEnabled"] and ex["priceAvailable"] is False
        inv = _usd_invoice(cfg)
        assert "ECX-OnChain" not in inv["checkout"]["paymentMethods"], "no price -> no eCash on the invoice"
        row = _invoice_row(cfg, inv["id"])
        assert row["ecx_address"] is None and row["ecx_amount_szat"] is None
        assert 'data-method-block="ecx"' not in _page_html(cfg, inv["id"])
        attempts = _admin_get(cfg, api="invoice_detail", id=inv["id"])["attempts"]
        skipped = [a for a in attempts if a["rail"] == "ecx"]
        assert len(skipped) == 1 and skipped[0]["outcome"] == "skipped", attempts
        assert "no eCash (ECX) price" in skipped[0]["reason"]
    finally:
        # Restore the price for the rest of the module even if an assertion
        # above fails: the outage put CoinMarketCap on a 120 s cooldown that
        # would otherwise starve every later test of an ECX price.
        cmc.mode = "ok"
        _clear_ecx_price_cache(cfg)
        with cfg.handle.db() as db:
            db.execute("DELETE FROM config WHERE key LIKE 'endpoint_health%'")
    # Price back: the next invoice offers eCash again (the cache was empty so
    # the next quote is a live one).
    deadline = time.monotonic() + 70
    while time.monotonic() < deadline:
        inv = _usd_invoice(cfg)
        if "ECX-OnChain" in inv["checkout"]["paymentMethods"]:
            break
        time.sleep(5)
    assert "ECX-OnChain" in inv["checkout"]["paymentMethods"], "eCash offered again once the price is back"


def test_offer_toggle_hides_ecash_without_losing_settings(cfg: ConfiguredPayserver) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    r = _admin_post(cfg, action="save_ecx_offer", store_id=cfg.store_id, offer_enabled="0")
    assert r.status_code == 200 and r.json()["offerEnabled"] is False
    inv = _usd_invoice(cfg)
    assert "ECX-OnChain" not in inv["checkout"]["paymentMethods"]
    ex = _dashboard(cfg)["ecx"]
    assert ex["enabled"] and not ex["offered"] and ex["xpub"] == ECX_ONLY_XPUB
    assert _admin_post(cfg, action="save_ecx_offer", store_id=cfg.store_id, offer_enabled="maybe").status_code == 400
    r = _admin_post(cfg, action="save_ecx_offer", store_id=cfg.store_id, offer_enabled="1")
    assert r.status_code == 200 and r.json()["offered"] is True


# ---------------------------------------------------------------------------
# two chains, one address format
# ---------------------------------------------------------------------------


def test_coins_on_the_wrong_chain_never_settle(
    cfg: ConfiguredPayserver, btc_explorer: MockEsplora, ecx_explorer: MockEsplora
) -> None:
    """A payer sends BTC to the eCash address, or ECX to the Bitcoin address.
    Neither poller watches the other chain's address, so the invoice stays
    unpaid — and the wrong explorer is never even asked."""
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    btc_addr, ecx_addr = _btc_pm(inv)["destination"], _ecx_pm(inv)["destination"]
    assert btc_addr != ecx_addr
    btc_explorer.reset_hits()
    ecx_explorer.reset_hits()

    # (a) BTC sent to the eCash address: visible on the Bitcoin chain only.
    btc_explorer.pay(ecx_addr, 10_000, _txid("a"), BTC_TIP)
    # (b) ECX sent to the Bitcoin address: visible on the ECX chain only.
    ecx_explorer.pay(btc_addr, 25_000_000, _txid("b"), ECX_TIP)
    _drive_cron(cfg)
    got = cfg.greenfield.get_invoice(cfg.store_id, inv["id"])
    assert got["status"] == "New", got
    assert _count(cfg, "SELECT COUNT(*) FROM onchain_payments WHERE invoice_id = ?", (inv["id"],)) == 0
    assert _count(cfg, "SELECT COUNT(*) FROM ecx_payments WHERE invoice_id = ?", (inv["id"],)) == 0
    row = _invoice_row(cfg, inv["id"])
    assert row["onchain_first_seen_at"] is None and row["ecx_first_seen_at"] is None
    # Each explorer was asked about its own chain's address only.
    assert btc_explorer.hit_count(f"/address/{btc_addr}/") >= 1, "Bitcoin explorer watched the Bitcoin address"
    assert btc_explorer.hit_count(f"/address/{ecx_addr}/") == 0, "Bitcoin explorer never asked about the eCash address"
    assert ecx_explorer.hit_count(f"/address/{ecx_addr}/") >= 1, "ECX explorer watched the eCash address"
    assert ecx_explorer.hit_count(f"/address/{btc_addr}/") == 0, "ECX explorer never asked about the Bitcoin address"

    # (c) The right coins on the right chain settle it.
    ecx_explorer.pay(ecx_addr, 25_000_000, _txid("c"), ECX_TIP)
    got = _poll_until(cfg, inv["id"], "Settled")
    assert got["paymentRail"] == "ecx" and got["destination"] == ecx_addr
    assert _count(cfg, "SELECT COUNT(*) FROM onchain_payments WHERE invoice_id = ?", (inv["id"],)) == 0


def test_dual_rail_bitcoin_payment_settles_onchain_and_ecash_cannot_resettle(
    cfg: ConfiguredPayserver, btc_explorer: MockEsplora, ecx_explorer: MockEsplora
) -> None:
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200  # zero-conf
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    btc_addr, ecx_addr = _btc_pm(inv)["destination"], _ecx_pm(inv)["destination"]

    btc_explorer.pay(btc_addr, 10_000, _txid("d"), BTC_TIP)
    got = _poll_until(cfg, inv["id"], "Settled")
    assert got["paymentRail"] == "onchain" and got["destination"] == btc_addr
    assert got.get("destinationChain") != "ecx" and got.get("txidChain") != "ecx"
    assert got["ecx"]["settled"] is False, "the eCash leg was offered, not paid"
    assert _count(cfg, "SELECT COUNT(*) FROM ecx_payments WHERE invoice_id = ?", (inv["id"],)) == 0
    assert _page_json(cfg, inv["id"])["chainKind"] == "btc"
    html = _page_html(cfg, inv["id"])
    amounts = re.findall(r'<div class="amount">(.*?)</div>', html)
    assert amounts and all("ECX" not in a for a in amounts), (
        "the complete screen of a Bitcoin settlement shows no ECX amount", amounts)
    assert "View transaction on mempool.space" in html

    # A later eCash payment to the (now settled) invoice's eCash address is
    # observed but cannot re-settle or re-route the invoice.
    ecx_explorer.pay(ecx_addr, 25_000_000, _txid("e"), ECX_TIP)
    _drive_cron(cfg)
    cfg.greenfield.get_invoice(cfg.store_id, inv["id"])
    row = _invoice_row(cfg, inv["id"])
    assert row["status"] == "Settled" and row["settled_rail"] == "onchain", "settle-once"
    det = _admin_get(cfg, api="invoice_detail", id=inv["id"])["detail"]
    assert det["onchain"]["payments"][0]["amountSat"] == 10_000
    assert det["ecx"]["address"] == ecx_addr


# ---------------------------------------------------------------------------
# payment page + settlement
# ---------------------------------------------------------------------------


def test_ecash_payment_settles_over_the_ecx_rail(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, webhook_sink: WebhookSink
) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    cfg.greenfield.create_webhook(cfg.store_id, webhook_sink.endpoint("ecx"), secret="s3cret")
    inv = _usd_invoice(cfg)
    ecx_pm = _ecx_pm(inv)
    addr = ecx_pm["destination"]
    assert inv["paymentRail"] in ("mint", "ecx"), inv["paymentRail"]

    # Payment page: eCash tab with the ecash: QR payload and szat amounts.
    page = _page_html(cfg, inv["id"])
    assert 'data-method-block="ecx"' in page
    assert ">eCash<" in page
    assert f"ecash:{addr}?amount=0.25" in page
    assert "25,000,000 szats" in page and "(0.25 ECX)" in page
    assert "Pay with ECX wallet" in page
    assert "pay this address with an <strong>ECX</strong> wallet only" in page

    # Mempool sighting on the ECX chain -> Processing, eCash confirmation copy.
    txid = _txid("f")
    ecx_explorer.pay(addr, 25_000_000, txid, None)
    got = _poll_until(cfg, inv["id"], "Processing")
    assert got["status"] == "Processing"
    poll = _page_json(cfg, inv["id"])
    assert poll["onchainPending"] is True and poll["chainKind"] == "ecx"
    assert poll["onchainTxUrl"] == f"https://explorer.ecash.com/tx/{txid}"
    row = _invoice_row(cfg, inv["id"])
    assert row["ecx_first_seen_at"] is not None and row["onchain_first_seen_at"] is None
    assert _count(cfg, "SELECT COUNT(*) FROM ecx_payments WHERE invoice_id = ?", (inv["id"],)) == 1
    assert _count(cfg, "SELECT COUNT(*) FROM onchain_payments WHERE invoice_id = ?", (inv["id"],)) == 0
    html = _page_html(cfg, inv["id"])
    assert "Waiting for eCash confirmation" in html
    assert "View transaction on explorer.ecash.com" in html

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
    assert _count(cfg, "SELECT COUNT(*) FROM melts") == 0, "no sats-ledger entry for an ECX payment"

    # Complete screen shows what was received in ECX.
    page = _page_html(cfg, inv["id"])
    assert "0.25 ECX" in page
    assert "View transaction on explorer.ecash.com" in page

    # Webhooks: the outbox is drained by cron; the shop sees the usual events.
    cfg.handle.drive_cron_until(
        lambda: any(r.json().get("type") == "InvoiceSettled" for r in webhook_sink.by_path("/hook/ecx")),
        timeout_s=40, label="InvoiceSettled webhook delivered",
    )
    events = [r.json() for r in webhook_sink.by_path("/hook/ecx")]
    types = [e["type"] for e in events]
    assert "InvoiceProcessing" in types and "InvoiceSettled" in types, types
    settled = [e for e in events if e["type"] == "InvoiceSettled"][0]
    assert settled["invoiceId"] == inv["id"] and settled["invoice"]["status"] == "Settled"
    assert settled["invoice"]["amountSats"] == 10_000, "BTCPay-shaped payload: the sats quote, as for every rail"

    # Cron summary names the ECX poll task.
    tasks = cfg.handle.trigger_cron_json().get("tasks", {})
    assert str(tasks.get("poll_ecx", "")).startswith("polled "), tasks

    # Admin: invoice detail carries the eCash block; stats carry the ledger.
    det = _admin_get(cfg, api="invoice_detail", id=inv["id"])["detail"]
    assert det["ecx"]["address"] == addr and det["ecx"]["amountSzat"] == 25_000_000
    assert det["ecx"]["payments"][0]["txid"] == txid and det["ecx"]["payments"][0]["amountSzat"] == 25_000_000
    assert det.get("onchain") is None
    stats = _admin_get(cfg, api="stats_summary", store_id=cfg.store_id, range="all")
    assert stats["ecx"]["revenue_szats"] == 25_000_000 and stats["ecx"]["count"] == 1
    assert stats["revenue_sats"] == 0, "ECX revenue is not sats revenue"
    # 25,000,000 szats = 0.25 ECX = $10 = 10,000 sats at $100k.
    assert stats["ecx"]["price_available"] is True
    assert stats["ecx"]["revenue_sats_equiv"] == 10_000
    assert stats["revenue_total_sats"] == 10_000
    assert stats["ecx"]["fees_owed"]["dev"] == 250_000, "1% of 25,000,000 szats owed in ECX"
    chart = _admin_get(cfg, api="stats_chart", store_id=cfg.store_id, range="all", type="count")
    assert chart["labels"] == ["Lightning", "On-chain", "eCash"] and chart["data"][2] == 1


def test_ecx_static_address_mode(cfg: ConfiguredPayserver, ecx_explorer: MockEsplora) -> None:
    r = _save_ecx(cfg, "", mode="static", static_address=STATIC_ADDR, static_tweak_range="500")
    assert r.status_code == 200, r.text
    assert r.json()["mode"] == "static"
    inv1 = _usd_invoice(cfg)
    inv2 = _usd_invoice(cfg)
    a1, a2 = _ecx_pm(inv1), _ecx_pm(inv2)
    assert a1["destination"] == STATIC_ADDR == a2["destination"]
    assert int(a1["amount"]) == 25_000_000 and int(a2["amount"]) == 25_000_001, "per-invoice szat tweak"
    # Exact-amount attribution settles the right invoice.
    ecx_explorer.pay(STATIC_ADDR, 25_000_001, _txid("d"), ECX_TIP)
    got = _poll_until(cfg, inv2["id"], "Settled")
    assert got["paymentRail"] == "ecx"
    assert cfg.greenfield.get_invoice(cfg.store_id, inv1["id"])["status"] == "New"
    # Switching back to xpub mode wipes the static address.
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    row = _store_row(cfg)
    assert row["ecx_address_mode"] == "xpub" and row["ecx_static_address"] is None and row["ecx_xpub"] == ECX_ONLY_XPUB


def _force_ecx_collision(cfg: ConfiguredPayserver, ecx_explorer: MockEsplora) -> tuple[dict, dict, str, int]:
    """Static ECX store, two open invoices forced to the same szat total, one
    matching confirmed payment on the ECX chain. Returns (inv1, inv2, txid, total)."""
    # A fresh static address per store so the module-wide mock's tx list for it is empty.
    assert _save_ecx(cfg, "", mode="static", static_address=STATIC_ADDR, static_tweak_range="500").status_code == 200
    ecx_explorer.txs[STATIC_ADDR] = []
    inv1 = _usd_invoice(cfg)
    inv2 = _usd_invoice(cfg)
    total = int(_ecx_pm(inv1)["amount"])
    with cfg.handle.db() as db:
        db.execute(
            "UPDATE invoices SET ecx_amount_szat = ?, ecx_amount_tweak_szats = ? WHERE id = ?",
            (total, total - 25_000_000, inv2["id"]),
        )
    txid = _txid("c")
    ecx_explorer.pay(STATIC_ADDR, total, txid, ECX_TIP)
    _drive_cron(cfg)
    cfg.greenfield.get_invoice(cfg.store_id, inv1["id"])
    cfg.greenfield.get_invoice(cfg.store_id, inv2["id"])
    return inv1, inv2, txid, total


def test_static_collision_needs_manual_confirmation_on_the_ecx_chain(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora
) -> None:
    inv1, inv2, txid, total = _force_ecx_collision(cfg, ecx_explorer)
    for inv in (inv1, inv2):
        g = cfg.greenfield.get_invoice(cfg.store_id, inv["id"])
        assert g["status"] != "Settled", g
        row = _invoice_row(cfg, inv["id"])
        assert row["ecx_needs_manual_confirmation"] == 1, row
        assert row["onchain_needs_manual_confirmation"] == 0, "Bitcoin columns untouched"
        cands = json.loads(row["ecx_manual_candidates"])
        assert len(cands) == 1 and cands[0]["txid"] == txid and cands[0]["amount_sat"] == total
    dash = _dashboard(cfg)
    assert dash["ecx"]["needsManualConfirmation"] == 2
    assert dash["onchain"]["needsManualConfirmation"] == 0

    listing = _admin_get(cfg, api="list_onchain_manual", store_id=cfg.store_id)["invoices"]
    assert {i["id"] for i in listing} == {inv1["id"], inv2["id"]}
    for i in listing:
        assert i["chain"] == "ecx" and i["unit"] == "szats"
        assert i["onchain_address"] == STATIC_ADDR and i["onchain_amount_sat"] == total
        assert i["candidates"][0]["txid"] == txid
    vout = int(listing[0]["candidates"][0]["vout"])

    # Resolving on the wrong chain is refused (the Bitcoin candidate list is empty).
    r = _admin_post(cfg, action="resolve_onchain_manual", invoice_id=inv1["id"], txid=txid, vout=str(vout), chain="btc")
    assert r.status_code == 400, r.text
    r = _admin_post(cfg, action="resolve_onchain_manual", invoice_id=inv1["id"], txid=txid, vout=str(vout), chain="xec")
    assert r.status_code == 400 and "chain" in r.json()["error"].lower()
    assert _invoice_row(cfg, inv1["id"])["status"] == "New"

    r = _admin_post(cfg, action="resolve_onchain_manual", invoice_id=inv1["id"], txid=txid, vout=str(vout), chain="ecx")
    assert r.ok, r.text
    got = _poll_until(cfg, inv1["id"], "Settled", timeout_s=15)
    assert got["paymentRail"] == "ecx" and got["txid"] == txid and got["txidChain"] == "ecx"
    row2 = _invoice_row(cfg, inv2["id"])
    assert row2["status"] == "New" and row2["ecx_needs_manual_confirmation"] == 0
    assert row2["ecx_manual_candidates"] in (None, "", "[]")
    assert _admin_get(cfg, api="list_onchain_manual", store_id=cfg.store_id)["invoices"] == []
    assert _dashboard(cfg)["ecx"]["needsManualConfirmation"] == 0
    # Re-attributing the same output to the sibling is refused.
    r = _admin_post(cfg, action="resolve_onchain_manual", invoice_id=inv2["id"], txid=txid, vout=str(vout), chain="ecx")
    assert r.status_code == 400
    err = (r.json().get("error") or "").lower()
    assert "already attributed" in err or "not listed" in err, err


# ---------------------------------------------------------------------------
# fees in ECX
# ---------------------------------------------------------------------------


def test_hosting_fee_in_ecx_routes_the_ecash_address(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, webhook_sink: WebhookSink
) -> None:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    cfg.greenfield.create_webhook(cfg.store_id, webhook_sink.endpoint("fee"), secret="s3cret")
    # Hosting fee 5%; an invalid ECX hosting xpub is refused and nothing changes.
    r = _admin_post(cfg, action="update_store", store_id=cfg.store_id, hosting_fee_percent="5",
                    hosting_fee_ecx_xpub="xpub-garbage")
    assert r.status_code == 400 and "Hosting fee ECX xpub" in (r.json().get("error") or ""), r.text
    r = _admin_post(cfg, action="update_store", store_id=cfg.store_id, hosting_fee_ecx_xpub=TEST_TPUB)
    assert r.status_code == 400, "a testnet key is not an ECX (mainnet) destination"
    assert _store_row(cfg)["hosting_fee_ecx_xpub"] is None
    r = _admin_post(cfg, action="update_store", store_id=cfg.store_id, hosting_fee_percent="5",
                    hosting_fee_ecx_xpub=HOSTING_ZPUB)
    assert r.status_code == 200, r.text
    row = _store_row(cfg)
    assert row["hosting_fee_ecx_xpub"] == HOSTING_ZPUB and row["hosting_fee_ecx_address_type"] == "P2WPKH"
    assert float(row["hosting_fee_percent"]) == 5.0
    assert _dashboard(cfg)["ecx"]["hostingFeeXpub"] == HOSTING_ZPUB

    # Nothing owed yet: the merchant keeps the address.
    preview = _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id).json()["address"]
    inv0 = _usd_invoice(cfg, "20.00")  # 0.5 ECX = 50,000,000 szats
    assert _ecx_pm(inv0)["destination"] == preview
    assert _invoice_row(cfg, inv0["id"])["ecx_fee_note"] is None

    # 20 ECX of eCash revenue -> hosting 5% = 1 ECX owed >= the next 0.5 ECX invoice.
    _seed_ecx_revenue(cfg, 2_000_000_000)
    stats = _admin_get(cfg, api="stats_summary", store_id=cfg.store_id, range="all")
    assert stats["ecx"]["fees_owed"]["hosting"] == 100_000_000
    preview = _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id).json()["address"]
    inv = _usd_invoice(cfg, "20.00")
    row = _invoice_row(cfg, inv["id"])
    assert row["ecx_fee_note"] == "HOSTING_FEE", row
    fee_addr = _ecx_pm(inv)["destination"]
    assert fee_addr != preview, "not the merchant's next address"
    assert fee_addr == row["ecx_fee_destination"]
    assert row["ecx_address_index"] == 0, "first address of the hosting xpub"
    assert inv["ecx"]["feeLabel"] == "hosting fee" and inv["ecx"]["settledToFee"] is False
    assert "feeRedirect" not in inv, "the Bitcoin fee badge is not raised by an ECX redirect"
    full = _admin_get(cfg, api="invoice_detail", id=inv["id"])
    det = full["detail"]
    assert det["ecx"]["feeNote"] == "HOSTING_FEE" and det["ecx"]["feeDestination"] == fee_addr
    fee_rows = [a for a in full["attempts"] if a["rail"] == "fee"]
    assert len(fee_rows) == 1 and fee_rows[0]["outcome"] == "ok", full["attempts"]
    # The merchant's next address was NOT consumed by the fee allocation.
    assert _admin_post(cfg, action="test_ecx_xpub", store_id=cfg.store_id).json()["address"] == preview

    # Paying the fee-routed address settles the invoice and credits the ECX ledger.
    ecx_explorer.pay(fee_addr, 50_000_000, _txid("9"), ECX_TIP)
    got = _poll_until(cfg, inv["id"], "Settled")
    assert got["paymentRail"] == "ecx" and got["ecx"]["settledToFee"] is True
    with cfg.handle.db() as db:
        credits = [dict(r) for r in db.execute(
            "SELECT * FROM ecx_fee_credits WHERE store_id = ?", (cfg.store_id,)).fetchall()]
    assert len(credits) == 1, credits
    assert credits[0]["note"] == "HOSTING_FEE" and credits[0]["amount_szat"] == 50_000_000
    assert credits[0]["invoice_id"] == inv["id"] and credits[0]["destination"] == fee_addr
    assert _count(cfg, "SELECT COUNT(*) FROM melts") == 0
    stats = _admin_get(cfg, api="stats_summary", store_id=cfg.store_id, range="all")
    assert stats["ecx"]["fees_paid"]["hosting"] == 50_000_000
    # owed: 5% of 2,050,000,000 = 102,500,000 minus 50,000,000 paid.
    assert stats["ecx"]["fees_owed"]["hosting"] == 52_500_000
    # The shop still gets its InvoiceSettled (the customer did pay).
    cfg.handle.drive_cron_until(
        lambda: any(r.json().get("type") == "InvoiceSettled" and r.json().get("invoiceId") == inv["id"]
                    for r in webhook_sink.by_path("/hook/fee")),
        timeout_s=40, label="InvoiceSettled for the fee-routed invoice",
    )


# ---------------------------------------------------------------------------
# custom ECX explorer
# ---------------------------------------------------------------------------


def test_custom_ecx_explorer_probe_exclusivity_and_fallback(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, custom_ecx_explorer: MockEsplora
) -> None:
    # The web root is not an API: refused, and the /api base is suggested.
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url=custom_ecx_explorer.url)
    assert r.status_code == 400, r.text
    err = r.json()["error"]
    assert "did not return a block height" in err and f"Did you mean {custom_ecx_explorer.url}/api" in err, err
    assert _store_row(cfg)["ecx_provider_url"] is None and _store_row(cfg)["ecx_xpub"] is None, "refused save wrote nothing"
    # Credentials in the URL are never accepted.
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url=f"http://user:pw@127.0.0.1:{custom_ecx_explorer.port}/api")
    assert r.status_code == 400
    # The API base verifies (tip echoed back) and persists; fallback off by default.
    custom_ecx_explorer.reset_hits()
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url=custom_ecx_explorer.api_url)
    assert r.status_code == 200, r.text
    assert r.json()["providerProbe"] == {"url": custom_ecx_explorer.api_url, "tipHeight": ECX_TIP}
    assert custom_ecx_explorer.hit_count("/api/blocks/tip/height") == 1
    row = _store_row(cfg)
    assert row["ecx_provider_url"] == custom_ecx_explorer.api_url and row["ecx_provider_fallback"] == 0
    assert _dashboard(cfg)["ecx"]["providerUrl"] == custom_ecx_explorer.api_url
    # Saving again with the same URL does not re-probe.
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url=custom_ecx_explorer.api_url, min_confs="2")
    assert r.status_code == 200 and r.json()["providerProbe"] is None
    assert custom_ecx_explorer.hit_count("/api/blocks/tip/height") == 1

    # The invoice's tip comes from the custom explorer (the public ECX mock is
    # module-wide and serves other stores' invoices, so assertions about it
    # are filtered by this invoice's address).
    tips_before = custom_ecx_explorer.hit_count("/api/blocks/tip/height")
    inv = _usd_invoice(cfg)
    addr = _ecx_pm(inv)["destination"]
    assert _invoice_row(cfg, inv["id"])["ecx_created_tip_height"] == ECX_TIP
    assert custom_ecx_explorer.hit_count("/api/blocks/tip/height") == tips_before + 1, (
        "allocation reads the tip from the custom explorer"
    )

    # Outage, fallback OFF: the payment (only the public chain can see it) is
    # not detected and the public explorers are never asked about the address.
    # (A poll asks the explorer for its tip before the address, so a host that
    # is down only ever records tip requests — count any request to it.)
    custom_ecx_explorer.mode = "down"
    hits_before = custom_ecx_explorer.hit_count()
    ecx_explorer.pay(addr, 25_000_000, _txid("7"), ECX_TIP)
    _drive_cron(cfg)
    assert cfg.greenfield.get_invoice(cfg.store_id, inv["id"])["status"] == "New"
    assert ecx_explorer.hit_count(f"/address/{addr}/") == 0, "custom URL is exclusive: no public request without the opt-in"
    assert custom_ecx_explorer.hit_count() > hits_before, (
        "the custom explorer keeps being tried", custom_ecx_explorer.hits[-12:])

    # Fallback ON (URL unchanged -> no probe even though the host is down):
    # the public chain is used during the outage and the payment settles.
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url=custom_ecx_explorer.api_url, provider_fallback="1", min_confs="1")
    assert r.status_code == 200, r.text
    assert r.json()["providerProbe"] is None
    assert _store_row(cfg)["ecx_provider_fallback"] == 1
    got = _poll_until(cfg, inv["id"], "Settled")
    assert got["paymentRail"] == "ecx"
    assert ecx_explorer.hit_count(f"/address/{addr}/") >= 1, "the public ECX chain answered during the outage"
    # Clearing the URL also clears the fallback flag.
    r = _save_ecx(cfg, ECX_ONLY_XPUB, provider_url="", provider_fallback="1")
    assert r.status_code == 200
    row = _store_row(cfg)
    assert row["ecx_provider_url"] is None and row["ecx_provider_fallback"] == 0


# ---------------------------------------------------------------------------
# merchant notification
# ---------------------------------------------------------------------------


def test_merchant_email_names_the_ecash_amount(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, smtp_sink: SmtpSink
) -> None:
    r = cfg.admin._post_action(
        "save_notifications_settings",
        enabled="1", invoice_paid_enabled="1", to_email="owner@example.com",
        smtp_host=smtp_sink.host, smtp_port=str(smtp_sink.port), smtp_encryption="none",
        smtp_username=smtp_sink.username, smtp_password=smtp_sink.password,
        smtp_from_address="shop@example.com", smtp_from_name="Shop",
    )
    assert r.get("success"), r
    with cfg.handle.db() as db:
        db.execute("UPDATE stores SET notifications_enabled = 1 WHERE id = ?", (cfg.store_id,))
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    addr = _ecx_pm(inv)["destination"]
    txid = _txid("5")
    ecx_explorer.pay(addr, 25_000_000, txid, ECX_TIP)
    _poll_until(cfg, inv["id"], "Settled")
    cfg.handle.drive_cron_until(
        lambda: any(inv["id"] in m.data for m in smtp_sink.messages),
        timeout_s=40, label="invoice-paid email drained",
    )
    msg = [m for m in smtp_sink.messages if inv["id"] in m.data][0]
    assert "25,000,000 szats (0.25 ECX)" in msg.subject, msg.subject
    assert "10,000 sats" not in msg.subject
    body = msg.data
    assert "Amount:      25,000,000 szats (0.25 ECX)" in body, body
    assert "Amount (fiat): 10.00 USD" in body
    assert "Paid via:    eCash (ECX)" in body
    assert f"Receiving addr: {addr} (ECX chain)" in body
    assert f"Txid:           {txid}:0" in body


# ---------------------------------------------------------------------------
# admin + payment page UI (Playwright)
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


@pytest.fixture
def payer_page(browser):
    ctx = browser.new_context(viewport={"width": 420, "height": 900})
    page = ctx.new_page()
    yield page
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
    row = _store_row(cfg)
    assert row["ecx_xpub"] == _MAINNET_VECTOR_XPUB and row["ecx_address_mode"] == "xpub"
    assert row["ecx_min_confs"] == 2 and row["ecx_confirm_timeout_sec"] == 7200

    # Reload: the card reports the saved key as shared with Bitcoin.
    _open_ecx_card(page, base, cfg.store_id)
    assert "same as your Bitcoin xpub" in page.inner_text("#ecx-xpub-meta")
    assert page.input_value("#ecx-min-confs") == "2"
    assert page.inner_text("#ecx-offer-effective").strip() == "on"


@pytest.mark.ui
def test_payment_page_ecash_tab_qr_and_wallet_link(cfg: ConfiguredPayserver, payer_page) -> None:
    """Lightning (mint) + Bitcoin + eCash on one invoice: the eCash tab is
    offered after the Bitcoin methods, opens its block, renders the ecash:
    QR, hides the Bitcoin-wallet brand row and keeps the address copyable."""
    assert _save_btc(cfg, _MAINNET_VECTOR_XPUB).status_code == 200
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    addr = _ecx_pm(inv)["destination"]
    uri = f"ecash:{addr}?amount=0.25"
    page = payer_page
    page.goto(f"{cfg.handle.url}/payment.php?id={inv['id']}", wait_until="networkidle")

    tabs = page.locator(".method-tabs [role=tab]:not(.method-tab-wallet)")
    labels = [t.strip() for t in tabs.all_inner_texts()]
    assert "eCash" in labels, labels
    assert labels.index("eCash") > labels.index("On-chain") and labels.index("eCash") > labels.index("Lightning"), (
        "eCash follows the two Bitcoin methods"
    )
    ecx_block = page.locator('.method-block[data-method-block="ecx"]')
    assert not ecx_block.is_visible(), "eCash is never the default while a Bitcoin method is offered"
    brand_row = page.locator("#payment-methods")
    assert brand_row.is_visible()

    page.click('.method-tab[data-method="ecx"]')
    ecx_block.wait_for(state="visible")
    assert page.locator('.method-tab[data-method="ecx"]').get_attribute("class").find("active") >= 0
    # QR rendered from the ecash: URI (the canvas can't be read back; the
    # container records what it encodes) and matches the open-in-wallet link.
    page.wait_for_function("() => document.getElementById('qr-ecx').querySelector('canvas') !== null")
    assert page.get_attribute("#qr-ecx", "data-qr-value") == uri
    assert page.get_attribute('[data-ecx-link]', "href") == uri
    assert "Pay with ECX wallet" in page.inner_text('[data-ecx-link]')
    text = ecx_block.inner_text()
    assert addr in text and "25,000,000 szats" in text and "(0.25 ECX)" in text
    assert "ECX wallet only" in text and "sending BTC to it will not complete this invoice" in text
    copy_btns = ecx_block.locator("[data-copy]")
    assert all(v == addr for v in [copy_btns.nth(i).get_attribute("data-copy") for i in range(copy_btns.count())])
    # The "Pay with" brand row (Bitcoin / Lightning wallets) hides on the eCash tab ...
    assert brand_row.get_attribute("data-method") == "ecx"
    assert not brand_row.is_visible()
    # ... and comes back on a Bitcoin tab.
    page.click('.method-tab[data-method="onchain"]')
    page.locator('.method-block[data-method-block="onchain"]').wait_for(state="visible")
    assert not ecx_block.is_visible()
    assert brand_row.is_visible()


@pytest.mark.ui
def test_payment_page_live_pending_and_settled_copy_for_ecash(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, payer_page
) -> None:
    """The payer keeps the page open: an unconfirmed ECX payment flips it to the
    eCash confirmation copy with the ECX explorer link, confirmation flips it
    to the settled screen and the link survives."""
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    addr = _ecx_pm(inv)["destination"]
    page = payer_page
    page.goto(f"{cfg.handle.url}/payment.php?id={inv['id']}", wait_until="networkidle")
    assert page.locator("#payment-pending").is_visible()
    assert not page.locator("#success-badge-pending").is_visible()

    txid = _txid("3")
    ecx_explorer.pay(addr, 25_000_000, txid, None)
    deadline = time.monotonic() + 40
    while time.monotonic() < deadline and not page.locator("#success-badge-pending").is_visible():
        cfg.handle.trigger_cron()
        page.wait_for_timeout(700)
    page.locator("#success-badge-pending").wait_for(state="visible", timeout=5000)
    assert "Waiting for eCash confirmation" in page.inner_text("#success-badge-pending")
    assert "eCash payment detected" in page.inner_text("#onchain-pending-copy")
    assert "ECX chain" in page.inner_text("#onchain-pending-copy")
    link = page.locator("#onchain-tx-link")
    link.wait_for(state="visible")
    assert link.inner_text().strip() == "View transaction on explorer.ecash.com"
    assert link.get_attribute("href") == f"https://explorer.ecash.com/tx/{txid}"
    assert not page.locator("#payment-pending").is_visible()

    ecx_explorer.txs[addr] = []
    ecx_explorer.pay(addr, 25_000_000, txid, ECX_TIP)
    deadline = time.monotonic() + 40
    while time.monotonic() < deadline and not page.locator("#success-badge-settled").is_visible():
        cfg.handle.trigger_cron()
        page.wait_for_timeout(700)
    page.locator("#success-badge-settled").wait_for(state="visible", timeout=5000)
    assert not page.locator("#success-badge-pending").is_visible()
    assert not page.locator("#onchain-pending-copy").is_visible()
    assert link.is_visible() and link.get_attribute("href") == f"https://explorer.ecash.com/tx/{txid}"
    assert link.inner_text().strip() == "View transaction on explorer.ecash.com"
    # A fresh load of the settled page says what was received.
    page.reload(wait_until="networkidle")
    assert "0.25 ECX" in page.inner_text("#payment-success")
    assert page.locator("#onchain-tx-link").is_visible()


def _settle_in_ecash(cfg: ConfiguredPayserver, ecx_explorer: MockEsplora) -> tuple[dict, str, str]:
    assert _save_ecx(cfg, ECX_ONLY_XPUB).status_code == 200
    inv = _usd_invoice(cfg)
    addr = _ecx_pm(inv)["destination"]
    txid = _txid("2")
    ecx_explorer.pay(addr, 25_000_000, txid, ECX_TIP)
    _poll_until(cfg, inv["id"], "Settled")
    return inv, addr, txid


@pytest.mark.ui
def test_admin_invoice_list_and_detail_show_the_ecash_leg(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, admin_page
) -> None:
    inv, addr, txid = _settle_in_ecash(cfg, ecx_explorer)
    page, base = admin_page
    page.goto(f"{base}/admin/invoices", wait_until="networkidle")
    row = page.locator(f"tr.inv-row[data-invoice-id='{inv['id']}']")
    row.wait_for(state="visible", timeout=15000)
    text = row.inner_text()
    assert "eCash (ECX)" in text and "Paid" in text, text
    links = row.locator("a[href^='https://explorer.ecash.com/']")
    hrefs = [links.nth(i).get_attribute("href") for i in range(links.count())]
    assert f"https://explorer.ecash.com/address/{addr}" in hrefs, hrefs
    assert f"https://explorer.ecash.com/tx/{txid}" in hrefs, hrefs
    assert row.locator("a[href^='https://mempool.space/']").count() == 0, "an eCash settlement never links mempool.space"

    row.locator("td").nth(3).click()
    modal = page.locator("#modal-invoice-detail")
    modal.wait_for(state="visible", timeout=10000)
    page.wait_for_selector("#invoice-detail-id", timeout=10000)
    assert inv["id"] in page.locator("#invoice-detail-id").inner_text()
    content = page.locator("#invoice-detail-content")
    body = content.inner_text()
    assert "eCash (ECX)" in body, body
    assert "25000000 szats (0.25 ECX)" in body, body
    assert "40 USD per ECX" in body, body
    assert "1 confirmation" in body, body
    assert "🔗 On-chain" not in body, "no Bitcoin on-chain group on an invoice without a Bitcoin address"
    detail_links = content.locator("a[href^='https://explorer.ecash.com/']")
    dhrefs = [detail_links.nth(i).get_attribute("href") for i in range(detail_links.count())]
    assert f"https://explorer.ecash.com/tx/{txid}" in dhrefs and f"https://explorer.ecash.com/address/{addr}" in dhrefs
    ok_rows = content.locator(".invd-attempt[data-rail='ecx'][data-outcome='ok']")
    assert ok_rows.count() >= 1, body
    assert addr in ok_rows.first.inner_text()


@pytest.mark.ui
def test_admin_stats_rows_show_the_ecash_ledger(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, admin_page
) -> None:
    _settle_in_ecash(cfg, ecx_explorer)
    page, base = admin_page
    page.goto(f"{base}/admin/stats", wait_until="networkidle")
    # The stats view has its own store selector (default: all stores on this
    # shared server); pin it to this test's store.
    page.wait_for_function(
        "(id) => [...document.querySelectorAll('#stats-store-selector option')].some(o => o.value === id)",
        arg=cfg.store_id, timeout=30000,
    )
    page.select_option("#stats-store-selector", cfg.store_id)
    page.wait_for_function(
        "() => { const r = document.getElementById('stats-ecx-revenue-row');"
        " return r && !r.classList.contains('hidden') && r.innerText.includes('25,000,000 szats'); }",
        timeout=30000,
    )
    revenue_row = page.inner_text("#stats-ecx-revenue-row")
    assert "eCash (ECX) revenue" in revenue_row
    assert "25,000,000 szats (0.25 ECX)" in revenue_row, revenue_row
    assert "$10" in revenue_row, revenue_row
    owed_row = page.inner_text("#stats-ecx-owed-row")
    assert "Fees owed (eCash)" in owed_row and "250,000 szats" in owed_row, owed_row
    assert "not included" not in owed_row, "the price is available, so the figure is folded into the total"
    dev_row = page.inner_text("#stats-fee-ecx-dev-row")
    assert "Dev fee (eCash)" in dev_row and "0 szats" in dev_row, dev_row
    # The headline total folds the eCash revenue in as sats (0.25 ECX = 10,000 sats).
    assert "10,000" in page.inner_text("#stats-revenue")


@pytest.mark.ui
def test_admin_manual_confirmation_banner_and_card_for_ecash(
    cfg: ConfiguredPayserver, ecx_explorer: MockEsplora, admin_page
) -> None:
    inv1, inv2, txid, total = _force_ecx_collision(cfg, ecx_explorer)
    page, base = admin_page
    page.goto(f"{base}/admin", wait_until="networkidle")
    banner = page.locator("#onchain-manual-banner")
    banner.wait_for(state="visible", timeout=30000)
    assert "2 eCash payments need manual confirmation." == page.inner_text("#onchain-manual-banner-text").strip()

    page.goto(f"{base}/admin/invoices", wait_until="networkidle")
    card = page.locator("#card-onchain-manual")
    card.wait_for(state="visible", timeout=30000)
    page.wait_for_selector("#onchain-manual-list [data-manual-action='attribute']", timeout=30000)
    text = card.inner_text()
    assert "eCash (ECX)" in text and f"{total} szats" in text, text
    assert "sats" not in text.replace("szats", ""), "no Bitcoin unit on an eCash ambiguity"
    buttons = page.locator("#onchain-manual-list [data-manual-action='attribute']")
    assert buttons.count() == 2
    for i in range(2):
        assert buttons.nth(i).get_attribute("data-chain") == "ecx"
        assert buttons.nth(i).get_attribute("data-txid") == txid
    chosen = page.locator(f"#onchain-manual-list [data-manual-action='attribute'][data-invoice-id='{inv1['id']}']")
    chosen.click()
    page.wait_for_function(
        "() => { const c = document.getElementById('card-onchain-manual');"
        " return c && c.classList.contains('hidden'); }",
        timeout=30000,
    )
    got = cfg.greenfield.get_invoice(cfg.store_id, inv1["id"])
    assert got["status"] == "Settled" and got["paymentRail"] == "ecx" and got["txid"] == txid
    row2 = _invoice_row(cfg, inv2["id"])
    assert row2["status"] == "New" and row2["ecx_needs_manual_confirmation"] == 0
    page.goto(f"{base}/admin", wait_until="networkidle")
    page.wait_for_function(
        "() => typeof dashboardLoadSeq === 'number' && dashboardRenderedSeq === dashboardLoadSeq"
    )
    assert not page.locator("#onchain-manual-banner").is_visible()
