<?php
/**
 * The ECX fee ledger (EcxFees): revenue only from invoices settled over their
 * eCash address, owed = percentage minus credits, both fees accrue in ECX;
 * the sats ledger (DevFee::computeOwed) excludes those invoices; the redirect
 * decision follows the Bitcoin rules (gate owed >= invoice, largest first,
 * dev needs the install's branch, hosting needs its ECX xpub); a settlement
 * over a fee-routed eCash address credits the ledger exactly once.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$store = 'st_ecx';
make_store($store, 'https://m.example.com');
Config::set('fee_tracking_start_at', 0);
Database::update('stores', ['hosting_fee_percent' => 2.0], 'id = ?', [$store]);
$now = time();

// Two settled invoices: one paid in ECX (1,000,000 szats), one paid on-chain
// in sats (500,000 sats). Each ledger counts only its own.
Database::insert('invoices', [
    'id' => 'inv_ecx', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
    'ecx_address' => 'bc1qecxpaid', 'ecx_amount_szat' => 1000000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('invoices', [
    'id' => 'inv_btc', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'onchain',
    'amount' => '500000', 'currency' => 'sat', 'amount_sats' => 500000,
    'onchain_address' => 'bc1qbtcpaid', 'onchain_amount_sat' => 500000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
// An ECX-offered invoice that settled over Lightning is NOT ecx revenue.
Database::insert('invoices', [
    'id' => 'inv_ln', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'mint',
    'amount' => '20', 'currency' => 'USD', 'amount_sats' => 20000,
    'ecx_address' => 'bc1qecxunused', 'ecx_amount_szat' => 2000000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);

$owed = EcxFees::computeOwed($store);
assert_eq(1000000, $owed['revenue_szats'], 'ecx revenue = the ECX-settled invoice only');
assert_eq(10000, $owed['dev_owed'], '1% of 1,000,000 szats');
assert_eq(20000, $owed['hosting_owed'], '2% hosting');
assert_eq(0, $owed['dev_paid']);
$sats = DevFee::computeOwed($store);
assert_eq(520000, $sats['revenue'], 'sats ledger: on-chain + lightning invoices, not the ECX one');
assert_eq(5200, $sats['dev_owed'], 'sats dev fee untouched by ECX revenue');

// ---- candidates: ordering + coverage -------------------------------------------
$srow = Config::getStore($store);
$cands = EcxFees::candidates($srow, $owed);
assert_eq('hosting', $cands[0]['key'], 'largest owed first (hosting 20k > dev 10k)');
assert_eq('dev', $cands[1]['key']);
assert_eq((string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB, $cands[1]['xpub'], 'dev uses the BareBits zpub');
assert_eq(FEE_NOTE_HOSTING, $cands[0]['note']);
assert_false(EcxFees::covers($cands[0]), 'no hosting ECX xpub -> hosting cannot take the address');
$devCovers = (string)CASHUPAY_DEV_FEE_ONCHAIN_NETWORK === 'mainnet';
assert_eq($devCovers, EcxFees::covers($cands[1]), 'dev covers only with a mainnet dev xpub');

// ---- decide(): gate -----------------------------------------------------------
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled');
assert_null(EcxFees::decide($store, $srow, 50000), 'owed (20k / 10k) < invoice (50k) -> merchant keeps the address');
// Hosting owed 20k >= 15k but has no xpub; dev owed 10k < 15k -> null.
assert_null(EcxFees::decide($store, $srow, 15000), 'hosting covers by amount but has no destination');
// Dev: 10k >= 8k, but no branch allocated and the allocator is disabled -> null.
Config::set(FeeBranch::CONFIG_BRANCH, null);
if ($devCovers) {
    assert_null(EcxFees::decide($store, $srow, 8000), 'dev fee never takes the address without a branch');
}
// With a branch: the dev fee takes the address, derived under <zpub>/branch/0/i.
Config::set(FeeBranch::CONFIG_BRANCH, 5);
if ($devCovers) {
    $d = EcxFees::decide($store, $srow, 8000);
    assert_not_null($d, 'dev fee claims the eCash address');
    assert_eq(FEE_NOTE_DEV, $d['note']);
    assert_eq(5, $d['branch']);
    assert_eq(0, $d['index']);
    assert_eq(OnchainWallet::deriveAddress((string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB, (string)CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE, 'mainnet', 0, 5),
        $d['address'], 'address = zpub/5/0/0');
    assert_eq($d['address'], $d['destination']);
}
// Hosting with an ECX xpub configured wins at 15k (largest owed first).
$hostXpub = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';
Database::update('stores', ['hosting_fee_ecx_xpub' => $hostXpub, 'hosting_fee_ecx_address_type' => 'P2WPKH'], 'id = ?', [$store]);
$srow = Config::getStore($store);
$h = EcxFees::decide($store, $srow, 15000);
assert_not_null($h, 'hosting claims the address once it has an ECX xpub');
assert_eq(FEE_NOTE_HOSTING, $h['note']);
assert_null($h['branch'], 'hosting derives on the plain chain');
assert_eq(OnchainWallet::deriveAddress($hostXpub, 'P2WPKH', 'mainnet', 0), $h['address']);
// The deployment-wide free trial zeroes the ECX owed figures too.
Config::set('free_trial_until_ts', $now + 86400);
$trial = EcxFees::computeOwed($store);
assert_true($trial['trial_active'], 'trial active');
assert_eq(0, $trial['dev_owed'] + $trial['hosting_owed'], 'free trial -> nothing owed in ECX');
assert_null(EcxFees::decide($store, $srow, 1), 'nothing redirects during the trial');
Config::set('free_trial_until_ts', null);
assert_eq(20000, EcxFees::computeOwed($store)['hosting_owed'], 'owed again after the trial setting is cleared');

// ---- credit on settlement, once -----------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_fee', 'store_id' => $store, 'status' => 'New', 'payment_rail' => 'ecx',
    'amount' => '3', 'currency' => 'USD', 'amount_sats' => 3000,
    'ecx_address' => $h['address'], 'ecx_amount_szat' => 15000,
    'ecx_fee_note' => FEE_NOTE_HOSTING, 'ecx_fee_destination' => $h['address'],
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
// A merchant-rail settlement of the same invoice would NOT be a fee payment.
assert_false(EcxFees::settledToFee(['settled_rail' => 'onchain', 'ecx_fee_note' => FEE_NOTE_HOSTING]));
assert_true(EcxFees::settledToFee(['settled_rail' => 'ecx', 'ecx_fee_note' => FEE_NOTE_HOSTING]));
assert_false(EcxFees::settledToFee(['settled_rail' => 'ecx', 'ecx_fee_note' => null]), 'merchant eCash address');

$before = EcxFees::computeOwed($store);
assert_eq(20000, $before['hosting_owed']);
assert_true(Invoice::updateStatus('inv_fee', 'Settled', null, 'ecx'), 'settles over the eCash address');
$credits = Database::fetchAll("SELECT * FROM ecx_fee_credits WHERE store_id = ?", [$store]);
assert_eq(1, count($credits), 'one credit row');
assert_eq(15000, (int)$credits[0]['amount_szat']);
assert_eq(FEE_NOTE_HOSTING, $credits[0]['note']);
assert_eq('inv_fee', $credits[0]['invoice_id']);
$after = EcxFees::computeOwed($store);
// Revenue grew by the fee invoice (15k -> hosting +300) and 15k was paid.
assert_eq(1015000, $after['revenue_szats']);
assert_eq(15000, $after['hosting_paid']);
assert_eq(20300 - 15000, $after['hosting_owed'], 'owed drops by the credited amount');
assert_eq(10150, $after['dev_owed'], 'dev untouched by a hosting credit');
// Idempotent: a second credit attempt is a no-op.
EcxFees::recordCredit(Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_fee'"));
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_fee_credits")['n'], 'no duplicate credit');
// The sats ledger never saw any of it.
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM melts")['n'], 'no melts row for an ECX fee');
// paidByNote for the stats card.
$paid = EcxFees::paidByNote($store, null);
assert_eq(['dev' => 0, 'hosting' => 15000], $paid);
assert_eq(['dev' => 0, 'hosting' => 15000], EcxFees::paidByNote('__all__', null), 'all stores');
assert_eq(['dev' => 0, 'hosting' => 0], EcxFees::paidByNote($store, $now + 100), 'outside the window');

echo "ok\n";
