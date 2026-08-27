<?php
/**
 * WordPress WP-cron pinger (wordpress/cron-integration.php).
 *
 * An alongside install's BareBits server skipped the wizard's crontab screen
 * because the installer declared CASHUPAY_EXTERNAL_CRON — the plugin promised
 * to tick its cron endpoint. This file pins that promise: an every-minute
 * WP-cron event fires one authenticated GET at {server}/cron.php (X-CRON-KEY
 * header, never the URL), requires cron.php's own JSON shape back (a 'mode'
 * key — full run, essentials, and the lock-bounce all carry one), and backs
 * off for ten minutes after any failure so loopback-hostile hosts don't burn
 * every wp-cron request on a timeout. Pure HTTP — there is no inline Invoice
 * fallback and no BareBits code runs inside WordPress; servers connected by
 * URL (mode 'url') run their own cron and get no pinger. The WordPress
 * HTTP/cron/option API surface is stubbed below; the HTTP stub records every
 * request so the tests can assert exactly what was sent and how often.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress stubs -------------------------------------------------
define('ABSPATH', '/tmp/');
function site_url($path = '') { return 'http://wp.test' . $path; }

$GLOBALS['wp_options'] = [];
$GLOBALS['http_requests'] = [];          // recorded [url, args] pairs
$GLOBALS['http_response'] = 200;         // int status, or 'error' for WP_Error
$GLOBALS['http_body'] = '{"mode":"all","tasks":{}}';  // cron.php-shaped default
$GLOBALS['scheduled'] = [];              // wp_schedule_event recordings
$GLOBALS['unscheduled'] = [];            // wp_unschedule_event recordings
$GLOBALS['next_scheduled'] = false;

class WP_Error {}

function add_action($hook, $cb) {}
function add_filter($hook, $cb) {}
function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['wp_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function wp_next_scheduled($hook) { return $GLOBALS['next_scheduled']; }
function wp_schedule_event($ts, $recurrence, $hook) { $GLOBALS['scheduled'][] = [$recurrence, $hook]; return true; }
function wp_unschedule_event($ts, $hook) { $GLOBALS['unscheduled'][] = [$ts, $hook]; return true; }
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

require dirname(__DIR__, 2) . '/wordpress/state.php';
require dirname(__DIR__, 2) . '/wordpress/cron-integration.php';

function reset_http(): void {
    $GLOBALS['http_requests'] = [];
    $GLOBALS['http_response'] = 200;
    $GLOBALS['http_body'] = '{"mode":"all","tasks":{}}';
}

/** The option set an alongside install ends onboarding with. */
function configure_install(): void {
    $GLOBALS['wp_options']['cashupay_mode'] = 'install';
    $GLOBALS['wp_options']['cashupay_server_url'] = 'http://wp.test/barebits';
    $GLOBALS['wp_options']['cashupay_cron_key'] = 'k';
}

// --- unconfigured: no heartbeat owed, tick sends nothing ---------------------
reset_http();
assert_false(cashupay_cron_needed(), 'a fresh plugin owes no heartbeat');
cashupay_cron_tick();
assert_eq(0, count($GLOBALS['http_requests']), 'unconfigured tick sends nothing');

// Every leg of the gate matters: a URL-mode server runs its own cron, and
// without a cron key there is nothing to authenticate with.
configure_install();
$GLOBALS['wp_options']['cashupay_mode'] = 'url';
assert_false(cashupay_cron_needed(), 'URL-mode servers run their own cron');
$GLOBALS['wp_options']['cashupay_mode'] = 'install';
$GLOBALS['wp_options']['cashupay_cron_key'] = '';
assert_false(cashupay_cron_needed(), 'no cron key means no heartbeat');
configure_install();
assert_true(cashupay_cron_needed(), 'a wired alongside install owes the heartbeat');

// --- the ping: authenticated GET at the install's cron.php -------------------
reset_http();
cashupay_cron_tick();
assert_eq(1, count($GLOBALS['http_requests']), 'exactly one request fired');
$req = $GLOBALS['http_requests'][0];
assert_eq('http://wp.test/barebits/cron.php', $req['url'], 'targets the install\'s cron.php');
assert_eq('k', $req['args']['headers']['X-CRON-KEY'] ?? null, 'key travels in the X-CRON-KEY header');
assert_false(str_contains($req['url'], 'k='), 'key must not appear in the URL');
// Same-origin self-request: TLS peer verification is skipped, like WP core's
// own loopbacks (site_url() is wp.test, same as the install).
assert_eq(false, $req['args']['sslverify'] ?? null, 'same-origin loopback skips peer verification');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_backoff_until']), 'success leaves no backoff');
// Every success stamps the heartbeat — the wp-admin stale warning reads this.
$lastOk = (int)($GLOBALS['wp_options']['cashupay_cron_last_ok'] ?? 0);
assert_true($lastOk > 0 && $lastOk <= time(), 'success stamps cashupay_cron_last_ok');

