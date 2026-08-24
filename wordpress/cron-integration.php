<?php
/**
 * CashuPay WordPress Cron Integration
 *
 * Drives the FULL cron.php task set (webhook drain, on-chain polling, fee
 * settlement, swap lifecycle, cleanups, ...) through WP-cron, so a WordPress
 * install works without the operator wiring a system crontab entry.
 *
 * The every-minute `cashupay_poll_quotes` event (scheduled on activation,
 * self-healed below) fires an authenticated loopback request to the plugin's
 * cron endpoint. Going over HTTP — rather than including cron.php in-process —
 * keeps all of cron.php's machinery intact (overlap flock, per-task
 * throttles, `last_external_cron_at` stamping) and, crucially, keeps its
 * `exit` paths (lock bounce, not-configured guard) from aborting the wp-cron
 * request and killing other plugins' scheduled events.
 *
 * Because the loopback carries the real cron key it counts as an EXTERNAL
 * cron run: the dashboard's "set up cron" staleness warning clears only while
 * this actually works, and page-load internal triggers drop to their cheaper
 * essentials-only mode. On loopback-hostile hosts (blocked self-requests,
 * basic-auth staging) every call fails, the warning stays visible, and the
 * inline quote-poll fallback keeps Lightning settlement working — exactly the
 * pre-existing behaviour.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('cashupay_poll_quotes', 'cashupay_cron_poll');
add_action('init', 'cashupay_ensure_cron_scheduled');

// When a loopback attempt fails, don't burn ~15s of every minute's wp-cron
// request timing out against a host that blocks self-requests — retry the
// loopback on this cadence and run the inline fallback in between.
const CASHUPAY_CRON_LOOPBACK_RETRY_SECONDS = 600;

/**
 * Self-heal the WP-cron schedule. Activation is the only other place the
 * event gets registered, so a cron-cleaner plugin (or WordPress's known
 * cron-array write race) losing the event would otherwise silence all
 * background work until the plugin is re-activated. wp_next_scheduled reads
 * the already-loaded cron option, so this is cheap enough for init.
 */
function cashupay_ensure_cron_scheduled(): void {
    if (!wp_next_scheduled('cashupay_poll_quotes')) {
        wp_schedule_event(time(), 'every_minute', 'cashupay_poll_quotes');
    }
}

/**
 * Fire an authenticated request to the plugin's cron endpoint. The key
 * travels in the X-CRON-KEY header (cron.php's preferred channel) so it stays
 * out of webserver access logs.
 *
 * Returns true when the endpoint answered 200. A client-side timeout returns
 * false even though the run usually still completes server-side (cron.php
 * calls ignore_user_abort) — callers treat false as "fall back / keep the
 * manual instructions", which is the safe direction.
 */
function cashupay_fire_cron_endpoint(int $timeoutSeconds, array $queryArgs = []): bool {
    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/urls.php';

    if (!Database::isInitialized() || !Config::isSetupComplete()) {
        return false;
    }
    $cronKey = Config::get('cron_key');
    if (!$cronKey) {
        return false;
    }

    $url = Urls::cron();
    if ($queryArgs !== []) {
        $url = add_query_arg($queryArgs, $url);
    }
    $response = wp_remote_get($url, [
        'timeout' => $timeoutSeconds,
        'redirection' => 2,
        // Self-request: mirrors Background::trigger, which also skips peer
        // verification for local/self-signed HTTPS.
        'sslverify' => false,
        'headers' => ['X-CRON-KEY' => $cronKey],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }
    // cron.php always answers JSON carrying a 'mode' field (full /
    // essentials-only / swaps-only runs and the lock-bounce 'skipped'
    // response alike). A 200 with anything else means some other page
    // answered — e.g. a plain-permalink install where /cashupay/cron falls
    // through to the front page — and must read as failure, or the wizard
    // would skip the manual instructions on a host where WP-cron cannot
    // actually reach cron.php.
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($body) && array_key_exists('mode', $body);
}

/**
 * Quick loopback reachability check for the setup wizard: proves WP-cron's
 * request path (rewrite rule → cron.php → key auth) works on this host
 * without paying for a full task run. `only=swaps` is cron.php's slim
 * fast-lane mode — near-instant on a fresh install, unlike a full first run
 * (which downloads the trusted-mints list and geo database and could outlive
 * the probe timeout, reading as a false negative).
 *
 * Success is stamped in config so the wizard's completion screen can say
 * that WordPress cron has taken over.
 */
function cashupay_wp_cron_selftest(): bool {
    $ok = cashupay_fire_cron_endpoint(8, ['only' => 'swaps']);
    if ($ok) {
        Config::set('wp_cron_selftest_ok_at', time());
    }
    return $ok;
}

/**
 * The every-minute WP-cron callback: run the complete cron.php task set via
 * loopback; fall back to the original inline quote poll when the loopback
 * fails, so Lightning settlement never regresses on hosts where self-requests
 * don't work.
 */
function cashupay_cron_poll(): void {
    $now = time();
    $retryAt = (int) get_option('cashupay_cron_loopback_retry_at', 0);
    if ($now >= $retryAt) {
        if (cashupay_fire_cron_endpoint(15)) {
            return;
        }
        update_option(
            'cashupay_cron_loopback_retry_at',
            $now + CASHUPAY_CRON_LOOPBACK_RETRY_SECONDS,
            false
        );
    }

    require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
    require_once CASHUPAY_PLUGIN_DIR . '/includes/invoice.php';

    if (Database::isInitialized() && Config::isSetupComplete()) {
        Invoice::pollPendingQuotes();
    }
}
