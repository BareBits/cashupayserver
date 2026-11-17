<?php
/**
 * Shared mock LUD-16/LUD-21 Lightning-address host for PHP unit tests.
 *
 *   start_mock_lnurl_host()            → ['pid','port','dir','url_template']
 *   mock_lnurl_set_mode($dir, 'fail')  → /cb answers 500 (destination down)
 *   mock_lnurl_settle($dir, $verifyUrl)→ that verify URL now reports settled
 *   mock_lnurl_calls($dir)             → number of /cb hits so far
 *   mock_lnurl_requests($dir)          → every request URI the host served
 *                                        (path + raw query), in order
 *
 * Every callback hands out a NEW stub bolt11 ("lnbc1pretend<hex>") with its
 * own verify URL, so tests can tell one sourced invoice from the next.
 * Callers export CASHU_LNURL_URL_TEMPLATE themselves (returned here) so
 * LnUrlReceive resolves addresses against this host.
 */
declare(strict_types=1);

function start_mock_lnurl_host(): array {
    $dir = sys_get_temp_dir() . '/mock_lnurl_' . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    file_put_contents($dir . '/mode', 'ok');
    $router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$dir = __DIR__;
if ($path !== '/__mock_lnurl') {
    file_put_contents($dir . '/requests.log', ($_SERVER['REQUEST_URI'] ?? '/') . "\n", FILE_APPEND);
}
$port = (int)($_SERVER['SERVER_PORT'] ?? 0);
if ($path === '/__mock_lnurl') { echo json_encode(['dir' => $dir]); exit; }
if (strpos($path, '/.well-known/lnurlp/') === 0) {
    echo json_encode([
        'callback' => "http://127.0.0.1:{$port}/cb",
        'minSendable' => 1000, 'maxSendable' => 100000000000,
        'metadata' => '[["text/plain","mock host"]]', 'tag' => 'payRequest',
    ]);
    exit;
}
if ($path === '/cb') {
    file_put_contents($dir . '/calls.log', ($_GET['amount'] ?? '') . "\n", FILE_APPEND);
    if (trim((string)@file_get_contents($dir . '/mode')) === 'fail') {
        http_response_code(500);
        echo json_encode(['status' => 'ERROR', 'reason' => 'down']);
        exit;
    }
    $id = bin2hex(random_bytes(4));
    echo json_encode(['pr' => 'lnbc1pretend' . $id, 'verify' => "http://127.0.0.1:{$port}/verify/{$id}"]);
    exit;
}
if (preg_match('#^/verify/([a-f0-9]+)$#', $path, $m)) {
    $settled = is_file($dir . '/settled_' . $m[1]);
    echo json_encode([
        'status' => 'OK', 'settled' => $settled,
        'preimage' => $settled ? str_repeat('ab', 32) : null, 'pr' => 'lnbc1pretend' . $m[1],
    ]);
    exit;
}
http_response_code(404);
echo json_encode(['status' => 'ERROR', 'reason' => 'not found']);
PHP;
    file_put_contents($dir . '/router.php', $router);
    $base = 30600 + (getmypid() % 900);
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $port = $base + $attempt;
        $pid = (int) shell_exec(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')
        ));
        register_shutdown_function(static function () use ($pid) { @posix_kill($pid, 9); });
        for ($i = 0; $i < 40; $i++) {
            $resp = @file_get_contents("http://127.0.0.1:{$port}/__mock_lnurl");
            if ($resp !== false) {
                $h = json_decode($resp, true);
                if (is_array($h) && ($h['dir'] ?? '') === $dir) {
                    return [
                        'pid' => $pid, 'port' => $port, 'dir' => $dir,
                        'url_template' => "http://127.0.0.1:{$port}/.well-known/lnurlp/{user}",
                    ];
                }
                break;
            }
            usleep(50000);
        }
        @posix_kill($pid, 9);
    }
    fwrite(STDERR, "mock lnurl host failed to start on any port\n");
    exit(1);
}

function mock_lnurl_set_mode(string $dir, string $mode): void {
    file_put_contents($dir . '/mode', $mode);
}

function mock_lnurl_settle(string $dir, string $verifyUrl): void {
    $id = substr($verifyUrl, strrpos($verifyUrl, '/') + 1);
    file_put_contents($dir . '/settled_' . $id, '1');
}

function mock_lnurl_calls(string $dir): int {
    $f = $dir . '/calls.log';
    return is_file($f) ? count(array_filter(explode("\n", (string)file_get_contents($f)))) : 0;
}

/** @return string[] request URIs (path + query) served so far, oldest first. */
function mock_lnurl_requests(string $dir): array {
    $f = $dir . '/requests.log';
    return is_file($f) ? array_values(array_filter(explode("\n", (string)file_get_contents($f)))) : [];
}
