<?php
/**
 * Per-invoice payment-path attempt log (includes/invoice_attempts.php) and
 * its wiring into Invoice::create / the pollers:
 *
 *   1. Unit: record() persists rows; the poll-context throttle collapses an
 *      identical failure inside POLL_THROTTLE_SEC; rows are capped per
 *      invoice; formatForApi hides the raw detail from non-admins;
 *      legacyFromInvoice reconstructs a creation walk from receive_errors +
 *      the winning rail for invoices that predate the table; pollFailure
 *      writes the attempt AND the admin_event_log row.
 *   2. Buffering: rows recorded under beginBuffer() stay in memory until
 *      flush(); discard() drops them.
 *   3. Invoice::create with two silent NWC connections and a live noffer:
 *      the walk is recorded in order (nwc failed, nwc failed, noffer ok)
 *      with masked labels, sanitized reasons and the raw error as detail;
 *      no secret material reaches any column; the checkout-context
 *      admin_event_log rows now carry the invoice id.
 *   4. Dead LNURL host first, noffer second: the lnurl failure names the
 *      address in label (admin-visible) and the fixed phrase in reason.
 *   5. A create() that throws (dead mint, nothing else) leaves no rows
 *      behind (no invoice, no orphaned attempts, no FK violation).
 */
declare(strict_types=1);

define('NWC_TIMEOUT_SEC', 2);
define('CLINK_NOFFER_TIMEOUT_SEC', 2);

require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice_attempts.php';
require_once dirname(__DIR__, 2) . '/app/includes/admin_log.php';

use swentel\nostr\Key\Key;

// ---------- 1. unit checks ----------
make_store('store_att_unit');
Database::insert('invoices', [
    'id' => 'inv_att_1', 'store_id' => 'store_att_unit', 'status' => 'New', 'amount' => '1000',
    'currency' => 'sat', 'created_at' => 1700000000, 'expiration_time' => 1700003600,
]);

InvoiceAttempts::record('inv_att_1', 'store_att_unit', 'checkout', 'nwc', 'NWC wallet ab12… via relay.test',
    'failed', 'no response from the wallet (timed out)', 'No response from NWC wallet within 2s');
InvoiceAttempts::record('inv_att_1', 'store_att_unit', 'checkout', 'noffer', 'noffer1abc', 'ok', null, 'bolt11 received');
$rows = InvoiceAttempts::listForInvoice('inv_att_1');
assert_eq(2, count($rows), 'two rows persisted');
assert_eq('nwc', $rows[0]['rail'], 'rows come back oldest first');
assert_eq('failed', $rows[0]['outcome'], 'outcome persisted');
assert_eq('ok', $rows[1]['outcome'], 'second row ok');

// Unknown context / outcome are coerced rather than rejected (logging must
// never throw).
InvoiceAttempts::record('inv_att_1', 'store_att_unit', 'bogus', 'mint', null, 'weird', null, null);
$last = Database::fetchOne("SELECT * FROM invoice_rail_attempts WHERE invoice_id = 'inv_att_1' ORDER BY id DESC LIMIT 1");
assert_eq('checkout', $last['context'], 'unknown context coerced to checkout');
assert_eq('failed', $last['outcome'], 'unknown outcome coerced to failed');
Database::query("DELETE FROM invoice_rail_attempts WHERE id = ?", [$last['id']]);

// formatForApi: detail only for admins; reason always.
$api = InvoiceAttempts::formatForApi($rows, false);
assert_false(array_key_exists('detail', $api[0]), 'non-admin payload has no detail key');
assert_eq('no response from the wallet (timed out)', $api[0]['reason'], 'sanitized reason always present');
assert_eq('NWC wallet ab12… via relay.test', $api[0]['label'], 'masked label present for everyone');
$apiAdmin = InvoiceAttempts::formatForApi($rows, true);
assert_eq('No response from NWC wallet within 2s', $apiAdmin[0]['detail'], 'admin payload carries the raw detail');

