"""Unified on-chain detected/complete screen on payment.php.

When an on-chain payment is detected (Processing + onchain_first_seen_at) the
page shows the unified screen in pending mode: the "waiting for on-chain
confirmation" copy, a pending badge, the email form — but no Continue-to-Store
link. The payer can leave their email while the tx confirms (receipt queued at
settlement). When the invoice settles, the JS poller flips only the
mode-specific elements: badge becomes "Payment Complete", pending copy
disappears, and the email the payer typed survives untouched.

The on-chain state is driven through the DB directly (status +
onchain_first_seen_at), mirroring what OnchainPayments::applyTransitions does
on a mempool sighting — the full bitcoind pipeline is covered by
tests/e2e/test_onchain_payments.py; this test pins the customer-facing UI.
"""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui

PENDING_COPY = "Payment detected, waiting for on-chain confirmation"


def _enable_payer_receipts(configured: ConfiguredPayserver) -> None:
    """Flip the receipt gates (master switch, per-type toggle, SMTP host) so
    the pending email submit records a receipt request. Config values are
    stored JSON-encoded for non-strings, raw for strings."""
    now = int(time.time())
    with configured.handle.db() as conn:
        for key, value in (
            ("notifications_enabled", "true"),
            ("notifications_payer_receipt_enabled", "true"),
            ("smtp_host", "smtp.test"),
        ):
            conn.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                (key, value, now, now),
            )


def test_onchain_pending_screen_flips_to_settled_preserving_email(
    configured: ConfiguredPayserver,
    page,
) -> None:
    # Stays on the function-scoped `configured` fixture: _enable_payer_receipts
    # writes server-global config rows (notifications_enabled, smtp_host) and
    # never restores them, which would leak into a module-shared server.
    page.set_default_timeout(20000)
    _enable_payer_receipts(configured)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    invoice_id = invoice["id"]

    # Simulate the on-chain poller's mempool sighting (New -> Processing +
    # first-seen stamp).
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET status = 'Processing', onchain_first_seen_at = ? WHERE id = ?",
            (int(time.time()), invoice_id),
        )

    page.goto(f"{configured.handle.url}/payment?id={invoice_id}")

    # Unified screen in pending mode: confirmation copy + pending badge shown,
    # settled badge hidden, and the generic processing block NOT shown.
    page.wait_for_selector("#payment-success.show", state="attached")
    page.wait_for_selector("#onchain-pending-copy", state="visible")
    assert PENDING_COPY in page.locator("#onchain-pending-copy").inner_text()
    assert "Please do not close this window" in page.locator("#onchain-pending-copy").inner_text()
    page.wait_for_selector("#success-badge-pending", state="visible")
    assert page.locator("#success-badge-settled").is_hidden()
    assert page.locator("#payment-processing").is_hidden()

    # The payer leaves their email while the tx is still confirming.
    page.fill("#receipt-email", "payer@example.com")
    page.click("#receipt-submit")
    page.wait_for_function(
        "() => (document.getElementById('receipt-status')?.textContent || '')"
        ".includes('will be emailed once the payment confirms')"
    )
    with configured.handle.db() as conn:
        row = conn.execute(
            "SELECT customer_email, payer_receipt_requested FROM invoices WHERE id = ?",
            (invoice_id,),
        ).fetchone()
        assert row["customer_email"] == "payer@example.com"
        assert row["payer_receipt_requested"] == 1
        queued = conn.execute(
            "SELECT COUNT(*) AS c FROM notification_queue WHERE invoice_id = ? AND event_type = 'PayerReceipt'",
            (invoice_id,),
        ).fetchone()
        assert queued["c"] == 0, "receipt must not be queued before settlement"

    # Confirmation lands: the poller flips the badge without a reload...
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET status = 'Settled', paid_at = ? WHERE id = ?",
            (int(time.time()), invoice_id),
        )
    page.wait_for_selector("#success-badge-settled", state="visible")
    assert "Payment Complete" in page.locator("#success-badge-settled").inner_text()
    assert page.locator("#onchain-pending-copy").is_hidden()
    assert page.locator("#success-badge-pending").is_hidden()

    # ...the typed email survives the transition...
    assert page.input_value("#receipt-email") == "payer@example.com"

    # ...and the settlement poll queues exactly one receipt to that address.
    deadline = time.monotonic() + 15
    count = 0
    while time.monotonic() < deadline:
        with configured.handle.db() as conn:
            count = conn.execute(
                "SELECT COUNT(*) AS c FROM notification_queue "
                "WHERE invoice_id = ? AND event_type = 'PayerReceipt' AND to_email = ?",
                (invoice_id, "payer@example.com"),
            ).fetchone()["c"]
        if count == 1:
            break
        time.sleep(0.5)
    assert count == 1, "settlement poll should queue exactly one payer receipt"

    with configured.handle.db() as conn:
        flag = conn.execute(
            "SELECT payer_receipt_requested FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()["payer_receipt_requested"]
    assert flag == 0, "request flag cleared after the receipt is queued"


def test_transient_processing_keeps_generic_block(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """Processing WITHOUT an on-chain observation (Cashu minting after a
    Lightning payment) keeps the old generic block — no confirmation copy."""
    configured = shared_configured
    page.set_default_timeout(15000)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE invoices SET status = 'Processing' WHERE id = ?", (invoice["id"],)
        )

    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_selector("#payment-processing", state="visible")
    assert "Payment detected. Please wait..." in page.locator("#payment-processing").inner_text()
    assert page.locator("#onchain-pending-copy").is_hidden()
