<?php
/**
 * WordPress API bridge (wordpress/api-bridge.php) — request matching.
 *
 * On rewrite-hostile hosts the alongside install's /api/v1 URLs fall through
 * the web server into WordPress, and the bridge replays them against the
 * install's api.php. cashupay_api_bridge_path is the pure gatekeeper: it must
 * claim exactly the install's API namespace — both the /api/v1 form and the
 * BTCPay-compatible /v1 alias, at any install depth — and nothing else, since
 * a false match would swallow a real WordPress page and a missed match leaves
 * the API dead on the very hosts the bridge exists for. The live proxying is
 * driven end to end by the hostile-host browser journey test.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', '/tmp/');
function add_action($hook, $callable) {}

require __DIR__ . '/wp_compat_stubs.php';
require dirname(__DIR__, 2) . '/wordpress/api-bridge.php';

// --- No install, nothing claimed --------------------------------------------
assert_eq(null, cashupay_api_bridge_path('/barebits/api/v1/server/info', ''),
    'no install URL claims nothing');

$install = 'http://wp.test/barebits';

// --- The install's API namespace is claimed, verbatim ------------------------
assert_eq('/api/v1/server/info',
    cashupay_api_bridge_path('/barebits/api/v1/server/info', $install),
    'the Greenfield path under the install is claimed');
assert_eq('/api/v1/stores/abc/invoices',
    cashupay_api_bridge_path('/barebits/api/v1/stores/abc/invoices', $install),
    'deep API paths are claimed');
assert_eq('/v1/server/info',
    cashupay_api_bridge_path('/barebits/v1/server/info', $install),
    'the BTCPay-compatible /v1 alias is claimed, unrewritten (api.php normalizes it)');

// A trailing slash on the recorded install URL never changes the match.
assert_eq('/api/v1/server/info',
    cashupay_api_bridge_path('/barebits/api/v1/server/info', $install . '/'),
    'a trailing slash on the install URL is normalized');

// Installs deeper than one segment (WordPress in a subdirectory, or the
// wp-content fallback target) match on their full path.
assert_eq('/api/v1/server/info',
    cashupay_api_bridge_path('/wp-content/barebits/api/v1/server/info', 'http://wp.test/wp-content/barebits'),
    'a nested install path is honored');
assert_eq(null,
    cashupay_api_bridge_path('/barebits/api/v1/server/info', 'http://wp.test/wp-content/barebits'),
    'and only its full path matches');

// --- Everything else stays WordPress's ---------------------------------------
assert_eq(null, cashupay_api_bridge_path('/barebits/payment.php', $install),
    'non-API install URLs are not claimed');
assert_eq(null, cashupay_api_bridge_path('/barebits/setup.php', $install),
    'the wizard is not claimed');
assert_eq(null, cashupay_api_bridge_path('/barebits/api/v2/thing', $install),
    'unknown API versions are not claimed');
assert_eq(null, cashupay_api_bridge_path('/api/v1/server/info', $install),
    'the site root API path (not under the install) is not claimed');
assert_eq(null, cashupay_api_bridge_path('/barebits-blog/api/v1/x', $install),
    'a prefix-similar sibling path is not claimed');
assert_eq(null, cashupay_api_bridge_path('/barebits', $install),
    'the bare install URL is not claimed');
assert_eq(null, cashupay_api_bridge_path('/barebits/api/v1', $install),
    'the namespace root without a trailing segment is not claimed');
// An install URL with no path at all can never happen (the installer always
// appends a directory segment) — but if it ever did, claiming every /api/v1
// URL on the site would be the wrong failure mode.
assert_eq(null, cashupay_api_bridge_path('/api/v1/server/info', 'http://wp.test'),
    'a pathless install URL claims nothing');

// --- Authorization header recovery -------------------------------------------
$_SERVER['HTTP_AUTHORIZATION'] = 'token abc';
assert_eq('token abc', cashupay_api_bridge_authorization(), 'HTTP_AUTHORIZATION is used');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'token xyz';
assert_eq('token xyz', cashupay_api_bridge_authorization(), 'the REDIRECT_ variant is the fallback');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
assert_eq('', cashupay_api_bridge_authorization(), 'no header means empty, never null');

echo "test_wp_api_bridge: ok\n";