// Poll throttle: identical failure within the window collapses; a different
// message or a different invoice still logs.
InvoiceAttempts::pollFailure('inv_att_1', 'store_att_unit', 'nwc', 'NWC wallet ab12…', 'relay refused');
InvoiceAttempts::pollFailure('inv_att_1', 'store_att_unit', 'nwc', 'NWC wallet ab12…', 'relay refused');
InvoiceAttempts::pollFailure('inv_att_1', 'store_att_unit', 'nwc', 'NWC wallet ab12…', 'relay timeout');
$polls = Database::fetchAll("SELECT * FROM invoice_rail_attempts WHERE invoice_id = 'inv_att_1' AND context = 'poll'");
assert_eq(2, count($polls), 'identical poll failures throttle; a distinct message still logs');
assert_eq('the payment status could not be checked', $polls[0]['reason'], 'poll failures carry the fixed phrase');
assert_eq('relay refused', $polls[0]['detail'], 'raw poll error kept as detail');
$logPolls = Database::fetchAll("SELECT * FROM admin_event_log WHERE context = 'poll' AND invoice_id = 'inv_att_1'");
assert_eq(2, count($logPolls), 'pollFailure mirrors into admin_event_log (same throttle)');
assert_eq('nwc', $logPolls[0]['category'], 'admin log category = rail');

// Per-invoice cap: overfill, then one more record() trims to the cap and
// keeps the newest.
$stmt = Database::getInstance()->prepare(
    "INSERT INTO invoice_rail_attempts (invoice_id, store_id, timestamp, context, rail, outcome, detail)
     VALUES ('inv_att_1', 'store_att_unit', ?, 'poll', 'mint', 'failed', ?)"
);
for ($i = 0; $i < InvoiceAttempts::PER_INVOICE_CAP + 15; $i++) {
    $stmt->execute([1600000000 + $i, "filler {$i}"]);
}
InvoiceAttempts::record('inv_att_1', 'store_att_unit', 'checkout', 'mint', 'https://mint.test', 'ok', null, 'newest');
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts WHERE invoice_id = 'inv_att_1'");
assert_eq(InvoiceAttempts::PER_INVOICE_CAP, (int)$n['n'], 'rows per invoice capped');
$newest = Database::fetchOne("SELECT detail FROM invoice_rail_attempts WHERE invoice_id = 'inv_att_1' ORDER BY timestamp DESC, id DESC LIMIT 1");
assert_eq('newest', $newest['detail'], 'newest row survives the prune');

// Cascade: deleting the invoice removes its attempts.
Database::query("DELETE FROM invoices WHERE id = 'inv_att_1'");
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts WHERE invoice_id = 'inv_att_1'");
assert_eq(0, (int)$n['n'], 'attempt rows cascade with the invoice');

// legacyFromInvoice: pre-table invoice -> failures from receive_errors, then
// the winning rail, then the on-chain address.
$legacy = InvoiceAttempts::legacyFromInvoice([
    'id' => 'inv_old', 'created_at' => 1690000000, 'payment_rail' => 'lnaddress',
    'ln_destination' => 'merchant@host.test', 'bolt11' => 'lnbc1...', 'mint_url' => null,
    'receive_errors' => json_encode([
        ['type' => 'nwc', 'reason' => 'no response from the wallet (timed out)'],
        ['type' => 'strike', 'reason' => 'the Strike API could not be reached'],
    ]),
    'onchain_address' => 'bc1qexample', 'fee_redirect_rails' => null,
]);
assert_eq(4, count($legacy), 'two failures + lightning ok + onchain ok');
assert_eq(['nwc', 'strike', 'lnurl', 'onchain'], array_column($legacy, 'rail'), 'legacy rails in walk order');
assert_eq(['failed', 'failed', 'ok', 'ok'], array_column($legacy, 'outcome'), 'legacy outcomes');
assert_eq('merchant@host.test', $legacy[2]['label'], 'winning LN destination becomes the ok label');
assert_eq(1690000000, $legacy[0]['timestamp'], 'legacy rows are stamped with created_at');
assert_false(array_key_exists('detail', $legacy[0]), 'legacy rows have no raw detail');
$legacyFee = InvoiceAttempts::legacyFromInvoice([
    'created_at' => 1, 'payment_rail' => 'lnaddress', 'bolt11' => 'lnbc', 'ln_destination' => 'fees@payee.test',
    'receive_errors' => null, 'fee_redirect_rails' => 'lightning', 'onchain_address' => null,
]);
assert_eq('fee', $legacyFee[0]['rail'], 'fee-routed lightning rail is labelled fee');
assert_eq([], InvoiceAttempts::legacyFromInvoice(['created_at' => 1, 'receive_errors' => null, 'bolt11' => null]),
    'nothing to reconstruct -> empty list');

