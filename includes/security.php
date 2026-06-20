<?php
/**
 * CashuPayServer - Security Module
 *
 * Rate limiting, CSRF protection, and security utilities.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class Security {
    private const RATE_LIMIT_WINDOW = 60; // seconds
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 300; // 5 minutes

    /**
     * Check rate limit for an action
     */
    public static function checkRateLimit(string $action, string $identifier, int $maxAttempts = 60): bool {
        $key = "rate_{$action}_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            self::setCache($key, ['count' => 1, 'window_start' => time()], self::RATE_LIMIT_WINDOW);
            return true;
        }

        // Check if window has expired
        if (time() - $data['window_start'] > self::RATE_LIMIT_WINDOW) {
            self::setCache($key, ['count' => 1, 'window_start' => time()], self::RATE_LIMIT_WINDOW);
            return true;
        }

        // Increment count
        $data['count']++;
        self::setCache($key, $data, self::RATE_LIMIT_WINDOW);

        // M4: Log rate limit exceeded
        if ($data['count'] > $maxAttempts) {
            error_log("CashuPayServer: Rate limit exceeded for {$action} from {$identifier}");
        }

        return $data['count'] <= $maxAttempts;
    }

    /**
     * Record failed login attempt
     */
    public static function recordFailedLogin(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }

        $data['count']++;
        $data['last_attempt'] = time();

        self::setCache($key, $data, self::LOCKOUT_DURATION);
    }

    /**
     * Check if identifier is locked out
     */
    public static function isLockedOut(string $identifier): bool {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null) {
            return false;
        }

        if ($data['count'] >= self::MAX_LOGIN_ATTEMPTS) {
            $lockoutEnd = ($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
            return time() < $lockoutEnd;
        }

        return false;
    }

    /**
     * Clear login attempts on successful login
     */
    public static function clearLoginAttempts(string $identifier): void {
        $key = "login_attempts_{$identifier}";
        self::deleteCache($key);
    }

    /**
     * Get remaining lockout time
     */
    public static function getLockoutRemaining(string $identifier): int {
        $key = "login_attempts_{$identifier}";
        $data = self::getCache($key);

        if ($data === null || $data['count'] < self::MAX_LOGIN_ATTEMPTS) {
            return 0;
        }

        $lockoutEnd = ($data['last_attempt'] ?? time()) + self::LOCKOUT_DURATION;
        return max(0, $lockoutEnd - time());
    }

    /**
     * Sanitize string for output
     */
    public static function escape(string $string): string {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Validate and sanitize URL
     */
    public static function sanitizeUrl(string $url): ?string {
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Only allow http and https
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower($scheme), ['http', 'https'])) {
            return null;
        }

        return $url;
    }

    /**
     * Generate secure random token
     */
    public static function generateToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }

    /**
     * Constant-time string comparison
     */
    public static function secureCompare(string $a, string $b): bool {
        return hash_equals($a, $b);
    }

    /**
     * Get the client IP address.
     *
     * Forwarding headers (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) are
     * CLIENT-CONTROLLED unless the request actually arrived from a reverse
     * proxy we trust. Honoring them unconditionally lets anyone spoof their IP
     * by sending a header — defeating the per-IP login lockout and every rate
     * limit (just rotate the header per request). So we only consult them when
     * REMOTE_ADDR is a configured trusted proxy; otherwise REMOTE_ADDR wins.
     *
     * Configure trusted proxies via Config 'trusted_proxies' or the
     * CASHUPAY_TRUSTED_PROXIES env var — a comma-separated list of IPs and/or
     * CIDR ranges (e.g. "127.0.0.1, ::1, 10.0.0.0/8"). Default: empty, i.e. no
     * proxy is trusted and REMOTE_ADDR is always used. The literal "*" trusts
     * all proxies and should only be set when a fully trusted edge (e.g. a
     * cloud load balancer) is the sole ingress.
     */
    public static function getClientIp(): string {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $remoteValid = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        if (!self::isTrustedProxy($remoteValid)) {
            return $remoteValid;
        }

        // Cloudflare sets CF-Connecting-IP to a single real client IP.
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        // X-Forwarded-For is "client, proxy1, proxy2, ...". Walk RIGHT to LEFT,
        // skipping hops that are themselves trusted proxies; the first untrusted
        // hop is the real client. Never take [0] — the left-most entry is fully
        // attacker-controlled (the client writes it).
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $hops = array_map('trim', explode(',', $xff));
            $leftmostValid = null;
            foreach ($hops as $hop) {
                if ($leftmostValid === null && filter_var($hop, FILTER_VALIDATE_IP)) {
                    $leftmostValid = $hop;
                }
            }
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $hop = $hops[$i];
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if (self::isTrustedProxy($hop)) {
                    continue;
                }
                return $hop;
            }
            // Every hop was itself a trusted proxy (e.g. the "*" trust-all
            // wildcard, or a chain entirely within trusted ranges): the original
            // client is then the left-most entry.
            if ($leftmostValid !== null) {
                return $leftmostValid;
            }
        }

        $xr = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($xr !== '' && filter_var($xr, FILTER_VALIDATE_IP)) {
            return $xr;
        }

        return $remoteValid;
    }

    /**
     * Comma-separated trusted-proxy list from Config (preferred) or the
     * CASHUPAY_TRUSTED_PROXIES env var. Returns [] (trust nothing) by default.
     */
    private static function trustedProxyList(): array {
        $raw = Config::get('trusted_proxies', null);
        if ($raw === null || $raw === '') {
            $env = getenv('CASHUPAY_TRUSTED_PROXIES');
            $raw = ($env === false) ? '' : $env;
        }
        $raw = (string)$raw;
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn($s) => $s !== ''
        ));
    }

    /**
     * True if $ip falls within any configured trusted-proxy entry (bare IP,
     * CIDR, or the "*" trust-all sentinel).
     */
    public static function isTrustedProxy(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::trustedProxyList() as $entry) {
            if (self::ipInCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match an IP against a bare IP, a CIDR range, or "*". Handles both IPv4
     * and IPv6; a family mismatch is a non-match.
     */
    private static function ipInCidr(string $ip, string $entry): bool {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }
        if ($entry === '*') {
            return true; // trust-all: use only behind a fully trusted edge
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        if (strpos($entry, '/') === false) {
            $entryBin = @inet_pton($entry);
            return $entryBin !== false && $ipBin === $entryBin;
        }

        [$subnet, $bitsStr] = explode('/', $entry, 2);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false; // unparsable subnet or IPv4-vs-IPv6 mismatch
        }
        $bits = (int)$bitsStr;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;
        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Set security headers
     */
    public static function setSecurityHeaders(): void {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS protection
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (adjust as needed)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'");
    }

    // Simple file-based cache for rate limiting
    private static function getCacheDir(): string {
        $dir = __DIR__ . '/../data/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function getCacheFile(string $key): string {
        return self::getCacheDir() . '/' . md5($key) . '.cache';
    }

    private static function getCache(string $key): ?array {
        $file = self::getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        $decoded = json_decode($data, true);

        if ($decoded === null || !isset($decoded['expires']) || $decoded['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $decoded['data'];
    }

    private static function setCache(string $key, array $data, int $ttl): void {
        $file = self::getCacheFile($key);
        $content = json_encode([
            'data' => $data,
            'expires' => time() + $ttl,
        ]);
        file_put_contents($file, $content, LOCK_EX);
    }

    private static function deleteCache(string $key): void {
        $file = self::getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Clean expired cache files
     */
    public static function cleanCache(): void {
        $dir = self::getCacheDir();
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.cache') as $file) {
            $data = file_get_contents($file);
            $decoded = json_decode($data, true);

            if ($decoded === null || !isset($decoded['expires']) || $decoded['expires'] < time()) {
                @unlink($file);
            }
        }
    }
}
