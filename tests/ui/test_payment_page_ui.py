"""Customer payment.php page: BOLT11 QR rendering, live status polling."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui


def test_payment_page_renders_qr_and_polls_to_settled(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
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


def test_payment_page_displays_invoice_amount(
    configured: ConfiguredPayserver,
    page,
) -> None:
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
    configured: ConfiguredPayserver,
    page,
) -> None:
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
    configured: ConfiguredPayserver,
    page,
) -> None:
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
    configured: ConfiguredPayserver,
    page,
) -> None:
    """Cashu is offered on the payment screen only when the store has opted
    into offline Cashu acceptance (offline_cashu_enabled)."""
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
