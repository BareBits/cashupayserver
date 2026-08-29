"""End-to-end: the Strike API direct-receive rail against the mock Strike API.

The payserver is started with CASHUPAY_STRIKE_API_BASE pointing at
fixtures/strike_api.py, so the real StrikeClient code path runs — bearer
auth, BTC-denominated create, quote, read-back — without touching strike.me:

    store configured with a Strike API key (the save-time probe issues a
    real 1-sat create + quote + read round trip through the mock)
      -> cashupayserver creates an invoice (Strike create + quote -> the
         bolt11 shown to the customer is the mock's lnInvoice) with our
         invoice id as the Strike correlationId
      -> "payment" is simulated by flipping the mock invoice to PAID
      -> settlement is confirmed by the read-back — via the payment page's
         2s JSON poll in one test and via cron.php in the other.

The live-key counterpart is tests/php/test_strike_live_api.php (opt-in via
STRIKE_TEST_API_KEY).
"""
from __future__ import annotations

import sqlite3
import time

import requests

from conftest import ConfiguredPayserver
from fixtures.api_client import AdminClient
from fixtures.strike_api import TEST_STRIKE_KEY, StrikeApiServer

INVOICE_AMOUNT_SAT = 1_000


def save_strike_destination(admin: AdminClient, store_id: str, key: str) -> dict:
    """Configure a single Strike API key via save_lightning_payments (runs
    the save-time probe against the mock)."""
    r = admin.s.post(
        admin._admin_url,
        data=[
            ("action", "save_lightning_payments"),
            ("store_id", store_id),
            ("strike[]", key),
        ],
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body
    return body


def _invoice_row(payserver, invoice_id: str) -> dict:
    db = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert row is not None, f"no invoices row for {invoice_id}"
        return dict(row)


def _poll_page_until(payserver, invoice_id: str, status: str, timeout: float = 45.0) -> dict:
    """Drive the customer payment page's JSON poll until the invoice reaches
    ``status``. Each hit runs Invoice::pollSingleQuote server-side, i.e. the
    Strike read-back behind its min-interval throttle."""
    deadline = time.monotonic() + timeout
    last: dict = {}
    while time.monotonic() < deadline:
        r = requests.get(
            f"{payserver.url}/payment.php",
            params={"id": invoice_id, "json": "1"}, timeout=30,
        )
        assert r.status_code == 200, r.text
        last = r.json()
        if last.get("status") == status:
            return last
        time.sleep(1)
    raise AssertionError(f"invoice {invoice_id} not {status} within {timeout}s; last={last}")


def _create_strike_invoice(configured: ConfiguredPayserver) -> tuple[str, str, dict]:
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {}).get("paymentMethods", {})
        .get("BTC-LightningNetwork", {}).get("destination")
    )
    assert bolt11 and bolt11.startswith("lnbcmockstrike"), f"expected mock strike bolt11, got {bolt11}"
    row = _invoice_row(configured.handle, invoice_id)
    assert row["payment_rail"] == "strike", row
    assert row["strike_invoice_id"], row
    assert row["strike_api_key"] == TEST_STRIKE_KEY, row
    return invoice_id, bolt11, row


