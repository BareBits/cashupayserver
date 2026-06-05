<?php
/**
 * cron.php integration test: Task 12 (updater) runs end-to-end inside the
 * real cron pipeline against a fixture release server, and reports
 * "update applied" in the JSON tasks summary.
 *
 * We're testing the wiring — that the require/call/setup-gate around the
 * updater is correct. The actual update logic is exercised by
 * test_updater_e2e.php; this one just makes sure cron.php hits it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

// Stand up fixture release.
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => 'cronsha-' . str_repeat('b', 35),
    'CHANNEL' => 'main',
    'VERSION' => '0.3-new',
    'HTACCESS_SHA256' => hash('sha256', ''),
], [
    'admin.php' => 'NEW_ADMIN_FROM_CRON',
]);

Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Updater::$autoUpdateEnabledOverride = true;
Config::set('update_channel', 'main');
Config::set('updater_last_check', 0);

// Fake $_SERVER state so cron.php's external-request branch runs without
// the internal-key gate.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cron.php';
$_GET = [];

// Run cron.php in-process. It echoes JSON.
ob_start();
require dirname(__DIR__, 2) . '/cron.php';
$out = ob_get_clean();

// Output starts with the JSON (cron.php sets a Content-Type header which
// is silently ignored in CLI).
$decoded = json_decode($out, true);
assert_true(is_array($decoded), 'cron.php output is JSON');
assert_true(isset($decoded['tasks']), 'tasks array present');
assert_true(isset($decoded['tasks']['updater']), 'updater task present');
assert_eq('update applied', $decoded['tasks']['updater'], 'updater task reported applied');

// And the install was actually overlaid.
assert_eq('NEW_ADMIN_FROM_CRON', file_get_contents($fixture['installRoot'] . '/admin.php'));
$info = Updater::getLocalBuildInfo();
assert_eq('cronsha-' . str_repeat('b', 35), $info['COMMIT_SHA'], 'BUILD_INFO advanced');

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;
Updater::$autoUpdateEnabledOverride = null;

echo "ok\n";
