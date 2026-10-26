<?php
/**
 * Spawns the REAL scripts/xpub-endpoint/xpub.php under `php -S` on a free
 * loopback port, from a scratch directory (so its SQLite file never lands in
 * the repo), behind tests/fixtures/xpub_endpoint_router.php for failure
 * injection. Not a test itself (run.php only globs test_*.php).
 */
declare(strict_types=1);

final class XpubEndpointDriver {
    public int $port;
    public string $base;   // http://127.0.0.1:<port>
    public string $url;    // .../xpub.php
    public string $dir;
    /** @var resource|null */
    private $proc = null;

    public static function start(): self {
        $d = new self();
        $repo = dirname(__DIR__, 2);
        $d->dir = sys_get_temp_dir() . '/cashupay_xpub_' . bin2hex(random_bytes(6));
        mkdir($d->dir, 0750, true);
        copy($repo . '/scripts/xpub-endpoint/xpub.php', $d->dir . '/xpub.php');
        copy($repo . '/tests/fixtures/xpub_endpoint_router.php', $d->dir . '/router.php');
        $d->port = self::freePort();
        $d->base = 'http://127.0.0.1:' . $d->port;
        $d->url = $d->base . '/xpub.php';
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $d->port
            . ' -t ' . escapeshellarg($d->dir) . ' ' . escapeshellarg($d->dir . '/router.php');
        $log = $d->dir . '/server.log';
        $d->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            // Several workers: the 'hang' failure mode parks one worker past the
            // client's timeout, and later requests must not queue behind it.
            $pipes, $d->dir, ['PATH' => (string)getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '4']);
        if (!is_resource($d->proc)) {
            throw new \RuntimeException('could not start php -S for xpub.php');
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
        throw new \RuntimeException('xpub.php php -S did not come up');
    }

    public function setMode(string $mode): void {
        file_put_contents($this->dir . '/mode', $mode);
    }

    public function dbPath(): string {
        return $this->dir . '/xpub-branches.sqlite';
    }

    public function db(): PDO {
        $pdo = new PDO('sqlite:' . $this->dbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function request(string $method, array $opts = []): array {
        $ch = curl_init($this->url . ($opts['query'] ?? ''));
        $hdrs = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HEADERFUNCTION => function ($_c, string $h) use (&$hdrs) {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $hdrs[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($h);
            },
        ]);
        if (isset($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
        } elseif (isset($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'headers' => $hdrs];
    }

    /** One allocation as the payserver would do it. */
    public function allocate(): array {
        return $this->request('POST', ['form' => ['client' => 'barebits-payserver']]);
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
