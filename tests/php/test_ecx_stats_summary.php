<?php
/**
 * Stats::summary / Stats::chart with the eCash (ECX) ledger: ECX-settled
 * invoices leave the sats figures, appear in the `ecx` block in szats, and
 * fold into the headline totals as a sats equivalent at the current ECX:BTC
 * ratio (0, flagged, without a price).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/stats.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';

final class StubPriceProvider implements PriceProvider {
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return strtoupper($currency) === 'USD' ? 100000.0 : null; }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider()];
EcxRates::$testEcxUsd = 40.0; // 1 szat = 0.0004 sat
Config::set('fee_tracking_start_at', 0);
make_store('s1', 'https://m.example.com');
Database::update('stores', ['ecx_xpub' => 'zpubx', 'hosting_fee_percent' => 0], 'id = ?', ['s1']);
$now = time();

Database::insert('invoices', [
    'id' => 'btc', 'store_id' => 's1', 'status' => 'Settled', 'settled_rail' => 'onchain',
    'amount' => '100000', 'currency' => 'sat', 'amount_sats' => 100000, 'onchain_first_seen_at' => $now,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('invoices', [
    'id' => 'ln', 'store_id' => 's1', 'status' => 'Settled', 'settled_rail' => 'mint',
    'amount' => '50000', 'currency' => 'sat', 'amount_sats' => 50000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
// Paid in eCash: 2 ECX = 200,000,000 szats (= 80,000 sats at the ratio).
Database::insert('invoices', [
    'id' => 'ecx', 'store_id' => 's1', 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '80', 'currency' => 'USD', 'amount_sats' => 80000,
    'ecx_address' => 'bc1qecx', 'ecx_amount_szat' => 200000000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
// A dev-fee credit in ECX: 1,000,000 szats (= 400 sats).
Database::insert('ecx_fee_credits', [
    'store_id' => 's1', 'invoice_id' => 'ecx_fee_inv', 'note' => FEE_NOTE_DEV,
    'amount_szat' => 1000000, 'destination' => 'bc1qdev', 'created_at' => $now,
]);

$s = Stats::summary('s1', 'all');
assert_eq(150000, $s['revenue_sats'], 'sats revenue excludes the ECX-settled invoice');
assert_eq(100000, $s['onchain_revenue_sats']);
assert_eq(50000, $s['lightning_revenue_sats']);
assert_eq(3, $s['invoice_count'], 'invoice count includes the eCash one');
$e = $s['ecx'];
assert_eq(200000000, $e['revenue_szats']);
assert_eq(1, $e['count']);
assert_eq(1000000, $e['fees_paid']['dev']);
assert_eq(0, $e['fees_paid']['hosting']);
assert_eq(1000000, $e['fees_paid']['total']);
// Owed: 1% of 200,000,000 = 2,000,000 minus 1,000,000 paid.
assert_eq(1000000, $e['fees_owed']['dev']);
assert_eq(1000000, $e['fees_owed']['total']);
assert_true($e['price_available']);
assert_eq(40.0, $e['ecx_price'], 'ECX price in the display currency (USD)');
assert_eq(0.0004, $e['szat_to_sat']);
assert_eq(80000, $e['revenue_sats_equiv']);
assert_eq(400, $e['fees_paid_sats_equiv']);
assert_eq(400, $e['fees_owed_sats_equiv']);
// Headline totals fold the equivalents in.
assert_eq(230000, $s['revenue_total_sats'], '150,000 sats + 80,000 sats-equivalent of ECX');
assert_eq(400, $s['fees_paid']['ecx_sats_equiv']);
assert_eq(400, $s['fees_paid']['total'], 'no sats fees paid; the ECX credit folds in');
assert_eq(0, $s['fees_paid']['total_sats_ledger']);
assert_eq(400, $s['fees_owed']['ecx_sats_equiv']);
// Sats dev owed: 1% of 150,000 = 1,500; plus 400 equivalent.
assert_eq(1500, $s['fees_owed']['dev']);
assert_eq(1900, $s['fees_owed']['total']);
assert_eq(230000 - 400, $s['profit_sats']);

// Charts: eCash is its own series.
$rev = Stats::chart('s1', 'all', 'revenue');
assert_eq(['Lightning', 'On-chain', 'eCash'], $rev['labels']);
assert_eq([50000, 100000, 80000], $rev['data'], 'eCash revenue as sats equivalent');
$cnt = Stats::chart('s1', 'all', 'count');
assert_eq([1, 1, 1], $cnt['data']);
$fees = Stats::chart('s1', 'all', 'fees');
assert_eq(['Dev fee', 'Hosting fee', 'Network fees', 'eCash fees'], $fees['labels']);
assert_eq([0, 0, 0, 400], $fees['data']);

// Without a price: raw szat figures stay, equivalents are 0 and flagged.
EcxRates::$testEcxUsd = 0.0;
$s2 = Stats::summary('s1', 'all');
assert_false($s2['ecx']['price_available']);
assert_eq(200000000, $s2['ecx']['revenue_szats']);
assert_eq(0, $s2['ecx']['revenue_sats_equiv']);
assert_eq(150000, $s2['revenue_total_sats'], 'nothing folded without a price');
assert_eq(1500, $s2['fees_owed']['total']);

// All-stores view sums per store.
assert_eq(200000000, Stats::summary(Stats::ALL_STORES, 'all')['ecx']['revenue_szats']);

ExchangeRates::$testProviders = null;
EcxRates::$testEcxUsd = null;
echo "ok\n";
