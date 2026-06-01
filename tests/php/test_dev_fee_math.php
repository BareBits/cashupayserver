<?php
/**
 * DevFee::computeOwed math. Verifies the three formulas (upstream / dev /
 * hosting) work together with the network-cost decrement and the
 * "upstream paid counts toward network cost" rule.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/dev_fee.php';

$store = 'store_math';
make_store($store, 'https://m.example.com');

// Force a known fee_tracking_start_at so the harness's idempotent migration
// doesn't pin the cutoff to "now" (which would exclude our test invoices).
Config::set('fee_tracking_start_at', 0);

/**
 * Insert a paid invoice with a sat-denominated amount. Helper that ensures
 * created_at > fee_tracking_start_at so the row is in scope for the math.
 */
function paid_invoice(string $storeId, int $sats, int $createdAt): void {
    Database::insert('invoices', [
        'id' => 'inv_' . bin2hex(random_bytes(4)),
        'store_id' => $storeId,
        'status' => 'Settled',
        'amount' => (string) $sats,
        'currency' => 'sat',
        'amount_sats' => $sats,
        'created_at' => $createdAt,
        'expiration_time' => $createdAt + 3600,
    ]);
}

// 1. Empty store: nothing owed.
$o = DevFee::computeOwed($store);
assert_eq(0, $o['revenue'], 'no revenue');
assert_eq(0, $o['upstream_owed']);
assert_eq(0, $o['dev_owed']);
assert_eq(0, $o['hosting_owed']);

// 2. 100k sats revenue, no network costs, no hosting fee.
//    upstream = 100000 * 0.005 = 500 sats
//    dev      = (100000 - 0) * 0.02 = 2000 sats   (no upstream paid yet)
//    hosting  = 100000 * 0 / 100 = 0
paid_invoice($store, 100000, time());
$o = DevFee::computeOwed($store);
assert_eq(100000, $o['revenue']);
assert_eq(500, $o['upstream_owed'], 'upstream 0.5% of revenue');
assert_eq(2000, $o['dev_owed'], 'dev 2% of revenue (upstream not yet paid)');
assert_eq(0, $o['hosting_owed']);

// 3. After upstream is paid, dev fee base shrinks by upstream_paid.
//    dev base = 100000 - 0 - 500 = 99500 → floor(99500 * 0.02) = 1990
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 500,
    'network_fee_sats' => 0,
    'destination' => 'https://cypherpunk.today/donation-sink/donation-sink.php',
    'preimage' => null,
    'note' => FEE_NOTE_UPSTREAM,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(500, $o['upstream_paid']);
assert_eq(0, $o['upstream_owed'], 'upstream paid, nothing more owed');
assert_eq(1990, $o['dev_owed'], 'dev base shrunk by upstream paid');

// 4. Network cost from a user withdraw further reduces both bases.
//    User withdrew with 100 sats network fee.
//    upstream base = 100000 - 100 = 99900 → floor(99900 * 0.005) = 499
//      upstream_owed = 499 - 500 = -1 → clamped to 0 (already overpaid)
//    dev base = 100000 - 100 - 500 = 99400 → floor(99400 * 0.02) = 1988
//      dev_owed = 1988 - 0 = 1988
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 50000,
    'network_fee_sats' => 100,
    'destination' => 'user@somewhere.com',
    'preimage' => 'abc',
    'note' => null,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(100, $o['network_cost']);
assert_eq(0, $o['upstream_owed'], 'upstream already overpaid');
assert_eq(1988, $o['dev_owed'], 'dev base reflects network cost + upstream paid');

// 5. Hosting fee is flat over revenue (does NOT subtract network costs).
//    With 2% hosting: 100000 * 0.02 = 2000
Database::update('stores', ['hosting_fee_percent' => 2.0], 'id = ?', [$store]);
$o = DevFee::computeOwed($store);
assert_eq(2000, $o['hosting_owed'], 'hosting flat-over-revenue, ignores network cost');

// 6. After hosting paid, owed clamps to 0.
Database::insert('melts', [
    'store_id' => $store,
    'amount_sats' => 2000,
    'network_fee_sats' => 5,
    'destination' => 'host@somewhere.com',
    'preimage' => 'def',
    'note' => FEE_NOTE_HOSTING,
    'created_at' => time(),
]);
$o = DevFee::computeOwed($store);
assert_eq(2000, $o['hosting_paid']);
assert_eq(0, $o['hosting_owed']);
// The hosting payment also added 5 sats to network cost, shrinking dev base:
// dev base = 100000 - 105 - 500 = 99395 → floor * 0.02 = 1987
assert_eq(105, $o['network_cost'], 'hosting payment network fee counted');
assert_eq(1987, $o['dev_owed'], 'dev base shrunk by hosting payment network fee');

echo "test_dev_fee_math: ok\n";
