<?php
/**
 * CashuPay BTCPay WooCommerce Auto-Configuration
 *
 * Safely configures the BTCPay WooCommerce plugin to point to CashuPay.
 * Includes safety checks to avoid overwriting a real BTCPay Server config.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if a real BTCPay Server (not CashuPay) is configured
 */
function cashupay_is_real_btcpay_configured(): bool {
    $url = get_option('btcpay_gf_url', '');
    if (empty($url)) {
        return false;
    }

    // Check if URL points to CashuPay (not a real BTCPay Server)
    $cashupay_url = site_url('/cashupay');
    if (strpos($url, $cashupay_url) === 0) {
        return false; // Already ours
    }

    return true; // Real BTCPay Server is configured
}

/**
 * Fully qualified plugin file for the BTCPay Greenfield WooCommerce gateway,
 * as WordPress identifies it (folder/entry-file).
 */
function cashupay_btcpay_plugin_file(): string {
    return 'btcpay-greenfield-for-woocommerce/btcpay-greenfield-for-woocommerce.php';
}

/**
 * Whether the BTCPay Greenfield WooCommerce gateway plugin is installed AND
 * active.
 */
function cashupay_is_btcpay_plugin_active(): bool {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active(cashupay_btcpay_plugin_file());
}

/**
 * Enable the BTCPay gateway in WooCommerce so it appears at checkout.
 *
 * WooCommerce stores each gateway's config under
 * woocommerce_{gateway_id}_settings; the Greenfield gateway's id is
 * btcpaygf_default (see the plugin's DefaultGateway). Merchants normally flip
 * this by hand under WooCommerce → Settings → Payments; doing it here removes
 * that last manual step so payments work the moment setup finishes.
 */
function cashupay_enable_btcpay_gateway(): void {
    $optionKey = 'woocommerce_btcpaygf_default_settings';
    $settings = get_option($optionKey, []);
    if (!is_array($settings)) {
        $settings = [];
    }
    $settings['enabled'] = 'yes';
    update_option($optionKey, $settings);
}

/**
 * Whether this WordPress install can install plugins programmatically without
 * prompting for FTP/SSH credentials.
 *
 * WordPress writes plugin files through the WP_Filesystem abstraction. Only the
 * "direct" transport works unattended; ftp/ssh transports need credentials we
 * cannot collect during a headless setup render. DISALLOW_FILE_MODS (common on
 * managed hosts) blocks all plugin installs outright. When either check fails
 * we fall back to asking the merchant to install the plugin by hand.
 */
function cashupay_can_install_plugins(): bool {
    if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
        return false;
    }
    if (!function_exists('get_filesystem_method')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    return get_filesystem_method() === 'direct';
}

/**
 * Install (from the WordPress.org plugin directory) and activate the BTCPay
 * Greenfield WooCommerce gateway.
 *
 * Idempotent: if the plugin is already present it is only (re)activated; if it
 * is already active this is a no-op. The 'installed' flag in the result is true
 * only when this call *freshly downloaded* the plugin — the completion screen
 * uses it to decide whether to show the "we installed a plugin for you" notice.
 *
 * @return array{success:bool, installed:bool, error?:string, message?:string}
 */
function cashupay_install_btcpay_plugin(): array {
    $pluginFile = cashupay_btcpay_plugin_file();

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $alreadyInstalled = array_key_exists($pluginFile, get_plugins());
    $freshlyInstalled = false;

    if (!$alreadyInstalled) {
        if (!cashupay_can_install_plugins()) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'filesystem',
                'message' => 'This host does not allow unattended plugin installs.',
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', [
            'slug' => 'btcpay-greenfield-for-woocommerce',
            'fields' => ['sections' => false],
        ]);
        if (is_wp_error($api)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'plugins_api',
                'message' => $api->get_error_message(),
            ];
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => $result->get_error_message(),
            ];
        }
        if (!$result) {
            return [
                'success' => false,
                'installed' => false,
                'error' => 'install',
                'message' => 'The plugin could not be installed.',
            ];
        }
        $freshlyInstalled = true;
    }

    if (!is_plugin_active($pluginFile)) {
        $activated = activate_plugin($pluginFile);
        if (is_wp_error($activated)) {
            return [
                'success' => false,
                'installed' => $freshlyInstalled,
                'error' => 'activate',
                'message' => $activated->get_error_message(),
            ];
        }
    }

    return ['success' => true, 'installed' => $freshlyInstalled];
}

/**
 * One call that makes WooCommerce ready to take payments through BareBits:
 * ensure the BTCPay gateway plugin is installed + active, point it at this
 * server's Greenfield API, register the webhook, and enable the gateway at
 * checkout. Safe to call on every completion-screen render.
 *
 * Returns a status the completion screen renders from:
 *   - 'ready'            everything wired; 'auto_installed' says whether we had
 *                        to fetch the gateway plugin ourselves.
 *   - 'existing_btcpay'  a real BTCPay Server is configured; we never clobber it.
 *   - 'needs_woocommerce' WooCommerce itself isn't active.
 *   - 'needs_plugin'     the gateway plugin is missing and we couldn't install
 *                        it unattended (merchant must add it by hand).
 *   - 'error'            configuration failed after the plugin was available.
 *
 * @return array{status:string, auto_installed:bool, message?:string, current_url?:string, webhook?:mixed}
 */
