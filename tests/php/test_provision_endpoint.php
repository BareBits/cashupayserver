<?php
/**
 * One-time provisioning handshake (provision.php).
 *
 * An external orchestrator (the GPL WordPress companion plugin's alongside
 * install) deploys the app with only a token *hash* in user_config.php, then
 * POSTs the plaintext token here after the operator finishes the wizard and
 * collects — exactly once — the store id, internal API key, and cron key.
 * The invariants pinned here: the endpoint is a 404 on deployments that never
 * opted in, the token is checked in constant time against the sha256 hash,
 * nothing is minted before the wizard completes ({"status":"pending"} lets
 * the installer poll), and the first successful collection stamps
 * provision_consumed_at so every later attempt gets 410.
 *
 * provision.php echoes JSON and exits, so each scenario runs it in a
 * subprocess through a small driver that fakes the request superglobals and
 * appends the final http_response_code() to stdout on shutdown (CLI reports
 * false until a code is set — that reads as the default 200).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

/**
 * Run provision.php once in a subprocess.
 *
 * @param ?string $hash   value for CASHUPAY_PROVISION_TOKEN_HASH (null = undefined)
 * @param ?string $header X-PROVISION-TOKEN header value
 * @param ?string $post   POST body 'token' field value
 * @return array{status:int, json:?array}
 */
function run_provision(?string $hash, string $method, ?string $header, ?string $post = null): array {
    global $dataDir;
    static $n = 0;
    $driver = $dataDir . '/provision_driver_' . (++$n) . '.php';
    $code = "<?php\n";
    $code .= "define('CASHUPAY_DATA_DIR', " . var_export($dataDir, true) . ");\n";
    if ($hash !== null) {
        $code .= "define('CASHUPAY_PROVISION_TOKEN_HASH', " . var_export($hash, true) . ");\n";
    }
    $code .= "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n";
    if ($header !== null) {
        $code .= "\$_SERVER['HTTP_X_PROVISION_TOKEN'] = " . var_export($header, true) . ";\n";
    }
    if ($post !== null) {
        $code .= "\$_POST['token'] = " . var_export($post, true) . ";\n";
    }
    $code .= "register_shutdown_function(function () {\n"
        . "    \$c = http_response_code();\n"
        . "    echo \"\\nHTTP_STATUS:\" . (\$c === false ? 200 : \$c);\n"
        . "});\n";
    $code .= "require " . var_export(dirname(__DIR__, 2) . '/provision.php', true) . ";\n";
    file_put_contents($driver, $code);

    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("provision driver produced unparseable output (rc=$rc): $raw");
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

// --- 1. No hash constant: the endpoint does not exist ------------------------
//
// Ordinary installs never define CASHUPAY_PROVISION_TOKEN_HASH, so even a
// well-formed POST with a plausible token must see a plain 404.
$res = run_provision(null, 'POST', $token);
assert_eq(404, $res['status'], 'no hash constant answers 404');
assert_eq(['error' => 'Not found'], $res['json'], 'and a JSON not-found body');

// --- 2. Wrong method ---------------------------------------------------------
$res = run_provision($tokenHash, 'GET', $token);
assert_eq(405, $res['status'], 'GET is refused — the token never rides a query string');

// --- 3. Wrong or missing token ----------------------------------------------
$res = run_provision($tokenHash, 'POST', bin2hex(random_bytes(32)));
assert_eq(403, $res['status'], 'a wrong token is rejected');
$res = run_provision($tokenHash, 'POST', null);
assert_eq(403, $res['status'], 'a missing token is rejected');
// A malformed hash constant (not 64 lowercase hex) must never validate, even
// against a "matching" token — a truncated write during install is a lockout,
// not a bypass.
$res = run_provision('deadbeef', 'POST', $token);
assert_eq(403, $res['status'], 'a malformed hash constant validates nothing');

// --- 4. Right token, wizard not finished: pending ----------------------------
Config::set('setup_complete', false);
$res = run_provision($tokenHash, 'POST', $token);
assert_eq(200, $res['status'], 'pending is a 200 — the installer should keep polling');
assert_eq(['status' => 'pending'], $res['json'], 'setup incomplete answers pending');

// Setup complete but the wizard has not created a store yet: still pending,
// never an early mint of credentials.
Config::set('setup_complete', true);
$res = run_provision($tokenHash, 'POST', $token);
assert_eq(['status' => 'pending'], $res['json'], 'no store yet still answers pending');

// --- 5. Right token, wizard done: credentials, exactly once ------------------
//
// Two stores prove the orchestrator gets the FIRST-created one (the one the
// wizard's first run made), not whichever an operator added later. The token
// travels as the POST field this time, covering the body fallback.
make_store('store_prov');
make_store('store_later');
$res = run_provision($tokenHash, 'POST', null, $token);
assert_eq(200, $res['status'], 'collection succeeds');
assert_eq('ready', $res['json']['status'] ?? null, 'status is ready');
assert_eq('store_prov', $res['json']['storeId'] ?? null, 'the first-created store is handed out');
$apiKey = (string)($res['json']['apiKey'] ?? '');
$cronKey = (string)($res['json']['cronKey'] ?? '');
assert_true((bool)preg_match('/^[0-9a-f]{64}$/', $apiKey), 'apiKey is 64 hex chars');
assert_true((bool)preg_match('/^[0-9a-f]{64}$/', $cronKey), 'cronKey is 64 hex chars');

// The handed-out credentials are the persisted ones, not one-off values.
// (Config::get is safe here: this process never read or wrote these keys, so
// its cache cannot mask the subprocess's writes.)
$storeRow = Database::fetchOne("SELECT internal_api_key FROM stores WHERE id = ?", ['store_prov']);
assert_eq($apiKey, $storeRow['internal_api_key'] ?? null, 'apiKey matches stores.internal_api_key');
assert_eq($cronKey, (string)Config::get('cron_key'), 'the lazily seeded cron_key is persisted in config');
assert_true((int)Config::get('provision_consumed_at', 0) > 0, 'provision_consumed_at is stamped');

// --- 6. Second collection: gone ---------------------------------------------
$res = run_provision($tokenHash, 'POST', $token);
assert_eq(410, $res['status'], 'a second collection gets 410');
assert_true(str_contains((string)($res['json']['error'] ?? ''), 'already'), 'and says the credentials were already collected');

// The refusal must not have rotated anything: the first collection's
// credentials stay valid for the orchestrator that holds them.
$storeRow = Database::fetchOne("SELECT internal_api_key FROM stores WHERE id = ?", ['store_prov']);
assert_eq($apiKey, $storeRow['internal_api_key'] ?? null, 'the API key survives the refused retry');

echo "test_provision_endpoint: ok\n";
