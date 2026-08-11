<?php
/**
 * WordPress installs hide the "Offer payer receipt" and "Newsletter checkbox
 * checked by default" toggles (site-wide) and the per-store newsletter
 * default selector — the payment page never renders the email/newsletter
 * form there. A settings save from that reduced UI carries no values for the
 * hidden fields, so the save handlers must NOT clobber the stored values
 * with defaults. Standalone saves (the control) still update them.
 *
 * admin.php exits per request, so each save runs in its own PHP subprocess
 * with a runner that fakes an authenticated admin session (WP mode: stubbed
 * current_user_can; standalone: session user) plus the CSRF token.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

make_store('store_wpsave');

// Seed the values a merchant configured before switching contexts.
Config::set('notifications_payer_receipt_enabled', true);
Config::set('newsletter_default_checked', false);
Database::update('stores', ['newsletter_default_checked' => 1], 'id = ?', ['store_wpsave']);

$root = dirname(__DIR__, 2);
$runner = $dataDir . '/admin_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
if (getenv('T_WP') === '1') {
    define('CASHUPAY_WORDPRESS', true);
    // Minimal WordPress stand-ins: an authenticated WP admin.
    function current_user_can($cap) { return true; }
    function site_url($path = '') { return 'http://wp.test' . $path; }
    function plugins_url($path = '', $file = '') { return 'http://wp.test/wp-content/plugins/cashupay/' . $path; }
}
// Pre-authenticate: admin.php's dispatch checks the session-stored CSRF
// token, and (standalone) the session user. Auth::initSession is idempotent,
// so starting the session here first lets us seed both.
session_name('cashupay_session');
session_start();
$_SESSION['csrf_token'] = 'testtoken';
if (getenv('T_WP') !== '1') {
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'admin';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'wp.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
parse_str((string)getenv('T_POST'), $_POST);
$_POST['csrf_token'] = 'testtoken';
require %s;
PHP, var_export($root . '/admin.php', true)));

/** POST an admin action in a subprocess; returns the JSON response. */
function run_admin_post(bool $wordpress, array $post): array {
    global $dataDir, $runner;
    $env = sprintf(
        'T_DATA_DIR=%s T_POST=%s T_WP=%s',
        escapeshellarg($dataDir),
        escapeshellarg(http_build_query($post)),
        $wordpress ? '1' : '0'
    );
    $out = (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>/dev/null');
    $json = json_decode(trim($out), true);
    assert_true(is_array($json), 'admin action returned JSON: ' . $out);
    return $json;
}

$smtpBlank = [
    'smtp_host' => '', 'smtp_port' => '', 'smtp_username' => '',
    'smtp_encryption' => '', 'smtp_from_address' => '', 'smtp_from_name' => '',
    'smtp_password' => '', 'smtp_password_clear' => '0',
];

// --- WP save (hidden fields absent from the POST): stored values survive ---
$resp = run_admin_post(true, [
    'action' => 'save_notifications_settings',
    'enabled' => '1', 'invoice_paid_enabled' => '1',
    'auto_cashout_enabled' => '0', 'to_email' => '',
] + $smtpBlank);
assert_true(($resp['success'] ?? false) === true, 'WP notifications save succeeds');
Config::clearCache();
assert_eq(true, Config::get('notifications_payer_receipt_enabled', false) === true, 'WP save keeps payer receipt enabled');
assert_eq(true, Config::get('newsletter_default_checked', true) === false, 'WP save keeps newsletter default off');
assert_eq(true, Config::get('notifications_enabled', false) === true, 'WP save still applies visible toggles');

$resp = run_admin_post(true, [
    'action' => 'save_store_notifications',
    'store_id' => 'store_wpsave',
    'enabled' => '0', 'email' => '',
    'newsletter_default_checked' => '',
    'smtp_override_enabled' => '0',
] + $smtpBlank);
assert_true(($resp['success'] ?? false) === true, 'WP store save succeeds');
$row = Database::fetchOne("SELECT newsletter_default_checked FROM stores WHERE id = ?", ['store_wpsave']);
assert_eq(1, (int)$row['newsletter_default_checked'], 'WP store save keeps the per-store newsletter override');

// --- standalone control: the same saves DO update the fields ---
$resp = run_admin_post(false, [
    'action' => 'save_notifications_settings',
    'enabled' => '1', 'invoice_paid_enabled' => '1',
    'auto_cashout_enabled' => '0', 'to_email' => '',
    'payer_receipt_enabled' => '0', 'newsletter_default_checked' => '1',
] + $smtpBlank);
assert_true(($resp['success'] ?? false) === true, 'standalone notifications save succeeds');
Config::clearCache();
assert_eq(true, Config::get('notifications_payer_receipt_enabled', true) === false, 'standalone save updates payer receipt');
assert_eq(true, Config::get('newsletter_default_checked', false) === true, 'standalone save updates newsletter default');

$resp = run_admin_post(false, [
    'action' => 'save_store_notifications',
    'store_id' => 'store_wpsave',
    'enabled' => '0', 'email' => '',
    'newsletter_default_checked' => '0',
    'smtp_override_enabled' => '0',
] + $smtpBlank);
assert_true(($resp['success'] ?? false) === true, 'standalone store save succeeds');
$row = Database::fetchOne("SELECT newsletter_default_checked FROM stores WHERE id = ?", ['store_wpsave']);
assert_eq(0, (int)$row['newsletter_default_checked'], 'standalone store save updates the per-store override');

echo "test_admin_wp_notifications_settings_preserved: ok\n";
