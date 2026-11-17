"""escapeHtml() must be quote-safe: the invoices table puts escaped values
inside attributes (title=, href=), and the old textContent->innerHTML escaper
left quotes alone, so a value with a `"` broke out of the attribute. Provider-
fed values (block-explorer txids) reach that sink; txids are hex-validated
now too, but the renderer itself has to be safe for any string.
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


EVIL_TXID = 'abc" onmouseover="window.__xss_fired = true" data-x="'
EVIL_DESC = "<img src=x onerror=\"window.__xss_fired = true\">'quoted'"


def test_quoted_values_cannot_break_out_of_attributes(shared_configured: ConfiguredPayserver, page) -> None:
    page.add_init_script("window.__xss_fired = false;")
    _login(page, shared_configured)

    rows = [{
        "id": "inv_evil", "status": "Settled", "amount": "25000", "currency": "sat",
        "paymentRail": "onchain", "createdTime": 1700000000, "paidTime": 1700000100,
        "metadata": {"itemDesc": EVIL_DESC},
        "txid": EVIL_TXID,
        "destination": "bc1qexampleonchainaddr0000000000000000",
        "network": "regtest",
    }]
    page.evaluate("(rows) => renderInvoicesTable('all-invoices', rows)", rows)

    # The escaper's output round-trips: the browser decodes &quot; back into
    # the literal value inside the attribute, and nothing became an extra
    # attribute or element.
    assert page.evaluate("() => window.escapeHtml('a\"b\\'c<d>&')") == "a&quot;b&#39;c&lt;d&gt;&amp;"
    anchors = page.locator("#all-invoices a[title]")
    assert anchors.count() >= 1
    titles = [anchors.nth(i).get_attribute("title") for i in range(anchors.count())]
    assert any(t == EVIL_TXID for t in titles), titles
    assert page.locator("#all-invoices [onmouseover]").count() == 0, "no injected attribute"
    assert page.locator("#all-invoices [data-x]").count() == 0, "no injected attribute"
    assert page.locator("#all-invoices img").count() == 0, "item description did not parse as HTML"
    # The invoices view is display:none until navigated to, so fire the
    # event the payload hooked rather than hovering a hidden element.
    page.evaluate(
        "() => document.querySelectorAll('#all-invoices a').forEach(a => a.dispatchEvent(new MouseEvent('mouseover', {bubbles: true})))"
    )
    assert page.evaluate("() => window.__xss_fired") is False
