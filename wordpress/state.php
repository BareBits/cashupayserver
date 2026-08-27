<?php
/**
 * BareBits plugin — configuration state and the HTTP client for talking to
 * the BareBits server.
 *
 * Everything the plugin knows lives in WordPress options with the cashupay_
 * prefix; the BareBits server is only ever reached over HTTP with the
 * BTCPay-compatible `Authorization: token …` scheme. License: GPLv2 or later.
 *
 * Options:
 *   cashupay_mode              'url' (existing server) | 'install' (alongside)
 *   cashupay_server_url        BareBits base URL (the BTCPay "server URL")
 *   cashupay_store_id          store id on that server
 *   cashupay_install_dir       install mode: absolute path of the install
 *   cashupay_install_url       install mode: the alongside install's own base
 *                              URL — survives mode changes so the cron
 *                              heartbeat can keep finding the install
 *   cashupay_install_data_dir  install mode: absolute path of the data dir
 *   cashupay_provision_token   install mode: one-time token (deleted on use)
 *   cashupay_admin_password    install mode: the BareBits admin password the
 *                              installer generated (account pre-seeded from
 *                              its hash; revealable on the Connection page)
 *   cashupay_sso_key           install mode: key that mints one-time BareBits
 *                              sign-in tokens (see cashupay_sso_login_url)
 *   cashupay_cron_key          install mode: key for the WP-cron pinger
 *   cashupay_cron_last_ok      install mode: unix ts of the last successful
 *                              cron ping (drives the stale-heartbeat notice)
 *   cashupay_wired_at          unix ts when WooCommerce wiring completed
 *   cashupay_discount_percent  merchant's checkout discount answer (int)
 *   cashupay_pairing_expected  unix ts while a pairing redirect is in flight
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Chosen onboarding mode: 'url', 'install', or '' while undecided. */
function cashupay_mode(): string {
    $mode = (string) get_option('cashupay_mode', '');
    return in_array($mode, ['url', 'install'], true) ? $mode : '';
}

/** The BareBits server base URL (no trailing slash), or '' if not set yet. */
function cashupay_server_url(): string {
    return rtrim((string) get_option('cashupay_server_url', ''), '/');
}

/**
 * The base URL of the alongside install this plugin provisioned (no trailing
 * slash), or '' when none exists. Deliberately distinct from
 * cashupay_server_url: the CONNECTED server can change — "Start over", then
 * reconnecting the install by URL, or connecting some other server entirely —
 * while the install this plugin promised a cron heartbeat to (it was
 * provisioned with its own cron screen skipped) keeps running at its own
 * address. Installs recorded before this option existed are backfilled from
 * the connected URL while the plugin is still in install mode, when the two
 * are the same thing by construction.
 */
function cashupay_install_url(): string {
    if ((string) get_option('cashupay_install_dir', '') === '') {
        return '';
    }
    $url = rtrim((string) get_option('cashupay_install_url', ''), '/');
    if ($url !== '') {
        return $url;
    }
    // Backfill for installs recorded before this option existed. In install
    // mode the connected server IS the install, so the connected URL is
    // proven and worth persisting. After an old-code reset (mode '') the
    // surviving connected URL is still the best available answer, but only
    // install mode proves it — return it without persisting the guess. In
    // URL mode the connected server may be a different host entirely.
    if (cashupay_mode() === 'install') {
        $url = cashupay_server_url();
        if ($url !== '') {
            update_option('cashupay_install_url', $url, false);
        }
        return $url;
    }
    return cashupay_mode() === '' ? cashupay_server_url() : '';
}

/** Whether onboarding finished: a server is connected and WooCommerce wired. */
function cashupay_is_configured(): bool {
    return cashupay_server_url() !== '' && (int) get_option('cashupay_wired_at', 0) > 0;
}

