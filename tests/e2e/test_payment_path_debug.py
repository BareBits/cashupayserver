"""Admin-only payment-path debug labels on the customer payment page.

The payment screen is public, but when the site-wide toggle is ON *and* the
viewer is a logged-in admin, a small "path" label is rendered next to each
"Copy" button describing how the invoice is routed. This verifies the double
gate end-to-end against the rendered HTML:

  - admin session + toggle ON   -> labels visible
  - admin session + toggle OFF  -> labels hidden
  - no session   + toggle ON    -> labels hidden (a payer never sees them)
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver

INVOICE_AMOUNT_SAT = "1200"

# The CSS class the server wraps each debug label in (payment.php).
DEBUG_MARKER = 'class="debug-path"'


def _set_path_debug(configured: ConfiguredPayserver, enabled: bool) -> None:
    resp = configured.admin._post_action(
        "save_developer_settings",
        payment_path_debug="1" if enabled else "0",
    )
    assert resp.get("success") is True, resp


def _payment_html(session: requests.Session, base_url: str, invoice_id: str) -> str:
    r = session.get(f"{base_url}/payment.php", params={"id": invoice_id}, timeout=15)
    r.raise_for_status()
    return r.text


def test_payment_path_debug_double_gate(configured: ConfiguredPayserver) -> None:
    base_url = configured.handle.url

    # A sat invoice on the mint-only config routes via the 'mint' rail: it has a
    # Lightning method block (mint quote) and a Cashu ecash block.
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=INVOICE_AMOUNT_SAT, currency="sat"
    )
    invoice_id = invoice["id"]

    # Default: the toggle is OFF site-wide. Even an admin sees no labels.
    admin_session = configured.admin.s
    html = _payment_html(admin_session, base_url, invoice_id)
    assert DEBUG_MARKER not in html, "labels must be hidden while the toggle is OFF"

    # Turn the toggle ON, and confirm the get endpoint reflects it.
    _set_path_debug(configured, True)
    got = configured.admin._post_action("get_developer_settings")
    assert got.get("paymentPathDebug") is True, got

    # Admin + toggle ON -> labels visible, with the rail described.
    html = _payment_html(admin_session, base_url, invoice_id)
    assert DEBUG_MARKER in html, "admin should see labels when the toggle is ON"
    assert "Cashu mint quote (Lightning)" in html, html[:2000]
    assert "Cashu ecash (NUT-18)" in html, html[:2000]

    # No session (a regular payer) -> labels hidden even though the toggle is ON.
    anon = requests.Session()
    html_anon = _payment_html(anon, base_url, invoice_id)
    assert DEBUG_MARKER not in html_anon, "a payer must never see the debug labels"

    # Toggle back OFF -> admin no longer sees them.
    _set_path_debug(configured, False)
    html = _payment_html(admin_session, base_url, invoice_id)
    assert DEBUG_MARKER not in html, "labels must disappear when the toggle is turned OFF"
