<?php
/**
 * ECX addresses are Bitcoin addresses. With the same xpub on both rails the
 * per-xpub counter is shared, so the Bitcoin and ECX allocations never hand
 * out the same address; the ECX rail's own columns (ecx_next_index, static
 * tweak slots on ecx_address / ecx_amount_szat) are the ones it touches.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';

$zpub = 'zpub6mg9NbdenqrqfD2BbNqa4EdKS8z2yEBxH2PC4NXmXwnTDfukw3w7JLVMeb81xc9i1N8b7ReB9ia4wLoUyLVrVxaPQbJidU8Yn6Gjd95kQmA';
make_store('s1');
Database::update('stores', [
    'onchain_xpub' => $zpub, 'onchain_address_type' => 'P2WPKH', 'onchain_network' => 'mainnet',
    'ecx_xpub' => $zpub, 'ecx_address_type' => 'P2WPKH',
], 'id = ?', ['s1']);

// No network: tip reads are best-effort and must not matter here.
$fake = new class implements BlockchainProvider {
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return []; }
    public function currentTipHeight(): int { return 984770; }
};
OnchainProviderFactory::$testProvider = $fake;

$btc = OnchainPayments::allocateAddress('s1', 1000);
$ecx = OnchainPayments::allocateAddress('s1', 1000, Chain::ecx());
$btc2 = OnchainPayments::allocateAddress('s1', 1000);
assert_eq(0, $btc['index'], 'bitcoin takes index 0');
assert_eq(1, $ecx['index'], 'ecx continues the SAME counter (index 1)');
assert_eq(2, $btc2['index'], 'bitcoin continues after ecx (index 2)');
assert_neq($btc['address'], $ecx['address'], 'no address is shown for both chains');
assert_eq(OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 1), $ecx['address'], 'ecx address = m/0/1 of the shared key');
assert_eq(984770, $ecx['tip_height'], 'ecx allocation stamps the chain tip');
$row = Database::fetchOne("SELECT onchain_next_index, ecx_next_index FROM stores WHERE id = 's1'");
assert_eq(3, (int)$row['onchain_next_index'], 'bitcoin mirror column reflects the last bitcoin allocation');
assert_eq(2, (int)$row['ecx_next_index'], 'ecx mirror column reflects the last ecx allocation');

// A different ECX xpub has its own counter.
$other = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz';
Database::update('stores', ['ecx_xpub' => $other], 'id = ?', ['s1']);
$e2 = OnchainPayments::allocateAddress('s1', 1000, Chain::ecx());
assert_eq(0, $e2['index'], 'a separate ecx xpub starts at 0');

// Fee addresses (dev zpub under a branch) share the counter with the Bitcoin
// fee address stream for the same branch.
$f1 = OnchainPayments::allocateFeeAddress($zpub, 'mainnet', 'P2WPKH', null, 7);
$f2 = OnchainPayments::allocateFeeAddress($zpub, 'mainnet', 'P2WPKH', null, 7, Chain::ecx());
assert_eq(0, $f1['index']);
assert_eq(1, $f2['index'], 'ecx fee address continues the branch counter');
assert_eq(OnchainWallet::deriveAddress($zpub, 'P2WPKH', 'mainnet', 1, 7), $f2['address']);

// ---- static mode on the ECX rail uses the ecx_* columns -----------------------
make_store('s2');
Database::update('stores', [
    'ecx_address_mode' => 'static', 'ecx_static_address' => 'bc1qstaticecx', 'ecx_static_tweak_range' => 3,
    'onchain_address_mode' => 'static', 'onchain_static_address' => 'bc1qstaticecx', 'onchain_static_tweak_range' => 1000,
], 'id = ?', ['s2']);
$a = OnchainPayments::allocateAddress('s2', 5000, Chain::ecx());
assert_eq('bc1qstaticecx', $a['address']);
assert_eq(0, $a['tweak'], 'first ecx slot');
// An open ECX invoice at 5000 reserves slot 0 on the ECX rail only.
Database::insert('invoices', [
    'id' => 'e1', 'store_id' => 's2', 'status' => 'New', 'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1,
    'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 5000,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
assert_eq(1, OnchainPayments::allocateAddress('s2', 5000, Chain::ecx())['tweak'], 'next ecx slot');
assert_eq(0, OnchainPayments::allocateAddress('s2', 5000)['tweak'], 'the bitcoin rail does not see ecx slots');
Database::insert('invoices', [
    'id' => 'e2', 'store_id' => 's2', 'status' => 'New', 'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1,
    'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 5001,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
Database::insert('invoices', [
    'id' => 'e3', 'store_id' => 's2', 'status' => 'New', 'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1,
    'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 5002,
    'created_at' => time(), 'expiration_time' => time() + 3600,
]);
$threw = null;
try { OnchainPayments::allocateAddress('s2', 5000, Chain::ecx()); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
assert_eq(OnchainPayments::ERR_TWEAK_SLOTS_EXHAUSTED, $threw, 'ecx slots exhausted');

// EcxConfig reads the ECX columns.
$s1 = Config::getStore('s1');
assert_true(EcxConfig::isConfigured($s1));
assert_true(EcxConfig::isOffered($s1));
Database::update('stores', ['ecx_offer_enabled' => 0], 'id = ?', ['s1']);
assert_false(EcxConfig::isOffered(Config::getStore('s1')), 'offer toggle off hides the rail');
assert_true(EcxConfig::isConfigured(Config::getStore('s2')), 'static address counts as configured');
make_store('s3');
assert_false(EcxConfig::isConfigured(Config::getStore('s3')));

OnchainProviderFactory::$testProvider = null;
echo "ok\n";
