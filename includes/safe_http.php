<?php
/**
 * CashuPayServer - Safe outbound HTTP helper
 *
 * Centralizes URL validation and curl hardening for every outbound HTTP
 * call in the server. Without this, every call site reinvents (or skips)
 * the same defenses, which is how SSRF gets in: an attacker-controlled URL
 * (webhook target, LNURL callback, mint info endpoint, redirect from a
 * trusted host) points at 169.254.169.254 / 127.0.0.1 / fc00::/7 and the
 * payserver dutifully proxies the response back, leaking cloud metadata
 * or pivoting to internal services.
 *
 * Default posture:
 *   - http/https only (no file://, gopher://, etc.)
 *   - reject hostnames that resolve to private/loopback/link-local IPs
 *   - pin curl to the resolved IP (CURLOPT_RESOLVE) so a follow-up DNS
 *     re-resolve can't swap to a private address mid-connection
 *   - follow redirects only when the caller asks for it
 *   - cap response size (default 5 MB) so a hostile peer can't OOM us
 *   - verify TLS peer
 *
 * Self-hosters who legitimately run a local mint or local Bitcoin node
 * opt in via:
 *
 *   Config::set('allow_private_endpoints', true);    -- or --
 *   CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1 environment variable
 *
 * The opt-in is consulted by the call sites that may legitimately point
 * at private IPs (mint client, onchain provider). Webhook delivery and
 * LNURL fetches NEVER opt in — those targets are attacker-controlled.
 */

require_once __DIR__ . '/config.php';

class SafeHttp {
    public const DEFAULT_TIMEOUT_SEC = 15;
    public const DEFAULT_CONNECT_TIMEOUT_SEC = 5;
    public const DEFAULT_MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    /**
     * Perform an outbound HTTP request with hardened defaults.
     *
     * Options:
     *   - method:           'GET' (default), 'POST', 'PUT', 'DELETE'
     *   - headers:          string[] (e.g. ['Accept: application/json'])
     *   - body:             string raw request body (POST/PUT)
     *   - timeout:          int seconds, default DEFAULT_TIMEOUT_SEC
     *   - connectTimeout:   int seconds, default DEFAULT_CONNECT_TIMEOUT_SEC
     *   - userAgent:        string, default 'CashuPayServer/1.0'
     *   - allowPrivate:     bool, default false. Override for trusted
     *                       admin-configured destinations (local mint /
     *                       local Bitcoin node / self-call).
     *   - followRedirects:  bool, default false. Off by default because
     *                       a redirect to a private IP is a classic SSRF
     *                       bypass; on-by-default leaks the request.
     *   - maxRedirects:     int, default 3 (only used when following)
     *   - maxBytes:         int max response bytes, default
     *                       DEFAULT_MAX_RESPONSE_BYTES
     *   - verifyTls:        bool, default true
     *
     * Returns:
     *   ['status' => int, 'body' => string, 'error' => string, 'effectiveUrl' => string]
     *
     * On URL-validation failure the request is not made and 'error' is set
     * to a descriptive message. The 'status' is 0 in that case.
     */
    public static function request(string $url, array $opts = []): array {
        $allowPrivate = (bool)($opts['allowPrivate'] ?? false);

        try {
            $validated = self::validateUrl($url, $allowPrivate);
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage(), 'effectiveUrl' => $url];
        }

