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
require_once __DIR__ . '/safe_http.php';

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

    // Test hook: when non-null, bypasses the CASHUPAY_AUTO_UPDATE_ENABLED
    // opt-in check (which is otherwise needed for the updater to run at all).
    // Production code paths leave this at null; updater unit tests set it to
    // true for the duration of a single case.
    public static ?bool $autoUpdateEnabledOverride = null;

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
        // Operator opt-in. Auto-update is OFF by default for fresh installs;
        // an operator who wants it has to set CASHUPAY_AUTO_UPDATE_ENABLED in
        // user_config.php (or as an env var). See user_config.example.php.
        if (!self::isAutoUpdateEnabled()) {
            return false;
        }
        // Test-harness kill switch. Honoured when running under iterate.py
        // or the pytest payserver fixture so a long-running stack doesn't
        // overlay an in-progress dev branch with the latest channel-main
        // build mid-iteration.
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

        // Forward-failure guard: a SHA that previously broke the install (its
        // post-update health check failed and it was auto-rolled-back) is
        // parked in updater_blocked_shas. Never re-apply it — otherwise we'd
        // loop apply -> crash -> rollback -> apply on every tick until the
        // maintainer ships a different build. The block clears itself simply
        // by the channel moving on to a new COMMIT_SHA.
        $remoteSha = (string)($remote['COMMIT_SHA'] ?? '');
        if ($remoteSha !== '' && in_array($remoteSha, self::getBlockedShas(), true)) {
            self::log("remote SHA $remoteSha is blocked (failed a prior health check), skipping");
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
     * Operator-facing opt-in for the auto-updater. Returns true when:
     *   - PHP constant CASHUPAY_AUTO_UPDATE_ENABLED is defined and truthy, or
     *   - Env var CASHUPAY_AUTO_UPDATE_ENABLED is non-empty and not "0", or
     *   - Updater::$autoUpdateEnabledOverride is set to true (test hook).
     *
     * Default (constant undefined, env unset, override null) is false — fresh
     * installs do not auto-update. Operators who want it must opt in.
     */
    public static function isAutoUpdateEnabled(): bool {
        if (self::$autoUpdateEnabledOverride !== null) {
            return self::$autoUpdateEnabledOverride;
        }
        if (defined('CASHUPAY_AUTO_UPDATE_ENABLED') && CASHUPAY_AUTO_UPDATE_ENABLED) {
            return true;
        }
        $env = getenv('CASHUPAY_AUTO_UPDATE_ENABLED');
        return ($env !== false && $env !== '' && $env !== '0');
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

        // Mark this build "applied but not yet proven healthy" BEFORE we touch
        // any live file. The overlay below is the one window where a crash can
        // leave the install half-old/half-new; writing the recovery breadcrumb
        // first guarantees the next update.php run finds it and rolls back from
        // `backup`, even if we die mid-overlay. (update.php clears this marker
        // once health.php probes green; if the probe fails or the process dies,
        // the next run re-probes and auto-rolls-back to `backup`, parking `sha`
        // in updater_blocked_shas.) The marker lives in the DB under data/,
        // which the overlay never touches.
        $newVersion = (string)($remote['VERSION'] ?? 'unknown');
        Config::set('updater_pending_verify', [
            'sha' => $sha,
            'version' => $newVersion,
            'from_version' => $oldVersion,
            'backup' => basename($backupDir),
            'applied_at' => time(),
        ]);

        // Overlay. Each file is replaced atomically (staged to a temp path in
        // the destination dir, then renamed into place) so no live file is ever
        // observed truncated/half-written even if we crash mid-copy.
        $oldHtaccessSha = self::getLocalBuildInfo()['HTACCESS_SHA256'] ?? '';
        $htaccessUntouched = self::overlayInstall($sourceDir, $root, $oldHtaccessSha, $remote);

        // Cleanup staging.
        self::rmrf($stagingDir);

        // Rotate backups.
        self::pruneBackups($updatesDir . '/backup', self::BACKUPS_TO_KEEP);

        // Record the result.
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
        // updater_pending_verify was written before the overlay (above) so a
        // mid-overlay crash still leaves the rollback breadcrumb; update.php
        // clears it once health.php probes green.

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
                        self::atomicCopy($item->getPathname(), $root . '/.htaccess.new');
                        $htaccessHeldBack = true;
                        continue;
                    }
                }
                self::atomicCopy($item->getPathname(), $dest);
                continue;
            }

            if ($item->isDir()) {
                self::ensureDir($dest);
            } else {
                self::ensureDir(dirname($dest));
                self::atomicCopy($item->getPathname(), $dest);
            }
        }

        return !$htaccessHeldBack;
    }

    /**
     * Replace $dest with the contents of $src atomically: copy to a temp file
     * in the SAME directory (so rename stays on one filesystem), preserve the
     * source's permission bits, then rename over the destination. rename() is
     * atomic on POSIX, so a concurrent reader / a crash never sees a partially
     * written destination — it sees either the old file or the complete new one.
     * Best-effort (mirrors the previous @copy): returns false on failure without
     * throwing, leaving the existing file intact.
     */
    private static function atomicCopy(string $src, string $dest): bool {
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
        if (!@copy($src, $tmp)) {
            @unlink($tmp);
            return false;
        }
        $perms = @fileperms($src);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return false;
        }
        return true;
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

    // ---------------- Health-verify / blocked SHAs ----------------
    //
    // The orchestration around these (probe health.php, decide healthy vs
    // broken, roll back) lives in the standalone update.php so it keeps
    // working when a bad update breaks includes/. These accessors are the
    // shared, canonical readers/writers for the config keys update.php and
    // the admin UI both touch, so the two never drift on key names or shape.

    /** SHAs whose post-update health check failed — never auto-applied again. */
    public static function getBlockedShas(): array {
        $v = Config::get('updater_blocked_shas', []);
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_string'));
    }

    public static function blockSha(string $sha): void {
        if ($sha === '') {
            return;
        }
        $list = self::getBlockedShas();
        if (!in_array($sha, $list, true)) {
            $list[] = $sha;
            Config::set('updater_blocked_shas', $list);
        }
    }

    /** The "applied but not yet proven healthy" marker, or null. */
    public static function getPendingVerify(): ?array {
        $v = Config::get('updater_pending_verify');
        return is_array($v) ? $v : null;
    }

    public static function clearPendingVerify(): void {
        Config::set('updater_pending_verify', null);
    }

    /**
     * Fire-and-forget self-request to the isolated update.php endpoint.
     *
     * Called from cron.php (Task 12) as the *fallback* trigger for installs
     * that only wired up the single cron line. The dedicated `update.php`
     * cron entry is the primary, crash-isolated path; this just nudges the
     * same endpoint so single-cron installs keep updating. Mirrors
     * Background::trigger(): short timeout, the server side runs to
     * completion via ignore_user_abort() regardless of the early disconnect.
     */
    public static function triggerSelfCheck(): void {
        if (self::isWordPressMode()) {
            return;
        }
        $cronKey = Config::get('cron_key');
        if (!$cronKey) {
            return;
        }
        $url = rtrim(Config::getBaseUrl(), '/') . '/update.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 200,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false, // localhost self-request; same as Background::trigger
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['X-CRON-KEY: ' . $cronKey],
        ]);
        @curl_exec($ch);
    }

    // ---------------- HTTP ----------------

    private static function httpGet(string $url): ?string {
        // Release URLs are typically GitHub but the channel can be
        // overridden by the operator. Honor the same allow-private opt-in
        // we use elsewhere so tests can serve releases from a local
        // fixture and self-hosters can mirror releases on their LAN.
        $result = \SafeHttp::request($url, [
            'timeout' => 60,
            'connectTimeout' => 10,
            'userAgent' => self::HTTP_USER_AGENT,
            'headers' => ['Accept: */*'],
            'followRedirects' => true,
            'maxRedirects' => 5,
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '' || $result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }
        return $result['body'];
    }

    private static function httpGetJson(string $url): ?array {
        $body = self::httpGet($url);
        if ($body === null) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function httpDownload(string $url, string $dest): bool {
        // Release tarballs can be tens of MB, so we stream to disk via
        // CURLOPT_FILE rather than buffer in memory through SafeHttp.
        // SafeHttp::validateUrl() still vets the host and pins the IP so
        // a hostile redirect can't pivot to a private address. Same opt-in
        // as httpGet: tests + LAN mirrors need to allow private destinations.
        try {
            $validated = \SafeHttp::validateUrl($url, \SafeHttp::privateEndpointsAllowed());
        } catch (\Throwable $e) {
            return false;
        }

        $fp = @fopen($dest, 'w');
        if ($fp === false) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            // Cap the redirect chain so a misbehaving / hostile mirror can't
            // bounce us through an unbounded sequence of hops.
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::HTTP_USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => $validated['resolve'],
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
