"""WordPress fixture smoke tests: plugin activates, admin menu present."""
from __future__ import annotations

import pytest

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress


def test_wp_install_is_reachable(wordpress: WordPressHandle) -> None:
    """The fresh WP install responds on its ephemeral port."""
    import requests
    r = requests.get(wordpress.url, timeout=10)
    assert r.status_code == 200
    assert "wordpress" in r.text.lower() or "html" in r.headers.get("Content-Type", "").lower()


def test_cashupay_plugin_is_active(wordpress: WordPressHandle) -> None:
    """wp-cli reports cashupay as an active plugin."""
    result = wordpress.wp_cli("plugin", "list", "--field=name", "--status=active")
    active = result.stdout.split()
    assert "cashupay" in active, f"cashupay not active; active plugins: {active}"


def test_cashupay_constants_defined(wordpress: WordPressHandle) -> None:
    """Plugin bootstrap.php defines CASHUPAY_WORDPRESS and CASHUPAY_PLUGIN_DIR."""
    # `wp eval` runs PHP within the WP context — including loaded plugins.
    result = wordpress.wp_cli(
        "eval",
        "echo (int)defined('CASHUPAY_WORDPRESS') . '|' . (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR : 'undef');",
    )
    flag, plugin_dir = result.stdout.strip().split("|", 1)
    assert flag == "1", f"CASHUPAY_WORDPRESS not defined: {result.stdout!r} stderr={result.stderr!r}"
    assert "cashupay" in plugin_dir, plugin_dir


def test_cashupay_data_dir_honored(wordpress: WordPressHandle) -> None:
    """wp-config.php pins CASHUPAY_DATA_DIR to the per-test isolated path."""
    result = wordpress.wp_cli("eval", "echo CASHUPAY_DATA_DIR;")
    assert str(wordpress.data_dir) == result.stdout.strip(), result.stdout


def test_plugin_uri_points_at_barebits_repo(wordpress: WordPressHandle) -> None:
    """The "Visit plugin site" link on the Plugins screen must lead to the
    BareBits fork that actually ships this plugin, not the old ArcadeLabsInc
    upstream URL that no longer hosts it."""
    result = wordpress.wp_cli(
        "eval",
        "require_once ABSPATH . 'wp-admin/includes/plugin.php';"
        "echo get_plugin_data(WP_PLUGIN_DIR . '/cashupay/cashupay.php', false, false)['PluginURI'];",
    )
    uri = result.stdout.strip()
    assert uri == "https://github.com/BareBits/cashupayserver", uri


def test_admin_menu_is_a_top_level_section_not_under_tools(wordpress: WordPressHandle) -> None:
    """BareBits gets its own top-level sidebar section. Regression guard against
    the previous placement as a Tools submenu, which buried it."""
    snippet = (
        "require_once ABSPATH . 'wp-admin/includes/plugin.php';"
        "wp_set_current_user(1);"
        "do_action('admin_menu');"
        "global $menu, $submenu;"
        "$top = false;"
        "foreach ((array)$menu as $m) { if (($m[2] ?? '') === 'cashupay') $top = true; }"
        "$underTools = false;"
        "foreach ((array)($submenu['tools.php'] ?? []) as $m) { if (($m[2] ?? '') === 'cashupay') $underTools = true; }"
        "echo ($top ? '1' : '0') . '|' . ($underTools ? '1' : '0');"
    )
    top, under_tools = wordpress.wp_cli("eval", snippet).stdout.strip().split("|")
    assert top == "1", "BareBits should register a top-level admin menu page"
    assert under_tools == "0", "BareBits must no longer live under the Tools menu"


def test_unconfigured_admin_banner_invites_configuration(wordpress: WordPressHandle) -> None:
    """A freshly installed, not-yet-configured plugin shows an admin banner that
    links operators into setup via the embedded BareBits admin page (the menu
    page iframes the wizard while setup is incomplete), keeping them inside
    wp-admin. Rendered for a manage_options user."""
    snippet = (
        "wp_set_current_user(1);"
        "ob_start(); cashupay_admin_notice(); echo ob_get_clean();"
    )
    body = wordpress.wp_cli("eval", snippet).stdout
    assert "Configure BareBits" in body, body[:400]
    assert "admin.php?page=cashupay" in body, (
        "banner must link to the embedded admin page: " + body[:400]
    )
    assert "cashupay-setup" not in body, (
        "banner must no longer navigate out of wp-admin to the bare wizard: " + body[:400]
    )


def test_admin_banner_never_leaks_to_the_storefront(wordpress: WordPressHandle) -> None:
    """The configuration banner is an admin_notices callback, so it must stay on
    wp-admin and never render on the customer-facing site."""
    import requests
    r = requests.get(wordpress.url, timeout=10)
    assert "Configure BareBits" not in r.text
