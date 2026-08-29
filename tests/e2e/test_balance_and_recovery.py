"""Local proof-based balance and proof persistence after settlement.

The dashboard balance sums cashu_proofs by wallet_id = sha256(mint:unit) —
it is per MINT, not per store, so on the shared server every store's settles
pool into one number. Balance assertions are therefore deltas around this
test's own settles (tests in a module run sequentially, so the delta is
exact), never absolute totals.
"""
from __future__ import annotations

import time

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle


INVOICE_AMOUNT_SAT = 1500


def _wallet_balance(shared_configured: ConfiguredPayserver) -> int:
    r = shared_configured.admin.s.get(
        f"{shared_configured.handle.url}/admin?api=dashboard&store_id={shared_configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    # Local balance reads from cached proofs only — no mint contact required.
    return r.json()["balance"]


def _settle_an_invoice(shared_configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> str:
    """Create + pay + wait for settle. Returns invoice ID."""
    invoice = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount=str(amount_sat), currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        got = shared_configured.greenfield.get_invoice(shared_configured.store_id, invoice["id"])
        if got["status"] == "Settled":
            return invoice["id"]
        time.sleep(0.3)
    raise AssertionError(f"invoice {invoice['id']} did not settle")


def test_local_balance_matches_settled_amount(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    before = _wallet_balance(shared_configured)
    _settle_an_invoice(shared_configured, lnd_payer, INVOICE_AMOUNT_SAT)
    assert _wallet_balance(shared_configured) - before == INVOICE_AMOUNT_SAT


def test_proofs_persisted_locally_after_settle(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    _settle_an_invoice(shared_configured, lnd_payer, INVOICE_AMOUNT_SAT)

    with shared_configured.handle.db() as db:
        proof_tables = db.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%proof%'"
        ).fetchall()
        assert proof_tables, "no proof storage tables found"
        # WalletStorage::initializeSchema creates the proofs table; count rows.
        total_proofs = 0
        for t in proof_tables:
            name = t["name"]
            try:
                row = db.execute(f"SELECT COUNT(*) AS c FROM {name}").fetchone()
                total_proofs += row["c"]
            except Exception:
                pass
        assert total_proofs > 0, "no proofs stored after settle"


def test_subsequent_invoice_adds_to_balance(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    before = _wallet_balance(shared_configured)
    _settle_an_invoice(shared_configured, lnd_payer, 1000)
    _settle_an_invoice(shared_configured, lnd_payer, 500)
    assert _wallet_balance(shared_configured) - before == 1500
