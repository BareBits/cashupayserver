<?php
/**
 * CashuPayServer - Auto-Update Module
 *
 * Fetches a fresh build of the standalone zip from a moving-tag GitHub
 * release (`channel-main` / `channel-testing`), overlays it on the
 * current install, and supports rollback via stored backups.
 *
 * Deployed servers have no shell, composer, npm, or git — only PHP. The
 * release artifact is a fully pre-built zip (vendor/, JS bundle, etc.
 * baked in by CI). The updater needs only:
 *   - curl (project requirement)
 *   - PHP's ZipArchive (core)
 *   - Filesystem write access to the install directory
 *
 * NEVER active in WordPress mode — WP has its own update path.
 */

require_once __DIR__ . '/config.php';

class Updater {
    public const GH_OWNER = 'BareBits';
    public const GH_REPO = 'cashupayserver';
    public const CHECK_INTERVAL_SECONDS = 86400; // daily
    public const BACKUPS_TO_KEEP = 3;
    public const HTTP_USER_AGENT = 'cashupayserver-updater';

    /**
     * Override for the release-tag API base URL. Tests point this at a
     * local fixture server. Null = use GitHub. Production code never
     * touches this; production callers should not see this property.
     */
    public static ?string $releaseApiUrlBase = null;

    /**
     * Override for the install root. Tests point this at a tempdir so
     * the overlay / backup / rollback paths can run hermetically.
     * Null = use the project root (parent of includes/).
     */
    public static ?string $installRootOverride = null;

    // Files/dirs that must survive an update (relative to install root).
    private const PRESERVE_PATHS = [
        'data',
        'user_config.php',
    ];

    /**
     * Top-level entry point called from cron.php.
     * Returns true if an update was applied, false otherwise.
     */
    public static function checkAndApply(): bool {
        if (self::isWordPressMode()) {
            return false;
        }
        // Test-harness kill switch. Honoured when running under iterate.py
        // or the pytest payserver fixture so a long-running stack doesn't
        // overlay an in-progress dev branch with the latest channel-main
        // build mid-iteration. Operators should not set this — the WP
        // build remains the supported "no auto-update" path.
        if (self::isDisabledForTests()) {
            return false;
        }

        $now = time();
        $lastCheck = (int)Config::get('updater_last_check', 0);
        if ($lastCheck && ($now - $lastCheck) < self::CHECK_INTERVAL_SECONDS) {
            return false;
        }
        Config::set('updater_last_check', $now);

        $channel = self::getChannel();
        $remote = self::fetchRemoteBuildInfo($channel);
        if ($remote === null) {
            self::log("could not fetch remote BUILD_INFO for channel $channel");
            return false;
        }

        $localSha = self::getLocalCommitSha();
        if ($localSha !== '' && $localSha === ($remote['COMMIT_SHA'] ?? '')) {
            // Already current.
            return false;
        }

        // Take the lock — concurrent cron runs must not race.
        $lockPath = self::installRoot() . '/data/updates/.lock';
        self::ensureDir(dirname($lockPath));
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::log('updater lock held by another run, skipping');
            return false;
        }

