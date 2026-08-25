<?php
/**
 * windows/render-ini.php: the desktop package's php.ini renderer.
 *
 * Until now this only ran inside the release-time Windows smoke, so a bug in
 * it surfaced while trying to ship. The script is plain CLI PHP, so pin its
 * contract here on Linux: it writes php.ini next to itself from
 * php.ini.template with every {{ROOT}} replaced by its own directory
 * (forward-slashed), and it fails loudly when the template is missing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$repoRoot = dirname(__DIR__, 2);

/** Stage render-ini.php (and optionally the template) into a fresh dir. */
function stage_renderer(string $repoRoot, bool $withTemplate): string {
    $dir = sys_get_temp_dir() . '/cashupay_renderini_' . bin2hex(random_bytes(6));
    mkdir($dir, 0750, true);
    copy($repoRoot . '/windows/render-ini.php', $dir . '/render-ini.php');
    if ($withTemplate) {
        copy($repoRoot . '/windows/php.ini.template', $dir . '/php.ini.template');
    }
    return $dir;
}

function run_renderer(string $dir): array {
    $out = [];
    $rc = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($dir . '/render-ini.php') . ' 2>&1',
        $out,
        $rc
    );
    return [$rc, implode("\n", $out)];
}

// --- 0. The template's only placeholder is {{ROOT}} --------------------------
// render-ini.php substitutes exactly one token; any other {{...}} added to the
// template would ship verbatim into the merchant's php.ini.
$template = file_get_contents($repoRoot . '/windows/php.ini.template');
assert_not_null($template ?: null, 'php.ini.template readable');
preg_match_all('/\{\{(\w+)\}\}/', $template, $placeholders);
assert_true(count($placeholders[1]) > 0, 'template actually uses the placeholder');
assert_eq([], array_diff(array_unique($placeholders[1]), ['ROOT']),
    'template contains no placeholder render-ini.php does not substitute');

// --- 1. Happy path: php.ini rendered with {{ROOT}} substituted ---------------
$dir = stage_renderer($repoRoot, true);
[$rc, $output] = run_renderer($dir);
assert_eq(0, $rc, "renderer exit code (output: $output)");
assert_true(is_file($dir . '/php.ini'), 'php.ini written next to the renderer');

$ini = (string) file_get_contents($dir . '/php.ini');
assert_true(strpos($ini, '{{') === false, 'no unsubstituted placeholder left in php.ini');

// Every template line that used {{ROOT}} must appear with the absolute,
// forward-slashed directory in its place (backslash normalization is what
// keeps Windows paths unambiguous in ini values).
$root = str_replace('\\', '/', $dir);
foreach (explode("\n", $template) as $line) {
    if (strpos($line, '{{ROOT}}') === false) {
        continue;
    }
    $expected = str_replace('{{ROOT}}', $root, rtrim($line, "\r"));
    assert_true(
        strpos($ini, $expected) !== false,
        "rendered ini carries: $expected"
    );
}
cleanup_db($dir);

// --- 2. Missing template: loud failure ---------------------------------------
$bare = stage_renderer($repoRoot, false);
[$rc, $output] = run_renderer($bare);
assert_eq(1, $rc, 'missing template exits nonzero');
assert_true(
    strpos($output, 'php.ini.template') !== false,
    "error names the missing template, got: $output"
);
assert_false(is_file($bare . '/php.ini'), 'no php.ini written on failure');
cleanup_db($bare);

echo "ok\n";
