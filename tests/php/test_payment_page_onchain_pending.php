<?php
/**
 * On-chain "payment detected" flow on the payment page.
 *
 * A Processing invoice with onchain_first_seen_at set (tx seen in the
 * mempool, unconfirmed) must render the unified detected/complete screen in
 * pending mode: the on-chain confirmation copy, pending badge, no
 * Continue-to-Store link — while a Processing invoice WITHOUT an on-chain
 * observation (transient Cashu minting state) keeps the old generic
 * "Payment detected. Please wait..." block. The JSON poll exposes the
 * distinction as onchainPending.
 *
 * The email form works during pending: the POST saves the address and flags
 * payer_receipt_requested, and the receipt itself is queued only at
 * settlement (via the payment-page poll flush / cron sweep, exercised here
 * through NotificationSender::flushRequestedPayerReceipts and the JSON poll).
 *
 * Invoices deliberately have NO onchain_address so pollSingleQuote never
 * reaches the on-chain provider (no network in tests); the page keys the
 * pending mode off onchain_first_seen_at alone.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/notification_sender.php';

// Offer payer receipts so the pending email flow sets the request flag.
Config::set('notifications_enabled', true);
Config::set('notifications_payer_receipt_enabled', true);
Config::set('smtp_host', 'smtp.test');

make_store('store_oc');

// Transient Processing (Cashu minting after a Lightning payment): no on-chain
// observation.
Database::insert('invoices', [
    'id' => 'inv_transient',
    'store_id' => 'store_oc',
    'status' => 'Processing',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
]);

// On-chain pending: mempool observation stamped, waiting for confirmation.
Database::insert('invoices', [
    'id' => 'inv_onchain',
    'store_id' => 'store_oc',
    'status' => 'Processing',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
    'onchain_first_seen_at' => Database::timestamp(),
    'checkout_config' => json_encode(['redirectURL' => 'https://shop.test/thanks']),
]);

$root = dirname(__DIR__, 2);
$runner = $dataDir . '/payment_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/payment.php';
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD') ?: 'GET';
$_GET['id'] = getenv('T_INVOICE');
if (getenv('T_JSON') === '1') {
    $_GET['json'] = '1';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['action'] = 'send_receipt';
    $_POST['email'] = getenv('T_EMAIL') ?: 'payer@example.com';
    $_POST['newsletter'] = '0';
}
require %s;
PHP, var_export($root . '/payment.php', true)));

/** Run payment.php in a subprocess; returns its full output. */
function run_payment_page(string $invoiceId, string $method = 'GET', bool $json = false, string $email = ''): string {
    global $dataDir, $runner;
    $env = sprintf(
        'T_DATA_DIR=%s T_INVOICE=%s T_METHOD=%s T_JSON=%s T_EMAIL=%s',
        escapeshellarg($dataDir),
        escapeshellarg($invoiceId),
        escapeshellarg($method),
        $json ? '1' : '0',
        escapeshellarg($email)
    );
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
}

$pendingCopy = 'Payment detected, waiting for on-chain confirmation';

// --- transient Processing: old generic block, no unified screen ---
$html = run_payment_page('inv_transient');
assert_true(str_contains($html, 'id="payment-processing" class=""'), 'transient Processing shows the generic processing block');
assert_true(str_contains($html, 'Payment detected. Please wait...'), 'transient Processing keeps the generic copy');
assert_false(str_contains($html, 'success-animation show'), 'transient Processing must not show the unified screen');

$json = json_decode(trim(run_payment_page('inv_transient', 'GET', true)), true);
assert_eq('Processing', $json['status'] ?? null, 'transient poll status');
assert_eq(false, $json['onchainPending'] ?? null, 'transient poll reports onchainPending=false');

// --- on-chain pending: unified screen in pending mode ---
$html = run_payment_page('inv_onchain');
assert_true(str_contains($html, 'id="payment-processing" class="hidden"'), 'on-chain pending hides the generic processing block');
assert_true(str_contains($html, 'success-animation show'), 'on-chain pending shows the unified screen');
$flatHtml = preg_replace('/\s+/', ' ', $html);
assert_true(str_contains(
    $flatHtml,
    'Payment detected, waiting for on-chain confirmation. Please do not close this window. '
    . 'Confirmation usually takes up to 10 minutes, but may take longer if you elected to pay a low network fee.'
), 'on-chain pending renders the full confirmation copy');
assert_true(str_contains($html, 'id="success-badge-pending"'), 'pending badge present');
assert_true(str_contains($html, 'status-badge settled hidden'), 'settled badge starts hidden in pending mode');
assert_true(str_contains($html, 'class="btn hidden" id="redirect-btn"'), 'Continue-to-Store is hidden while confirming');
assert_true(str_contains($html, 'id="receipt-form"'), 'email form is offered while confirming');

