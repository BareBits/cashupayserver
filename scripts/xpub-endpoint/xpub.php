<?php
/**
 * BareBits fee-branch allocator — deployed at https://getbarebits.com/xpub.php
 *
 * Every BareBits payserver install derives its on-chain dev-fee addresses from
 * the same BareBits zpub. Without coordination two installs would derive the
 * identical address at the same index and could no longer tell whose customer
 * paid. This endpoint hands every install a unique, sequential BIP32 "branch"
 * number; the install then derives fee addresses at  <zpub>/<branch>/0/<index>
 * (see includes/fee_branch.php in the payserver); without a branch the dev fee
 * never takes the on-chain rail. BareBits sweeps the lot with
 * one Bitcoin Core descriptor wallet that imports  wpkh(xpub/<b>/0/*)  for
 * every branch up to the highest one allocated (scripts/fee-branch-descriptors.php
 * prints the import list).
 *
 * Protocol
 *   GET  /xpub.php                 -> text/plain: highest branch allocated so far
 *                                     (0 when none). ?format=json gives
 *                                     {"highest_branch": N}.
 *   POST /xpub.php  client=barebits-payserver
 *                                  -> {"branch": N}  (200). The marker field is
 *                                     what distinguishes a payserver install from
 *                                     a web spider that happens to POST; without
 *                                     it nothing is allocated (400).
 *                                  -> {"error":"rate_limited","retry_after":S}
 *                                     (429) after XPUB_RATE_LIMIT_PER_HOUR new
 *                                     allocations from one IP within an hour.
 *   anything else                  -> 405.
 *
 * Storage: one SQLite file next to this script (XPUB_DB_FILE). The `branches`
 * table holds branch numbers and allocation times only. Client IPs are kept
 * solely for the per-IP rate limit, in `recent_allocations`, and every
 * request first deletes rows older than XPUB_IP_RETENTION_SEC (2 hours) —
 * nothing identifying an install is retained beyond that window. The .htaccess
 * shipped alongside blocks web access to the SQLite file and its journals.
 *
 * Requires PHP >= 8.0 with pdo_sqlite. No other dependencies.
 */
declare(strict_types=1);

const XPUB_DB_FILE              = __DIR__ . '/xpub-branches.sqlite';
const XPUB_CLIENT_MARKER        = 'barebits-payserver';
const XPUB_FIRST_BRANCH         = 1;            // 0 stays unused: "no branch" in the payserver
const XPUB_MAX_BRANCH           = 2147483647;   // BIP32 non-hardened child range
const XPUB_RATE_LIMIT_PER_HOUR  = 20;
const XPUB_RATE_WINDOW_SEC      = 3600;
const XPUB_IP_RETENTION_SEC     = 7200;         // purge IP rows older than this on every request
// Set to the $_SERVER key carrying the real client IP when the site sits
// behind a proxy or CDN that you trust (e.g. 'HTTP_CF_CONNECTING_IP' on
// Cloudflare). Leave empty to use REMOTE_ADDR directly.
const XPUB_TRUSTED_PROXY_IP_HEADER = '';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function xpub_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . XPUB_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS branches (
        branch INTEGER PRIMARY KEY,
        allocated_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS recent_allocations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        allocated_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recent_ip ON recent_allocations (ip, allocated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recent_at ON recent_allocations (allocated_at)');
    return $pdo;
}

function xpub_client_ip(): string {
    if (XPUB_TRUSTED_PROXY_IP_HEADER !== '' && !empty($_SERVER[XPUB_TRUSTED_PROXY_IP_HEADER])) {
        $ip = trim(explode(',', (string)$_SERVER[XPUB_TRUSTED_PROXY_IP_HEADER])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Drop rate-limit rows past the retention window. Runs on every request. */
function xpub_purge_ips(PDO $pdo, int $now): void {
    $stmt = $pdo->prepare('DELETE FROM recent_allocations WHERE allocated_at < ?');
    $stmt->execute([$now - XPUB_IP_RETENTION_SEC]);
}

function xpub_highest(PDO $pdo): int {
    $v = $pdo->query('SELECT COALESCE(MAX(branch), 0) FROM branches')->fetchColumn();
    return (int)$v;
}

function xpub_json(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload), "\n";
}

/** The POST marker may arrive as a form field or as a JSON body. */
function xpub_has_client_marker(): bool {
    if (isset($_POST['client']) && (string)$_POST['client'] === XPUB_CLIENT_MARKER) {
        return true;
    }
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 4096) {
        return false;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) && (($decoded['client'] ?? null) === XPUB_CLIENT_MARKER);
}

/**
 * Allocate the next branch for $ip. Returns ['branch' => N] or
 * ['retry_after' => S] when the IP is over its hourly quota. Runs in one
 * IMMEDIATE transaction so concurrent requests can never hand out the same
 * number.
 */
function xpub_allocate(PDO $pdo, string $ip, int $now): array {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MIN(allocated_at) AS oldest FROM recent_allocations
                                WHERE ip = ? AND allocated_at > ?');
        $stmt->execute([$ip, $now - XPUB_RATE_WINDOW_SEC]);
        $row = $stmt->fetch();
        if ((int)$row['n'] >= XPUB_RATE_LIMIT_PER_HOUR) {
            $pdo->exec('ROLLBACK');
            $retry = max(1, ((int)$row['oldest'] + XPUB_RATE_WINDOW_SEC) - $now);
            return ['retry_after' => $retry];
        }
        $next = max(XPUB_FIRST_BRANCH, xpub_highest($pdo) + 1);
        if ($next > XPUB_MAX_BRANCH) {
            $pdo->exec('ROLLBACK');
            return ['exhausted' => true];
        }
        $pdo->prepare('INSERT INTO branches (branch, allocated_at) VALUES (?, ?)')->execute([$next, $now]);
        $pdo->prepare('INSERT INTO recent_allocations (ip, allocated_at) VALUES (?, ?)')->execute([$ip, $now]);
        $pdo->exec('COMMIT');
        return ['branch' => $next];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------

try {
    $pdo = xpub_db();
    $now = time();
    xpub_purge_ips($pdo, $now);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' || $method === 'HEAD') {
        $highest = xpub_highest($pdo);
        if (($_GET['format'] ?? '') === 'json') {
            xpub_json(200, ['highest_branch' => $highest]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $highest, "\n";
        }
        exit;
    }

    if ($method !== 'POST') {
        header('Allow: GET, HEAD, POST');
        xpub_json(405, ['error' => 'method_not_allowed']);
        exit;
    }

    if (!xpub_has_client_marker()) {
        xpub_json(400, ['error' => 'not_a_barebits_request']);
        exit;
    }

    $result = xpub_allocate($pdo, xpub_client_ip(), $now);
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . (int)$result['retry_after']);
        xpub_json(429, ['error' => 'rate_limited', 'retry_after' => (int)$result['retry_after']]);
        exit;
    }
    if (isset($result['exhausted'])) {
        xpub_json(503, ['error' => 'branch_space_exhausted']);
        exit;
    }
    xpub_json(200, ['branch' => (int)$result['branch']]);
} catch (Throwable $e) {
    error_log('[xpub.php] ' . $e->getMessage());
    xpub_json(500, ['error' => 'internal_error']);
}
