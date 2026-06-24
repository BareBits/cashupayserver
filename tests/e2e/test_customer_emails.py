"""Customer email capture + newsletter opt-in.

Covers the payment-screen newsletter checkbox (default state, site-wide and
per-store), the decoupled email/newsletter capture onto the invoice, and the
admin Customers list + CSV export.
"""
from __future__ import annotations

import re
import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle


def _payment_html(url: str, invoice_id: str) -> str:
    import requests

    r = requests.get(f"{url}/payment.php", params={"id": invoice_id}, timeout=15)
    r.raise_for_status()
    return r.text


def _newsletter_checkbox_checked(html: str) -> bool:
    """Return whether the rendered receipt-newsletter checkbox is pre-checked."""
    m = re.search(r'<input[^>]*id="receipt-newsletter"[^>]*>', html)
    assert m, "newsletter checkbox should always be rendered on the payment page"
    return "checked" in m.group(0)


def _read_invoice_row(handle, invoice_id: str) -> dict:
    db_path = handle.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert row is not None, f"invoice {invoice_id} not found in db"
        return dict(row)


def _new_invoice(configured: ConfiguredPayserver) -> str:
    inv = configured.greenfield.create_invoice(configured.store_id, amount="1000", currency="sat")
    return inv["id"]


def test_newsletter_checkbox_default_rendering(configured: ConfiguredPayserver) -> None:
    """The checkbox defaults to checked site-wide; a per-store override of
    'unchecked' flips it. The form renders regardless of payer-receipt setup."""
    # Default: site-wide default is "checked" and nothing is configured.
    inv_a = _new_invoice(configured)
    assert _newsletter_checkbox_checked(_payment_html(configured.handle.url, inv_a)) is True

    # Per-store override → unchecked. save_store_notifications routes the
    # newsletter_default_checked write straight to the stores table.
    res = configured.admin._post_action(
        "save_store_notifications",
        store_id=configured.store_id,
        enabled="0",
        email="",
        newsletter_default_checked="0",
    )
    assert res.get("success"), res

    inv_b = _new_invoice(configured)
    assert _newsletter_checkbox_checked(_payment_html(configured.handle.url, inv_b)) is False

    # Override = checked again.
    configured.admin._post_action(
        "save_store_notifications",
        store_id=configured.store_id,
        enabled="0",
        email="",
        newsletter_default_checked="1",
    )
    inv_c = _new_invoice(configured)
    assert _newsletter_checkbox_checked(_payment_html(configured.handle.url, inv_c)) is True


@pytest.mark.slow
def test_customer_capture_and_listing(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    """Pay an invoice, submit the email/newsletter form, and verify the email is
    stored on the invoice and surfaced in the admin Customers list + CSV — with
    payer receipts left OFF, proving capture is decoupled from receipt sending."""
    import requests

    gc = configured.greenfield
    url = configured.handle.url

    invoice = gc.create_invoice(configured.store_id, amount="1000", currency="sat")
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), bolt11

    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), f"payment failed: {pay}"

    # Wait for settlement.
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if gc.get_invoice(configured.store_id, invoice_id).get("status") == "Settled":
            break
        time.sleep(0.5)
    else:
        raise AssertionError("invoice did not settle in time")

    # Submit the public capture form (same request the browser makes). Payer
    # receipts are disabled, so receiptQueued is False but the email is stored.
    email = "Buyer@Example.com"
    r = requests.post(
        f"{url}/payment.php",
        params={"id": invoice_id},
        data={"action": "send_receipt", "email": email, "newsletter": "1"},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success") is True, body
    assert body.get("receiptQueued") is False, "receipts are off; capture must still succeed"

    row = _read_invoice_row(configured.handle, invoice_id)
    assert row["customer_email"] == email
    assert int(row["newsletter_opt_in"]) == 1

    # Admin Customers API: the customer shows up, subscribed.
    cust = configured.admin.s.get(f"{url}/admin?api=customers", timeout=15).json()
    emails = [c["email"] for c in cust["customers"]]
    assert email in emails, cust
    me = next(c for c in cust["customers"] if c["email"] == email)
    assert me["newsletterOptIn"] is True
    assert me["invoiceId"] == invoice_id
    assert cust["total"] >= 1

    # Subscription filter: present under "subscribed", absent under "unsubscribed".
    subbed = configured.admin.s.get(f"{url}/admin?api=customers&subscription=subscribed", timeout=15).json()
    assert email in [c["email"] for c in subbed["customers"]]
    unsub = configured.admin.s.get(f"{url}/admin?api=customers&subscription=unsubscribed", timeout=15).json()
    assert email not in [c["email"] for c in unsub["customers"]]

    # CSV export contains the captured email.
    csv = configured.admin.s.get(f"{url}/admin?api=export_customers_csv", timeout=15)
    assert "text/csv" in csv.headers.get("Content-Type", "")
    assert email in csv.text
    assert "Email,Subscribed,Store" in csv.text


@pytest.mark.slow
@pytest.mark.ui
def test_customers_view_renders_in_admin_ui(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    browser,
) -> None:
    """The admin Customers view loads and shows a captured customer row."""
    import requests

    gc = configured.greenfield
    url = configured.handle.url

    invoice = gc.create_invoice(configured.store_id, amount="1000", currency="sat")
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if gc.get_invoice(configured.store_id, invoice_id).get("status") == "Settled":
            break
        time.sleep(0.5)
    else:
        raise AssertionError("invoice did not settle in time")

    email = "uibuyer@example.com"
    requests.post(
        f"{url}/payment.php",
        params={"id": invoice_id},
        data={"action": "send_receipt", "email": email, "newsletter": "1"},
        timeout=15,
    ).raise_for_status()

    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{url}/admin",
        form={"action": "login", "username": "admin", "password": configured.admin_password},
    )
    page = ctx.new_page()
    page.goto(f"{url}/admin/customers", wait_until="networkidle")
    # The customers nav item is admin-only and the view loads its list async.
    page.wait_for_selector("#view-customers.active", timeout=10000)
    page.wait_for_function(
        "(em) => document.getElementById('all-customers')"
        "  && document.getElementById('all-customers').textContent.includes(em)",
        arg=email,
        timeout=10000,
    )
    ctx.close()