        $ch = curl_init($validated['url']);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed', 'effectiveUrl' => $url];
        }

        $method = strtoupper((string)($opts['method'] ?? 'GET'));
        $timeout = (int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT_SEC);
        $connectTimeout = (int)($opts['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_SEC);
        $userAgent = (string)($opts['userAgent'] ?? 'CashuPayServer/1.0');
        $followRedirects = (bool)($opts['followRedirects'] ?? false);
        $maxRedirects = (int)($opts['maxRedirects'] ?? 3);
        $maxBytes = (int)($opts['maxBytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES);
        $verifyTls = (bool)($opts['verifyTls'] ?? true);
        $headers = $opts['headers'] ?? [];

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_NOSIGNAL => 1,
        ];

        // Pin curl to the validated IP so a re-resolve (or a redirect when
        // followRedirects is on) cannot swap to a private address between
        // our check and the connect.
        if (!empty($validated['resolve'])) {
            $curlOpts[CURLOPT_RESOLVE] = $validated['resolve'];
        }

        if (!empty($headers)) {
            $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        }

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                $curlOpts[CURLOPT_POST] = true;
                if (isset($opts['body'])) {
                    $curlOpts[CURLOPT_POSTFIELDS] = (string)$opts['body'];
                }
                break;
            default:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
                if (isset($opts['body'])) {
                    $curlOpts[CURLOPT_POSTFIELDS] = (string)$opts['body'];
                }
                break;
        }

        // Enforce response size cap. Bail mid-stream as soon as the
        // accumulated body exceeds $maxBytes so a 500 MB hostile response
        // can't pin a PHP-FPM worker's memory.
        $bodyBuf = '';
        $curlOpts[CURLOPT_WRITEFUNCTION] = function ($_ch, string $chunk) use (&$bodyBuf, $maxBytes) {
            $len = strlen($chunk);
            if (strlen($bodyBuf) + $len > $maxBytes) {
                return 0; // abort transfer
            }
            $bodyBuf .= $chunk;
            return $len;
        };

        curl_setopt_array($ch, $curlOpts);

        $execOk = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = (string)curl_error($ch);
        curl_close($ch);

        $error = '';
        if ($execOk === false && $err !== '') {
            // Distinguish "we aborted because of size cap" from a real
            // network error: when the write function returns 0, curl
            // reports CURLE_WRITE_ERROR.
            $error = $err;
        }

        return [
            'status' => $status,
            'body' => $bodyBuf,
            'error' => $error,
            'effectiveUrl' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        ];
    }

    /**
     * Validate a URL and resolve its host to an IP. Returns:
     *   ['url' => $url, 'resolve' => ['host:port:ip']]
     *
     * The 'resolve' entry is meant for CURLOPT_RESOLVE so curl will skip
     * its own DNS lookup and use our validated IP. Throws on rejection.
     */
    public static function validateUrl(string $url, bool $allowPrivate = false): array {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("Disallowed scheme '{$scheme}'");
        }

        $host = $parts['host'];
        // Strip IPv6 brackets if present
        $hostForResolve = $host;
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $hostForResolve = substr($host, 1, -1);
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Resolve host -> IPs. If host is already an IP literal, skip DNS.
        $ips = [];
        if (filter_var($hostForResolve, FILTER_VALIDATE_IP)) {
            $ips = [$hostForResolve];
        } else {
            $ips = self::resolveHost($hostForResolve);
            if (empty($ips)) {
                throw new \RuntimeException("DNS resolution failed for '{$hostForResolve}'");
            }
        }

        // If any resolved IP is private, reject (defense against multi-A
        // DNS rebinding tricks where the first IP is public and a later
        // one is private).
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (self::isPrivateIp($ip)) {
                    throw new \RuntimeException("Host '{$hostForResolve}' resolves to a private/reserved IP ({$ip})");
                }
            }
        }

        // Pin to the first resolved IP. curl_setopt(CURLOPT_RESOLVE)
        // format is "host:port:ip".
        $resolveLines = [$hostForResolve . ':' . $port . ':' . $ips[0]];

        return [
            'url' => $url,
            'resolve' => $resolveLines,
            'ips' => $ips,
            'host' => $hostForResolve,
            'port' => $port,
        ];
    }

    /**
     * Returns true if the IP address falls in a range we never want to
     * send unsolicited HTTP to: RFC1918, loopback, link-local, multicast,
     * reserved, and (per the operator's request) carrier-grade NAT.
     *
     * Implemented with PHP's built-in FILTER_VALIDATE_IP flags, which
     * cover the IPv4 and IPv6 cases declaratively.
     */
    public static function isPrivateIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // unparsable -> refuse
        }
        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7
        // FILTER_FLAG_NO_RES_RANGE blocks 0/8, 127/8, 169.254/16, 192.0.0/24,
        //   192.0.2/24, 192.88.99/24, 198.18/15, 198.51.100/24, 203.0.113/24,
        //   224/4, 240/4, ::1, fe80::/10, etc.
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Resolve A and AAAA records for a hostname.
     *
     * @return string[] list of IP literals
     */
    private static function resolveHost(string $host): array {
        $ips = [];

        // gethostbynamel returns IPv4 A records as an array
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }
            }
        }

        // dns_get_record for AAAA
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Returns true if the operator has opted in to allowing private/
     * loopback destinations (for self-hosted mints or local Bitcoin nodes).
     *
     * Config value wins; env var is a fallback for ephemeral test rigs
     * (tests/scripts/iterate.py, CI) that don't have a persisted config.
     */
    public static function privateEndpointsAllowed(): bool {
        $cfg = Config::get('allow_private_endpoints', null);
        if ($cfg !== null) {
            return (bool)$cfg;
        }
        $env = getenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');
        if ($env === false || $env === '') {
            return false;
        }
        return in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
    }
}