        try {
            return self::applyUpdate($channel, $remote);
        } catch (Throwable $e) {
            self::log('update failed: ' . $e->getMessage());
            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function getChannel(): string {
        $fromDb = Config::get('update_channel');
        if (is_string($fromDb) && $fromDb !== '') {
            return self::normalizeChannel($fromDb);
        }
        if (defined('CASHUPAY_UPDATE_CHANNEL') && is_string(CASHUPAY_UPDATE_CHANNEL)) {
            return self::normalizeChannel(CASHUPAY_UPDATE_CHANNEL);
        }
        return 'main';
    }

    public static function setChannel(string $channel): void {
        Config::set('update_channel', self::normalizeChannel($channel));
    }

    private static function normalizeChannel(string $channel): string {
        return $channel === 'testing' ? 'testing' : 'main';
    }

    public static function installRoot(): string {
        return self::$installRootOverride ?? dirname(__DIR__);
    }

    private static function isWordPressMode(): bool {
        return defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS;
    }

    /**
     * Test-harness opt-out. Honoured when any of these is true:
     *   - PHP constant CASHUPAY_UPDATER_DISABLED is defined and truthy
     *   - Env var CASHUPAY_UPDATER_DISABLED is non-empty and not "0"
     *   - A sentinel file `.updater_disabled` exists in CASHUPAY_DATA_DIR
     *     (or the default data/ dir, if no CASHUPAY_DATA_DIR override).
     *
     * The sentinel-file path exists for iterate.py / pytest stacks: a stale
     * env var on an external cron hit (or any other path that bypasses the
     * fixture-spawned process tree) won't fire the updater and overwrite
     * an in-progress dev branch.
     */
    private static function isDisabledForTests(): bool {
        if (defined('CASHUPAY_UPDATER_DISABLED') && CASHUPAY_UPDATER_DISABLED) {
            return true;
        }
        $env = getenv('CASHUPAY_UPDATER_DISABLED');
        if ($env !== false && $env !== '' && $env !== '0') {
            return true;
        }
        $dataDir = defined('CASHUPAY_DATA_DIR')
            ? (string)CASHUPAY_DATA_DIR
            : (getenv('CASHUPAY_DATA_DIR') ?: (self::installRoot() . '/data'));
        if ($dataDir !== '' && is_file($dataDir . '/.updater_disabled')) {
            return true;
        }
        return false;
    }

    // ---------------- Remote / local build info ----------------

    /**
     * Fetch the BUILD_INFO asset attached to the channel-<channel> release.
     * Returns the parsed key=value pairs, or null on failure.
     */
    private static function fetchRemoteBuildInfo(string $channel): ?array {
        $tag = 'channel-' . $channel;
        $base = self::$releaseApiUrlBase ?? sprintf(
            'https://api.github.com/repos/%s/%s/releases/tags/',
            self::GH_OWNER,
            self::GH_REPO
        );
        $apiUrl = $base . $tag;
        $release = self::httpGetJson($apiUrl);
        if (!is_array($release) || empty($release['assets'])) {
            return null;
        }

        $buildInfoUrl = null;
        $zipUrl = null;
        foreach ($release['assets'] as $asset) {
            $name = $asset['name'] ?? '';
            $url = $asset['browser_download_url'] ?? '';
            if ($name === 'BUILD_INFO') {
                $buildInfoUrl = $url;
            } elseif ($name === 'cashupayserver.zip') {
                $zipUrl = $url;
            }
        }
        if ($buildInfoUrl === null || $zipUrl === null) {
            return null;
        }

        $raw = self::httpGet($buildInfoUrl);
        if ($raw === null) {
            return null;
        }
        $info = self::parseBuildInfo($raw);
        $info['__zip_url'] = $zipUrl;
        return $info;
    }

    public static function getLocalBuildInfo(): array {
        $path = self::installRoot() . '/BUILD_INFO';
        if (!is_file($path)) {
            return [];
        }
        return self::parseBuildInfo((string)file_get_contents($path));
    }

    private static function getLocalCommitSha(): string {
        $info = self::getLocalBuildInfo();
        return (string)($info['COMMIT_SHA'] ?? '');
    }

    private static function parseBuildInfo(string $raw): array {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $out[$key] = $val;
        }
        return $out;
    }

    // ---------------- Apply update ----------------

    private static function applyUpdate(string $channel, array $remote): bool {
        $root = self::installRoot();
        $sha = (string)($remote['COMMIT_SHA'] ?? '');
        if ($sha === '') {
            self::log('remote BUILD_INFO missing COMMIT_SHA');
            return false;
        }

        $updatesDir = $root . '/data/updates';
        $stagingDir = $updatesDir . '/staging/' . $sha;
        $zipPath = $updatesDir . '/staging/' . $sha . '.zip';
        self::ensureDir($stagingDir);

        // Download the zip.
        if (!self::httpDownload($remote['__zip_url'], $zipPath)) {
            self::log('zip download failed');
            self::rmrf($stagingDir);
            @unlink($zipPath);
            return false;
        }

        // Extract.
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            self::log('zip open failed');
            @unlink($zipPath);
            self::rmrf($stagingDir);
            return false;
        }
        if (!$zip->extractTo($stagingDir)) {
            $zip->close();
            self::log('zip extract failed');
            @unlink($zipPath);
            self::rmrf($stagingDir);
            return false;
        }
        $zip->close();
        @unlink($zipPath);

