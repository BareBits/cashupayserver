<?php
/**
 * EsploraProvider must only observe outputs whose `value` is an integer sat
 * amount. A hostile or buggy explorer handing back a string, float, array,
 * negative or boolean value must have that OUTPUT dropped (and the rest of
 * the page still walked) — never cast into 1 sat, a truncated amount or a
 * negative that corrupts the underpayment math. Also: an HTTP error body
 * with escape sequences never reaches the exception text raw.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

$ADDR = 'bc1qexampleexampleexampleexampleexampleexx';
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$pname = stream_socket_get_name($probe, false);
$port = (int)substr($pname, strrpos($pname, ':') + 1);
fclose($probe);
$serverDir = sys_get_temp_dir() . '/esplora_value_' . bin2hex(random_bytes(4));
mkdir($serverDir, 0750, true);
file_put_contents($serverDir . '/mode', 'values');

$router = <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$addr = 'bc1qexampleexampleexampleexampleexampleexx';
$mode = trim((string)@file_get_contents(__DIR__ . '/mode'));
if ($mode === 'error') {
    http_response_code(502);
    header('Content-Type: text/html');
    echo "<html>\x1b[31mBAD GATEWAY\x1b[0m\r\n[forged] log line</html>";
    return;
}
header('Content-Type: application/json');
if (str_contains($uri, '/blocks/tip/height')) { echo '800100'; return; }
if (str_contains($uri, '/address/') && str_ends_with($uri, '/txs')) {
    $tx = fn(string $pad, $value) => [
        'txid' => str_repeat($pad, 64),
        'status' => ['confirmed' => true, 'block_height' => 800090],
        'vout' => [['scriptpubkey_address' => $addr, 'value' => $value]],
    ];
    echo json_encode([
        $tx('a', 700),            // the only well-formed output
        $tx('b', '700'),          // digit string -> tolerated as 700 (ResponseShape::integer)
        $tx('c', 700.5),          // fractional -> dropped
        $tx('d', [700]),          // array -> would have cast to 1 sat
        $tx('e', -700),           // negative -> would have floored to 0 then been counted
        $tx('f', true),           // boolean -> would have cast to 1 sat
        $tx('0', 1e20),           // out of int range -> dropped
        [ // mixed vouts: only the integer one is observed
            'txid' => str_repeat('9', 64),
            'status' => ['confirmed' => true, 'block_height' => 800091],
            'vout' => [
                ['scriptpubkey_address' => $addr, 'value' => 'abc'],
                ['scriptpubkey_address' => $addr, 'value' => 300],
            ],
        ],
    ]);
    return;
}
http_response_code(404);
echo '[]';
PHP;
file_put_contents($serverDir . '/router.php', $router);
$pid = (int) shell_exec(sprintf(
    '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($serverDir), escapeshellarg($serverDir . '/router.php')
));
register_shutdown_function(function () use ($pid) { @posix_kill($pid, 15); });
$up = false;
for ($i = 0; $i < 120; $i++) {
    $h = @fopen("http://127.0.0.1:$port/blocks/tip/height", 'r');
    if ($h) { fclose($h); $up = true; break; }
    usleep(50000);
}
if (!$up) { fail("esplora stub failed to start on port $port"); }
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');

$provider = new EsploraProvider("http://127.0.0.1:$port");
$obs = $provider->addressTransactions($ADDR);
$seen = [];
foreach ($obs as $o) {
    $seen[$o->txid] = $o->amountSat;
}
assert_eq(3, count($obs), 'exactly the three integer-valued outputs observed');
assert_eq(700, $seen[str_repeat('a', 64)] ?? null, 'integer value observed as-is');
assert_eq(700, $seen[str_repeat('b', 64)] ?? null, 'digit-string value tolerated');
assert_eq(300, $seen[str_repeat('9', 64)] ?? null, 'integer output next to a garbage one still observed');
foreach (['c', 'd', 'e', 'f', '0'] as $pad) {
    assert_false(array_key_exists(str_repeat($pad, 64), $seen), "malformed value tx {$pad} dropped");
}
$total = 0;
foreach ($obs as $o) { $total += $o->amountSat; }
assert_eq(1700, $total, 'received total counts only well-formed outputs');

// HTTP error body is sanitized before it reaches the exception message.
file_put_contents($serverDir . '/mode', 'error');
try {
    (new EsploraProvider("http://127.0.0.1:$port"))->addressTransactions($ADDR);
    fail('502 must throw');
} catch (RuntimeException $e) {
    $m = $e->getMessage();
    assert_true(str_contains($m, 'HTTP 502'), 'status reported');
    assert_false(str_contains($m, "\x1b") || str_contains($m, "\r") || str_contains($m, "\n"), 'escape/CRLF bytes stripped from the message');
    assert_true(str_contains($m, 'BAD GATEWAY'), 'printable part of the body kept');
}

echo "test_esplora_value_shape: all assertions passed\n";
