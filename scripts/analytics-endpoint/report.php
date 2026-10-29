<?php
/**
 * BareBits anonymous usage statistics — report receiver.
 * Deployed at https://getbarebits.com/analytics/report.php
 *
 * The payserver (includes/usage_stats.php) POSTs one JSON report here when
 * the operator opts in, then weekly. This script is the server half of the
 * privacy contract: it accepts exactly the fields the chosen level allows
 * (common.php analytics_rules), with type and range checks on every value,
 * and refuses everything else — so a client bug can never widen what is
 * stored. It keeps ONE row per install id (a newer report replaces the
 * older), derives a country from the connection and then forgets the IP
 * (kept at most two hours, only for the per-IP rate limit). It never
 * executes anything, never touches the shell, never writes the request
 * body anywhere unvalidated.
 *
 * Protocol
 *   POST /report.php   body: application/json, <= 16 KB, the report object
 *                      -> 200 {"ok":true}
 *                      -> 400 {"error":"<code>","field":"<name>"}   invalid report
 *                      -> 413 {"error":"too_large"}
 *                      -> 429 {"error":"rate_limited","retry_after":S}
 *   GET  /report.php   -> 200 {"ok":true}  (liveness; discloses nothing)
 *        ?whoami=1     -> text/plain, the caller's IP as the rate limiter
 *                         sees it (deploy check behind proxies)
 *   anything else      -> 405
 *
 * Aggregates: summary.php (operator-only). Country table: import-geo.php.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * Store (or replace) the install's report. Runs in one IMMEDIATE
 * transaction with the rate-limit bookkeeping so a burst from one address
 * can never exceed the quota.
 *
 * @return array{stored?: bool, retry_after?: int}
 */
function analytics_store(PDO $pdo, array $report, ?string $country, string $ip, int $now): array {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MIN(at) AS oldest FROM recent_requests WHERE ip = ? AND at > ?');
        $stmt->execute([$ip, $now - ANALYTICS_RATE_WINDOW_SEC]);
        $row = $stmt->fetch();
        if ((int)$row['n'] >= ANALYTICS_RATE_LIMIT_PER_HOUR) {
            $pdo->exec('ROLLBACK');
            return ['retry_after' => max(1, ((int)$row['oldest'] + ANALYTICS_RATE_WINDOW_SEC) - $now)];
        }
        $pdo->prepare('INSERT INTO recent_requests (ip, at) VALUES (?, ?)')->execute([$ip, $now]);
        $props = $report['properties'];
        $pdo->prepare('INSERT INTO reports
                (install_id, first_seen_at, received_at, schema_version, level, country, version, channel, install_kind, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(install_id) DO UPDATE SET
                received_at = excluded.received_at,
                schema_version = excluded.schema_version,
                level = excluded.level,
                country = excluded.country,
                version = excluded.version,
                channel = excluded.channel,
                install_kind = excluded.install_kind,
                payload = excluded.payload')
            ->execute([
                $report['install_id'], $now, $now, (int)$report['schema_version'], (int)$report['level'],
                $country, (string)$props['version'], (string)$props['channel'], (string)$props['install_kind'],
                json_encode($report, JSON_UNESCAPED_SLASHES),
            ]);
        $pdo->exec('COMMIT');
        return ['stored' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

try {
    $pdo = analytics_db();
    $now = time();
    analytics_purge_ips($pdo, $now);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' || $method === 'HEAD') {
        if (($_GET['whoami'] ?? '') === '1') {
            header('Content-Type: text/plain; charset=utf-8');
            echo analytics_client_ip(), "\n";
            exit;
        }
        analytics_json(200, ['ok' => true]);
        exit;
    }
    if ($method !== 'POST') {
        header('Allow: GET, HEAD, POST');
        analytics_json(405, ['error' => 'method_not_allowed']);
        exit;
    }

    // Size cap before reading: a declared oversize body is refused unread,
    // and an undeclared one is cut off at the cap + 1 and refused too.
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > ANALYTICS_MAX_BODY_BYTES) {
        analytics_json(413, ['error' => 'too_large']);
        exit;
    }
    $raw = (string)file_get_contents('php://input', false, null, 0, ANALYTICS_MAX_BODY_BYTES + 1);
    if (strlen($raw) > ANALYTICS_MAX_BODY_BYTES) {
        analytics_json(413, ['error' => 'too_large']);
        exit;
    }
    if ($raw === '') {
        analytics_json(400, ['error' => 'empty_body', 'field' => '']);
        exit;
    }
    $decoded = json_decode($raw, true, 8);
    if (json_last_error() !== JSON_ERROR_NONE) {
        analytics_json(400, ['error' => 'invalid_json', 'field' => '']);
        exit;
    }
    $v = analytics_validate($decoded);
    if (!$v['ok']) {
        analytics_json(400, ['error' => $v['error'], 'field' => $v['field']]);
        exit;
    }

    $ip = analytics_client_ip();
    $country = analytics_country($pdo, $ip);
    $result = analytics_store($pdo, $v['report'], $country, $ip, $now);
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . (int)$result['retry_after']);
        analytics_json(429, ['error' => 'rate_limited', 'retry_after' => (int)$result['retry_after']]);
        exit;
    }
    analytics_json(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('[analytics/report.php] ' . $e->getMessage());
    analytics_json(500, ['error' => 'internal_error']);
}
