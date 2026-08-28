<?php
/**
 * BareBits Windows desktop — CLI cron runner.
 *
 * The desktop package serves the app with PHP's built-in web server, which on
 * Windows is strictly single-threaded — background work can't be left to the
 * opportunistic HTTP self-requests alone. desktop-helper.php spawns this
 * script on a timer instead; it authenticates to cron.php the same way an
 * operator's external cron would (cron_key, seeded by Database::initialize),
 * so a desktop install shows up as "external cron wired up" in the admin
 * dashboard rather than warning forever.
 *
 * Runs cron.php IN-PROCESS (no HTTP round-trip), so it works even while the
 * single-threaded web server is busy with a checkout.
 *
 * Usage: php cron-runner.php [only]
 *   only — optional cron.php mode filter (e.g. "swaps" for the fast lane).
 *
 * Env:
 *   CASHUPAY_DESKTOP_APP_DIR — app directory override (tests; default ./app)
 *   CASHUPAY_DATA_DIR        — forwarded to the CASHUPAY_DATA_DIR constant
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = getenv('CASHUPAY_DESKTOP_APP_DIR') ?: __DIR__ . '/app';
if (!is_file($app . '/cron.php')) {
    fwrite(STDERR, "cron-runner: no app at $app\n");
    exit(1);
}
chdir($app);

// The launcher relocates nothing by default (data lives in app/data), but the
// data dir is overridable the same way database.php documents — via constant.
$dataDir = getenv('CASHUPAY_DATA_DIR');
if ($dataDir !== false && $dataDir !== '' && !defined('CASHUPAY_DATA_DIR')) {
    define('CASHUPAY_DATA_DIR', $dataDir);
}

require_once $app . '/includes/database.php';
require_once $app . '/includes/config.php';

if (!Database::isInitialized() || !Config::isSetupComplete()) {
    fwrite(STDERR, "cron-runner: setup not complete yet, skipping\n");
    exit(0);
}

$key = Config::get('cron_key');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, "cron-runner: no cron_key configured, skipping\n");
    exit(0);
}

// cron.php's external-cron auth path reads these superglobals; in the CLI
// SAPI they exist but are empty, so this is the whole "request".
$_GET['key'] = $key;
if (isset($argv[1]) && $argv[1] !== '') {
    $_GET['only'] = $argv[1];
}

require $app . '/cron.php';
