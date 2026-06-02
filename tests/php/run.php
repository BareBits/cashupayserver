<?php
/**
 * Runs every test_*.php in this directory in its own PHP subprocess, prints
 * a summary, and exits non-zero if any case failed. The subprocess isolation
 * lets each case freely define CASHUPAY_DATA_DIR without conflicting.
 *
 * Usage:
 *   /path/to/php tests/php/run.php
 */

declare(strict_types=1);

$dir = __DIR__;
$files = glob($dir . '/test_*.php');
// Sibling suites: include the swap/crypto self-tests in the same single command.
$files = array_merge(
    $files,
    glob(dirname($dir) . '/crypto/test_*.php') ?: [],
    glob(dirname($dir) . '/swap/test_*.php') ?: []
);
sort($files);

if (empty($files)) {
    fwrite(STDERR, "No tests found in $dir\n");
    exit(1);
}

$phpBin = PHP_BINARY;
$passed = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    $name = basename($file, '.php');
    $out = [];
    $rc = 0;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    if ($rc === 0) {
        $passed++;
        fwrite(STDERR, "  ok  $name\n");
    } else {
        $failed++;
        $failures[] = [$name, $out];
        fwrite(STDERR, "  FAIL $name\n");
    }
}

fwrite(STDERR, sprintf("\n%d passed, %d failed\n", $passed, $failed));

if ($failed > 0) {
    fwrite(STDERR, "\nFailures:\n");
    foreach ($failures as [$name, $lines]) {
        fwrite(STDERR, "\n--- $name ---\n");
        foreach ($lines as $line) {
            fwrite(STDERR, $line . "\n");
        }
    }
    exit(1);
}
exit(0);
