"""End-to-end: NWC (NIP-47) direct-receive round trip against a live rig.

The fake NWC wallet (fixtures/nwc_wallet.py) is backed by the session's
regtest ``lnd_mint`` node and connected to the in-rig Nostr relay; the
payserver acts as the NIP-47 client:

    store configured with a nostr+walletconnect:// string (the save-time
    probe mints a real 1-sat test invoice through the wallet)
      -> cashupayserver creates an invoice (NwcClient.makeInvoice over the
         relay -> the wallet answers with a real lnbcrt bolt11 from LND)
      -> the customer LND node pays that bolt11
      -> settlement is confirmed by lookup_invoice — via the payment page's
         2s JSON poll in one test and via cron.php in the other (the path
         that covers a customer who closed the tab).

This is the live counterpart to the PHP mock-wallet suite
(tests/php/test_nwc_roundtrip.php / test_nwc_invoice_rail.php).
"""
from __future__ import annotations

import sqlite3
import time
from pathlib import Path
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.lnd import LndHandle
from fixtures.nwc_wallet import NwcStack, start_nwc_stack, stop_nwc_stack

INVOICE_AMOUNT_SAT = 1_000


@pytest.fixture
def nwc_stack(lnd_mint: LndHandle, channels: None) -> Iterator[NwcStack]:
    """Relay + fake wallet minting from lnd_mint (lnd_payer pays over the
    session's dual channels). get_info reports a receive-only connection."""
    workdir = SESSION_TMP / f"nwc-stack-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_mint, info_methods=["make_invoice", "lookup_invoice", "get_info"]
    )
    yield stack
    stop_nwc_stack(stack)


def save_nwc_destination(admin: AdminClient, store_id: str, uri: str,
                         enabled: str = "1") -> dict:
    """Configure a single NWC destination via the split-list contract:
    save_lightning_payments carries the chain (and the probe results this
    returns), save_auto_melt the cashout toggle."""
    data = [
        ("action", "save_lightning_payments"),
        ("store_id", store_id),
        ("nwc[]", uri),
    ]
    r = admin.s.post(
        admin._admin_url, data=data,
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body

    r = admin.s.post(
        admin._admin_url,
        data=[
            ("action", "save_auto_melt"),
            ("store_id", store_id),
            ("enabled", enabled),
            ("threshold", "100"),
            ("mode_override", "0"),
        ],
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )
    assert r.status_code == 200, r.text
    assert r.json().get("success"), r.text
    return body


def _invoice_row(payserver, invoice_id: str) -> dict:
    db = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert row is not None, f"no invoices row for {invoice_id}"
        return dict(row)


def _poll_page_until(payserver, invoice_id: str, status: str, timeout: float = 45.0) -> dict:
    """Drive the customer payment page's JSON poll (the 2s tick) until the
    invoice reaches ``status``. Each hit runs Invoice::pollSingleQuote server-
    side, i.e. the NWC lookup_invoice throttle + round trip."""
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


def _create_nwc_invoice(configured: ConfiguredPayserver) -> tuple[str, str, dict]:
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {}).get("paymentMethods", {})
        .get("BTC-LightningNetwork", {}).get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), f"expected regtest bolt11, got {bolt11}"
    row = _invoice_row(configured.handle, invoice_id)
    assert row["payment_rail"] == "nwc", row
    assert row["nwc_payment_hash"], row
    assert row["nwc_uri"], row
    return invoice_id, bolt11, row


