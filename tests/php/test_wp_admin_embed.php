<?php
/**
 * WordPress embedded admin page (wordpress/admin-menu.php).
 *
 * Clicking "BareBits" in the wp-admin sidebar renders the payserver admin —
 * or the setup wizard while setup is incomplete — inside a full-height
 * same-origin iframe, instead of the old window.location redirect that
 * navigated the operator out of wp-admin. The "Configure BareBits" notice
 * routes to that embedded page too. The WordPress API surface is stubbed the
 * same way as test_wp_review_banner.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400);
define('CASHUPAY_PLUGIN_DIR', dirname(__DIR__, 2));
define('CASHUPAY_WORDPRESS', true);
function site_url($path = '') { return 'http://wp.test' . $path; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }
require_once dirname(__DIR__, 2) . '/includes/urls.php';

$GLOBALS['wp_options'] = [];
function add_action($hook, $cb) {}
function add_menu_page(...$args) {}
function current_user_can($cap) { return true; }
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

require dirname(__DIR__, 2) . '/wordpress/admin-menu.php';

function render_admin_page(): string {
    ob_start();
    cashupay_admin_page();
    return (string)ob_get_clean();
}

// --- setup complete: iframe embeds the admin SPA, no redirect ----------------
$html = render_admin_page();
assert_true(str_contains($html, '<iframe'), 'menu page renders an iframe');
assert_true(str_contains($html, 'http://wp.test/cashupay-admin/'), 'iframe embeds the admin SPA URL');
assert_false(str_contains($html, 'window.location'), 'no JS redirect out of wp-admin');
assert_true(str_contains($html, '--wp-admin--admin-bar--height'), 'iframe height accounts for the admin bar');

// --- setup incomplete: iframe embeds the wizard instead ----------------------
Config::set('setup_complete', false);
$html = render_admin_page();
assert_true(str_contains($html, 'http://wp.test/cashupay-setup/'), 'unconfigured plugin embeds the setup wizard');
assert_false(str_contains($html, '/cashupay-admin/'), 'admin SPA not embedded while setup is incomplete');

// --- configure notice routes into the embedded page, not the bare wizard -----
ob_start();
cashupay_admin_notice();
$notice = (string)ob_get_clean();
assert_true(str_contains($notice, 'Configure BareBits'), 'configure notice shown while setup incomplete');
assert_true(str_contains($notice, 'admin.php?page=cashupay'), 'configure notice links to the embedded admin page');
assert_false(str_contains($notice, 'cashupay-setup'), 'configure notice no longer leaves wp-admin for the wizard');

// --- wizard wp-admin links must escape the iframe ----------------------------
// setup.php can render inside the embedded iframe; every link it emits into
// wp-admin needs target="_top" or wp-admin would nest inside itself.
$setupSrc = file(dirname(__DIR__, 2) . '/setup.php');
$checked = 0;
foreach ($setupSrc as $i => $line) {
    if (str_contains($line, '<a ') && str_contains($line, 'admin_url(')) {
        assert_true(
            str_contains($line, 'target="_top"'),
            'setup.php line ' . ($i + 1) . ': wp-admin link is missing target="_top"'
        );
        $checked++;
    }
}
assert_true($checked >= 9, "expected at least 9 wp-admin links in setup.php, found $checked");

echo "test_wp_admin_embed: ok\n";
