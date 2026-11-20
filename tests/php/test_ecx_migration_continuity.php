<?php
/**
 * Upgrading a running install to the eCash (ECX) release: a pre-ECX database
 * with real data (a mint-less Bitcoin on-chain store, settled invoices, an
 * onchain_payments row, a fee melt, a webhook) gains the ecx_* columns, the
 * ecx_payments / ecx_fee_credits tables and the ecx_address index on the
 * first connection, with every existing row intact and the ECX defaults
 * applied (offer on, xpub mode, 1 confirmation, 86400 s window, no
 * fallback). Afterwards the rail is simply "not configured" for every
 * existing store: invoices keep being created without an eCash leg and
 * without an ECX attempt-log row, stats report an empty ECX ledger, and the
 * Bitcoin poller / fee math see exactly what they saw before.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/stats.php';

function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}
function has_col(string $table, string $col): bool {
    $r = Database::fetchOne("SELECT COUNT(*) AS n FROM pragma_table_info('{$table}') WHERE name = ?", [$col]);
    return (int)($r['n'] ?? 0) > 0;
}
function has_table(string $table): bool {
    $r = Database::fetchOne("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
    return (int)($r['n'] ?? 0) > 0;
}
function has_index(string $name): bool {
    $r = Database::fetchOne("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);
    return (int)($r['n'] ?? 0) > 0;
}

$ECX_STORE_COLS = [
    'ecx_offer_enabled', 'ecx_xpub', 'ecx_address_mode', 'ecx_static_address', 'ecx_static_tweak_range',
    'ecx_address_type', 'ecx_min_confs', 'ecx_confirm_timeout_sec', 'ecx_provider_url', 'ecx_provider_fallback',
    'ecx_next_index', 'hosting_fee_ecx_xpub', 'hosting_fee_ecx_address_type',
];
$ECX_INVOICE_COLS = [
    'ecx_address', 'ecx_address_index', 'ecx_amount_szat', 'ecx_amount_tweak_szats', 'ecx_amount_floor_szat',
    'ecx_first_seen_at', 'ecx_created_tip_height', 'ecx_needs_manual_confirmation', 'ecx_manual_candidates',
    'ecx_last_polled_at', 'ecx_exchange_rate', 'ecx_fee_note', 'ecx_fee_destination',
];

// ---- pre-upgrade data on a Bitcoin on-chain store --------------------------------------
$merchant = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';
$now = time();
make_store('legacy'); // mint-less on-chain store: hermetic (no mint quote attempted)
Database::update('stores', [
    'onchain_xpub' => $merchant, 'onchain_address_type' => 'P2WPKH', 'onchain_network' => 'mainnet',
    'onchain_min_confs' => 2, 'onchain_confirm_timeout_sec' => 7200, 'hosting_fee_percent' => 1.5,
], 'id = ?', ['legacy']);
Config::set('fee_tracking_start_at', 0);
Database::insert('invoices', [
    'id' => 'old_paid', 'store_id' => 'legacy', 'status' => 'Settled', 'settled_rail' => 'onchain',
    'amount' => '100000', 'currency' => 'sat', 'amount_sats' => 100000,
    'onchain_address' => 'bc1qoldpaid', 'onchain_address_index' => 0, 'onchain_amount_sat' => 100000,
    'onchain_first_seen_at' => $now - 7200, 'onchain_created_tip_height' => 900000,
    'created_at' => $now - 86400, 'expiration_time' => $now - 86400 + 3600, 'paid_at' => $now - 7000,
]);
Database::insert('onchain_payments', [
    'id' => 'och_old', 'invoice_id' => 'old_paid', 'txid' => str_repeat('d', 64), 'vout' => 0,
    'amount_sat' => 100000, 'confirmations' => 6, 'block_height' => 900001,
    'first_seen_at' => $now - 7200, 'last_seen_at' => $now - 7000,
]);
Database::insert('invoices', [
    'id' => 'old_open', 'store_id' => 'legacy', 'status' => 'New', 'payment_rail' => 'onchain',
    'amount' => '5000', 'currency' => 'sat', 'amount_sats' => 5000,
    'onchain_address' => 'bc1qoldopen', 'onchain_address_index' => 1, 'onchain_amount_sat' => 5000,
    'created_at' => $now - 60, 'expiration_time' => $now + 3540,
]);
Database::insert('melts', [
    'store_id' => 'legacy', 'amount_sats' => 1000, 'network_fee_sats' => 1, 'destination' => 'fees@getbarebits.com',
    'note' => FEE_NOTE_DEV, 'invoice_id' => 'old_paid', 'created_at' => $now - 3600,
]);
Database::insert('webhooks', [
    'id' => 'wh_old', 'store_id' => 'legacy', 'url' => 'https://shop.example/hook', 'secret' => 'shh',
    'events' => json_encode([]), 'enabled' => 1, 'created_at' => $now,
]);
$owedBefore = DevFee::computeOwed('legacy');
$statsBefore = Stats::summary('legacy', 'all');

// ---- roll the schema back to the release before ECX ----------------------------------------
$pdo = Database::getInstance();
// The release before ECX predates the Cashu maximum and tip jars too: drop
// those newer marker sets first, or getInstance() sees the current marker
// and never re-runs.
$pdo->exec("ALTER TABLE stores DROP COLUMN cashu_max_invoice_sats");
$pdo->exec("ALTER TABLE stores DROP COLUMN cashu_max_respect_always");
$pdo->exec("UPDATE invoices SET tip_jar_id = NULL");
$pdo->exec("ALTER TABLE invoices DROP COLUMN tip_jar_id");
$pdo->exec("DROP TABLE tip_jars");
$pdo->exec("DROP INDEX IF EXISTS idx_invoices_ecx_address");
$pdo->exec("DROP TABLE ecx_payments");
$pdo->exec("DROP TABLE ecx_fee_credits");
foreach ($ECX_INVOICE_COLS as $c) {
    $pdo->exec("ALTER TABLE invoices DROP COLUMN {$c}");
}
foreach ($ECX_STORE_COLS as $c) {
    $pdo->exec("ALTER TABLE stores DROP COLUMN {$c}");
}
assert_false(has_col('stores', 'cashu_max_invoice_sats'), 'pre-ECX: current marker column gone');
assert_false(has_col('invoices', 'tip_jar_id'), 'pre-ECX: tip jar marker column gone');
assert_false(has_col('stores', 'ecx_xpub'), 'pre-ECX: ECX marker column gone');
assert_false(has_table('ecx_payments'));
assert_false(has_col('invoices', 'ecx_amount_szat'));
assert_true(has_col('stores', 'onchain_provider_fallback'), 'pre-ECX: the previous marker is still there');
assert_eq(2, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM invoices")['n']);

// ---- first connection after the deploy: self-heal -------------------------------------------
reset_db_singleton();
Database::getInstance();
assert_true(has_col('stores', 'cashu_max_invoice_sats'), 'Cashu maximum marker re-added');
assert_true(has_col('stores', 'cashu_max_respect_always'), 'Cashu respect-size column re-added');
assert_true(has_col('invoices', 'tip_jar_id'), 'tip jar marker re-added');
assert_true(has_table('tip_jars'), 'tip_jars table re-added');
foreach ($ECX_STORE_COLS as $c) {
    assert_true(has_col('stores', $c), "stores.{$c} re-added");
}
foreach ($ECX_INVOICE_COLS as $c) {
    assert_true(has_col('invoices', $c), "invoices.{$c} re-added");
}
assert_true(has_table('ecx_payments'), 'ecx_payments re-created');
assert_true(has_table('ecx_fee_credits'), 'ecx_fee_credits re-created');
assert_true(has_index('idx_invoices_ecx_address'), 'partial index re-created');
assert_true(has_index('idx_ecx_payments_invoice'));
assert_true(has_index('idx_ecx_fee_credits_store_note'));

// Existing rows intact, ECX defaults applied.
$store = Config::getStore('legacy');
assert_eq($merchant, $store['onchain_xpub'], 'bitcoin xpub survived');
assert_eq(2, (int)$store['onchain_min_confs']);
assert_eq(7200, (int)$store['onchain_confirm_timeout_sec']);
assert_eq(1.5, (float)$store['hosting_fee_percent']);
assert_eq(1, (int)$store['ecx_offer_enabled'], 'default: offer on (but nothing configured)');
assert_eq('xpub', $store['ecx_address_mode']);
assert_null($store['ecx_xpub']);
assert_null($store['ecx_static_address']);
assert_eq(1000, (int)$store['ecx_static_tweak_range']);
assert_eq('P2WPKH', $store['ecx_address_type']);
assert_eq(1, (int)$store['ecx_min_confs']);
assert_eq(86400, (int)$store['ecx_confirm_timeout_sec']);
assert_null($store['ecx_provider_url']);
assert_eq(0, (int)$store['ecx_provider_fallback']);
assert_eq(0, (int)$store['ecx_next_index']);
assert_null($store['hosting_fee_ecx_xpub']);
assert_eq('P2WPKH', $store['hosting_fee_ecx_address_type']);
assert_false(EcxConfig::isConfigured($store), 'existing store: eCash not configured');
assert_false(EcxConfig::isOffered($store));

$paid = Invoice::getById('old_paid');
assert_eq('Settled', $paid['status']);
assert_eq('onchain', $paid['settled_rail']);
assert_eq(100000, (int)$paid['onchain_amount_sat']);
assert_eq(900000, (int)$paid['onchain_created_tip_height']);
assert_null($paid['ecx_address'], 'no eCash leg on an old invoice');
assert_null($paid['ecx_amount_szat']);
assert_eq(0, (int)$paid['ecx_needs_manual_confirmation'], 'NOT NULL DEFAULT 0 applied to existing rows');
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM onchain_payments WHERE invoice_id = 'old_paid'")['n']);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM melts")['n']);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM webhooks")['n']);
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_payments")['n']);
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_fee_credits")['n']);

// Fee math and stats unchanged by the migration.
assert_eq($owedBefore['revenue'], DevFee::computeOwed('legacy')['revenue'], 'sats revenue unchanged');
assert_eq($owedBefore['dev_owed'], DevFee::computeOwed('legacy')['dev_owed']);
$ecxOwed = EcxFees::computeOwed('legacy');
assert_eq(0, $ecxOwed['revenue_szats']);
assert_eq(0, $ecxOwed['dev_owed'] + $ecxOwed['hosting_owed']);
$stats = Stats::summary('legacy', 'all');
assert_eq($statsBefore['revenue_sats'], $stats['revenue_sats']);
assert_eq($statsBefore['onchain_count'], $stats['onchain_count']);
assert_eq(0, $stats['ecx']['revenue_szats']);
assert_eq(0, $stats['ecx']['count']);
assert_eq($stats['revenue_sats'], $stats['revenue_total_sats'], 'nothing folded in');
assert_false($stats['ecx']['price_available'], 'no ECX store -> the price is not even looked up');

// The Bitcoin poller still watches the open invoice; the ECX poller has nothing to do.
$fake = new class implements BlockchainProvider {
    public array $asked = [];
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { $this->asked[] = $address; return []; }
    public function currentTipHeight(): int { return 900100; }
};
OnchainProviderFactory::$testProvider = $fake;
$btcRun = OnchainPayments::pollPending(60, 20);
assert_eq(['old_open'], array_keys($btcRun));
assert_eq(['bc1qoldopen'], $fake->asked);
$fake->asked = [];
assert_eq([], OnchainPayments::pollPending(60, 20, Chain::ecx()), 'no invoice carries an eCash address');
assert_eq([], $fake->asked, 'the ECX explorer is never contacted');

// New invoices on the upgraded store: Bitcoin leg as before, no eCash leg, no ECX attempt row.
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled');
$inv = Invoice::create('legacy', ['amount' => 2000, 'currency' => 'sat']);
assert_eq('onchain', $inv['payment_rail']);
assert_not_null($inv['onchain_address']);
assert_null($inv['ecx_address']);
assert_null($inv['ecx_amount_szat']);
$api = Invoice::formatForApi($inv);
assert_true(isset($api['checkout']['paymentMethods']['BTC-OnChain']));
assert_false(isset($api['checkout']['paymentMethods']['ECX-OnChain']), 'no ECX method');
assert_false(isset($api['ecx']), 'no ecx block');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts WHERE invoice_id = ? AND rail = 'ecx'", [$inv['id']])['n'],
    'an unconfigured rail is not even attempted');

// Running the migration a second time is a no-op (idempotent).
reset_db_singleton();
Database::getInstance();
assert_eq(3, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM invoices")['n']);
assert_true(has_index('idx_invoices_ecx_address'));

OnchainProviderFactory::$testProvider = null;
echo "ok\n";
