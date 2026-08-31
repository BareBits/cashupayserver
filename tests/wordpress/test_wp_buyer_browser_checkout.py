"""The BUYER's journey, end to end, in a real browser.

The Store API checkout tests prove the machinery (gateway → invoice →
payment → webhook → paid order) but drive it as raw HTTP; the Playwright
journeys stop at merchant onboarding. What neither can see is what a
customer's BROWSER does between those steps — the WooCommerce blocks
checkout failing to render the gateway, the place-order redirect landing
somewhere unexpected, the payment page's poll never flipping to the success
modal, or the "Continue to Store" exit not returning to the shop.

So: a real Chromium guest walks the storefront —

    product page -> add to cart -> the blocks checkout (WooCommerce 9.x's
    default) -> fill billing -> pick the BareBits-branded gateway -> Place
    Order -> the browser lands on the BareBits payment page -> the invoice
    is paid over regtest Lightning -> the page's own poll flips to the
    success modal (no reload) -> "Continue to Store" returns to
    WooCommerce's order-received page -> the webhook drain marks the order
    paid.

URL mode (separate payserver) with the multi-worker fixture, like the Store
API settle test: the webhook POST re-enters the payserver API.
"""
from __future__ import annotations

import time

import pytest
import requests

from fixtures.lnd import LndHandle
from wordpress.test_wp_woocommerce_checkout import (  # noqa: F401 — fixture
    _await_settled,
    _flush_rewrites,
    _order_field,
    _wire,
    configured_multiworker,
)

pytestmark = [pytest.mark.wordpress, pytest.mark.ui]


def _wp_eval(wp, code: str) -> str:
    return wp.wp_cli("eval", code).stdout.strip().splitlines()[-1].strip()


def _fill_if_empty(page, selector: str, value: str) -> None:
    """Fill a checkout field only when the block hasn't prefilled it (the
    blocks checkout hydrates defaults asynchronously; overwriting is flaky).
    Country/state render as either a <select> or a combobox input depending
    on the WooCommerce version and the chosen country — handle both."""
    field = page.locator(selector)
    if not field.count() or field.input_value():
        return
    if field.evaluate("el => el.tagName.toLowerCase()") == "select":
        field.select_option(value)
    else:
        field.fill(value)


def _checkout_blockers(page) -> str:
    """Collect whatever the blocks checkout is showing the shopper — error
    banners and per-field validation messages — for assertion output."""
    texts = []
    for sel in (
        ".wc-block-components-notice-banner",
        ".wc-block-components-validation-error",
    ):
        for el in page.locator(sel).all():
            t = " ".join((el.inner_text() or "").split())
            if t:
                texts.append(t)
    return "; ".join(texts) or "(no notice rendered)"


