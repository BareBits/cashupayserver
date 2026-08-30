"""Customer payment.php page: BOLT11 QR rendering, live status polling."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_payment_page_renders_qr_and_polls_to_settled(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    configured = shared_configured
    page.set_default_timeout(20000)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    page.goto(f"{configured.handle.url}/payment?id={invoice_id}")

    # The QR canvas renders into #qr-lightning (and #qr-onchain when both
    # methods are configured); wait for the Lightning QR specifically.
    page.wait_for_function(
        "() => { const el = document.getElementById('qr-lightning'); return el && el.childElementCount > 0; }"
    )

    # The pending state ("Waiting for payment") should be visible.
    page.wait_for_selector("#payment-pending", state="visible")

    # Pay the invoice — the JS poller should pick it up and flip the page.
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    # Settled state shows #payment-success with class "show".
    page.wait_for_function(
        "() => { const el = document.getElementById('payment-success'); return el && el.classList.contains('show'); }",
        timeout=30000,
    )


def test_payment_page_qr_size_and_button_row(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """The QR fills the checkout column (not the old fixed 220px), the
    open-in-wallet / copy buttons share one row, and the invoice-details box
    sits below the payment method blocks."""
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_function(
        "() => document.querySelector('#qr-lightning canvas') !== null"
    )

    # Desktop viewport: the QR should render well above the old 220px.
    canvas = page.locator("#qr-lightning canvas")
    box = canvas.bounding_box()
    assert box["width"] >= 300, f"QR too small on desktop: {box['width']}px"
    assert abs(box["width"] - box["height"]) < 2, "QR should stay square"
    # Internal canvas resolution is oversized so CSS upscaling stays crisp.
    assert canvas.evaluate("c => c.width") >= 380

    # Open in Wallet + Copy Invoice share a single row.
    block = page.locator('[data-method-block="lightning"]')
    open_box = block.locator("a.btn", has_text="Open in Wallet").bounding_box()
    copy_box = block.locator("button.btn", has_text="Copy Invoice").bounding_box()
    assert abs(open_box["y"] - copy_box["y"]) < 2, "buttons should share a row"
    assert open_box["x"] + open_box["width"] <= copy_box["x"] + 1

    # Invoice details were moved below the method blocks to lift the QR.
    assert page.evaluate(
        """() => {
            const details = document.querySelector('#payment-pending .invoice-details');
            const block = document.querySelector('[data-method-block="lightning"]');
            return !!(details && block
                && (block.compareDocumentPosition(details) & Node.DOCUMENT_POSITION_FOLLOWING));
        }"""
    )

    # Logo + store name merged into one compact header line.
    header = page.locator(".merchant-header")
    assert header.count() == 1
    assert header.locator(".logo").count() == 1
    assert header.locator(".merchant-name").count() == 1


def test_payment_page_displays_invoice_amount(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="4242", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")
    page.wait_for_function("() => document.body.innerText.includes('4242')")


# Each "Pay with" brand links to the provider's site and shows a name caption.
EXPECTED_BRANDS = {
    "Cash App": "https://cash.app",
    "Strike": "https://strike.me",
    "Coinbase": "https://www.coinbase.com",
    "Kraken": "https://www.kraken.com",
    "Venmo": "https://venmo.com",
    "PayPal": "https://www.paypal.com",
}


def test_payment_page_provider_logos_link_and_label(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")

    items = page.locator("#payment-methods .pm-item")
    items.first.wait_for(state="attached")
    assert items.count() == len(EXPECTED_BRANDS)

    for name, url in EXPECTED_BRANDS.items():
        link = page.locator(f"#payment-methods a.pm-item:has(.pm-name:text-is('{name}'))")
        assert link.count() == 1, f"expected one link for {name}"
        assert link.get_attribute("href") == url
        # Opens in a new tab without leaking the referrer/opener.
        assert link.get_attribute("target") == "_blank"
        assert "noopener" in (link.get_attribute("rel") or "")
        # The logo carries the provider name for accessibility.
        assert link.locator("img.pm-logo").get_attribute("alt") == name


def test_payment_page_footer_powered_by_links_to_barebits(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    configured = shared_configured
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/payment?id={invoice['id']}")

    footer_link = page.locator(".footer a", has_text="BareBits")
    footer_link.wait_for(state="attached")
    assert footer_link.get_attribute("href") == "https://getbarebits.com"
    assert footer_link.get_attribute("target") == "_blank"


def test_cashu_option_only_shown_when_offline_acceptance_enabled(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    """Cashu is offered on the payment screen only when the store has opted
    into offline Cashu acceptance (offline_cashu_enabled). The DB writes are
    scoped to this test's own store on the shared server (and restored)."""
    configured = shared_configured
    page.set_default_timeout(15000)

    # Offline acceptance is OFF by default → no Cashu tab or block.
    inv_off = configured.greenfield.create_invoice(
        configured.store_id, amount="1000", currency="sat"
    )
    page.goto(f"{configured.handle.url}/payment?id={inv_off['id']}")
    page.wait_for_selector("#payment-pending", state="visible")
    assert page.locator('[data-method-block="cashu"]').count() == 0
    assert page.locator('.method-tab[data-method="cashu"]').count() == 0

    # Enable offline Cashu acceptance for the store → Cashu block appears.
    with configured.handle.db() as conn:
        conn.execute(
            "UPDATE stores SET offline_cashu_enabled = 1 WHERE id = ?",
            (configured.store_id,),
        )
    try:
        inv_on = configured.greenfield.create_invoice(
            configured.store_id, amount="1000", currency="sat"
        )
        page.goto(f"{configured.handle.url}/payment?id={inv_on['id']}")
        page.wait_for_selector("#payment-pending", state="visible")
        assert page.locator('[data-method-block="cashu"]').count() == 1
    finally:
        # Restore the default so the shared session store doesn't leak state.
        with configured.handle.db() as conn:
            conn.execute(
                "UPDATE stores SET offline_cashu_enabled = 0 WHERE id = ?",
                (configured.store_id,),
            )