def test_strike_receive_round_trip(
    shared_configured_with_strike: ConfiguredPayserver, strike_api_shared: StrikeApiServer
) -> None:
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle

    # 1. Save the key — the probe runs create+quote+read through the mock.
    # The mock is module-scoped (other tests' invoices accumulate in it), so
    # the probe assertion counts only invoices created by THIS save.
    ids_before = set(strike_api.invoices)
    save = save_strike_destination(admin, store_id, TEST_STRIKE_KEY)
    results = save.get("addresses") or []
    assert results and results[0]["type"] == "strike", save
    assert TEST_STRIKE_KEY not in str(save), "save response must never echo the key"
    assert results[0]["address"].startswith("Strike API ("), results
    probe_invoices = [
        inv for iid, inv in strike_api.invoices.items() if iid not in ids_before
    ]
    assert len(probe_invoices) == 1, "the probe issued exactly one test invoice"
    assert probe_invoices[0]["body"]["amount"]["amount"] == "0.00000001", "probe is 1 sat"

    # The dashboard payload returns a masked label + keep ref, never the key.
    dash = admin.s.get(
        admin._admin_url, params={"api": "dashboard", "store_id": store_id}, timeout=30
    ).json()
    rows = dash["autoMelt"]["addresses"]
    assert rows and rows[0]["type"] == "strike", rows
    assert TEST_STRIKE_KEY not in str(dash), "dashboard payload must never contain the key"
    assert rows[0]["address"].startswith("Strike API ("), rows
    assert rows[0].get("ref", "").startswith("keep:"), rows

    # 2. Create an invoice: the bolt11 comes from the Strike quote and the
    # Strike invoice carries our invoice id as its correlationId.
    invoice_id, bolt11, row = _create_strike_invoice(configured)
    assert TEST_STRIKE_KEY not in (row["ln_destination"] or ""), "ln_destination must be masked"
    strike_inv = strike_api.invoices[row["strike_invoice_id"]]
    assert strike_inv["body"].get("correlationId") == invoice_id, strike_inv
    assert strike_inv["body"]["amount"] == {"amount": "0.00001000", "currency": "BTC"}

    # Pre-payment page poll leaves it New (read-back says UNPAID).
    pending = requests.get(
        f"{payserver.url}/payment.php", params={"id": invoice_id, "json": "1"}, timeout=30
    ).json()
    assert pending["status"] == "New", pending

    # 3. "The customer pays": the mock flips to PAID.
    strike_api.mark_paid(row["strike_invoice_id"])

    # 4. The payment page poll confirms settlement via the read-back.
    settled = _poll_page_until(payserver, invoice_id, "Settled")
    assert settled["status"] == "Settled"
    final = _invoice_row(payserver, invoice_id)
    assert final["settled_rail"] == "strike", final

    # The Greenfield payload shows the masked destination, never the key.
    api_view = configured.greenfield.get_invoice(store_id, invoice_id)
    assert TEST_STRIKE_KEY not in str(api_view), "API payload must never contain the key"
    assert api_view.get("destination", "").startswith("Strike API ("), api_view


def test_strike_settles_via_cron_after_tab_closed(
    shared_configured_with_strike: ConfiguredPayserver, strike_api_shared: StrikeApiServer
) -> None:
    """The Strike read-back is a stored-state query, so cron settles a Strike
    invoice nobody is watching."""
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    payserver = configured.handle
    save_strike_destination(configured.admin, configured.store_id, TEST_STRIKE_KEY)

    invoice_id, _bolt11, row = _create_strike_invoice(configured)
    strike_api.mark_paid(row["strike_invoice_id"])

    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        cron = payserver.trigger_cron()
        assert cron.status_code == 200, cron.text
        if _invoice_row(payserver, invoice_id)["status"] == "Settled":
            break
        time.sleep(2)
    final = _invoice_row(payserver, invoice_id)
    assert final["status"] == "Settled", final
    assert final["settled_rail"] == "strike", final


def test_strike_failure_falls_back_to_mint(
    shared_configured_with_strike: ConfiguredPayserver, strike_api_shared: StrikeApiServer
) -> None:
    """A dead Strike API doesn't take Lightning off the checkout: the walk
    falls through to the store's mint and the payer-facing receive_errors
    records a sanitized strike entry with no key material.

    fail_create is a mock-global toggle, but tests run sequentially and only
    this test issues creates while it is set (read-backs use GET); it is
    restored in the finally, so sharing the module mock is safe."""
    configured = shared_configured_with_strike
    strike_api = strike_api_shared
    save_strike_destination(configured.admin, configured.store_id, TEST_STRIKE_KEY)

    strike_api.fail_create = 500
    try:
        invoice = configured.greenfield.create_invoice(
            configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
        )
    finally:
        strike_api.fail_create = None
    row = _invoice_row(configured.handle, invoice["id"])
    assert row["payment_rail"] == "mint", row
    assert row["bolt11"] and row["bolt11"].lower().startswith("lnbcrt"), row
    assert row["strike_api_key"] is None, "no strike context on a non-strike rail"
    errors = row["receive_errors"]
    assert errors and '"strike"' in errors, errors
    assert TEST_STRIKE_KEY not in errors, "receive_errors must never contain the key"
