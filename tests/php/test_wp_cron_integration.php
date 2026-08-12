<?php
/**
 * WordPress WP-cron integration (wordpress/cron-integration.php).
 *
 * The every-minute WP-cron callback drives the FULL cron.php task set via an
 * authenticated loopback request (X-CRON-KEY header, real cron key), with an
 * inline quote-poll fallback and a retry backoff for loopback-hostile hosts.
 * The setup wizard's self-test probes the same path with cron.php's slim
 * `only=swaps` mode. The WordPress HTTP/cron API surface is stubbed below;
 * the stub records every request so the tests can assert exactly what was
 * sent and how often.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
define('CASHUPAY_PLUGIN_DIR', dirname(__DIR__, 2));
define('CASHUPAY_WORDPRESS', true);
function site_url($path = '') { return 'http://wp.test' . $path; }

$GLOBALS['wp_options'] = [];
$GLOBALS['http_requests'] = [];          // recorded [url, args] pairs
$GLOBALS['http_response'] = 200;         // int status, or 'error' for WP_Error
$GLOBALS['http_body'] = '{"mode":"all","tasks":{}}';  // cron.php-shaped default
$GLOBALS['scheduled'] = [];              // wp_schedule_event recordings
$GLOBALS['next_scheduled'] = false;

class WP_Error {}

function add_action($hook, $cb) {}
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function wp_next_scheduled($hook) { return $GLOBALS['next_scheduled']; }
function wp_schedule_event($ts, $recurrence, $hook) { $GLOBALS['scheduled'][] = [$recurrence, $hook]; return true; }
function add_query_arg($args, $url) {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function wp_remote_get($url, $args = []) {
    $GLOBALS['http_requests'][] = ['url' => $url, 'args' => $args];
    if ($GLOBALS['http_response'] === 'error') {
        return new WP_Error();
    }
    return ['response' => ['code' => $GLOBALS['http_response']], 'body' => $GLOBALS['http_body']];
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

require dirname(__DIR__, 2) . '/wordpress/cron-integration.php';

function reset_http(): void {
    $GLOBALS['http_requests'] = [];
    $GLOBALS['http_response'] = 200;
    $GLOBALS['http_body'] = '{"mode":"all","tasks":{}}';
}

// fresh_db() marks setup complete; give it the cron key an install would have.
Config::set('cron_key', 'k_test_cron_key');

// --- fire_cron_endpoint: authenticated request to the WP cron route ---------
reset_http();
assert_true(cashupay_fire_cron_endpoint(15), 'endpoint fire succeeds on 200');
assert_eq(1, count($GLOBALS['http_requests']), 'exactly one request fired');
$req = $GLOBALS['http_requests'][0];
assert_eq('http://wp.test/cashupay/cron', $req['url'], 'targets the WP cron route');
assert_eq('k_test_cron_key', $req['args']['headers']['X-CRON-KEY'] ?? null, 'key travels in the X-CRON-KEY header');
assert_true(!str_contains($req['url'], 'k_test_cron_key'), 'key must not appear in the URL');

// Non-200 and WP_Error both read as failure.
$GLOBALS['http_response'] = 503;
assert_false(cashupay_fire_cron_endpoint(15), 'non-200 is a failure');
$GLOBALS['http_response'] = 'error';
assert_false(cashupay_fire_cron_endpoint(15), 'WP_Error is a failure');

// A 200 whose body is not cron.php's JSON is some OTHER page answering — a
// plain-permalink install serving the front page for /cashupay/cron must not
// count as a working cron loopback.
$GLOBALS['http_response'] = 200;
$GLOBALS['http_body'] = '<!doctype html><html>front page</html>';
assert_false(cashupay_fire_cron_endpoint(15), 'a 200 without cron.php JSON is a failure');
// The lock-bounce response still counts: cron.php answered, mechanism works.
$GLOBALS['http_body'] = '{"skipped":"another cron run in progress","mode":"all"}';
assert_true(cashupay_fire_cron_endpoint(15), 'the lock-bounce JSON still proves the mechanism');

// --- selftest: slim only=swaps probe, stamps config on success --------------
reset_http();
assert_true(cashupay_wp_cron_selftest(), 'selftest passes on 200');
assert_true(str_contains($GLOBALS['http_requests'][0]['url'], 'only=swaps'), 'selftest uses the slim fast-lane mode');
assert_true((int)Config::get('wp_cron_selftest_ok_at', 0) > 0, 'selftest success stamped in config');

Config::set('wp_cron_selftest_ok_at', 0);
$GLOBALS['http_response'] = 'error';
assert_false(cashupay_wp_cron_selftest(), 'selftest fails on WP_Error');
assert_eq(0, (int)Config::get('wp_cron_selftest_ok_at', 0), 'failed selftest leaves no stamp');

// --- cron_poll: loopback success needs no fallback ---------------------------
reset_http();
cashupay_cron_poll();
assert_eq(1, count($GLOBALS['http_requests']), 'poll fires one loopback');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_loopback_retry_at']), 'no backoff after success');

// --- cron_poll: failure sets backoff; retries stop until it expires ----------
reset_http();
$GLOBALS['http_response'] = 'error';
cashupay_cron_poll();
assert_eq(1, count($GLOBALS['http_requests']), 'failed poll attempted the loopback');
$retryAt = (int)($GLOBALS['wp_options']['cashupay_cron_loopback_retry_at'] ?? 0);
assert_true($retryAt > time(), 'failure sets a future retry time');

cashupay_cron_poll();
assert_eq(1, count($GLOBALS['http_requests']), 'no loopback attempt during backoff (inline fallback only)');

// Backoff expired: the loopback is attempted again (and succeeds now).
$GLOBALS['wp_options']['cashupay_cron_loopback_retry_at'] = time() - 1;
$GLOBALS['http_response'] = 200;
cashupay_cron_poll();
assert_eq(2, count($GLOBALS['http_requests']), 'loopback retried after backoff expires');

// --- gates: no key / setup incomplete → no request ---------------------------
reset_http();
Config::set('cron_key', '');
assert_false(cashupay_fire_cron_endpoint(15), 'missing cron key refuses to fire');
assert_eq(0, count($GLOBALS['http_requests']), 'no request without a key');
Config::set('cron_key', 'k_test_cron_key');

Config::set('setup_complete', false);
assert_false(cashupay_fire_cron_endpoint(15), 'incomplete setup refuses to fire');
assert_eq(0, count($GLOBALS['http_requests']), 'no request before setup completes');
Config::set('setup_complete', true);

// --- schedule self-heal -------------------------------------------------------
$GLOBALS['next_scheduled'] = false;
cashupay_ensure_cron_scheduled();
assert_eq([['every_minute', 'cashupay_poll_quotes']], $GLOBALS['scheduled'], 'missing event is rescheduled every_minute');

$GLOBALS['next_scheduled'] = time() + 30;
cashupay_ensure_cron_scheduled();
assert_eq(1, count($GLOBALS['scheduled']), 'present event is not double-scheduled');

echo "test_wp_cron_integration: ok\n";
