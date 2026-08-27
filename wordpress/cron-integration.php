<?php
/**
 * BareBits plugin — WP-cron pinger for an alongside install.
 *
 * A BareBits server installed by this plugin declared CASHUPAY_EXTERNAL_CRON
 * at install time: its setup wizard skipped the crontab screen because WE
 * promised to tick its cron endpoint. This file keeps that promise — an
 * every-minute WP-cron event fires an authenticated HTTP request to the
 * install's cron.php, which drives the server's full background task set
 * (webhook drain, on-chain polling, sweeps, its own auto-updater, …).
 *
 * Pure HTTP: the cron key travels in the X-CRON-KEY header so it stays out
 * of access logs, and no BareBits code runs inside WordPress. Servers
 * connected by URL (mode 'url') run their own cron and get no pinger.
 * License: GPLv2 or later.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('cashupay_cron_tick', 'cashupay_cron_tick');
add_action('init', 'cashupay_cron_reschedule');
add_filter('cron_schedules', 'cashupay_register_cron_interval');

// When a ping fails (host blocks self-requests, install broken), don't burn
// ~15s of every minute's wp-cron request timing out — back off to this
// cadence until a ping succeeds again.
const CASHUPAY_CRON_BACKOFF_SECONDS = 600;

/** Whether this site owes the alongside install a cron heartbeat. */
function cashupay_cron_needed(): bool {
    return cashupay_mode() === 'install'
        && cashupay_server_url() !== ''
        && (string) get_option('cashupay_cron_key', '') !== '';
}

function cashupay_register_cron_interval(array $schedules): array {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => 'Every minute',
        ];
    }
    return $schedules;
}

/**
 * Self-heal the schedule (WordPress's cron-array write race and cron-cleaner
 * plugins both lose events). wp_next_scheduled reads the already-loaded cron
 * option, so this is cheap enough for init. Also the activation hook's body.
 */
function cashupay_cron_reschedule(): void {
    if (!cashupay_cron_needed()) {
        return;
    }
    if (!wp_next_scheduled('cashupay_cron_tick')) {
        wp_schedule_event(time(), 'every_minute', 'cashupay_cron_tick');
    }
}

function cashupay_cron_unschedule(): void {
    $timestamp = wp_next_scheduled('cashupay_cron_tick');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cashupay_cron_tick');
    }
    delete_option('cashupay_cron_backoff_until');
    delete_option('cashupay_cron_last_ok');
}

/**
 * Fire one authenticated request at the install's cron endpoint. Returns
 * true when cron.php itself answered — it always returns JSON with a 'mode'
 * field (full / essentials / lock-bounce alike); a 200 carrying anything
 * else means some other page answered and must read as failure. Every
 * success stamps cashupay_cron_last_ok, which the wp-admin stale-heartbeat
 * warning (admin-menu.php) reads.
 */
function cashupay_fire_cron_endpoint(int $timeoutSeconds): bool {
    $server = cashupay_server_url();
    $cronKey = (string) get_option('cashupay_cron_key', '');
    if ($server === '' || $cronKey === '') {
        return false;
    }
    $response = wp_remote_get($server . '/cron.php', [
        'timeout' => $timeoutSeconds,
        'redirection' => 2,
        // Same-origin self-request; mirrors WordPress core's own loopbacks,
        // which skip peer verification for local/self-signed HTTPS.
        'sslverify' => !cashupay_is_same_host_url($server),
        'headers' => ['X-CRON-KEY' => $cronKey],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || !array_key_exists('mode', $body)) {
        return false;
    }
    update_option('cashupay_cron_last_ok', time(), false);
    return true;
}

/** The every-minute WP-cron callback. */
function cashupay_cron_tick(): void {
    if (!cashupay_cron_needed()) {
        return;
    }
    $now = time();
    if ($now < (int) get_option('cashupay_cron_backoff_until', 0)) {
        return;
    }
    if (cashupay_fire_cron_endpoint(15)) {
        delete_option('cashupay_cron_backoff_until');
        return;
    }
    update_option('cashupay_cron_backoff_until', $now + CASHUPAY_CRON_BACKOFF_SECONDS, false);
}
