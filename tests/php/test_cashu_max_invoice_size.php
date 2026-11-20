<?php
/**
 * Per-store maximum payment size for the Cashu path (includes/cashu_limit.php)
 * through Invoice::create.
 *
 *   0. Unit: fresh install has both columns with the defaults (unlimited,
 *      respect ON); fromRow / decide / parseMaxSats / deferMintStep / set.
 *   1. Default (unlimited) with the mint FIRST in the order: the mint serves
 *      any size.
 *   2. Max 500, respect ON, payment 1000, working LNURL destination: the
 *      mint is not asked; the attempt log shows a FAILED mint row (size
 *      reason, both figures in the detail) BEFORE the lnurl ok row; the
 *      invoice lands on the LNURL rail; nothing payer-facing is added to
 *      receive_errors; no e-mail is queued.
 *   3. Boundary: a payment AT the maximum (and below it) still goes to the
 *      mint.
 *   4. Max 500, respect ON, LNURL dead, no on-chain: creation fails with a
 *      clear Cashu-maximum error and leaves nothing behind.
 *   5. Same with an on-chain xpub: the invoice is created on-chain only
 *      (failed mint row + failed lnurl row) and the admin log names the
 *      maximum.
 *   6. Max 500, respect OFF, payment 1000, mint-first order, working LNURL:
 *      the mint is deferred behind the destinations — LNURL serves, the
 *      mint is not asked, and a SKIPPED mint row explains the deferral.
 *   7. Respect OFF, LNURL dead: the deferred mint is the last resort and
 *      serves the invoice (lnurl failed, then mint ok).
 *   8. Respect OFF, payment at/below the max: the mint keeps its first slot.
 *   9. Through it all the notification queue stays empty.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/mock_mint_stub.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';
require_once dirname(__DIR__, 2) . '/app/includes/cashu_limit.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice_attempts.php';

const TPUB = 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B';

function has_col(string $table, string $col): bool {
    foreach (Database::getInstance()->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['name'] === $col) {
            return true;
        }
    }
    return false;
}
function attempts(string $invoiceId): array {
    return array_map(
        fn(array $r): array => [$r['rail'], $r['outcome'], $r['reason']],
        InvoiceAttempts::listForInvoice($invoiceId)
    );
}
function attempt_rows(string $invoiceId): array { return InvoiceAttempts::listForInvoice($invoiceId); }
function queued_emails(): int {
    return (int)Database::fetchOne("SELECT COUNT(*) AS c FROM notification_queue")['c'];
}
function new_quote_requests(string $mintDir): array {
    return array_values(array_filter(mock_mint_requests($mintDir), fn($req) => str_starts_with($req, 'POST ')));
}

// ---------- 0. unit ----------
assert_true(has_col('stores', 'cashu_max_invoice_sats'), 'fresh install has the max column');
assert_true(has_col('stores', 'cashu_max_respect_always'), 'fresh install has the respect column');
make_store('store_unit');
$row = Database::fetchOne("SELECT cashu_max_invoice_sats, cashu_max_respect_always FROM stores WHERE id = ?", ['store_unit']);
assert_null($row['cashu_max_invoice_sats'], 'default maximum is NULL (unlimited)');
assert_eq(1, (int)$row['cashu_max_respect_always'], 'default respect-size is ON');
assert_eq(['max_sats' => null, 'respect_always' => true], CashuLimit::forStore('store_unit'), 'forStore reads the defaults');
assert_eq(['max_sats' => null, 'respect_always' => true], CashuLimit::fromRow(['id' => 'x']), 'a row without the columns reads as the defaults');
assert_eq(['max_sats' => null, 'respect_always' => false],
    CashuLimit::fromRow(['cashu_max_invoice_sats' => 0, 'cashu_max_respect_always' => 0]), '0 max = unlimited');

$unlimited = ['max_sats' => null, 'respect_always' => true];
$strict = ['max_sats' => 500, 'respect_always' => true];
$lenient = ['max_sats' => 500, 'respect_always' => false];
assert_eq(CashuLimit::ALLOW, CashuLimit::decide($unlimited, 1_000_000), 'unlimited allows everything');
assert_eq(CashuLimit::ALLOW, CashuLimit::decide($strict, 500), 'at the maximum is allowed');
assert_eq(CashuLimit::ALLOW, CashuLimit::decide($strict, 1), 'below the maximum is allowed');
assert_eq(CashuLimit::ALLOW, CashuLimit::decide($strict, 0), 'an unknown sat value cannot be compared: allowed');
assert_eq(CashuLimit::BLOCK, CashuLimit::decide($strict, 501), 'above + respect ON = block');
assert_eq(CashuLimit::DEFER, CashuLimit::decide($lenient, 501), 'above + respect OFF = defer');

assert_null(CashuLimit::parseMaxSats(''), 'blank = unlimited');
assert_null(CashuLimit::parseMaxSats(' 0 '), '0 = unlimited');
assert_eq(2500, CashuLimit::parseMaxSats('2500'), 'whole sats accepted');
foreach (['12.5', '-5', 'abc', '1e3', '99999999999999999'] as $bad) {
    try {
        CashuLimit::parseMaxSats($bad);
        fail("parseMaxSats accepted {$bad}");
    } catch (InvalidArgumentException $e) {
        assert_true($e->getMessage() !== '', 'rejects ' . $bad);
    }
}

$steps = [['kind' => 'mint'], ['kind' => 'direct', 'destinations' => [1]], ['kind' => 'swap']];
assert_eq([['kind' => 'direct', 'destinations' => [1]], ['kind' => 'swap'], ['kind' => 'mint']],
    CashuLimit::deferMintStep($steps), 'deferMintStep moves the mint last');
assert_eq([['kind' => 'swap']], CashuLimit::deferMintStep([['kind' => 'swap']]), 'no mint step: unchanged');

CashuLimit::set('store_unit', 1234, false);
assert_eq(['max_sats' => 1234, 'respect_always' => false], CashuLimit::forStore('store_unit'), 'set persists both values');
CashuLimit::set('store_unit', null, true);
assert_eq(['max_sats' => null, 'respect_always' => true], CashuLimit::forStore('store_unit'), 'set back to unlimited');

// ---------- mock LNURL host (same shape as test_invoice_ln_rail_order) ----------
$lnurlDir = sys_get_temp_dir() . '/cashu_limit_lnurl_' . bin2hex(random_bytes(4));
mkdir($lnurlDir, 0750, true);
$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
if (strpos($path, '/.well-known/lnurlp/') === 0) {
    echo json_encode(['callback' => $base . '/callback', 'minSendable' => 1000, 'maxSendable' => 100000000000, 'tag' => 'payRequest']);
    return;
}
if (strpos($path, '/callback') === 0) {
    echo json_encode(['pr' => 'lnbc' . intdiv((int)($_GET['amount'] ?? 0), 100) . 'n1m0cks3zelnurl', 'verify' => $base . '/verify/x']);
    return;
}
if (strpos($path, '/verify') === 0) {
    echo json_encode(['status' => 'OK', 'settled' => false, 'preimage' => null]);
    return;
}
http_response_code(404);
PHP;
file_put_contents($lnurlDir . '/router.php', $router);
$lnurlPort = 28700 + (getmypid() % 700);
$lnurlPid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $lnurlPort,
    escapeshellarg($lnurlDir), escapeshellarg($lnurlDir . '/router.php')
));
register_shutdown_function(static function () use ($lnurlPid) {
    @posix_kill($lnurlPid, 9);
});
for ($i = 0; $i < 40; $i++) {
    $c = @fsockopen('127.0.0.1', $lnurlPort, $e, $s, 0.2);
    if ($c) { fclose($c); break; }
    usleep(50000);
}
$LIVE_LNURL = "http://127.0.0.1:{$lnurlPort}/.well-known/lnurlp/{user}";
$DEAD_LNURL = 'http://127.0.0.1:1/.well-known/lnurlp/{user}';
putenv("CASHU_LNURL_URL_TEMPLATE={$LIVE_LNURL}");

$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 600]);
$store = 'store_cashu_max';
make_store($store, $mint['url']);
StoreLnAddresses::replaceForStore($store, [
    ['type' => 'lnaddress', 'address' => 'merchant@limit-wallet.test', 'supports_verify' => 1],
]);
// Mint FIRST: without a limit the mint would serve every invoice.
RailOrder::setLnOrder($store, 'cashu,lnaddress,strike,nwc,noffer,swap');
assert_eq(0, queued_emails(), 'no e-mail queued before the run');

try {
    // ---------- 1. unlimited: the mint serves any size ----------
    mock_mint_clear_requests($mint['dir']);
    $inv1 = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('mint', $inv1['payment_rail'], 'unlimited: mint-first order serves from the mint');
    assert_eq([['mint', 'ok', null]], attempts($inv1['id']), 'one ok mint attempt');

    // ---------- 2. max 500, respect ON, payment 1000, LNURL working ----------
    CashuLimit::set($store, 500, true);
    mock_mint_clear_requests($mint['dir']);
    $inv2 = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv2['payment_rail'], 'respect ON: the LNURL destination serves the over-limit payment');
    assert_eq('lnbc10000n1m0cks3zelnurl', $inv2['bolt11'], 'bolt11 from the LNURL host');
    assert_eq([['mint', 'failed', CashuLimit::REASON_BLOCKED], ['lnurl', 'ok', null]], attempts($inv2['id']),
        'walk recorded: mint failed for size (in its first slot), then lnurl ok');
    $blockRow = attempt_rows($inv2['id'])[0];
    assert_eq('checkout', $blockRow['context'], 'checkout context');
    assert_true(str_contains((string)$blockRow['detail'], '1,000 sat') && str_contains((string)$blockRow['detail'], '500 sat'),
        'detail names both figures: ' . $blockRow['detail']);
    assert_true(str_contains((string)$blockRow['detail'], 'respect size'), 'detail names the switch');
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was never asked for a quote');
    $row2 = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv2['id']]);
    assert_null($row2['receive_errors'], 'a size decision is not a wallet problem: nothing payer-facing');
    assert_eq(0, queued_emails(), 'size block queues no e-mail');

    // ---------- 3. boundary: at / below the maximum the mint still leads ----------
    mock_mint_clear_requests($mint['dir']);
    $inv3 = Invoice::create($store, ['amount' => 500, 'currency' => 'sat']);
    assert_eq('mint', $inv3['payment_rail'], 'a payment AT the maximum goes to the mint');
    assert_eq([['mint', 'ok', null]], attempts($inv3['id']), 'no size row at the boundary');
    $inv3b = Invoice::create($store, ['amount' => 499, 'currency' => 'sat']);
    assert_eq('mint', $inv3b['payment_rail'], 'below the maximum goes to the mint');

    // ---------- 4. respect ON, LNURL dead, nothing else: clear error, nothing left behind ----------
    putenv("CASHU_LNURL_URL_TEMPLATE={$DEAD_LNURL}");
    $invoicesBefore = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoices")['c'];
    $attemptsBefore = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_rail_attempts")['c'];
    mock_mint_clear_requests($mint['dir']);
    try {
        Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
        fail('creation must fail when the size block leaves no payable rail');
    } catch (Exception $e) {
        assert_true(str_contains($e->getMessage(), 'Cashu maximum'), 'error names the Cashu maximum: ' . $e->getMessage());
        assert_true(str_contains($e->getMessage(), '1,000 sat') && str_contains($e->getMessage(), '500 sat'),
            'error carries both figures: ' . $e->getMessage());
    }
    assert_eq($invoicesBefore, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoices")['c'], 'no invoice row');
    assert_eq($attemptsBefore, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_rail_attempts")['c'],
        'no orphaned attempt rows');
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was not asked');
    assert_eq(0, queued_emails(), 'a refused creation queues no e-mail');

    // ---------- 5. respect ON, LNURL dead, on-chain xpub: on-chain only + admin log ----------
    Database::update('stores', [
        'onchain_xpub' => TPUB, 'onchain_address_type' => 'P2WPKH', 'onchain_network' => 'regtest',
    ], 'id = ?', [$store]);
    mock_mint_clear_requests($mint['dir']);
    $inv5 = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('onchain', $inv5['payment_rail'], 'on-chain only when the size block + dead LNURL leave no Lightning');
    assert_null($inv5['bolt11'], 'no bolt11');
    assert_null($inv5['mint_url'], 'the mint did not issue anything');
    assert_not_null($inv5['onchain_address'], 'on-chain address allocated');
    $a5 = attempts($inv5['id']);
    assert_eq(['mint', 'failed', CashuLimit::REASON_BLOCKED], $a5[0], 'mint failed for size first');
    assert_eq(['lnurl', 'failed'], [$a5[1][0], $a5[1][1]], 'then the dead LNURL host');
    assert_eq('onchain', $a5[2][0], 'then the on-chain address');
    assert_eq('ok', $a5[2][1], 'on-chain allocated');
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was not asked');
    $log5 = Database::fetchOne(
        "SELECT category, message FROM admin_event_log WHERE invoice_id = ? AND context = 'checkout' ORDER BY id DESC LIMIT 1",
        [$inv5['id']]
    );
    assert_not_null($log5, 'admin log row written');
    assert_eq('mint', $log5['category'], 'logged under the mint category');
    assert_true(str_contains((string)$log5['message'], 'Cashu maximum'), 'admin log names the maximum: ' . $log5['message']);
    $row5 = Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv5['id']]);
    assert_eq(['lnurl'], array_column(json_decode((string)$row5['receive_errors'], true) ?: [], 'type'),
        'only the real wallet failure is payer-facing');
    assert_eq(0, queued_emails(), 'on-chain-only outcome queues no e-mail');
    Database::update('stores', ['onchain_xpub' => null], 'id = ?', [$store]);

    // ---------- 6. respect OFF, LNURL working: the mint is deferred behind the destinations ----------
    putenv("CASHU_LNURL_URL_TEMPLATE={$LIVE_LNURL}");
    CashuLimit::set($store, 500, false);
    mock_mint_clear_requests($mint['dir']);
    $inv6 = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('lnaddress', $inv6['payment_rail'], 'respect OFF: destinations go first for an over-limit payment');
    assert_eq([['lnurl', 'ok', null], ['mint', 'skipped', CashuLimit::REASON_DEFERRED]], attempts($inv6['id']),
        'lnurl ok, then the deferred mint recorded as skipped');
    $skipRow = attempt_rows($inv6['id'])[1];
    assert_true(str_contains((string)$skipRow['detail'], 'deferred') && str_contains((string)$skipRow['detail'], '500 sat'),
        'skip detail explains the deferral: ' . $skipRow['detail']);
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was not asked when another path served');
    assert_null(Database::fetchOne("SELECT receive_errors FROM invoices WHERE id = ?", [$inv6['id']])['receive_errors'],
        'nothing payer-facing for a deferral');

    // ---------- 7. respect OFF, LNURL dead: the deferred mint is the last resort ----------
    putenv("CASHU_LNURL_URL_TEMPLATE={$DEAD_LNURL}");
    mock_mint_clear_requests($mint['dir']);
    $inv7 = Invoice::create($store, ['amount' => 1000, 'currency' => 'sat']);
    assert_eq('mint', $inv7['payment_rail'], 'respect OFF: the mint serves once every other Lightning path failed');
    assert_eq($mint['url'], $inv7['mint_url'], 'the store mint issued the quote');
    $a7 = attempts($inv7['id']);
    assert_eq(['lnurl', 'failed'], [$a7[0][0], $a7[0][1]], 'lnurl failed first');
    assert_eq(['mint', 'ok', null], $a7[1], 'then the mint ok (no size row: it was actually used)');
    assert_true(new_quote_requests($mint['dir']) !== [], 'the mint was asked for a quote');

    // ---------- 8. respect OFF, at/below the max: the mint keeps its first slot ----------
    putenv("CASHU_LNURL_URL_TEMPLATE={$LIVE_LNURL}");
    mock_mint_clear_requests($mint['dir']);
    $inv8 = Invoice::create($store, ['amount' => 500, 'currency' => 'sat']);
    assert_eq('mint', $inv8['payment_rail'], 'respect OFF at the maximum: mint-first order unchanged');
    assert_eq([['mint', 'ok', null]], attempts($inv8['id']), 'single ok mint row');

    // ---------- 9. no e-mail of any kind ----------
    assert_eq(0, queued_emails(), 'the notification queue stayed empty throughout');
} finally {
    putenv('CASHU_LNURL_URL_TEMPLATE');
    @posix_kill($lnurlPid, 9);
}

echo "test_cashu_max_invoice_size: ok\n";
