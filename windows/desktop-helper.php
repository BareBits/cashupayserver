<?php
/**
 * CashuPayServer Windows desktop — background helper.
 *
 * Launched minimized by CashuPayServer.bat alongside the web server. Does
 * three things, then gets out of the way:
 *
 *   1. Waits for the local web server to accept connections.
 *   2. Opens the merchant's default browser at the POS URL.
 *   3. Ticks cron-runner.php on an interval so background work (invoice
 *      polling, webhook delivery, auto-melt) runs reliably — the built-in
 *      PHP web server is single-threaded on Windows, so this can't be left
 *      to in-request self-triggers.
 *
 * Exits on its own once the server has been unreachable for three
 * consecutive ticks (i.e. the merchant closed the server window).
 *
 * Usage: php desktop-helper.php <port>
 *
 * Env (test hooks / tuning):
 *   CASHUPAY_HELPER_TICK         — seconds between cron ticks (default 60)
 *   CASHUPAY_HELPER_BOOT_TIMEOUT — seconds to wait for server boot (default 60)
 *   CASHUPAY_BROWSER_CMD         — command to open the browser; "{url}" is
 *                                  replaced with the POS URL (default: the
 *                                  Windows `start` command)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$port = (int) ($argv[1] ?? 0);
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "usage: php desktop-helper.php <port>\n");
    exit(1);
}

$tick = max(1, (int) (getenv('CASHUPAY_HELPER_TICK') ?: 60));
$bootTimeout = max(1, (int) (getenv('CASHUPAY_HELPER_BOOT_TIMEOUT') ?: 60));

function server_up(int $port): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

// --- 1+2. Wait for boot, then open the browser -----------------------------
$deadline = microtime(true) + $bootTimeout;
$booted = false;
while (microtime(true) < $deadline) {
    if (server_up($port)) {
        $booted = true;
        break;
    }
    usleep(250_000);
}

if ($booted) {
    $url = 'http://127.0.0.1:' . $port . '/';
    $browserCmd = getenv('CASHUPAY_BROWSER_CMD');
    if ($browserCmd !== false && $browserCmd !== '') {
        system(str_replace('{url}', $url, $browserCmd));
    } elseif (PHP_OS_FAMILY === 'Windows') {
        // `start` is a cmd builtin; the empty "" is its window-title slot so
        // the quoted URL isn't mistaken for the title.
        pclose(popen('start "" "' . $url . '"', 'r'));
    }
}

// --- 3. Cron tick loop ------------------------------------------------------
$runnerCmd = [PHP_BINARY];
$ini = php_ini_loaded_file();
if ($ini !== false) {
    $runnerCmd[] = '-c';
    $runnerCmd[] = $ini;
}
$runnerCmd[] = __DIR__ . '/cron-runner.php';

$downStreak = 0;
while ($downStreak < 3) {
    sleep($tick);
    if (!server_up($port)) {
        $downStreak++;
        continue;
    }
    $downStreak = 0;

    // Array-form proc_open bypasses cmd.exe, dodging its quote-stripping
    // rules. Blocking is fine — an overlong tick just delays the next one,
    // and cron.php's own lock bounces overlapping passes anyway.
    $proc = proc_open($runnerCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc)) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
}
