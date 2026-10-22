<?php
/**
 * A php -S Cashu mint stub for the money-safety tests.
 *
 * Serves just enough of NUT-01/02/04/07 for Invoice::getWalletForStore()
 * (loadMint: /v1/info, /v1/keysets, /v1/keys), checkMintQuote
 * (/v1/mint/quote/bolt11/{id}) and NUT-07 checkstate (/v1/checkstate). All
 * answers come from a JSON state file the test rewrites between calls:
 *
 *   {
 *     "quotes":  { "<quoteId>": {"state": "UNPAID|PAID|ISSUED", "expiry": 123, "amount": 500} },
 *     "states":  { "<Yhex>": "SPENT" },      // per-Y override for checkstate
 *     "default_state": "UNSPENT",            // checkstate answer for other Ys
 *     "checkstate_mode": "ok|empty|short|reorder|lowercase",
 *     "fee_ppk": 0
 *   }
 *
 * Every request is appended to <dir>/requests.log as "<METHOD> <PATH>\t<BODY>"
 * so a test can assert which mint was asked (and what for).
 *
 * Not a test itself (no test_ prefix): require it from a test_*.php.
 */
declare(strict_types=1);

/** @return array{pid:int, port:int, url:string, dir:string} */
function start_mock_mint(array $state = []): array {
    $dir = sys_get_temp_dir() . '/mock_mint_' . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);
    mock_mint_set_state($dir, $state);
    $router = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/requests.log', ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $path . "\t" . $body . "\n", FILE_APPEND);
$state = json_decode((string)@file_get_contents(__DIR__ . '/state.json'), true) ?: [];
$keysetId = '00ad268c4d1f5826';
$feePpk = (int)($state['fee_ppk'] ?? 0);
if ($path === '/__mock_mint') {
    echo json_encode(['dir' => __DIR__]);
    exit;
}
if ($path === '/v1/info') {
    echo json_encode(['name' => 'mock mint', 'version' => 'mock/0.1', 'nuts' => new stdClass()]);
    exit;
}
if ($path === '/v1/keysets') {
    echo json_encode(['keysets' => [
        ['id' => $keysetId, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => $feePpk],
    ]]);
    exit;
}
if ($path === '/v1/keys' || $path === '/v1/keys/' . $keysetId) {
    $keys = [];
    for ($i = 0; $i < 32; $i++) {
        // Any 33-byte hex string will do: these tests never unblind a signature.
        $keys[(string)(2 ** $i)] = '02' . str_pad(dechex($i + 1), 64, '0', STR_PAD_LEFT);
    }
    echo json_encode(['keysets' => [['id' => $keysetId, 'unit' => 'sat', 'keys' => $keys]]]);
    exit;
}
if (preg_match('#^/v1/mint/quote/bolt11/([^/]+)$#', $path, $m)) {
    $q = $state['quotes'][$m[1]] ?? null;
    if ($q === null) {
        http_response_code(404);
        echo json_encode(['detail' => 'quote not found', 'code' => 20001]);
        exit;
    }
    echo json_encode([
        'quote' => $m[1],
        'request' => 'lnbc1stub',
        'amount' => (int)($q['amount'] ?? 0),
        'unit' => 'sat',
        'state' => (string)($q['state'] ?? 'UNPAID'),
        'expiry' => $q['expiry'] ?? null,
    ]);
    exit;
}
if ($path === '/v1/checkstate') {
    $req = json_decode($body, true) ?: [];
    $Ys = $req['Ys'] ?? [];
    $mode = $state['checkstate_mode'] ?? 'ok';
    $default = strtoupper((string)($state['default_state'] ?? 'UNSPENT'));
    $states = [];
    foreach ($Ys as $Y) {
        $st = strtoupper((string)($state['states'][$Y] ?? $default));
        if ($mode === 'lowercase') {
            $st = strtolower($st);
        }
        $states[] = ['Y' => $Y, 'state' => $st, 'witness' => null];
    }
    if ($mode === 'empty') {
        $states = [];
    } elseif ($mode === 'short') {
        array_pop($states);
    } elseif ($mode === 'reorder' && count($states) > 1) {
        $states = array_reverse($states);
    } elseif ($mode === 'nostates') {
        echo json_encode(['detail' => 'nope']);
        exit;
    }
    echo json_encode(['states' => $states]);
    exit;
}
http_response_code(404);
echo json_encode(['detail' => 'not found: ' . $path]);
PHP;
    file_put_contents($dir . '/router.php', $router);

    $base = 29600 + (getmypid() % 900);
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $port = $base + $attempt;
        $pid = (int) shell_exec(sprintf(
            '%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), $port,
            escapeshellarg($dir), escapeshellarg($dir . '/router.php')
        ));
        register_shutdown_function(static function () use ($pid) {
            @posix_kill($pid, 9);
        });
        for ($i = 0; $i < 40; $i++) {
            $resp = @file_get_contents("http://127.0.0.1:{$port}/__mock_mint");
            if ($resp !== false) {
                $health = json_decode($resp, true);
                if (is_array($health) && ($health['dir'] ?? '') === $dir) {
                    return ['pid' => $pid, 'port' => $port, 'url' => "http://127.0.0.1:{$port}", 'dir' => $dir];
                }
                break; // some other server owns this port — try the next one
            }
            usleep(50000);
        }
        @posix_kill($pid, 9);
    }
    fwrite(STDERR, "mock mint failed to start on any port\n");
    exit(1);
}

function mock_mint_set_state(string $dir, array $state): void {
    file_put_contents($dir . '/state.json', json_encode($state));
}

/** Merge into the current state (top-level keys replaced). */
function mock_mint_update_state(string $dir, array $patch): void {
    $state = json_decode((string)@file_get_contents($dir . '/state.json'), true) ?: [];
    mock_mint_set_state($dir, array_merge($state, $patch));
}

/** @return string[] "<METHOD> <PATH>" lines received so far */
function mock_mint_requests(string $dir): array {
    $raw = (string)@file_get_contents($dir . '/requests.log');
    $out = [];
    foreach (explode("\n", $raw) as $line) {
        if ($line === '') {
            continue;
        }
        $out[] = explode("\t", $line, 2)[0];
    }
    return $out;
}

function mock_mint_clear_requests(string $dir): void {
    @unlink($dir . '/requests.log');
}
