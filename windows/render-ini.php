<?php
/**
 * BareBits Windows desktop — php.ini renderer.
 *
 * Run by the launcher with `php -n` (no ini needed) on EVERY start: writes
 * php.ini next to this script from php.ini.template, substituting {{ROOT}}
 * with this directory's absolute path. Re-rendering each launch is what lets
 * the merchant move the extracted folder without breaking anything.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$template = @file_get_contents(__DIR__ . '/php.ini.template');
if ($template === false) {
    fwrite(STDERR, "render-ini: php.ini.template missing next to render-ini.php\n");
    exit(1);
}

// Forward slashes work everywhere in php.ini values and sidestep any
// backslash-escaping ambiguity.
$root = str_replace('\\', '/', __DIR__);
$ini = str_replace('{{ROOT}}', $root, $template);

if (@file_put_contents(__DIR__ . '/php.ini', $ini) === false) {
    fwrite(STDERR, "render-ini: cannot write php.ini (is the folder read-only?)\n");
    exit(1);
}
