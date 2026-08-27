<?php
/**
 * BareBits plugin — "install BareBits alongside WordPress".
 *
 * Downloads the latest stable BareBits release zip from GitHub, verifies it
 * against the release's SHA256SUMS when published, unpacks it into a
 * web-served directory next to the WordPress install, and writes the
 * deployment config (data directory outside the web root when possible, an
 * external-cron marker, and a one-time provisioning token hash) that lets the
 * onboarding flow collect credentials once the operator finishes the BareBits
 * setup wizard. Uses only WordPress filesystem/HTTP APIs. License: GPLv2 or
 * later.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Default directory name (under the web root) and URL path for the install. */
const CASHUPAY_INSTALL_DEFAULT_DIRNAME = 'barebits';

/**
 * GitHub releases API base for the BareBits repository. Overridable for
 * testing/mirrors via the CASHUPAY_RELEASE_API_BASE constant (wp-config.php)
 * or the cashupay_release_api_base filter.
 */
function cashupay_release_api_base(): string {
    $base = defined('CASHUPAY_RELEASE_API_BASE')
        ? (string) CASHUPAY_RELEASE_API_BASE
        : 'https://api.github.com/repos/BareBits/cashupayserver';
    return rtrim((string) apply_filters('cashupay_release_api_base', $base), '/');
}

/**
 * Where the alongside install goes. $dirname is the merchant's optional
 * override from the onboarding form; it is a single path segment, not a full
 * path, so the result is always web-served under the site. Preference order:
 * a sibling of wp-admin/ in the web root (served at /{dirname}/ exactly like
 * a hand-made standalone install), falling back to wp-content when the web
 * root is not writable.
 *
 * @return array{dir?:string, url?:string, error?:string}
 */
function cashupay_resolve_install_target(string $dirname = ''): array {
    $dirname = trim($dirname);
    if ($dirname === '') {
        $dirname = CASHUPAY_INSTALL_DEFAULT_DIRNAME;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $dirname)) {
        return ['error' => 'The folder name may only contain letters, numbers, hyphens and underscores.'];
    }
    if (wp_is_writable(ABSPATH)) {
        return [
            'dir' => rtrim(ABSPATH, '/\\') . '/' . $dirname,
            'url' => site_url('/' . $dirname),
        ];
    }
    if (wp_is_writable(WP_CONTENT_DIR)) {
        return [
            'dir' => rtrim(WP_CONTENT_DIR, '/\\') . '/' . $dirname,
            'url' => content_url('/' . $dirname),
        ];
    }
    return ['error' => 'Neither the web root nor wp-content is writable, so BareBits cannot be installed automatically on this host.'];
}

/**
 * Where the BareBits data directory goes: outside the web root when the
 * parent of the web root is writable (so the database can never be fetched
 * over HTTP), else inside the install's own data/ directory — which the
 * release ships pre-protected with a deny-all .htaccess, and the BareBits
 * setup wizard's security screen verifies protection on such layouts.
 */
function cashupay_resolve_data_dir(string $installDir): string {
    $outside = dirname(rtrim(ABSPATH, '/\\')) . '/barebits-data';
    if (is_dir($outside) && wp_is_writable($outside)) {
        return $outside;
    }
    if (!is_dir($outside) && wp_is_writable(dirname($outside)) && @mkdir($outside, 0750, true)) {
        return $outside;
    }
    return rtrim($installDir, '/') . '/data';
}

/**
 * Preflight checks shown on (and gating) the install screen. The BareBits
 * server will run under the same PHP as WordPress, so checking this process
 * checks the server too. Returns [label => ['ok' => bool, 'detail' => string]].
 */
function cashupay_install_preflight(): array {
    $target = cashupay_resolve_install_target((string) get_option('cashupay_install_dirname', ''));
    $checks = [
        'PHP ' . PHP_VERSION . ' (8.0+ required)' => [
            'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'detail' => '',
        ],
        'cURL extension' => ['ok' => extension_loaded('curl'), 'detail' => ''],
        'PDO SQLite extension' => ['ok' => extension_loaded('pdo_sqlite'), 'detail' => ''],
        'GMP or BCMath extension' => [
            'ok' => extension_loaded('gmp') || extension_loaded('bcmath'),
            'detail' => extension_loaded('gmp') ? '' : 'BCMath works; GMP is much faster and required for on-chain xpub wallets and swaps.',
        ],
        'Writable install location' => [
            'ok' => !isset($target['error']),
            'detail' => $target['error'] ?? ('Will install to ' . ($target['dir'] ?? '')),
        ],
        'Direct filesystem access' => [
            'ok' => cashupay_can_install_plugins(),
            'detail' => 'WordPress must be able to write files without FTP credentials.',
        ],
    ];
    return $checks;
}

