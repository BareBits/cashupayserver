<?php
/**
 * Shared fixture for Updater integration tests. Spins up PHP's built-in
 * server pointed at a tempdir that mimics the GitHub release layout the
 * updater fetches against:
 *
 *   GET /release/channel-<channel>
 *       → JSON {"assets": [
 *             {"name": "BUILD_INFO",         "browser_download_url": ".../BUILD_INFO"},
 *             {"name": "cashupayserver.zip", "browser_download_url": ".../cashupayserver.zip"}
 *         ]}
 *   GET /BUILD_INFO
 *       → the build-info file (server side, matches the zip)
 *   GET /cashupayserver.zip
 *       → the zip that overlay will install
 *
 * Returns an array with:
 *   - 'baseUrl'   — pass to Updater::$releaseApiUrlBase (with trailing slash)
 *   - 'installRoot' — pre-populated fake install root with old BUILD_INFO
 *   - 'pid'       — server pid for explicit kill (also auto-killed at shutdown)
 *   - 'workdir'   — root of fixture tempdir
 *
 * The "old" install is set up with COMMIT_SHA = 0000... so the updater sees
 * a mismatch and runs the full apply path.
 */

declare(strict_types=1);

function updater_fixture_pick_free_port(): int {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fail("pick_free_port: $errstr");
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    [$_, $port] = explode(':', $name);
    return (int)$port;
}

function updater_fixture_make_zip(string $zipPath, string $stagedRoot): void {
    // The build script wraps content under cashupayserver/.
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("could not create zip $zipPath");
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $prefixLen = strlen($stagedRoot) + 1;
    foreach ($iter as $item) {
        $rel = substr($item->getPathname(), $prefixLen);
        $entry = 'cashupayserver/' . $rel;
        if ($item->isDir()) {
            $zip->addEmptyDir($entry);
        } else {
            $zip->addFile($item->getPathname(), $entry);
        }
    }
    $zip->close();
}

/**
 * Build a full fixture: staged "release" content + zip + release-tag JSON,
 * then start a PHP built-in server serving it. Caller owns shutdown.
 *
 * $newBuildInfo: array of keys for the BUILD_INFO file shipped in the zip
 *                (e.g. ['COMMIT_SHA' => 'newsha', 'VERSION' => '0.2'])
 * $extraFiles:   array of relative path => contents that should land inside
 *                the zip's cashupayserver/ tree. e.g. ['admin.php' => 'NEW']
 */
function updater_fixture_start(string $channel, array $newBuildInfo, array $extraFiles = []): array {
    $work = sys_get_temp_dir() . '/cashupay_fixture_' . bin2hex(random_bytes(6));
    mkdir($work, 0755, true);

    // Build the staged release content (this is what goes INSIDE the zip,
    // under cashupayserver/).
    $stage = $work . '/stage';
    mkdir($stage . '/data', 0755, true);
    $buildInfoLines = [];
    foreach ($newBuildInfo as $k => $v) {
        $buildInfoLines[] = "$k=$v";
    }
    $buildInfoText = implode("\n", $buildInfoLines) . "\n";
    file_put_contents($stage . '/BUILD_INFO', $buildInfoText);
    // A minimal .htaccess matching the HTACCESS_SHA256 the test will assert
    // against. Caller may have set HTACCESS_SHA256 — if so the .htaccess
    // file content must hash to that. The simplest contract: caller passes
    // .htaccess content via extraFiles and provides the matching SHA in
    // newBuildInfo if it cares.
    foreach ($extraFiles as $rel => $content) {
        $full = $stage . '/' . $rel;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $content);
    }

    // Build the zip.
    $serveDir = $work . '/serve';
    mkdir($serveDir . '/release', 0755, true);
    $zipPath = $serveDir . '/cashupayserver.zip';
    updater_fixture_make_zip($zipPath, $stage);
    // Copy BUILD_INFO to serve dir (the BUILD_INFO asset is served separately
    // from the zip, just like the real GH release).
    copy($stage . '/BUILD_INFO', $serveDir . '/BUILD_INFO');

    // Pick a port. There's a small race window between picking and PHP-
    // built-in-server binding, but in CI/test machines this is fine.
    $port = updater_fixture_pick_free_port();

    // Write the release-tag JSON pointing at our own server.
    $releaseJson = json_encode([
        'tag_name' => 'channel-' . $channel,
        'assets' => [
            [
                'name' => 'BUILD_INFO',
                'browser_download_url' => "http://127.0.0.1:$port/BUILD_INFO",
            ],
            [
                'name' => 'cashupayserver.zip',
                'browser_download_url' => "http://127.0.0.1:$port/cashupayserver.zip",
            ],
        ],
    ]);
    file_put_contents($serveDir . '/release/channel-' . $channel, $releaseJson);

    // Spawn the PHP built-in server. No router — just static file serving
    // from $serveDir.
    $phpBin = PHP_BINARY;
    $cmd = sprintf(
        '%s -S 127.0.0.1:%d -t %s',
        escapeshellarg($phpBin),
        $port,
        escapeshellarg($serveDir)
    );
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $work . '/server.log', 'a'],
        2 => ['file', $work . '/server.log', 'a'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fail('proc_open(php -S) failed');
    }
    $status = proc_get_status($proc);
    $pid = $status['pid'];

    // Wait for the server to start accepting connections (max ~3s).
    $deadline = microtime(true) + 3.0;
    $ready = false;
    while (microtime(true) < $deadline) {
        $fp = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(50_000);
    }
    if (!$ready) {
        proc_terminate($proc);
        fail("fixture server failed to start on port $port (log: $work/server.log)");
    }

    // Build the "old" install root.
    $installRoot = $work . '/install';
    mkdir($installRoot . '/data', 0755, true);
    mkdir($installRoot . '/includes', 0755, true);
    file_put_contents($installRoot . '/BUILD_INFO',
        "COMMIT_SHA=0000000000000000000000000000000000000000\n"
        . "VERSION=0.0-old\n"
    );
    file_put_contents($installRoot . '/admin.php', 'OLD_ADMIN');
    // User data that must survive
    file_put_contents($installRoot . '/data/MARKER', 'preserve_me');
    file_put_contents($installRoot . '/user_config.php', 'USER_CONFIG');

    // Ensure cleanup even on test failure.
    register_shutdown_function(static function () use ($proc, $work) {
        if (is_resource($proc)) {
            // PHP's -S server doesn't quit on SIGTERM cleanly always; kill
            // process group to be sure. proc_terminate sends SIGTERM.
            @proc_terminate($proc, SIGKILL);
            @proc_close($proc);
        }
        $rec = @new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        if ($rec) {
            foreach ($rec as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($work);
    });

    return [
        'baseUrl' => "http://127.0.0.1:$port/release/",
        'installRoot' => $installRoot,
        'workdir' => $work,
        'port' => $port,
        'proc' => $proc,
    ];
}
