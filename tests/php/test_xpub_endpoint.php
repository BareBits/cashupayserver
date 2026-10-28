<?php
/**
 * scripts/xpub-endpoint/xpub.php — the BareBits fee-branch allocator — served
 * for real under php -S from a scratch dir. Pins the wire contract the
 * payserver client (includes/fee_branch.php) relies on:
 *   GET prints the highest branch allocated (0 when none), ?format=json too;
 *   POST allocates ONLY with the client marker (form field or JSON body);
 *   numbering is sequential from 1; per-IP rate limit -> 429 + Retry-After;
 *   IP rows are purged after 2 hours; other methods -> 405.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/xpub_endpoint_driver.php';

$ep = XpubEndpointDriver::start();
register_shutdown_function(static fn() => $ep->stop());

// ---- GET before any allocation ---------------------------------------------
$r = $ep->request('GET');
assert_eq(200, $r['status'], 'GET is 200');
assert_eq("0\n", $r['body'], 'nothing allocated yet -> prints 0');
assert_true(str_starts_with($r['headers']['content-type'] ?? '', 'text/plain'), 'GET is text/plain');
assert_eq('no-store', $r['headers']['cache-control'] ?? '', 'never cached');
$r = $ep->request('GET', ['query' => '?format=json']);
assert_eq(['highest_branch' => 0], json_decode($r['body'], true), 'json form of GET');
$r = $ep->request('GET', ['query' => '?whoami=1']);
assert_eq(200, $r['status'], 'whoami is 200');
assert_eq("127.0.0.1\n", $r['body'], 'whoami echoes the IP the rate limiter keys on (REMOTE_ADDR here)');

// ---- a bare POST (spider, curl -X POST) allocates nothing ------------------
$r = $ep->request('POST');
assert_eq(400, $r['status'], 'POST without the client marker is refused');
assert_eq('not_a_barebits_request', json_decode($r['body'], true)['error'] ?? null);
$r = $ep->request('POST', ['form' => ['client' => 'something-else']]);
assert_eq(400, $r['status'], 'wrong marker value is refused too');
assert_eq("0\n", $ep->request('GET')['body'], 'refused POSTs did not consume a branch');

// ---- real allocations: sequential from 1 ------------------------------------
$r = $ep->allocate();
assert_eq(200, $r['status'], 'first allocation OK');
assert_eq(['branch' => 1], json_decode($r['body'], true), 'first branch is 1 (0 is reserved)');
assert_eq(['branch' => 2], json_decode($ep->allocate()['body'], true), 'second branch is 2');
$r = $ep->request('POST', ['json' => ['client' => 'barebits-payserver']]);
assert_eq(['branch' => 3], json_decode($r['body'], true), 'JSON-body marker is accepted');
assert_eq("3\n", $ep->request('GET')['body'], 'GET prints the highest allocated');
$r = $ep->request('HEAD');
assert_eq(200, $r['status'], 'HEAD behaves like GET');

// ---- other methods ----------------------------------------------------------
$r = $ep->request('PUT');
assert_eq(405, $r['status'], 'PUT is 405');
assert_eq('GET, HEAD, POST', $r['headers']['allow'] ?? '', 'Allow header lists the supported methods');
$r = $ep->request('DELETE');
assert_eq(405, $r['status'], 'DELETE is 405');
assert_eq("3\n", $ep->request('GET')['body'], 'nothing allocated by the refused methods');

// ---- the store holds branches + allocation times, and IPs only transiently --
$db = $ep->db();
$branches = $db->query('SELECT branch, allocated_at FROM branches ORDER BY branch')->fetchAll(PDO::FETCH_ASSOC);
assert_eq([1, 2, 3], array_map('intval', array_column($branches, 'branch')), 'branch table holds 1..3');
foreach ($branches as $b) {
    assert_true((int)$b['allocated_at'] > time() - 60, 'allocation timestamp is fresh');
}
$cols = array_column($db->query('PRAGMA table_info(branches)')->fetchAll(PDO::FETCH_ASSOC), 'name');
assert_false(in_array('ip', $cols, true), 'branch table never records an IP');
assert_eq(3, (int)$db->query('SELECT COUNT(*) FROM recent_allocations')->fetchColumn(), 'one rate-limit row per allocation');

// ---- rate limit: 20 per IP per hour, then 429 with Retry-After ---------------
for ($i = 4; $i <= 20; $i++) {
    assert_eq(['branch' => $i], json_decode($ep->allocate()['body'], true), "allocation {$i} within quota");
}
$r = $ep->allocate();
assert_eq(429, $r['status'], '21st allocation within the hour is rate-limited');
$body = json_decode($r['body'], true);
assert_eq('rate_limited', $body['error'] ?? null);
assert_true((int)($body['retry_after'] ?? 0) > 0 && (int)$body['retry_after'] <= 3600, 'retry_after is within the hour');
assert_eq((string)(int)$body['retry_after'], $r['headers']['retry-after'] ?? '', 'Retry-After header matches the body');
assert_eq("20\n", $ep->request('GET')['body'], 'the refused request burned no branch');

// Age the IP rows past the hour: quota is free again.
$db->exec('UPDATE recent_allocations SET allocated_at = allocated_at - 3700');
assert_eq(['branch' => 21], json_decode($ep->allocate()['body'], true), 'quota recovers after the window');

// ---- IP retention: rows older than 2 hours are deleted on the next request --
$db->exec('UPDATE recent_allocations SET allocated_at = allocated_at - 7300');
$ep->request('GET'); // any request purges
assert_eq(0, (int)$db->query('SELECT COUNT(*) FROM recent_allocations')->fetchColumn(),
    'IP rows older than 2h are gone after any request');
assert_eq(21, (int)$db->query('SELECT MAX(branch) FROM branches')->fetchColumn(), 'branch history is kept');

// ---- the shipped .htaccess denies the SQLite file (and its WAL/SHM siblings) --
$ht = (string)file_get_contents(dirname(__DIR__, 2) . '/scripts/xpub-endpoint/.htaccess');
assert_true(str_contains($ht, 'FilesMatch "^xpub-branches\\.sqlite"'), '.htaccess matches the sqlite file prefix');
assert_true(str_contains($ht, 'Require all denied'), '.htaccess denies access');

echo "test_xpub_endpoint: ok\n";
