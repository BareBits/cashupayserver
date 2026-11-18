<?php
/**
 * LNURL services are third parties the merchant points at; everything they
 * return is attacker-influenced. The two LNURL clients (LnUrlReceive for
 * invoices shown to the payer, LnurlPay for melting our own funds to the
 * dev-fee destination) must refuse:
 *   - an invoice that is not BOLT11-shaped (wrong alphabet, no separator,
 *     escape bytes, an address, mixed case),
 *   - an invoice for a DIFFERENT amount than requested (LUD-06),
 *   - an amountless invoice,
 *   - payRequest metadata whose bounds are not integers (exponent strings,
 *     arrays, floats),
 * and the LUD-21 verify poll must only treat `settled: true` (boolean) as
 * paid and only store a 32-byte hex preimage.
 *
 * One php -S mock host; the mode is a file the router reads per request.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/lnurl_receive.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';

$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$pname = stream_socket_get_name($probe, false);
$port = (int)substr($pname, strrpos($pname, ':') + 1);
fclose($probe);
$serverDir = sys_get_temp_dir() . '/lnurl_pr_test_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);
file_put_contents($serverDir . '/mode', 'ok');

$router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$mode = trim((string)@file_get_contents(__DIR__ . '/mode'));
$port = (int)($_SERVER['SERVER_PORT'] ?? 0);
if (strpos($path, '/.well-known/lnurlp/') === 0) {
    $meta = ['callback' => "http://127.0.0.1:{$port}/cb", 'minSendable' => 1000, 'maxSendable' => 100000000000,
             'metadata' => '[["text/plain","pr test"]]', 'tag' => 'payRequest', 'commentAllowed' => 0];
    if ($mode === 'meta_exponent') { $meta['minSendable'] = '1e3'; }
    if ($mode === 'meta_array') { $meta['maxSendable'] = [100000000000]; }
    if ($mode === 'meta_float') { $meta['minSendable'] = 1000.5; }
    if ($mode === 'meta_callback_array') { $meta['callback'] = ["http://127.0.0.1:{$port}/cb"]; }
    echo json_encode($meta);
    exit;
}
if ($path === '/cb') {
    $amt = (int)($_GET['amount'] ?? 0);
    $good = 'lnbc' . intdiv($amt, 100) . 'n1pretend' . str_repeat('q', 10);
    $pr = match ($mode) {
        'malformed_alphabet' => 'lnbc' . intdiv($amt, 100) . 'n1mockinvoice',
        'malformed_escape'   => $good . "\x1b[31m",
        'malformed_address'  => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
        'malformed_case'     => 'lnbc' . intdiv($amt, 100) . 'n1Pretendqqqqqq',
        'malformed_array'    => ['oops'],
        'wrong_amount'       => 'lnbc' . (intdiv($amt, 100) + 10) . 'n1pretendqqqqqq',
        'amountless'         => 'lnbc1pretendqqqqqq',
        'uppercase'          => strtoupper($good),
        default              => $good,
    };
    echo json_encode(['pr' => $pr, 'verify' => "http://127.0.0.1:{$port}/verify/" . bin2hex(random_bytes(4)), 'routes' => []]);
    exit;
}
if (strpos($path, '/verify/') === 0) {
    echo match ($mode) {
        'verify_string_true' => json_encode(['status' => 'OK', 'settled' => 'true', 'preimage' => str_repeat('ab', 32)]),
        'verify_int_one'     => json_encode(['status' => 'OK', 'settled' => 1, 'preimage' => str_repeat('ab', 32)]),
        'verify_bad_preimage'=> json_encode(['status' => 'OK', 'settled' => true, 'preimage' => "not hex \x1b[31m"]),
        'verify_short_preimage' => json_encode(['status' => 'OK', 'settled' => true, 'preimage' => 'abcd']),
        'verify_paid'        => json_encode(['status' => 'OK', 'settled' => true, 'preimage' => strtoupper(str_repeat('ab', 32))]),
        'verify_scalar'      => '"settled"',
        default              => json_encode(['status' => 'OK', 'settled' => false, 'preimage' => null]),
    };
    exit;
}
http_response_code(404);
echo json_encode(['status' => 'ERROR', 'reason' => 'not found']);
PHP;
file_put_contents($serverDir . '/router.php', $router);
$pid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
));
register_shutdown_function(static function () use ($pid) { @posix_kill($pid, 9); });
$up = false;
for ($i = 0; $i < 120; $i++) {
    $h = @fopen("http://127.0.0.1:$port/.well-known/lnurlp/x", 'r');
    if ($h) { fclose($h); $up = true; break; }
    usleep(50000);
}
if (!$up) { fail("mock lnurl host failed to start on port $port"); }
putenv("CASHU_LNURL_URL_TEMPLATE=http://127.0.0.1:$port/.well-known/lnurlp/{user}");
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
$mode = static function (string $m) use ($serverDir): void { file_put_contents($serverDir . '/mode', $m); };

// ---- LnUrlReceive::probeAndFetchInvoice -------------------------------------
$mode('ok');
$res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason);
assert_not_null($res, 'well-formed invoice for the right amount is accepted');
assert_eq('lnbc10000n1pretendqqqqqqqqqq', $res['bolt11'], 'bolt11 returned verbatim');
assert_eq(1000, $res['min_sendable_msats'], 'minSendable surfaced as int');

$mode('uppercase');
$res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason);
assert_not_null($res, 'all-uppercase BOLT11 is legal bech32');
assert_eq('LNBC10000N1PRETENDQQQQQQQQQQ', $res['bolt11'], 'uppercase preserved');

foreach (['malformed_alphabet', 'malformed_escape', 'malformed_address', 'malformed_case', 'malformed_array'] as $m) {
    $mode($m);
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason);
    assert_null($res, "{$m}: invoice refused");
    assert_eq('the Lightning address service returned a malformed invoice', $reason, "{$m}: reason names the malformed invoice");
}
$mode('wrong_amount');
assert_null(LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason), 'invoice for another amount refused');
assert_eq('the Lightning address service returned an invoice for a different amount', $reason, 'wrong-amount reason');
$mode('amountless');
assert_null(LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason), 'amountless invoice refused');
assert_eq('the Lightning address service returned an invoice for a different amount', $reason, 'amountless -> different amount');

foreach (['meta_exponent', 'meta_array', 'meta_float', 'meta_callback_array'] as $m) {
    $mode($m);
    $res = LnUrlReceive::probeAndFetchInvoice('alice@example.test', 1000, null, $reason);
    assert_null($res, "{$m}: payRequest metadata refused");
    assert_eq('the Lightning address service returned an invalid response', $reason, "{$m}: reason");
    assert_null(LnUrlReceive::probeLud21Support('alice@example.test'), "{$m}: LUD-21 probe reports unreachable/invalid");
}

// ---- parsePayRequestMeta directly ----------------------------------------------
assert_eq(['callback' => 'https://x/cb', 'minSendable' => 1000, 'maxSendable' => 5000],
    LnUrlReceive::parsePayRequestMeta('{"callback":"https://x/cb","minSendable":1000,"maxSendable":5000}'), 'meta happy path');
assert_eq(1000, LnUrlReceive::parsePayRequestMeta('{"callback":"https://x/cb","minSendable":"1000","maxSendable":5000}')['minSendable'], 'digit string bound accepted');
foreach ([
    '{"callback":"https://x/cb","minSendable":5000,"maxSendable":1000}' => 'min > max',
    '{"callback":"","minSendable":1000,"maxSendable":5000}' => 'empty callback',
    '{"callback":"https://x/cb","minSendable":-1,"maxSendable":5000}' => 'negative bound',
    '{"callback":"https://x/cb","minSendable":true,"maxSendable":5000}' => 'boolean bound',
    '{"callback":"https://x/cb","maxSendable":5000}' => 'missing min',
    '[1,2,3]' => 'list body',
    '"str"' => 'scalar body',
    '' => 'empty body',
] as $body => $why) {
    assert_null(LnUrlReceive::parsePayRequestMeta($body), "meta refused: {$why}");
}

// ---- probeLud21Support: malformed pr means "could not determine" -----------------
$mode('malformed_alphabet');
assert_null(LnUrlReceive::probeLud21Support('alice@example.test'), 'malformed pr -> null (not a false "no verify")');
$mode('ok');
assert_eq(1, LnUrlReceive::probeLud21Support('alice@example.test'), 'well-formed -> verify detected');

// ---- pollVerifyUrl -------------------------------------------------------------------
$verify = "http://127.0.0.1:$port/verify/abcd";
$mode('verify_paid');
$r = LnUrlReceive::pollVerifyUrl($verify);
assert_eq('paid', $r['state'], 'settled:true -> paid');
assert_eq(str_repeat('ab', 32), $r['preimage'], 'preimage normalised to lowercase hex');
foreach (['verify_string_true', 'verify_int_one'] as $m) {
    $mode($m);
    assert_eq('pending', LnUrlReceive::pollVerifyUrl($verify)['state'], "{$m}: only boolean true settles");
}
$mode('verify_bad_preimage');
$r = LnUrlReceive::pollVerifyUrl($verify);
assert_eq('paid', $r['state'], 'host says settled -> paid even without a usable preimage');
assert_eq('', $r['preimage'], 'garbage preimage is dropped, not stored');
$mode('verify_short_preimage');
assert_eq('', LnUrlReceive::pollVerifyUrl($verify)['preimage'], 'short preimage is dropped');
$mode('verify_scalar');
assert_eq('error', LnUrlReceive::pollVerifyUrl($verify)['state'], 'scalar body -> error');

// ---- LnurlPay::fetchInvoice (dev-fee melt destination) ------------------------------
$params = ['callback' => "http://127.0.0.1:$port/cb", 'minSendable' => 1000, 'maxSendable' => 100000000000, 'commentAllowed' => 0];
$mode('ok');
assert_eq('lnbc100000n1pretendqqqqqqqqqq', LnurlPay::fetchInvoice($params, 10000, 'memo'), 'pay: well-formed invoice for the right amount');
foreach (['malformed_alphabet' => 'malformed invoice', 'malformed_escape' => 'malformed invoice', 'malformed_array' => 'malformed invoice',
          'wrong_amount' => 'different amount', 'amountless' => 'different amount'] as $m => $needle) {
    $mode($m);
    try {
        LnurlPay::fetchInvoice($params, 10000, 'memo');
        fail("pay: {$m} must throw");
    } catch (Exception $e) {
        assert_true(str_contains($e->getMessage(), $needle), "pay: {$m} -> '{$needle}' (got: {$e->getMessage()})");
        assert_false(str_contains($e->getMessage(), "\x1b"), "pay: {$m} leaks no raw bytes");
    }
}
// payRequest metadata with non-integer bounds is refused by resolve() too
// (bare LNURL-pay URL form, which points straight at the mock).
$mode('meta_exponent');
assert_null(LnurlPay::resolve("http://127.0.0.1:$port/.well-known/lnurlp/alice"), 'pay: exponent-string bounds refused');
$mode('meta_array');
assert_null(LnurlPay::resolve("http://127.0.0.1:$port/.well-known/lnurlp/alice"), 'pay: array bound refused');
$mode('ok');
$resolved = LnurlPay::resolve("http://127.0.0.1:$port/.well-known/lnurlp/alice");
assert_not_null($resolved, 'pay: well-formed metadata resolves');
assert_eq(1000, $resolved['minSendable'], 'pay: minSendable is an int');

echo "test_lnurl_pr_validation: all assertions passed\n";
