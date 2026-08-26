<?php
/**
 * BareBits plugin — uninstall.
 *
 * Runs when the plugin is deleted via WordPress admin. Cleans up only the
 * plugin's own WordPress-side state. The BareBits server (whether remote or
 * installed alongside) and its data directory are deliberately NOT touched:
 * the data directory holds wallet keys and ecash tokens — real money. The
 * install/data directory locations are shown on the plugin's status page;
 * operators who truly want them gone must export their recovery phrases and
 * delete those directories by hand. License: GPLv2 or later.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Reset BTCPay gateway options only if they point at the server this plugin
// connected — a hand-configured BTCPay connection is not ours to remove.
$cashupay_server = rtrim((string) get_option('cashupay_server_url', ''), '/');
$btcpay_url = (string) get_option('btcpay_gf_url', '');
if ($cashupay_server !== '' && $btcpay_url !== '' && strpos($btcpay_url, $cashupay_server) === 0) {
    delete_option('btcpay_gf_url');
    delete_option('btcpay_gf_api_key');
    delete_option('btcpay_gf_store_id');
    delete_option('btcpay_gf_webhook');
}

// Stop the WP-cron pinger.
$timestamp = wp_next_scheduled('cashupay_cron_tick');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'cashupay_cron_tick');
}

// The plugin's own state.
foreach ([
    'cashupay_mode',
    'cashupay_server_url',
    'cashupay_store_id',
    'cashupay_api_key',
    'cashupay_cron_key',
    'cashupay_cron_backoff_until',
    'cashupay_wired_at',
    'cashupay_discount_percent',
    'cashupay_pairing_expected',
    'cashupay_provision_token',
    'cashupay_install_dir',
    'cashupay_install_data_dir',
    'cashupay_install_dirname',
    'cashupay_gateway_icon_attachment_id',
    // A future reinstall must re-warn about replacing a BTCPay connection,
    // not inherit a stale approval.
    'cashupay_btcpay_override_consent',
    'cashupay_review_banner',
] as $cashupay_option) {
    delete_option($cashupay_option);
}
