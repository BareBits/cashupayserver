"""The payment-page email/newsletter form is disabled in WordPress plugin mode.

When BareBits runs as a WordPress plugin, WooCommerce owns customer emails and
order confirmations, so the payment-complete screen must not offer the
email-capture / "Subscribe to our newsletter" form. This asserts, against a
real WordPress install:

  - the payment page HTML carries neither the receipt form nor the newsletter
    checkbox, and shows the "screenshot this page" fallback instead;
  - a crafted send_receipt POST is rejected and records nothing on the invoice;
  - the admin SPA hides the site-wide payer-receipt / newsletter-default
    toggles and the per-store newsletter-default selector.

Only the `wordpress` fixture is needed (no mint/LND): the invoice is seeded
directly as Settled via wp-cli, no money moves.
"""
from __future__ import annotations

import pytest
import requests

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress

INVOICE_ID = "inv_wp_newsletter_test"


def _seed_settled_invoice(wp: WordPressHandle) -> None:
    """Mark setup complete and insert a store + Settled invoice directly via
    the plugin's PHP, so the payment page renders its success state without a
    mint or Lightning backend."""
    snippet = """
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
Database::initialize();
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'WP Newsletter Store',
    'mint_url' => 'https://mint.example',
    'mint_unit' => 'sat',
    'created_at' => Database::timestamp(),
]);
Database::insert('invoices', [
    'id' => '%s',
    'store_id' => $storeId,
    'status' => 'Settled',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
    'paid_at' => Database::timestamp(),
]);
Config::set('setup_complete', true);
echo 'ok';
""" % INVOICE_ID
    result = wp.wp_cli("eval", snippet)
    assert "ok" in result.stdout, f"seeding failed: {result.stdout!r} / {result.stderr!r}"


def _invoice_email_state(wp: WordPressHandle) -> str:
    snippet = """
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
$row = Database::fetchOne(
    "SELECT customer_email, newsletter_opt_in FROM invoices WHERE id = ?", ['%s']);
echo json_encode([$row['customer_email'], $row['newsletter_opt_in']]);
""" % INVOICE_ID
    result = wp.wp_cli("eval", snippet)
    return result.stdout.strip()


def _flush_rewrites(wp: WordPressHandle) -> None:
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def _wp_login(wp: WordPressHandle) -> requests.Session:
    s = requests.Session()
    s.cookies.set("wordpress_test_cookie", "WP+Cookie+check", domain="127.0.0.1")
    s.post(
        f"{wp.url}/wp-login.php",
        data={
            "log": WP_ADMIN_USER,
            "pwd": WP_ADMIN_PASSWORD,
            "wp-submit": "Log In",
            "redirect_to": f"{wp.url}/wp-admin/",
            "testcookie": "1",
        },
        timeout=30,
        allow_redirects=False,
    )
    return s


def test_payment_page_has_no_email_form_in_plugin_mode(wordpress: WordPressHandle) -> None:
    _seed_settled_invoice(wordpress)
    _flush_rewrites(wordpress)

    r = requests.get(f"{wordpress.url}/cashupay/payment/{INVOICE_ID}", timeout=30)
    assert r.status_code == 200, f"payment page status {r.status_code}"
    body = r.text

    assert 'id="receipt-form"' not in body, "receipt form must not render in plugin mode"
    assert "Subscribe to our newsletter" not in body, "newsletter checkbox must not render in plugin mode"
    assert 'id="receipt-email"' not in body, "email input must not render in plugin mode"
    assert "Screenshot this page" in body, "screenshot fallback should replace the form"

    # A crafted POST (nothing in the page submits one) must be rejected and
    # record nothing on the invoice.
    r = requests.post(
        f"{wordpress.url}/cashupay/payment/{INVOICE_ID}",
        data={"action": "send_receipt", "email": "payer@example.com", "newsletter": "1"},
        timeout=30,
    )
    assert r.status_code == 404, f"send_receipt should be rejected, got {r.status_code}: {r.text[:200]}"
    assert _invoice_email_state(wordpress) == "[null,null]", "crafted POST must not record email/opt-in"


def test_admin_hides_newsletter_and_payer_receipt_settings(wordpress: WordPressHandle) -> None:
    _seed_settled_invoice(wordpress)
    _flush_rewrites(wordpress)
    s = _wp_login(wordpress)

    r = s.get(f"{wordpress.url}/cashupay-admin/", timeout=30)
    assert r.status_code == 200, f"admin page status {r.status_code}"
    body = r.text

    assert 'id="notifications-payer-receipt"' not in body, "payer-receipt toggle must be hidden in plugin mode"
    assert 'id="notifications-newsletter-default"' not in body, "newsletter-default toggle must be hidden in plugin mode"
    assert 'id="store-newsletter-default"' not in body, "per-store newsletter selector must be hidden in plugin mode"
    # Sanity: the rest of the notifications card still renders.
    assert 'id="notifications-enabled"' in body, "notifications master switch should still render"
