<?php
/**
 * php -S router for test copies of scripts/analytics-endpoint/*.php.
 *
 * The real endpoint files are copied next to this router into a scratch dir
 * (so the SQLite store lands there, not in the repo) and served as-is. A
 * `mode` file beside it lets tests inject failures the payserver client
 * (includes/usage_stats.php) has to survive — only report.php is affected,
 * summary.php and import-geo.php always run for real:
 *
 *   normal     (default / file absent)  serve report.php for real
 *   http500    respond 500 "boom"
 *   garbage    respond 200 with a non-JSON body
 *   notok      respond 200 {"ok": false}
 *   reject     respond 400 {"error":"unknown_field","field":"properties.x"}
 *   ratelimit  respond 429 {"error":"rate_limited","retry_after":1200}
 *   hang       sleep past the client's timeout, then 200 {"ok":true}
 *
 * Shared by tests/php/analytics_endpoint_driver.php and
 * tests/fixtures/analytics_endpoint.py.
 */
$modeFile = __DIR__ . '/mode';
$mode = is_file($modeFile) ? trim((string)file_get_contents($modeFile)) : 'normal';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$served = ['/report.php', '/summary.php', '/import-geo.php'];
if (!in_array($path, $served, true)) {
    http_response_code(404);
    echo "not found\n";
    return true;
}
if ($path !== '/report.php' || $mode === 'normal' || $mode === '') {
    return false; // let php -S execute the real script
}

header('Cache-Control: no-store');
switch ($mode) {
    case 'http500':
        http_response_code(500);
        echo "boom\n";
        break;
    case 'garbage':
        header('Content-Type: application/json');
        echo "<html><body>definitely not json</body></html>\n";
        break;
    case 'notok':
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]), "\n";
        break;
    case 'reject':
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unknown_field', 'field' => 'properties.x']), "\n";
        break;
    case 'ratelimit':
        http_response_code(429);
        header('Retry-After: 1200');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'rate_limited', 'retry_after' => 1200]), "\n";
        break;
    case 'hang':
        sleep(8);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]), "\n";
        break;
    default:
        http_response_code(500);
        echo "unknown test mode {$mode}\n";
}
return true;
