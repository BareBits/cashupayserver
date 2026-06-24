<?php
/**
 * Updater::checkForUpdate() — the always-on "is a newer build available?"
 * probe that powers the dashboard update banner + the Auto-update card.
 *
 * Contract under test:
 *   - Detects a newer remote COMMIT_SHA as available, WITHOUT the auto-update
 *     opt-in (the banner must nudge operators who haven't enabled auto-update).
 *   - Reports "not available" when local == remote.
 *   - Never advertises a blocked SHA (one that failed a prior health check).
 *   - Caches the verdict and self-throttles (no refetch within the interval).
 *   - Is a no-op under the test/dev kill switch (never phones GitHub there).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require __DIR__ . '/updater_fixture.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

$remoteSha = 'newsha-' . str_repeat('a', 35);
$fixture = updater_fixture_start('main', [
    'COMMIT_SHA' => $remoteSha,
    'CHANNEL' => 'main',
    'VERSION' => '0.2-new',
], [
    'admin.php' => 'NEW_ADMIN',
]);

Updater::$installRootOverride = $fixture['installRoot'];
Updater::$releaseApiUrlBase = $fixture['baseUrl'];
Config::set('update_channel', 'main');

// Deliberately leave the auto-update opt-in OFF (the default). checkForUpdate
// must still work — that's the whole point of the banner.
assert_false(Updater::isAutoUpdateEnabled(), 'auto-update opt-in is off by default');

// --- 1. A newer build is detected as available ---
$res = Updater::checkForUpdate(true);
assert_true($res['available'], 'newer remote SHA reported available');
assert_eq($remoteSha, $res['latest_sha'], 'latest_sha is the remote SHA');
assert_eq('0.2-new', $res['latest_version'], 'latest_version is the remote version');
assert_eq('0.0-old', $res['current_version'], 'current_version is the local version');
assert_false((bool)$res['blocked'], 'not blocked');

// Cached verdict is readable without a refetch.
$cached = Updater::getAvailableUpdate();
assert_true(is_array($cached) && $cached['available'] === true, 'verdict cached');

// --- 2. Caching / self-throttle: a non-forced call returns the cache even if
// the install has since advanced past the remote SHA. ---
file_put_contents($fixture['installRoot'] . '/BUILD_INFO',
    "COMMIT_SHA=$remoteSha\nVERSION=0.2-new\n");
$res2 = Updater::checkForUpdate(false); // within the daily window → cached
assert_true($res2['available'], 'non-forced call served stale cache (still available)');

// Forced re-check now sees local == remote → not available.
$res3 = Updater::checkForUpdate(true);
assert_false($res3['available'], 'forced re-check: local == remote → not available');

// --- 3. Blocked SHA is never advertised ---
// Roll the local install back to the "old" SHA so the remote looks newer again,
// but mark the remote SHA blocked (it failed a prior health check).
file_put_contents($fixture['installRoot'] . '/BUILD_INFO',
    "COMMIT_SHA=0000000000000000000000000000000000000000\nVERSION=0.0-old\n");
Updater::blockSha($remoteSha);
$res4 = Updater::checkForUpdate(true);
assert_false($res4['available'], 'blocked remote SHA is not advertised as available');
assert_true((bool)$res4['blocked'], 'blocked flag set');
Config::set('updater_blocked_shas', []); // unblock for the next step

// --- 4. Test/dev kill switch makes it a no-op (no fetch) ---
// Seed a known cache value, then drop the sentinel and confirm a forced call
// does NOT overwrite it (it returns early without touching GitHub).
Config::set('updater_available', ['available' => true, 'sentinel' => 'KEEP']);
$sentinel = CASHUPAY_DATA_DIR . '/.updater_disabled';
file_put_contents($sentinel, '1');
$res5 = Updater::checkForUpdate(true);
assert_true(isset($res5['sentinel']) && $res5['sentinel'] === 'KEEP',
    'kill switch: forced check is a no-op, cache untouched');
@unlink($sentinel);

Updater::$installRootOverride = null;
Updater::$releaseApiUrlBase = null;

echo "test_updater_check_for_update: ok\n";