        // The build script wraps everything under cashupayserver/, so the
        // real source dir is staging/<sha>/cashupayserver.
        $sourceDir = $stagingDir . '/cashupayserver';
        if (!is_dir($sourceDir) || !is_file($sourceDir . '/BUILD_INFO')) {
            self::log('extracted zip missing expected cashupayserver/ layout');
            self::rmrf($stagingDir);
            return false;
        }

        // Snapshot the current install before touching anything live.
        $oldVersion = (string)(self::getLocalBuildInfo()['VERSION'] ?? 'unknown');
        $oldSha = self::getLocalCommitSha();
        $backupDir = $updatesDir . '/backup/' . date('Ymd-His') . '-' . substr($oldSha ?: 'init', 0, 12);
        self::ensureDir($backupDir);
        if (!self::backupInstall($root, $backupDir)) {
            self::log('backup failed, aborting before live overlay');
            self::rmrf($stagingDir);
            self::rmrf($backupDir);
            return false;
        }

        // Overlay.
        $oldHtaccessSha = self::getLocalBuildInfo()['HTACCESS_SHA256'] ?? '';
        $htaccessUntouched = self::overlayInstall($sourceDir, $root, $oldHtaccessSha, $remote);

        // Cleanup staging.
        self::rmrf($stagingDir);

        // Rotate backups.
        self::pruneBackups($updatesDir . '/backup', self::BACKUPS_TO_KEEP);

        // Record the result.
        $newVersion = (string)($remote['VERSION'] ?? 'unknown');
        $token = bin2hex(random_bytes(32));
        file_put_contents($updatesDir . '/recovery_token.txt', $token);
        @chmod($updatesDir . '/recovery_token.txt', 0600);

        Config::set('updater_last_update', [
            'from_version' => $oldVersion,
            'to_version' => $newVersion,
            'from_sha' => $oldSha,
            'to_sha' => $sha,
            'channel' => $channel,
            'applied_at' => time(),
            'htaccess_held_back' => !$htaccessUntouched,
        ]);
        Config::set('updater_banner_dismissed', false);

