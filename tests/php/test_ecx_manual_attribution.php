<?php
/**
 * Static-address ambiguity on the eCash (ECX) rail: two open invoices with
 * the same szat total at one static ECX address do not auto-settle when a
 * matching payment appears; both are flagged on the ECX columns
 * (ecx_needs_manual_confirmation / ecx_manual_candidates) with the Bitcoin
 * columns untouched; the per-chain manual-confirmation list and count report
 * them under chain 'ecx'; OnchainPayments::manuallyAttribute(..., Chain::ecx())
 * settles the chosen invoice over the 'ecx' rail into ecx_payments, clears
 * the candidate from the sibling (which stays open) and refuses a second
 * attribution of the same output.
 *
 * Replay edge: ECX is a Bitcoin fork, so a transaction can exist on both
 * chains with the SAME txid. A Bitcoin observation of that txid:vout
 * (onchain_payments) neither blocks the ECX attribution (separate tables) nor
 * loses its own Bitcoin-side candidate when the ECX side is resolved
 * (per-chain scrub).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$now = time();
$T = str_repeat('a', 63) . '1';
make_store('s1');
Database::update('stores', [
    'ecx_address_mode' => 'static', 'ecx_static_address' => 'bc1qstaticecx', 'ecx_static_tweak_range' => 1000,
    'ecx_min_confs' => 1, 'ecx_confirm_timeout_sec' => 86400,
    // The same string is also this store's static Bitcoin address (replay scenario).
    'onchain_address_mode' => 'static', 'onchain_static_address' => 'bc1qstaticecx', 'onchain_min_confs' => 1,
], 'id = ?', ['s1']);

$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs[$address] ?? []; }
    public function currentTipHeight(): int { return 984800; }
};
OnchainProviderFactory::$testProvider = $fake;

// Two ECX invoices with the SAME total at the static address (forced; the
// allocator never produces this on its own).
foreach (['e1', 'e2'] as $id) {
    Database::insert('invoices', [
        'id' => $id, 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'ecx',
        'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
        'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 25000000, 'ecx_amount_tweak_szats' => 0,
        'ecx_created_tip_height' => 984790,
        'created_at' => $now, 'expiration_time' => $now + 3600,
    ]);
}
// A Bitcoin invoice on the same static address expecting the same number of
// base units: a replayed tx would be ambiguous there too.
Database::insert('invoices', [
    'id' => 'b1', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'onchain',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 25000000,
    'onchain_address' => 'bc1qstaticecx', 'onchain_amount_sat' => 25000000, 'onchain_created_tip_height' => 900000,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
Database::insert('invoices', [
    'id' => 'b2', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'onchain',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 25000000,
    'onchain_address' => 'bc1qstaticecx', 'onchain_amount_sat' => 25000000, 'onchain_created_tip_height' => 900000,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);

// ---- the ECX chain sees the payment: ambiguity, no settlement -----------------------------
$fake->obs['bc1qstaticecx'] = [new OnchainTxObservation($T, 0, 25000000, 1, 984800)];
$r1 = OnchainPayments::pollInvoice('e1', Chain::ecx());
$r2 = OnchainPayments::pollInvoice('e2', Chain::ecx());
assert_eq('New', $r1['status']);
assert_eq('New', $r2['status']);
foreach (['e1', 'e2'] as $id) {
    $row = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]);
    assert_eq(1, (int)$row['ecx_needs_manual_confirmation'], "{$id} flagged on the ECX column");
    $c = json_decode((string)$row['ecx_manual_candidates'], true);
    assert_eq(1, count($c));
    assert_eq($T, $c[0]['txid']);
    assert_eq(0, (int)$c[0]['vout']);
    assert_eq(25000000, (int)$c[0]['amount_sat']);
    assert_eq(0, (int)$row['onchain_needs_manual_confirmation'], "{$id}: bitcoin flag untouched");
    assert_null($row['onchain_manual_candidates']);
    assert_null($row['ecx_first_seen_at'], 'an unattributed candidate is not a sighting');
}
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_payments")['n'], 'nothing attributed yet');

// Per-chain list / count.
assert_eq(2, OnchainPayments::countNeedingManualConfirmation('s1', Chain::ecx()));
assert_eq(0, OnchainPayments::countNeedingManualConfirmation('s1'), 'bitcoin count unaffected');
$list = OnchainPayments::listNeedingManualConfirmation(['s1'], Chain::ecx());
$ids = array_column($list, 'id'); sort($ids);
assert_eq(['e1', 'e2'], $ids);
assert_eq('ecx', $list[0]['chain']);
assert_eq($T, $list[0]['onchain_manual_candidates_decoded'][0]['txid'], 'decoded under the shared key the admin list renders');
assert_eq([], OnchainPayments::listNeedingManualConfirmation(['s1']), 'bitcoin list empty');

// ---- the replayed tx is ALSO seen on the Bitcoin chain: its own ambiguity -----------------
OnchainPayments::pollInvoice('b1');
OnchainPayments::pollInvoice('b2');
assert_eq(2, OnchainPayments::countNeedingManualConfirmation('s1'), 'bitcoin side flagged on its own columns');
assert_eq(2, OnchainPayments::countNeedingManualConfirmation('s1', Chain::ecx()), 'ecx side unchanged by the bitcoin poll');

// ---- resolve on the ECX chain ----------------------------------------------------------------
OnchainPayments::manuallyAttribute('e1', $T, 0, Chain::ecx());
$e1 = Database::fetchOne("SELECT * FROM invoices WHERE id = 'e1'");
assert_eq('Settled', $e1['status'], 'chosen invoice settles from the persisted ECX observation');
assert_eq('ecx', $e1['settled_rail']);
assert_eq(0, (int)$e1['ecx_needs_manual_confirmation']);
assert_null($e1['ecx_manual_candidates']);
$ep = Database::fetchAll("SELECT * FROM ecx_payments");
assert_eq(1, count($ep), 'one ECX observation row');
assert_eq('e1', $ep[0]['invoice_id']);
assert_true(str_starts_with($ep[0]['id'], 'ecxp'), 'ECX observation id prefix: ' . $ep[0]['id']);
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM onchain_payments")['n'], 'nothing in onchain_payments');
$e2 = Database::fetchOne("SELECT * FROM invoices WHERE id = 'e2'");
assert_eq('New', $e2['status'], 'sibling stays open');
assert_eq(0, (int)$e2['ecx_needs_manual_confirmation'], 'sibling lost the candidate');
assert_null($e2['ecx_manual_candidates']);
assert_eq(0, OnchainPayments::countNeedingManualConfirmation('s1', Chain::ecx()));
// The Bitcoin-side candidates for the same txid:vout are NOT scrubbed by an ECX resolution.
assert_eq(2, OnchainPayments::countNeedingManualConfirmation('s1'), 'per-chain scrub leaves the bitcoin candidates');
$b1 = Database::fetchOne("SELECT onchain_manual_candidates FROM invoices WHERE id = 'b1'");
assert_eq($T, json_decode((string)$b1['onchain_manual_candidates'], true)[0]['txid']);

// Double attribution is refused.
$threw = '';
try { OnchainPayments::manuallyAttribute('e2', $T, 0, Chain::ecx()); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
assert_true(str_contains($threw, 'already attributed'), 'same output cannot be given to e2: ' . $threw);
// ... and the Bitcoin side can still attribute ITS copy of the replayed tx (separate table).
OnchainPayments::manuallyAttribute('b1', $T, 0);
assert_eq('Settled', Database::fetchOne("SELECT status FROM invoices WHERE id = 'b1'")['status']);
assert_eq('onchain', Database::fetchOne("SELECT settled_rail FROM invoices WHERE id = 'b1'")['settled_rail']);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM onchain_payments WHERE txid = ?", [$T])['n']);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_payments WHERE txid = ?", [$T])['n'], 'same txid, one row per chain');
assert_eq(0, OnchainPayments::countNeedingManualConfirmation('s1'), 'bitcoin sibling cleared by the bitcoin resolution');

// A later ECX poll of the settled invoice is stable (upsert on the attributed row).
$r = OnchainPayments::pollInvoice('e1', Chain::ecx());
assert_eq('Settled', $r['status']);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_payments")['n']);

// Candidates expire with their invoices: a lapsed sibling drops out of the
// ambiguity set and the remaining open invoice settles on the next poll.
Database::insert('invoices', [
    'id' => 'e3', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'ecx',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
    'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 25000001, 'ecx_created_tip_height' => 984790,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
Database::insert('invoices', [
    'id' => 'e4', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'ecx',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
    'ecx_address' => 'bc1qstaticecx', 'ecx_amount_szat' => 25000001, 'ecx_created_tip_height' => 984790,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
$U = str_repeat('b', 63) . '2';
$fake->obs['bc1qstaticecx'] = [new OnchainTxObservation($U, 0, 25000001, 1, 984801)];
OnchainPayments::pollInvoice('e3', Chain::ecx());
assert_eq(1, (int)Database::fetchOne("SELECT ecx_needs_manual_confirmation FROM invoices WHERE id = 'e3'")['ecx_needs_manual_confirmation']);
// e4 expires (past its ECX confirmation window): no longer a competitor.
Database::update('invoices', ['status' => 'Expired'], 'id = ?', ['e4']);
$r = OnchainPayments::pollInvoice('e3', Chain::ecx());
assert_eq('Settled', $r['status'], 'ambiguity cleared by the sibling expiring');
assert_eq(0, (int)Database::fetchOne("SELECT ecx_needs_manual_confirmation FROM invoices WHERE id = 'e3'")['ecx_needs_manual_confirmation']);

OnchainProviderFactory::$testProvider = null;
echo "ok\n";
