<?php
/**
 * Fee-redirect accounting: when a redirect invoice settles, a melts credit
 * (via='redirect', tagged with the fee note) is recorded so DevFee::computeOwed
 * drops the owed amount and the cron won't melt the same fee again. The credit
 * is idempotent — re-running settlement (e.g. a dual-rail invoice observed by
 * both pollers) never double-credits.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/includes/dev_fee.php';

$store = 'store_credit';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);

// Prior revenue so a dev fee is owed well above our redirect invoice amount.
Database::insert('invoices', [
    'id' => 'inv_prior', 'store_id' => $store, 'status' => 'Settled',
    'amount' => '500000', 'currency' => 'sat', 'amount_sats' => 500000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

$before = DevFee::computeOwed($store);
assert_eq(10000, $before['dev_owed'], 'dev owed 2% of 500k = 10000');
assert_eq(0, $before['dev_paid'], 'nothing paid yet');

// A redirect invoice pointed at the dev fee, still New (not yet counted as
// revenue). amount_sats is the plain sat amount the customer pays.
$rid = 'inv_redirect';
Database::insert('invoices', [
    'id' => $rid, 'store_id' => $store, 'status' => 'New',
    'amount' => '3000', 'currency' => 'sat', 'amount_sats' => 3000,
    'payment_rail' => 'onchain',
    'onchain_address' => 'bc1qexamplefeeaddress0000000000000000000',
    'onchain_amount_sat' => 3000,
    'fee_redirect_note' => FEE_NOTE_DEV,
    'fee_redirect_destination' => 'bc1qexamplefeeaddress0000000000000000000',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);

// Settle it (as the on-chain poller would, via updateStatus).
Invoice::updateStatus($rid, 'Settled', null, 'onchain');

// A single redirect credit row exists for this invoice.
$melts = Database::fetchAll("SELECT * FROM melts WHERE invoice_id = ?", [$rid]);
assert_eq(1, count($melts), 'exactly one redirect credit recorded');
assert_eq('redirect', $melts[0]['via'], 'credit tagged via=redirect');
assert_eq(FEE_NOTE_DEV, $melts[0]['note'], 'credit carries the dev fee note');
assert_eq(3000, (int)$melts[0]['amount_sats'], 'credit amount = invoice sats');
assert_eq(0, (int)$melts[0]['network_fee_sats'], 'redirect has no network fee');

// computeOwed reflects it: the redirect invoice now counts as revenue (503000)
// and dev_paid jumped by 3000.
//   dev base = 503000 - 0 networkCost - 0 upstreamPaid = 503000
//   dev gross = floor(503000 * 0.02) = 10060
//   dev_owed  = 10060 - 3000 = 7060
$after = DevFee::computeOwed($store);
assert_eq(3000, $after['dev_paid'], 'dev_paid credited by the redirect');
assert_eq(503000, $after['revenue'], 'redirect invoice now counts as revenue');
assert_eq(7060, $after['dev_owed'], 'dev owed dropped by the redirected amount (less its ~2% residual)');
assert_true($after['dev_owed'] < $before['dev_owed'], 'owed strictly decreased');

// Idempotency: a second settlement pass (dual-rail race / re-poll) must not
// add a second credit.
Invoice::updateStatus($rid, 'Settled', null, 'onchain');
$count = (int) Database::fetchOne(
    "SELECT COUNT(*) AS c FROM melts WHERE invoice_id = ?", [$rid]
)['c'];
assert_eq(1, $count, 'settlement is idempotent: still one credit');

// A normal (non-redirect) invoice settling records NO redirect credit.
Database::insert('invoices', [
    'id' => 'inv_normal', 'store_id' => $store, 'status' => 'New',
    'amount' => '4000', 'currency' => 'sat', 'amount_sats' => 4000,
    'payment_rail' => 'mint',
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Invoice::updateStatus('inv_normal', 'Settled', null, 'mint');
$normalCredits = (int) Database::fetchOne(
    "SELECT COUNT(*) AS c FROM melts WHERE invoice_id = ?", ['inv_normal']
)['c'];
assert_eq(0, $normalCredits, 'normal invoice creates no redirect credit');

echo "test_fee_redirect_credit: ok\n";
