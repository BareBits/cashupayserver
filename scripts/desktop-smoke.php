<?php
/**
 * Functional smoke for the Windows desktop package: drives the app's real
 * HTTP surface end to end, the way a merchant's first launch does.
 *
 *   1. A fresh install redirects to the onboarding wizard (200 down the chain).
 *   2. The wizard walks its DESKTOP shape (terms straight to password, an
 *      8-step counter, no cron screen) to completion with mints declined —
 *      no payment rails, so the smoke needs no external services.
 *   3. The admin session works: login, CSRF token, dashboard lists the store.
 *   4. An API key can be minted and authenticates against /api/v1.
 *   5. Invoice creation on the rail-less store fails with the clean
 *      "no payment methods" validation error — the whole API stack (auth,
 *      routing, JSON handling, DB writes) proves out without needing a mint.
 *
 * Run by scripts/windows-smoke.ps1 against the real package in CI, and by
 * tests/e2e/test_desktop_smoke_driver.py against a Linux desktop-mode
 * instance so the driver itself cannot rot unnoticed between releases.
 *
 * Usage: php desktop-smoke.php <base-url>      (exit 0 = pass)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim((string) ($argv[1] ?? ''), '/');
if ($base === '' || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "usage: php desktop-smoke.php <base-url>\n");
    exit(2);
}

function fail(string $msg, string $body = ''): void {
    fwrite(STDERR, "desktop-smoke FAIL: $msg\n");
    if ($body !== '') {
        fwrite(STDERR, "---- last response body (first 800 bytes) ----\n"
            . substr($body, 0, 800) . "\n");
    }
    exit(1);
}

function ok(string $msg): void {
    echo "ok - $msg\n";
}

/**
 * One HTTP request on the shared handle. curl_reset() clears options but
 * keeps the handle's in-memory cookies, so the PHP session (wizard, admin)
 * carries across calls.
 *
 * @return array{0:int,1:string,2:string} [status, body, effective url]
 */
