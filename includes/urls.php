<?php
/**
 * CashuPayServer - Centralized URL Helper
 *
 * All URL generation logic in one place. Handles both WordPress
 * and standalone mode without scattered conditionals.
 */

class Urls {
    /** @var string|null Plugin file path for WordPress plugins_url() */
    private static ?string $pluginFile = null;

    /**
     * Initialize with WordPress plugin file path.
     * Call this from wordpress/bootstrap.php.
     */
    public static function init(string $pluginFile): void {
        self::$pluginFile = $pluginFile;
    }

    /**
     * Check if running in WordPress mode
     */
    public static function isWordPress(): bool {
        return defined('CASHUPAY_WORDPRESS') && CASHUPAY_WORDPRESS;
    }

    /**
     * Human-readable label for a URL routing mode (standalone only).
     *
     * Centralised so the admin settings card and the setup summary render the
     * same wording. Falls back to the current configured mode when none given.
     */
    public static function urlModeLabel(?string $mode = null): string {
        if (self::isWordPress()) {
            return 'WordPress routing';
        }
        $mode = $mode ?? Config::getUrlMode();
        switch ($mode) {
            case 'clean':  return 'Clean URLs';
            case 'direct': return 'Direct URLs';
            default:       return 'Router.php URLs';
        }
    }

    /**
     * Get the server URL for e-commerce integration (BTCPay API base).
     * This is the URL e-commerce plugins should use as "BTCPay Server URL".
     */
    public static function server(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay');
        }

        // Standalone: only router mode needs the /router.php front-controller
        // prefix. In both clean (rewrite-to-router.php) and direct (.htaccess
        // rewrites /api/v1 straight to api.php) modes the API is reachable at
        // the bare base URL.
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();

