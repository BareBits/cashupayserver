<?php
/**
 * FeeRedirect decision logic: candidate assembly + ordering, the pure
 * coverage check (staticallyCovers), and the null paths of decide() that
 * short-circuit before any network I/O.
 *
 * The positive end-to-end path (real fee-xpub address / fee-LNURL bolt11,
 * customer pays, credit recorded) is exercised by the Playwright e2e; here we
 * pin the pure selection rules: GATE (owed >= invoice), PRECEDENCE (largest
 * owed first) and ALL-RAILS-OR-NONE coverage.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/fee_redirect.php';

// ---- candidates(): ordering + field mapping --------------------------------
$store = [
    'hosting_fee_destination' => 'host@op.com',
    'hosting_fee_onchain_xpub' => 'xpubHOST',
    'hosting_fee_onchain_network' => 'mainnet',
    'hosting_fee_onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'mainnet',
];
$owed = ['upstream_owed' => 300, 'dev_owed' => 9000, 'hosting_owed' => 5000];
$cands = FeeRedirect::candidates($store, $owed);
assert_eq(3, count($cands), 'three fee candidates');
// Largest owed first: dev (9000) > hosting (5000) > upstream (300).
assert_eq('dev', $cands[0]['key'], 'dev sorts first');
assert_eq('hosting', $cands[1]['key'], 'hosting second');
assert_eq('upstream', $cands[2]['key'], 'upstream last');
assert_eq(FEE_NOTE_DEV, $cands[0]['note']);
assert_eq(9000, $cands[0]['owed']);
// Hosting destinations come from the store row.
$hosting = $cands[1];
assert_eq('host@op.com', $hosting['lnurl'], 'hosting lnurl from store');
assert_eq('xpubHOST', $hosting['xpub'], 'hosting xpub from store');
// Dev LNURL comes from the global constant (getbarebits default in tests).
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $cands[0]['lnurl'], 'dev lnurl from config');

// ---- staticallyCovers(): truth table ---------------------------------------
$full = ['lnurl' => 'a@b.com', 'xpub' => 'xpubX', 'network' => 'mainnet'];
assert_true(FeeRedirect::staticallyCovers($full, ['lightning', 'onchain'], 'mainnet'),
    'both rails covered when lnurl+xpub present and network matches');
assert_true(FeeRedirect::staticallyCovers($full, ['lightning'], 'mainnet'),
    'lightning-only covered by lnurl alone');
assert_true(FeeRedirect::staticallyCovers($full, ['onchain'], 'mainnet'),
    'onchain-only covered by matching xpub');

$noXpub = ['lnurl' => 'a@b.com', 'xpub' => '', 'network' => 'mainnet'];
assert_true(FeeRedirect::staticallyCovers($noXpub, ['lightning'], 'mainnet'),
    'no xpub still covers lightning-only');
assert_true(!FeeRedirect::staticallyCovers($noXpub, ['lightning', 'onchain'], 'mainnet'),
    'no xpub cannot cover onchain rail');

$noLnurl = ['lnurl' => '', 'xpub' => 'xpubX', 'network' => 'mainnet'];
assert_true(!FeeRedirect::staticallyCovers($noLnurl, ['lightning'], 'mainnet'),
    'no lnurl cannot cover lightning rail');
assert_true(FeeRedirect::staticallyCovers($noLnurl, ['onchain'], 'mainnet'),
    'no lnurl still covers onchain-only');

$wrongNet = ['lnurl' => 'a@b.com', 'xpub' => 'xpubX', 'network' => 'testnet'];
assert_true(!FeeRedirect::staticallyCovers($wrongNet, ['onchain'], 'mainnet'),
    'xpub on a different network cannot cover onchain (poller uses store network)');
assert_true(FeeRedirect::staticallyCovers($wrongNet, ['lightning'], 'mainnet'),
    'network mismatch irrelevant for lightning-only');

// ---- decide(): null paths (no network) -------------------------------------
$sid = 'store_dec';
make_store($sid, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);
$storeRow = Config::getStore($sid);

// No revenue -> nothing owed -> no redirect.
assert_null(
    FeeRedirect::decide($sid, $storeRow, 3000, ['lightning', 'onchain']),
    'no revenue: no redirect'
);

// Revenue accrues a dev fee, but the store offers an on-chain rail and NO fee
// xpub is configured (dev/upstream/hosting all empty by default), so no fee
// can cover all offered rails -> no redirect. This path returns before any
// LNURL probe, so it stays offline.
Database::insert('invoices', [
    'id' => 'inv_rev', 'store_id' => $sid, 'status' => 'Settled',
    'amount' => '500000', 'currency' => 'sat', 'amount_sats' => 500000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$o = DevFee::computeOwed($sid);
assert_true($o['dev_owed'] >= 3000, 'precondition: dev fee owed exceeds the invoice');
assert_null(
    FeeRedirect::decide($sid, $storeRow, 3000, ['onchain']),
    'onchain offered but no fee xpub configured: no redirect'
);
assert_null(
    FeeRedirect::decide($sid, $storeRow, 3000, ['lightning', 'onchain']),
    'mixed rails, no xpub for onchain: no redirect'
);

// Gate: an invoice larger than every owed fee never redirects (would overpay).
assert_null(
    FeeRedirect::decide($sid, $storeRow, 100_000_000, ['onchain']),
    'invoice larger than all owed fees: gate blocks redirect'
);

echo "test_fee_redirect_decision: ok\n";