def test_nwc_receive_round_trip(
    configured: ConfiguredPayserver, nwc_stack: NwcStack, lnd_payer: LndHandle
) -> None:
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle
    uri = nwc_stack.wallet.connection_uri()
    secret = uri.split("secret=", 1)[1].split("&", 1)[0]

    # 1. Save the connection — the probe mints a real 1-sat test invoice.
    save = save_nwc_destination(admin, store_id, uri)
    results = save.get("addresses") or []
    assert results and results[0]["type"] == "nwc", save
    assert secret not in str(save), "save response must never echo the secret"
    assert results[0].get("warning") in (None, ""), (
        "receive-only connection must not trigger the spend warning"
    )

    # The dashboard payload returns a masked label + keep ref, never the URI.
    dash = admin.s.get(
        admin._admin_url, params={"api": "dashboard", "store_id": store_id}, timeout=30
    ).json()
    rows = dash["autoMelt"]["addresses"]
    assert rows and rows[0]["type"] == "nwc", rows
    assert secret not in str(dash), "dashboard payload must never contain the secret"
    assert rows[0]["address"].startswith("NWC wallet "), rows
    assert rows[0].get("ref", "").startswith("keep:"), rows

    # 2. Create an invoice: the bolt11 comes from the wallet's LND.
    invoice_id, bolt11, row = _create_nwc_invoice(configured)
    assert secret not in (row["ln_destination"] or ""), "ln_destination must be masked"

    # Pre-payment page poll leaves it New (lookup says pending).
    pending = requests.get(
        f"{payserver.url}/payment.php", params={"id": invoice_id, "json": "1"}, timeout=30
    ).json()
    assert pending["status"] == "New", pending

    # 3. The customer pays the bolt11.
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=60)
    assert not pay.get("payment_error"), pay

    # 4. The payment page poll confirms settlement via lookup_invoice.
    settled = _poll_page_until(payserver, invoice_id, "Settled")
    assert settled["status"] == "Settled"
    final = _invoice_row(payserver, invoice_id)
    assert final["settled_rail"] == "nwc", final
    assert final["nwc_preimage"], "settlement proof (preimage) recorded"

    # The Greenfield payload shows the masked destination, never the secret.
    api_view = configured.greenfield.get_invoice(store_id, invoice_id)
    assert secret not in str(api_view), "API payload must never contain the secret"
    assert api_view.get("destination", "").startswith("NWC wallet "), api_view


def test_nwc_settles_via_cron_after_tab_closed(
    configured: ConfiguredPayserver, nwc_stack: NwcStack, lnd_payer: LndHandle
) -> None:
    """lookup_invoice is a stored-state query, so cron settles an NWC invoice
    nobody is watching — the shared-hosting answer to 'no background threads'."""
    admin = configured.admin
    payserver = configured.handle
    save_nwc_destination(admin, configured.store_id, nwc_stack.wallet.connection_uri())

    invoice_id, bolt11, _row = _create_nwc_invoice(configured)
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=60)
    assert not pay.get("payment_error"), pay

    # No page polls — only cron. (Age the throttle claim first: invoice
    # creation itself never sets last_polled_at, so the first cron poll runs.)
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        cron = payserver.trigger_cron()
        assert cron.status_code == 200, cron.text
        if _invoice_row(payserver, invoice_id)["status"] == "Settled":
            break
        time.sleep(2)
    final = _invoice_row(payserver, invoice_id)
    assert final["status"] == "Settled", final
    assert final["settled_rail"] == "nwc", final


def test_nwc_direct_receive_without_auto_cashout(
    configured: ConfiguredPayserver, nwc_stack: NwcStack
) -> None:
    """Configuring an NWC destination is enough for direct-receive; the
    auto-cashout toggle only governs the cron melt (noffer-parity guard)."""
    payserver = configured.handle
    save_nwc_destination(
        configured.admin, configured.store_id,
        nwc_stack.wallet.connection_uri(), enabled="0",
    )
    with sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        store = conn.execute(
            "SELECT auto_melt_enabled FROM stores WHERE id = ?", (configured.store_id,)
        ).fetchone()
    assert store["auto_melt_enabled"] == 0, dict(store)

    invoice_id, _bolt11, row = _create_nwc_invoice(configured)
    assert row["payment_rail"] == "nwc", row
