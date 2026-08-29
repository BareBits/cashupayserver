<?php
/**
 * Regression: a data directory that already exists when the app first connects
 * must still receive its web-facing protections (deny-all .htaccess + 403
 * index.php). Database::createDataDirectory used to be the only writer, so any
 * pre-created dir — docker/entrypoint-production.sh's `mkdir -p data`, the
 * pytest fixtures' mkdir, an operator's own mkdir — shipped a downloadable
 * cashupay.sqlite on Apache hosts (nginx installs are covered by the deny
 * rules in docker/nginx-site.conf instead).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$dir = fresh_db();  // mkdirs the data dir itself, then connects — the pre-created path

assert_true(is_file($dir . '/.htaccess'), 'pre-created data dir got .htaccess on first connect');
assert_true(
    strpos((string) file_get_contents($dir . '/.htaccess'), 'Require all denied') !== false,
    'data dir .htaccess carries the deny-all rule'
);
assert_true(is_file($dir . '/index.php'), 'pre-created data dir got the 403 index.php');

// Idempotence: an operator-customized .htaccess is never overwritten.
file_put_contents($dir . '/.htaccess', "# operator customized\n");
Database::ensureDataDirectoryProtections($dir);
assert_eq(
    "# operator customized\n",
    file_get_contents($dir . '/.htaccess'),
    'existing .htaccess left untouched'
);

// A missing index.php is restored without touching the customized .htaccess.
unlink($dir . '/index.php');
Database::ensureDataDirectoryProtections($dir);
assert_true(is_file($dir . '/index.php'), 'missing index.php restored');
assert_eq(
    "# operator customized\n",
    file_get_contents($dir . '/.htaccess'),
    '.htaccess still untouched after index.php restore'
);

echo "ok\n";