/**
 * Whether $url points at this WordPress site's own origin — scheme, host,
 * AND port. Same-origin requests (the alongside install, a loopback cron
 * ping) skip TLS peer verification the same way WordPress core's own
 * loopbacks do — staging boxes with self-signed certificates would
 * otherwise break themselves. The comparison is deliberately the full
 * origin, not the hostname alone: a different service on another port of
 * the same host is NOT this site and gets verified like any remote server.
 */
function cashupay_is_same_host_url(string $url): bool {
    $target = parse_url($url);
    $self = parse_url(site_url('/'));
    if (!is_array($target) || !is_array($self) || empty($target['host']) || empty($self['host'])) {
        return false;
    }
    $port = static function (array $parts): int {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    };
    return strtolower($target['host']) === strtolower($self['host'])
        && strtolower((string) ($target['scheme'] ?? '')) === strtolower((string) ($self['scheme'] ?? ''))
        && $port($target) === $port($self);
}

/**
 * Probe a candidate BareBits server URL: fetch {url}/api/v1/server/info and
 * require the isCashuPayServer marker. Returns ['ok' => true, 'version' => …]
 * or ['ok' => false, 'message' => operator-facing reason].
 */
function cashupay_probe_server(string $url): array {
    $url = rtrim(trim($url), '/');
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'message' => 'Enter the full URL, starting with https:// (or http:// for a local server).'];
    }
    $response = wp_remote_get($url . '/api/v1/server/info', [
        'timeout' => 10,
        'redirection' => 3,
        'sslverify' => !cashupay_is_same_host_url($url),
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'message' => 'Could not reach the server: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($body) || empty($body['isCashuPayServer'])) {
        return ['ok' => false, 'message' => 'That URL answered, but it does not look like a BareBits server (HTTP ' . $code . ').'];
    }
    return ['ok' => true, 'version' => (string) ($body['version'] ?? '')];
}

/**
 * Mint a one-time BareBits admin sign-in URL through the install's SSO
 * handoff (install mode only — the plugin holds the SSO key it provisioned).
 * Returns the URL to send the browser/iframe to, or null when SSO isn't
 * available (URL mode, setup not finished, install unreachable) — callers
 * fall back to the plain admin URL, where BareBits shows its own login.
 */
function cashupay_sso_login_url(): ?string {
    $server = cashupay_server_url();
    $ssoKey = (string) get_option('cashupay_sso_key', '');
    if ($server === '' || $ssoKey === '') {
        return null;
    }
    $response = wp_remote_post($server . '/sso.php', [
        'timeout' => 10,
        'sslverify' => !cashupay_is_same_host_url($server),
        'headers' => ['X-SSO-KEY' => $ssoKey],
    ]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || ($body['status'] ?? '') !== 'ready' || empty($body['token'])) {
        return null;
    }
    return $server . '/sso.php?token=' . rawurlencode((string) $body['token']);
}

/**
 * Authenticated request against the configured BareBits server's Greenfield
 * API. $path is relative to the API base (e.g. '/api/v1/stores/x/webhooks').
 * Returns ['code' => int, 'body' => decoded array|null, 'error' => string|null].
 */
function cashupay_api_request(string $method, string $path, ?array $body = null, ?string $apiKey = null): array {
    $server = cashupay_server_url();
    if ($server === '') {
        return ['code' => 0, 'body' => null, 'error' => 'No BareBits server configured.'];
    }
    if ($apiKey === null) {
        $apiKey = (string) get_option('btcpay_gf_api_key', '');
    }
    $args = [
        'method' => $method,
        'timeout' => 15,
        'redirection' => 2,
        'sslverify' => !cashupay_is_same_host_url($server),
        'headers' => [
            'Authorization' => 'token ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
    ];
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $response = wp_remote_request($server . $path, $args);
    if (is_wp_error($response)) {
        return ['code' => 0, 'body' => null, 'error' => $response->get_error_message()];
    }
    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => json_decode((string) wp_remote_retrieve_body($response), true),
        'error' => null,
    ];
}
