<?php
/**
 * Plugin uninstall (wordpress/uninstall.php).
 *
 * Deleting the plugin from wp-admin must clean up the plugin's own
 * WordPress-side state — every cashupay_* option and the WP-cron pinger —
 * and reset the BTCPay gateway options ONLY when they point at the server
 * this plugin connected; a hand-configured BTCPay connection is not ours to
 * remove. The script has no filesystem or HTTP surface (the stub set below
 * provides none), which is the "never touches the server or its data"
 * promise in executable form.
 *
 * uninstall.php is a plain top-level script (no exit, no functions), so each
 * scenario seeds the option store and requires it again.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// --- minimal WordPress stubs -------------------------------------------------
define('WP_UNINSTALL_PLUGIN', true);

$GLOBALS['wp_options'] = [];
$GLOBALS['unscheduled'] = [];
$GLOBALS['next_scheduled'] = false;

function get_option($name, $default = false) { return $GLOBALS['wp_options'][$name] ?? $default; }
function delete_option($name) { unset($GLOBALS['wp_options'][$name]); return true; }
function wp_next_scheduled($hook) { return $GLOBALS['next_scheduled']; }
function wp_unschedule_event($ts, $hook) { $GLOBALS['unscheduled'][] = $hook; $GLOBALS['next_scheduled'] = false; return true; }

const UNINSTALL = __DIR__ . '/../../wordpress/uninstall.php';

/** Every option the plugin owns, as a fully-populated install would hold them. */
function seed_plugin_options(string $serverUrl): void {
    $GLOBALS['wp_options'] = array_merge($GLOBALS['wp_options'], [
        'cashupay_mode' => 'install',
        'cashupay_server_url' => $serverUrl,
        'cashupay_store_id' => 'store_x',
        'cashupay_api_key' => str_repeat('a', 64),
        'cashupay_cron_key' => str_repeat('b', 64),
        'cashupay_cron_backoff_until' => 1700000000,
        'cashupay_wired_at' => 1700000000,
        'cashupay_discount_percent' => 3,
        'cashupay_pairing_expected' => ['state' => 'x', 'at' => 1700000000],
        'cashupay_provision_token' => str_repeat('c', 64),
        'cashupay_admin_password' => 'super-secret',
        'cashupay_sso_key' => str_repeat('d', 64),
        'cashupay_install_dir' => '/var/www/barebits',
        'cashupay_install_data_dir' => '/var/www/barebits-data',
        'cashupay_install_dirname' => 'barebits',
        'cashupay_gateway_icon_attachment_id' => 42,
        'cashupay_btcpay_override_consent' => 'https://old.example',
        'cashupay_review_banner' => ['count' => 1, 'dismissed_at' => 1700000000],
    ]);
}

function remaining_cashupay_options(): array {
    return array_values(array_filter(
        array_keys($GLOBALS['wp_options']),
        fn($k) => str_starts_with($k, 'cashupay_')
    ));
}

// --- Gateway pointed at OUR server: its options are reset too ----------------

seed_plugin_options('http://wp.test/barebits');
$GLOBALS['wp_options'] += [
    'btcpay_gf_url' => 'http://wp.test/barebits',
    'btcpay_gf_api_key' => str_repeat('a', 64),
    'btcpay_gf_store_id' => 'store_x',
    'btcpay_gf_webhook' => ['id' => 'wh_1', 'url' => 'http://wp.test/?wc-api=btcpaygf_default', 'secret' => 's'],
];
$GLOBALS['next_scheduled'] = 1700000000;

require UNINSTALL;

assert_eq([], remaining_cashupay_options(), 'every cashupay_* option is deleted');
foreach (['btcpay_gf_url', 'btcpay_gf_api_key', 'btcpay_gf_store_id', 'btcpay_gf_webhook'] as $option) {
    assert_false(array_key_exists($option, $GLOBALS['wp_options']),
        "{$option} pointing at our server is cleaned up");
}
assert_eq(['cashupay_cron_tick'], $GLOBALS['unscheduled'], 'the WP-cron pinger is unscheduled');

// --- Gateway pointed at a REAL BTCPay Server: not ours to remove -------------

$GLOBALS['wp_options'] = [];
$GLOBALS['unscheduled'] = [];
seed_plugin_options('http://wp.test/barebits');
$GLOBALS['wp_options'] += [
    'btcpay_gf_url' => 'https://btcpay.example.com',
    'btcpay_gf_api_key' => 'their-key',
    'btcpay_gf_store_id' => 'their-store',
    'btcpay_gf_webhook' => ['id' => 'wh_theirs'],
];

require UNINSTALL;

assert_eq([], remaining_cashupay_options(), 'plugin state is still fully deleted');
assert_eq('https://btcpay.example.com', $GLOBALS['wp_options']['btcpay_gf_url'] ?? null,
    'a foreign BTCPay connection survives the uninstall');
assert_eq('their-key', $GLOBALS['wp_options']['btcpay_gf_api_key'] ?? null,
    'so does its API key');

// --- No server ever connected: gateway options are left alone entirely -------
//
// cashupay_server_url is '' here; an empty prefix must not read as
// "everything is ours" (the same guard test_btcpay_takeover_decision pins
// for the wiring direction).
$GLOBALS['wp_options'] = [
    'cashupay_mode' => 'url',
    'btcpay_gf_url' => 'https://btcpay.example.com',
    'btcpay_gf_api_key' => 'their-key',
];

require UNINSTALL;

assert_eq([], remaining_cashupay_options(), 'the half-configured state is deleted');
assert_eq('https://btcpay.example.com', $GLOBALS['wp_options']['btcpay_gf_url'] ?? null,
    'an unconfigured plugin never touches the gateway options');

echo "test_wp_uninstall: ok\n";
