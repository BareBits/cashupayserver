<?php
/**
 * Hosting fee owed in ECX, end to end inside Invoice::create and
 * Invoice::updateStatus: once the hosting fee accrued on eCash revenue covers
 * a new invoice's szat amount, that invoice's eCash address is derived from
 * the store's hosting_fee_ecx_xpub (the dev fee, owed less, loses the
 * largest-owed-first race); the Bitcoin leg of the same invoice stays with
 * the merchant; settling over the fee-routed eCash address writes exactly one
 * ecx_fee_credits row, still fires the InvoiceSettled webhook (the customer
 * did pay) but suppresses the merchant "you were paid" notification; a
 * settlement of a fee-routed invoice over its Bitcoin leg is a normal
 * merchant payment (credit NOT written, notification sent); and the hosting
 * payee never takes an address without its ECX xpub.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true); // keep WebhookSender::fireEvent hermetic (no self-request)
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';

final class StubPriceProvider implements PriceProvider {
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return 100000.0; }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider()];
EcxRates::$testEcxUsd = 40.0;
$fake = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { return 984800; }
};
OnchainProviderFactory::$testProvider = $fake;
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled');
Config::set('fee_tracking_start_at', 0);
Config::set(FeeBranch::CONFIG_BRANCH, null); // dev fee has no branch -> can never take the address here
Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', true);
Config::set('notifications_to_email', 'merchant@example.com');

$merchant = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';
$hostXpub = 'zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA';
make_store('s1');
Database::update('stores', [
    'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub',
    'onchain_xpub' => $merchant, 'onchain_address_type' => 'P2WPKH', 'onchain_network' => 'mainnet',
    'ecx_xpub' => $merchant, 'ecx_address_type' => 'P2WPKH',
    'hosting_fee_percent' => 5.0, 'notifications_enabled' => 1,
], 'id = ?', ['s1']);
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's1', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'shh',
    'events' => json_encode([]), 'enabled' => 1, 'created_at' => time(),
]);

// 20 ECX of eCash revenue -> hosting 5% = 1 ECX = 100,000,000 szats owed; dev 1% = 0.2 ECX.
Database::insert('invoices', [
    'id' => 'paid_ecx', 'store_id' => 's1', 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '800', 'currency' => 'USD', 'amount_sats' => 800000,
    'ecx_address' => 'bc1qpaid', 'ecx_amount_szat' => 2000000000,
    'created_at' => time(), 'expiration_time' => time() + 3600, 'paid_at' => time(),
]);
$owed = EcxFees::computeOwed('s1');
assert_eq(100000000, $owed['hosting_owed']);
assert_eq(20000000, $owed['dev_owed']);

// ---- 1. no hosting ECX xpub: the merchant keeps the address ---------------------------
$inv0 = Invoice::create('s1', ['amount' => '20.00', 'currency' => 'USD']); // 0.5 ECX = 50,000,000 szats < owed
assert_null($inv0['ecx_fee_note'], 'hosting owed enough but has no ECX destination -> merchant address');
assert_eq(OnchainWallet::deriveAddress($merchant, 'P2WPKH', 'mainnet', (int)$inv0['ecx_address_index']), $inv0['ecx_address']);

// ---- 2. with the hosting ECX xpub: the eCash address goes to the hosting payee ---------
Database::update('stores', ['hosting_fee_ecx_xpub' => $hostXpub, 'hosting_fee_ecx_address_type' => 'P2WPKH'], 'id = ?', ['s1']);
$inv = Invoice::create('s1', ['amount' => '20.00', 'currency' => 'USD']);
assert_eq(FEE_NOTE_HOSTING, $inv['ecx_fee_note'], 'hosting fee claims the eCash address');
assert_eq(OnchainWallet::deriveAddress($hostXpub, 'P2WPKH', 'mainnet', 0), $inv['ecx_address'], 'derived from the hosting ECX xpub, index 0');
assert_eq($inv['ecx_address'], $inv['ecx_fee_destination']);
assert_eq(50000000, (int)$inv['ecx_amount_szat']);
assert_eq(984800, (int)$inv['ecx_created_tip_height'], 'tip stamped through the ECX explorer');
// The Bitcoin leg is untouched by the ECX fee decision.
assert_null($inv['fee_redirect_note'], 'bitcoin rails stay with the merchant');
assert_eq(OnchainWallet::deriveAddress($merchant, 'P2WPKH', 'mainnet', (int)$inv['onchain_address_index']), $inv['onchain_address']);
$api = Invoice::formatForApi($inv);
assert_eq('hosting fee', $api['ecx']['feeLabel']);
assert_eq('HOSTING_FEE', $api['ecx']['feeNote']);
assert_false($api['ecx']['settledToFee']);
assert_false(isset($api['feeRedirect']), 'no Bitcoin fee badge');
$fee = Database::fetchAll("SELECT * FROM invoice_rail_attempts WHERE invoice_id = ? AND rail = 'fee'", [$inv['id']]);
assert_eq(1, count($fee), 'fee routing logged once');
assert_true(str_contains((string)$fee[0]['detail'], 'HOSTING_FEE'), 'attempt detail names the fee: ' . $fee[0]['detail']);

// A second fee-routed invoice continues the hosting xpub's own counter.
$inv2 = Invoice::create('s1', ['amount' => '20.00', 'currency' => 'USD']);
assert_eq(FEE_NOTE_HOSTING, $inv2['ecx_fee_note']);
assert_eq(OnchainWallet::deriveAddress($hostXpub, 'P2WPKH', 'mainnet', 1), $inv2['ecx_address'], 'hosting xpub counter advanced to 1');

// ---- 3. settle the first over its eCash address: credit once, webhook yes, email no ----
Database::query("DELETE FROM notification_queue");
Database::query("DELETE FROM webhook_deliveries");
assert_true(Invoice::updateStatus($inv['id'], 'Settled', null, 'ecx'));
$credits = Database::fetchAll("SELECT * FROM ecx_fee_credits WHERE invoice_id = ?", [$inv['id']]);
assert_eq(1, count($credits), 'one credit row');
assert_eq(FEE_NOTE_HOSTING, $credits[0]['note']);
assert_eq(50000000, (int)$credits[0]['amount_szat']);
assert_eq($inv['ecx_address'], $credits[0]['destination']);
$after = EcxFees::computeOwed('s1');
assert_eq(50000000, $after['hosting_paid']);
// revenue grew by 50,000,000 (5% = 2,500,000); owed = 102,500,000 - 50,000,000.
assert_eq(52500000, $after['hosting_owed']);
$wh = Database::fetchAll("SELECT event_type FROM webhook_deliveries WHERE invoice_id = ?", [$inv['id']]);
assert_eq(['InvoiceSettled'], array_column($wh, 'event_type'), 'the shop still learns the invoice was paid');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM notification_queue WHERE event_type = 'InvoicePaid'")['n'],
    'merchant was not paid -> no "invoice paid" email');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM melts")['n'], 'nothing on the sats ledger');
assert_true(Invoice::formatForApi(Invoice::getById($inv['id']))['ecx']['settledToFee']);
// Settling again (poller race) is a no-op for the ledger.
EcxFees::recordCredit(Invoice::getById($inv['id']));
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_fee_credits")['n'], 'idempotent credit');

// ---- 4. the second fee-routed invoice is paid over its BITCOIN leg instead ----------------
Database::query("DELETE FROM notification_queue");
Database::query("DELETE FROM webhook_deliveries");
assert_true(Invoice::updateStatus($inv2['id'], 'Settled', null, 'onchain'));
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_fee_credits")['n'],
    'a bitcoin settlement of a fee-routed eCash address is not an ECX fee payment');
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM notification_queue WHERE event_type = 'InvoicePaid'")['n'],
    'the merchant really was paid -> notified');
assert_false(Invoice::formatForApi(Invoice::getById($inv2['id']))['ecx']['settledToFee']);
assert_eq(52500000, EcxFees::computeOwed('s1')['hosting_owed'], 'the bitcoin settlement is not eCash revenue either');
assert_eq(2050000000, EcxFees::computeOwed('s1')['revenue_szats'], 'ECX revenue: the seeded 20 ECX + the one fee-routed eCash settlement only');

// ---- 5. gate: an invoice bigger than what is owed stays with the merchant --------------------
$big = Invoice::create('s1', ['amount' => '40.00', 'currency' => 'USD']); // 1 ECX = 100,000,000 > 52,500,000 owed
assert_null($big['ecx_fee_note'], 'owed < invoice -> merchant address');
assert_eq(OnchainWallet::deriveAddress($merchant, 'P2WPKH', 'mainnet', (int)$big['ecx_address_index']), $big['ecx_address']);

ExchangeRates::$testProviders = null;
EcxRates::$testEcxUsd = null;
OnchainProviderFactory::$testProvider = null;
echo "ok\n";
