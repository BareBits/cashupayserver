"""Admin UI for self-serve product links + invoice-modal button changes.

Through the browser against the full stack:
  - the Invoices view offers both create flows (advanced cart modal + simple),
    mirroring the dashboard labels
  - the simple modal's "Switch to advanced" swaps it for the cart modal, whose
    submit button now reads "Create"
  - each Products row has "Copy link"; with self-serve off it prompts to
    enable it first, then copies a /pay link carrying the product id
  - "Generate link for multiple products" reuses the cart modal in link mode
    (no checkout/custom-amount/invoice fields) and builds a multi-product link
    with quantities

The public consumption of these links is covered by
tests/e2e/test_selfserve_product_links.py.
"""
from __future__ import annotations

import secrets
import time

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#store-select') && document.querySelector('#store-select').options.length > 0"
    )
    # Pin the header selector to this test's own store: dashboardData.selfserve
    # (payUrl, enabled state) is loaded per selected store.
    page.select_option("#store-select", configured.store_id)
    page.wait_for_function("() => dashboardData && dashboardData.selfserve")


def _set_selfserve(configured: ConfiguredPayserver, enabled: bool) -> None:
    with configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            (1 if enabled else 0, configured.store_id),
        )


def _make_product(configured: ConfiguredPayserver, title: str, price: str) -> str:
    pid = "prod_" + secrets.token_hex(12)
    now = int(time.time())
    with configured.handle.db() as db:
        db.execute(
            "INSERT INTO products (id, store_id, title, price, currency, image_type, image_value,"
            " purchase_count, enabled, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, 'sat', 'none', NULL, 0, 1, ?, ?)",
            (pid, configured.store_id, title, price, now, now),
        )
    return pid


def _goto_products_view(page) -> None:
    page.click('[data-view="products"]')
    page.wait_for_selector("#view-products", state="visible")
    page.wait_for_function(
        "() => document.querySelector('#products-admin-list') "
        "&& !document.querySelector('#products-admin-list .spinner')"
    )


def test_invoices_view_offers_both_create_flows(
    shared_configured: ConfiguredPayserver, page
) -> None:
    _login(shared_configured, page)
    page.click('[data-view="invoices"]')
    page.wait_for_selector("#view-invoices", state="visible")

    # Both buttons, mirroring the dashboard labels.
    assert page.locator("#btn-new-invoice-advanced").inner_text().strip() == "Create invoice"
    assert page.locator("#btn-new-invoice").inner_text().strip() == "Create invoice (simple)"

    # Advanced opens the cart modal (whose submit now reads "Create")…
    page.click("#btn-new-invoice-advanced")
    page.wait_for_selector("#modal-cart.visible")
    assert page.locator("#cart-modal-title").inner_text().strip() == "Create invoice"
    assert page.locator("#btn-cart-checkout").inner_text().strip() == "Create"
    page.evaluate("() => closeModal('modal-cart')")

    # …and the simple button still opens the simple modal.
    page.click("#btn-new-invoice")
    page.wait_for_selector("#modal-request.visible")
    page.evaluate("() => closeModal('modal-request')")


def test_simple_modal_switch_to_advanced(
    shared_configured: ConfiguredPayserver, page
) -> None:
    _login(shared_configured, page)
    page.click("#btn-request-simple")
    page.wait_for_selector("#modal-request.visible")
    page.click("#btn-request-switch-advanced")
    page.wait_for_selector("#modal-cart.visible")
    assert not page.locator("#modal-request").evaluate(
        "el => el.classList.contains('visible')"
    )
    page.evaluate("() => closeModal('modal-cart')")