$json = json_decode(trim(run_payment_page('inv_onchain', 'GET', true)), true);
assert_eq('Processing', $json['status'] ?? null, 'on-chain pending poll status');
assert_eq(true, $json['onchainPending'] ?? null, 'on-chain pending poll reports onchainPending=true');

// --- email POST during transient Processing: still rejected ---
$out = run_payment_page('inv_transient', 'POST');
$json = json_decode(trim($out), true);
assert_true(is_array($json) && isset($json['error']), 'transient Processing rejects send_receipt: ' . $out);

// --- email POST during on-chain pending: saved + flagged, nothing queued ---
$out = run_payment_page('inv_onchain', 'POST', false, 'typo@example.com');
$json = json_decode(trim($out), true);
assert_true(is_array($json) && ($json['success'] ?? false) === true, 'pending send_receipt succeeds: ' . $out);
assert_eq(true, $json['receiptPending'] ?? null, 'pending send_receipt reports receiptPending');
assert_eq(false, $json['receiptQueued'] ?? null, 'pending send_receipt must not queue a receipt yet');

// Resubmission with a corrected address: last one wins, still one request.
$out = run_payment_page('inv_onchain', 'POST', false, 'payer@example.com');
$json = json_decode(trim($out), true);
assert_true(is_array($json) && ($json['success'] ?? false) === true, 'pending resubmission succeeds: ' . $out);

$row = Database::fetchOne("SELECT customer_email, payer_receipt_requested FROM invoices WHERE id = ?", ['inv_onchain']);
assert_eq('payer@example.com', $row['customer_email'], 'latest email wins');
assert_eq(1, (int)$row['payer_receipt_requested'], 'receipt request flagged');
$queue = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM notification_queue WHERE invoice_id = ? AND event_type = ?",
    ['inv_onchain', NotificationSender::EVENT_PAYER_RECEIPT]
);
assert_eq(0, (int)$queue['c'], 'no receipt queued before settlement');

// --- settlement: the payment-page poll flushes the requested receipt ---
Database::update('invoices', ['status' => 'Settled', 'paid_at' => time()], 'id = ?', ['inv_onchain']);
$json = json_decode(trim(run_payment_page('inv_onchain', 'GET', true)), true);
assert_eq('Settled', $json['status'] ?? null, 'poll sees settlement');

$queue = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM notification_queue WHERE invoice_id = ? AND event_type = ? AND to_email = ?",
    ['inv_onchain', NotificationSender::EVENT_PAYER_RECEIPT, 'payer@example.com']
);
assert_eq(1, (int)$queue['c'], 'settlement poll queues exactly one receipt to the latest email');
$row = Database::fetchOne("SELECT payer_receipt_requested FROM invoices WHERE id = ?", ['inv_onchain']);
assert_eq(0, (int)$row['payer_receipt_requested'], 'request flag cleared after queueing');

// --- the cron sweep finds nothing left to do (no double receipt) ---
assert_eq(0, NotificationSender::flushRequestedPayerReceipts(), 'sweep after flush queues nothing');
$queue = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM notification_queue WHERE invoice_id = ? AND event_type = ?",
    ['inv_onchain', NotificationSender::EVENT_PAYER_RECEIPT]
);
assert_eq(1, (int)$queue['c'], 'still exactly one receipt after the sweep');

// --- cron-sweep path in isolation: payer closed the tab before settlement ---
Database::insert('invoices', [
    'id' => 'inv_walkaway',
    'store_id' => 'store_oc',
    'status' => 'Settled',
    'amount' => '21',
    'currency' => 'sat',
    'created_at' => Database::timestamp(),
    'expiration_time' => Database::timestamp() + 3600,
    'paid_at' => Database::timestamp(),
    'customer_email' => 'gone@example.com',
    'payer_receipt_requested' => 1,
]);
assert_eq(1, NotificationSender::flushRequestedPayerReceipts(), 'sweep queues the walked-away receipt');
$queue = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM notification_queue WHERE invoice_id = ? AND event_type = ? AND to_email = ?",
    ['inv_walkaway', NotificationSender::EVENT_PAYER_RECEIPT, 'gone@example.com']
);
assert_eq(1, (int)$queue['c'], 'sweep queued the receipt for the walked-away payer');

echo "test_payment_page_onchain_pending: ok\n";