/**
 * Resolve the latest stable release's app zip (and SHA256SUMS when the
 * release publishes one).
 *
 * @return array{ok:bool, message?:string, tag?:string, zip_url?:string, zip_name?:string, sums_url?:?string}
 */
function cashupay_fetch_latest_release(): array {
    $response = wp_remote_get(cashupay_release_api_base() . '/releases/latest', [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/vnd.github+json'],
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'message' => 'Could not reach GitHub: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $release = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($release) || empty($release['assets'])) {
        return ['ok' => false, 'message' => 'GitHub did not return a usable latest release (HTTP ' . $code . ').'];
    }

    $zipUrl = $zipName = null;
    $sumsUrl = null;
    foreach ((array) $release['assets'] as $asset) {
        $name = (string) ($asset['name'] ?? '');
        $url = (string) ($asset['browser_download_url'] ?? '');
        if ($name === 'SHA256SUMS') {
            $sumsUrl = $url;
            continue;
        }
        // The standalone app zip only — never the WordPress-plugin or
        // Windows-desktop artifacts that sit on the same release.
        if ($zipUrl === null
                && preg_match('/^cashupayserver(-.+)?\.zip$/', $name)
                && strpos($name, 'windows') === false
                && strpos($name, 'wordpress') === false) {
            $zipUrl = $url;
            $zipName = $name;
        }
    }
    if ($zipUrl === null) {
        return ['ok' => false, 'message' => 'The latest release has no standalone BareBits zip attached.'];
    }
    return [
        'ok' => true,
        'tag' => (string) ($release['tag_name'] ?? ''),
        'zip_url' => $zipUrl,
        'zip_name' => $zipName,
        'sums_url' => $sumsUrl,
    ];
}

/**
 * Download the release zip to a temp file and, when the release publishes
 * SHA256SUMS, verify the zip's hash against it. Returns the local path or an
 * error. The caller must delete the returned file.
 *
 * @return array{ok:bool, file?:string, message?:string, verified?:bool}
 */
function cashupay_download_release(array $release): array {
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $file = download_url($release['zip_url'], 300);
    if (is_wp_error($file)) {
        return ['ok' => false, 'message' => 'Download failed: ' . $file->get_error_message()];
    }

    $verified = false;
    if (!empty($release['sums_url'])) {
        $sums = wp_remote_get($release['sums_url'], ['timeout' => 30]);
        if (is_wp_error($sums) || (int) wp_remote_retrieve_response_code($sums) !== 200) {
            @unlink($file);
            return ['ok' => false, 'message' => 'The release publishes SHA256SUMS but it could not be downloaded; aborting rather than installing unverified code.'];
        }
        $expected = null;
        foreach (preg_split('/\r?\n/', (string) wp_remote_retrieve_body($sums)) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m)
                    && trim($m[2]) === $release['zip_name']) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            @unlink($file);
            return ['ok' => false, 'message' => 'SHA256SUMS has no entry for ' . $release['zip_name'] . '; aborting.'];
        }
        if (!hash_equals($expected, hash_file('sha256', $file))) {
            @unlink($file);
            return ['ok' => false, 'message' => 'Checksum mismatch on the downloaded release — refusing to install it.'];
        }
        $verified = true;
    }

    return ['ok' => true, 'file' => $file, 'verified' => $verified];
}

/**
 * Unpack the release zip (top-level directory `cashupayserver/`) into
 * $installDir. Unzips to a staging directory first so a half-extracted
 * archive can never masquerade as an install.
 *
 * @return array{ok:bool, message?:string}
 */
