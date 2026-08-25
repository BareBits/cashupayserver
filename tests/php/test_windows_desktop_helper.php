<?php
/**
 * windows/desktop-helper.php: the Windows desktop package's sidecar.
 *
 * Behaviors under test, driven through its documented env hooks:
 *   1. Waits for the server port to accept connections, then fires the
 *      browser command exactly once with {url} substituted.
 *   2. After the server goes away, shuts itself down within three ticks
 *      (so closing the server window doesn't strand a background process).
 *
 * The "server" here is just a listening socket — the helper only probes
 * connectability, it never speaks HTTP.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

$repoRoot = dirname(__DIR__, 2);
$helper = $repoRoot . '/windows/desktop-helper.php';

// A data dir with nothing in it keeps any cron tick that sneaks in a no-op.
$dataDir = sys_get_temp_dir() . '/cashupay_winhelper_' . bin2hex(random_bytes(6));
mkdir($dataDir, 0750, true);
$marker = $dataDir . '/browser-opened.txt';

// "Server": a listening socket that never accepts — connect() still succeeds.
// It must live in a SEPARATE process: if this test held the socket itself,
// the helper (a proc_open child) would inherit the listening fd, and closing
// the socket here would not actually release the port. (Production has no
// such aliasing — the launcher starts the helper before the server.)
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
assert_not_null($probe ?: null, "probe socket: $errstr");
$port = (int) explode(':', stream_socket_get_name($probe, false))[1];
fclose($probe); // free the port for the listener child (benign test-only race)

$listener = proc_open(
    [PHP_BINARY, '-r', '$srv = stream_socket_server("tcp://127.0.0.1:" . $argv[1]) or exit(1); while (true) sleep(60);', (string) $port],
    [2 => ['file', '/dev/null', 'w']],
    $listenerPipes
);
assert_true(is_resource($listener), 'listener spawned');
// Wait until the listener child actually holds the port.
$deadline = microtime(true) + 5.0;
$listening = false;
while (microtime(true) < $deadline) {
    if ($fp = @fsockopen('127.0.0.1', $port, $e2, $s2, 1)) {
        fclose($fp);
        $listening = true;
        break;
    }
    usleep(50_000);
}
assert_true($listening, 'listener child bound the port');

putenv('CASHUPAY_HELPER_TICK=1');
putenv('CASHUPAY_HELPER_BOOT_TIMEOUT=5');
putenv('CASHUPAY_BROWSER_CMD=echo {url} > ' . $marker);
putenv('CASHUPAY_DESKTOP_APP_DIR=' . $repoRoot);
putenv('CASHUPAY_DATA_DIR=' . $dataDir);

$proc = proc_open(
    [PHP_BINARY, $helper, (string) $port],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
assert_true(is_resource($proc), 'helper spawned');

// --- 1. Browser hook fires with the substituted URL -------------------------
$deadline = microtime(true) + 5.0;
while (!is_file($marker) && microtime(true) < $deadline) {
    usleep(100_000);
}
assert_true(is_file($marker), 'browser command ran after server came up');
assert_true(
    strpos((string) file_get_contents($marker), "http://127.0.0.1:$port/") !== false,
    '{url} substituted into the browser command'
);

// --- 2. Self-shutdown after the server disappears ---------------------------
proc_terminate($listener, 9);
proc_close($listener);

// 3 one-second ticks plus generous slack for slow CI.
$deadline = microtime(true) + 15.0;
$exited = false;
while (microtime(true) < $deadline) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $exited = true;
        break;
    }
    usleep(200_000);
}
if (!$exited) {
    proc_terminate($proc, 9);
}
proc_close($proc);
assert_true($exited, 'helper exited on its own after the server went away');

cleanup_db($dataDir);
echo "ok\n";