        self::log("update applied: $oldVersion -> $newVersion ($channel)");
        return true;
    }

    /**
     * Copy the current install (minus preserved data and backups themselves)
     * into $backupDir. Used both for rollback and as a transactional safety net.
     */
    private static function backupInstall(string $root, string $backupDir): bool {
        $iter = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function ($current, $key, $iterator) use ($root) {
                    $rel = self::relPath($current->getPathname(), $root);
                    if ($rel === 'data' || str_starts_with($rel, 'data/')) {
                        return false; // never back up runtime data into the backup tree
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel = self::relPath($item->getPathname(), $root);
            $dest = $backupDir . '/' . $rel;
            if ($item->isDir()) {
                self::ensureDir($dest);
            } else {
                self::ensureDir(dirname($dest));
                if (!@copy($item->getPathname(), $dest)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Overlay $source onto $root, skipping PRESERVE_PATHS and handling
     * .htaccess specially. Returns true if .htaccess was left untouched
     * (because user had modified it), false if it was overwritten.
     */
    private static function overlayInstall(string $source, string $root, string $oldHtaccessSha, array $remote): bool {
        $htaccessHeldBack = false;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $item) {
            $rel = self::relPath($item->getPathname(), $source);
            if (self::isPreserved($rel)) {
                continue;
            }
            $dest = $root . '/' . $rel;

            // .htaccess handling — overwrite only if untouched since last build.
            if ($rel === '.htaccess') {
                if (is_file($dest) && $oldHtaccessSha !== '') {
                    $liveSha = hash_file('sha256', $dest);
                    if ($liveSha !== $oldHtaccessSha) {
                        // User modified .htaccess — write new version as
                        // .htaccess.new and leave the live file alone.
                        @copy($item->getPathname(), $root . '/.htaccess.new');
                        $htaccessHeldBack = true;
                        continue;
                    }
                }
                @copy($item->getPathname(), $dest);
                continue;
            }

            if ($item->isDir()) {
                self::ensureDir($dest);
            } else {
                self::ensureDir(dirname($dest));
                @copy($item->getPathname(), $dest);
            }
        }

        return !$htaccessHeldBack;
    }

    private static function isPreserved(string $rel): bool {
        foreach (self::PRESERVE_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    // ---------------- Rollback ----------------

    public static function listBackups(): array {
        $dir = self::installRoot() . '/data/updates/backup';
        if (!is_dir($dir)) {
            return [];
        }
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $entries[] = $name;
            }
        }
        rsort($entries); // newest first by Ymd-His prefix
        return $entries;
    }

    public static function rollbackToMostRecent(): bool {
        $backups = self::listBackups();
        if (empty($backups)) {
            return false;
        }
        return self::rollbackTo($backups[0]);
    }

    public static function rollbackTo(string $backupName): bool {
        $root = self::installRoot();
        $backupDir = $root . '/data/updates/backup/' . $backupName;
        if (!is_dir($backupDir)) {
            return false;
        }

        // Same lock used by the forward path.
        $lockPath = $root . '/data/updates/.lock';
        self::ensureDir(dirname($lockPath));
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::log('rollback lock held, skipping');
            return false;
        }

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                $rel = self::relPath($item->getPathname(), $backupDir);
                if (self::isPreserved($rel)) {
                    continue;
                }
                $dest = $root . '/' . $rel;
                if ($item->isDir()) {
                    self::ensureDir($dest);
                } else {
                    self::ensureDir(dirname($dest));
                    @copy($item->getPathname(), $dest);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        Config::set('updater_last_rollback', [
            'backup' => $backupName,
            'rolled_back_at' => time(),
        ]);
        // After rollback, force a re-check so we don't immediately re-apply.
        Config::set('updater_last_check', time());
        self::log("rolled back to $backupName");
        return true;
    }

    private static function pruneBackups(string $dir, int $keep): void {
        $entries = self::listBackups();
        $i = 0;
        foreach ($entries as $name) {
            if ($i++ < $keep) continue;
            self::rmrf($dir . '/' . $name);
        }
    }

    // ---------------- Recovery token ----------------

    public static function verifyRecoveryToken(string $provided): bool {
        $path = self::installRoot() . '/data/updates/recovery_token.txt';
        if (!is_file($path)) {
            return false;
        }
        $stored = trim((string)file_get_contents($path));
        if ($stored === '' || $provided === '') {
            return false;
        }
        return hash_equals($stored, $provided);
    }

    public static function consumeRecoveryToken(): void {
        $path = self::installRoot() . '/data/updates/recovery_token.txt';
        @unlink($path);
    }

    // ---------------- HTTP ----------------

    private static function httpGet(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::HTTP_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        return (string)$body;
    }

    private static function httpGetJson(string $url): ?array {
        $body = self::httpGet($url);
        if ($body === null) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function httpDownload(string $url, string $dest): bool {
        $fp = @fopen($dest, 'w');
        if ($fp === false) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::HTTP_USER_AGENT,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    // ---------------- Filesystem helpers ----------------

    private static function ensureDir(string $dir): void {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private static function rmrf(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private static function relPath(string $full, string $base): string {
        $base = rtrim($base, '/');
        if (str_starts_with($full, $base . '/')) {
            return substr($full, strlen($base) + 1);
        }
        return $full;
    }

    private static function log(string $msg): void {
        @error_log('[updater] ' . $msg);
    }
}
