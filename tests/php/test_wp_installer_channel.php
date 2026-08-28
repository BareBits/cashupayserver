<?php
/**
 * WordPress installer (wordpress/installer.php) — release-channel selection
 * and the post-install API-routing probe.
 *
 * The install-alongside flow originally read GitHub's /releases/latest, which
 * only ever answers with the newest STABLE release — so a testing-channel
 * plugin deployed a stable server missing the whole managed-install feature
 * set (the provisioning handshake above all) and walked the merchant into a
 * wizard that asked for a password and an onboarding that could never finish.
 * This file pins the channel logic that prevents that: the stable channel
 * keeps /releases/latest, the testing channel reads the head of /releases
 * (newest release of any kind, drafts skipped), and the channel value itself
 * is sanitized. Plus cashupay_install_api_routes_ok, the probe that tells a
 * BareBits JSON answer apart from a WordPress "page not found" swallowing
 * the install's /api/v1 route.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/cashupay_channel_' . bin2hex(random_bytes(6)) . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

// --- minimal WordPress stubs -------------------------------------------------
$GLOBALS['filters'] = [];                // hook => forced return value
$GLOBALS['http_routes'] = [];            // url-substring => ['code'=>…, 'body'=>…] | 'error'
$GLOBALS['http_log'] = [];               // every wp_remote_get url

function apply_filters($hook, $value) {
    return array_key_exists($hook, $GLOBALS['filters']) ? $GLOBALS['filters'][$hook] : $value;
}
function get_option($name, $default = false) { return $default; }
function site_url($path = '') { return 'http://wp.test' . $path; }

class WP_Error {
    public function __construct(private string $msg = 'stub error') {}
    public function get_error_message(): string { return $this->msg; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_get($url, $args = []) {
    $GLOBALS['http_log'][] = $url;
    foreach ($GLOBALS['http_routes'] as $needle => $response) {
        if (str_contains($url, $needle)) {
            return $response === 'error' ? new WP_Error('unreachable') : $response;
        }
    }
    return new WP_Error('no stub route for ' . $url);
}
function wp_remote_retrieve_response_code($response) { return $response['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

// installer.php calls this (defined in state.php in the real plugin).
function cashupay_is_same_host_url(string $url): bool { return true; }

require dirname(__DIR__, 2) . '/wordpress/installer.php';

// =============================================================================
// cashupay_release_channel — default, override, sanitization
// =============================================================================

assert_eq('stable', cashupay_release_channel(), 'the in-tree default channel is stable');

$GLOBALS['filters']['cashupay_release_channel'] = 'testing';
assert_eq('testing', cashupay_release_channel(), 'the filter can put a site on the testing channel');

$GLOBALS['filters']['cashupay_release_channel'] = 'nightly-of-doom';
assert_eq('stable', cashupay_release_channel(), 'anything but "testing" collapses to stable');
unset($GLOBALS['filters']['cashupay_release_channel']);

// =============================================================================
// cashupay_fetch_latest_release — stable reads /releases/latest
// =============================================================================

$assets = [
    ['name' => 'SHA256SUMS', 'browser_download_url' => 'http://releases.test/dl/SHA256SUMS'],
    ['name' => 'barebits-v9.9.zip', 'browser_download_url' => 'http://releases.test/dl/barebits.zip'],
];

$GLOBALS['http_routes'] = ['/releases/latest' => [
    'code' => 200,
    'body' => json_encode(['tag_name' => 'v9.9', 'assets' => $assets]),
]];
$GLOBALS['http_log'] = [];
$r = cashupay_fetch_latest_release();
assert_eq(true, $r['ok'], 'stable channel resolves the latest stable release');
assert_eq('v9.9', $r['tag'], 'with its tag');
assert_eq('barebits-v9.9.zip', $r['zip_name'], 'and the standalone zip');
assert_eq(1, count($GLOBALS['http_log']), 'one API call');
assert_true(str_contains($GLOBALS['http_log'][0], '/releases/latest'), 'to /releases/latest');
assert_false(str_contains($GLOBALS['http_log'][0], '/releases?'), 'never the prerelease listing');

// =============================================================================
// cashupay_fetch_latest_release — testing reads the head of /releases
// =============================================================================

$GLOBALS['filters']['cashupay_release_channel'] = 'testing';

// Newest-first listing: a draft (never installable) ahead of the prerelease
// the testing channel must pick, with an older stable behind it.
$GLOBALS['http_routes'] = ['/releases?' => [
    'code' => 200,
    'body' => json_encode([
        ['tag_name' => 'v9.10-draft', 'draft' => true, 'prerelease' => true, 'assets' => $assets],
        ['tag_name' => 'v9.9-testing.7', 'draft' => false, 'prerelease' => true, 'assets' => [
            ['name' => 'SHA256SUMS', 'browser_download_url' => 'http://releases.test/dl/SHA256SUMS'],
            ['name' => 'barebits-v9.9-testing.7.zip', 'browser_download_url' => 'http://releases.test/dl/t.zip'],
        ]],
        ['tag_name' => 'v9.8', 'draft' => false, 'prerelease' => false, 'assets' => $assets],
    ]),
]];
$GLOBALS['http_log'] = [];
$r = cashupay_fetch_latest_release();
assert_eq(true, $r['ok'], 'testing channel resolves a release');
assert_eq('v9.9-testing.7', $r['tag'], 'the newest NON-DRAFT release, prereleases included');
assert_eq('barebits-v9.9-testing.7.zip', $r['zip_name'], 'with its standalone zip');
assert_true(str_contains($GLOBALS['http_log'][0], '/releases?'), 'read from the /releases listing');

// An empty listing (or one of drafts only) is a clean channel-specific error.
$GLOBALS['http_routes'] = ['/releases?' => ['code' => 200, 'body' => json_encode([])]];
$r = cashupay_fetch_latest_release();
assert_eq(false, $r['ok'], 'an empty listing fails cleanly');
assert_true(str_contains($r['message'], 'testing-channel release'), 'naming the channel');

unset($GLOBALS['filters']['cashupay_release_channel']);

// =============================================================================
// cashupay_install_api_routes_ok — BareBits JSON vs a WordPress 404
// =============================================================================

// Fresh install, wizard not run: api.php answers 503 with a JSON body.
$GLOBALS['http_routes'] = ['/api/v1/server/info' => [
    'code' => 503,
    'body' => json_encode(['code' => 'service-unavailable', 'message' => 'BareBits setup not complete']),
]];
assert_eq(true, cashupay_install_api_routes_ok('http://wp.test/barebits'),
    'a pre-setup 503 JSON answer proves the route reaches BareBits');

// Set-up install: 200 JSON.
$GLOBALS['http_routes'] = ['/api/v1/server/info' => ['code' => 200, 'body' => json_encode(['version' => '9.9'])]];
assert_eq(true, cashupay_install_api_routes_ok('http://wp.test/barebits'), 'a 200 JSON answer counts too');

// A host that ignores .htaccess: WordPress swallows the path with its themed
// 404 page (HTML, status 404).
$GLOBALS['http_routes'] = ['/api/v1/server/info' => ['code' => 404, 'body' => '<html><body>Page not found</body></html>']];
assert_eq(false, cashupay_install_api_routes_ok('http://wp.test/barebits'), 'a WordPress 404 is not routing');

// A redirect-404s-home plugin: HTML with a 200. Still not BareBits.
$GLOBALS['http_routes'] = ['/api/v1/server/info' => ['code' => 200, 'body' => '<html><body>Welcome to the shop</body></html>']];
assert_eq(false, cashupay_install_api_routes_ok('http://wp.test/barebits'),
    'a 200 HTML page (404-to-home redirect plugins) is not routing either');

// Unreachable — no false alarm suppression, the probe just reports false.
$GLOBALS['http_routes'] = ['/api/v1/server/info' => 'error'];
assert_eq(false, cashupay_install_api_routes_ok('http://wp.test/barebits'), 'an unreachable install probes false');

echo "test_wp_installer_channel: ok\n";