// --- cron-shaped 200 succeeds and clears a standing backoff ------------------
reset_http();
$GLOBALS['wp_options']['cashupay_cron_backoff_until'] = time() - 5; // expired
cashupay_cron_tick();
assert_eq(1, count($GLOBALS['http_requests']), 'expired backoff lets the tick fire');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_backoff_until']), 'success clears the backoff option');

// The lock-bounce response still counts: cron.php answered, mechanism works.
reset_http();
$GLOBALS['http_body'] = '{"skipped":"another cron run in progress","mode":"all"}';
assert_true(cashupay_fire_cron_endpoint(15), 'the lock-bounce JSON still proves the mechanism');

// --- a 200 with a non-cron body is some OTHER page answering -----------------
//
// A misrouted install serving an HTML front page for /cron.php must not count
// as a working heartbeat.
reset_http();
$GLOBALS['http_body'] = '<!doctype html><html>front page</html>';
cashupay_cron_tick();
assert_eq(1, count($GLOBALS['http_requests']), 'the tick attempted the ping');
$backoff = (int)($GLOBALS['wp_options']['cashupay_cron_backoff_until'] ?? 0);
assert_true($backoff > time(), 'a 200 without cron.php JSON sets the backoff');
unset($GLOBALS['wp_options']['cashupay_cron_backoff_until']);

// Non-200 and WP_Error read as failure too.
reset_http();
$GLOBALS['http_response'] = 503;
assert_false(cashupay_fire_cron_endpoint(15), 'non-200 is a failure');
$GLOBALS['http_response'] = 'error';
assert_false(cashupay_fire_cron_endpoint(15), 'WP_Error is a failure');

// --- failure backs off; ticks stop until it expires, then retry --------------
reset_http();
$GLOBALS['http_response'] = 'error';
$staleStamp = time() - 1234;
$GLOBALS['wp_options']['cashupay_cron_last_ok'] = $staleStamp;
cashupay_cron_tick();
assert_eq(1, count($GLOBALS['http_requests']), 'the failed tick attempted the ping');
assert_eq($staleStamp, $GLOBALS['wp_options']['cashupay_cron_last_ok'] ?? null,
    'a failed ping never advances the heartbeat stamp');
$backoff = (int)($GLOBALS['wp_options']['cashupay_cron_backoff_until'] ?? 0);
assert_true($backoff > time(), 'failure sets a future backoff');
assert_true($backoff <= time() + 600, 'the backoff is the documented ten minutes, not forever');

cashupay_cron_tick();
assert_eq(1, count($GLOBALS['http_requests']), 'no request while backed off');

// Time passes (rewind the option): the ping is attempted again and succeeds.
$GLOBALS['wp_options']['cashupay_cron_backoff_until'] = time() - 1;
$GLOBALS['http_response'] = 200;
cashupay_cron_tick();
assert_eq(2, count($GLOBALS['http_requests']), 'ping retried after the backoff expires');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_backoff_until']), 'the successful retry clears the backoff');

// --- the every_minute schedule ----------------------------------------------
$schedules = cashupay_register_cron_interval(['hourly' => ['interval' => 3600, 'display' => 'Hourly']]);
assert_eq(60, $schedules['every_minute']['interval'] ?? null, 'every_minute interval registered at 60s');
assert_eq(3600, $schedules['hourly']['interval'] ?? null, 'existing schedules survive');
$custom = ['every_minute' => ['interval' => 61, 'display' => 'theirs']];
assert_eq($custom, cashupay_register_cron_interval($custom), 'an every_minute someone else registered is left alone');

// --- reschedule self-heals, and only when a heartbeat is owed ----------------
$GLOBALS['next_scheduled'] = false;
cashupay_cron_reschedule();
assert_eq([['every_minute', 'cashupay_cron_tick']], $GLOBALS['scheduled'], 'missing event is rescheduled every_minute');

$GLOBALS['next_scheduled'] = time() + 30;
cashupay_cron_reschedule();
assert_eq(1, count($GLOBALS['scheduled']), 'present event is not double-scheduled');

$GLOBALS['scheduled'] = [];
$GLOBALS['next_scheduled'] = false;
$GLOBALS['wp_options']['cashupay_mode'] = 'url';
cashupay_cron_reschedule();
assert_eq([], $GLOBALS['scheduled'], 'no heartbeat owed, nothing scheduled');
$GLOBALS['wp_options']['cashupay_mode'] = 'install';

// --- unschedule clears both the event and the backoff ------------------------
$GLOBALS['next_scheduled'] = 12345;
$GLOBALS['wp_options']['cashupay_cron_backoff_until'] = time() + 300;
cashupay_cron_unschedule();
assert_eq([[12345, 'cashupay_cron_tick']], $GLOBALS['unscheduled'], 'the scheduled event is removed');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_backoff_until']), 'unschedule drops the backoff state');
assert_false(isset($GLOBALS['wp_options']['cashupay_cron_last_ok']), 'unschedule drops the heartbeat stamp');

echo "test_wp_cron_integration: ok\n";
