<?php
/**
 * Invoice::create with the eCash (ECX) rail: priced in szats through
 * EcxRates, allocated on the shared per-xpub counter, offered only with a
 * price and when switched on, redirected to the ECX fee ledger's payee when a
 * fee owed in ECX covers the invoice, and re-quoted on long-lived requests.
 * No network: scripted BTC price, scripted ECX price, scripted chain tip.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice_attempts.php';

final class StubPriceProvider implements PriceProvider {
    public $behavior;
    public function __construct(callable $behavior) { $this->behavior = $behavior; }
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return ($this->behavior)($currency); }
}
$price = new StubPriceProvider(fn() => 100000.0);
ExchangeRates::$testProviders = ['stub' => $price];
EcxRates::$testEcxUsd = 40.0; // 1 BTC = 2500 ECX
function rate_cache_clear(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'rate_%'");
    Config::clearCache();
}
$fake = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { return 984800; }
};
OnchainProviderFactory::$testProvider = $fake;
putenv('CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled');
Config::set('fee_tracking_start_at', 0);

$zpub = 'zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA';
$merchant = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';

function attempts(string $invoiceId): array {
    return Database::fetchAll("SELECT rail, label, outcome, reason FROM invoice_rail_attempts WHERE invoice_id = ? ORDER BY id", [$invoiceId]);
}

// ---- 1. dual-rail store: bitcoin xpub + the same xpub for eCash -----------------
make_store('dual');
Database::update('stores', [
    'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub',
    'onchain_xpub' => $merchant, 'onchain_address_type' => 'P2WPKH', 'onchain_network' => 'mainnet',
    'ecx_xpub' => $merchant, 'ecx_address_type' => 'P2WPKH', 'ecx_min_confs' => 1,
], 'id = ?', ['dual']);
$inv = Invoice::create('dual', ['amount' => '10.00', 'currency' => 'USD']);
assert_eq('onchain', $inv['payment_rail'], 'bitcoin address rail (no lightning)');
assert_eq(10000, (int)$inv['onchain_amount_sat'], '$10 at 100k = 10,000 sats');
assert_eq(25000000, (int)$inv['ecx_amount_szat'], '$10 at $40 = 0.25 ECX = 25,000,000 szats');
assert_eq(40.0, (float)$inv['ecx_exchange_rate'], 'ECX price in the invoice currency');
assert_not_null($inv['ecx_address']);
assert_neq($inv['onchain_address'], $inv['ecx_address'], 'different addresses on the shared key');
assert_eq(0, (int)$inv['onchain_address_index']);
assert_eq(1, (int)$inv['ecx_address_index'], 'ecx continues the shared counter');
assert_eq(984800, (int)$inv['ecx_created_tip_height'], 'ECX tip stamped at allocation');
assert_null($inv['ecx_fee_note'], 'merchant address (nothing owed yet)');
assert_null($inv['ecx_amount_tweak_szats']);
$api = Invoice::formatForApi($inv);
assert_eq('ecash:' . $inv['ecx_address'] . '?amount=0.25', $api['checkout']['paymentMethods']['ECX-OnChain']['paymentLink']);
assert_true(isset($api['checkout']['paymentMethods']['BTC-OnChain']));
$at = attempts($inv['id']);
$ecxRows = array_values(array_filter($at, fn($a) => $a['rail'] === 'ecx'));
assert_eq(1, count($ecxRows), 'one eCash attempt row');
assert_eq('ok', $ecxRows[0]['outcome']);
assert_eq('local address', $ecxRows[0]['label']);

// A sats-denominated invoice goes through the BTC:ECX ratio.
$inv2 = Invoice::create('dual', ['amount' => 20000, 'currency' => 'sat']);
assert_eq(50000000, (int)$inv2['ecx_amount_szat'], '20,000 sats = 0.0002 BTC = 0.5 ECX');
assert_eq(2, (int)$inv2['onchain_address_index']);
assert_eq(3, (int)$inv2['ecx_address_index']);

// ---- 2. no ECX price -> eCash left off, invoice still created ----------------------
EcxRates::$testEcxUsd = 0.0;
$inv3 = Invoice::create('dual', ['amount' => '5.00', 'currency' => 'USD']);
assert_null($inv3['ecx_address'], 'no price -> no eCash address');
assert_null($inv3['ecx_amount_szat']);
assert_not_null($inv3['onchain_address'], 'bitcoin leg unaffected');
$skipped = array_values(array_filter(attempts($inv3['id']), fn($a) => $a['rail'] === 'ecx'));
assert_eq(1, count($skipped));
assert_eq('skipped', $skipped[0]['outcome']);
assert_eq('no eCash (ECX) price was available', $skipped[0]['reason']);
assert_false(isset(Invoice::formatForApi($inv3)['checkout']['paymentMethods']['ECX-OnChain']));
EcxRates::$testEcxUsd = 40.0;

// ---- 3. offer toggle off -> no eCash leg, settings kept ------------------------------
Database::update('stores', ['ecx_offer_enabled' => 0], 'id = ?', ['dual']);
$inv4 = Invoice::create('dual', ['amount' => '5.00', 'currency' => 'USD']);
assert_null($inv4['ecx_address'], 'switched off -> not offered');
assert_eq([], array_filter(attempts($inv4['id']), fn($a) => $a['rail'] === 'ecx'), 'no attempt row when switched off');
Database::update('stores', ['ecx_offer_enabled' => 1], 'id = ?', ['dual']);

// ---- 4. eCash-only store -------------------------------------------------------------
make_store('ecxonly');
Database::update('stores', [
    'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub',
    'ecx_xpub' => $merchant, 'ecx_address_type' => 'P2WPKH',
], 'id = ?', ['ecxonly']);
$inv5 = Invoice::create('ecxonly', ['amount' => '4.00', 'currency' => 'USD']);
assert_eq('ecx', $inv5['payment_rail'], 'eCash is the only payable rail');
assert_null($inv5['bolt11']);
assert_null($inv5['onchain_address']);
assert_eq(10000000, (int)$inv5['ecx_amount_szat']);
assert_eq('ecx', Invoice::formatForApi($inv5)['paymentRail']);
// ... and with the price gone it refuses rather than issuing an unpayable invoice.
EcxRates::$testEcxUsd = 0.0;
$threw = '';
try { Invoice::create('ecxonly', ['amount' => '4.00', 'currency' => 'USD']); } catch (Throwable $e) { $threw = $e->getMessage(); }
assert_true(str_contains($threw, 'no payment rail is available'), 'eCash-only store without a price refuses: ' . $threw);
EcxRates::$testEcxUsd = 40.0;

// ---- 5. ECX fee redirect inside create ------------------------------------------------
// 10 ECX of eCash revenue -> 1% dev fee = 0.1 ECX = 10,000,000 szats owed.
Database::insert('invoices', [
    'id' => 'paid_ecx', 'store_id' => 'dual', 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '400', 'currency' => 'USD', 'amount_sats' => 400000,
    'ecx_address' => 'bc1qpaidecx', 'ecx_amount_szat' => 1000000000,
    'created_at' => time(), 'expiration_time' => time() + 3600, 'paid_at' => time(),
]);
assert_eq(10000000, EcxFees::computeOwed('dual')['dev_owed']);
Config::set(FeeBranch::CONFIG_BRANCH, 5);
$devNet = (string)CASHUPAY_DEV_FEE_ONCHAIN_NETWORK;
$inv6 = Invoice::create('dual', ['amount' => '2.00', 'currency' => 'USD']); // 0.05 ECX = 5,000,000 szats < owed
if ($devNet === 'mainnet') {
    assert_eq(FEE_NOTE_DEV, $inv6['ecx_fee_note'], 'dev fee claims the eCash address');
    assert_eq($inv6['ecx_address'], $inv6['ecx_fee_destination']);
    assert_eq(OnchainWallet::deriveAddress((string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB, (string)CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE, 'mainnet', 0, 5),
        $inv6['ecx_address'], 'derived under the BareBits zpub / install branch');
    assert_eq(5000000, (int)$inv6['ecx_amount_szat'], 'exact amount, no tweak');
    assert_null($inv6['fee_redirect_rails'], 'the Bitcoin rails stay with the merchant (sats ledger owes little)');
    assert_true(str_starts_with((string)$inv6['onchain_address'], 'bc1q'), 'merchant bitcoin address still issued');
    $feeRows = array_values(array_filter(attempts($inv6['id']), fn($a) => $a['rail'] === 'fee'));
    assert_eq(1, count($feeRows), 'fee routing logged');
    $apiFee = Invoice::formatForApi($inv6);
    assert_eq('dev fee', $apiFee['ecx']['feeLabel']);
    assert_false(isset($apiFee['feeRedirect']), 'the Bitcoin fee badge is not raised by an ECX redirect');
    // Settling over the eCash address credits the ECX ledger, not melts.
    assert_true(Invoice::updateStatus($inv6['id'], 'Settled', null, 'ecx'));
    assert_eq(5000000, EcxFees::computeOwed('dual')['dev_paid']);
    assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM melts")['n']);
    // The next invoice (owed now 5,050,000 szats for 10.05 ECX revenue) above the gate -> merchant.
    $inv7 = Invoice::create('dual', ['amount' => '3.00', 'currency' => 'USD']); // 7,500,000 szats > owed
    assert_null($inv7['ecx_fee_note'], 'gate: owed < invoice -> merchant address');
} else {
    assert_null($inv6['ecx_fee_note'], 'a non-mainnet dev xpub never takes an ECX address');
}
Config::set(FeeBranch::CONFIG_BRANCH, null);

// ---- 6. long-lived re-quote moves the szat amount and keeps the floor ----------------
$ll = Invoice::create('dual', ['amount' => '10.00', 'currency' => 'USD', 'long_lived' => true]);
assert_eq(25000000, (int)$ll['ecx_amount_szat']);
EcxRates::$testEcxUsd = 80.0; // ECX doubled: $10 is now 0.125 ECX
Database::update('invoices', ['requoted_at' => null], 'id = ?', [$ll['id']]);
rate_cache_clear();
$res = Invoice::requoteForView(Invoice::getById($ll['id']));
assert_true($res['requoted'], 're-quoted');
$ll2 = Invoice::getById($ll['id']);
assert_eq(12500000, (int)$ll2['ecx_amount_szat'], 'szat amount re-quoted at the new ECX price');
assert_eq(12500000, (int)$ll2['ecx_amount_floor_szat'], 'floor = smallest amount ever shown');
assert_eq(80.0, (float)$ll2['ecx_exchange_rate']);
// Price back down: amount rises, the floor stays at the smaller figure.
EcxRates::$testEcxUsd = 40.0;
Database::update('invoices', ['requoted_at' => null], 'id = ?', [$ll['id']]);
rate_cache_clear();
Invoice::requoteForView(Invoice::getById($ll['id']));
$ll3 = Invoice::getById($ll['id']);
assert_eq(25000000, (int)$ll3['ecx_amount_szat']);
assert_eq(12500000, (int)$ll3['ecx_amount_floor_szat'], 'floor keeps the smallest amount shown');
// Once an ECX payment was seen the amount is locked.
Database::update('invoices', ['ecx_first_seen_at' => time(), 'requoted_at' => null], 'id = ?', [$ll['id']]);
EcxRates::$testEcxUsd = 80.0;
rate_cache_clear();
Invoice::requoteForView(Invoice::getById($ll['id']));
assert_eq(25000000, (int)Invoice::getById($ll['id'])['ecx_amount_szat'], 'seen -> locked');

ExchangeRates::$testProviders = null;
EcxRates::$testEcxUsd = null;
OnchainProviderFactory::$testProvider = null;
echo "ok\n";
