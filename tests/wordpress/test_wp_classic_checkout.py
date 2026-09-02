"""The Bitcoin discount on the CLASSIC (shortcode) checkout, in a real browser.

The blocks checkout is WooCommerce 9.x's default and has its own buyer
journey coverage; plenty of live shops still run the classic
[woocommerce_checkout] shortcode, and the discount rides a completely
different path there: WooCommerce core does not recalculate totals when the
payment method changes, so the plugin ships discount-classic.js to trigger
update_checkout — whose update_order_review AJAX writes the selection into
the session, where the woocommerce_cart_calculate_fees hook reads it.

A real Chromium guest: product page -> add to cart -> the shortcode checkout
-> toggling the payment method adds/removes the discount row in the order
review table -> Place Order with BareBits selected -> the payserver invoice
carries the DISCOUNTED amount.

Product: 1500 sats; 2.5% -> 37.5 sats, rounded to 38 -> 1462-sat total.
"""
from __future__ import annotations

import time

import pytest
import requests

from fixtures.wordpress import use_classic_checkout
from wordpress.test_wp_woocommerce_checkout import (  # noqa: F401 — fixture
    _flush_rewrites,
    _order_field,
    _wire,
)

pytestmark = [pytest.mark.wordpress, pytest.mark.ui]


def _wp_eval(wp, code: str) -> str:
    return wp.wp_cli("eval", code).stdout.strip().splitlines()[-1].strip()


def test_classic_checkout_discount_follows_selection_and_prices_the_invoice(
    woocommerce,
    configured,
    page,
) -> None:
    wp, info = woocommerce
    page.set_default_timeout(60_000)
    _flush_rewrites(wp)
    _wire(wp, configured, percent="2.5")
    use_classic_checkout(wp)
    # A second gateway so the selection toggles both ways, and a shop base
    # address so the classic form prefills country/state (its state field is
    # a select2 widget that plain fill() cannot drive).
    wp.wp_cli("option", "update", "woocommerce_cod_settings", '{"enabled":"yes","enable_for_virtual":"yes"}',
              "--format=json", check=False)
    wp.wp_cli("option", "update", "woocommerce_default_country", "US:CA", check=False)

    events: list[str] = []
    page.on("console", lambda m: events.append(f"console[{m.type}] {m.text[:300]}"))
    page.on("pageerror", lambda e: events.append(f"pageerror {str(e)[:300]}"))

    # --- storefront: product -> cart -> the shortcode checkout ---
    product_url = _wp_eval(wp, f"echo get_permalink({info['product_id']});")
    page.goto(product_url)
    page.click("button.single_add_to_cart_button")
    page.wait_for_selector(".woocommerce-message, .wc-block-components-notice-banner")

    checkout_url = _wp_eval(wp, "echo wc_get_checkout_url();")
    page.goto(checkout_url)
    page.wait_for_selector("form.checkout")

    barebits_radio = page.locator("input#payment_method_btcpaygf_default")
    cod_radio = page.locator("input#payment_method_cod")
    barebits_radio.wait_for(state="attached")
    cod_radio.wait_for(state="attached")
    fee_row = page.locator("tr.fee", has_text="Bitcoin discount (2.5%)")

    def _totals_show(amount: str, why: str) -> None:
        """The review-order table re-renders via AJAX; poll its text."""
        deadline = time.monotonic() + 20
        text = ""
        while time.monotonic() < deadline:
            text = " ".join(
                (page.locator(".woocommerce-checkout-review-order-table")
                 .inner_text() or "").split()
            )
            if amount in text:
                return
            time.sleep(0.25)
        raise AssertionError(f"{why}; review table: {text!r}; events: {events[-15:]}")

    # Selecting BareBits adds the discount row and drops the total — no page
    # reload, just the update_checkout our script triggers.
    barebits_radio.check()
    fee_row.wait_for(state="visible")
    _totals_show("0.00001462", "discounted total never rendered")

    # Switching to COD removes it.
    cod_radio.check()
    fee_row.wait_for(state="hidden")
    _totals_show("0.00001500", "full total never came back after deselecting")

    # Back to BareBits, fill billing, place the order.
    barebits_radio.check()
    fee_row.wait_for(state="visible")
    page.fill("#billing_first_name", "Sat")
    page.fill("#billing_last_name", "Oshi")
    page.fill("#billing_address_1", "1 Genesis Block")
    page.fill("#billing_city", "Cypherpunk")
    page.fill("#billing_postcode", "94016")
    page.fill("#billing_phone", "5551234567")
    page.fill("#billing_email", "buyer@example.test")

    page.click("#place_order")
    try:
        page.wait_for_url(f"{configured.handle.url}/**", timeout=90_000)
    except Exception:
        shot = wp.workdir / "classic-discount-failure.png"
        page.screenshot(path=str(shot), full_page=True)
        errors = " ".join(
            (page.locator(".woocommerce-error").inner_text() or "").split()
        ) if page.locator(".woocommerce-error").count() else "(no error rendered)"
        raise AssertionError(
            "Place order never reached the BareBits payment page; "
            f"still on {page.url}; checkout shows: {errors}; "
            f"screenshot: {shot}; events: {events[-30:]}"
        )

    # The invoice the gateway created carries the discounted amount, and the
    # order it belongs to totals the same.
    invoice_id = page.url.split("id=")[-1].split("&")[0]
    inv = requests.get(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/invoices/{invoice_id}",
        headers={"Authorization": f"token {configured.api_token}"},
        timeout=15,
    )
    inv.raise_for_status()
    assert float(inv.json()["amount"]) == pytest.approx(0.00001462), inv.json()

    order_id = int(inv.json()["metadata"]["orderId"])
    total = _order_field(wp, order_id, "get_total()")
    assert float(total) == pytest.approx(0.00001462), total
    assert _order_field(wp, order_id, "get_payment_method()") == "btcpaygf_default"
