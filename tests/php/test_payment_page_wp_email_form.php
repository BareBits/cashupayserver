<?php
/**
 * WordPress installs must not offer the payment-page email/newsletter form:
 * WooCommerce owns customer emails and order confirmations there, so the
 * success modal shows only the "screenshot this page" fallback, and the
 * send_receipt POST endpoint is rejected outright (a crafted POST must not
 * record an email or newsletter opt-in). Standalone behaviour is pinned as
 * the control: form rendered, POST persists email + opt-in.
 *
 * payment.php exits at the end of every branch, so each request runs in its
 * own PHP subprocess via a small runner that fakes the superglobals and (in
 * WP mode) defines CASHUPAY_WORDPRESS plus the WordPress URL helpers the
 * page touches (site_url via Background::trigger→Urls::cron, plugins_url
 * via Urls::images).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

make_store('store_wpform');
Database::insert('invoices', [
    'id' => 'inv_wpform',
    'store_id' => 'store_wpform',
    'status' => 'Settled',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
    'paid_at' => Database::timestamp(),
]);

$root = dirname(__DIR__, 2);
$runner = $dataDir . '/payment_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
if (getenv('T_WP') === '1') {
    define('CASHUPAY_WORDPRESS', true);
    // Minimal stand-ins for the WordPress helpers the payment page reaches.
    function site_url($path = '') { return 'http://wp.test' . $path; }
    function plugins_url($path = '', $file = '') { return 'http://wp.test/wp-content/plugins/cashupay/' . $path; }
}
$_SERVER['HTTP_HOST'] = 'wp.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD') ?: 'GET';
$_GET['id'] = getenv('T_INVOICE');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['action'] = 'send_receipt';
    $_POST['email'] = 'payer@example.com';
    $_POST['newsletter'] = '1';
}
require %s;
PHP, var_export($root . '/payment.php', true)));

/** Run payment.php in a subprocess; returns its full output. */
function run_payment_page(bool $wordpress, string $method): string {
    global $dataDir, $runner;
    $env = sprintf(
        'T_DATA_DIR=%s T_INVOICE=inv_wpform T_METHOD=%s T_WP=%s',
        escapeshellarg($dataDir),
        escapeshellarg($method),
        $wordpress ? '1' : '0'
    );
    $out = shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
    return (string)$out;
}

// --- standalone GET: email form + newsletter checkbox rendered ---
$html = run_payment_page(false, 'GET');
assert_true(str_contains($html, 'id="receipt-form"'), 'standalone renders the receipt form');
assert_true(str_contains($html, 'Subscribe to our newsletter'), 'standalone renders the newsletter checkbox');

// --- WordPress GET: no form, no newsletter, fallback shown instead ---
$html = run_payment_page(true, 'GET');
assert_false(str_contains($html, 'id="receipt-form"'), 'WP mode must not render the receipt form');
assert_false(str_contains($html, 'Subscribe to our newsletter'), 'WP mode must not render the newsletter checkbox');
assert_false(str_contains($html, 'id="receipt-email"'), 'WP mode must not render the email input');
assert_true(str_contains($html, 'Screenshot this page'), 'WP mode shows the screenshot fallback');

// --- WordPress POST: rejected, nothing recorded ---
$out = run_payment_page(true, 'POST');
$json = json_decode(trim($out), true);
assert_true(is_array($json) && isset($json['error']), 'WP send_receipt POST is rejected: ' . $out);
$row = Database::fetchOne("SELECT customer_email, newsletter_opt_in FROM invoices WHERE id = ?", ['inv_wpform']);
assert_null($row['customer_email'], 'WP POST must not record an email');
assert_null($row['newsletter_opt_in'], 'WP POST must not record a newsletter opt-in');

// --- standalone POST (control): email + opt-in persisted ---
$out = run_payment_page(false, 'POST');
$json = json_decode(trim($out), true);
assert_true(is_array($json) && ($json['success'] ?? false) === true, 'standalone send_receipt succeeds: ' . $out);
$row = Database::fetchOne("SELECT customer_email, newsletter_opt_in FROM invoices WHERE id = ?", ['inv_wpform']);
assert_eq('payer@example.com', $row['customer_email'], 'standalone POST records the email');
assert_eq(1, (int)$row['newsletter_opt_in'], 'standalone POST records the newsletter opt-in');

echo "test_payment_page_wp_email_form: ok\n";