function cashupay_unpack_release(string $zipPath, string $installDir): array {
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!WP_Filesystem()) {
        return ['ok' => false, 'message' => 'Could not initialize the WordPress filesystem API.'];
    }

    $staging = dirname($installDir) . '/.barebits-staging-' . wp_generate_password(8, false, false);
    if (!wp_mkdir_p($staging)) {
        return ['ok' => false, 'message' => 'Could not create the staging directory.'];
    }

    $result = unzip_file($zipPath, $staging);
    if (is_wp_error($result)) {
        $wp_filesystem->delete($staging, true);
        return ['ok' => false, 'message' => 'Unzip failed: ' . $result->get_error_message()];
    }

    $unpacked = $staging . '/cashupayserver';
    if (!is_dir($unpacked) || !is_file($unpacked . '/BUILD_INFO')) {
        $wp_filesystem->delete($staging, true);
        return ['ok' => false, 'message' => 'The downloaded zip does not look like a BareBits release (no cashupayserver/BUILD_INFO inside).'];
    }

    if (!wp_mkdir_p(dirname($installDir)) || !@rename($unpacked, $installDir)) {
        // rename() can fail across filesystems; fall back to a copy.
        if (!wp_mkdir_p($installDir) || is_wp_error(copy_dir($unpacked, $installDir))) {
            $wp_filesystem->delete($staging, true);
            return ['ok' => false, 'message' => 'Could not move the unpacked release into place at ' . $installDir . '.'];
        }
    }
    $wp_filesystem->delete($staging, true);
    return ['ok' => true];
}

/**
 * Write the install's user_config.php. Everything BareBits needs to run as a
 * managed single-shop install is declared here as data — never code:
 *
 *   - the data directory and pinned base URL,
 *   - CASHUPAY_MANAGED_INSTALL (shapes the product for the single-shop
 *     case and implies the wizard's cron-screen skip — WP-cron pings
 *     cron.php),
 *   - the shop's front page + retry endpoint for payer-facing links,
 *   - a pre-seeded admin password (hash only; the wizard skips its
 *     password screen and this plugin can reveal the plaintext to the
 *     site admin when BareBits ever asks for it),
 *   - an SSO key (hash only) so opening BareBits from wp-admin signs the
 *     operator in via single-use login tokens,
 *   - a one-time provisioning token (hash only) for the credentials
 *     handshake after setup.
 *
 * The plaintexts are returned and kept in WordPress options; only hashes
 * ever touch the BareBits side.
 *
 * @return array{ok:bool, token?:string, admin_password?:string, sso_key?:string, message?:string}
 */
function cashupay_write_install_config(string $installDir, string $dataDir, string $baseUrl): array {
    $token = bin2hex(random_bytes(32));
    $adminPassword = wp_generate_password(24, true, false);
    $ssoKey = bin2hex(random_bytes(32));
    $config = "<?php\n"
        . "// Written by the BareBits WordPress plugin's installer.\n"
        . "define('CASHUPAY_DATA_DIR', " . var_export(rtrim($dataDir, '/'), true) . ");\n"
        . "// The URL this install is served at; pinned so the app never has\n"
        . "// to trust the Host header or guess its own path.\n"
        . "define('CASHUPAY_BASE_URL', " . var_export(rtrim($baseUrl, '/'), true) . ");\n"
        . "// Managed single-shop install: single-store admin UI, shop-owned\n"
        . "// sections hidden, payer email capture defaulted off, cron screen\n"
        . "// skipped (WP-cron pings cron.php every minute).\n"
        . "define('CASHUPAY_MANAGED_INSTALL', true);\n"
        . "// Payer-facing links prefer the shop.\n"
        . "define('CASHUPAY_SHOP_URL', " . var_export(rtrim(home_url('/'), '/'), true) . ");\n"
        . "define('CASHUPAY_RETRY_URL_TEMPLATE', " . var_export(home_url('/?cashupay-retry={invoiceId}'), true) . ");\n"
        . "// Pre-seeded admin account (wizard skips its password screen); the\n"
        . "// plaintext is held by the WordPress plugin (BareBits page -> reveal).\n"
        . "define('CASHUPAY_ADMIN_PASSWORD_HASH', " . var_export(password_hash($adminPassword, PASSWORD_DEFAULT), true) . ");\n"
        . "// SSO login-token handoff (see sso.php in the install).\n"
        . "define('CASHUPAY_SSO_KEY_HASH', '" . hash('sha256', $ssoKey) . "');\n"
        . "// One-time provisioning handshake (see provision.php in the install).\n"
        . "define('CASHUPAY_PROVISION_TOKEN_HASH', '" . hash('sha256', $token) . "');\n";
    if (file_put_contents(rtrim($installDir, '/') . '/user_config.php', $config) === false) {
        return ['ok' => false, 'message' => 'Could not write user_config.php into the install.'];
    }
    if (!is_dir($dataDir) && !wp_mkdir_p($dataDir)) {
        return ['ok' => false, 'message' => 'Could not create the data directory at ' . $dataDir . '.'];
    }
    return ['ok' => true, 'token' => $token, 'admin_password' => $adminPassword, 'sso_key' => $ssoKey];
}

