"""SMTP settings are greyed out in WordPress plugin mode.

When BareBits runs as a WordPress plugin, email is delegated to wp_mail()
(WordPress owns delivery), so the admin SMTP settings — both the site-wide
section and the per-store override — are disabled with a notice pointing the
operator at WordPress. This asserts the server-rendered notice + the disabled
(pointer-events:none) containers appear on the admin page.

Only the `wordpress` fixture is needed (no mint/LND): the test renders the
admin HTML, it does not move money.
"""
from __future__ import annotations

import pytest
import requests

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress

NOTICE = "configure your e-mail settings in WordPress directly"


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
    'name' => 'WP SMTP Store',
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


def test_admin_smtp_section_greyed_in_plugin_mode(wordpress: WordPressHandle) -> None:
    _seed_setup_complete(wordpress)
    _flush_rewrites(wordpress)
    s = _wp_login(wordpress)

    r = s.get(f"{wordpress.url}/cashupay-admin/", timeout=30)
    assert r.status_code == 200, f"admin page status {r.status_code}"
    body = r.text

    # The plugin-mode notice appears on both SMTP sections (site-wide + per-store).
    assert body.count(NOTICE) >= 2, (
        f"expected the plugin-mode SMTP notice on both sections; found {body.count(NOTICE)}"
    )
    # The SMTP inputs sit inside a visually disabled container.
    assert "pointer-events: none" in body, "SMTP section should be disabled (pointer-events:none)"
    # Sanity: the SMTP host input is still present in the DOM (greyed, not removed).
    assert 'id="smtp-host"' in body, "global SMTP host field should still render"
