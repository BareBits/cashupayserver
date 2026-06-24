<?php
/**
 * Updater::checkAndApply(true) — the manual ("Update now") path.
 *
 * A manual run is the operator-initiated path: the click is the consent, so it
 * bypasses the CASHUPAY_AUTO_UPDATE_ENABLED opt-in and the daily throttle. It
 * must STILL honour the other guards: the test/dev kill switch and the
 * blocked-SHA forward-failure list. This test pins all four behaviours.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

$remoteSha = 'forcesha-' . str_repeat('b', 34);
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => $remoteSha,
    'CHANNEL' => 'main',
    'VERSION' => '0.2-new',
], [
    'admin.php' => 'NEW_ADMIN',
]);

$root = $fixture['installRoot'];
Updater::$installRootOverride = $root;
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Config::set('update_channel', 'main');

// Auto-update opt-in stays OFF for the whole test (default).
assert_false(Updater::isAutoUpdateEnabled(), 'opt-in off');

// --- 1. Non-forced apply is gated off by the opt-in ---
Config::set('updater_last_check', 0);
assert_false(Updater::checkAndApply(false), 'auto checkAndApply no-ops while opt-in off');
assert_eq('OLD_ADMIN', file_get_contents($root . '/admin.php'), 'install untouched (auto path)');

// --- 2. Forced apply still skips a blocked SHA ---
Updater::blockSha($remoteSha);
Config::set('updater_last_check', 0);
assert_false(Updater::checkAndApply(true), 'forced apply skips blocked SHA');
assert_eq('OLD_ADMIN', file_get_contents($root . '/admin.php'), 'install untouched (blocked)');
Config::set('updater_blocked_shas', []); // unblock

// --- 3. Forced apply still honours the test/dev kill switch ---
$sentinel = CASHUPAY_DATA_DIR . '/.updater_disabled';
file_put_contents($sentinel, '1');
Config::set('updater_last_check', 0);
assert_false(Updater::checkAndApply(true), 'forced apply blocked by kill switch');
assert_eq('OLD_ADMIN', file_get_contents($root . '/admin.php'), 'install untouched (kill switch)');
@unlink($sentinel);

// --- 4. Forced apply with no blockers applies even though opt-in is OFF ---
Config::set('updater_last_check', 0);
assert_true(Updater::checkAndApply(true), 'forced apply runs with opt-in off');
assert_eq('NEW_ADMIN', file_get_contents($root . '/admin.php'), 'install overlaid by manual update');

$info = Updater::getLocalBuildInfo();
assert_eq($remoteSha, $info['COMMIT_SHA'], 'BUILD_INFO advanced');

// Data preserved, backup + pending-verify marker written just like the auto path.
assert_eq('preserve_me', file_get_contents($root . '/data/MARKER'), 'data preserved');
assert_eq(1, count(Updater::listBackups()), 'backup created');
$pending = Updater::getPendingVerify();
assert_true(is_array($pending) && $pending['sha'] === $remoteSha, 'pending-verify marker set');

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;

echo "test_updater_force_apply: ok\n";
