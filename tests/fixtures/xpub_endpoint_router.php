<?php
/**
 * php -S router for test copies of scripts/xpub-endpoint/xpub.php.
 *
 * The real endpoint file is copied next to this router into a scratch dir
 * (so its SQLite store lands there, not in the repo) and served as-is. A
 * `mode` file beside it lets tests inject failures the payserver client has
 * to survive:
 *
 *   normal     (default / file absent)  serve xpub.php for real
 *   http500    respond 500 "boom"
 *   garbage    respond 200 with a non-JSON body
 *   badbranch  respond 200 {"branch": 0}         (out of range)
 *   strbranch  respond 200 {"branch": "7"}       (wrong type)
 *   nobranch   respond 200 {"ok": true}          (missing field)
 *   ratelimit  respond 429 {"error":"rate_limited","retry_after":1200}
 *   hang       sleep past the client's timeout, then 200
 *
 * Shared by tests/php/xpub_endpoint_driver.php and tests/fixtures/xpub_endpoint.py.
 */
$modeFile = __DIR__ . '/mode';
$mode = is_file($modeFile) ? trim((string)file_get_contents($modeFile)) : 'normal';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/xpub.php' && $path !== '/') {
    http_response_code(404);
    echo "not found\n";
    return true;
}
if ($mode === 'normal' || $mode === '') {
    if ($path === '/') {
        require __DIR__ . '/xpub.php';
        return true;
    }
    return false; // let php -S execute xpub.php itself
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
    case 'badbranch':
        header('Content-Type: application/json');
        echo json_encode(['branch' => 0]), "\n";
        break;
    case 'strbranch':
        header('Content-Type: application/json');
        echo json_encode(['branch' => '7']), "\n";
        break;
    case 'nobranch':
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]), "\n";
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
        echo json_encode(['branch' => 99]), "\n";
        break;
    default:
        http_response_code(500);
        echo "unknown test mode {$mode}\n";
}
return true;
