<?php
/**
 * Shared pieces of the BareBits anonymous-usage-statistics endpoint
 * (report.php receives, summary.php aggregates, import-geo.php loads the
 * IP-to-country table). Deployed together to getbarebits.com/analytics/.
 *
 * Settings live in the constants below; edit them in place on the host.
 * Requires PHP >= 8.0 with pdo_sqlite. No other dependencies, no shell use.
 */
declare(strict_types=1);

const ANALYTICS_DB_FILE             = __DIR__ . '/usage-reports.sqlite';
const ANALYTICS_CLIENT_MARKER       = 'barebits-payserver';
const ANALYTICS_EVENT               = 'usage_report';
const ANALYTICS_SCHEMA_VERSION      = 1;
const ANALYTICS_MAX_BODY_BYTES      = 16384;   // a level-3 report is ~1.5 KB
const ANALYTICS_RATE_LIMIT_PER_HOUR = 120;     // many installs may share one egress IP
const ANALYTICS_RATE_WINDOW_SEC     = 3600;
const ANALYTICS_IP_RETENTION_SEC    = 7200;    // rate-limit rows purged after this
// Set to the $_SERVER key carrying the real client IP when the site sits
// behind a proxy or CDN you trust (e.g. 'HTTP_CF_CONNECTING_IP' on
// Cloudflare). Leave empty to use REMOTE_ADDR directly.
const ANALYTICS_TRUSTED_PROXY_IP_HEADER = '';
// A $_SERVER key that already carries the caller's ISO country code, when
// the host provides one ('HTTP_CF_IPCOUNTRY' on Cloudflare, 'GEOIP_COUNTRY_CODE'
// with mod_geoip). Checked before the local geo table. Leave empty if none.
const ANALYTICS_COUNTRY_HEADER = '';
// Secret for summary.php / import-geo.php when they are not behind HTTP
// basic auth: pass it as ?token=... . Empty = token access disabled (then
// basic auth is the only way in). Use a long random string.
const ANALYTICS_ACCESS_TOKEN = '';

// PHP 8.0 hosts: array_is_list() arrived in 8.1.
if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}

// --------------------------------------------------------------------------
// Database
// --------------------------------------------------------------------------

function analytics_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . ANALYTICS_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    // One row per install: a newer report replaces the older one, so the
    // table is the current population, never a history.
    $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
        install_id     TEXT PRIMARY KEY,
        first_seen_at  INTEGER NOT NULL,
        received_at    INTEGER NOT NULL,
        schema_version INTEGER NOT NULL,
        level          INTEGER NOT NULL,
        country        TEXT,
        version        TEXT NOT NULL,
        channel        TEXT NOT NULL,
        install_kind   TEXT NOT NULL,
        payload        TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_received ON reports (received_at)');
    // Client IPs live ONLY here, only for the per-IP rate limit, and are
    // purged after ANALYTICS_IP_RETENTION_SEC on every request.
    $pdo->exec('CREATE TABLE IF NOT EXISTS recent_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recent_ip ON recent_requests (ip, at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recent_at ON recent_requests (at)');
    // IP-to-country ranges (DB-IP Lite), loaded by import-geo.php. v4 as
    // integers, v6 as 16-byte blobs (SQLite compares blobs bytewise).
    $pdo->exec('CREATE TABLE IF NOT EXISTS geo_v4 (start INTEGER NOT NULL, end INTEGER NOT NULL, cc TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_geo_v4_start ON geo_v4 (start)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS geo_v6 (start BLOB NOT NULL, end BLOB NOT NULL, cc TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_geo_v6_start ON geo_v6 (start)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    return $pdo;
}

