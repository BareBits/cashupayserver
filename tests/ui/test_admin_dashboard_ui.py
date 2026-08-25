"""Admin dashboard UI: login, balance display, store selector."""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_password_login_loads_dashboard(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")

    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")

    # The SPA flips visibility classes; #app should become displayed and
    # #store-select populated.
    page.wait_for_selector("#app", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#store-select') && document.querySelector('#store-select').options.length > 0"
    )


def test_dashboard_shows_balance_after_settle(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    # Settle 2500 sats first so there's a visible balance.
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="2500", currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        if configured.greenfield.get_invoice(configured.store_id, invoice["id"])["status"] == "Settled":
            break
        time.sleep(0.3)

    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # The balance card eventually renders "2500" once the SPA finishes the
    # dashboard fetch.
    page.wait_for_function(
        "() => document.body.innerText.includes('2500')",
        timeout=15000,
    )


def test_dashboard_balance_and_invoice_button_labels(
    configured: ConfiguredPayserver, page,
) -> None:
    """The balance card reads "Balance stored in Cashu Mint" and the request
    buttons are named "Create invoice" / "Create invoice (simple)". The balance
    label is asserted after the dashboard fetch because loadDashboard() rewrites
    it from JS (mint-online path) and would clobber a rename done only in HTML."""
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # Wait until the dashboard fetch has run and re-set the label from JS.
    page.wait_for_function(
        "() => document.querySelector('.balance-label').textContent.trim()"
        " === 'Balance stored in Cashu Mint'"
    )

    assert page.locator("#btn-request").inner_text().strip() == "Create invoice"
    assert (
        page.locator("#btn-request-simple").inner_text().strip()
        == "Create invoice (simple)"
    )

    # Modal titles follow the buttons that open them.
    page.click("#btn-request-simple")
    page.wait_for_selector("#modal-request.visible")
    assert (
        page.locator("#modal-request .modal-title").inner_text().strip()
        == "Create invoice (simple)"
    )
    page.evaluate("() => closeModal('modal-request')")

    page.click("#btn-request")
    page.wait_for_selector("#modal-cart.visible")
    assert (
        page.locator("#modal-cart .modal-title").inner_text().strip()
        == "Create invoice"
    )
