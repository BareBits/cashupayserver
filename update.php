<?php
/**
 * CashuPayServer - Isolated Auto-Update Endpoint
 *
 * This is the crash-resilient entry point for the auto-updater. Unlike
 * cron.php (which require_once's ~15 heavy modules before it ever reaches the
 * update task), this file deliberately depends on *nothing* in includes/ for
 * its recovery-critical path. A bad update that introduces a parse/fatal error
 * somewhere in includes/ therefore cannot stop this endpoint from running,
 * detecting the breakage, and rolling it back.
 *
 * Two phases:
 *
 *   1. VERIFY (self-contained). If a prior run left an "applied but not yet
 *      proven healthy" marker (updater_pending_verify), probe health.php over
 *      HTTP. Healthy -> clear the marker. Unhealthy -> roll back to the marker's
 *      backup, park the bad COMMIT_SHA in updater_blocked_shas so it is never
 *      re-applied, and email the operator. This phase uses only inlined SQLite
 *      + filesystem helpers, so it works even when includes/ is broken.
 *
 *   2. FORWARD APPLY (reuses includes/updater.php). Only reached when there is
 *      no pending marker — i.e. the current install was proven healthy (or is
 *      fresh). At that point includes/ is known-good, so we load the canonical
 *      Updater engine to download/back-up/overlay, then immediately probe
 *      health and verify in the same request.
 *
 * Why HTTP for the health check: a PHP parse/fatal in an included file is
 * uncatchable from the requiring process, so we cannot require() the possibly
 * broken code and try/catch it. We fetch health.php out-of-process instead.
 *
 * Auth: the cron key (?key= or X-CRON-KEY header), same as cron.php.
 * Opt-in: honours CASHUPAY_AUTO_UPDATE_ENABLED (OFF by default) and the same
 * disable switches (CASHUPAY_UPDATER_DISABLED / data/.updater_disabled) the
 * Updater class uses, so iterate.py / the test harness never trigger it.
 *
 * NEVER active in WordPress mode.
 */

declare(strict_types=1);

// Keep running server-side even when the triggering request disconnects early
// (cron.php fires this fire-and-forget with a 200ms timeout). Downloads + the
// health-probe retry window need real wall-clock time.
ignore_user_abort(true);
@set_time_limit(300);

const UPD_CHECK_INTERVAL_SECONDS = 86400; // daily, matches Updater::CHECK_INTERVAL_SECONDS
const UPD_PRESERVE_PATHS = ['data', 'user_config.php'];

$UPD_ROOT = __DIR__;

// Operator constants live in config.local.php / user_config.php, both of which
// are preserved across updates — safe to load even when the rest of includes/
// is broken. Mirrors database.php's load order/precedence.
if (is_file($UPD_ROOT . '/includes/config.local.php')) {
    require_once $UPD_ROOT . '/includes/config.local.php';
}
if (is_file($UPD_ROOT . '/user_config.php')) {
    require_once $UPD_ROOT . '/user_config.php';
}

// ---------------------------------------------------------------------------
// Self-contained helpers (no includes/ dependency)
// ---------------------------------------------------------------------------

function upd_log(string $msg): void {
    @error_log('[update.php] ' . $msg);
}

function upd_root(): string {
    // Test hook: point the backup/rollback/staging tree at a tempdir so the
    // verify/rollback path can be exercised hermetically. Production never
    // sets this. Mirrors Updater::$installRootOverride.
    if (isset($GLOBALS['UPD_ROOT_OVERRIDE']) && is_string($GLOBALS['UPD_ROOT_OVERRIDE'])) {
        return $GLOBALS['UPD_ROOT_OVERRIDE'];
    }
    return __DIR__;
}

/** Matches Database::getDataDir() so we read/write the same SQLite file. */
function upd_data_dir(): string {
    if (defined('CASHUPAY_DATA_DIR')) {
        return rtrim((string)CASHUPAY_DATA_DIR, '/');
    }
    return upd_root() . '/data';
}

function upd_db_path(): string {
    return upd_data_dir() . '/cashupay.sqlite';
}