function analytics_meta_get(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

function analytics_meta_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

// --------------------------------------------------------------------------
// Request helpers
// --------------------------------------------------------------------------

function analytics_client_ip(): string {
    if (ANALYTICS_TRUSTED_PROXY_IP_HEADER !== '' && !empty($_SERVER[ANALYTICS_TRUSTED_PROXY_IP_HEADER])) {
        $ip = trim(explode(',', (string)$_SERVER[ANALYTICS_TRUSTED_PROXY_IP_HEADER])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function analytics_json(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload), "\n";
}

/** Drop rate-limit rows past the retention window. Runs on every request. */
function analytics_purge_ips(PDO $pdo, int $now): void {
    $pdo->prepare('DELETE FROM recent_requests WHERE at < ?')->execute([$now - ANALYTICS_IP_RETENTION_SEC]);
}

/**
 * Is the operator allowed to read the aggregate pages? Either the web
 * server authenticated them (HTTP basic auth via .htaccess) or they passed
 * the configured access token. Fails closed when neither is set up.
 */
function analytics_operator_authorized(): bool {
    foreach (['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $k) {
        if (!empty($_SERVER[$k])) {
            return true;
        }
    }
    if (ANALYTICS_ACCESS_TOKEN !== '' && strlen(ANALYTICS_ACCESS_TOKEN) >= 16) {
        $given = (string)($_GET['token'] ?? '');
        if ($given !== '' && hash_equals(ANALYTICS_ACCESS_TOKEN, $given)) {
            return true;
        }
    }
    return false;
}

// --------------------------------------------------------------------------
// Country
// --------------------------------------------------------------------------

/**
 * ISO 3166-1 alpha-2 country for the caller, or null. Header first (when
 * the host provides one), then the local DB-IP table. The IP itself is
 * never written anywhere except the transient rate-limit table.
 */
function analytics_country(PDO $pdo, string $ip): ?string {
    if (ANALYTICS_COUNTRY_HEADER !== '' && !empty($_SERVER[ANALYTICS_COUNTRY_HEADER])) {
        $cc = strtoupper(trim((string)$_SERVER[ANALYTICS_COUNTRY_HEADER]));
        if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX' && $cc !== 'T1') {
            return $cc;
        }
    }
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return null;
    }
    try {
        if (strlen($packed) === 4) {
            $n = (int)sprintf('%u', unpack('N', $packed)[1]);
            $stmt = $pdo->prepare('SELECT cc, end FROM geo_v4 WHERE start <= ? ORDER BY start DESC LIMIT 1');
            $stmt->execute([$n]);
            $row = $stmt->fetch();
            return ($row && (int)$row['end'] >= $n) ? (string)$row['cc'] : null;
        }
        $stmt = $pdo->prepare('SELECT cc, end FROM geo_v6 WHERE start <= ? ORDER BY start DESC LIMIT 1');
        $stmt->bindValue(1, $packed, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        return ($row && strcmp((string)$row['end'], $packed) >= 0) ? (string)$row['cc'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

// --------------------------------------------------------------------------
// Report validation — the privacy contract, enforced server-side
// --------------------------------------------------------------------------

const ANALYTICS_FEATURE_RAILS = ['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'onchain', 'swaps'];
const ANALYTICS_VOLUME_RAILS  = ['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'swap', 'onchain'];
const ANALYTICS_MAX_COUNT     = 1000000000;      // counts: 1e9
const ANALYTICS_MAX_SATS      = 2100000000000000; // 21M BTC in sats

/**
 * Field rules per level. Each entry: [type, constraint]. Types:
 *   enum  -> constraint is the allowed values
 *   regex -> constraint is the pattern (strings only)
 *   int   -> constraint is [min, max]
 *   bool  -> no constraint
 *   nullable_regex -> null or a string matching the pattern
 *   rails -> list of distinct ANALYTICS_FEATURE_RAILS values
 * A report must carry EXACTLY the keys of its level (and the lower levels):
 * unknown keys are refused, missing keys are refused, so a client change
 * that widens the payload fails loudly here instead of being stored.
 */
function analytics_rules(): array {
    $r = [
        1 => [
            'version'      => ['regex', '/^[0-9]{1,4}(\.[0-9]{1,4}){0,3}(-[A-Za-z0-9.]{1,24})?$/'],
            'build_sha'    => ['nullable_regex', '/^[0-9a-f]{12}$/'],
            'channel'      => ['enum', ['main', 'testing']],
            'php_version'  => ['regex', '/^[0-9]{1,2}\.[0-9]{1,3}$/'],
            'os_family'    => ['enum', ['Windows', 'BSD', 'Darwin', 'Solaris', 'Linux', 'Unknown']],
            'install_kind' => ['enum', ['standalone', 'docker', 'desktop', 'wordpress']],
            'web_server'   => ['enum', ['apache', 'nginx', 'litespeed', 'caddy', 'iis', 'php-dev-server', 'other', 'unknown']],
        ],
        2 => [
            'store_count'           => ['int', [0, ANALYTICS_MAX_COUNT]],
            'rails_enabled'         => ['rails', null],
            'stores_with_mint'      => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_lnaddress' => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_nwc'       => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_noffer'    => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_strike'    => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_onchain'   => ['int', [0, ANALYTICS_MAX_COUNT]],
            'stores_with_swaps'     => ['int', [0, ANALYTICS_MAX_COUNT]],
            'auto_update_enabled'   => ['bool', null],
            'products_count'        => ['int', [0, ANALYTICS_MAX_COUNT]],
            'api_keys_count'        => ['int', [0, ANALYTICS_MAX_COUNT]],
            'wordpress_connected'   => ['bool', null],
        ],
        3 => [
            'invoices_30d_created'      => ['int', [0, ANALYTICS_MAX_COUNT]],
            'invoices_30d_settled'      => ['int', [0, ANALYTICS_MAX_COUNT]],
            'invoices_30d_expired'      => ['int', [0, ANALYTICS_MAX_COUNT]],
            'invoices_30d_settled_sats' => ['int', [0, ANALYTICS_MAX_SATS]],
        ],
    ];
    foreach (ANALYTICS_VOLUME_RAILS as $rail) {
        $r[3]["settled_30d_{$rail}_count"] = ['int', [0, ANALYTICS_MAX_COUNT]];
        $r[3]["settled_30d_{$rail}_sats"]  = ['int', [0, ANALYTICS_MAX_SATS]];
    }
    return $r;
}

/** @return array<string, array> rules for every key a report at $level must carry */
function analytics_rules_for_level(int $level): array {
    $out = [];
    foreach (analytics_rules() as $l => $rules) {
        if ($l <= $level) {
            $out += $rules;
        }
    }
    return $out;
}

function analytics_is_uuid_v4(string $v): bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v) === 1;
}

/**
 * Validate a decoded report. Returns ['ok' => true, 'report' => <normalized>]
 * or ['ok' => false, 'error' => <code>, 'field' => <name>]. Normalization
 * casts ints that arrived as floats with no fraction (json round-trips) and
 * nothing else — a wrong type is an error, not a coercion.
 */
function analytics_validate(mixed $decoded): array {
    $fail = static fn(string $error, string $field = '') => ['ok' => false, 'error' => $error, 'field' => $field];
    // json_decode('{}', true) is [] — an empty object, not a list.
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
        return $fail('not_an_object');
    }
    $envelopeKeys = ['schema_version', 'event', 'client', 'install_id', 'sent_at', 'level', 'properties'];
    foreach (array_keys($decoded) as $k) {
        if (!in_array($k, $envelopeKeys, true)) {
            return $fail('unknown_field', (string)$k);
        }
    }
    foreach ($envelopeKeys as $k) {
        if (!array_key_exists($k, $decoded)) {
            return $fail('missing_field', $k);
        }
    }
    if ($decoded['schema_version'] !== ANALYTICS_SCHEMA_VERSION) {
        return $fail('unsupported_schema_version', 'schema_version');
    }
    if ($decoded['event'] !== ANALYTICS_EVENT) {
        return $fail('invalid_value', 'event');
    }
    if ($decoded['client'] !== ANALYTICS_CLIENT_MARKER) {
        return $fail('not_a_barebits_request', 'client');
    }
    if (!is_string($decoded['install_id']) || !analytics_is_uuid_v4($decoded['install_id'])) {
        return $fail('invalid_value', 'install_id');
    }
    if (!is_string($decoded['sent_at'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $decoded['sent_at'])
        || strtotime($decoded['sent_at']) === false) {
        return $fail('invalid_value', 'sent_at');
    }
    $level = $decoded['level'];
    if (!is_int($level) || $level < 1 || $level > 3) {
        return $fail('invalid_value', 'level');
    }
    $props = $decoded['properties'];
    if (!is_array($props) || (count($props) > 0 && array_is_list($props))) {
        return $fail('invalid_value', 'properties');
    }
    $rules = analytics_rules_for_level($level);
    foreach (array_keys($props) as $k) {
        if (!isset($rules[$k])) {
            return $fail('unknown_field', 'properties.' . (string)$k);
        }
    }
    $normalized = [];
    foreach ($rules as $key => [$type, $constraint]) {
        if (!array_key_exists($key, $props)) {
            return $fail('missing_field', 'properties.' . $key);
        }
        $v = $props[$key];
        switch ($type) {
            case 'enum':
                if (!is_string($v) || !in_array($v, $constraint, true)) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            case 'regex':
                if (!is_string($v) || strlen($v) > 64 || !preg_match($constraint, $v)) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            case 'nullable_regex':
                if ($v !== null && (!is_string($v) || strlen($v) > 64 || !preg_match($constraint, $v))) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            case 'int':
                if (is_float($v) && floor($v) === $v && abs($v) < 9.0e15) {
                    $v = (int)$v;
                }
                if (!is_int($v) || $v < $constraint[0] || $v > $constraint[1]) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            case 'bool':
                if (!is_bool($v)) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            case 'rails':
                if (!is_array($v) || !array_is_list($v) || count($v) > count(ANALYTICS_FEATURE_RAILS)) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                foreach ($v as $rail) {
                    if (!is_string($rail) || !in_array($rail, ANALYTICS_FEATURE_RAILS, true)) {
                        return $fail('invalid_value', 'properties.' . $key);
                    }
                }
                if (count(array_unique($v)) !== count($v)) {
                    return $fail('invalid_value', 'properties.' . $key);
                }
                break;
            default:
                return $fail('internal_rule_error', $key);
        }
        $normalized[$key] = $v;
    }
    return ['ok' => true, 'report' => [
        'schema_version' => ANALYTICS_SCHEMA_VERSION,
        'event'          => ANALYTICS_EVENT,
        'client'         => ANALYTICS_CLIENT_MARKER,
        'install_id'     => $decoded['install_id'],
        'sent_at'        => $decoded['sent_at'],
        'level'          => $level,
        'properties'     => $normalized,
    ]];
}
