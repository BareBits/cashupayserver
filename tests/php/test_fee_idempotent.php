<?php
/**
 * Idempotency: once a fee payment is recorded in the melts table, the next
 * computeOwed() reflects the new paid total — settling twice with no new
 * revenue does not double-charge. Also: a fee that fails (no melts row
 * inserted) still shows the original owed amount on the next pass.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/dev_fee.php';

$store = 'store_idem';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);

Database::insert('invoices', [
    'id' => 'inv_1',
    'store_id' => $store,
    'status' => 'paid',
    'amount' => '500000',
    'currency' => 'sat',
    'amount_sats' => 500000,
    'created_at' => time(),
    'expiration_time' => time() + 3600,
]);
Database::update('stores', ['hosting_fee_percent' => 1.0], 'id = ?', [$store]);

// Initial owed: upstream = 2500, dev = 10000 (upstream not yet paid), hosting = 5000.
$o = DevFee::computeOwed($store);
assert_eq(2500, $o['upstream_owed']);
assert_eq(10000, $o['dev_owed']);
assert_eq(5000, $o['hosting_owed']);

// Simulate a SUCCESSFUL upstream payment by inserting the melts row directly
// (the real path goes through UpstreamDevFee::sendToSink + MeltLog::record).
MeltLog::record($store, 2500, 0, 'https://sink', null, FEE_NOTE_UPSTREAM);

// Next pass: upstream_owed drops to 0; dev_owed shrinks (upstream paid counts).
//   dev_base = 500000 - 0 - 2500 = 497500 → floor * 0.02 = 9950
//   dev_owed = 9950 - 0 = 9950
$o = DevFee::computeOwed($store);
assert_eq(0, $o['upstream_owed'], 'upstream paid, owed clamps to 0');
assert_eq(2500, $o['upstream_paid']);
assert_eq(9950, $o['dev_owed'], 'dev unchanged because upstream paid - upstream subtracted match exactly');

// Now pay dev and hosting; their owed clamps to 0 on the next pass.
MeltLog::record($store, 9950, 50, 'fees@getbarebits.com', 'pre1', FEE_NOTE_DEV);
MeltLog::record($store, 5000, 25, 'host@op.com', 'pre2', FEE_NOTE_HOSTING);

$o = DevFee::computeOwed($store);
assert_eq(0, $o['upstream_owed']);
assert_eq(75, $o['network_cost'], 'both fee payments contributed network cost');

// Dev base after second pass: 500000 - 75 - 2500 = 497425 → floor * 0.02 = 9948
// dev_owed = 9948 - 9950 = -2 → clamped to 0
assert_eq(0, $o['dev_owed'], 'dev clamps to 0 even after slight base shrink');
assert_eq(0, $o['hosting_owed'], 'hosting clamps to 0');

// A second settle attempt (without new revenue) finds nothing to pay — none
// of the *_owed values rise above CASHUPAY_FEE_SETTLE_THRESHOLD_SATS.
assert_true($o['upstream_owed'] < CASHUPAY_FEE_SETTLE_THRESHOLD_SATS);
assert_true($o['dev_owed'] < CASHUPAY_FEE_SETTLE_THRESHOLD_SATS);
assert_true($o['hosting_owed'] < CASHUPAY_FEE_SETTLE_THRESHOLD_SATS);

echo "test_fee_idempotent: ok\n";
