"""Admin invoices table: payment-method chip labels for Cashu-related rails.

Drives the real renderInvoicesTable() with mock invoice data via page.evaluate
(same approach as test_admin_invoices_fee_badge_ui) so we assert the chip text
the operator actually sees: mint-quote Lightning receives labelled
"Lightning (cashu)(<mint host>)", direct ecash-token receives labelled
"Cashu(<mint host>)", other rails untouched.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


MOCK_INVOICES = [
    {  # Mint-quote Lightning receive: mint host in the label.
        "id": "inv_mint", "status": "Settled", "amount": "800", "currency": "sat",
        "paymentRail": "mint", "mintUrl": "https://mint.example.com/Bitcoin",
        "createdTime": 1700000000, "paidTime": 1700000100, "metadata": {},
        "network": "regtest",
    },
    {  # Direct ecash-token receive: Cashu label with the token's mint host.
        "id": "inv_token", "status": "Settled", "amount": "250", "currency": "sat",
        "paymentRail": "cashu", "mintUrl": "https://othermint.example.org",
        "createdTime": 1700000000, "paidTime": 1700000100, "metadata": {},
        "network": "regtest",
    },
    {  # Mint rail with no mintUrl (defensive): label without a host suffix.
        "id": "inv_mint_nohost", "status": "Settled", "amount": "100", "currency": "sat",
        "paymentRail": "mint", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {}, "network": "regtest",
    },
    {  # On-chain: unchanged.
        "id": "inv_oc", "status": "Settled", "amount": "25000", "currency": "sat",
        "paymentRail": "onchain", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {}, "network": "regtest",
    },
]


def test_invoices_table_method_chips(shared_configured: ConfiguredPayserver, page) -> None:
    _login(page, shared_configured)

    page.evaluate(
        "(rows) => renderInvoicesTable('all-invoices', rows)",
        MOCK_INVOICES,
    )
    html = page.inner_html("#all-invoices")

    assert "Lightning (cashu)(mint.example.com)" in html, html
    assert "Cashu(othermint.example.org)" in html, html
    # No mintUrl -> bare label, no dangling parentheses.
    assert "Lightning (cashu)</span>" in html or "Lightning (cashu) " in html, html
    assert "(undefined)" not in html and "(null)" not in html, html
    # Other rails keep their existing labels.
    assert "On-chain" in html, html
