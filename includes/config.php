<?php
/**
 * CashuPayServer Configuration Module
 *
 * Load/save configuration from database.
 */

require_once __DIR__ . '/database.php';

// Version
define('CASHUPAY_VERSION', '0.1-alpha');

// Upstream dev fee — paid to the original CashuPayServer author via the
// existing cypherpunk.today donation sink. Triggered on the periodic fee
// settlement cron tick (see includes/dev_fee.php) when ≥ 1000 sats are owed.
// Counts as a network cost when computing the Zaphaus LLC development fee
// base, so the upstream fee never "stacks" on top of the dev fee.
define('CASHUPAY_UPSTREAM_DEV_FEE_PERCENT', 0.5);
define('CASHUPAY_UPSTREAM_DEV_FEE_SINK_URL', 'https://cypherpunk.today/donation-sink/donation-sink.php');

class Config {
    private static array $cache = [];

    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = Database::fetchOne(
            "SELECT value FROM config WHERE key = ?",
            [$key]
        );

        if ($row === null) {
            return $default;
        }

        $value = json_decode($row['value'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $value = $row['value'];
        }

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value): void {
        $now = Database::timestamp();
        $jsonValue = is_string($value) ? $value : json_encode($value);

        $existing = Database::fetchOne(
            "SELECT key FROM config WHERE key = ?",
            [$key]
        );

        if ($existing) {
            Database::update(
                'config',
                ['value' => $jsonValue, 'updated_at' => $now],
                'key = ?',
                [$key]
            );
        } else {
            Database::insert('config', [
                'key' => $key,
                'value' => $jsonValue,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::$cache[$key] = $value;
    }

    /**
     * Delete configuration value
     */
    public static function delete(string $key): void {
        Database::delete('config', 'key = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /**
     * Get all configuration values
     */
    public static function getAll(): array {
        $rows = Database::fetchAll("SELECT key, value FROM config");
        $config = [];

        foreach ($rows as $row) {
            $value = json_decode($row['value'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $value = $row['value'];
            }
            $config[$row['key']] = $value;
        }

        return $config;
    }

    /**
     * Check if setup has been completed
     */
    public static function isSetupComplete(): bool {
        return self::get('setup_complete', false) === true;
    }

    /**
     * Get mint URL
     */
    public static function getMintUrl(): ?string {
        return self::get('mint_url');
    }

    /**
     * Get mint unit
     */
    public static function getMintUnit(): string {
        return self::get('mint_unit', 'sat');
    }

    /**
     * Get seed phrase (encrypted)
     */
    public static function getSeedPhrase(): ?string {
        return self::get('seed_phrase');
    }

    /**
     * Get admin password hash
     */
    public static function getAdminPasswordHash(): ?string {
        return self::get('admin_password_hash');
    }

    /**
     * Get accepted currencies
     */
    public static function getAcceptedCurrencies(): array {
        return self::get('accept_currencies', ['BTC', 'sat']);
    }

    /**
     * Get invoice expiration time in seconds
     */
    public static function getInvoiceExpiration(): int {
        return self::get('invoice_expiration', 900); // 15 minutes default
    }

    /**
     * Get URL mode for standalone deployments
     *
     * @return string 'direct' for clean URLs (/api/v1/...) or 'router' for router.php URLs
     */
    public static function getUrlMode(): string {
        return self::get('url_mode', 'router'); // Default router for max compatibility
    }

    /**
     * Get base URL for the application
     */
    public static function getBaseUrl(): string {
        $baseUrl = self::get('base_url');
        if ($baseUrl) {
            return rtrim($baseUrl, '/');
        }

        // Auto-detect
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');

        return rtrim($protocol . '://' . $host . $path, '/');
    }

    /**
     * Clear configuration cache
     */
    public static function clearCache(): void {
        self::$cache = [];
    }

    // ========================================================================
    // PER-STORE CONFIGURATION
    // ========================================================================

    /**
     * Get store configuration
     */
    public static function getStore(string $storeId): ?array {
        return Database::fetchOne(
            "SELECT * FROM stores WHERE id = ?",
            [$storeId]
        );
    }

    /**
     * Get store's mint URL
     */
    public static function getStoreMintUrl(string $storeId): ?string {
        $store = self::getStore($storeId);
        return $store['mint_url'] ?? null;
    }

    /**
     * Get store's mint unit
     */
    public static function getStoreMintUnit(string $storeId): string {
        $store = self::getStore($storeId);
        return $store['mint_unit'] ?? 'sat';
    }

    /**
     * Get store's seed phrase
     */
    public static function getStoreSeedPhrase(string $storeId): ?string {
        $store = self::getStore($storeId);
        return $store['seed_phrase'] ?? null;
    }

    /**
     * Get store's exchange fee percentage
     */
    public static function getStoreExchangeFee(string $storeId): float {
        $store = self::getStore($storeId);
        return (float)($store['exchange_fee_percent'] ?? 0);
    }

    /**
     * Get store's default display/input currency (sat or fiat code, e.g. USD).
     * Falls back to the mint unit when the column is empty so behavior matches
     * pre-migration installs.
     */
    public static function getStoreDefaultCurrency(string $storeId): string {
        $store = self::getStore($storeId);
        $value = $store['default_currency'] ?? null;
        if (is_string($value) && $value !== '') return strtoupper($value) === 'SATS' ? 'sat' : $value;
        return $store['mint_unit'] ?? 'sat';
    }

    /**
     * Currencies that may be offered as a default display/input currency in
     * addition to the mint's native unit.
     */
    public static function getSupportedDisplayCurrencies(): array {
        return ['sat', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'];
    }

    /**
     * Get store's price provider settings
     */
    public static function getStorePriceProviders(string $storeId): array {
        $store = self::getStore($storeId);
        return [
            'primary' => $store['price_provider_primary'] ?? 'coingecko',
            'secondary' => $store['price_provider_secondary'] ?? 'binance',
        ];
    }

    /**
     * Check if store is configured (has mint and seed phrase)
     */
    public static function isStoreConfigured(string $storeId): bool {
        $store = self::getStore($storeId);
        return $store !== null
            && !empty($store['mint_url'])
            && !empty($store['seed_phrase']);
    }

    /**
     * Update store settings
     *
     * Changing mint_url through this method implicitly marks
     * primary_mint_source='manual' unless the caller passes the column
     * explicitly. The trusted-mints code path uses the explicit value to mark
     * its auto-populated primaries differently so they can later be replaced.
     */
    public static function updateStore(string $storeId, array $data): void {
        $allowed = [
            'name', 'mint_url', 'mint_unit', 'seed_phrase',
            'exchange_fee_percent', 'price_provider_primary', 'price_provider_secondary',
            'default_currency',
            'primary_mint_source',
            // Hosting fee (per-store) — see includes/dev_fee.php
            'hosting_fee_percent', 'hosting_fee_destination',
            // On-chain Bitcoin payment settings
            'onchain_xpub', 'onchain_network', 'onchain_address_type',
            'onchain_next_index', 'onchain_min_confs', 'onchain_confirm_timeout_sec',
            'onchain_provider', 'onchain_provider_url',
        ];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('mint_url', $updateData) && !array_key_exists('primary_mint_source', $updateData)) {
            $updateData['primary_mint_source'] = 'manual';
        }

        if (!empty($updateData)) {
            Database::update('stores', $updateData, 'id = ?', [$storeId]);
        }
    }

    /**
     * Get the source of the store's currently configured primary mint URL.
     * One of: 'manual' (admin entered), 'trusted_list' (auto-populated from
     * the trusted-mints URL), or 'setup' (left empty during initial setup).
     */
    public static function getStorePrimaryMintSource(string $storeId): string {
        $store = self::getStore($storeId);
        return $store['primary_mint_source'] ?? 'manual';
    }

    // ========================================================================
    // PER-STORE BACKUP MINTS MANAGEMENT
    // ========================================================================

    /**
     * Get all backup mints for a store in priority order
     */
    public static function getStoreBackupMints(string $storeId): array {
        return Database::fetchAll(
            "SELECT id, mint_url, unit, priority, enabled, created_at
             FROM store_mints
             WHERE store_id = ?
             ORDER BY priority ASC",
            [$storeId]
        );
    }

    /**
     * Get all enabled backup mints for a store and specific unit
     */
    public static function getStoreEnabledMints(string $storeId, string $unit = 'sat'): array {
        $rows = Database::fetchAll(
            "SELECT mint_url FROM store_mints
             WHERE store_id = ? AND enabled = 1 AND unit = ?
             ORDER BY priority ASC",
            [$storeId, $unit]
        );
        return array_column($rows, 'mint_url');
    }

    /**
     * Get all mint URLs (primary + backups) for a store, filtered through the
     * reliability gate. Mints with disabled_pending_success, permanently_disabled,
     * or trusted_list_disabled are excluded — including the primary, which
     * cleanly falls through to the highest-priority eligible backup.
     */
    public static function getStoreAllMintUrls(string $storeId): array {
        require_once __DIR__ . '/mint_reliability.php';

        $primary = self::getStoreMintUrl($storeId);
        $unit = self::getStoreMintUnit($storeId);
        $backups = self::getStoreEnabledMints($storeId, $unit);

        $candidates = [];
        if ($primary) {
            $candidates[] = $primary;
        }
        foreach ($backups as $backup) {
            if (!$primary || rtrim($backup, '/') !== rtrim($primary, '/')) {
                $candidates[] = $backup;
            }
        }

        $result = [];
        foreach ($candidates as $mintUrl) {
            if (MintReliability::isAvailableForNewInvoices($mintUrl)) {
                $result[] = $mintUrl;
            }
        }
        return $result;
    }

    /**
     * Add a backup mint to a store
     */
    public static function addStoreBackupMint(string $storeId, string $mintUrl, string $unit = 'sat', int $priority = 100): int {
        $mintUrl = rtrim($mintUrl, '/');

        return (int) Database::insert('store_mints', [
            'store_id' => $storeId,
            'mint_url' => $mintUrl,
            'unit' => $unit,
            'priority' => $priority,
            'enabled' => 1,
            'created_at' => Database::timestamp(),
        ]);
    }

    /**
     * Update a store's backup mint settings
     */
    public static function updateStoreBackupMint(int $id, array $data): void {
        $allowed = ['priority', 'enabled'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (!empty($updateData)) {
            Database::update('store_mints', $updateData, 'id = ?', [$id]);
        }
    }

    /**
     * Remove a backup mint from a store
     */
    public static function removeStoreBackupMint(int $id): void {
        Database::delete('store_mints', 'id = ?', [$id]);
    }

    // ========================================================================
    // UTILITIES
    // ========================================================================

    /**
     * Test connectivity to a mint
     *
     * @param string $mintUrl Mint URL to test
     * @return array{success: bool, error: ?string, info: ?array}
     */
    public static function testMintConnection(string $mintUrl): array {
        try {
            $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
            $info = $client->get('info');
            return ['success' => true, 'error' => null, 'info' => $info];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'info' => null];
        }
    }
}
