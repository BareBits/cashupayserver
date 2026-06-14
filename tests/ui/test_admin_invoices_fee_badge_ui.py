"""Admin invoices table: fee-redirect badge + Lightning destination/txid.

Drives the real renderInvoicesTable() with mock invoice data via page.evaluate
so we assert the DOM the operator actually sees — the fee-redirect badge only
showing for actual redirects, and Lightning destinations/bolt11 rendered
copy-only (no block-explorer link) — without standing up the full LNURL /
fee-redirect payment flow.
"""
from __future__ import annotations

import json

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


# bolt11 strings are bech32-ish; only the head...tail shows, full value rides
# in title + data-copy.
LN_BOLT11 = "lnbc50u1pexamplebolt11invoicestringaaaaaaaaaaaaaaaa"
FEE_BOLT11 = "lnbc20u1pexamplefeebolt11bbbbbbbbbbbbbbbbbbbbbbbbbb"

MOCK_INVOICES = [
    {  # Normal LN-address payment: LN destination + bolt11 txid, no badge.
        "id": "inv_lnaddr", "status": "Settled", "amount": "5000", "currency": "sat",
        "paymentRail": "lnaddress", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {"itemDesc": "ln addr pay"},
        "txid": LN_BOLT11, "txidIsLightning": True,
        "destination": "merchant@example.test", "destinationIsLightning": True,
        "network": "regtest",
    },
    {  # Fee redirect settled to the fee: "Fee payment" badge + fee destination.
        "id": "inv_fee", "status": "Settled", "amount": "2000", "currency": "sat",
        "paymentRail": "lnaddress", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {},
        "txid": FEE_BOLT11, "txidIsLightning": True,
        "destination": "dev-fee@example.test", "destinationIsLightning": True,
        "feeRedirect": {
            "note": "DEV_FEE", "label": "dev fee", "destination": "dev-fee@example.test",
            "rails": ["lightning"], "mixed": False, "settled": True, "settledToFee": True,
        },
        "network": "regtest",
    },
    {  # Pending mixed invoice: NOT yet redirected -> no badge at all.
        "id": "inv_mixed", "status": "New", "amount": "1000", "currency": "sat",
        "paymentRail": "lnaddress", "createdTime": 1700000000, "metadata": {},
        "feeRedirect": {
            "note": "DEV_FEE", "label": "dev fee", "destination": "dev-fee@example.test",
            "rails": ["lightning"], "mixed": True, "settled": False, "settledToFee": False,
        },
        "network": "regtest",
    },
    {  # On-chain: destination + txid still link to the block explorer.
        "id": "inv_oc", "status": "Settled", "amount": "25000", "currency": "sat",
        "paymentRail": "onchain", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {},
        "txid": "abc123def456abc123def456",
        "destination": "bc1qexampleonchainaddr0000000000000000",
        "network": "regtest",
    },
]


def test_invoices_table_badge_and_lightning_cells(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)

    # Render the table with our mock data using the page's own render function.
    page.evaluate(
        "(rows) => renderInvoicesTable('all-invoices', rows)",
        MOCK_INVOICES,
    )
    html = page.inner_html("#all-invoices")

    # Fee-redirect badge shows for the settled-to-fee row...
    assert "Fee payment" in html, f"expected fee badge; got:\n{html}"
    # ...but the pending mixed invoice (not actually redirected) shows nothing.
    assert "Fee-eligible" not in html, "pending mixed should not show a fee badge"

    # Lightning destination + bolt11 are copy-only (data-copy carries the full
    # value; class marks the copy renderer).
    assert 'data-copy="merchant@example.test"' in html, html
    assert f'data-copy="{LN_BOLT11}"' in html, html
    assert "inv-copy" in html, "lightning cells should use the copy renderer"

    # On-chain row still links to the explorer, but the bolt11/LN-address
    # lightning values are never wrapped in a mempool.space link.
    assert "mempool.space" in html, "on-chain cells should still link to mempool"
    assert "mempool.space/tx/lnbc" not in html, "bolt11 must not be linked as a txid"
    assert "mempool.space/address/merchant" not in html, "LN address must not be an address link"
