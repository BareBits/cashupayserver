<?php
/**
 * Strict no-mint-fallback vs. the orderable Cashu mint (admin.php handlers):
 *
 *   1. save_lightning_payments with the Cashu mint AHEAD of the swap clears a
 *      store's strict_no_mint_fallback pin and reports strictCleared=true;
 *      with the mint behind the swap the flag is left alone
 *      (strictCleared=false), and a save that posts no rail_order never
 *      touches either.
 *   2. save_store_swaps refuses to turn strict ON while the stored order has
 *      the mint ahead of the swap (clear message, flag unchanged), accepts it
 *      once the mint is behind the swap again, and still accepts strict OFF
 *      in either order.
 *   3. Legacy four-type rail_order posts (older UI) are stored with swap and
 *      cashu appended, i.e. the historical "mint last" behavior.
 *
 * admin.php echoes and exits, so each call runs in a subprocess (same driver
 * shape as test_enable_store_mint.php).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';
require_once dirname(__DIR__, 2) . '/app/includes/swap/config.php';

Database::insert('users', [
    'id'            => 'user_rail_admin',
    'username'      => 'admin',
    'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'          => 'admin',
    'created_at'    => Database::timestamp(),
]);

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_rail_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %s;
require %s;
require %s;
Auth::initSession();
$_SESSION['user_id'] = 'user_rail_admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['auth_fp'] = hash('sha256', 'cashupay-session:' . (string)Database::fetchOne("SELECT password_hash FROM users WHERE id = ?", [$_SESSION['user_id']])['password_hash']);
$_SESSION['login_time'] = time();
$_POST = json_decode((string)getenv('T_POST'), true);
$_POST['csrf_token'] = Auth::generateCsrfToken();
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/admin.php', true)
));

/** Run one admin.php POST action in a subprocess. @return array{status:int, json:?array} */
function admin_post(array $post): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_POST=' . escapeshellarg(json_encode($post));
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>>' . escapeshellarg($dataDir . '/runner-stderr.log'), $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

function strict_flag(string $storeId): int {
    return (int)(Database::fetchOne("SELECT strict_no_mint_fallback FROM stores WHERE id = ?", [$storeId])['strict_no_mint_fallback'] ?? -99);
}
function stored_order(string $storeId): ?string {
    return Database::fetchOne("SELECT ln_rail_order FROM stores WHERE id = ?", [$storeId])['ln_rail_order'] ?? null;
}

$store = 'store_strict_order';
make_store($store, 'https://mint.example');
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_ON);
assert_eq(1, strict_flag($store), 'precondition: strict pinned ON');

// ---------- 1a. a save with the mint still behind the swap leaves strict alone ----------
$res = admin_post([
    'action' => 'save_lightning_payments',
    'store_id' => $store,
    'rail_order' => 'noffer,nwc,lnaddress,strike,swap,cashu',
]);
assert_eq(200, $res['status'], 'order save (mint last) succeeds: ' . json_encode($res['json']));
assert_eq(false, $res['json']['strictCleared'] ?? null, 'strictCleared=false when the swap still precedes the mint');
assert_eq(1, strict_flag($store), 'strict untouched while the swap precedes the mint');
assert_eq('noffer,nwc,lnaddress,strike,swap,cashu', stored_order($store), 'order persisted');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike', 'swap', 'cashu'], $res['json']['railOrder'] ?? null,
    'response echoes the effective six-path order');

// ---------- 1b. mint ahead of the swap clears strict ----------
$res = admin_post([
    'action' => 'save_lightning_payments',
    'store_id' => $store,
    'rail_order' => 'strike,lnaddress,cashu,nwc,noffer,swap',
]);
assert_eq(200, $res['status'], 'order save (mint before swap) succeeds: ' . json_encode($res['json']));
assert_eq(true, $res['json']['strictCleared'] ?? null, 'strictCleared=true reported');
assert_eq(0, strict_flag($store), 'strict cleared: the mint now precedes the swap');
assert_eq('strike,lnaddress,cashu,nwc,noffer,swap', stored_order($store), 'order persisted');

// Saving the same order again: nothing to clear, reported as such.
$res = admin_post([
    'action' => 'save_lightning_payments',
    'store_id' => $store,
    'rail_order' => 'strike,lnaddress,cashu,nwc,noffer,swap',
]);
assert_eq(200, $res['status'], 're-save succeeds');
assert_eq(false, $res['json']['strictCleared'] ?? null, 'strictCleared=false when strict was already off');

// ---------- 1c. a save without rail_order touches neither ----------
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_OFF);
RailOrder::setLnOrder($store, 'strike,lnaddress,nwc,noffer,swap,cashu');
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_ON);
$res = admin_post([
    'action' => 'save_lightning_payments',
    'store_id' => $store,
]);
assert_eq(200, $res['status'], 'legacy save without rail_order succeeds');
assert_eq(1, strict_flag($store), 'strict untouched when no order was posted');
assert_eq('strike,lnaddress,nwc,noffer,swap,cashu', stored_order($store), 'order untouched when none was posted');

// ---------- 2. swaps card: strict ON refused while the mint precedes the swap ----------
RailOrder::setLnOrder($store, 'cashu,strike,lnaddress,nwc,noffer,swap');
SwapsConfig::setStoreStrictOverride($store, SwapsConfig::FORCE_OFF);
$res = admin_post([
    'action' => 'save_store_swaps',
    'store_id' => $store,
    'enabled' => '0',
    'strict_no_mint_fallback' => '1',
]);
assert_eq(400, $res['status'], 'strict ON refused while the mint precedes the swap: ' . json_encode($res['json']));
assert_true(stripos((string)($res['json']['error'] ?? ''), 'ahead of the submarine swap') !== false,
    'refusal explains the order conflict: ' . json_encode($res['json']));
assert_eq(0, strict_flag($store), 'flag unchanged after the refusal');

// Strict OFF is always accepted.
$res = admin_post([
    'action' => 'save_store_swaps',
    'store_id' => $store,
    'enabled' => '0',
    'strict_no_mint_fallback' => '0',
]);
assert_eq(200, $res['status'], 'strict OFF accepted in the mint-first order: ' . json_encode($res['json']));

// Once the swap precedes the mint again, strict ON is accepted.
RailOrder::setLnOrder($store, 'strike,lnaddress,nwc,noffer,swap,cashu');
$res = admin_post([
    'action' => 'save_store_swaps',
    'store_id' => $store,
    'enabled' => '0',
    'strict_no_mint_fallback' => '1',
]);
assert_eq(200, $res['status'], 'strict ON accepted with the swap before the mint: ' . json_encode($res['json']));
assert_eq(1, strict_flag($store), 'strict pinned ON again');

// ---------- 3. legacy four-type post → swap,cashu appended ----------
$res = admin_post([
    'action' => 'save_lightning_payments',
    'store_id' => $store,
    'rail_order' => 'lnaddress,strike,nwc,noffer',
]);
assert_eq(200, $res['status'], 'legacy four-type order accepted: ' . json_encode($res['json']));
assert_eq('lnaddress,strike,nwc,noffer,swap,cashu', stored_order($store),
    'legacy order stored with swap,cashu appended (mint stays last)');
assert_eq(1, strict_flag($store), 'legacy order never clears strict (mint still behind the swap)');
assert_eq(false, $res['json']['strictCleared'] ?? null, 'strictCleared=false for the legacy shape');

echo "test_rail_order_strict_clear: OK\n";
