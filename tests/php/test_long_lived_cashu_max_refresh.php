<?php
/**
 * Long-lived invoices honour the per-store Cashu maximum on refresh
 * (includes/cashu_limit.php through Invoice::refreshLightning).
 *
 * A long-lived invoice re-sources its Lightning invoice as the bolt11 lapses.
 * The refresh walks the same order as creation, so the same size rule must
 * apply — otherwise an over-limit invoice created on a Lightning destination
 * would quietly move onto the mint the first time that destination is down.
 *
 *   1. Max 500, respect ON: a 1000 sat long-lived invoice created on the
 *      LNURL rail; the LNURL host goes down and the bolt11 lapses. The
 *      refresh answers 'unavailable', the row is untouched, the mint saw no
 *      new quote request, the refresh-context attempt log shows lnurl failed
 *      + mint FAILED for size, and the admin log names the maximum.
 *   2. Respect OFF, LNURL still down: the refresh moves the invoice onto the
 *      mint (last resort) — lnurl failed, then mint ok.
 *   3. Respect OFF, LNURL back: a fresh over-limit invoice refreshes onto the
 *      LNURL rail and the mint is recorded SKIPPED (deferred, not asked).
 *   4. At/below the maximum nothing changes: the mint serves a refresh when
 *      the destination is down.
 *   5. No e-mail is queued by any of it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/cashu_limit.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice_attempts.php';

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function refresh_attempts(string $invoiceId): array {
    return array_values(array_map(
        fn(array $r): array => [$r['rail'], $r['outcome'], $r['reason']],
        array_filter(InvoiceAttempts::listForInvoice($invoiceId), fn(array $r): bool => $r['context'] === 'refresh')
    ));
}
function last_refresh_log(string $invoiceId): ?string {
    $r = Database::fetchOne(
        "SELECT message FROM admin_event_log WHERE invoice_id = ? AND context = 'refresh' ORDER BY id DESC LIMIT 1",
        [$invoiceId]
    );
    return $r ? (string)$r['message'] : null;
}
function new_quote_requests(string $mintDir): array {
    return array_values(array_filter(mock_mint_requests($mintDir), fn($req) => str_starts_with($req, 'POST ')));
}
function queued_emails(): int {
    return (int)Database::fetchOne("SELECT COUNT(*) AS c FROM notification_queue")['c'];
}
function lapse(string $invoiceId): void {
    Database::update('invoices', ['ln_expires_at' => time() - 10, 'ln_refresh_lock_at' => null], 'id = ?', [$invoiceId]);
}

// ---------- mock LNURL host ----------
$lnurlDir = sys_get_temp_dir() . '/cashu_limit_refresh_lnurl_' . bin2hex(random_bytes(4));
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
    echo json_encode(['pr' => 'lnbc' . intdiv((int)($_GET['amount'] ?? 0), 100) . 'n1m0cks3zerefresh' . strtr(substr(md5((string)microtime(true)), 0, 6), 'abcdef1', 'qpzry9l'), 'verify' => $base . '/verify/x']);
    return;
}
if (strpos($path, '/verify') === 0) {
    echo json_encode(['status' => 'OK', 'settled' => false, 'preimage' => null]);
    return;
}
http_response_code(404);
PHP;
file_put_contents($lnurlDir . '/router.php', $router);
$lnurlPort = 26500 + (getmypid() % 700); // clear of the mock mint's 29600+ range
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
$store = 'store_cashu_max_ll';
make_store($store, $mint['url']);
StoreLnAddresses::replaceForStore($store, [
    ['type' => 'lnaddress', 'address' => 'merchant@limit-refresh.test', 'supports_verify' => 1],
]);
$lifetime = Config::getSharedInvoiceLifetime();
function new_long_lived(string $store, int $lifetime, int $sats): array {
    return Invoice::create($store, ['amount' => $sats, 'currency' => 'sat', 'lifetime_sec' => $lifetime, 'long_lived' => true]);
}

try {
    // ---------- 1. respect ON: an over-limit invoice must not move onto the mint ----------
    CashuLimit::set($store, 500, true);
    $ll = new_long_lived($store, $lifetime, 1000);
    assert_eq('lnaddress', $ll['payment_rail'], 'created on the LNURL rail');
    $oldBolt11 = $ll['bolt11'];
    putenv("CASHU_LNURL_URL_TEMPLATE={$DEAD_LNURL}");
    lapse($ll['id']);
    mock_mint_clear_requests($mint['dir']);
    $r = Invoice::refreshLightning($ll['id']);
    assert_false((bool)$r['ok'], 'refresh did not succeed');
    assert_eq('unavailable', $r['reason'], 'respect ON: no mint on refresh for an over-limit payment');
    $after = row($ll['id']);
    assert_eq('lnaddress', $after['payment_rail'], 'rail kept');
    assert_eq($oldBolt11, $after['bolt11'], 'lapsed bolt11 left in place like any failed re-source');
    assert_null($after['mint_url'], 'mint not used');
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was not asked for a new quote');
    $ra = refresh_attempts($ll['id']);
    assert_eq(2, count($ra), 'two refresh attempts logged: ' . json_encode($ra));
    assert_eq(['lnurl', 'failed'], [$ra[0][0], $ra[0][1]], 'lnurl failed on refresh');
    assert_eq(['mint', 'failed', CashuLimit::REASON_BLOCKED], $ra[1], 'mint failed for size on refresh');
    $msg = last_refresh_log($ll['id']);
    assert_true($msg !== null && str_contains($msg, 'Cashu maximum'), 'admin log names the maximum: ' . var_export($msg, true));
    assert_eq(0, queued_emails(), 'no e-mail for the refused refresh');

    // ---------- 2. respect OFF: the mint is the last resort on refresh ----------
    CashuLimit::set($store, 500, false);
    Database::update('invoices', ['ln_refresh_lock_at' => null], 'id = ?', [$ll['id']]);
    mock_mint_clear_requests($mint['dir']);
    $r = Invoice::refreshLightning($ll['id']);
    assert_true((bool)$r['ok'], 'respect OFF: refresh ok: ' . json_encode($r['reason'] ?? null));
    $after = row($ll['id']);
    assert_eq('mint', $after['payment_rail'], 'moved onto the mint once the destination failed');
    assert_eq($mint['url'], $after['mint_url'], 'mint recorded');
    assert_not_null($after['bolt11'], 'bolt11 sourced from the mint');
    assert_true(new_quote_requests($mint['dir']) !== [], 'the mint was asked for a quote');
    $ra2 = array_slice(refresh_attempts($ll['id']), 2);
    assert_eq(['lnurl', 'failed'], [$ra2[0][0], $ra2[0][1]], 'lnurl failed again');
    assert_eq(['mint', 'ok', null], $ra2[1], 'then the deferred mint served');

    // ---------- 3. respect OFF, destination back: deferred mint recorded as skipped ----------
    putenv("CASHU_LNURL_URL_TEMPLATE={$LIVE_LNURL}");
    $ll3 = new_long_lived($store, $lifetime, 1000);
    assert_eq('lnaddress', $ll3['payment_rail'], 'created on the LNURL rail');
    lapse($ll3['id']);
    mock_mint_clear_requests($mint['dir']);
    $r = Invoice::refreshLightning($ll3['id']);
    assert_true((bool)$r['ok'], 'refresh ok on the live destination');
    $after3 = row($ll3['id']);
    assert_eq('lnaddress', $after3['payment_rail'], 'still on the LNURL rail');
    assert_neq($ll3['bolt11'], $after3['bolt11'], 'a fresh bolt11 was sourced');
    assert_eq([['lnurl', 'ok', null], ['mint', 'skipped', CashuLimit::REASON_DEFERRED]], refresh_attempts($ll3['id']),
        'refresh walk: lnurl ok, deferred mint skipped');
    assert_eq([], new_quote_requests($mint['dir']), 'the mint was not asked');

    // ---------- 4. at/below the maximum: unchanged behaviour ----------
    CashuLimit::set($store, 500, true);
    $ll4 = new_long_lived($store, $lifetime, 500);
    assert_eq('lnaddress', $ll4['payment_rail'], 'created on the LNURL rail');
    putenv("CASHU_LNURL_URL_TEMPLATE={$DEAD_LNURL}");
    lapse($ll4['id']);
    $r = Invoice::refreshLightning($ll4['id']);
    assert_true((bool)$r['ok'], 'at the maximum the mint may serve the refresh');
    assert_eq('mint', row($ll4['id'])['payment_rail'], 'moved onto the mint');
    $ra4 = refresh_attempts($ll4['id']);
    assert_eq(['mint', 'ok', null], $ra4[count($ra4) - 1], 'mint ok, no size row');

    // ---------- 5. no e-mail of any kind ----------
    assert_eq(0, queued_emails(), 'the notification queue stayed empty throughout');
} finally {
    putenv('CASHU_LNURL_URL_TEMPLATE');
    @posix_kill($lnurlPid, 9);
}

echo "test_long_lived_cashu_max_refresh: ok\n";