        return $mode === 'router' ? $base . '/router.php' : $base;
    }

    /**
     * Get the admin dashboard URL
     */
    public static function admin(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay-admin/');
        }
        // Clean mode serves the SPA at the extension-less /admin route (the
        // front-controller rewrite forwards it to router.php -> admin.php).
        // Absolute so it is a valid redirect target from index.php. Direct and
        // router modes keep the relative 'admin.php', which resolves to the
        // real file from the directory index under both.
        if (Config::getUrlMode() === 'clean') {
            return rtrim(Config::getBaseUrl(), '/') . '/admin';
        }
        return 'admin.php';
    }

    /**
     * Get the setup wizard URL.
     *
     * Returns an absolute URL so it resolves correctly when called from
     * admin views that live under a deeper path (e.g. /admin/stores after
     * path-based view routing). Mirrors the absolute-URL convention used
     * by server(), payment(), cron(), and receive().
     */
    public static function setup(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay-setup/');
        }
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();
        if ($mode === 'clean')  return $base . '/setup';
        if ($mode === 'router') return $base . '/router.php/setup.php';
        return $base . '/setup.php'; // direct
    }

    /**
     * Get the URL for static assets (JS, CSS, etc.)
     *
     * @param string $subpath Path within assets/ directory (e.g., 'js/', 'css/')
     * @return string Base URL for assets
     */
    public static function assets(string $subpath = ''): string {
        if (self::isWordPress()) {
            // Use the plugin file if set, otherwise fallback to constant
            $pluginFile = self::$pluginFile ?? (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR . '/cashupay.php' : __FILE__);
            return plugins_url('assets/' . $subpath, $pluginFile);
        }
        // Absolute (base-rooted) URL rather than a page-relative 'assets/...'.
        // With path-based admin routing the SPA is served at sub-paths like
        // /admin/dashboard and /admin/stats, where a relative 'assets/...'
        // resolves to /admin/assets/... and 404s — which silently broke
        // chart.min.js (blank stats charts), mint-discovery, animated-qr and
        // flag images. A base-rooted URL loads correctly from any sub-path.
        return Config::getBaseUrl() . '/assets/' . $subpath;
    }

    /**
     * Get the URL for static images.
     *
     * @param string $subpath Path within images/ directory (e.g., 'payment-methods/strike.png')
     * @return string Full URL to the image
     */
    public static function images(string $subpath = ''): string {
        if (self::isWordPress()) {
            $pluginFile = self::$pluginFile ?? (defined('CASHUPAY_PLUGIN_DIR') ? CASHUPAY_PLUGIN_DIR . '/cashupay.php' : __FILE__);
            return plugins_url('images/' . $subpath, $pluginFile);
        }
        // Base-rooted ABSOLUTE URL, not a page-relative 'images/...'. In clean
        // mode the payment page is served at the sub-path /payment/{id}, where a
        // relative 'images/payment-methods/foo.svg' resolves to
        // /payment/images/... and 404s — which silently blanked the "Pay with …"
        // wallet logos. Mirrors assets(): a base-rooted URL loads from any path.
        return Config::getBaseUrl() . '/images/' . $subpath;
    }

    /**
     * Get the API base URL (same as server URL for API calls)
     */
    public static function api(): string {
        return rtrim(self::server(), '/');
    }

    /**
     * Get the payment page URL
     *
     * @param string $invoiceId Invoice ID (optional)
     * @return string Payment page URL
     */
    public static function payment(string $invoiceId = ''): string {
        if (self::isWordPress()) {
            $url = site_url('/cashupay/payment/');
            return $invoiceId ? $url . $invoiceId : $url;
        }

        $base = rtrim(Config::getBaseUrl(), '/');
        // Clean mode uses the pretty /payment/{id} route (router.php maps the
        // path tail into $_GET['id']); other modes hit payment.php directly.
        if (Config::getUrlMode() === 'clean') {
            return $invoiceId ? $base . '/payment/' . rawurlencode($invoiceId) : $base . '/payment';
        }
        $file = $base . '/payment.php';
        return $invoiceId ? $file . '?id=' . urlencode($invoiceId) : $file;
    }

    /**
     * Get the public self-serve invoice page URL for a store (/pay/{storeId}).
     *
     * Mirrors setup(): in router mode the pretty path is served through
     * router.php; in direct mode we link straight to the file with a query
     * param so it works without any URL rewrites.
     */
    public static function selfServe(string $storeId): string {
        if (self::isWordPress()) {
            return site_url('/cashupay/pay/' . rawurlencode($storeId));
        }
        $base = rtrim(Config::getBaseUrl(), '/');
        $mode = Config::getUrlMode();
        if ($mode === 'clean')  return $base . '/pay/' . rawurlencode($storeId);
        if ($mode === 'router') return $base . '/router.php/pay/' . rawurlencode($storeId);
        return $base . '/pay.php?store=' . urlencode($storeId); // direct
    }

    /**
     * Get the API key authorization (pairing) URL
     *
     * @param array $params Query parameters for the authorization request
     * @return string Full pairing URL with parameters
     */
    public static function pairing(array $params = []): string {
        $serverUrl = rtrim(self::server(), '/');
        $url = $serverUrl . '/api-keys/authorize';

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Build the standard BTCPay pairing URL with test permissions
     */
    public static function pairingTest(): string {
        return self::pairing([
            'applicationName' => 'Test Connection',
            'permissions' => [
                'btcpay.store.canviewinvoices',
                'btcpay.store.cancreateinvoice',
                'btcpay.store.webhooks.canmodifywebhooks',
            ],
            'strict' => 'true',
        ]);
    }

    /**
     * Get the cron/background task URL
     */
    public static function cron(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay/cron');
        }
        return rtrim(Config::getBaseUrl(), '/') . '/cron.php';
    }

    /**
     * Get the receive endpoint URL (NUT-18 payment requests)
     */
    public static function receive(): string {
        if (self::isWordPress()) {
            return site_url('/cashupay/receive');
        }
        return rtrim(Config::getBaseUrl(), '/') . '/receive.php';
    }

    /**
     * Get all URLs as JSON for JavaScript consumption
     */
    public static function toJson(): string {
        return json_encode([
            'server' => self::server(),
            'admin' => self::admin(),
            'setup' => self::setup(),
            'assets' => self::assets(),
            'assetsJs' => self::assets('js/'),
            'api' => self::api(),
            'pairing' => self::pairing(),
            'pairingTest' => self::pairingTest(),
            'cron' => self::cron(),
            'receive' => self::receive(),
            'isWordPress' => self::isWordPress(),
        ]);
    }

    /**
     * Get site base URL (for security tests and redirects)
     * In WordPress, uses site_url(). In standalone, uses Config::getBaseUrl().
     */
    public static function siteBase(): string {
        if (self::isWordPress()) {
            return rtrim(site_url(), '/');
        }
        return rtrim(Config::getBaseUrl(), '/');
    }
}