function upd_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;
    $path = upd_db_path();
    if (!is_file($path)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // Tolerate the main app holding a write lock during our infrequent writes.
        $pdo->exec('PRAGMA busy_timeout=5000');
    } catch (Throwable $e) {
        upd_log('cannot open db: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** Mirrors Config::get(): JSON-decode, falling back to the raw string. */
function upd_config_get(string $key, $default = null) {
    $pdo = upd_db();
    if ($pdo === null) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare('SELECT value FROM config WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $default;
    }
    if ($row === false) {
        return $default;
    }
    $decoded = json_decode((string)$row['value'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $row['value'];
    }
    return $decoded;
}

/** Mirrors Config::set(): strings stored raw, everything else JSON-encoded. */
function upd_config_set(string $key, $value): void {
    $pdo = upd_db();
    if ($pdo === null) {
        return;
    }
    $stored = is_string($value) ? $value : json_encode($value);
    $now = time();
    try {
        // SQLite UPSERT keeps created_at on first insert, bumps updated_at.
        $stmt = $pdo->prepare(
            'INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([$key, $stored, $now, $now]);
    } catch (Throwable $e) {
        upd_log("config_set($key) failed: " . $e->getMessage());
    }
}

/** Reads a deployment setting, preferring the PHP constant over the env var. */
function upd_setting(string $name) {
    if (defined($name)) {
        $v = constant($name);
        return $v === null ? false : (string)$v;
    }
    return getenv($name);
}

function upd_is_wordpress(): bool {
    return defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS;
}

/** Operator opt-in — mirrors Updater::isAutoUpdateEnabled(). Default OFF. */
function upd_is_enabled(): bool {
    if (defined('CASHUPAY_AUTO_UPDATE_ENABLED') && CASHUPAY_AUTO_UPDATE_ENABLED) {
        return true;
    }
    $env = getenv('CASHUPAY_AUTO_UPDATE_ENABLED');
    return $env !== false && $env !== '' && $env !== '0';
}

/** Test/dev kill switch — mirrors Updater::isDisabledForTests(). */
function upd_is_disabled_for_tests(): bool {
    if (defined('CASHUPAY_UPDATER_DISABLED') && CASHUPAY_UPDATER_DISABLED) {
        return true;
    }
    $env = getenv('CASHUPAY_UPDATER_DISABLED');
    if ($env !== false && $env !== '' && $env !== '0') {
        return true;
    }
    $dataDir = defined('CASHUPAY_DATA_DIR')
        ? (string)CASHUPAY_DATA_DIR
        : (getenv('CASHUPAY_DATA_DIR') ?: upd_data_dir());
    return $dataDir !== '' && is_file($dataDir . '/.updater_disabled');
}

/** Mirrors Config::getBaseUrl() — config override, else autodetect. */
function upd_base_url(): string {
    $configured = upd_config_get('base_url');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    return rtrim($protocol . '://' . $host . $path, '/');
}

// ---------------- Health probe ----------------

/**
 * Probe health.php out-of-process. Returns one of:
 *   'healthy'      — HTTP 2xx with {"ok":true}
 *   'unhealthy'    — server responded but the bootstrap is broken (5xx, or
 *                    2xx {"ok":false}); a definitive signal to roll back
 *   'inconclusive' — no response, or 403 (key mismatch). NOT a rollback signal;
 *                    we keep the pending marker and re-check on the next run so
 *                    a transient loopback/TLS hiccup never triggers a false
 *                    rollback of a perfectly good update.
 */
function upd_health_status(string $cronKey): string {
    $url = upd_base_url() . '/health.php';
    $last = 'inconclusive';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            sleep(2);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false, // localhost self-request, same as Background::trigger
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['X-CRON-KEY: ' . $cronKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0 || $code === 0) {
            $last = 'inconclusive';
            continue;
        }
        if ($code >= 200 && $code < 300) {
            $json = json_decode((string)$body, true);
            if (is_array($json) && ($json['ok'] ?? false) === true) {
                return 'healthy';
            }
            $last = 'unhealthy'; // loaded but e.g. DB unreachable
            continue;
        }
        if ($code === 403) {
            $last = 'inconclusive'; // auth/key problem, not a health signal
            continue;
        }
        if ($code >= 500) {
            $last = 'unhealthy'; // bootstrap fatal -> exactly what a bad update looks like
            continue;
        }
        $last = 'inconclusive';
    }
    return $last;
}

// ---------------- Blocked SHAs ----------------

function upd_get_blocked_shas(): array {
    $v = upd_config_get('updater_blocked_shas', []);
    if (!is_array($v)) {
        return [];
    }
    return array_values(array_filter($v, 'is_string'));
}

function upd_block_sha(string $sha): void {
    if ($sha === '') {
        return;
    }
    $list = upd_get_blocked_shas();
    if (!in_array($sha, $list, true)) {
        $list[] = $sha;
        upd_config_set('updater_blocked_shas', $list);
    }
}

// ---------------- Inline rollback (mirrors recover.php / Updater::rollbackTo) ----------------

function upd_is_preserved(string $rel): bool {
    foreach (UPD_PRESERVE_PATHS as $p) {
        if ($rel === $p || str_starts_with($rel, $p . '/')) {
            return true;
        }
    }
    return false;
}

function upd_rel(string $full, string $base): string {
    $base = rtrim($base, '/');
    if (str_starts_with($full, $base . '/')) {
        return substr($full, strlen($base) + 1);
    }
    return $full;
}

function upd_ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function upd_list_backups(): array {
    $dir = upd_root() . '/data/updates/backup';
    if (!is_dir($dir)) {
        return [];
    }
    $entries = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_dir($dir . '/' . $name)) {
            $entries[] = $name;
        }
    }
    rsort($entries); // newest first by Ymd-His prefix
    return $entries;
}

/**
 * Restore $backupName over the live install, preserving data/ and
 * user_config.php. Returns true on success. Self-contained (no Updater) so it
 * works when includes/ is broken.
 */
function upd_rollback(string $backupName): bool {
    $root = upd_root();
    $backupDir = $root . '/data/updates/backup/' . $backupName;
    if ($backupName === '' || !is_dir($backupDir)) {
        return false;
    }

    $lockPath = $root . '/data/updates/.lock';
    upd_ensure_dir(dirname($lockPath));
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        upd_log('rollback lock held, skipping');
        return false;
    }

    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel = upd_rel($item->getPathname(), $backupDir);
            if (upd_is_preserved($rel)) {
                continue;
            }
            $dest = $root . '/' . $rel;
            if ($item->isDir()) {
                upd_ensure_dir($dest);
            } else {
                upd_ensure_dir(dirname($dest));
                @copy($item->getPathname(), $dest);
            }
        }
    } catch (Throwable $e) {
        upd_log('rollback copy error: ' . $e->getMessage());
        return false;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    // The recovery token was minted for the (now reverted) bad build; drop it.
    @unlink($root . '/data/updates/recovery_token.txt');
    return true;
}

