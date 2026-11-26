<?php
/**
 * Cash back rewards on a static shared on-chain / eCash address: the amount
 * moves by taking a fresh tweak while the previous total stays reserved as
 * an "alternate" (operator decision 7), because attribution on a shared
 * address is by exact total and a payer may already have scanned the old QR.
 *
 *   1. Apply: new tweak for the discounted base, old total appended to
 *      onchain_amount_alternates; amount_sats moves (no Lightning rail).
 *   2. The tweak picker treats every open invoice's alternates as used
 *      totals (OnchainPayments::allocateAddress).
 *   3. The poller accepts an observation matching an alternate; a competitor
 *      claiming that total by ITS alternate blocks attribution (manual
 *      candidates) until it is gone; a non-matching amount is ignored.
 *   4. Settlement on the old total runs the rewards hook (commit + NET earn).
 *   5. Remove re-allocates back and keeps the discounted total as an
 *      alternate; a payment of that lower alternate still settles
 *      (applyTransitions threshold).
 *   6. eCash: same mechanics through the Chain descriptor.
 *
 * Driven with the scripted provider seam (OnchainProviderFactory::$testProvider).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards_flow.php';

Config::set('fee_tracking_start_at', time() + 86400 * 365);
$ADDR = 'bcrt1qstaticsharedaddress000000000';
$ECX = 'bcrt1qecxstaticsharedaddress00000';
make_store('s_static', 'http://127.0.0.1:1'); // mint unreachable: these invoices have no Lightning rail
Database::update('stores', [
    'onchain_address_mode' => 'static', 'onchain_static_address' => $ADDR, 'onchain_static_tweak_range' => 1000, 'onchain_min_confs' => 0,
    'ecx_address_mode' => 'static', 'ecx_static_address' => $ECX, 'ecx_static_tweak_range' => 1000, 'ecx_min_confs' => 0,
], 'id = ?', ['s_static']);
Rewards::setStoreSettings('s_static', true, 1.0);

$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return 800000; }
};
OnchainProviderFactory::$testProvider = $fake;

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function alts(string $id, string $col = 'onchain_amount_alternates'): array {
    $v = row($id)[$col];
    return $v === null ? [] : json_decode((string)$v, true);
}
function mk(string $id, int $total, int $tweak, array $extra = []): void {
    global $ADDR;
    $now = time();
    Database::insert('invoices', array_merge([
        'id' => $id, 'store_id' => 's_static', 'status' => 'New', 'additional_status' => 'None',
        'amount' => '10000', 'currency' => 'sat', 'amount_sats' => 10000,
        'onchain_address' => $ADDR, 'onchain_amount_sat' => $total, 'onchain_amount_tweak_sats' => $tweak,
        'created_at' => $now, 'expiration_time' => $now + 3600,
    ], $extra));
}
function obs(string $txid, int $amount): OnchainTxObservation {
    return new OnchainTxObservation(str_repeat($txid, 64), 0, $amount, 0, null, replaceable: false);
}

mk('S1', 10000, 0);
mk('S2', 10001, 1);
$harry = Rewards::getOrCreateAccount('s_static', 'Harry');
Rewards::credit($harry['id'], 'seed_harry', 2000 * Rewards::MICRO, 'sat');

// ---- 1. apply: fresh tweak, old total kept as an alternate ----
$r = RewardsFlow::applyToInvoice('S1', 'Harry');
assert_true($r['ok'], 'apply: ' . json_encode($r));
assert_eq('2000', $r['applied']['discountAmount']);
$s1 = row('S1');
assert_eq(8000, (int)$s1['amount_sats'], 'plain sats figure moved (no bolt11)');
assert_eq(8000, (int)$s1['onchain_amount_sat'], 'new base 8000 + tweak 0 (free)');
assert_eq(0, (int)$s1['onchain_amount_tweak_sats']);
assert_eq([10000], alts('S1'), 'old total reserved as an alternate');
assert_null($s1['onchain_amount_floor_sat'], 'no floor on a static address (exact-amount attribution)');
assert_eq([10000], OnchainPayments::alternatesOf($s1));

// ---- 2. the picker excludes alternates of open invoices ----
$alloc = OnchainPayments::allocateAddress('s_static', 10000);
assert_eq(2, $alloc['tweak'], '10000 (S1 alternate) and 10001 (S2) are taken -> tweak 2');
assert_eq(1, OnchainPayments::allocateAddress('s_static', 8000)['tweak'], '8000 is S1\'s current total -> tweak 1');
assert_eq(0, OnchainPayments::allocateAddress('s_static', 12345)['tweak'], 'free base keeps tweak 0');

// ---- 3. poll: alternates accepted, competitors by alternate block, others ignored ----
$fake->obs = [obs('a', 9999)];
$p = OnchainPayments::pollInvoice('S1');
assert_eq('New', $p['status'], 'a total nobody expects is ignored');
assert_eq(0, $p['observation_count']);
// S4 claims 10000 through its own alternates list -> ambiguity.
mk('S4', 7000, 0, ['onchain_amount_alternates' => json_encode([10000])]);
$fake->obs = [obs('b', 10000)];
$p = OnchainPayments::pollInvoice('S1');
assert_eq('New', $p['status'], 'ambiguous payment is not attributed');
assert_eq(1, (int)row('S1')['onchain_needs_manual_confirmation'], 'S1 flagged for manual confirmation');
assert_eq(1, (int)row('S4')['onchain_needs_manual_confirmation'], 'competitor flagged too');
assert_eq(1, count(json_decode((string)row('S1')['onchain_manual_candidates'], true)));
Database::delete('invoices', 'id = ?', ['S4']);

// ---- 4. the old total settles S1 and runs the rewards hook ----
$p = OnchainPayments::pollInvoice('S1');
assert_eq('Settled', $p['status'], 'old total attributed once unambiguous');
assert_eq(10000, $p['total_confirmed']);
$s1 = row('S1');
assert_eq('Settled', $s1['status']);
assert_eq('onchain', $s1['settled_rail']);
assert_eq('committed', $s1['rewards_spend_state'], 'reservation committed by the hook');
assert_eq(80 * Rewards::MICRO, (int)$s1['rewards_earned_micro'], '1% of the NET 8000 sats');
assert_not_null($s1['rewards_claimed_at']);
$h = Rewards::getAccountById($harry['id']);
assert_eq(80 * Rewards::MICRO, (int)$h['balance_micro'], 'earn auto-credited');
assert_eq(2000 * Rewards::MICRO, (int)$h['spent_micro']);
assert_eq(0, (int)$h['reserved_micro']);
// Settled invoices release their totals from the pool.
assert_eq(0, OnchainPayments::allocateAddress('s_static', 10000)['tweak'], 'S1 settled: 10000 free again');

// ---- 5. remove re-allocates back; the discounted alternate still settles ----
$ivy = Rewards::getOrCreateAccount('s_static', 'Ivy');
Rewards::credit($ivy['id'], 'seed_ivy', 1500 * Rewards::MICRO, 'sat');
$r = RewardsFlow::applyToInvoice('S2', 'Ivy');
assert_true($r['ok'], json_encode($r));
$s2 = row('S2');
assert_eq(8500, (int)$s2['onchain_amount_sat']);
assert_eq([10001], alts('S2'));
assert_true(RewardsFlow::removeFromInvoice('S2')['ok'], 'remove');
$s2 = row('S2');
assert_eq(10000, (int)$s2['amount_sats'], 'sats back to the full amount');
assert_eq(10000, (int)$s2['onchain_amount_sat'], 'base 10000 + tweak 0 (S1 settled, slot free)');
assert_eq(0, (int)$s2['onchain_amount_tweak_sats']);
assert_eq([8500, 10001], alts('S2'), 'both earlier totals stay attributable');
assert_null($s2['rewards_spend_state']);
assert_eq(1500 * Rewards::MICRO, (int)Rewards::getAccountById($ivy['id'])['balance_micro'], 'Ivy\'s balance back');
// A payer who scanned the discounted QR pays 8500: attributed and settled
// (the threshold is the smallest total ever shown, like the floor).
$fake->obs = [obs('c', 8500)];
$p = OnchainPayments::pollInvoice('S2');
assert_eq('Settled', $p['status'], 'the lower alternate settles');
assert_eq(8500, $p['total_confirmed']);
assert_eq('Settled', row('S2')['status']);
assert_null(row('S2')['rewards_spend_state'], 'nothing to commit after the undo');
assert_eq(100 * Rewards::MICRO, (int)row('S2')['rewards_earned_micro'], '1% of the invoice amount (no discount in force)');
assert_null(row('S2')['rewards_claimed_at'], 'no account tied any more: left to be claimed');

// Re-allocation refuses to move once a payment was seen / the invoice closed.
assert_null(OnchainPayments::reallocateStaticAmount('S2', 5000), 'settled invoice is not re-amounted');
mk('S6', 6000, 0, ['onchain_first_seen_at' => time()]);
assert_null(OnchainPayments::reallocateStaticAmount('S6', 5000), 'payment seen: locked');
assert_eq(6000, (int)row('S6')['onchain_amount_sat']);
// Same base as the current total: nothing to move, nothing appended.
mk('S7', 7000, 0);
$same = OnchainPayments::reallocateStaticAmount('S7', 7000);
assert_eq(['amount' => 7000, 'tweak' => 0, 'alternates' => []], $same);
assert_eq([], alts('S7'));

// ---- 6. eCash leg through the Chain descriptor ----
$now = time();
Database::insert('invoices', ['id' => 'E1', 'store_id' => 's_static', 'status' => 'New', 'additional_status' => 'None',
    'amount' => '10000', 'currency' => 'sat', 'amount_sats' => 10000,
    'ecx_address' => $ECX, 'ecx_amount_szat' => 500000, 'ecx_amount_tweak_szats' => 0,
    'created_at' => $now, 'expiration_time' => $now + 3600]);
Database::insert('invoices', ['id' => 'E2', 'store_id' => 's_static', 'status' => 'New', 'additional_status' => 'None',
    'amount' => '10000', 'currency' => 'sat', 'amount_sats' => 10000,
    'ecx_address' => $ECX, 'ecx_amount_szat' => 500001, 'ecx_amount_tweak_szats' => 1,
    'created_at' => $now, 'expiration_time' => $now + 3600]);
$ecx = Chain::ecx();
$res = OnchainPayments::reallocateStaticAmount('E1', 400000, $ecx);
assert_eq(['amount' => 400000, 'tweak' => 0, 'alternates' => [500000]], $res, 'eCash re-allocation');
$e1 = row('E1');
assert_eq(400000, (int)$e1['ecx_amount_szat']);
assert_eq(0, (int)$e1['ecx_amount_tweak_szats']);
assert_eq([500000], alts('E1', 'ecx_amount_alternates'));
assert_eq([], alts('E1'), 'Bitcoin column untouched');
assert_eq(2, OnchainPayments::allocateAddress('s_static', 500000, $ecx)['tweak'], 'eCash picker honours the alternate');
$fake->obs = [obs('d', 500000)];
$p = OnchainPayments::pollInvoice('E1', $ecx);
assert_eq('Settled', $p['status'], 'eCash payment of the old total settles');
assert_eq('ecx', row('E1')['settled_rail']);

echo "test_rewards_static_alternates: ok\n";
