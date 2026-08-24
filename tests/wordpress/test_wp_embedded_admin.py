"""Embedded BareBits admin inside wp-admin, and WP-mode UI trims.

Clicking "BareBits" in the WordPress sidebar must render the payserver admin
(or the setup wizard while unconfigured) in a same-origin iframe inside
wp-admin instead of redirecting the browser away. In plugin mode the SPA also
hides what WordPress makes redundant: the Products and Customers tabs (the
shop plugin owns that data) and the store selector (plugin installs run a
single store).

Only the `wordpress` fixture is needed (no mint/LND): these tests render
HTML, they do not move money.
"""
from __future__ import annotations

import pytest
import requests

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress


def _render_menu_page(wp: WordPressHandle) -> str:
    snippet = (
        "wp_set_current_user(1);"
        "ob_start(); cashupay_admin_page(); echo ob_get_clean();"
    )
    return wp.wp_cli("eval", snippet).stdout


def _seed_setup_complete(wp: WordPressHandle) -> None:
    """Mark setup complete (+ a minimal store) directly via the plugin's PHP so
    /cashupay-admin/ renders the SPA instead of redirecting to the wizard."""
    snippet = """
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
Database::initialize();
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'WP Embed Store',
    'mint_url' => 'https://mint.example',
    'mint_unit' => 'sat',
    'created_at' => Database::timestamp(),
]);
Config::set('setup_complete', true);
echo 'ok';
"""
    result = wp.wp_cli("eval", snippet)
    assert "ok" in result.stdout, f"seeding failed: {result.stdout!r} / {result.stderr!r}"


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


def test_menu_page_embeds_wizard_while_unconfigured(wordpress: WordPressHandle) -> None:
    """Fresh install: the BareBits menu page iframes the setup wizard — it must
    not emit the old window.location redirect that left wp-admin."""
    body = _render_menu_page(wordpress)
    assert "<iframe" in body, body[:400]
    assert "cashupay-setup" in body, "unconfigured menu page should embed the wizard: " + body[:400]
    assert "window.location" not in body, "menu page must not redirect out of wp-admin"


def test_menu_page_embeds_admin_once_configured(wordpress: WordPressHandle) -> None:
    """Configured install: the same page iframes the admin SPA."""
    _seed_setup_complete(wordpress)
    body = _render_menu_page(wordpress)
    assert "<iframe" in body, body[:400]
    assert "cashupay-admin" in body, "configured menu page should embed the admin SPA: " + body[:400]
    assert "window.location" not in body, "menu page must not redirect out of wp-admin"


def test_admin_spa_hides_wp_redundant_chrome(wordpress: WordPressHandle) -> None:
    """In plugin mode the SPA marks the Products tab, the Customers tab, and
    the store selector with the wp-hidden (display:none !important) class."""
    _seed_setup_complete(wordpress)
    _flush_rewrites(wordpress)
    s = _wp_login(wordpress)

    r = s.get(f"{wordpress.url}/cashupay-admin/", timeout=30)
    assert r.status_code == 200, f"admin page status {r.status_code}"
    body = r.text

    for element_id in ("nav-products", "nav-customers", "header-store-selector"):
        # The wp-hidden class and the id sit on the same tag; assert on the
        # tag's full markup so a class landing on the wrong element fails.
        start = body.find(f'id="{element_id}"')
        assert start != -1, f"{element_id} missing from the admin HTML"
        tag_start = body.rfind("<", 0, start)
        tag = body[tag_start:body.find(">", start) + 1]
        assert "wp-hidden" in tag, f"{element_id} should carry wp-hidden in plugin mode: {tag}"

    # The class actually hides: the stylesheet ships the !important rule.
    assert ".wp-hidden" in body, "wp-hidden CSS rule missing"

    # Elements stay in the DOM (hidden, not removed) so the SPA's JS keeps
    # working without null guards.
    assert 'id="store-select"' in body, "store select should stay in the DOM"
