"""Happy path: create invoice, pay it via Lightning, observe settlement + webhook.

This is the first vertical-slice test that exercises the whole stack:
bitcoind -> two LND nodes -> nutshell mint -> cashupayserver -> webhook sink.
"""
from __future__ import annotations

import time

import pytest

from fixtures.api_client import AdminClient, GreenfieldClient
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import run_setup_wizard
from fixtures.webhook_sink import WebhookSink

ADMIN_PASSWORD = "test-admin-pw-1234"
STORE_NAME = "Test Store"
INVOICE_AMOUNT_SAT = "1000"


def _poll_invoice_until(gc: GreenfieldClient, store_id: str, invoice_id: str, status: str, timeout_s: float = 30) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        last = gc.get_invoice(store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(
        f"invoice {invoice_id} did not reach {status} within {timeout_s}s; last={last}"
    )


def test_invoice_payment_settles_end_to_end(
    payserver: PayserverHandle,
    mint: MintHandle,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
) -> None:
    # 1. Walk the setup wizard.
    run_setup_wizard(
        payserver.url,
        admin_password=ADMIN_PASSWORD,
        store_name=STORE_NAME,
        mint_url=mint.url,
        mint_unit="sat",
    )

    # 2. Login as admin, find the store, mint an API key.
    admin = AdminClient(payserver.url)
    admin.login(ADMIN_PASSWORD)
    stores = admin.list_stores()
    assert stores, "setup should have created a store"
    store_id = stores[0]["id"]

    key = admin.create_api_key(store_id, label="e2e-test")
    token = key.get("key") or key.get("apiKey") or key.get("token")
    assert token, f"expected api key in response, got {key}"

    gc = GreenfieldClient(payserver.url, token)

    # 3. Register a webhook so we can observe state changes.
    webhook_url = webhook_sink.endpoint("settle")
    secret = "wh-secret-e2e"
    gc.create_webhook(store_id, webhook_url, secret=secret)

    # 4. Create an invoice in sats — bypasses fiat rate fetch.
    invoice = gc.create_invoice(store_id, amount=INVOICE_AMOUNT_SAT, currency="sat")
    invoice_id = invoice["id"]
    assert invoice["status"] in ("New", "Processing"), invoice

    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), f"expected regtest BOLT11, got {bolt11}"

    # 5. Pay it from the customer-side LND node.
    pay_result = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay_result.get("payment_error"), f"payment failed: {pay_result}"
    assert pay_result.get("payment_preimage"), f"missing preimage: {pay_result}"

    # 6. Poll until cashupayserver flips the invoice to Settled.
    settled = _poll_invoice_until(gc, store_id, invoice_id, "Settled", timeout_s=30)
    assert settled["status"] == "Settled"

    # 7. Webhook should have been delivered for the settle event.
    captured = webhook_sink.wait_for("/hook/settle", count=1, timeout_s=15)
    delivered_types = {r.json().get("type") for r in captured}
    assert "InvoiceSettled" in delivered_types, f"missing InvoiceSettled in {delivered_types}"