def test_buyer_checks_out_and_pays_in_browser(
    woocommerce,
    configured_multiworker,
    lnd_payer: LndHandle,
    page,
) -> None:
    configured = configured_multiworker
    wp, info = woocommerce
    page.set_default_timeout(60_000)
    _flush_rewrites(wp)
    _wire(wp, configured)

    # Diagnostics for the assertion messages: a headless checkout that goes
    # nowhere is undebuggable without the browser's own view of events.
    events: list[str] = []
    page.on("console", lambda m: events.append(f"console[{m.type}] {m.text[:300]}"))
    page.on("pageerror", lambda e: events.append(f"pageerror {str(e)[:300]}"))
    page.on(
        "requestfailed",
        lambda r: events.append(f"requestfailed {r.url[:200]} {r.failure}"),
    )
    page.on(
        "response",
        lambda r: events.append(f"{r.status} {r.request.method} {r.url[:200]}")
        if ("/wc/store/" in r.url or "wc-ajax" in r.url or r.status >= 400)
        else None,
    )

    # --- storefront: product page -> cart ---
    product_url = _wp_eval(wp, f"echo get_permalink({info['product_id']});")
    assert product_url.startswith("http"), product_url
    page.goto(product_url)
    page.click("button.single_add_to_cart_button")
    # The classic template confirms with a woocommerce-message notice.
    page.wait_for_selector(".woocommerce-message, .wc-block-components-notice-banner")

    # --- the blocks checkout (WooCommerce 9.x default) ---
    checkout_url = _wp_eval(wp, "echo wc_get_checkout_url();")
    page.goto(checkout_url)
    page.wait_for_selector(".wc-block-checkout")

    page.fill("#email", "buyer@example.test")
    _fill_if_empty(page, "#billing-first_name", "Sat")
    _fill_if_empty(page, "#billing-last_name", "Oshi")
    _fill_if_empty(page, "#billing-country", "US")
    _fill_if_empty(page, "#billing-address_1", "1 Genesis Block")
    _fill_if_empty(page, "#billing-city", "Cypherpunk")
    _fill_if_empty(page, "#billing-state", "CA")
    _fill_if_empty(page, "#billing-postcode", "94016")

    # The BareBits-branded gateway must be offered, and selectable.
    gateway_radio = page.locator(
        "input[name='radio-control-wc-payment-method-options'][value='btcpaygf_default']"
    )
    gateway_radio.wait_for(state="attached")
    label = page.locator("label[for='radio-control-wc-payment-method-options-btcpaygf_default']")
    assert "BareBits" in (label.inner_text() or ""), (
        "the gateway at checkout must carry the BareBits branding"
    )
    gateway_radio.check()

    # --- place the order; the gateway must hand the browser to BareBits ---
    def _checkout_left_idle() -> bool:
        return page.evaluate(
            """() => {
                const sel = window.wp && window.wp.data && window.wp.data.select;
                if (!sel) return false;
                const c = sel('wc/store/checkout');
                return !!(c && c.getCheckoutStatus && c.getCheckoutStatus() !== 'idle');
            }"""
        )

    clicked = False
    for attempt, do_click in (
        ("css", lambda: page.click(".wc-block-components-checkout-place-order-button")),
        ("role", lambda: page.get_by_role("button", name="Place Order").click()),
        ("enter", lambda: page.locator(
            ".wc-block-components-checkout-place-order-button").press("Enter")),
    ):
        do_click()
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            if _checkout_left_idle():
                clicked = True
                break
            time.sleep(0.25)
        events.append(f"place-order attempt {attempt}: {'took' if clicked else 'ignored'}")
        if clicked:
            break
    try:
        page.wait_for_url(f"{configured.handle.url}/**", timeout=90_000)
    except Exception:
        shot = wp.workdir / "buyer-checkout-failure.png"
        page.screenshot(path=str(shot), full_page=True)
        state = page.evaluate(
            """() => {
                const sel = window.wp && window.wp.data && window.wp.data.select;
                if (!sel) return 'wp.data unavailable';
                const c = sel('wc/store/checkout');
                const p = sel('wc/store/payment');
                const btn = document.querySelector(
                    '.wc-block-components-checkout-place-order-button');
                return JSON.stringify({
                    buttonDisabled: btn ? btn.disabled : null,
                    checkoutStatus: c && c.getCheckoutStatus && c.getCheckoutStatus(),
                    isProcessing: c && c.isProcessing && c.isProcessing(),
                    isCalculating: c && c.isCalculating && c.isCalculating(),
                    hasError: c && c.hasError && c.hasError(),
                    orderId: c && c.getOrderId && c.getOrderId(),
                    paymentStatus: p && p.getCurrentStatus && p.getCurrentStatus(),
                    activeMethod: p && p.getActivePaymentMethod && p.getActivePaymentMethod(),
                });
            }"""
        )
        tail = "\n  ".join(events[-30:])
        raise AssertionError(
            "Place Order never reached the BareBits payment page; "
            f"still on {page.url}; checkout shows: {_checkout_blockers(page)}; "
            f"stores: {state}; screenshot: {shot}\nbrowser events:\n  {tail}"
        )
    page.wait_for_selector("#payment-pending:not(.hidden)")

    # The invoice id rides the payment page URL; map it back to the order.
    invoice_id = page.url.split("id=")[-1].split("&")[0]
    assert invoice_id, page.url
    api = f"{configured.handle.url}/api/v1"
    auth = {"Authorization": f"token {configured.api_token}"}
    inv = requests.get(
        f"{api}/stores/{configured.store_id}/invoices/{invoice_id}",
        headers=auth,
        timeout=15,
    )
    inv.raise_for_status()
    body = inv.json()
    order_id = int(body["metadata"]["orderId"])
    assert _order_field(wp, order_id, "get_meta('BTCPay_id')") == invoice_id

    # --- pay over regtest Lightning while the page is open ---
    bolt11 = body["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    assert bolt11.lower().startswith("lnbcrt"), bolt11
    pay = lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    assert not pay.get("payment_error"), pay
    _await_settled(configured, invoice_id)

    # The page's own poll must flip to the success modal without a reload.
    page.wait_for_selector("#payment-success.show")
    page.wait_for_selector("#success-badge-settled:not(.hidden)")

    # --- back to the shop: the modal's exit lands on order-received ---
    with page.expect_navigation():
        page.click("#redirect-btn")
    assert "order-received" in page.url, page.url
    page.wait_for_selector(
        ".woocommerce-thankyou-order-received, .wp-block-woocommerce-order-confirmation-status"
    )

    # --- the webhook drain marks the order paid ---
    deadline = time.monotonic() + 30
    status = None
    while time.monotonic() < deadline:
        r = configured.handle.trigger_cron()
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            break
        time.sleep(1)
    assert status in ("processing", "completed"), (
        f"order never reached a paid state (last status {status!r})"
    )
