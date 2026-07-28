<?php
/**
 * The admin settings footer's "Check for updates" link must point at the same
 * GitHub repo the in-app updater actually pulls from (Updater::GH_OWNER/GH_REPO)
 * — not the upstream jooray fork. A stale link sends operators to releases that
 * don't match the build they're running.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/includes/updater.php';

$admin = file_get_contents(dirname(__DIR__, 2) . '/admin.php');
assert_true($admin !== false, 'admin.php readable');

// Locate the "Check for updates" anchor and pull its href.
assert_true(
    (bool) preg_match(
        '/href="(https:\/\/github\.com\/[^"]+\/releases)"[^>]*>\s*Check for updates/i',
        $admin,
        $m
    ),
    'found the "Check for updates" release link'
);

$expected = sprintf(
    'https://github.com/%s/%s/releases',
    Updater::GH_OWNER,
    Updater::GH_REPO
);
assert_eq($expected, $m[1], 'link targets the updater\'s canonical repo');
assert_false(stripos($m[1], 'jooray') !== false, 'link does not point at the upstream fork');

echo "test_check_for_updates_link: ok\n";