// ---------------- Operator alert email ----------------

/**
 * Email the operator after an auto-rollback. Called only AFTER a rollback has
 * restored the known-good code, so loading includes/email_sender.php here is
 * safe. Recipient: the site-wide notifications_to_email, else the SMTP from
 * address. Best-effort — any failure is logged and swallowed.
 */
function upd_send_alert_email(string $subject, string $body): void {
    try {
        $to = trim((string)upd_config_get('notifications_to_email', ''));
        if ($to === '') {
            $from = upd_setting('CASHUPAY_SMTP_FROM_ADDRESS');
            $to = is_string($from) ? trim($from) : '';
        }
        if ($to === '') {
            upd_log('no alert recipient (notifications_to_email / SMTP from) configured; skipping email');
            return;
        }
        $from = upd_setting('CASHUPAY_SMTP_FROM_ADDRESS');
        if (!is_string($from) || trim($from) === '') {
            upd_log('CASHUPAY_SMTP_FROM_ADDRESS not set; skipping alert email');
            return;
        }
        // These live in the (now restored) real install regardless of any
        // test root override, so resolve them against this file's directory.
        require_once __DIR__ . '/includes/config.php';
        require_once __DIR__ . '/includes/email_sender.php';
        EmailSender::send($to, $subject, $body);
        upd_log("alert email sent to $to");
    } catch (Throwable $e) {
        upd_log('alert email failed: ' . $e->getMessage());
    }
}

// ---------------- Verify a pending (just-applied) update ----------------

/**
 * Probe health for a pending update and act on the verdict. Returns a result
 * array describing what happened (echoed back to the caller for visibility).
 */
