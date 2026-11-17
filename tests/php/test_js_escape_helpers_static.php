<?php
/**
 * The inline-JS escapers must be quote-safe. admin.php's escapeHtml() used
 * to be the textContent->innerHTML trick, which leaves `"` and `'` alone —
 * yet it is used inside attribute values (title=, href=, data-*), so a
 * provider-supplied value containing a quote (an Esplora txid, say) could
 * break out of the attribute. setup.php's mint-discovery card interpolated
 * mint-advertised unit strings into innerHTML with no escaping at all.
 *
 * Static pins (no browser): the escaper bodies and the two sinks.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$admin = file_get_contents(dirname(__DIR__, 2) . '/app/admin.php');
$setup = file_get_contents(dirname(__DIR__, 2) . '/app/setup.php');

// admin.php escapeHtml(): string replacement covering & < > " '.
assert_true(preg_match('/function escapeHtml\(text\)\s*\{(.*?)\n        \}/s', $admin, $m) === 1, 'admin escapeHtml found');
$body = $m[1];
foreach (["/&/g, '&amp;'", "/</g, '&lt;'", "/>/g, '&gt;'", '/"/g, \'&quot;\'', "/'/g, '&#39;'"] as $needle) {
    assert_true(str_contains($body, $needle), "admin escapeHtml escapes via {$needle}");
}
assert_false(str_contains($body, 'textContent'), 'admin escapeHtml no longer relies on textContent/innerHTML');

// setup.php: the unit list on a discovered-mint card is escaped.
assert_true(
    str_contains($setup, "units.map(function(u) { return escapeHtml(String(u).toUpperCase()); })"),
    'setup mint-discovery card escapes mint unit strings'
);
assert_false(
    str_contains($setup, "units.map(function(u) { return u.toUpperCase(); })"),
    'the unescaped unit interpolation is gone'
);
// ...and the country flag slot only ever renders the validated 2-letter code.
assert_true(preg_match('/function applyCountryToCard\(cardEl, cc\)\s*\{(.*?)\n    \}/s', $setup, $m) === 1, 'applyCountryToCard found');
assert_true(str_contains($m[1], "var label = safe.toUpperCase();"), 'flag slot renders the sanitised code');
assert_false(preg_match("/'\\s*\\+\\s*cc\\s*\\+\\s*'/", $m[1]) === 1, 'raw cc is not concatenated into the markup');

// admin.php's own mint-discovery card escapes units too (regression guard).
assert_true(str_contains($admin, "units.map(u => escapeHtml(u.toUpperCase()))"), 'admin discovery card escapes units');

echo "ok\n";
