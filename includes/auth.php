<?php
/**
 * CashuPayServer Authentication Module
 *
 * Admin session management and API key validation.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/urls.php';

class Auth {
    private const SESSION_NAME = 'cashupay_session';
    private const CSRF_TOKEN_NAME = 'csrf_token';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    /**
     * Initialize session. Applies hardened cookie params (HttpOnly + SameSite
     * Lax + Secure on HTTPS) before session_start() — they only take effect
     * when set before the cookie is emitted. Idempotent: subsequent calls in
     * the same request are no-ops.
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? null) == 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name(self::SESSION_NAME);
            session_start();
        }
    }

    /**
     * Check if a user is logged in (any role).
     */
    public static function isLoggedIn(): bool {
        if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
            return function_exists('current_user_can')
                && current_user_can('manage_options');
        }
        self::initSession();
        return !empty($_SESSION['user_id']);
    }

    /**
     * Check if the current session is an admin.
     * In WordPress mode every authenticated WP admin is treated as admin.
     */
    public static function isAdmin(): bool {
        if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
            return function_exists('current_user_can')
                && current_user_can('manage_options');
        }
        self::initSession();
        return ($_SESSION['user_role'] ?? null) === self::ROLE_ADMIN;
    }

    /**
     * 403 + exit if the current session is not an admin. Use to gate
     * fund-moving actions and store/user/system configuration.
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Admin role required']);
            exit;
        }
    }

    /**
     * Return the current logged-in user's row (id/username/role/has_pin),
     * or null if no session / WordPress mode. Does NOT return password_hash
     * or pin_hash.
     */
    public static function currentUser(): ?array {
        if (defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS) {
            return null;
        }
        self::initSession();
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }
        return self::getUserById($userId);
    }

    /**
     * Attempt login. Returns true on success.
     */
    public static function login(string $username, string $password): bool {
        $clientIp = Security::getClientIp();
        $user = self::getUserByUsername($username);

        if ($user && password_verify($password, $user['password_hash'])) {
            error_log("CashuPayServer: Login successful for '{$username}' from {$clientIp}");

            self::initSession();
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['login_time'] = time();
            session_regenerate_id(true);
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
     */
    public static function setAdminPassword(string $password): void {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $existing = self::getUserByUsername('admin');
        if ($existing) {
            Database::update('users', ['password_hash' => $hash], 'id = ?', [$existing['id']]);
        } else {
            Database::insert('users', [
                'id'            => Database::generateId('user'),
                'username'      => 'admin',
                'password_hash' => $hash,
                'role'          => self::ROLE_ADMIN,
                'created_at'    => Database::timestamp(),
            ]);
        }

        // Drop the legacy slot once we own the credential in users.
        Database::delete('config', 'key = ?', ['admin_password_hash']);
    }

    public static function getUserById(string $userId): ?array {
        $row = Database::fetchOne(
            "SELECT id, username, role, created_at,
                    CASE WHEN pin_hash IS NULL THEN 0 ELSE 1 END AS has_pin
             FROM users WHERE id = ?",
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
     * List all users (no hashes). Ordered admin-first then alphabetical.
     */
    public static function listUsers(): array {
        return Database::fetchAll(
            "SELECT id, username, role, created_at,
                    CASE WHEN pin_hash IS NULL THEN 0 ELSE 1 END AS has_pin
             FROM users
             ORDER BY (role = 'admin') DESC, LOWER(username) ASC"
        );
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
     * Validate a PIN. Returns null if OK, or an error message string.
     */
    public static function validatePin(string $pin): ?string {
        if (!preg_match('/^\d{4}$/', $pin)) {
            return 'PIN must be exactly 4 digits';
        }
        return null;
    }

    /**
     * Create a new user. Returns the new user id.
     * Throws on duplicate username, invalid input, or invalid role.
     */
    public static function createUser(string $username, string $password, string $role): string {
        if ($role !== self::ROLE_ADMIN && $role !== self::ROLE_USER) {
            throw new \InvalidArgumentException('Invalid role');
        }
        if ($err = self::validateUsername($username))  throw new \InvalidArgumentException($err);
        if ($err = self::validatePassword($password))  throw new \InvalidArgumentException($err);

        if (self::getUserByUsername($username) !== null) {
            throw new \RuntimeException('A user with that name already exists');
        }

        $userId = Database::generateId('user');
        Database::insert('users', [
            'id'            => $userId,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'created_at'    => Database::timestamp(),
        ]);
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
        Database::update(
            'users',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT)],
            'id = ?',
            [$userId]
        );
    }

    /**
     * Set or clear a user's PIN. Pass null to remove the PIN.
     */
    public static function setPin(string $userId, ?string $pin): void {
        if (!self::getUserById($userId)) {
            throw new \RuntimeException('User not found');
        }
        if ($pin === null) {
            Database::update('users', ['pin_hash' => null], 'id = ?', [$userId]);
            return;
        }
        if ($err = self::validatePin($pin)) {
            throw new \InvalidArgumentException($err);
        }
        Database::update(
            'users',
            ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT)],
            'id = ?',
            [$userId]
        );
    }

    /**
     * Verify a PIN against the current logged-in user. Returns false if
     * no session, no PIN configured, or wrong PIN.
     */
    public static function verifyPin(string $pin): bool {
        $user = self::currentUser();
        if (!$user) {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT pin_hash FROM users WHERE id = ?",
            [$user['id']]
        );
        if (!$row || !$row['pin_hash']) {
            return false;
        }
        return password_verify($pin, $row['pin_hash']);
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
     * Validate API request and return store ID
     */
    public static function validateApiRequest(): ?array {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

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

    /**
     * Check if API key has specific permission
     */
    public static function hasPermission(array $authData, string $permission): bool {
        // Check for wildcard permission
        if (in_array('*', $authData['permissions'])) {
            return true;
        }

        // Check for specific permission
        return in_array($permission, $authData['permissions']);
    }

    /**
     * Create a new API key
     */
    public static function createApiKey(
        string $storeId,
        string $label = '',
        array $permissions = ['*'],
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
            http_response_code(401);
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
