<?php
/**
 * Diagnostic report with the eCash (ECX) rail: the ecx_payments and
 * ecx_fee_credits sections are exported, invoice rows carry the ECX leg's
 * non-identifying columns, the aggregates carry the szat-side ledger, and
 * anonymization drops the ECX address, fee destination, manual candidates and
 * txids while the de-anonymized report keeps them. Surrogate invoice ids are
 * shared across every section so the rows still join up.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/diagnostics.php';

$store = 's_ecx_diag';
make_store($store, 'https://mint.example.com');
$now = time();

$INV_ID   = 'real-ecx-invoice-ABCDEF';
$ECX_ADDR = 'bc1qsecretecxaddressxxxxxxxxxxxxxxxxxxxxxxx';
$FEE_DEST = 'bc1qsecretecxfeedestxxxxxxxxxxxxxxxxxxxxxx';
$TXID     = str_repeat('e', 63) . '7';
$BTC_TXID = str_repeat('b', 63) . '7';
$CAND_TX  = str_repeat('c', 63) . '7';

Database::insert('invoices', [
    'id' => $INV_ID, 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
    'ecx_address' => $ECX_ADDR, 'ecx_address_index' => 3, 'ecx_amount_szat' => 25000000,
    'ecx_amount_floor_szat' => 24000000, 'ecx_first_seen_at' => $now - 60, 'ecx_created_tip_height' => 984790,
    'ecx_exchange_rate' => 40.0, 'ecx_fee_note' => FEE_NOTE_HOSTING, 'ecx_fee_destination' => $FEE_DEST,
    'ecx_needs_manual_confirmation' => 1,
    'ecx_manual_candidates' => json_encode([['txid' => $CAND_TX, 'vout' => 0, 'amount_sat' => 25000000]]),
    'onchain_address' => 'bc1qbtcsideaddress', 'onchain_amount_sat' => 10000,
    'created_at' => $now, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('ecx_payments', [
    'id' => 'ecxp_1', 'invoice_id' => $INV_ID, 'txid' => $TXID, 'vout' => 1, 'amount_sat' => 25000000,
    'confirmations' => 3, 'block_height' => 984800, 'first_seen_at' => $now - 60, 'last_seen_at' => $now,
]);
Database::insert('onchain_payments', [
    'id' => 'och_1', 'invoice_id' => $INV_ID, 'txid' => $BTC_TXID, 'vout' => 0, 'amount_sat' => 10000,
    'confirmations' => 0, 'block_height' => null, 'first_seen_at' => $now - 30, 'last_seen_at' => $now,
]);
Database::insert('ecx_fee_credits', [
    'store_id' => $store, 'invoice_id' => $INV_ID, 'note' => FEE_NOTE_HOSTING,
    'amount_szat' => 25000000, 'destination' => $FEE_DEST, 'created_at' => $now,
]);
// A second eCash settlement with a dev-fee credit so the by-type aggregate has two keys.
Database::insert('invoices', [
    'id' => 'inv_dev', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'ecx',
    'amount' => '4', 'currency' => 'USD', 'amount_sats' => 4000,
    'ecx_address' => 'bc1qother', 'ecx_amount_szat' => 10000000, 'ecx_fee_note' => FEE_NOTE_DEV,
    'created_at' => $now - 10, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('ecx_fee_credits', [
    'store_id' => $store, 'invoice_id' => 'inv_dev', 'note' => FEE_NOTE_DEV,
    'amount_szat' => 10000000, 'destination' => 'bc1qdevdest', 'created_at' => $now,
]);

// ---- anonymized -------------------------------------------------------------------
$anon = (new Diagnostics(true, null))->toArray();
$json = json_encode($anon);
foreach ([$INV_ID, $ECX_ADDR, $FEE_DEST, $TXID, $BTC_TXID, $CAND_TX, 'bc1qbtcsideaddress', 'bc1qdevdest'] as $marker) {
    assert_true(strpos($json, $marker) === false, "anonymized report must not contain {$marker}");
}
assert_eq(['meta', 'system', 'mint_reliability', 'aggregates', 'mint_event_log', 'notification_failures',
    'invoices', 'invoice_items', 'melts', 'onchain_payments', 'ecx_payments', 'ecx_fee_credits'],
    array_keys($anon), 'report sections, chain observations and the ECX ledger last');

$invRows = array_values(array_filter($anon['invoices'], fn($r) => ($r['ecx_amount_szat'] ?? 0) === 25000000));
assert_eq(1, count($invRows));
$ir = $invRows[0];
$sur = $ir['id'];
assert_true(str_starts_with($sur, 'inv_'), 'surrogate id');
assert_eq(3, $ir['ecx_address_index']);
assert_eq(24000000, $ir['ecx_amount_floor_szat']);
assert_eq(984790, $ir['ecx_created_tip_height']);
assert_eq(40.0, (float)$ir['ecx_exchange_rate']);
assert_eq(FEE_NOTE_HOSTING, $ir['ecx_fee_note'], 'fee note is a classifier, not PII');
assert_eq(1, $ir['ecx_needs_manual_confirmation']);
assert_eq('ecx', $ir['settled_rail']);
foreach (['ecx_address', 'ecx_fee_destination', 'ecx_manual_candidates'] as $pii) {
    assert_false(array_key_exists($pii, $ir), "{$pii} dropped when anonymizing");
}

assert_eq(1, count($anon['ecx_payments']));
$ep = $anon['ecx_payments'][0];
assert_eq($sur, $ep['invoice_id'], 'ecx_payments joins the invoice through the same surrogate');
assert_eq(25000000, $ep['amount_sat']);
assert_eq(3, $ep['confirmations']);
assert_eq(984800, $ep['block_height']);
assert_eq(1, $ep['vout']);
assert_false(array_key_exists('txid', $ep), 'txid dropped');
assert_eq(1, count($anon['onchain_payments']), 'the Bitcoin observations ride along in their own section');
assert_eq($sur, $anon['onchain_payments'][0]['invoice_id']);
assert_false(array_key_exists('txid', $anon['onchain_payments'][0]));

assert_eq(2, count($anon['ecx_fee_credits']));
$byType = array_column($anon['ecx_fee_credits'], null, 'type');
assert_eq(25000000, $byType['fee_hosting']['amount_szat']);
assert_eq($sur, $byType['fee_hosting']['invoice_id']);
assert_eq(10000000, $byType['fee_dev']['amount_szat']);
assert_false(array_key_exists('destination', $byType['fee_hosting']), 'fee destination dropped');

$agg = $anon['aggregates']['ecx'];
assert_eq(2, $agg['settled_count']);
assert_eq(35000000, $agg['revenue_szats']);
assert_eq(['count' => 1, 'amount_szats' => 25000000], $agg['fee_credits_by_type']['fee_hosting']);
assert_eq(['count' => 1, 'amount_szats' => 10000000], $agg['fee_credits_by_type']['fee_dev']);
$rails = array_column($anon['aggregates']['invoices_by_rail'], 'n', 'settled_rail');
assert_eq(2, (int)$rails['ecx'], 'rail breakdown counts the ECX settlements');

// ---- de-anonymized ----------------------------------------------------------------
$full = (new Diagnostics(false, null))->toArray();
$fjson = json_encode($full);
foreach ([$INV_ID, $ECX_ADDR, $FEE_DEST, $TXID, $CAND_TX] as $marker) {
    assert_true(strpos($fjson, $marker) !== false, "de-anonymized report must contain {$marker}");
}
$fr = array_values(array_filter($full['invoices'], fn($r) => $r['id'] === $INV_ID))[0];
assert_eq($ECX_ADDR, $fr['ecx_address']);
assert_eq($FEE_DEST, $fr['ecx_fee_destination']);
assert_eq($TXID, $full['ecx_payments'][0]['txid']);
assert_eq($INV_ID, $full['ecx_payments'][0]['invoice_id'], 'real ids in full mode');
assert_eq($FEE_DEST, array_column($full['ecx_fee_credits'], null, 'type')['fee_hosting']['destination']);

// ---- range filter applies to the new sections -----------------------------------------
Database::insert('ecx_payments', [
    'id' => 'ecxp_old', 'invoice_id' => $INV_ID, 'txid' => str_repeat('0', 64), 'vout' => 0, 'amount_sat' => 1,
    'confirmations' => 9, 'block_height' => 1, 'first_seen_at' => $now - 90 * 86400, 'last_seen_at' => $now - 90 * 86400,
]);
Database::insert('ecx_fee_credits', [
    'store_id' => $store, 'invoice_id' => 'inv_old', 'note' => FEE_NOTE_DEV,
    'amount_szat' => 1, 'destination' => 'x', 'created_at' => $now - 90 * 86400,
]);
$month = (new Diagnostics(true, '1m'))->toArray();
assert_eq(1, count($month['ecx_payments']), 'past-30-days range drops the old observation');
assert_eq(2, count($month['ecx_fee_credits']), 'and the old credit');
$all = (new Diagnostics(true, null))->toArray();
assert_eq(2, count($all['ecx_payments']));
assert_eq(3, count($all['ecx_fee_credits']));

echo "ok\n";
