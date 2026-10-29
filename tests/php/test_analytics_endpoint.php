<?php
/**
 * scripts/analytics-endpoint/ — the BareBits usage-statistics receiver —
 * served for real under php -S from a scratch dir. Pins the wire contract
 * the payserver client (includes/usage_stats.php) relies on and the privacy
 * contract the operator was promised:
 *   only a complete, exactly-allowlisted report is stored (400 otherwise);
 *   one row per install id, newer replaces older, no history;
 *   the IP never lands in `reports`, and rate-limit rows age out;
 *   per-IP rate limit -> 429 + Retry-After; size cap -> 413; other methods -> 405;
 *   summary.php / import-geo.php are 403 until auth or a token is configured,
 *   then aggregate correctly (HTML, JSON, CSV with formula guard);
 *   country comes from the configured header, else the geo table, else null.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/analytics_endpoint_driver.php';

$ep = AnalyticsEndpointDriver::start();
register_shutdown_function(static fn() => $ep->stop());
$post = static fn(array $report) => $ep->request('POST', ['json' => $report]);
$sample = [AnalyticsEndpointDriver::class, 'sampleReport'];

// ---- liveness / methods -------------------------------------------------------
$r = $ep->request('GET');
assert_eq(200, $r['status'], 'GET is 200');
assert_eq(['ok' => true], json_decode($r['body'], true), 'GET discloses nothing but liveness');
assert_eq('no-store', $r['headers']['cache-control'] ?? '', 'never cached');
$r = $ep->request('GET', ['query' => '?whoami=1']);
assert_eq("127.0.0.1\n", $r['body'], 'whoami echoes the IP the rate limiter keys on');
$r = $ep->request('HEAD');
assert_eq(200, $r['status'], 'HEAD behaves like GET');
$r = $ep->request('PUT');
assert_eq(405, $r['status'], 'PUT is 405');
assert_eq('GET, HEAD, POST', $r['headers']['allow'] ?? '');
$r = $ep->request('DELETE');
assert_eq(405, $r['status'], 'DELETE is 405');

// ---- bodies that are not a report -------------------------------------------------
$r = $ep->request('POST');
assert_eq(400, $r['status'], 'empty POST refused');
assert_eq('empty_body', json_decode($r['body'], true)['error'] ?? null);
$r = $ep->request('POST', ['raw' => '{not json']);
assert_eq('invalid_json', json_decode($r['body'], true)['error'] ?? null, 'malformed JSON refused');
$r = $ep->request('POST', ['raw' => '[1,2,3]']);
assert_eq('not_an_object', json_decode($r['body'], true)['error'] ?? null, 'a JSON list is not a report');
$r = $ep->request('POST', ['raw' => '{}']);
assert_eq(['error' => 'missing_field', 'field' => 'schema_version'], json_decode($r['body'], true), 'an empty object is a report with everything missing');
$r = $ep->request('POST', ['raw' => str_repeat('x', 20000)]);
assert_eq(413, $r['status'], 'oversize body refused');
assert_eq('too_large', json_decode($r['body'], true)['error'] ?? null);
$r = $ep->request('POST', ['raw' => '{"a":' . str_repeat('[', 40) . str_repeat(']', 40) . '}']);
assert_eq(400, $r['status'], 'deeply nested JSON is refused (depth cap), not crashed on');

// ---- envelope validation -------------------------------------------------------
$cases = [
    ['client' => 'something-else', 'not_a_barebits_request', 'client'],
    ['client' => '__unset__', 'missing_field', 'client'],
    ['event' => 'pageview', 'invalid_value', 'event'],
    ['schema_version' => 2, 'unsupported_schema_version', 'schema_version'],
    ['schema_version' => '1', 'unsupported_schema_version', 'schema_version'],
    ['install_id' => 'not-a-uuid', 'invalid_value', 'install_id'],
    ['install_id' => 'ABCDEF01-0000-4000-8000-000000000000', 'invalid_value', 'install_id'], // uppercase
    ['install_id' => 'abcdef01-0000-1000-8000-000000000000', 'invalid_value', 'install_id'], // not v4
    ['sent_at' => '2026-10-29 12:00:00', 'invalid_value', 'sent_at'],
    ['sent_at' => '2026-13-45T99:00:00Z', 'invalid_value', 'sent_at'],
    ['level' => 4, 'invalid_value', 'level'],
    ['level' => '1', 'invalid_value', 'level'],
    ['level' => 0, 'invalid_value', 'level'],
    ['properties' => [], 'missing_field', 'properties.version'],
    ['properties' => [1, 2], 'invalid_value', 'properties'],
    ['extra' => 1, 'unknown_field', 'extra'],
];
foreach ($cases as $c) {
    [$expectedError, $expectedField] = [array_values($c)[1], array_values($c)[2]];
    $override = [array_keys($c)[0] => array_values($c)[0]];
    $r = $post($sample(1, $override));
    $d = json_decode($r['body'], true);
    assert_eq(400, $r['status'], 'refused: ' . json_encode($override));
    assert_eq($expectedError, $d['error'] ?? null, 'error code for ' . json_encode($override));
    assert_eq($expectedField, $d['field'] ?? null, 'field for ' . json_encode($override));
}

// ---- property validation: the allowlist is exact, types are strict -------------------
$propCases = [
    // unknown keys at any level — including a level-2 key on a level-1 report
    [1, ['properties.store_count' => 1], 'unknown_field', 'properties.store_count'],
    [1, ['properties.mint_url' => 'https://mint.example'], 'unknown_field', 'properties.mint_url'],
    [1, ['properties.deployment_id' => 'x'], 'unknown_field', 'properties.deployment_id'],
    [3, ['properties.email' => 'a@b.c'], 'unknown_field', 'properties.email'],
    // missing keys
    [1, ['properties.channel' => '__unset__'], 'missing_field', 'properties.channel'],
    [2, ['properties.rails_enabled' => '__unset__'], 'missing_field', 'properties.rails_enabled'],
    [3, ['properties.settled_30d_swap_sats' => '__unset__'], 'missing_field', 'properties.settled_30d_swap_sats'],
    // enums / patterns
    [1, ['properties.channel' => 'nightly'], 'invalid_value', 'properties.channel'],
    [1, ['properties.install_kind' => 'vps'], 'invalid_value', 'properties.install_kind'],
    [1, ['properties.web_server' => 'Apache/2.4.58 (Ubuntu)'], 'invalid_value', 'properties.web_server'],
    [1, ['properties.os_family' => 'linux'], 'invalid_value', 'properties.os_family'],
    [1, ['properties.version' => '1.7; DROP TABLE reports'], 'invalid_value', 'properties.version'],
    [1, ['properties.version' => '<script>'], 'invalid_value', 'properties.version'],
    [1, ['properties.build_sha' => 'ABCDEF012345'], 'invalid_value', 'properties.build_sha'],
    [1, ['properties.build_sha' => 'abcdef0123456'], 'invalid_value', 'properties.build_sha'],
    [1, ['properties.php_version' => '8.3.1'], 'invalid_value', 'properties.php_version'],
    // ints: type and range
    [2, ['properties.store_count' => '2'], 'invalid_value', 'properties.store_count'],
    [2, ['properties.store_count' => -1], 'invalid_value', 'properties.store_count'],
    [2, ['properties.store_count' => 2.5], 'invalid_value', 'properties.store_count'],
    [2, ['properties.products_count' => 1000000001], 'invalid_value', 'properties.products_count'],
    [3, ['properties.invoices_30d_settled_sats' => 2100000000000001], 'invalid_value', 'properties.invoices_30d_settled_sats'],
    // bools
    [2, ['properties.auto_update_enabled' => 1], 'invalid_value', 'properties.auto_update_enabled'],
    [2, ['properties.wordpress_connected' => 'yes'], 'invalid_value', 'properties.wordpress_connected'],
    // rails list
    [2, ['properties.rails_enabled' => ['mint', 'paypal']], 'invalid_value', 'properties.rails_enabled'],
    [2, ['properties.rails_enabled' => ['mint', 'mint']], 'invalid_value', 'properties.rails_enabled'],
    [2, ['properties.rails_enabled' => 'mint'], 'invalid_value', 'properties.rails_enabled'],
    [2, ['properties.rails_enabled' => ['mint' => true]], 'invalid_value', 'properties.rails_enabled'],
];
foreach ($propCases as [$level, $override, $expectedError, $expectedField]) {
    $r = $post($sample($level, $override));
    $d = json_decode($r['body'], true);
    assert_eq(400, $r['status'], "refused L{$level}: " . json_encode($override));
    assert_eq($expectedError, $d['error'] ?? null, 'error code for ' . json_encode($override));
    assert_eq($expectedField, $d['field'] ?? null, 'field for ' . json_encode($override));
}
$db = $ep->db();
assert_eq(0, (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'nothing invalid was stored');

// ---- valid reports: one row per install, newest wins ---------------------------------
$idA = AnalyticsEndpointDriver::uuid();
$r = $post($sample(1, [], $idA));
assert_eq(200, $r['status'], 'level-1 report accepted');
assert_eq(['ok' => true], json_decode($r['body'], true), 'accepted body is exactly {"ok":true}');
$r = $post($sample(3, [], $idA));
assert_eq(200, $r['status'], 'level-3 report from the same install accepted');
$rows = $db->query('SELECT * FROM reports')->fetchAll();
assert_eq(1, count($rows), 'one row per install id');
assert_eq(3, (int)$rows[0]['level'], 'the newer report replaced the older');
$stored = json_decode($rows[0]['payload'], true);
assert_eq(7, $stored['properties']['settled_30d_mint_count'], 'stored payload is the validated report');
assert_eq('1.7', $rows[0]['version']);
assert_eq('standalone', $rows[0]['install_kind']);
assert_null($rows[0]['country'], 'no geo table and no header -> country unknown');
assert_true((int)$rows[0]['first_seen_at'] <= (int)$rows[0]['received_at']);
$colNames = array_column($db->query('PRAGMA table_info(reports)')->fetchAll(), 'name');
assert_false(in_array('ip', $colNames, true), 'reports has no ip column');
assert_false(str_contains($rows[0]['payload'], '127.0.0.1'), 'the IP is not inside the stored payload');
// Floats that are whole numbers (a JSON round-trip artefact) are accepted as ints.
$idB = AnalyticsEndpointDriver::uuid();
$r = $ep->request('POST', ['raw' => json_encode($sample(2, ['properties.store_count' => 2.0], $idB))]);
assert_eq(200, $r['status'], 'whole-number float accepted as int');
assert_eq(2, (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'second install -> second row');
// A level-2 report from install B after its level-2 one: a different level downgrades too.
$r = $post($sample(1, [], $idB));
assert_eq(200, $r['status']);
$lvl = (int)$db->query("SELECT level FROM reports WHERE install_id = " . $db->quote($idB))->fetchColumn();
assert_eq(1, $lvl, 'a lower level replaces a higher one — the install chose to share less');

// ---- rate-limit bookkeeping holds IPs only transiently --------------------------------
$recent = $db->query('SELECT ip, at FROM recent_requests')->fetchAll();
assert_true(count($recent) >= 3, 'accepted reports are counted against the IP');
assert_eq('127.0.0.1', $recent[0]['ip']);
$db->exec('UPDATE recent_requests SET at = at - 7201');
$ep->request('GET');
assert_eq(0, (int)$db->query('SELECT COUNT(*) FROM recent_requests')->fetchColumn(), 'rows older than 2h purged on the next request');
assert_eq(2, (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'purging IPs never touches reports');

// ---- per-IP rate limit ---------------------------------------------------------------
$db->exec('DELETE FROM recent_requests');
$stmt = $db->prepare('INSERT INTO recent_requests (ip, at) VALUES (?, ?)');
for ($i = 0; $i < 120; $i++) {
    $stmt->execute(['127.0.0.1', time() - 10]);
}
$idC = AnalyticsEndpointDriver::uuid();
$r = $post($sample(1, [], $idC));
assert_eq(429, $r['status'], '121st report within the hour is rate-limited');
$d = json_decode($r['body'], true);
assert_eq('rate_limited', $d['error'] ?? null);
assert_true((int)($d['retry_after'] ?? 0) > 0 && (int)$d['retry_after'] <= 3600, 'retry_after is within the window');
assert_eq((string)$d['retry_after'], $r['headers']['retry-after'] ?? '', 'Retry-After header matches');
assert_eq(2, (int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn(), 'rate-limited report not stored');
$db->exec('DELETE FROM recent_requests');

// ---- country: header, then geo table, else null --------------------------------------
// geo table: load a tiny range set directly (import-geo.php's job; its parser is tested below).
$db->exec("INSERT INTO geo_v4 (start, end, cc) VALUES (" . ip2long('127.0.0.0') . ", " . ip2long('127.255.255.255') . ", 'ZL')");
$r = $post($sample(1, [], $idC));
assert_eq(200, $r['status']);
assert_eq('ZL', $db->query("SELECT country FROM reports WHERE install_id = " . $db->quote($idC))->fetchColumn(), 'country resolved from the geo table');
// a configured header wins over the table
$ep->setConstant('ANALYTICS_COUNTRY_HEADER', "'HTTP_X_TEST_COUNTRY'");
$r = $ep->request('POST', ['json' => $sample(1, [], $idC), 'headers' => ['X-Test-Country: de']]);
assert_eq(200, $r['status']);
assert_eq('DE', $db->query("SELECT country FROM reports WHERE install_id = " . $db->quote($idC))->fetchColumn(), 'header country (upper-cased) wins');
$r = $ep->request('POST', ['json' => $sample(1, [], $idC), 'headers' => ['X-Test-Country: XX']]);
assert_eq('ZL', $db->query("SELECT country FROM reports WHERE install_id = " . $db->quote($idC))->fetchColumn(), 'an unknown-country header falls back to the table');
$ep->setConstant('ANALYTICS_COUNTRY_HEADER', "''");

// ---- import-geo.php: refuses the web without auth, parses a CSV when run -------------------
$r = $ep->request('GET', ['path' => '/import-geo.php']);
assert_eq(403, $r['status'], 'import-geo.php over HTTP is 403 without auth');
$csv = implode("\n", [
    '1.0.0.0,1.0.0.255,AU',
    '8.8.8.0,8.8.8.255,US',
    '2001:4860::,2001:4860:ffff:ffff:ffff:ffff:ffff:ffff,US',
    'garbage line',
    '9.9.9.0,9.9.9.255,ZZ',   // ZZ = unknown, skipped
]) . "\n";
// The loader refuses to replace the table with < 1000 IPv4 ranges (a truncated
// download must never wipe a good table), so pad with synthetic ranges.
for ($i = 0; $i < 1100; $i++) {
    $csv .= long2ip(0x0A000000 + $i * 256) . ',' . long2ip(0x0A000000 + $i * 256 + 255) . ",X" . chr(65 + ($i % 26)) . "\n";
}
$gz = $ep->dir . '/test-geo.csv.gz';
file_put_contents($gz, gzencode($csv));
$out = [];
$rc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ep->dir . '/import-geo.php') . ' ' . escapeshellarg($gz) . ' 2>&1', $out, $rc);
assert_eq(0, $rc, 'import-geo.php CLI import succeeds: ' . implode(' | ', $out));
assert_true(str_contains(implode("\n", $out), 'IPv4 ranges, 1 IPv6'), 'reports the counts: ' . implode(' | ', $out));
assert_eq('US', $db->query("SELECT cc FROM geo_v4 WHERE start <= " . ip2long('8.8.8.8') . " ORDER BY start DESC LIMIT 1")->fetchColumn());
assert_eq(0, (int)$db->query("SELECT COUNT(*) FROM geo_v4 WHERE cc = 'ZZ'")->fetchColumn(), 'unknown-country rows skipped');
assert_eq(0, (int)$db->query("SELECT COUNT(*) FROM geo_v4 WHERE cc = 'ZL'")->fetchColumn(), 'the previous table was replaced, not appended to');
assert_eq(1, (int)$db->query('SELECT COUNT(*) FROM geo_v6')->fetchColumn());
assert_not_null($db->query("SELECT value FROM meta WHERE key = 'geo_loaded_at'")->fetchColumn(), 'load time recorded');
// the v6 range resolves too
$idD = AnalyticsEndpointDriver::uuid();
$ep->setConstant('ANALYTICS_TRUSTED_PROXY_IP_HEADER', "'HTTP_X_TEST_IP'");
$r = $ep->request('POST', ['json' => $sample(1, [], $idD), 'headers' => ['X-Test-Ip: 2001:4860:4860::8888']]);
assert_eq(200, $r['status']);
assert_eq('US', $db->query("SELECT country FROM reports WHERE install_id = " . $db->quote($idD))->fetchColumn(), 'IPv6 country via the geo table');
$r = $ep->request('POST', ['json' => $sample(1, [], $idD), 'headers' => ['X-Test-Ip: 203.0.113.9']]);
assert_null($db->query("SELECT country FROM reports WHERE install_id = " . $db->quote($idD))->fetchColumn(), 'an address outside every range -> null');
$ep->setConstant('ANALYTICS_TRUSTED_PROXY_IP_HEADER', "''");

// ---- summary.php: fails closed, then aggregates ---------------------------------------
$r = $ep->request('GET', ['path' => '/summary.php']);
assert_eq(403, $r['status'], 'summary is 403 with neither basic auth nor a token configured');
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=anything']);
assert_eq(403, $r['status'], 'a token is refused while none is configured');
$ep->setConstant('ANALYTICS_ACCESS_TOKEN', "'correct-horse-battery-staple-9'");
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=wrong']);
assert_eq(403, $r['status'], 'wrong token refused');
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=correct-horse-battery-staple-9&format=json']);
assert_eq(200, $r['status'], 'correct token admitted');
$agg = json_decode($r['body'], true);
assert_eq(4, $agg['installs_total'], 'four installs seen (A, B, C, D)');
assert_eq(4, $agg['installs_active']);
assert_eq(['1' => 3, '3' => 1], $agg['by_level'], 'A is level 3, B/C/D level 1');
assert_eq(['1.7' => 4], $agg['by_version']);
assert_eq(['(none)' => 3, 'ZL' => 1], $agg['by_country'], 'C kept its table country, the rest none');
assert_eq(1, $agg['features']['installs_reporting'], 'only A shares features');
assert_eq(1, $agg['features']['installs_with_rail']['mint']);
assert_eq(1, $agg['features']['installs_with_rail']['lnaddress']);
assert_eq(0, $agg['features']['installs_with_rail']['nwc']);
assert_eq(2, $agg['features']['stores_total']);
assert_eq(1, $agg['volume_30d']['installs_reporting']);
assert_eq(70000, $agg['volume_30d']['invoices_30d_settled_sats']);
assert_eq(7, $agg['volume_30d']['by_rail']['mint']['count']);
// "active" window: age install D out
$db->exec("UPDATE reports SET received_at = received_at - 86400 * 20 WHERE install_id = " . $db->quote($idD));
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=correct-horse-battery-staple-9&format=json']);
$agg = json_decode($r['body'], true);
assert_eq(4, $agg['installs_total']);
assert_eq(3, $agg['installs_active'], 'D fell out of the 14-day active window');
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=correct-horse-battery-staple-9&format=json&active_days=30']);
assert_eq(4, json_decode($r['body'], true)['installs_active'], 'active_days widens the window');
// HTML renders, escapes, and is not indexable
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=correct-horse-battery-staple-9']);
assert_eq(200, $r['status']);
assert_true(str_starts_with($r['headers']['content-type'] ?? '', 'text/html'));
assert_true(str_contains($r['body'], 'noindex'), 'summary page is noindex');
assert_true(str_contains($r['body'], '<b>4</b>installs ever seen'), 'KPI rendered');
assert_true(str_contains($r['headers']['content-security-policy'] ?? '', "default-src 'none'"), 'CSP set');
// CSV: one row per install, formula-leading cells neutralised
$db->exec("UPDATE reports SET version = '=HYPERLINK(1)' WHERE install_id = " . $db->quote($idB));
$pl = json_decode($db->query("SELECT payload FROM reports WHERE install_id = " . $db->quote($idB))->fetchColumn(), true);
$pl['properties']['version'] = '=HYPERLINK(1)';
$db->exec("UPDATE reports SET payload = " . $db->quote(json_encode($pl)) . " WHERE install_id = " . $db->quote($idB));
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?token=correct-horse-battery-staple-9&format=csv']);
assert_eq(200, $r['status']);
assert_true(str_starts_with($r['headers']['content-type'] ?? '', 'text/csv'));
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
assert_eq(5, count($lines), 'header + 4 installs');
assert_true(str_starts_with($lines[0], 'install_id,first_seen_utc,received_utc,level,country,active,version,build_sha'), 'CSV header is the flattened allowlist: ' . $lines[0]);
assert_true(str_contains($r['body'], "'=HYPERLINK(1)"), 'formula-leading cell is prefixed with an apostrophe');
assert_false(str_contains($r['body'], ",=HYPERLINK"), 'no bare formula cell escapes');
// basic auth (what .htaccess provides) also admits, without a token
$ep->setConstant('ANALYTICS_ACCESS_TOKEN', "''");
$r = $ep->request('GET', ['path' => '/summary.php', 'query' => '?format=json', 'headers' => ['Authorization: Basic ' . base64_encode('ops:pw')]]);
assert_eq(200, $r['status'], 'a web-server-authenticated user (PHP_AUTH_USER) is admitted');

echo "test_analytics_endpoint: ok\n";
