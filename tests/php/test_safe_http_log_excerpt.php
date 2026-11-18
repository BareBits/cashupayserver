<?php
/**
 * SafeHttp::logExcerpt: a remote response body is attacker-influenced and
 * ends up in error_log / exception messages (the desktop package shows the
 * PHP log in a live console). Terminal escape sequences, CR/LF (log-line
 * forgery), NULs and invalid UTF-8 must never survive, and the excerpt must
 * be bounded.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/safe_http.php';

assert_eq('plain text 123', SafeHttp::logExcerpt('plain text 123'), 'printable ASCII passes through');
assert_eq('', SafeHttp::logExcerpt(''), 'empty stays empty');

$ansi = "\x1b[31mRED\x1b[0m \x1b]0;title\x07";
$out = SafeHttp::logExcerpt($ansi);
assert_false(str_contains($out, "\x1b"), 'ESC is stripped');
assert_false(str_contains($out, "\x07"), 'BEL is stripped');
assert_eq('?[31mRED?[0m ?]0;title?', $out, 'escape bytes collapse to ?');

$forged = "ok\n[cron] everything fine\r\nERROR hidden";
$out = SafeHttp::logExcerpt($forged);
assert_false(str_contains($out, "\n") || str_contains($out, "\r"), 'CR/LF cannot forge log lines');
assert_eq('ok?[cron] everything fine?ERROR hidden', $out, 'line breaks collapse to ?');

assert_eq('a?b', SafeHttp::logExcerpt("a\x00b"), 'NUL stripped');
assert_eq('a?b', SafeHttp::logExcerpt("a\xff\xfeb"), 'invalid UTF-8 bytes collapse to one ?');
assert_eq('?', SafeHttp::logExcerpt('日本語'), 'multibyte text collapses (log stays ASCII)');
assert_eq('a?b', SafeHttp::logExcerpt("a\t\t\x0b\x0cb"), 'tabs and other C0 controls collapse');
assert_eq('a?b', SafeHttp::logExcerpt("a\x7fb"), 'DEL stripped');

$long = str_repeat('x', 500);
$out = SafeHttp::logExcerpt($long);
assert_eq(123, strlen($out), 'default cap is 120 bytes plus the ... marker');
assert_true(str_ends_with($out, '...'), 'truncation is marked');
assert_eq(str_repeat('x', 120), SafeHttp::logExcerpt(str_repeat('x', 120)), 'exactly at the cap is not marked');
assert_eq('xxxxx...', SafeHttp::logExcerpt($long, 5), 'custom cap honoured');
assert_eq('...', SafeHttp::logExcerpt('abc', 0), 'zero cap yields only the marker');

// Realistic hostile body: an HTML error page with a script and escape codes.
$body = "<html><script>alert(1)</script>\x1b[2J\x1b[H<b>Gateway</b>\n\n";
$out = SafeHttp::logExcerpt($body, 60);
assert_true(strlen($out) <= 63, 'bounded');
assert_true(preg_match('/^[\x20-\x7E]*$/', $out) === 1, 'printable ASCII only');

echo "test_safe_http_log_excerpt: all assertions passed\n";