function cashupay_ensure_woocommerce_integration(string $store_id, string $api_key): array {
    // Never overwrite a merchant's real BTCPay Server connection.
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'status' => 'existing_btcpay',
            'auto_installed' => false,
            'current_url' => get_option('btcpay_gf_url', ''),
        ];
    }

    // The gateway plugin is useless without WooCommerce; don't install it into
    // a site that can't run it.
    if (!class_exists('WooCommerce')) {
        return ['status' => 'needs_woocommerce', 'auto_installed' => false];
    }

    $autoInstalled = false;
    if (!cashupay_is_btcpay_plugin_active()) {
        $install = cashupay_install_btcpay_plugin();
        if (empty($install['success'])) {
            return [
                'status' => 'needs_plugin',
                'auto_installed' => false,
                'message' => $install['message'] ?? '',
            ];
        }
        $autoInstalled = !empty($install['installed']);
    }

    $config = cashupay_configure_btcpay_plugin($store_id, $api_key);
    if (empty($config['success'])) {
        return [
            'status' => 'error',
            'auto_installed' => $autoInstalled,
            'message' => $config['message'] ?? 'Could not configure the BTCPay plugin.',
        ];
    }

    cashupay_enable_btcpay_gateway();

    return [
        'status' => 'ready',
        'auto_installed' => $autoInstalled,
        'webhook' => $config['webhook'] ?? null,
    ];
}

/**
 * Configure the BTCPay WooCommerce plugin to use CashuPay
 */
function cashupay_configure_btcpay_plugin(string $store_id, string $api_key): array {
    if (cashupay_is_real_btcpay_configured()) {
        return [
            'success' => false,
            'error' => 'existing_btcpay',
            'current_url' => get_option('btcpay_gf_url', ''),
            'message' => 'A real BTCPay Server is already configured. '
                       . 'Disconnect it first via WooCommerce > Settings > Payments > BTCPay.'
        ];
    }

    update_option('btcpay_gf_url', site_url('/cashupay'));
    update_option('btcpay_gf_api_key', $api_key);
    update_option('btcpay_gf_store_id', $store_id);

    // Register webhook with CashuPayServer for invoice events
    $webhookResult = cashupay_register_webhook($store_id);

    return [
        'success' => true,
        'webhook' => $webhookResult
    ];
}

/**
 * Register a webhook with CashuPayServer for WooCommerce BTCPay plugin
 *
 * The BTCPay WooCommerce plugin expects webhooks at: /?wc-api=btcpaygf_default
 */
function cashupay_register_webhook(string $store_id): array {
    // Build the webhook callback URL (same as WC()->api_request_url('btcpaygf_default'))
    $webhookUrl = site_url('/?wc-api=btcpaygf_default');

    // CashuPayServer database path
    $dataDir = defined('CASHUPAY_DATA_DIR') ? CASHUPAY_DATA_DIR : ABSPATH . 'cashupay/data';
    $dbPath = rtrim($dataDir, '/') . '/cashupay.sqlite';

    if (!file_exists($dbPath)) {
        return [
            'success' => false,
            'error' => 'Database not found at: ' . $dbPath
        ];
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if webhook already exists for this store and URL
        $stmt = $pdo->prepare("SELECT id, secret FROM webhooks WHERE store_id = ? AND url = ?");
        $stmt->execute([$store_id, $webhookUrl]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Webhook already exists, store secret in WooCommerce options
            update_option('btcpay_gf_webhook', [
                'id' => $existing['id'],
                'url' => $webhookUrl,
                'secret' => $existing['secret']
            ]);

            return [
                'success' => true,
                'webhook_id' => $existing['id'],
                'existing' => true
            ];
        }

        // Generate webhook ID and secret
        $webhookId = 'wh_' . bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(32));

        // Events that BTCPay WooCommerce plugin expects
        $events = json_encode([
            'InvoiceCreated',
            'InvoiceReceivedPayment',
            'InvoiceProcessing',
            'InvoiceSettled',
            'InvoiceExpired',
            'InvoiceInvalid'
        ]);

        // Insert webhook into CashuPayServer database
        $stmt = $pdo->prepare("
            INSERT INTO webhooks (id, store_id, url, secret, events, enabled, created_at)
            VALUES (?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $webhookId,
            $store_id,
            $webhookUrl,
            $secret,
            $events,
            time()
        ]);

        // Store webhook info in WooCommerce options (BTCPay plugin expects this)
        update_option('btcpay_gf_webhook', [
            'id' => $webhookId,
            'url' => $webhookUrl,
            'secret' => $secret
        ]);

        return [
            'success' => true,
            'webhook_id' => $webhookId,
            'existing' => false
        ];

    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}