def test_copy_product_link_prompts_to_enable_selfserve(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _set_selfserve(configured, False)
    title = f"LinkBtn {secrets.token_hex(3)}"
    pid = _make_product(configured, title, "500")
    page.context.grant_permissions(["clipboard-read", "clipboard-write"])

    _login(configured, page)
    _goto_products_view(page)
    row = page.locator("#products-admin-list > div", has_text=title)
    # Self-serve is off: clicking Copy copies the link in-gesture AND opens the
    # in-app enable modal (an app modal, NOT a native confirm() the browser can
    # suppress). Accepting it turns self-serve on.
    row.get_by_role("button", name="Copy link").click()
    page.wait_for_selector("#modal-enable-selfserve.visible")
    page.wait_for_function(
        "() => navigator.clipboard.readText().then(t => t.includes('products='))"
    )
    clipboard = page.evaluate("navigator.clipboard.readText()")
    assert f"products={pid}" in clipboard
    assert "/pay" in clipboard
    page.click("#btn-enable-selfserve-confirm")
    page.wait_for_function(
        "() => dashboardData && dashboardData.selfserve && dashboardData.selfserve.effective"
    )
    page.wait_for_selector("#modal-enable-selfserve", state="hidden")
    with configured.handle.db() as db:
        row_db = db.execute(
            "SELECT selfserve_enabled FROM stores WHERE id = ?", (configured.store_id,)
        ).fetchone()
    # Regression: clicking Copy while self-serve was off used to copy a link to
    # a disabled store (404). The fix copies the link AND flips self-serve on,
    # so the shared link resolves. (HTTP resolution of the link, incl. tips, is
    # covered by tests/e2e/test_selfserve_product_links.py via the plain /pay
    # form; the admin fixture runs router mode, whose /router.php/pay path only
    # resolves behind a real front controller, not the test cli-server.)
    assert row_db["selfserve_enabled"] == 1


def test_generate_link_for_multiple_products(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    _set_selfserve(configured, True)
    tag = secrets.token_hex(3)
    pid_a = _make_product(configured, f"MultiA {tag}", "100")
    pid_b = _make_product(configured, f"MultiB {tag}", "200")
    page.context.grant_permissions(["clipboard-read", "clipboard-write"])

    _login(configured, page)
    _goto_products_view(page)
    page.click("#btn-products-generate-link")
    page.wait_for_selector("#modal-cart.visible")

    # Link mode: picker stays, invoice-only pieces are swapped for the link bar.
    assert page.locator("#cart-modal-title").inner_text().strip() == "Generate product link"
    assert page.locator("#btn-cart-checkout").is_hidden()
    assert page.locator("#btn-add-custom-line").is_hidden()
    assert page.locator("#cart-invoice-fields").is_hidden()
    assert page.locator("#cart-link-footer").is_visible()
    assert page.locator("#btn-cart-copy-link").is_disabled()

    # Add MultiA twice (qty 2) and MultiB once; the preview updates live.
    page.fill("#cart-search", f"MultiA {tag}")
    page.click(f'#cart-catalog div[role="button"]:has-text("MultiA {tag}")')
    page.click(f'#cart-catalog div[role="button"]:has-text("MultiA {tag}")')
    page.fill("#cart-search", f"MultiB {tag}")
    page.click(f'#cart-catalog div[role="button"]:has-text("MultiB {tag}")')
    link = page.locator("#cart-link-input").input_value()
    assert f"products={pid_a}:2,{pid_b}" in link
    assert "/pay" in link

    # Copy button lands the same link on the clipboard (self-serve already on,
    # so no confirm dialog fires).
    page.click("#btn-cart-copy-link")
    page.wait_for_function(
        "() => navigator.clipboard.readText().then(t => t.includes('products='))"
    )
    assert page.evaluate("navigator.clipboard.readText()") == link
    page.evaluate("() => closeModal('modal-cart')")


def test_generate_link_warns_when_selfserve_off(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """Link-mode modal surfaces a warning while self-serve is off, and the Copy
    link button copies the link in-gesture and then offers to enable it."""
    configured = shared_configured
    _set_selfserve(configured, False)
    tag = secrets.token_hex(3)
    pid = _make_product(configured, f"WarnProd {tag}", "300")
    page.context.grant_permissions(["clipboard-read", "clipboard-write"])

    _login(configured, page)
    _goto_products_view(page)
    page.click("#btn-products-generate-link")
    page.wait_for_selector("#modal-cart.visible")
    page.click(f'#cart-catalog div[role="button"]:has-text("WarnProd {tag}")')
    # Off-state warning is visible.
    assert page.locator("#cart-link-warning").is_visible()

    # Copy link copies the link AND opens the in-app enable modal (not a native
    # confirm the browser can suppress). It stacks above the still-open cart
    # modal; accepting enables self-serve.
    page.click("#btn-cart-copy-link")
    page.wait_for_selector("#modal-enable-selfserve.visible")
    assert page.locator("#modal-cart").evaluate("el => el.classList.contains('visible')")
    clipboard = page.evaluate("navigator.clipboard.readText()")
    assert f"products={pid}" in clipboard
    page.click("#btn-enable-selfserve-confirm")
    page.wait_for_function(
        "() => dashboardData && dashboardData.selfserve && dashboardData.selfserve.effective"
    )
    page.wait_for_selector("#modal-enable-selfserve", state="hidden")
    # Enabling cleared the off-state warning in the still-open cart modal.
    page.wait_for_selector("#cart-link-warning", state="hidden")
    page.evaluate("() => closeModal('modal-cart')")
