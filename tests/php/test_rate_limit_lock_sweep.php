<?php
/**
 * Security's file-based limiter keeps one `.lock` per identifier and, until
 * this fix, never removed any — every client address that ever touched a
 * rate-limited endpoint left an inode behind for good (an unauthenticated
 * inode-fill on shared hosts; once the directory could not grow, every
 * limiter failed closed). Pinned here:
 *
 *  - checkRateLimit() folds IPv6 identifiers to their /64, so one client
 *    cannot mint a fresh budget (and a fresh lock file) per address;
 *  - taking a lock stamps the file's mtime, and cleanCache() sweeps lock
 *    files idle for longer than LOCK_SWEEP_AGE while leaving fresh ones;
 *  - a swept lock is recreated transparently on the next request.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

$cacheDir = $dataDir . '/cache';

// ---- IPv6 /64 folding --------------------------------------------------------
assert_eq('2001:db8:1:2::/64', Security::foldIpIdentifier('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), '/64 fold');
assert_eq('203.0.113.9', Security::foldIpIdentifier('203.0.113.9'), 'IPv4 verbatim');
assert_eq('203.0.113.9', Security::foldIpIdentifier('::ffff:203.0.113.9'), 'IPv4-mapped -> embedded IPv4');
assert_eq('opaque-token', Security::foldIpIdentifier('opaque-token'), 'non-IP verbatim');

$granted = 0;
for ($i = 1; $i <= 4; $i++) {
    if (Security::checkRateLimit('t', "2001:db8:1:2::$i", 3)) {
        $granted++;
    }
}
assert_eq(3, $granted, 'four addresses in one /64 share a single 3-request budget');
assert_true(Security::checkRateLimit('t', '2001:db8:1:3::1', 3), 'a different /64 is unaffected');

$locksAfterIpv6 = count(glob($cacheDir . '/*.lock'));
assert_eq(2, $locksAfterIpv6, 'one lock file per /64, not per address');

// ---- Sweep ----------------------------------------------------------------------
assert_true(Security::checkRateLimit('t', '198.51.100.7', 60), 'ipv4 request');
$locks = glob($cacheDir . '/*.lock');
assert_eq(3, count($locks), 'third identifier, third lock file');

// A fresh lock survives the sweep.
Security::cleanCache();
assert_eq(3, count(glob($cacheDir . '/*.lock')), 'fresh locks are kept');

// Age every lock past the sweep threshold: all go.
foreach ($locks as $f) {
    touch($f, time() - Security::LOCK_SWEEP_AGE - 60);
}
Security::cleanCache();
assert_eq(0, count(glob($cacheDir . '/*.lock')), 'idle locks are swept');

// The limiter keeps working after a sweep (the lock is recreated), and
// acquisition re-stamps mtime so an active key is not swept again.
assert_true(Security::checkRateLimit('t', '198.51.100.7', 60), 'request after sweep');
$locks = glob($cacheDir . '/*.lock');
assert_eq(1, count($locks), 'lock recreated');
touch($locks[0], time() - Security::LOCK_SWEEP_AGE - 60);
Security::checkRateLimit('t', '198.51.100.7', 60);
clearstatcache();
assert_true(filemtime($locks[0]) > time() - 60, 'acquiring the lock refreshes its mtime');
Security::cleanCache();
assert_eq(1, count(glob($cacheDir . '/*.lock')), 'an active lock is not swept');

echo "ok\n";
