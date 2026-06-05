<?php
/**
 * Operator opt-in gate for the auto-updater. checkAndApply() must return
 * false when neither CASHUPAY_AUTO_UPDATE_ENABLED constant, env var, nor
 * the test-override hook is set — even when a remote update is available.
 *
 * The companion e2e test (test_updater_e2e) flips the override on to
 * exercise the apply path; this test verifies the default-off behavior
 * that gate guarantees.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => 'gatesha-' . str_repeat('c', 35),
    'CHANNEL' => 'main',
    'VERSION' => '0.4-new',
    'HTACCESS_SHA256' => hash('sha256', ''),
], [
    'admin.php' => 'NEW_ADMIN_GATED_OUT',
]);

Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Config::set('update_channel', 'main');
Config::set('updater_last_check', 0);

// Default state: override is null, constant undefined, env unset. Gate is
// closed; checkAndApply must return false and the install root must be
// untouched (still "OLD_ADMIN" from the fixture's initial overlay).
assert_eq(false, Updater::isAutoUpdateEnabled(), 'default is off');
$applied = Updater::checkAndApply();
assert_eq(false, $applied, 'gate-closed checkAndApply returns false');
assert_eq('OLD_ADMIN', file_get_contents($fixture['installRoot'] . '/admin.php'),
    'install root not overlaid when gate closed');

// Env var opens the gate.
putenv('CASHUPAY_AUTO_UPDATE_ENABLED=1');
assert_eq(true, Updater::isAutoUpdateEnabled(), 'env var opens gate');
putenv('CASHUPAY_AUTO_UPDATE_ENABLED');  // unset

// Env var = "0" is treated as off (matches the existing
// CASHUPAY_UPDATER_DISABLED convention).
putenv('CASHUPAY_AUTO_UPDATE_ENABLED=0');
assert_eq(false, Updater::isAutoUpdateEnabled(), 'env var "0" is off');
putenv('CASHUPAY_AUTO_UPDATE_ENABLED');

// Test-override hook takes precedence over both constant and env.
Updater::$autoUpdateEnabledOverride = true;
assert_eq(true, Updater::isAutoUpdateEnabled(), 'override on');
Updater::$autoUpdateEnabledOverride = false;
assert_eq(false, Updater::isAutoUpdateEnabled(), 'override off');
Updater::$autoUpdateEnabledOverride = null;

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;

echo "test_updater_auto_update_gate: ok\n";