/**
 * The full install: resolve target → fetch release → download+verify →
 * unpack → write config → persist state. Idempotent: an already-completed
 * install (our own user_config.php present) is reused rather than clobbered,
 * and a directory we did not create is never touched.
 *
 * @return array{ok:bool, message?:string, url?:string, verified?:bool}
 */
function cashupay_run_install(string $dirname = ''): array {
    $target = cashupay_resolve_install_target($dirname);
    if (isset($target['error'])) {
        return ['ok' => false, 'message' => $target['error']];
    }
    $installDir = $target['dir'];

    if (is_dir($installDir)) {
        $ours = (string) get_option('cashupay_install_dir', '');
        if ($ours === $installDir && is_file($installDir . '/user_config.php') && is_file($installDir . '/BUILD_INFO')) {
            // Resuming after a partial onboarding run; the install is in place.
            return ['ok' => true, 'url' => $target['url'], 'verified' => true];
        }
        if (count(array_diff((array) scandir($installDir), ['.', '..'])) > 0) {
            return ['ok' => false, 'message' => 'The directory ' . $installDir . ' already exists and is not empty. Move it aside, or choose a different folder name.'];
        }
        // Empty leftover directory: remove it so rename() can take its place.
        @rmdir($installDir);
    }

    $release = cashupay_fetch_latest_release();
    if (empty($release['ok'])) {
        return ['ok' => false, 'message' => $release['message']];
    }

    $download = cashupay_download_release($release);
    if (empty($download['ok'])) {
        return ['ok' => false, 'message' => $download['message']];
    }

    $unpack = cashupay_unpack_release($download['file'], $installDir);
    @unlink($download['file']);
    if (empty($unpack['ok'])) {
        return ['ok' => false, 'message' => $unpack['message']];
    }

    $dataDir = cashupay_resolve_data_dir($installDir);
    $config = cashupay_write_install_config($installDir, $dataDir, $target['url']);
    if (empty($config['ok'])) {
        return ['ok' => false, 'message' => $config['message']];
    }

    update_option('cashupay_mode', 'install');
    update_option('cashupay_install_dir', $installDir, false);
    update_option('cashupay_install_data_dir', $dataDir, false);
    update_option('cashupay_server_url', $target['url']);
    update_option('cashupay_provision_token', $config['token'], false);
    // The BareBits admin password (its account is pre-seeded from the hash;
    // day-to-day login is automatic via SSO — this is the copy the site
    // admin can reveal when BareBits asks for a password, e.g. revealing a
    // wallet recovery phrase) and the SSO key that mints login tokens.
    update_option('cashupay_admin_password', $config['admin_password'], false);
    update_option('cashupay_sso_key', $config['sso_key'], false);

    return ['ok' => true, 'url' => $target['url'], 'verified' => !empty($download['verified'])];
}

/**
 * Collect the install's credentials through the one-time provisioning
 * handshake, once the operator has finished the BareBits setup wizard.
 *
 * @return array{status:'ready'|'pending'|'error', message?:string,
 *               storeId?:string, apiKey?:string, cronKey?:string}
 */
function cashupay_collect_provision(): array {
    $server = cashupay_server_url();
    $token = (string) get_option('cashupay_provision_token', '');
    if ($server === '' || $token === '') {
        return ['status' => 'error', 'message' => 'No provisioning token — run the installer first.'];
    }
    $response = wp_remote_post($server . '/provision.php', [
        'timeout' => 15,
        'sslverify' => !cashupay_is_same_host_url($server),
        'headers' => ['X-PROVISION-TOKEN' => $token],
    ]);
    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => 'Could not reach the install: ' . $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return ['status' => 'error', 'message' => 'Unexpected answer from the install (HTTP ' . $code . ').'];
    }
    if (($body['status'] ?? '') === 'pending') {
        return ['status' => 'pending'];
    }
    if (($body['status'] ?? '') === 'ready'
            && !empty($body['storeId']) && !empty($body['apiKey']) && !empty($body['cronKey'])) {
        // Single use on both sides: the server just invalidated the exchange,
        // so the plaintext token has no further value here either.
        delete_option('cashupay_provision_token');
        return [
            'status' => 'ready',
            'storeId' => (string) $body['storeId'],
            'apiKey' => (string) $body['apiKey'],
            'cronKey' => (string) $body['cronKey'],
        ];
    }
    return ['status' => 'error', 'message' => (string) ($body['error'] ?? ('HTTP ' . $code))];
}