// ---------- 2. buffering ----------
InvoiceAttempts::beginBuffer('inv_buf');
InvoiceAttempts::record('inv_buf', 'store_att_unit', 'checkout', 'nwc', 'x', 'failed', 'r', 'd');
InvoiceAttempts::record('inv_buf', 'store_att_unit', 'checkout', 'mint', 'https://m', 'ok', null, null);
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts WHERE invoice_id = 'inv_buf'");
assert_eq(0, (int)$n['n'], 'buffered rows are not written before flush');
assert_true(InvoiceAttempts::isBuffering('inv_buf'), 'buffer active');
Database::insert('invoices', [
    'id' => 'inv_buf', 'store_id' => 'store_att_unit', 'status' => 'New', 'amount' => '1',
    'currency' => 'sat', 'created_at' => 1700000000, 'expiration_time' => 1700003600,
]);
InvoiceAttempts::flush('inv_buf');
assert_false(InvoiceAttempts::isBuffering('inv_buf'), 'buffer cleared by flush');
$buffered = InvoiceAttempts::listForInvoice('inv_buf');
assert_eq(['nwc', 'mint'], array_column($buffered, 'rail'), 'flush writes the queued rows in order');

InvoiceAttempts::beginBuffer('inv_discard');
InvoiceAttempts::record('inv_discard', 'store_att_unit', 'checkout', 'nwc', 'x', 'failed', 'r', 'd');
InvoiceAttempts::discard('inv_discard');
InvoiceAttempts::flush('inv_discard'); // no-op
$n = Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts WHERE invoice_id = 'inv_discard'");
assert_eq(0, (int)$n['n'], 'discarded rows never reach the table');

// ---------- shared mock plumbing (mirrors test_invoice_receive_errors) ----------
$key = new Key();
$walletSk = $key->generatePrivateKey();
$walletPk = $key->getPublicKey($walletSk);
$clientSk = $key->generatePrivateKey();
$clientSk2 = $key->generatePrivateKey();
$merchantSk = $key->generatePrivateKey();
$merchantPk = $key->getPublicKey($merchantSk);

function start_mock(string $script, string $portVar, array $env): array {
    static $seq = 0;
    $base = 29700 + (getmypid() % 800) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, [$portVar => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/php/' . $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail("mock {$script} failed to start on any port");
}
function stop_mock($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

// ---------- 3. two silent NWC connections -> noffer fallback ----------
[$nwcProc, $nwcPort] = start_mock('mock_nwc_wallet.php', 'MOCK_NWC_PORT', [
    'MOCK_NWC_WALLET_SK' => $walletSk,
    'MOCK_NWC_SILENT' => '1',
]);
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1m0ckvnv0ce0000000',
]);
try {
    $store = 'store_att_nwc';
    make_store($store, 'http://127.0.0.1:1');
    $uri1 = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk}";
    $uri2 = "nostr+walletconnect://{$walletPk}?relay=ws://127.0.0.1:{$nwcPort}&secret={$clientSk2}";
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'fallback-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'nwc', 'address' => $uri1],
        ['type' => 'nwc', 'address' => $uri2],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'silent NWC wallets fall through to the noffer');
    assert_false(InvoiceAttempts::isBuffering($inv['id']), 'create() flushed its buffer');

    $att = InvoiceAttempts::listForInvoice($inv['id']);
    assert_eq(['nwc', 'nwc', 'noffer'], array_column($att, 'rail'), 'the walk is recorded in order');
    assert_eq(['failed', 'failed', 'ok'], array_column($att, 'outcome'), 'two failures then the winner');
    foreach ($att as $a) {
        assert_eq('checkout', $a['context'], 'creation walk rows are checkout context');
        assert_eq($store, $a['store_id'], 'store stamped');
    }
    assert_true(strpos((string)$att[0]['label'], 'NWC wallet') === 0, 'NWC label is the masked display label');
    assert_eq('no response from the wallet (timed out)', $att[0]['reason'], 'sanitized reason recorded');
    assert_true(strpos((string)$att[0]['detail'], 'No response from NWC wallet') !== false,
        'raw wallet error kept as admin detail');
    assert_eq($noffer, $att[2]['label'], 'noffer row labelled with the noffer string');
    assert_null($att[2]['reason'], 'ok rows carry no reason');
    assert_true(strpos((string)$att[2]['detail'], '1000 sat') !== false, 'ok detail names the amount');

    // Secret hygiene across every text column.
    foreach ($att as $a) {
        $blob = implode(' ', [(string)$a['label'], (string)$a['reason'], (string)$a['detail']]);
        foreach ([$clientSk, $clientSk2, 'nostr+walletconnect', 'secret='] as $needle) {
            assert_false(strpos($blob, $needle) !== false, "attempt rows must not contain '{$needle}'");
        }
    }

    // The checkout-context admin log rows now carry the invoice id (they
    // used to be written with NULL, so the Log tab couldn't tie them back).
    $logRows = Database::fetchAll(
        "SELECT * FROM admin_event_log WHERE category = 'nwc' AND context = 'checkout' AND store_id = ?",
        [$store]
    );
    assert_eq(2, count($logRows), 'one admin log row per failed NWC destination');
    foreach ($logRows as $lr) {
        assert_eq($inv['id'], $lr['invoice_id'], 'checkout log rows reference the invoice');
    }

    // formatForApi of the real rows: a non-admin sees reason + label, no detail.
    $public = InvoiceAttempts::formatForApi($att, false);
    assert_false(array_key_exists('detail', $public[0]), 'non-admin view hides raw detail');
    assert_eq('nwc', $public[0]['rail'], 'rail exposed');
} finally {
    stop_mock($nwcProc);
    stop_mock($clinkProc);
}

