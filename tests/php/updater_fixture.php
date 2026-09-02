<?php
/**
 * Shared fixture for Updater integration tests. Spins up PHP's built-in
 * server pointed at a tempdir that mimics the *versioned* GitHub release API
 * the updater fetches against (the old moving channel-<x> tags are gone):
 *
 *   GET /releases/latest
 *       → the newest non-draft, non-prerelease release JSON (what the 'main'
 *         channel tracks). 404 if there is no stable release.
 *   GET /releases
 *       → JSON array of all non-draft releases, newest-first (what the
 *         'testing' channel scans; it takes the first entry).
 *   GET /assets/<tag>/BUILD_INFO          → that release's BUILD_INFO file
 *   GET /assets/<tag>/barebits-<tag>.zip → that release's app zip
 *
 * Each release JSON carries the two assets the updater looks for: a
 * stable-named BUILD_INFO and a version-stamped barebits-<tag>.zip.
 *
 * Returns an array with:
 *   - 'baseUrl'     — pass to Updater::$releaseApiUrlBase. It is the releases
 *                     collection URL WITHOUT a trailing slash; the updater
 *                     appends '/latest' (main) or '?per_page=' (testing).
 *   - 'installRoot' — pre-populated fake install root with old BUILD_INFO
 *                     (COMMIT_SHA = 0000..., so the updater sees a mismatch and
 *                     runs the full apply path)
 *   - 'workdir'     — root of fixture tempdir
 *   - 'port', 'proc'
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
    // The build script wraps content under barebits/.
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
        $entry = 'barebits/' . $rel;
        if ($item->isDir()) {
            $zip->addEmptyDir($entry);
        } else {
            $zip->addFile($item->getPathname(), $entry);
        }
    }
    $zip->close();
}

/**
 * Build + serve one-or-more versioned releases. Caller owns shutdown (handled
 * automatically at process exit).
 *
 * $releases: a list in NEWEST-FIRST order. Each entry:
 *   [
 *     'tag'         => 'v0.3.0-rc1',           // required; drives the asset name
 *     'prerelease'  => true,                    // default false
 *     'draft'       => false,                   // default false (drafts hidden)
 *     'build_info'  => ['COMMIT_SHA'=>..., 'VERSION'=>..., ...], // BUILD_INFO
 *     'extra'       => ['admin.php' => 'NEW', ...], // extra files inside the zip
 *   ]
 */
function updater_fixture_start_releases(array $releases): array {
    $work = sys_get_temp_dir() . '/cashupay_fixture_' . bin2hex(random_bytes(6));
    mkdir($work, 0755, true);
    $serveDir = $work . '/serve';
    mkdir($serveDir, 0755, true);

    $port = updater_fixture_pick_free_port();

    $releaseObjs = [];
    foreach ($releases as $i => $r) {
        $tag = (string)($r['tag'] ?? '');
        if ($tag === '') {
            fail("updater_fixture: release #$i missing 'tag'");
        }
        $buildInfo = $r['build_info'] ?? [];
        $extra = $r['extra'] ?? [];

        // Stage the release content (this is what goes INSIDE the zip, under
        // barebits/).
        $stage = $work . '/stage-' . $i;
        mkdir($stage . '/data', 0755, true);
        $lines = [];
        foreach ($buildInfo as $k => $v) {
            $lines[] = "$k=$v";
        }
        $buildInfoText = implode("\n", $lines) . "\n";
        file_put_contents($stage . '/BUILD_INFO', $buildInfoText);
        foreach ($extra as $rel => $content) {
            $full = $stage . '/' . $rel;
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $content);
        }

        // Serve this release's assets under /assets/<tag>/.
        $assetDir = $serveDir . '/assets/' . $tag;
        mkdir($assetDir, 0755, true);
        $zipName = 'barebits-' . $tag . '.zip';
        updater_fixture_make_zip($assetDir . '/' . $zipName, $stage);
        copy($stage . '/BUILD_INFO', $assetDir . '/BUILD_INFO');

        $base = "http://127.0.0.1:$port/assets/" . rawurlencode($tag);
        $releaseObjs[] = [
            'tag_name' => $tag,
            'prerelease' => (bool)($r['prerelease'] ?? false),
            'draft' => (bool)($r['draft'] ?? false),
            'assets' => [
                ['name' => 'BUILD_INFO', 'browser_download_url' => "$base/BUILD_INFO"],
                ['name' => $zipName, 'browser_download_url' => "$base/$zipName"],
            ],
        ];
    }

    // /releases → non-draft releases, newest-first (== input order).
    $list = array_values(array_filter($releaseObjs, static fn($r) => !$r['draft']));
    file_put_contents($serveDir . '/releases_list.json', json_encode($list));
    // /releases/latest → newest non-draft, non-prerelease (GitHub semantics).
    $latest = null;
    foreach ($list as $r) {
        if (!$r['prerelease']) {
            $latest = $r;
            break;
        }
    }
    if ($latest !== null) {
        file_put_contents($serveDir . '/releases_latest.json', json_encode($latest));
    }

    // Router mimicking the two GitHub endpoints; everything else falls through
    // to static serving (the BUILD_INFO + zip assets).
    $router = <<<'PHP'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/releases/latest') {
    header('Content-Type: application/json');
    $f = __DIR__ . '/releases_latest.json';
    if (!is_file($f)) { http_response_code(404); echo '{"message":"Not Found"}'; return true; }
    readfile($f);
    return true;
}
if ($uri === '/releases') {
    header('Content-Type: application/json');
    readfile(__DIR__ . '/releases_list.json');
    return true;
}
return false; // static file (assets/*)
PHP;
    file_put_contents($serveDir . '/router.php', $router);

    // Spawn the PHP built-in server with the router.
    $cmd = sprintf(
        '%s -S 127.0.0.1:%d -t %s %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($serveDir),
        escapeshellarg($serveDir . '/router.php')
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
        'baseUrl' => "http://127.0.0.1:$port/releases",
        'installRoot' => $installRoot,
        'workdir' => $work,
        'port' => $port,
        'proc' => $proc,
    ];
}

/**
 * Backward-compatible single-release fixture. Publishes ONE stable release so
 * it is visible to both the main (/releases/latest) and testing channels.
 *
 * $newBuildInfo: BUILD_INFO keys for the release (e.g. ['COMMIT_SHA'=>...,
 *                'VERSION'=>'0.2']). The tag is derived from VERSION.
 * $extraFiles:   relative path => contents to place inside the zip's
 *                barebits/ tree (e.g. ['admin.php' => 'NEW']).
 */
function updater_fixture_start(string $channel, array $newBuildInfo, array $extraFiles = []): array {
    $version = (string)($newBuildInfo['VERSION'] ?? '0.0.0-fixture');
    return updater_fixture_start_releases([
        [
            'tag' => 'v' . $version,
            'prerelease' => false,
            'draft' => false,
            'build_info' => $newBuildInfo,
            'extra' => $extraFiles,
        ],
    ]);
}
