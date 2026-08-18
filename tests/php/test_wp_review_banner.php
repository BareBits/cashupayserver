<?php
/**
 * WordPress "leave us a review" banner (wordpress/admin-menu.php).
 *
 * Once setup is complete the admin_notices hook renders the review banner in
 * place of the "Configure BareBits" notice. Dismissal is site-wide via a WP
 * option: each dismissal hides it for 30 days, and after three dismissals it
 * is hidden permanently. The WordPress API surface the file touches
 * (options, nonces, capabilities, JSON responders) is stubbed below; the
 * option store is a plain array so the test can time-travel dismissed_at.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400);
define('CASHUPAY_PLUGIN_DIR', dirname(__DIR__, 2));
// WordPress mode so Urls::setup() (configure-notice branch) uses the site_url
// stub instead of standalone base-URL config.
define('CASHUPAY_WORDPRESS', true);
function site_url($path = '') { return 'http://wp.test' . $path; }
function admin_url($path = '') { return 'http://wp.test/wp-admin/' . $path; }
require_once dirname(__DIR__, 2) . '/includes/urls.php';

$GLOBALS['wp_options'] = [];
$GLOBALS['wp_can_manage'] = true;
$GLOBALS['wp_nonce_valid'] = true;
$GLOBALS['wp_json_response'] = null;

function add_action($hook, $cb) {}
function add_menu_page(...$args) {}
function current_user_can($cap) { return $GLOBALS['wp_can_manage']; }
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function check_ajax_referer($action, $query_arg) {
    if (!$GLOBALS['wp_nonce_valid']) {
        throw new RuntimeException('bad nonce');
    }
    return 1;
}
function wp_send_json_success($data = null) { $GLOBALS['wp_json_response'] = ['success' => true, 'data' => $data]; }
function wp_send_json_error($data = null, $code = 200) {
    $GLOBALS['wp_json_response'] = ['success' => false, 'code' => $code];
    throw new RuntimeException('json_error'); // wp_send_json_error exits in WP
}
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

require dirname(__DIR__, 2) . '/wordpress/admin-menu.php';

function render_notice(): string {
    ob_start();
    cashupay_admin_notice();
    return (string)ob_get_clean();
}

// --- setup complete: review banner renders, configure notice does not -------
$html = render_notice();
assert_true(str_contains($html, 'Enjoying having control of your money with'), 'review banner copy rendered');
assert_true(str_contains($html, 'https://wordpress.org/plugins/search/barebits/'), 'review link points at wordpress.org');
assert_true(str_contains($html, 'Leave us a review!'), 'review link text rendered');
assert_true(str_contains($html, 'is-dismissible'), 'banner is dismissible');
assert_false(str_contains($html, 'Configure BareBits'), 'configure notice not shown once setup is complete');

// --- non-admins never see it -------------------------------------------------
$GLOBALS['wp_can_manage'] = false;
assert_eq('', render_notice(), 'no notice for users without manage_options');
$GLOBALS['wp_can_manage'] = true;

// --- first dismissal: hidden now, back after 30 days -------------------------
cashupay_dismiss_review_notice();
assert_true(($GLOBALS['wp_json_response']['success'] ?? false) === true, 'dismiss handler responds success');
$state = get_option(CASHUPAY_REVIEW_OPTION);
assert_eq(1, $state['count'], 'first dismissal counted');
assert_eq('', render_notice(), 'banner hidden right after dismissal');

// 29 days later: still hidden. 31 days later: visible again.
$GLOBALS['wp_options'][CASHUPAY_REVIEW_OPTION]['dismissed_at'] = time() - 29 * DAY_IN_SECONDS;
assert_eq('', render_notice(), 'banner still hidden 29 days after dismissal');
$GLOBALS['wp_options'][CASHUPAY_REVIEW_OPTION]['dismissed_at'] = time() - 31 * DAY_IN_SECONDS;
assert_true(str_contains(render_notice(), 'Leave us a review!'), 'banner returns 31 days after dismissal');

// --- third dismissal: permanently hidden, even long after --------------------
cashupay_dismiss_review_notice();
cashupay_dismiss_review_notice();
$state = get_option(CASHUPAY_REVIEW_OPTION);
assert_eq(3, $state['count'], 'three dismissals counted');
assert_eq('', render_notice(), 'banner hidden after third dismissal');
$GLOBALS['wp_options'][CASHUPAY_REVIEW_OPTION]['dismissed_at'] = time() - 365 * DAY_IN_SECONDS;
assert_eq('', render_notice(), 'banner permanently hidden after three dismissals');

// --- corrupted option value falls back to visible -----------------------------
$GLOBALS['wp_options'][CASHUPAY_REVIEW_OPTION] = 'garbage';
assert_true(str_contains(render_notice(), 'Leave us a review!'), 'corrupted state falls back to visible');

// --- dismiss endpoint hardening ----------------------------------------------
$GLOBALS['wp_options'][CASHUPAY_REVIEW_OPTION] = [];
$GLOBALS['wp_nonce_valid'] = false;
try {
    cashupay_dismiss_review_notice();
    fail('dismiss without a valid nonce must not proceed');
} catch (RuntimeException $e) {
    assert_eq('bad nonce', $e->getMessage(), 'nonce check runs first');
}
assert_eq([], get_option(CASHUPAY_REVIEW_OPTION), 'failed nonce leaves state untouched');
$GLOBALS['wp_nonce_valid'] = true;

$GLOBALS['wp_can_manage'] = false;
try {
    cashupay_dismiss_review_notice();
    fail('dismiss without manage_options must not proceed');
} catch (RuntimeException $e) {
    assert_eq('json_error', $e->getMessage(), 'capability check rejects');
}
assert_eq(403, $GLOBALS['wp_json_response']['code'] ?? null, 'capability failure responds 403');
assert_eq([], get_option(CASHUPAY_REVIEW_OPTION), 'capability failure leaves state untouched');
$GLOBALS['wp_can_manage'] = true;

// --- setup incomplete: configure notice instead of review banner -------------
Config::set('setup_complete', false);
$html = render_notice();
assert_true(str_contains($html, 'Configure BareBits'), 'configure notice shown while setup incomplete');
assert_false(str_contains($html, 'Leave us a review!'), 'review banner not shown while setup incomplete');

echo "test_wp_review_banner: ok\n";