// ---------- 4. dead LNURL host first -> noffer second ----------
[$clinkProc, $clinkPort] = start_mock('mock_clink_relay.php', 'MOCK_CLINK_PORT', [
    'MOCK_CLINK_MERCHANT_SK' => $merchantSk,
    'MOCK_CLINK_BOLT11' => 'lnbc10000n1m0ckvnv0ce0000000',
]);
putenv('CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:1/.well-known/lnurlp/{user}');
try {
    $store = 'store_att_lnurl';
    make_store($store, 'http://127.0.0.1:1');
    $noffer = ClinkNoffer::encode([
        'pubkey' => $merchantPk,
        'relay' => "ws://127.0.0.1:{$clinkPort}",
        'offer' => 'fallback-offer',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);
    StoreLnAddresses::replaceForStore($store, [
        ['type' => 'lnaddress', 'address' => 'merchant@dead-host.test'],
        ['type' => 'noffer', 'address' => $noffer],
    ]);

    $inv = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('noffer', $inv['payment_rail'], 'dead LNURL host falls through to the noffer');
    $att = InvoiceAttempts::listForInvoice($inv['id']);
    assert_eq(['lnurl', 'noffer'], array_column($att, 'rail'), 'lnurl then noffer');
    assert_eq('failed', $att[0]['outcome'], 'lnurl failed');
    assert_eq('merchant@dead-host.test', $att[0]['label'], 'the LN address is the (admin-visible) label');
    assert_eq('the Lightning address service could not be reached', $att[0]['reason'], 'fixed phrase as reason');
    assert_eq('ok', $att[1]['outcome'], 'noffer ok');
    $lr = Database::fetchOne(
        "SELECT * FROM admin_event_log WHERE category = 'lnurl' AND store_id = ? ORDER BY id DESC LIMIT 1",
        [$store]
    );
    assert_eq($inv['id'], $lr['invoice_id'], 'lnurl checkout log row references the invoice');
} finally {
    putenv('CASHU_LNURL_URL_TEMPLATE');
    stop_mock($clinkProc);
}

// ---------- 5. aborted create leaves nothing behind ----------
$store = 'store_att_abort';
make_store($store, 'http://127.0.0.1:1'); // dead mint, no destinations, no on-chain
$before = (int)Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts")['n'];
$threw = false;
try {
    Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw, 'dead mint with no other rail aborts creation');
$after = (int)Database::fetchOne("SELECT COUNT(*) AS n FROM invoice_rail_attempts")['n'];
assert_eq($before, $after, 'an aborted create writes no attempt rows (buffer never flushed)');
$orphans = Database::fetchOne(
    "SELECT COUNT(*) AS n FROM invoice_rail_attempts a LEFT JOIN invoices i ON i.id = a.invoice_id WHERE i.id IS NULL"
);
assert_eq(0, (int)$orphans['n'], 'no orphaned attempt rows');

echo "test_invoice_rail_attempts: ok\n";
