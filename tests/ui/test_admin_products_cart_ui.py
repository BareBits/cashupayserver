"""Product catalog + cart request flow (admin UI).

End-to-end through the browser against the full stack:
  - an admin creates a product in the Products view
  - the product shows up in the cart-based Request modal
  - adding it to the cart + Checkout creates an invoice and lands on the
    payer checkout page, which renders the itemized line (title + sats)
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    # Wait for the dashboard to know about the store (cart needs currentStoreId).
    page.wait_for_function(
        "() => document.querySelector('#store-select') && document.querySelector('#store-select').options.length > 0"
    )


def test_create_product_and_cart_checkout(configured: ConfiguredPayserver, page) -> None:
    _login(configured, page)

    # --- Create a product via the Products view ---
    page.click('[data-view="products"]')
    page.wait_for_selector("#view-products", state="visible")
    page.click("#btn-new-product")
    page.wait_for_selector("#modal-product-edit.visible")
    page.fill("#product-edit-title-input", "Test Widget")
    page.fill("#product-edit-price", "1234")
    page.fill("#product-edit-emoji", "🧪")
    page.click("#btn-save-product")
    # The product appears in the admin list.
    page.wait_for_function(
        "() => document.querySelector('#products-admin-list') "
        "&& document.querySelector('#products-admin-list').textContent.includes('Test Widget')"
    )

    # --- Open the cart Request modal and add the product ---
    page.click('[data-view="dashboard"]')
    page.wait_for_selector("#view-dashboard", state="visible")
    page.click("#btn-request")
    page.wait_for_selector("#modal-cart.visible")
    page.wait_for_function(
        "() => document.querySelector('#cart-catalog') "
        "&& document.querySelector('#cart-catalog').textContent.includes('Test Widget')"
    )

    # Search filters the catalog as you type.
    page.fill("#cart-search", "Widget")
    page.wait_for_function(
        "() => document.querySelector('#cart-catalog').textContent.includes('Test Widget')"
    )

    # Click the product row to add it to the cart, then bump quantity to 2.
    page.click("#cart-catalog >> text=Test Widget")
    page.wait_for_function(
        "() => document.querySelector('#cart-items').textContent.includes('Test Widget')"
    )
    # The second stepper button in the cart line is the "+".
    page.click("#cart-items button:has-text('＋')")
    page.wait_for_function(
        "() => /2/.test(document.querySelector('#cart-items').textContent)"
    )

    # --- Checkout: creates the invoice and redirects to the payer page ---
    page.click("#btn-cart-checkout")
    page.wait_for_url("**/payment.php*", timeout=20000)
    # The checkout page renders the itemized line.
    page.wait_for_function(
        "() => document.body.textContent.includes('Test Widget') "
        "&& document.body.textContent.includes('sats')"
    )
