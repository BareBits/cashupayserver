<?php
/**
 * CashuPayServer Authentication Module
 *
 * Admin session management and API key validation.
 */

require_once __DIR__ . '/http_status.php';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/urls.php';

class Auth {
    private const SESSION_NAME = 'cashupay_session';
    private const CSRF_TOKEN_NAME = 'csrf_token';
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_STORE_ADMIN = 'store_admin';
    public const ROLE_USER        = 'user';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_STORE_ADMIN, self::ROLE_USER];

    /**
     * Initialize session. Applies hardened cookie params (HttpOnly + SameSite
     * Lax + Secure on HTTPS) before session_start() — they only take effect
     * when set before the cookie is emitted. Idempotent: subsequent calls in
     * the same request are no-ops.
     *
     * The cookie lives as long as the configured login timeout (see
     * loginTimeout()), so a signed-in operator survives a browser restart;
     * validateSessionUser() slides that expiry forward on activity and ends
     * the session server-side once it has sat idle for the whole timeout.
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? null) == 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https');
            $timeout = self::loginTimeout();
            self::configureSessionStorage($timeout);
            session_set_cookie_params([
                'lifetime' => $timeout,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name(self::SESSION_NAME);
            session_start();
        }
    }

    // ---- Login timeout --------------------------------------------------------

    /** Config key holding the idle login timeout, in seconds. */
    public const LOGIN_TIMEOUT_KEY = 'login_timeout_seconds';
    /** Default idle timeout: 7 days. */
    public const LOGIN_TIMEOUT_DEFAULT = 7 * 86400;
    /**
     * The values the Settings page offers. The saved value must be one of
     * these: a free-form number would let a typo lock everyone out after a
     * second or keep sessions alive for years.
     */
    public const LOGIN_TIMEOUT_CHOICES = [
        900        => '15 minutes',
        1800       => '30 minutes',
        3600       => '1 hour',
        4 * 3600   => '4 hours',
        8 * 3600   => '8 hours',
        86400      => '24 hours',
        7 * 86400  => '7 days',
        30 * 86400 => '30 days',
    ];

    /** Set by validateSessionUser when this request's session died of inactivity. */
    private static bool $timedOut = false;

    /**
     * The idle login timeout in seconds: how long a signed-in session may go
     * without a request before it is ended. Global (one value for every
     * user), admin-configured on the Settings page. Falls back to the default
     * when the database is not reachable yet (pre-setup wizard) or the stored
     * value is not one of the offered choices.
     */
    public static function loginTimeout(): int {
        // A fresh install has no database yet; reading config would create
        // an empty file ahead of the wizard's own first-run detection.
        if (!file_exists(Database::getDbPath())) {
            return self::LOGIN_TIMEOUT_DEFAULT;
        }
        try {
            $v = (int)Config::get(self::LOGIN_TIMEOUT_KEY, self::LOGIN_TIMEOUT_DEFAULT);
        } catch (\Throwable $e) {
            $v = self::LOGIN_TIMEOUT_DEFAULT;
        }
        return isset(self::LOGIN_TIMEOUT_CHOICES[$v]) ? $v : self::LOGIN_TIMEOUT_DEFAULT;
    }

    /**
     * Persist a new idle login timeout. Takes effect for every existing
     * session on its next request (a shorter value can end sessions that
     * are already idle for longer than it).
     *
     * @throws \InvalidArgumentException when $seconds is not an offered choice
     */
    public static function setLoginTimeout(int $seconds): void {
        if (!isset(self::LOGIN_TIMEOUT_CHOICES[$seconds])) {
            throw new \InvalidArgumentException('Login timeout must be one of the offered durations');
        }
        Config::set(self::LOGIN_TIMEOUT_KEY, $seconds);
    }

    /**
     * True when this session was ended by inactivity and nobody has signed
     * in since: either this very request found it expired, or an earlier one
     * did (the fact is kept in the now-anonymous session so the lock screen
     * can still explain itself after the SPA's 401-triggered reload or a
     * redirect). Cleared by establishSession().
     */
    public static function sessionTimedOut(): bool {
        return self::$timedOut
            || (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['login_timed_out_at']));
    }

    /**
     * Point PHP's file-based session store at <data dir>/sessions and align
     * its garbage collector with the login timeout.
     *
     * Left to the host, a long timeout is meaningless: the default
     * session.gc_maxlifetime is 24 minutes, and Debian/Ubuntu packaged PHP
     * disables in-process GC in favour of a cron job that deletes every file
     * in the system session directory older than that — ini_set() cannot
     * reach it. A directory of our own is outside that sweep, so we run
     * PHP's own probabilistic GC there with a lifetime matching the timeout.
     * The data directory already carries the deny-all protections the
     * SQLite file relies on, so session files are no more exposed than it.
     *
     * Only applies to the 'files' handler (an operator who configured redis
     * or memcached keeps their store) and only when the data directory
     * already exists: before the wizard has created it the wizard's own
     * session stays in PHP's default location.
     */
    private static function configureSessionStorage(int $timeout): void {
        if (ini_get('session.save_handler') === 'files') {
            $dataDir = Database::getDataDir();
            if (is_dir($dataDir)) {
                $dir = $dataDir . '/sessions';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                if (is_dir($dir) && is_writable($dir)) {
                    self::adoptLegacySessionFile($dir);
                    @ini_set('session.save_path', $dir);
                }
            }
        }
        // Keep files at least as long as the idle timeout. Sessions are
        // ended by validateSessionUser() exactly at the timeout regardless;
        // GC here is disk hygiene for our own directory.
        @ini_set('session.gc_maxlifetime', (string)$timeout);
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
    }

    /**
     * Shape of a session id PHP mints (session.sid_length 22–256 chars drawn
     * from [a-v0-9] or [A-Za-z0-9,-] depending on sid_bits_per_character).
     * Anything else never names a file we would touch.
     */
    private const SESSION_ID_PATTERN = '/^[A-Za-z0-9,-]{22,256}$/';

    /** Upper bound for a session file we are willing to carry over. */
    private const LEGACY_SESSION_MAX_BYTES = 65536;

    /**
     * Carry this request's session over from PHP's default session directory.
     *
     * Releases before the login timeout kept sessions wherever the host's
     * session.save_path pointed (the system temp dir under php -S, Debian's
     * /var/lib/php/sessions, …). Redirecting save_path to <data dir>/sessions
     * alone would leave every signed-in operator's cookie naming a file PHP
     * can no longer see on the first request after the update: the very
     * dashboard that just applied the update gets 401s and its progress
     * never completes. So when the cookie names a session we do not have yet
     * but the old directory does, move that file across once.
     *
     * Only a well-formed session id is ever turned into a path, only a file
     * that looks like one of ours (it carries user_id) is taken, and a file
     * already present in the new directory is never overwritten. The moved
     * session still goes through validateSessionUser: one minted before the
     * credential fingerprint existed is retired there as before.
     */
    private static function adoptLegacySessionFile(string $dir): void {
        $id = $_COOKIE[self::SESSION_NAME] ?? null;
        if (!is_string($id) || !preg_match(self::SESSION_ID_PATTERN, $id)) {
            return;
        }
        $target = $dir . '/sess_' . $id;
        if (file_exists($target)) {
            return;
        }
        $legacyDir = self::legacySessionDir($dir);
        if ($legacyDir === null) {
            return;
        }
        $source = $legacyDir . '/sess_' . $id;
        if (!is_file($source) || !is_readable($source)) {
            return;
        }
        $size = @filesize($source);
        if ($size === false || $size === 0 || $size > self::LEGACY_SESSION_MAX_BYTES) {
            return;
        }
        $data = @file_get_contents($source);
        if (!is_string($data) || strpos($data, 'user_id') === false) {
            return; // not a sign-in of ours
        }
        if (@rename($source, $target)) {
            @chmod($target, 0600);
            return;
        }
        // rename() fails across filesystems: copy, then drop the original.
        if (@file_put_contents($target, $data, LOCK_EX) !== false) {
            @chmod($target, 0600);
            @unlink($source);
        } else {
            @unlink($target);
        }
    }

    /**
     * The directory PHP's files handler used before configureSessionStorage
     * redirected it: session.save_path as inherited from php.ini / .htaccess
     * ("N;[mode;]/path" forms included) or, when unset, the system temp dir.
     * Null when it does not exist or is already our own directory.
     */
    private static function legacySessionDir(string $own): ?string {
        $raw = trim((string)ini_get('session.save_path'));
        if ($raw !== '') {
            $pos = strrpos($raw, ';');
            if ($pos !== false) {
                $raw = substr($raw, $pos + 1);
            }
        }
        $path = $raw !== '' ? $raw : sys_get_temp_dir();
        if (!is_dir($path)) {
            return null;
        }
        $real = realpath($path);
        $ownReal = realpath($own);
        if ($real === false || ($ownReal !== false && $real === $ownReal)) {
            return null;
        }
        return $real;
    }

    /** Minimum gap between two Set-Cookie refreshes of the same session, seconds. */
    private const COOKIE_REFRESH_INTERVAL = 60;

    /**
     * Slide the session cookie's expiry forward so it tracks the idle
     * timeout. PHP only emits the cookie when the id is new or regenerated,
     * so without this a 7-day cookie minted at login would expire 7 days
     * after login no matter how active the operator was. Throttled so a
     * burst of dashboard requests does not stamp a Set-Cookie on each one.
     */
    private static function refreshSessionCookie(int $timeout): void {
        $now = time();
        $sentAt = (int)($_SESSION['cookie_sent_at'] ?? 0);
        if ($now - $sentAt < self::COOKIE_REFRESH_INTERVAL) {
            return;
        }
        if (headers_sent() || !ini_get('session.use_cookies')) {
            return;
        }
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => $now + $timeout,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        $_SESSION['cookie_sent_at'] = $now;
    }

    /** Per-request cache of the validated session user (see validateSessionUser). */
    private static ?array $sessionUser = null;
    private static bool $sessionChecked = false;

    /**
     * Check if a user is logged in (any role).
     *
     * A session is only as alive as the credential it was minted with: the
     * user row must still exist and its password hash must still match the
     * fingerprint stamped at sign-in. Deleting a user or resetting a password
     * (admin reset, e-mailed token, file reset) therefore ends every other
     * session of that account on its next request, instead of leaving the
     * old cookie usable until the browser is closed.
     */
    public static function isLoggedIn(): bool {
        self::initSession();
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        return self::validateSessionUser() !== null;
    }

    /**
     * Check if the current session is an admin. The role is read from the
     * users table on every request (not from the cookie), so a demotion or
     * deletion takes effect immediately.
     */
    public static function isAdmin(): bool {
        if (!self::isLoggedIn()) {
            return false;
        }
        return (self::validateSessionUser()['role'] ?? null) === self::ROLE_ADMIN;
    }

    /** Role of the signed-in user, or null when nobody is signed in. */
    public static function currentRole(): ?string {
        if (!self::isLoggedIn()) {
            return null;
        }
        return self::validateSessionUser()['role'] ?? null;
    }

    /** Store admin: changes the settings of its granted stores, nothing global. */
    public static function isStoreAdmin(): bool {
        return self::currentRole() === self::ROLE_STORE_ADMIN;
    }

    /** Admin or store admin — the roles that may change store settings at all. */
    public static function isStoreManager(): bool {
        $role = self::currentRole();
        return $role === self::ROLE_ADMIN || $role === self::ROLE_STORE_ADMIN;
    }

    // =========================================================================
    // Store grants
    //
    // Admins implicitly hold every store. Store admins and users hold the
    // stores listed for them in user_store_access. "View" is what every
    // granted role may do on a store (dashboard, invoices, create invoices);
    // "manage" additionally requires the store_admin role (or admin).
    // =========================================================================

    /** Store ids granted to a user row (any role). Admins: every store. */
    public static function storeIdsForUser(array $user): array {
        if (($user['role'] ?? null) === self::ROLE_ADMIN) {
            return array_column(Database::fetchAll("SELECT id FROM stores ORDER BY created_at DESC"), 'id');
        }
        return self::getUserStoreIds((string)($user['id'] ?? ''));
    }

    /** Store ids the signed-in user may see; [] when nobody is signed in. */
    public static function accessibleStoreIds(): array {
        $user = self::currentUser();
        return $user ? self::storeIdsForUser($user) : [];
    }

    /** Admin, or any granted role holding the store. */
    public static function canViewStore(string $storeId): bool {
        if ($storeId === '') {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        $user = self::currentUser();
        if (!$user) {
            return false;
        }
        return Database::fetchOne(
            "SELECT 1 FROM user_store_access WHERE user_id = ? AND store_id = ?",
            [$user['id'], $storeId]
        ) !== null;
    }

    /** Admin, or a store admin holding the store. */
    public static function canManageStore(string $storeId): bool {
        if (self::isAdmin()) {
            return true;
        }
        return self::isStoreAdmin() && self::canViewStore($storeId);
    }

    private static function denyStore(string $message): void {
        cashupay_status(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    /** 403 + exit unless the session may view $storeId. */
    public static function requireStoreView(string $storeId): void {
        if (!self::canViewStore($storeId)) {
            self::denyStore('You do not have access to this store');
        }
    }

    /** 403 + exit unless the session may change $storeId's settings. */
    public static function requireStoreManage(string $storeId): void {
        if (!self::canManageStore($storeId)) {
            self::denyStore(self::isStoreManager()
                ? 'You do not have access to this store'
                : 'Store admin role required');
        }
    }

    /** 403 + exit unless the session is an admin or a store admin. */
    public static function requireStoreManager(): void {
        if (!self::isStoreManager()) {
            self::denyStore('Store admin role required');
        }
    }

    /**
     * Resolve a store filter from the UI ('' or '__all__' = every store the
     * caller may see, otherwise one store id) to the list of store ids a
     * query may cover. null means "no restriction" (an admin asking for all).
     * A single store the caller does not hold ends the request with 403.
     */
    public static function scopeStoreFilter(?string $requested): ?array {
        $requested = trim((string)$requested);
        if ($requested === '' || $requested === '__all__') {
            return self::isAdmin() ? null : self::accessibleStoreIds();
        }
        self::requireStoreView($requested);
        return [$requested];
    }

    /** SQL fragment restricting `$column` to $storeIds (null = no restriction). */
    public static function storeScopeSql(?array $storeIds, string $column = 'store_id'): array {
        if ($storeIds === null) {
            return ['sql' => '', 'params' => []];
        }
        if ($storeIds === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }
        return [
            'sql' => $column . ' IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ')',
            'params' => array_values($storeIds),
        ];
    }

    /** Store ids granted to a user (user_store_access rows only). */
    public static function getUserStoreIds(string $userId): array {
        if ($userId === '') {
            return [];
        }
        return array_column(Database::fetchAll(
            "SELECT a.store_id FROM user_store_access a
               JOIN stores s ON s.id = a.store_id
              WHERE a.user_id = ? ORDER BY s.created_at DESC",
            [$userId]
        ), 'store_id');
    }

    /**
     * Replace a user's grants. Admin accounts never carry grants (their rows
     * are cleared). Unknown store ids are refused. Caller authorizes.
     */
    public static function setUserStoreIds(string $userId, array $storeIds): void {
        $user = self::getUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        $storeIds = array_values(array_unique(array_map('strval', $storeIds)));
        if ($user['role'] === self::ROLE_ADMIN) {
            $storeIds = [];
        }
        $known = [];
        foreach (Database::fetchAll("SELECT id FROM stores") as $row) {
            $known[$row['id']] = true;
        }
        foreach ($storeIds as $sid) {
            if (!isset($known[$sid])) {
                throw new \InvalidArgumentException('Unknown store');
            }
        }
        Database::delete('user_store_access', 'user_id = ?', [$userId]);
        $now = Database::timestamp();
        foreach ($storeIds as $sid) {
            Database::insert('user_store_access', [
                'user_id' => $userId, 'store_id' => $sid, 'created_at' => $now,
            ]);
        }
    }

    /**
     * Fingerprint of the credential a session was established with. Stored
     * in the session (never the hash itself) and compared on every request.
     */
    public static function credentialFingerprint(string $passwordHash): string {
        return hash('sha256', 'cashupay-session:' . $passwordHash);
    }

    /**
     * Start an authenticated session for $user (a full users row, including
     * password_hash). Rotates the session id and records the admin's own
     * base URL for the password-reset e-mail (Config::recordAdminBaseUrl).
     */
    public static function establishSession(array $user): void {
        self::initSession();
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['auth_fp']    = self::credentialFingerprint((string)($user['password_hash'] ?? ''));
        self::$sessionChecked = false;
        self::$sessionUser = null;
        self::$timedOut = false;
        unset($_SESSION['login_timed_out_at']);
        // regenerate emits the cookie with initSession's lifetime (= the login timeout).
        session_regenerate_id(true);
        $_SESSION['cookie_sent_at'] = time();
        if (($user['role'] ?? null) === self::ROLE_ADMIN) {
            Config::recordAdminBaseUrl();
        }
    }

    /**
     * Resolve the session's user row once per request, or null (and a
     * cleared identity) when the session no longer matches a live
     * credential. Sessions minted before the fingerprint existed carry no
     * auth_fp and are retired too: one re-login after the upgrade.
     *
     * Also enforces the idle login timeout: a session whose last request is
     * older than loginTimeout() is ended here (sessionTimedOut() reports it
     * for this request), otherwise the activity stamp and the cookie expiry
     * move forward.
     */
    private static function validateSessionUser(): ?array {
        if (self::$sessionChecked) {
            return self::$sessionUser;
        }
        self::$sessionChecked = true;
        self::$sessionUser = null;
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }
        $timeout = self::loginTimeout();
        // Sessions from before the timeout existed have login_time only;
        // treat sign-in as their last activity rather than retiring them. A
        // session with neither stamp can only have been seeded server-side
        // (nothing a client sends reaches $_SESSION): it starts its clock now.
        $lastActivity = (int)($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? 0);
        if ($lastActivity > 0 && time() - $lastActivity > $timeout) {
            self::clearSessionIdentity();
            self::$timedOut = true;
            $_SESSION['login_timed_out_at'] = time();
            return null;
        }
        $row = Database::fetchOne(
            "SELECT id, username, role, email, created_at, password_hash FROM users WHERE id = ?",
            [$userId]
        );
        $fp = $_SESSION['auth_fp'] ?? null;
        $ok = $row !== null
            && is_string($fp)
            && hash_equals(self::credentialFingerprint((string)$row['password_hash']), $fp);
        if (!$ok) {
            self::clearSessionIdentity();
            return null;
        }
        $_SESSION['user_role'] = $row['role'];
        $_SESSION['last_activity'] = time();
        self::refreshSessionCookie($timeout);
        unset($row['password_hash']);
        self::$sessionUser = $row;
        return $row;
    }

    /** Drop the signed-in identity from the session (the CSRF token survives). */
    private static function clearSessionIdentity(): void {
        unset(
            $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['login_time'],
            $_SESSION['auth_fp'], $_SESSION['last_activity'], $_SESSION['cookie_sent_at']
        );
    }

    /**
     * 403 + exit if the current session is not an admin. Use to gate
     * fund-moving actions and store/user/system configuration.
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            cashupay_status(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Admin role required']);
            exit;
        }
    }

    /**
     * Return the current logged-in user's row (id/username/role), or null
     * if no session. Does NOT return password_hash.
     */
    public static function currentUser(): ?array {
        if (!self::isLoggedIn()) {
            return null;
        }
        return self::validateSessionUser();
    }

    /**
     * Re-verify the acting admin's own password, for actions sensitive enough
     * that a hijacked session alone must not be sufficient (currently: reading
     * a store's wallet recovery phrase). Callers must still gate on
     * requireAdmin() first; this only adds the second factor.
     */
    public static function verifyCurrentUserPassword(string $password): bool {
        if ($password === '') {
            return false;
        }
        $self = self::currentUser();
        if (!$self) {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT password_hash FROM users WHERE id = ?",
            [$self['id']]
        );
        return $row !== null && password_verify($password, $row['password_hash']);
    }

    /**
     * A stable identifier for whoever is acting, for audit logging.
     */
    public static function currentActorLabel(): string {
        $self = self::currentUser();
        return $self['id'] ?? 'unknown';
    }

    /**
     * Password sign-in with the lockout reserved BEFORE the password check.
     *
     * Returns 'locked' (no password check happened — the per-address counter
     * or the global sign-in budget is exhausted), 'ok' (session established,
     * counters cleared) or 'invalid'. Callers that used to chain
     * isLockedOut()/login()/recordFailedLogin() should use this instead: that
     * sequence let N parallel guesses all see count < 5 and all get a bcrypt
     * check. Report the wait with Security::getLockoutRemaining($ip, true).
     */
    public static function attemptLogin(string $username, string $password, ?string $ip = null): string {
        $ip = $ip ?? Security::getClientIp();
        if (!Security::reserveLoginAttempt($ip, true)) {
            return 'locked';
        }
        if ($username !== '' && self::login($username, $password)) {
            Security::clearLoginAttempts($ip);
            return 'ok';
        }
        return 'invalid';
    }

    /**
     * Attempt login. Returns true on success.
     */
    public static function login(string $username, string $password): bool {
        $clientIp = Security::getClientIp();
        $user = self::getUserByUsername($username);

        if ($user && password_verify($password, $user['password_hash'])) {
            error_log("CashuPayServer: Login successful for '{$username}' from {$clientIp}");
            self::establishSession($user);
            return true;
        }

        error_log("CashuPayServer: Failed login attempt for '{$username}' from {$clientIp}");
        return false;
    }

    /**
     * Destroy the current session and rotate the session id so any leftover
     * cookie reference held by a tab is unusable.
     */
    public static function logout(): void {
        self::initSession();
        $_SESSION = [];
        // Rotate first (mints a new id that we immediately destroy), then
        // explicitly clear the client cookie so the browser doesn't keep
        // sending the dead SID.
        session_regenerate_id(true);
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]
            );
        }
        session_destroy();
    }

    // =========================================================================
    // User management
    // =========================================================================

    /**
     * Set the admin password during the first-run setup wizard.
     * Creates the seed 'admin' user if no admin exists, otherwise updates
     * the existing admin's password. The setup-reentry guard (separate
     * commit) is enforced at the setup.php caller, not here.
     *
     * $email is the optional recovery address collected in the same setup
     * step; it powers the email-link reset mechanism. Pass null to leave it
     * unset (the file-based reset still works without an address).
     */
    public static function setAdminPassword(string $password, ?string $email = null): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $existing = self::getUserByUsername('admin');
        if ($existing) {
            $fields = ['password_hash' => $hash];
            if ($email !== null) {
                $fields['email'] = $email !== '' ? $email : null;
            }
            Database::update('users', $fields, 'id = ?', [$existing['id']]);
        } else {
            Database::insert('users', [
                'id'            => Database::generateId('user'),
                'username'      => 'admin',
                'password_hash' => $hash,
                'email'         => ($email !== null && $email !== '') ? $email : null,
                'role'          => self::ROLE_ADMIN,
                'created_at'    => Database::timestamp(),
            ]);
        }

        // Drop the legacy slot once we own the credential in users.
        Database::delete('config', 'key = ?', ['admin_password_hash']);
    }

    /**
     * Set (or clear, with '') the admin account's e-mail address — the
     * destination of the e-mailed password-reset link. No-op when the admin
     * account does not exist yet.
     */
    public static function setAdminEmail(string $email): void {
        $existing = self::getUserByUsername('admin');
        if (!$existing) {
            return;
        }
        Database::update('users', ['email' => $email !== '' ? $email : null], 'id = ?', [$existing['id']]);
    }

    public static function getUserById(string $userId): ?array {
        $row = Database::fetchOne(
            "SELECT id, username, role, email, created_at FROM users WHERE id = ?",
            [$userId]
        );
        return $row ?: null;
    }

    public static function getUserByUsername(string $username): ?array {
        return Database::fetchOne(
            "SELECT * FROM users WHERE username = ? COLLATE NOCASE",
            [$username]
        );
    }

    /**
     * List all users (no hashes) with their store grants. Ordered admin,
     * store admin, user, then alphabetical.
     */
    public static function listUsers(): array {
        $users = Database::fetchAll(
            "SELECT id, username, role, email, created_at
             FROM users
             ORDER BY CASE role WHEN 'admin' THEN 0 WHEN 'store_admin' THEN 1 ELSE 2 END,
                      LOWER(username) ASC"
        );
        $grants = [];
        foreach (Database::fetchAll("SELECT user_id, store_id FROM user_store_access") as $row) {
            $grants[$row['user_id']][] = $row['store_id'];
        }
        foreach ($users as &$u) {
            $u['storeIds'] = $u['role'] === self::ROLE_ADMIN ? [] : ($grants[$u['id']] ?? []);
        }
        unset($u);
        return $users;
    }

    public static function isValidRole(mixed $role): bool {
        return is_string($role) && in_array($role, self::ROLES, true);
    }

    /**
     * Change a user's role. Demoting the only admin is refused (the install
     * would have nobody left who can reach the site-wide settings). Promotion
     * to admin drops the grants (admins hold every store implicitly). Caller
     * authorizes and, when demoting, follows up with setUserStoreIds().
     */
    public static function setUserRole(string $userId, string $role): void {
        if (!self::isValidRole($role)) {
            throw new \InvalidArgumentException('Invalid role');
        }
        $user = self::getUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        if ($user['role'] === $role) {
            return;
        }
        if ($user['role'] === self::ROLE_ADMIN) {
            $adminCount = (int)Database::fetchOne(
                "SELECT COUNT(*) AS c FROM users WHERE role = 'admin'"
            )['c'];
            if ($adminCount <= 1) {
                throw new \RuntimeException('Cannot demote the only remaining admin');
            }
        }
        Database::update('users', ['role' => $role], 'id = ?', [$userId]);
        if ($role === self::ROLE_ADMIN) {
            Database::delete('user_store_access', 'user_id = ?', [$userId]);
        }
    }

    /**
     * Validate a username. Returns null if OK, or an error message string.
     */
    public static function validateUsername(string $username): ?string {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username)) {
            return 'Username must be 3-32 chars, letters/digits/underscore/dash only';
        }
        return null;
    }

    /**
     * Validate a password. Returns null if OK, or an error message string.
     */
    public static function validatePassword(string $password): ?string {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters';
        }
        return null;
    }

    /**
     * Create a new user. Returns the new user id.
     * Throws on duplicate username, invalid input, or invalid role.
     *
     * $storeIds are the grants for a store admin / user (admins take none);
     * a non-admin needs at least one — an account that can see nothing is a
     * mistake at creation time, even though one may end up with none later
     * when its last store is deleted. $email is the optional recovery
     * address for the e-mailed password reset.
     */
    public static function createUser(
        string $username,
        string $password,
        string $role,
        array $storeIds = [],
        ?string $email = null
    ): string {
        if (!self::isValidRole($role)) {
            throw new \InvalidArgumentException('Invalid role');
        }
        if ($err = self::validateUsername($username))  throw new \InvalidArgumentException($err);
        if ($err = self::validatePassword($password))  throw new \InvalidArgumentException($err);
        $email = $email !== null ? trim($email) : null;
        if ($email !== null && $email !== '' && ($err = self::validateEmail($email))) {
            throw new \InvalidArgumentException($err);
        }
        $storeIds = array_values(array_unique(array_map('strval', $storeIds)));
        if ($role !== self::ROLE_ADMIN && $storeIds === []) {
            throw new \InvalidArgumentException('Select at least one store for this account');
        }

        if (self::getUserByUsername($username) !== null) {
            throw new \RuntimeException('A user with that name already exists');
        }

        $userId = Database::generateId('user');
        Database::insert('users', [
            'id'            => $userId,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'email'         => ($email !== null && $email !== '') ? $email : null,
            'created_at'    => Database::timestamp(),
        ]);
        if ($role !== self::ROLE_ADMIN) {
            try {
                self::setUserStoreIds($userId, $storeIds);
            } catch (\Throwable $e) {
                Database::delete('users', 'id = ?', [$userId]);
                throw $e;
            }
        }
        return $userId;
    }

    /**
     * Delete a user. Refuses to delete the only remaining admin so the
     * install can't be locked out.
     */
    public static function deleteUser(string $userId): void {
        $user = self::getUserById($userId);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        if ($user['role'] === self::ROLE_ADMIN) {
            $adminCount = (int)Database::fetchOne(
                "SELECT COUNT(*) AS c FROM users WHERE role = 'admin'"
            )['c'];
            if ($adminCount <= 1) {
                throw new \RuntimeException('Cannot delete the only remaining admin');
            }
        }
        Database::delete('users', 'id = ?', [$userId]);
    }

    /**
     * Set a user's password (admin path: reset another user). The caller
     * is responsible for authorization.
     */
    public static function changePassword(string $userId, string $newPassword): void {
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        if (!self::getUserById($userId)) {
            throw new \RuntimeException('User not found');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::update(
            'users',
            ['password_hash' => $hash],
            'id = ?',
            [$userId]
        );
        // Every other session of this account dies with the old hash (see
        // validateSessionUser); the session that made the change keeps going.
        if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['user_id'] ?? null) === $userId) {
            $_SESSION['auth_fp'] = self::credentialFingerprint($hash);
        }
    }

    // =========================================================================
    // Password recovery
    //
    // Two operator escape hatches for a lost admin password:
    //   1. Email reset link  — createPasswordResetToken + resetPasswordWithToken
    //   2. File-based reset   — fileResetRequested + completeFileReset
    // Both are surfaced from the lock screen "Forgot password?" modal and
    // documented in the README.
    // =========================================================================

    /** Lifetime of an emailed reset link, in seconds (1 hour). */
    public const RESET_TOKEN_TTL = 3600;

    /**
     * Validate an email address. Returns null if OK, or an error message.
     */
    public static function validateEmail(string $email): ?string {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address';
        }
        return null;
    }

    /**
     * Set (or clear, with null/'') a user's recovery email. Validates a
     * non-empty address. Caller is responsible for authorization.
     */
    public static function setUserEmail(string $userId, ?string $email): void {
        if (!self::getUserById($userId)) {
            throw new \RuntimeException('User not found');
        }
        $value = null;
        if ($email !== null && trim($email) !== '') {
            $email = trim($email);
            if ($err = self::validateEmail($email)) {
                throw new \InvalidArgumentException($err);
            }
            $value = $email;
        }
        Database::update('users', ['email' => $value], 'id = ?', [$userId]);
    }

    /**
     * The seed 'admin' account's recovery email, or null if unset.
     */
    public static function getAdminEmail(): ?string {
        $row = Database::fetchOne(
            "SELECT email FROM users WHERE username = 'admin' COLLATE NOCASE"
        );
        $email = $row['email'] ?? null;
        return ($email !== null && $email !== '') ? $email : null;
    }

    /**
     * Find the user whose recovery email matches (case-insensitive), any
     * role — every account may reset its own password by e-mail. Admins win
     * when two accounts share an address. Returns the full row or null.
     */
    public static function findUserByEmail(string $email): ?array {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        return Database::fetchOne(
            "SELECT * FROM users
              WHERE email IS NOT NULL AND email != ''
                AND email = ? COLLATE NOCASE
              ORDER BY CASE role WHEN 'admin' THEN 0 WHEN 'store_admin' THEN 1 ELSE 2 END,
                       created_at ASC
              LIMIT 1",
            [$email]
        ) ?: null;
    }

    /** @deprecated Kept for callers written when only admins could reset by e-mail. */
    public static function findAdminByEmail(string $email): ?array {
        return self::findUserByEmail($email);
    }

    /**
     * Mint a single-use, time-boxed password-reset token for a user. The raw
     * token is returned to the caller (to embed in the emailed link); only its
     * SHA-256 hash is persisted, so a leaked DB can't be used to reset. Any of
     * the user's prior unused tokens are invalidated so only the newest link
     * works.
     */
    public static function createPasswordResetToken(string $userId): string {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $now  = Database::timestamp();

        // Burn older outstanding tokens for this user (newest link wins).
        Database::update(
            'password_reset_tokens',
            ['used_at' => $now],
            'user_id = ? AND used_at IS NULL',
            [$userId]
        );

        Database::insert('password_reset_tokens', [
            'user_id'    => $userId,
            'token_hash' => $hash,
            'created_at' => $now,
            'expires_at' => $now + self::RESET_TOKEN_TTL,
        ]);

        return $raw;
    }

    /**
     * Look up a reset token without consuming it. Returns the user row the
     * token belongs to if the token is valid (exists, unused, unexpired),
     * else null. Used to decide whether to render the set-password form.
     */
    public static function peekPasswordResetToken(string $rawToken): ?array {
        if ($rawToken === '') {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ?",
            [hash('sha256', $rawToken)]
        );
        if (!$row || $row['used_at'] !== null) {
            return null;
        }
        if ((int)$row['expires_at'] < Database::timestamp()) {
            return null;
        }
        return self::getUserById($row['user_id']);
    }

    /**
     * Consume a reset token and set the new password atomically-ish: the token
     * is marked used first (so a concurrent retry can't reuse it), then the
     * password is updated. Returns true on success, false if the token is
     * invalid/expired/used. Throws InvalidArgumentException on a weak password
     * (caller should surface the message and the token stays usable — we only
     * mark it used once the password validates).
     */
    public static function resetPasswordWithToken(string $rawToken, string $newPassword): bool {
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        if ($rawToken === '') {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ?",
            [hash('sha256', $rawToken)]
        );
        if (!$row || $row['used_at'] !== null) {
            return false;
        }
        if ((int)$row['expires_at'] < Database::timestamp()) {
            return false;
        }

        // Mark used before changing the password so a racing second submit of
        // the same link can't also go through.
        $affected = Database::update(
            'password_reset_tokens',
            ['used_at' => Database::timestamp()],
            'id = ? AND used_at IS NULL',
            [$row['id']]
        );
        if ($affected < 1) {
            return false; // lost the race
        }

        if (!self::getUserById($row['user_id'])) {
            return false;
        }
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$row['user_id']]
        );
        return true;
    }

    /**
     * Absolute path to the file-based reset trigger. Lives in the data
     * directory (gitignored, not web-served), so writing it already proves
     * filesystem access — the authorization for this escape hatch.
     */
    public static function fileResetPath(): string {
        return Database::getDataDir() . '/reset-admin-password';
    }

    /**
     * Whether the operator has dropped the file-based reset trigger.
     */
    public static function fileResetRequested(): bool {
        return is_file(self::fileResetPath());
    }

    /**
     * Complete a file-based reset: set a new password on the seed 'admin'
     * account and delete the trigger file. The old password stays valid until
     * this succeeds, and the trigger file must still be present (operator
     * controls the window). Returns true on success; false if the trigger is
     * gone or there is no 'admin' account. Throws on a weak password.
     */
    public static function completeFileReset(string $newPassword): bool {
        if (!self::fileResetRequested()) {
            return false;
        }
        if ($err = self::validatePassword($newPassword)) {
            throw new \InvalidArgumentException($err);
        }
        $admin = self::getUserByUsername('admin');
        if (!$admin) {
            return false;
        }
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$admin['id']]
        );
        @unlink(self::fileResetPath());
        return true;
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string {
        self::initSession();
        if (!isset($_SESSION[self::CSRF_TOKEN_NAME])) {
            $_SESSION[self::CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_TOKEN_NAME];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool {
        self::initSession();
        return isset($_SESSION[self::CSRF_TOKEN_NAME]) &&
               hash_equals($_SESSION[self::CSRF_TOKEN_NAME], $token);
    }

    /**
     * Require admin login (redirect if not logged in)
     */
    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: ' . Urls::admin() . '?action=login');
            exit;
        }
    }

    // =========================================================================
    // API Authentication
    // =========================================================================

    /**
     * The raw Authorization header, wherever the SAPI put it.
     *
     * Apache never exports Authorization into the CGI-style environment for
     * non-Basic schemes: under mod_php $_SERVER['HTTP_AUTHORIZATION'] is
     * simply unset (verified against stock php:8.3-apache), and FastCGI
     * strips it per RFC 3875 unless CGIPassAuth is on. Without this fallback
     * every `Authorization: token …` API call 401s on Apache. Same technique
     * as the WordPress api-bridge (wordpress/api-bridge.php).
     */
    public static function authorizationHeader(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                        $header = $value;
                        break;
                    }
                }
            }
        }
        return is_string($header) ? $header : '';
    }

    /**
     * Validate API request and return store ID
     */
    public static function validateApiRequest(): ?array {
        $authHeader = self::authorizationHeader();

        // BTCPay format: "token API_KEY"
        if (preg_match('/^token\s+(.+)$/i', $authHeader, $matches)) {
            $apiKey = $matches[1];
            return self::validateApiKey($apiKey);
        }

        // Also support Bearer format
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $apiKey = $matches[1];
            return self::validateApiKey($apiKey);
        }

        return null;
    }

    /**
     * Validate API key and return associated data
     */
    public static function validateApiKey(string $apiKey): ?array {
        $keyHash = hash('sha256', $apiKey);

        $row = Database::fetchOne(
            "SELECT ak.*, s.name as store_name
             FROM api_keys ak
             JOIN stores s ON s.id = ak.store_id
             WHERE ak.key_hash = ?",
            [$keyHash]
        );

        if ($row === null) {
            return null;
        }

        return [
            'key_id' => $row['id'],
            'store_id' => $row['store_id'],
            'store_name' => $row['store_name'],
            'permissions' => json_decode($row['permissions'], true) ?? [],
        ];
    }

    // Greenfield permission strings the API understands. '*' is the
    // server-wide wildcard: it crosses store boundaries, so it is never
    // granted to a new key (admin-created keys are store-scoped, pairing
    // refuses it); keys that already hold it keep working.
    public const PERM_VIEW_INVOICES   = 'btcpay.store.canviewinvoices';
    public const PERM_CREATE_INVOICE  = 'btcpay.store.cancreateinvoice';
    public const PERM_MODIFY_INVOICES = 'btcpay.store.canmodifyinvoices';
    public const PERM_VIEW_STORE      = 'btcpay.store.canviewstoresettings';
    public const PERM_MODIFY_STORE    = 'btcpay.store.canmodifystoresettings';
    public const PERM_MODIFY_WEBHOOKS = 'btcpay.store.webhooks.canmodifywebhooks';

    public const KNOWN_PERMISSIONS = [
        self::PERM_VIEW_INVOICES, self::PERM_CREATE_INVOICE, self::PERM_MODIFY_INVOICES,
        self::PERM_VIEW_STORE, self::PERM_MODIFY_STORE, self::PERM_MODIFY_WEBHOOKS,
    ];

    /**
     * What a key created from the admin UI gets: everything a store
     * integration (the WooCommerce gateway) needs on ITS store, explicitly.
     * No wildcard — the key cannot list, create or delete other stores.
     */
    public const DEFAULT_STORE_KEY_PERMISSIONS = [
        self::PERM_VIEW_INVOICES, self::PERM_CREATE_INVOICE, self::PERM_MODIFY_INVOICES,
        self::PERM_VIEW_STORE, self::PERM_MODIFY_WEBHOOKS,
    ];

    /**
     * BTCPay's permission hierarchy: a broader permission implies the
     * narrower ones, so a key paired with canmodifyinvoices alone can still
     * read invoices (BTCPay clients rely on that).
     */
    private const PERMISSION_IMPLIES = [
        self::PERM_MODIFY_STORE => [
            self::PERM_VIEW_STORE, self::PERM_MODIFY_INVOICES, self::PERM_CREATE_INVOICE,
            self::PERM_VIEW_INVOICES, self::PERM_MODIFY_WEBHOOKS,
        ],
        self::PERM_MODIFY_INVOICES => [self::PERM_CREATE_INVOICE, self::PERM_VIEW_INVOICES],
        self::PERM_CREATE_INVOICE  => [self::PERM_VIEW_INVOICES],
    ];

    /**
     * Check if API key has specific permission (directly, by implication,
     * or through the legacy wildcard).
     */
    public static function hasPermission(array $authData, string $permission): bool {
        $granted = $authData['permissions'] ?? [];
        if (!is_array($granted)) {
            return false;
        }
        // Check for wildcard permission
        if (in_array('*', $granted, true)) {
            return true;
        }
        if (in_array($permission, $granted, true)) {
            return true;
        }
        foreach ($granted as $g) {
            if (is_string($g) && in_array($permission, self::PERMISSION_IMPLIES[$g] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    /** Is $permission a string the API knows how to enforce? ('*' is not grantable.) */
    public static function isKnownPermission(mixed $permission): bool {
        return is_string($permission) && in_array($permission, self::KNOWN_PERMISSIONS, true);
    }

    /**
     * Create a new API key. Store-scoped with the explicit default set
     * unless the caller (pairing) supplies the permissions it was asked for.
     */
    public static function createApiKey(
        string $storeId,
        string $label = '',
        array $permissions = self::DEFAULT_STORE_KEY_PERMISSIONS,
        ?string $applicationIdentifier = null,
        ?string $redirectHost = null
    ): array {
        $rawKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $keyId = Database::generateId('key');

        Database::insert('api_keys', [
            'id' => $keyId,
            'key_hash' => $keyHash,
            'store_id' => $storeId,
            'label' => $label,
            'permissions' => json_encode($permissions),
            'application_identifier' => $applicationIdentifier,
            'redirect_host' => $redirectHost,
            'created_at' => Database::timestamp(),
        ]);

        return [
            'id' => $keyId,
            'key' => $rawKey, // Only returned once!
            'store_id' => $storeId,
            'label' => $label,
            'permissions' => $permissions,
        ];
    }

    /**
     * Find existing API key by application identifier (for pairing flow reuse)
     */
    public static function findApiKeyByAppIdentifier(
        string $storeId,
        string $applicationIdentifier,
        string $redirectHost,
        array $requiredPermissions
    ): ?array {
        $key = Database::fetchOne(
            "SELECT * FROM api_keys
             WHERE store_id = ? AND application_identifier = ? AND redirect_host = ?",
            [$storeId, $applicationIdentifier, $redirectHost]
        );

        if (!$key) {
            return null;
        }

        // Check if existing key has all required permissions
        $existingPerms = json_decode($key['permissions'], true) ?? [];
        if (in_array('*', $existingPerms)) {
            // Wildcard permission covers everything
            return $key;
        }

        foreach ($requiredPermissions as $perm) {
            if (!in_array($perm, $existingPerms)) {
                return null; // Missing required permission
            }
        }

        return $key;
    }

    /**
     * Get API keys for a store
     */
    public static function getApiKeys(string $storeId): array {
        return Database::fetchAll(
            "SELECT id, store_id, label, permissions, created_at FROM api_keys WHERE store_id = ?",
            [$storeId]
        );
    }

    /**
     * Delete API key
     */
    public static function deleteApiKey(string $keyId): bool {
        // Check if this is an internal dashboard key
        $key = Database::fetchOne(
            "SELECT label FROM api_keys WHERE id = ?",
            [$keyId]
        );

        if ($key && $key['label'] === 'Internal (Dashboard)') {
            throw new \Exception('Cannot delete the internal dashboard API key');
        }

        return Database::delete('api_keys', 'id = ?', [$keyId]) > 0;
    }

    /**
     * Get or create internal API key for a store
     *
     * Internal API keys are used for admin dashboard features that need
     * to use the Greenfield API (like the Request button). They are stored
     * in the stores table and have a corresponding hash in api_keys.
     *
     * The key is create-only (PERM_CREATE_INVOICE): the dashboard payload
     * hands it to the limited user role as well, so it must not open
     * invoice listing or webhook management. Integrations get their own
     * key (api-keys/authorize.php pairing, provision.php).
     */
    public static function getOrCreateInternalApiKey(string $storeId): ?string {
        // Check if store exists and has internal key
        $store = Database::fetchOne(
            "SELECT internal_api_key FROM stores WHERE id = ?",
            [$storeId]
        );

        if ($store === null) {
            return null; // Store doesn't exist
        }

        // If internal key exists and is valid, return it
        if (!empty($store['internal_api_key'])) {
            // Verify the corresponding api_keys entry exists
            $keyHash = hash('sha256', $store['internal_api_key']);
            $exists = Database::fetchOne(
                "SELECT id FROM api_keys WHERE key_hash = ?",
                [$keyHash]
            );
            if ($exists) {
                return $store['internal_api_key'];
            }
            // Key is orphaned, will recreate below
        }

        // Generate new internal API key
        $rawKey = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $keyId = Database::generateId('key');

        // Store the key hash in api_keys table
        Database::insert('api_keys', [
            'id' => $keyId,
            'key_hash' => $keyHash,
            'store_id' => $storeId,
            'label' => 'Internal (Dashboard)',
            'permissions' => json_encode(['btcpay.store.cancreateinvoice']),
            'application_identifier' => null,
            'redirect_host' => null,
            'created_at' => Database::timestamp(),
        ]);

        // Store the raw key in stores table
        Database::update(
            'stores',
            ['internal_api_key' => $rawKey],
            'id = ?',
            [$storeId]
        );

        return $rawKey;
    }

    /**
     * Require API authentication (send error response if not authenticated)
     */
    public static function requireApiAuth(): array {
        $auth = self::validateApiRequest();
        if ($auth === null) {
            cashupay_status(401);
            header('Content-Type: application/json');
            echo json_encode([
                'code' => 'unauthenticated',
                'message' => 'Authentication required. Use Authorization: token YOUR_API_KEY'
            ]);
            exit;
        }
        return $auth;
    }
}
