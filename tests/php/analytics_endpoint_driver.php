<?php
/**
 * Spawns the REAL scripts/analytics-endpoint/*.php under `php -S` on a free
 * loopback port, from a scratch directory (so its SQLite file never lands
 * in the repo), behind tests/fixtures/analytics_endpoint_router.php for
 * failure injection. Not a test itself (run.php only globs test_*.php).
 */
declare(strict_types=1);

final class AnalyticsEndpointDriver {
    public int $port;
    public string $base;   // http://127.0.0.1:<port>
    public string $url;    // .../report.php
    public string $dir;
    /** @var resource|null */
    private $proc = null;

    public const FILES = ['common.php', 'report.php', 'summary.php', 'import-geo.php'];

    /**
     * @param int|null $port reuse a specific port (a client whose endpoint
     *                       constant is already fixed restarts "the same"
     *                       endpoint); waits for it to be free. null = any.
     */
    public static function start(?int $port = null): self {
        $d = new self();
        $repo = dirname(__DIR__, 2);
        $d->dir = sys_get_temp_dir() . '/cashupay_analytics_' . bin2hex(random_bytes(6));
        mkdir($d->dir, 0750, true);
        foreach (self::FILES as $f) {
            copy($repo . '/scripts/analytics-endpoint/' . $f, $d->dir . '/' . $f);
        }
        copy($repo . '/tests/fixtures/analytics_endpoint_router.php', $d->dir . '/router.php');
        if ($port !== null) {
            $deadline = microtime(true) + 20;
            while (true) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (!$probe) {
                    break;
                }
                fclose($probe);
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException("port {$port} never freed");
                }
                usleep(100000);
            }
        }
        $d->port = $port ?? self::freePort();
        $d->base = 'http://127.0.0.1:' . $d->port;
        $d->url = $d->base . '/report.php';
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $d->port
            . ' -t ' . escapeshellarg($d->dir) . ' ' . escapeshellarg($d->dir . '/router.php');
        $log = $d->dir . '/server.log';
        $d->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, $d->dir, ['PATH' => (string)getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4']);
        if (!is_resource($d->proc)) {
            throw new \RuntimeException('could not start php -S for the analytics endpoint');
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $s = @fsockopen('127.0.0.1', $d->port, $errno, $errstr, 0.2);
            if ($s) {
                fclose($s);
                return $d;
            }
            usleep(100000);
        }
        $d->stop();
        throw new \RuntimeException('analytics endpoint php -S did not come up');
    }

    public function setMode(string $mode): void {
        file_put_contents($this->dir . '/mode', $mode);
    }

    /** Edit a constant in the deployed common.php copy (e.g. the access token). */
    public function setConstant(string $name, string $phpLiteral): void {
        $p = $this->dir . '/common.php';
        $src = (string)file_get_contents($p);
        $new = preg_replace('/^const ' . preg_quote($name, '/') . '\s*=\s*.*?;/m', "const {$name} = {$phpLiteral};", $src, 1, $n);
        if ($n !== 1) {
            throw new \RuntimeException("constant {$name} not found in common.php");
        }
        file_put_contents($p, $new);
    }

    public function dbPath(): string {
        return $this->dir . '/usage-reports.sqlite';
    }

    public function db(): PDO {
        $pdo = new PDO('sqlite:' . $this->dbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * @param array $opts path (default /report.php), query, json (array, encoded),
     *                    raw (string body), headers (string[]), timeout
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function request(string $method, array $opts = []): array {
        $path = $opts['path'] ?? '/report.php';
        $ch = curl_init($this->base . $path . ($opts['query'] ?? ''));
        $hdrs = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 10),
            CURLOPT_HEADERFUNCTION => function ($_c, string $h) use (&$hdrs) {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $hdrs[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($h);
            },
        ]);
        $headers = $opts['headers'] ?? [];
        if (array_key_exists('json', $opts)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            $headers[] = 'Content-Type: application/json';
        } elseif (array_key_exists('raw', $opts)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opts['raw']);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'headers' => $hdrs];
    }

    /** A valid level-N report, as the payserver would build it. */
    public static function sampleReport(int $level = 1, array $overrides = [], ?string $installId = null): array {
        $props = [
            'version' => '1.7', 'build_sha' => 'abcdef012345', 'channel' => 'main', 'php_version' => '8.3',
            'os_family' => 'Linux', 'install_kind' => 'standalone', 'web_server' => 'apache',
        ];
        if ($level >= 2) {
            $props += [
                'store_count' => 2, 'rails_enabled' => ['mint', 'lnaddress'],
                'stores_with_mint' => 2, 'stores_with_lnaddress' => 1, 'stores_with_nwc' => 0,
                'stores_with_noffer' => 0, 'stores_with_strike' => 0, 'stores_with_onchain' => 0,
                'stores_with_swaps' => 0, 'auto_update_enabled' => true, 'products_count' => 3,
                'api_keys_count' => 1, 'wordpress_connected' => false,
            ];
        }
        if ($level >= 3) {
            $props += [
                'invoices_30d_created' => 10, 'invoices_30d_settled' => 7, 'invoices_30d_expired' => 2,
                'invoices_30d_settled_sats' => 70000,
            ];
            foreach (['mint', 'lnaddress', 'nwc', 'noffer', 'strike', 'swap', 'onchain'] as $rail) {
                $props["settled_30d_{$rail}_count"] = $rail === 'mint' ? 7 : 0;
                $props["settled_30d_{$rail}_sats"] = $rail === 'mint' ? 70000 : 0;
            }
        }
        $report = [
            'schema_version' => 1,
            'event'          => 'usage_report',
            'client'         => 'barebits-payserver',
            'install_id'     => $installId ?? self::uuid(),
            'sent_at'        => gmdate('Y-m-d\TH:i:s\Z'),
            'level'          => $level,
            'properties'     => $props,
        ];
        foreach ($overrides as $k => $v) {
            if (str_starts_with($k, 'properties.')) {
                $pk = substr($k, strlen('properties.'));
                if ($v === '__unset__') {
                    unset($report['properties'][$pk]);
                } else {
                    $report['properties'][$pk] = $v;
                }
            } elseif ($v === '__unset__') {
                unset($report[$k]);
            } else {
                $report[$k] = $v;
            }
        }
        return $report;
    }

    public static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-'
            . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private static function freePort(): int {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) {
            throw new \RuntimeException("freePort: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int)substr((string)$name, (int)strrpos((string)$name, ':') + 1);
    }
}