function upd_verify_pending(array $pending, string $cronKey): array {
    $sha = (string)($pending['sha'] ?? '');
    $status = upd_health_status($cronKey);

    if ($status === 'healthy') {
        upd_config_set('updater_pending_verify', null);
        upd_log("update $sha verified healthy");
        return ['phase' => 'verify', 'result' => 'healthy', 'sha' => $sha];
    }

    if ($status === 'inconclusive') {
        // No definitive failure — keep the marker and re-check next run rather
        // than risk rolling back a good update over a transient blip.
        upd_log("update $sha health check inconclusive; will re-check next run");
        return ['phase' => 'verify', 'result' => 'inconclusive', 'sha' => $sha];
    }

    // Unhealthy: roll back, block the SHA, alert.
    $backup = (string)($pending['backup'] ?? '');
    $rolledBack = $backup !== '' ? upd_rollback($backup) : false;
    if (!$rolledBack && $backup === '') {
        $backups = upd_list_backups();
        if (!empty($backups)) {
            $backup = $backups[0];
            $rolledBack = upd_rollback($backup);
        }
    }

    upd_block_sha($sha);
    upd_config_set('updater_pending_verify', null);
    // Don't immediately re-check for a (possibly same) update on the next tick.
    upd_config_set('updater_last_check', time());
    upd_config_set('updater_last_auto_rollback', [
        'bad_sha' => $sha,
        'version' => (string)($pending['version'] ?? ''),
        'from_version' => (string)($pending['from_version'] ?? ''),
        'backup' => $backup,
        'rolled_back' => $rolledBack,
        'rolled_back_at' => time(),
    ]);
    upd_config_set('updater_auto_rollback_dismissed', false);

    upd_log(sprintf(
        'update %s FAILED health check; rollback to %s %s; SHA blocked',
        $sha,
        $backup !== '' ? $backup : '(none)',
        $rolledBack ? 'succeeded' : 'FAILED'
    ));

    $base = upd_base_url();
    $subject = '[CashuPayServer] Auto-update rolled back';
    $body = "An automatic update failed its post-install health check and was rolled back.\n\n"
        . 'Broken build: ' . (string)($pending['version'] ?? 'unknown') . ' (' . substr($sha, 0, 12) . ")\n"
        . 'Restored backup: ' . ($backup !== '' ? $backup : '(none available)') . "\n"
        . 'Rollback ' . ($rolledBack ? 'succeeded' : 'FAILED — manual recovery may be required') . ".\n\n"
        . "This COMMIT_SHA is now blocked and will not be re-applied. The updater\n"
        . "will resume normally once the channel advances to a different build.\n\n"
        . 'Admin: ' . $base . "/admin.php\n";
    if (!$rolledBack) {
        $body .= 'Manual recovery: ' . $base . "/recover.php\n";
    }
    upd_send_alert_email($subject, $body);

    return [
        'phase' => 'verify',
        'result' => 'rolled_back',
        'sha' => $sha,
        'backup' => $backup,
        'rolled_back' => $rolledBack,
    ];
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

// Test hook: when defined, this file only DEFINES the upd_* helpers and does
// not run the request flow, so unit tests can require it and call functions
// directly. Production never defines this constant.
if (defined('CASHUPAY_UPDATE_PHP_NO_RUN') && CASHUPAY_UPDATE_PHP_NO_RUN) {
    return;
}

header('Content-Type: application/json');

// Auth — cron key via header (preferred, stays out of access logs) or query.
$providedKey = $_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? '';
$cronKey = upd_config_get('cron_key');
if (!$cronKey || !is_string($providedKey) || !hash_equals((string)$cronKey, (string)$providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Gating — identical policy to Updater::checkAndApply().
if (upd_is_wordpress()) {
    echo json_encode(['ok' => true, 'skipped' => 'wordpress']);
    exit;
}
if (!upd_is_enabled()) {
    echo json_encode(['ok' => true, 'skipped' => 'auto_update_disabled']);
    exit;
}
if (upd_is_disabled_for_tests()) {
    echo json_encode(['ok' => true, 'skipped' => 'updater_disabled']);
    exit;
}

// Phase 1: verify any pending update FIRST, regardless of the daily interval.
// This is the self-contained recovery path that must work even if includes/
// is broken — so it never touches the Updater class.
$pending = upd_config_get('updater_pending_verify');
if (is_array($pending) && !empty($pending['sha'])) {
    echo json_encode(['ok' => true] + upd_verify_pending($pending, (string)$cronKey));
    exit;
}

// Daily throttle for the *forward* check (the verify path above bypasses it).
$now = time();
$lastCheck = (int)upd_config_get('updater_last_check', 0);
if ($lastCheck && ($now - $lastCheck) < UPD_CHECK_INTERVAL_SECONDS) {
    echo json_encode(['ok' => true, 'skipped' => 'not_due']);
    exit;
}

// Phase 2: forward apply. Safe to load the canonical engine now — no pending
// marker means the current install was proven healthy (or is fresh), so
// includes/ is known-good. Updater::checkAndApply() handles the blocked-SHA
// skip, download, backup and overlay, and sets the pending-verify marker.
require_once upd_root() . '/includes/updater.php';

$applied = false;
try {
    $applied = Updater::checkAndApply();
} catch (Throwable $e) {
    upd_log('checkAndApply error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'apply_failed', 'message' => $e->getMessage()]);
    exit;
}

if (!$applied) {
    echo json_encode(['ok' => true, 'result' => 'no_update']);
    exit;
}

// An update was applied and a pending-verify marker was written. Probe health
// right now for a fast verdict; if the probe is inconclusive the marker stays
// and the next run re-checks.
$pending = upd_config_get('updater_pending_verify');
if (is_array($pending) && !empty($pending['sha'])) {
    echo json_encode(['ok' => true, 'applied' => true] + upd_verify_pending($pending, (string)$cronKey));
    exit;
}

echo json_encode(['ok' => true, 'result' => 'applied_no_marker']);
