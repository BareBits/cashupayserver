"""Payer-facing redirect links must never target the WordPress admin.

Invoices created from the embedded admin's "Request payment" modal used to
store the admin SPA's own URL (e.g. /cashupay-admin/dashboard) as
checkout.redirectURL. The payment page renders that URL as the customer-facing
"Return to Shop" (expired screen) and "Continue to Store" (settled screen)
buttons — and /cashupay-admin is gated behind manage_options, so a customer
clicking either landed on WordPress's Access-denied error page.

Fixed twice over:
  - payment.php rewrites admin-surface redirect targets to the shop's front
    page at render time, covering invoices already stored with an admin URL;
  - the admin modal now stores home_url('/') in WordPress mode (shopHomeUrl).
"""
from __future__ import annotations

import html
import re
import time

import pytest
import requests

from fixtures.nutshell import MintHandle
from wordpress.test_wp_e2e import _flush_rewrites, _seed_cashupay_via_wp_cli
from wordpress.test_wp_embedded_admin import _wp_login

pytestmark = pytest.mark.wordpress


def _create_invoice(wp, store_id: str, api_key: str, redirect_url: str) -> str:
    r = requests.post(
        f"{wp.url}/cashupay/api/v1/stores/{store_id}/invoices",
        json={
            "amount": "21",
            "currency": "sat",
            "checkout": {"redirectURL": redirect_url, "redirectAutomatically": True},
        },
        headers={"Authorization": f"token {api_key}"},
        timeout=30,
    )
    assert r.status_code in (200, 201), f"{r.status_code} {r.text[:300]}"
    return r.json()["id"]


def _expire(wp, invoice_id: str) -> None:
    with wp.db() as db:
        db.execute(
            "UPDATE invoices SET expiration_time = ?, status = 'Expired' WHERE id = ?",
            (int(time.time()) - 60, invoice_id),
        )


def _return_to_shop_href(wp, invoice_id: str) -> str:
    page = requests.get(f"{wp.url}/cashupay/payment/{invoice_id}", timeout=15)
    page.raise_for_status()
    m = re.search(r'<a href="([^"]+)"[^>]*>\s*Return to Shop', page.text)
    assert m, "no Return to Shop link on the expired payment page"
    return html.unescape(m.group(1))


def test_admin_created_invoice_sends_payers_home_not_to_admin(
    wordpress, mint: MintHandle
) -> None:
    """The historical shape: an invoice whose stored redirectURL is the admin
    SPA. The expired page's Return to Shop must land a logged-out payer on the
    shop's front page (HTTP 200), not the manage_options-gated admin."""
    wp = wordpress
    _flush_rewrites(wp)
    store_id, api_key = _seed_cashupay_via_wp_cli(wp, mint_url=mint.url)

    invoice_id = _create_invoice(
        wp, store_id, api_key, f"{wp.url}/cashupay-admin/dashboard"
    )
    _expire(wp, invoice_id)

    href = _return_to_shop_href(wp, invoice_id)
    assert "/cashupay-admin" not in href, href
    assert href.rstrip("/") == wp.url.rstrip("/"), href

    # A guest (customer on their own device, no wp-admin cookies) can follow it.
    r = requests.get(href, timeout=15)
    assert r.status_code == 200, f"Return to Shop gave {r.status_code} at {r.url}"

    # The settled screen's Continue to Store shares $redirectUrl — no admin
    # URL may appear as any link target on the payer-facing page.
    page = requests.get(f"{wp.url}/cashupay/payment/{invoice_id}", timeout=15)
    assert 'href="' + wp.url + '/cashupay-admin' not in page.text


def test_non_admin_redirect_urls_pass_through_untouched(
    wordpress, mint: MintHandle
) -> None:
    """The rewrite is scoped to admin surfaces: an ordinary merchant-supplied
    redirect URL (e.g. a WooCommerce order-received URL) must render as
    stored."""
    wp = wordpress
    _flush_rewrites(wp)
    store_id, api_key = _seed_cashupay_via_wp_cli(wp, mint_url=mint.url)

    target = f"{wp.url}/checkout/order-received/42/?key=wc_order_test"
    invoice_id = _create_invoice(wp, store_id, api_key, target)
    _expire(wp, invoice_id)

    assert _return_to_shop_href(wp, invoice_id) == target


def test_admin_page_serves_shop_home_url_to_modal(wordpress, mint: MintHandle) -> None:
    """The Request-payment modal's fix at the source: the WP-mode admin SPA
    embeds shopHomeUrl (the public front page) for new invoices' redirectURL."""
    wp = wordpress
    _flush_rewrites(wp)
    _seed_cashupay_via_wp_cli(wp, mint_url=mint.url)

    s = _wp_login(wp)
    body = s.get(f"{wp.url}/cashupay-admin/dashboard/", timeout=30).text
    m = re.search(r"const shopHomeUrl = (.+?);\n", body)
    assert m, "shopHomeUrl const missing from the admin SPA"
    value = m.group(1).strip().strip('"').replace("\\/", "/")
    assert value.rstrip("/") == wp.url.rstrip("/"), m.group(0)