function request(
    CurlHandle $ch,
    string $method,
    string $url,
    ?array $form = null,
    array $headers = [],
    bool $follow = false,
    ?string $rawBody = null
): array {
    curl_reset($ch);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => '',      // enable the in-memory cookie engine
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fail("$method $url: " . curl_error($ch));
    }
    return [
        (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        (string) $body,
        (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
}

/** The screen's <h2>, entity-decoded, with the decorative leading emoji
 *  stripped (mirrors tests/fixtures/setup_helpers.py::wizard_heading). */
function heading(string $body): string {
    if (!preg_match('#<h2[^>]*>(.*?)</h2>#s', $body, $m)) {
        return '';
    }
    $t = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    while ($t !== '') {
        $first = mb_substr($t, 0, 1, 'UTF-8');
        if ($first !== ' ' && mb_ord($first, 'UTF-8') < 0x2000) {
            break;
        }
        $t = mb_substr($t, 1, null, 'UTF-8');
    }
    return trim($t);
}

/** The wizard's validation message, or null. It returns 200 with an error
 *  <div> rather than a 4xx, so this is how a failed step is detected. */
function wizard_error(string $body): ?string {
    if (preg_match('#<div class="error"(?:\s+id="setup-error")?>(.*?)</div>#s', $body, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return null;
}

$ch = curl_init();

// --- 1. Fresh install redirects to the wizard -------------------------------
[$status, $body, $effective] = request($ch, 'GET', $base . '/', null, [], true);
if ($status !== 200) {
    fail("GET / (following redirects) -> $status, expected 200", $body);
}
if (stripos($effective, 'setup') === false) {
    fail("expected the fresh-install redirect to land on the wizard, landed on $effective", $body);
}
$setupUrl = strtok($effective, '?');
ok("fresh install serves the onboarding wizard ($effective)");

$wpost = function (array $data) use ($ch, $setupUrl): string {
    [$status, $body] = request($ch, 'POST', $setupUrl, $data);
    $step = $data['step'] ?? '?';
    if ($status >= 400) {
        fail("wizard step '$step' -> $status", $body);
    }
    $err = wizard_error($body);
    if ($err !== null) {
        fail("wizard step '$step' rejected: $err");
    }
    return $body;
};

// --- 2. The wizard's desktop shape, to completion ---------------------------
$body = $wpost(['step' => 'terms', 'terms_legal' => '1', 'terms_warranty' => '1', 'terms_fee' => '1']);
if (heading($body) !== 'Create your admin password') {
    fail("desktop mode must go straight from terms to password, landed on '" . heading($body) . "'", $body);
}
if (strpos($body, 'of 8') === false) {
    fail('step counter must show the desktop count ("of 8")', $body);
}

$password = 'desktop-smoke-pw-1234';
$wpost(['step' => 'password', 'password' => $password, 'confirm_password' => $password]);
$wpost(['step' => 'store', 'store_name' => 'Smoke Store', 'default_currency' => 'sat']);
$wpost(['step' => 'onchain', 'onchain_action' => 'skip']);
$wpost(['step' => 'lightning', 'lightning_action' => 'skip']);
$wpost(['step' => 'swaps', 'swaps_enabled' => '0']);
$body = $wpost(['step' => 'mints', 'mints_enabled' => '0']);

if (heading($body) !== "You're all set!") {
    fail("declining mints must land on the completion screen, landed on '" . heading($body) . "'", $body);
}
if (strpos($body, 'Background jobs run automatically') === false) {
    fail('completion screen must carry the desktop background-jobs note', $body);
}
if (stripos($body, 'crontab') !== false) {
    fail('crontab instructions must not appear on a desktop install', $body);
}
ok('onboarding wizard completes in its desktop shape (no security or cron screens)');

// --- 3. Admin session -------------------------------------------------------
[$status, $body] = request($ch, 'POST', $base . '/admin', [
    'action' => 'login', 'username' => 'admin', 'password' => $password,
]);
$login = json_decode($body, true);
if ($status !== 200 || !is_array($login) || ($login['success'] ?? false) !== true) {
    fail("admin login failed ($status)", $body);
}
$csrf = (string) ($login['csrfToken'] ?? '');
if ($csrf === '') {
    [, $adminHtml] = request($ch, 'GET', $base . '/admin');
    if (!preg_match('/name="csrf-token"\s+content="([^"]+)"/', $adminHtml, $m)) {
        fail('no csrfToken in the login response and none in the admin page meta');
    }
    $csrf = $m[1];
}
ok('admin login succeeds and yields a CSRF token');

// --- 4. Dashboard + API key -------------------------------------------------
[$status, $body] = request($ch, 'GET', $base . '/admin?api=dashboard');
$dash = json_decode($body, true);
$stores = is_array($dash) ? ($dash['stores'] ?? []) : [];
if ($status !== 200 || count($stores) !== 1) {
    fail("dashboard must list exactly the one wizard-created store ($status)", $body);
}
if (($stores[0]['name'] ?? '') !== 'Smoke Store') {
    fail("store name mismatch: expected 'Smoke Store', got '" . ($stores[0]['name'] ?? '') . "'");
}
$storeId = (string) $stores[0]['id'];

[$status, $body] = request(
    $ch,
    'POST',
    $base . '/admin',
    ['action' => 'create_api_key', 'store_id' => $storeId, 'label' => 'desktop-smoke'],
    ['X-CSRF-Token: ' . $csrf]
);
$keyResp = json_decode($body, true) ?: [];
$apiKey = (string) ($keyResp['key'] ?? $keyResp['apiKey'] ?? $keyResp['token'] ?? '');
if ($status !== 200 || $apiKey === '') {
    fail("create_api_key failed ($status)", $body);
}
ok('dashboard lists the store; API key minted');

// --- 5. Greenfield API surface ----------------------------------------------
$auth = 'Authorization: token ' . $apiKey;

[$status, $body] = request($ch, 'GET', $base . '/api/v1/server/info', null, [$auth]);
if ($status !== 200 || !is_array(json_decode($body, true))) {
    fail("GET /api/v1/server/info -> $status, expected 200 JSON", $body);
}

// This store deliberately has no rails; creation must fail with the clean
// validation error, not a 500 — that path exercises auth, routing, the store
// lookup and the rail-selection logic.
[$status, $body] = request(
    $ch,
    'POST',
    $base . "/api/v1/stores/$storeId/invoices",
    null,
    [$auth, 'Content-Type: application/json'],
    false,
    json_encode(['amount' => '21', 'currency' => 'sat'])
);
$err = json_decode($body, true) ?: [];
if ($status !== 400 || ($err['code'] ?? '') !== 'invoice-error') {
    fail("rail-less invoice creation must 400 with code invoice-error, got $status", $body);
}
if (stripos((string) ($err['message'] ?? ''), 'no payment methods') === false) {
    fail('expected the "no payment methods" message, got: ' . $body);
}
ok('API auth works; rail-less invoice creation fails with the clean validation error');

echo "desktop-smoke: all checks passed\n";
