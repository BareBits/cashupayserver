<?php
/**
 * barebits_configure_btcpay_plugin() must confirm the three btcpay_gf_*
 * gateway options actually persisted (wordpress/btcpay-integration.php).
 *
 * The BTCPay-for-WooCommerce gateway treats an empty URL / API key / store id
 * as "not configured" and fails every checkout with "Can't process order".
 * On the SQLite database drop-in a write racing another worker's can be
 * dropped without an error reaching update_option()'s caller, so the wiring
 * reads each option back past the object cache, retries briefly, and reports
 * a loud failure instead of handing the merchant a checkout that cannot take
 * orders. Exercised here against an option store that can be told to drop
 * writes.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress + plugin-state stubs ----------------------------------
define('ABSPATH', __DIR__ . '/');
function add_action($hook, $cb) {}
function site_url($path = '') { return 'http://shop.test' . $path; }

$GLOBALS['wp_options'] = [];
$GLOBALS['wp_cache'] = [];           // what get_option() serves first, like the object cache
$GLOBALS['drop_writes'] = [];        // option name => how many more writes to lose
$GLOBALS['write_log'] = [];
$GLOBALS['api_calls'] = [];

function get_option($name, $default = false) {
    if (array_key_exists($name, $GLOBALS['wp_cache'])) {
        return $GLOBALS['wp_cache'][$name];
    }
    return $GLOBALS['wp_options'][$name] ?? $default;
}
function update_option($name, $value) {
    $GLOBALS['write_log'][] = $name;
    if (($GLOBALS['drop_writes'][$name] ?? 0) > 0) {
        // The lost-write shape: the database keeps its old row, but the
        // in-request cache would still answer with the value we "stored".
        $GLOBALS['drop_writes'][$name]--;
        $GLOBALS['wp_cache'][$name] = $value;
        return true;
    }
    $GLOBALS['wp_options'][$name] = $value;
    $GLOBALS['wp_cache'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['wp_options'][$name], $GLOBALS['wp_cache'][$name]); return true; }
function wp_cache_delete($key, $group = '') {
    if ($key === 'alloptions') { $GLOBALS['wp_cache'] = []; }
    unset($GLOBALS['wp_cache'][$key]);
    return true;
}
// state.php (not loaded) — the server this site is paired with.
function barebits_server_url(): string { return 'http://127.0.0.1:9999/barebits'; }
function barebits_gateway_server_url(): string { return 'http://127.0.0.1:9999/barebits/api.php?cashupay_path='; }
// The Greenfield webhook API, happy path: nothing registered yet, create works.
function barebits_api_request(string $method, string $path, ?array $body = null, ?string $apiKey = null): array {
    $GLOBALS['api_calls'][] = $method . ' ' . $path;
    if ($method === 'GET') {
        return ['error' => null, 'code' => 200, 'body' => []];
    }
    return ['error' => null, 'code' => 200, 'body' => ['id' => 'wh_1', 'secret' => 's3cret']];
}
require __DIR__ . '/../../wordpress/btcpay-integration.php';

function reset_state(array $dropWrites = []): void {
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cache'] = [];
    $GLOBALS['drop_writes'] = $dropWrites;
    $GLOBALS['write_log'] = [];
    $GLOBALS['api_calls'] = [];
}

// --- 1. every write persists: wired, webhook registered ------------------------
reset_state();
$res = barebits_configure_btcpay_plugin('store_abc', str_repeat('k', 64));
assert_true(!empty($res['success']), 'happy path succeeds: ' . json_encode($res));
assert_eq('http://127.0.0.1:9999/barebits/api.php?cashupay_path=', $GLOBALS['wp_options']['btcpay_gf_url']);
assert_eq(str_repeat('k', 64), $GLOBALS['wp_options']['btcpay_gf_api_key']);
assert_eq('store_abc', $GLOBALS['wp_options']['btcpay_gf_store_id']);
assert_eq(2, count($GLOBALS['api_calls']), 'webhook listed then created');
assert_eq('wh_1', $GLOBALS['wp_options']['btcpay_gf_webhook']['id'] ?? null);

// --- 2. a transiently dropped write is retried and still lands ----------------
reset_state(['btcpay_gf_api_key' => 2]);
$res = barebits_configure_btcpay_plugin('store_abc', str_repeat('k', 64));
assert_true(!empty($res['success']), 'two lost writes are retried through: ' . json_encode($res));
assert_eq(str_repeat('k', 64), $GLOBALS['wp_options']['btcpay_gf_api_key'], 'third attempt persisted');
assert_eq(3, count(array_keys($GLOBALS['write_log'], 'btcpay_gf_api_key', true)), 'exactly three write attempts');
assert_eq(1, count(array_keys($GLOBALS['write_log'], 'btcpay_gf_url', true)), 'a write that landed is not repeated');

// --- 3. a write that never persists fails the connection loudly --------------
reset_state(['btcpay_gf_store_id' => PHP_INT_MAX]);
$res = barebits_configure_btcpay_plugin('store_abc', str_repeat('k', 64));
assert_true(empty($res['success']), 'lost store id must not report success');
assert_eq('options', $res['error'] ?? null);
assert_true(strpos((string) ($res['message'] ?? ''), 'btcpay_gf_store_id') !== false, 'message names the option: ' . json_encode($res));
assert_eq(3, count(array_keys($GLOBALS['write_log'], 'btcpay_gf_store_id', true)), 'gives up after three attempts');
assert_eq(0, count($GLOBALS['api_calls']), 'no webhook registration against a half-configured gateway');
assert_true(!array_key_exists('btcpay_gf_store_id', $GLOBALS['wp_options']), 'the store id really is absent');

// --- 4. the cache must not mask a lost write ----------------------------------
// Regression guard for the helper itself: with the cache still holding the
// value we "wrote", a naive get_option() would say it landed.
reset_state(['btcpay_gf_url' => PHP_INT_MAX]);
assert_false(barebits_update_option_verified('btcpay_gf_url', 'http://x/'), 'verified write sees past the cache');
reset_state();
$GLOBALS['wp_options']['btcpay_gf_url'] = 'http://same/';
assert_true(barebits_update_option_verified('btcpay_gf_url', 'http://same/'), 'rewriting the stored value is a success');

echo "test_wp_configure_btcpay_verified_writes: OK\n";
