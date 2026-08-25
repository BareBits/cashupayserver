<?php
/**
 * Verify a built plugin/app tree is internally coherent.
 *
 * Motivation: setup.php shipped a `require_once __DIR__ . '/btcpay-integration.php'`
 * that resolved only under the Docker layout. In the zip produced by
 * scripts/build-wordpress-plugin.sh the file lands in wordpress/ instead, so
 * the WordPress completion screen fataled — and nothing caught it, because the
 * build succeeded and the test suite assembles its own plugin tree rather than
 * consuming the artifact.
 *
 * Checks, over every PHP file in the tree:
 *   1. It parses (php -l).
 *   2. Every literal `__DIR__ . '/relative/path.php'` used in a
 *      require/require_once/include/include_once resolves to a real file.
 *
 * Only literal concatenations are checked; dynamic includes are skipped
 * (they cannot be resolved statically, and the codebase guards those with
 * is_file()).
 *
 * Usage: php scripts/verify-plugin-build.php <plugin-dir> [required-file ...]
 */

declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "usage: php scripts/verify-plugin-build.php <plugin-dir> [required-file ...]\n");
    exit(2);
}
$root = realpath($root);
$requiredFiles = array_slice($argv, 2);

$errors = [];
$checkedFiles = 0;
$checkedIncludes = 0;

// --- 1. Required files present -------------------------------------------
foreach ($requiredFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        $errors[] = "missing required file: {$rel}";
    }
}

// --- 2. Walk every PHP file ----------------------------------------------
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$phpBin = PHP_BINARY;

foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    // vendor/ is third-party and huge; trust composer's own validation.
    // Matched at any depth, not just the tree root: the Windows desktop
    // package nests the whole app (vendor/ included) under app/.
    if (strpos($path, '/vendor/') !== false) {
        continue;
    }
    $checkedFiles++;

    // 2a. Syntax.
    $out = [];
    $rc = 0;
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $errors[] = "syntax error in " . substr($path, strlen($root) + 1) . ": " . implode(' ', $out);
        continue;
    }

    $src = file_get_contents($path);

    // 2b. Literal __DIR__-relative includes. These resolve against the file's
    //     own directory.
    $pattern = '/\b(?:require|require_once|include|include_once)\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/';
    if (preg_match_all($pattern, $src, $matches)) {
        foreach ($matches[1] as $rel) {
            $checkedIncludes++;
            // Optional includes are guarded by a file_exists() on the same path
            // (config.local.php, user_config.php); absence is expected there.
            $guard = '/file_exists\s*\(\s*__DIR__\s*\.\s*[\'"]' . preg_quote($rel, '/') . '[\'"]\s*\)/';
            if (preg_match($guard, $src)) {
                continue;
            }
            $target = dirname($path) . $rel;
            if (!is_file($target)) {
                $errors[] = sprintf(
                    "%s: require of __DIR__ . '%s' does not resolve (looked for %s)",
                    substr($path, strlen($root) + 1),
                    $rel,
                    substr($target, strlen($root) + 1)
                );
            }
        }
    }

    // 2c. Literal CASHUPAY_PLUGIN_DIR-relative includes. The WordPress rewrite
    //     router dispatches to entry points this way (require CASHUPAY_PLUGIN_DIR
    //     . '/pay.php'), and CASHUPAY_PLUGIN_DIR is always the plugin root — so
    //     these resolve against $root regardless of which file references them.
    //     Missing pay.php slipped through precisely because 2b only saw __DIR__.
    $pluginPattern = '/\b(?:require|require_once|include|include_once)\s*\(?\s*CASHUPAY_PLUGIN_DIR\s*\.\s*[\'"]([^\'"]+)[\'"]/';
    if (preg_match_all($pluginPattern, $src, $pmatches)) {
        foreach ($pmatches[1] as $rel) {
            $checkedIncludes++;
            $target = $root . $rel;
            if (!is_file($target)) {
                $errors[] = sprintf(
                    "%s: require of CASHUPAY_PLUGIN_DIR . '%s' does not resolve (looked for %s)",
                    substr($path, strlen($root) + 1),
                    $rel,
                    substr($target, strlen($root) + 1)
                );
            }
        }
    }
}

printf("checked %d PHP files, %d literal __DIR__ includes\n", $checkedFiles, $checkedIncludes);

if ($errors) {
    fwrite(STDERR, "\nplugin build verification FAILED:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

echo "plugin build verification passed\n";
