<?php
/**
 * CashuPayServer Database Module
 *
 * PDO wrapper for SQLite database operations.
 *
 * CUSTOM DATA PATH:
 * For better security, you can store data outside the web root.
 * Create a file at includes/config.local.php with:
 *
 *   <?php
 *   define('CASHUPAY_DATA_DIR', '/path/outside/webroot/cashupay-data');
 *
 * The directory will be created automatically with proper permissions.
 */

require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

// Load custom config if exists
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
// Load operator deployment-time settings (free trial, etc.) from the
// project-root user_config.php. Constants defined there take precedence
// over equivalent env vars — see Database::settingValue().
if (file_exists(__DIR__ . '/../user_config.php')) {
    require_once __DIR__ . '/../user_config.php';
}

use Cashu\WalletStorage;

class Database {
    private static ?PDO $instance = null;
    private static ?string $dbPath = null;
    private static ?string $dataDir = null;

    /**
     * Get the data directory path
     */
    public static function getDataDir(): string {
        if (self::$dataDir === null) {
            // Check for custom path
            if (defined('CASHUPAY_DATA_DIR')) {
                self::$dataDir = rtrim(CASHUPAY_DATA_DIR, '/');
            } else {
                self::$dataDir = __DIR__ . '/../data';
            }
        }
        return self::$dataDir;
    }

    /**
     * Get the database file path
     */
    public static function getDbPath(): string {
        if (self::$dbPath === null) {
            self::$dbPath = self::getDataDir() . '/cashupay.sqlite';
        }
        return self::$dbPath;
    }

    /**
     * Check if data directory is outside document root (more secure)
     */
    public static function isDataDirOutsideWebroot(): bool {
        $dataDir = realpath(self::getDataDir()) ?: self::getDataDir();
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..') ?: '';

        if (empty($docRoot)) {
            return false;
        }

        return strpos($dataDir, $docRoot) !== 0;
    }

    /**
     * Get PDO instance (singleton).
     *
     * On first connection per process, if the schema is already initialized
     * (config table exists) but a newer-than-original migration target is
     * missing (the users table, added by the multi-user feature), run the
     * idempotent migrations. This catches upgrade-from-pre-multi-user
     * installs where setup.php (and therefore initialize()) never runs again.
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::connect();

            $hasConfig = self::$instance
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'")
                ->fetch() !== false;
            $hasUsers = self::$instance
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
                ->fetch() !== false;
            $hasReliability = self::$instance
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mint_reliability'")
                ->fetch() !== false;
            // Marker for the most recent migration set. When a new migration
            // is added, set this to a column/table that only exists *after*
            // that migration ran — getInstance() will then trigger runMigrations()
            // on existing installs that haven't yet picked it up. All migrations
            // are idempotent, so a fire is safe.
            $hasLatestMigration = $hasConfig && self::columnExists(self::$instance, 'swap_attempts', 'provider_response_json');

            if ($hasConfig && (!$hasUsers || !$hasReliability || !$hasLatestMigration)) {
                if (!$hasUsers) {
                    self::$instance->exec("
                        CREATE TABLE IF NOT EXISTS users (
                            id              TEXT PRIMARY KEY,
                            username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                            password_hash   TEXT NOT NULL,
                            role            TEXT NOT NULL CHECK (role IN ('admin','user')),
                            created_at      INTEGER NOT NULL
                        );
                    ");
                }
                self::runMigrations(self::$instance);
            }
        }
        return self::$instance;
    }

    /**
     * Create database connection
     */
    private static function connect(): PDO {
        $dir = self::getDataDir();
        if (!is_dir($dir)) {
            self::createDataDirectory($dir);
        }

        $pdo = new PDO('sqlite:' . self::getDbPath());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000'); // Wait up to 5 seconds for locks

        return $pdo;
    }

    /**
     * Create data directory with .htaccess protection
     */
    private static function createDataDirectory(string $dir): void {
        // Create directory
        if (!mkdir($dir, 0750, true)) {
            throw new Exception("Failed to create data directory: $dir");
        }

        // Create .htaccess for Apache protection
        $htaccess = $dir . '/.htaccess';
        $htaccessContent = <<<'HTACCESS'
# Deny all access to this directory
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
        file_put_contents($htaccess, $htaccessContent);

        // Create index.php as additional protection
        $indexPhp = $dir . '/index.php';
        file_put_contents($indexPhp, "<?php http_response_code(403); exit('Forbidden');");
    }

    /**
     * Check if database exists and has been initialized
     */
    public static function isInitialized(): bool {
        if (!file_exists(self::getDbPath())) {
            return false;
        }

        try {
            $pdo = self::getInstance();
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Ensure the database exists (creates directory, .htaccess, and empty DB)
     */
    public static function ensureExists(): void {
        $dir = self::getDataDir();
        if (!is_dir($dir)) {
            self::createDataDirectory($dir);
        }

        // Touch the database file to ensure it exists
        if (!file_exists(self::getDbPath())) {
            self::getInstance(); // This creates the DB
        }
    }

    /**
     * Initialize database schema
     */
    public static function initialize(): void {
        $pdo = self::getInstance();

        $schema = "
        -- Core configuration
        CREATE TABLE IF NOT EXISTS config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );

        -- Stores (per-store configuration with own mint and wallet)
        CREATE TABLE IF NOT EXISTS stores (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            internal_api_key TEXT,
            -- Mint configuration (required for store to be active)
            mint_url TEXT,
            mint_unit TEXT NOT NULL DEFAULT 'sat',
            seed_phrase TEXT,
            -- Exchange settings
            exchange_fee_percent REAL NOT NULL DEFAULT 0,
            price_provider_primary TEXT NOT NULL DEFAULT 'coingecko',
            price_provider_secondary TEXT DEFAULT 'binance',
            -- Auto-withdraw settings (per-store)
            auto_melt_enabled INTEGER NOT NULL DEFAULT 0,
            auto_melt_address TEXT,
            auto_melt_threshold INTEGER NOT NULL DEFAULT 2000,
            -- Default display/input currency for the merchant UI (sat or fiat code)
            default_currency TEXT NOT NULL DEFAULT 'sat',
            -- Timestamps
            created_at INTEGER NOT NULL
        );

        -- API keys
        CREATE TABLE IF NOT EXISTS api_keys (
            id TEXT PRIMARY KEY,
            key_hash TEXT NOT NULL UNIQUE,
            store_id TEXT NOT NULL,
            label TEXT,
            permissions TEXT NOT NULL,
            application_identifier TEXT,
            redirect_host TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_api_keys_app_id
            ON api_keys(store_id, application_identifier, redirect_host);

        -- Invoices (BTCPay compatible)
        CREATE TABLE IF NOT EXISTS invoices (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'New',
            additional_status TEXT DEFAULT 'None',
            amount TEXT NOT NULL,
            currency TEXT NOT NULL,
            amount_sats INTEGER,
            exchange_rate REAL,
            quote_id TEXT,
            bolt11 TEXT,
            mint_url TEXT,
            metadata TEXT,
            checkout_config TEXT,
            created_at INTEGER NOT NULL,
            expiration_time INTEGER NOT NULL,
            last_polled_at INTEGER DEFAULT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        -- Webhooks
        CREATE TABLE IF NOT EXISTS webhooks (
            id TEXT PRIMARY KEY,
            store_id TEXT NOT NULL,
            url TEXT NOT NULL,
            secret TEXT NOT NULL,
            events TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
        );

        -- Webhook deliveries (for retry/debug)
        CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id TEXT PRIMARY KEY,
            webhook_id TEXT NOT NULL,
            invoice_id TEXT,
            event_type TEXT NOT NULL,
            payload TEXT NOT NULL,
            status_code INTEGER,
            response TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
        );

        -- Users: web-admin identities. Single 'admin' role gates fund moves
        -- and store/config changes; 'user' role can view + create invoices.
        -- Migrated from the legacy single config.admin_password_hash slot —
        -- see runMigrations().
        CREATE TABLE IF NOT EXISTS users (
            id              TEXT PRIMARY KEY,
            username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash   TEXT NOT NULL,
            role            TEXT NOT NULL CHECK (role IN ('admin','user')),
            created_at      INTEGER NOT NULL
        );

        -- Per-store backup mints for failover
        CREATE TABLE IF NOT EXISTS store_mints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id TEXT NOT NULL,
            mint_url TEXT NOT NULL,
            unit TEXT NOT NULL DEFAULT 'sat',
            priority INTEGER NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
            UNIQUE(store_id, mint_url)
        );

        -- Mint reliability tracking (global, keyed by mint_url). Holds the
        -- lifetime + transient state flags used to decide whether a mint is
        -- eligible for new invoices. Successful withdraws clear
        -- disabled_pending_success; permanently_disabled and trusted_list_disabled
        -- only clear via admin action / trusted list refresh respectively.
        CREATE TABLE IF NOT EXISTS mint_reliability (
            mint_url TEXT PRIMARY KEY,
            total_failures INTEGER NOT NULL DEFAULT 0,
            consecutive_failures INTEGER NOT NULL DEFAULT 0,
            disabled_pending_success INTEGER NOT NULL DEFAULT 0,
            permanently_disabled INTEGER NOT NULL DEFAULT 0,
            trusted_list_disabled INTEGER NOT NULL DEFAULT 0,
            trusted_list_disabled_reason TEXT,
            last_failure_at INTEGER,
            last_failure_kind TEXT,
            last_failure_message TEXT,
            last_success_at INTEGER,
            updated_at INTEGER NOT NULL
        );

        -- Per-mint event log: failures + every state-change decision. UI surfaces
        -- this as the diagnostic view. Capped at 1000 rows per mint_url (oldest
        -- pruned on insert).
        CREATE TABLE IF NOT EXISTS mint_event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mint_url TEXT NOT NULL,
            timestamp INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            failure_type TEXT,
            store_id TEXT,
            address TEXT,
            details TEXT
        );

        -- Open suspect rows for LIGHTNING_WALLET_ERROR pending verification.
        -- Resolved by another mint succeeding/failing at the same address (#1)
        -- or by protocol introspection (#2). Repeated same-pair failures bump
        -- last_seen_at rather than inserting a new row.
        CREATE TABLE IF NOT EXISTS mint_suspect (
            mint_url TEXT NOT NULL,
            address TEXT NOT NULL,
            store_id TEXT,
            opened_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            PRIMARY KEY (mint_url, address)
        );

        -- Indexes for performance
        CREATE INDEX IF NOT EXISTS idx_invoices_store ON invoices(store_id);
        CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
        CREATE INDEX IF NOT EXISTS idx_invoices_quote ON invoices(quote_id);
        CREATE INDEX IF NOT EXISTS idx_api_keys_store ON api_keys(store_id);
        CREATE INDEX IF NOT EXISTS idx_webhooks_store ON webhooks(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_store ON store_mints(store_id);
        CREATE INDEX IF NOT EXISTS idx_store_mints_priority ON store_mints(store_id, priority);
        CREATE INDEX IF NOT EXISTS idx_mint_event_log_mint ON mint_event_log(mint_url, timestamp DESC);
        CREATE INDEX IF NOT EXISTS idx_mint_event_log_address ON mint_event_log(address) WHERE address IS NOT NULL;
        CREATE INDEX IF NOT EXISTS idx_mint_suspect_address ON mint_suspect(address);
        ";

        $pdo->exec($schema);

        self::runMigrations($pdo);

        // Initialize wallet storage schema (for cashu-wallet-php library)
        WalletStorage::initializeSchema($pdo);
    }

    /**
     * Apply idempotent schema migrations for existing databases.
     */
    private static function runMigrations(\PDO $pdo): void {
        if (!self::columnExists($pdo, 'invoices', 'mint_url')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN mint_url TEXT");
        }
        if (!self::columnExists($pdo, 'invoices', 'last_polled_at')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN last_polled_at INTEGER DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'stores', 'default_currency')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN default_currency TEXT NOT NULL DEFAULT 'sat'");
        }

        // On-chain Bitcoin payment support (per-store xpub + lifecycle state).
        if (!self::columnExists($pdo, 'stores', 'onchain_xpub')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_xpub TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_network')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_network TEXT NOT NULL DEFAULT 'mainnet'");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_address_type')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_address_type TEXT NOT NULL DEFAULT 'P2WPKH'");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_next_index')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_next_index INTEGER NOT NULL DEFAULT 0");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_min_confs')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_min_confs INTEGER NOT NULL DEFAULT 1");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_confirm_timeout_sec')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_confirm_timeout_sec INTEGER NOT NULL DEFAULT 86400");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_provider')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_provider TEXT NOT NULL DEFAULT 'esplora'");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_provider_url')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_provider_url TEXT DEFAULT NULL");
        }

        if (!self::columnExists($pdo, 'invoices', 'onchain_address')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_address TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'onchain_address_index')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_address_index INTEGER DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'onchain_amount_sat')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_amount_sat INTEGER DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'onchain_first_seen_at')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_first_seen_at INTEGER DEFAULT NULL");
        }
        // Chain tip height at invoice creation. Used by the poller to discard
        // historical UTXOs on a re-used address (txs confirmed BEFORE the
        // invoice existed). NULL on legacy rows → no filtering applied.
        if (!self::columnExists($pdo, 'invoices', 'onchain_created_tip_height')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_created_tip_height INTEGER DEFAULT NULL");
        }

        if (!self::tableExists($pdo, 'onchain_xpub_state')) {
            $pdo->exec("
                CREATE TABLE onchain_xpub_state (
                    xpub_hash TEXT PRIMARY KEY,
                    next_index INTEGER NOT NULL DEFAULT 0,
                    updated_at INTEGER NOT NULL DEFAULT 0
                );
            ");
        }

        if (!self::tableExists($pdo, 'onchain_payments')) {
            $pdo->exec("
                CREATE TABLE onchain_payments (
                    id TEXT PRIMARY KEY,
                    invoice_id TEXT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                    txid TEXT NOT NULL,
                    vout INTEGER NOT NULL,
                    amount_sat INTEGER NOT NULL,
                    confirmations INTEGER NOT NULL DEFAULT 0,
                    block_height INTEGER,
                    first_seen_at INTEGER NOT NULL,
                    last_seen_at INTEGER NOT NULL,
                    UNIQUE(txid, vout)
                );
            ");
            $pdo->exec("CREATE INDEX idx_onchain_payments_invoice ON onchain_payments(invoice_id);");
        }
        if (!self::indexExists($pdo, 'idx_invoices_onchain_address')) {
            $pdo->exec("CREATE INDEX idx_invoices_onchain_address ON invoices(onchain_address) WHERE onchain_address IS NOT NULL;");
        }

        // Mint reliability tracking + trusted mints feature: tables and the
        // primary_mint_source column distinguishing admin-set vs auto-populated
        // primary mints. See includes/mint_reliability.php and
        // includes/trusted_mints.php for the consumers.
        if (!self::columnExists($pdo, 'stores', 'primary_mint_source')) {
            // Existing rows: presume admin set the primary explicitly.
            $pdo->exec("ALTER TABLE stores ADD COLUMN primary_mint_source TEXT NOT NULL DEFAULT 'manual'");
        }
        if (!self::tableExists($pdo, 'mint_reliability')) {
            $pdo->exec("
                CREATE TABLE mint_reliability (
                    mint_url TEXT PRIMARY KEY,
                    total_failures INTEGER NOT NULL DEFAULT 0,
                    consecutive_failures INTEGER NOT NULL DEFAULT 0,
                    disabled_pending_success INTEGER NOT NULL DEFAULT 0,
                    permanently_disabled INTEGER NOT NULL DEFAULT 0,
                    trusted_list_disabled INTEGER NOT NULL DEFAULT 0,
                    trusted_list_disabled_reason TEXT,
                    last_failure_at INTEGER,
                    last_failure_kind TEXT,
                    last_failure_message TEXT,
                    last_success_at INTEGER,
                    updated_at INTEGER NOT NULL
                );
            ");
        }
        if (!self::tableExists($pdo, 'mint_event_log')) {
            $pdo->exec("
                CREATE TABLE mint_event_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    mint_url TEXT NOT NULL,
                    timestamp INTEGER NOT NULL,
                    event_type TEXT NOT NULL,
                    failure_type TEXT,
                    store_id TEXT,
                    address TEXT,
                    details TEXT
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_mint_event_log_mint')) {
            $pdo->exec("CREATE INDEX idx_mint_event_log_mint ON mint_event_log(mint_url, timestamp DESC);");
        }
        if (!self::indexExists($pdo, 'idx_mint_event_log_address')) {
            $pdo->exec("CREATE INDEX idx_mint_event_log_address ON mint_event_log(address) WHERE address IS NOT NULL;");
        }
        if (!self::tableExists($pdo, 'mint_suspect')) {
            $pdo->exec("
                CREATE TABLE mint_suspect (
                    mint_url TEXT NOT NULL,
                    address TEXT NOT NULL,
                    store_id TEXT,
                    opened_at INTEGER NOT NULL,
                    last_seen_at INTEGER NOT NULL,
                    PRIMARY KEY (mint_url, address)
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_mint_suspect_address')) {
            $pdo->exec("CREATE INDEX idx_mint_suspect_address ON mint_suspect(address);");
        }

        // Multi-user migration: existing installs have a single admin password
        // stored in config.admin_password_hash. Copy it into the new users
        // table as the 'admin' user the first time we see an empty users table
        // alongside a populated legacy slot.
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) {
            $legacy = $pdo->query("SELECT value FROM config WHERE key = 'admin_password_hash'")
                ->fetchColumn();
            if ($legacy) {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (id, username, password_hash, role, created_at)
                     VALUES (?, 'admin', ?, 'admin', ?)"
                );
                $stmt->execute([self::generateId('user'), $legacy, time()]);
            }
        }

        // Modified MIT dev fee + per-store hosting fee. Revenue tracked in sats
        // from this migration timestamp forward (pre-existing invoices are not
        // backfilled). melts table is the source of truth for fee-paid totals
        // and the future stats dashboard.
        if (!self::columnExists($pdo, 'stores', 'hosting_fee_percent')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN hosting_fee_percent REAL NOT NULL DEFAULT 0");
        }
        if (!self::columnExists($pdo, 'stores', 'hosting_fee_destination')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN hosting_fee_destination TEXT");
        }
        if (!self::tableExists($pdo, 'melts')) {
            $pdo->exec("
                CREATE TABLE melts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id TEXT NOT NULL,
                    amount_sats INTEGER NOT NULL,
                    network_fee_sats INTEGER NOT NULL DEFAULT 0,
                    destination TEXT NOT NULL,
                    preimage TEXT,
                    note TEXT,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_melts_store_note')) {
            $pdo->exec("CREATE INDEX idx_melts_store_note ON melts(store_id, note);");
        }
        if (!self::indexExists($pdo, 'idx_melts_created')) {
            $pdo->exec("CREATE INDEX idx_melts_created ON melts(created_at);");
        }

        // Seed deployment_id once from env CASHUPAY_DEPLOYMENT_ID or 'ANONYMOUS'.
        $depRow = $pdo->query("SELECT value FROM config WHERE key = 'deployment_id'")->fetchColumn();
        if ($depRow === false) {
            $envDep = getenv('CASHUPAY_DEPLOYMENT_ID');
            $value = ($envDep !== false && $envDep !== '') ? $envDep : 'ANONYMOUS';
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES ('deployment_id', ?, ?, ?)"
            );
            $stmt->execute([json_encode($value), $now, $now]);
        }
        // Stamp the migration timestamp once so dev fee math only sees revenue
        // accrued from this point forward.
        $startRow = $pdo->query("SELECT value FROM config WHERE key = 'fee_tracking_start_at'")->fetchColumn();
        if ($startRow === false) {
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES ('fee_tracking_start_at', ?, ?, ?)"
            );
            $stmt->execute([json_encode($now), $now, $now]);
        }
        // installed_at anchors the cron-staleness warning: fresh deployments
        // (< 24h since first migration) don't see the warning, giving the
        // operator a grace period to set the cron entry up.
        $installedRow = $pdo->query("SELECT value FROM config WHERE key = 'installed_at'")->fetchColumn();
        if ($installedRow === false) {
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES ('installed_at', ?, ?, ?)"
            );
            $stmt->execute([json_encode($now), $now, $now]);
        }

        // Free-trial seeding (deployment-time). Operator sets either
        // CASHUPAY_FREE_TRIAL_UNTIL (ISO 8601 date or unix seconds) and/or
        // CASHUPAY_FREE_TRIAL_REVENUE_SATS (integer sat cap). If both are set
        // the trial expires on whichever condition fires first (OR). Both
        // missing or both already-expired-at-seed-time → no trial. Seeded
        // once; immutable from the admin UI (matches deployment_id).
        $trialSeededRow = $pdo->query("SELECT value FROM config WHERE key = 'free_trial_seeded'")->fetchColumn();
        if ($trialSeededRow === false) {
            $now = time();
            $untilTs = self::parseFreeTrialUntilEnv(self::settingValue('CASHUPAY_FREE_TRIAL_UNTIL'));
            $capSats = self::parseFreeTrialCapEnv(self::settingValue('CASHUPAY_FREE_TRIAL_REVENUE_SATS'));

            // Already-past date or non-positive cap → behave as no trial.
            $untilActive = ($untilTs !== null && $untilTs > $now);
            $capActive = ($capSats !== null && $capSats > 0);

            if ($untilActive || $capActive) {
                $insert = $pdo->prepare(
                    "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)"
                );
                if ($untilActive) {
                    $insert->execute(['free_trial_until_ts', json_encode($untilTs), $now, $now]);
                }
                if ($capActive) {
                    $insert->execute(['free_trial_revenue_cap_sats', json_encode($capSats), $now, $now]);
                }
                $insert->execute(['free_trial_started_at', json_encode($now), $now, $now]);
            } elseif ($untilTs !== null || $capSats !== null) {
                error_log("CASHUPAY: free-trial env present but already-expired at seed time; treating as no trial");
            }

            // Mark seeded either way so we don't re-evaluate the env on every
            // subsequent migration run.
            $pdo->prepare(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES ('free_trial_seeded', ?, ?, ?)"
            )->execute([json_encode(true), $now, $now]);
        }

        // Email notifications: per-store opt-in + override "to" address.
        // Site-wide defaults live in the config table; tables below buffer
        // outbound mail (drained by cron) and dedupe identical auto-withdraw
        // failures within 48h (see includes/notification_sender.php).
        if (!self::columnExists($pdo, 'stores', 'notifications_enabled')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN notifications_enabled INTEGER NOT NULL DEFAULT 0");
        }
        if (!self::columnExists($pdo, 'stores', 'notification_email')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN notification_email TEXT DEFAULT NULL");
        }
        if (!self::tableExists($pdo, 'notification_queue')) {
            $pdo->exec("
                CREATE TABLE notification_queue (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id TEXT,
                    event_type TEXT NOT NULL,
                    to_email TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body TEXT NOT NULL,
                    dedupe_key TEXT,
                    created_at INTEGER NOT NULL,
                    sent_at INTEGER,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT
                );
            ");
            $pdo->exec("CREATE INDEX idx_notification_queue_pending ON notification_queue(sent_at) WHERE sent_at IS NULL;");
        }
        if (!self::tableExists($pdo, 'notification_log')) {
            $pdo->exec("
                CREATE TABLE notification_log (
                    store_id TEXT NOT NULL,
                    event_type TEXT NOT NULL,
                    dedupe_key TEXT NOT NULL,
                    sent_at INTEGER NOT NULL,
                    PRIMARY KEY (store_id, event_type, dedupe_key)
                );
            ");
        }

        // Submarine swaps (LN→on-chain via Boltz/Zeus). Replaces the cashu
        // mint in the LN invoice flow with a non-custodial swap that settles
        // directly to the merchant's xpub. Feature is off by default; site-
        // wide and per-store toggles control activation.
        if (!self::columnExists($pdo, 'stores', 'swaps_enabled')) {
            // Tri-state: -1 inherit site default, 0 force off, 1 force on.
            $pdo->exec("ALTER TABLE stores ADD COLUMN swaps_enabled INTEGER NOT NULL DEFAULT -1");
        }
        if (!self::columnExists($pdo, 'invoices', 'payment_rail')) {
            // 'mint' (cashu mint, existing default) / 'swap' (submarine swap) /
            // 'onchain' (pay-to-address only). Set once at invoice create time.
            $pdo->exec("ALTER TABLE invoices ADD COLUMN payment_rail TEXT NOT NULL DEFAULT 'mint'");
        }
        if (!self::tableExists($pdo, 'swap_attempts')) {
            $pdo->exec("
                CREATE TABLE swap_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_id TEXT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                    store_id TEXT NOT NULL,
                    provider TEXT NOT NULL,
                    network TEXT NOT NULL,
                    direction TEXT NOT NULL DEFAULT 'reverse',
                    swap_id_external TEXT NOT NULL,
                    status TEXT NOT NULL,
                    preimage_hex TEXT,
                    preimage_hash_hex TEXT NOT NULL,
                    claim_pubkey_hex TEXT NOT NULL,
                    claim_privkey_hex TEXT NOT NULL,
                    refund_pubkey_hex TEXT NOT NULL,
                    lockup_address TEXT NOT NULL,
                    lockup_txid TEXT,
                    lockup_vout INTEGER,
                    lockup_amount_sats INTEGER,
                    timeout_block_height INTEGER NOT NULL,
                    claim_leaf_script_hex TEXT NOT NULL,
                    refund_leaf_script_hex TEXT NOT NULL,
                    lightning_invoice TEXT NOT NULL,
                    target_onchain_amount_sats INTEGER NOT NULL,
                    invoice_amount_sats INTEGER NOT NULL,
                    swap_lockup_fee_sats INTEGER NOT NULL DEFAULT 0,
                    swap_percent_fee_sats INTEGER NOT NULL DEFAULT 0,
                    merchant_address TEXT NOT NULL,
                    merchant_address_index INTEGER NOT NULL,
                    claim_txid TEXT,
                    error_message TEXT,
                    last_polled_at INTEGER,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_swap_attempts_invoice')) {
            $pdo->exec("CREATE INDEX idx_swap_attempts_invoice ON swap_attempts(invoice_id);");
        }
        if (!self::indexExists($pdo, 'idx_swap_attempts_status')) {
            $pdo->exec("CREATE INDEX idx_swap_attempts_status ON swap_attempts(status, last_polled_at);");
        }
        if (!self::indexExists($pdo, 'idx_swap_attempts_store')) {
            $pdo->exec("CREATE INDEX idx_swap_attempts_store ON swap_attempts(store_id, created_at DESC);");
        }
        // Recovery aids: store raw hex/JSON snapshots so an operator can manually
        // claim a stuck lockup without needing access to the original Bitcoin
        // node or having to recompute anything. All three are populated lazily
        // — null on rows created before the migration ran.
        if (!self::columnExists($pdo, 'swap_attempts', 'lockup_tx_hex')) {
            $pdo->exec("ALTER TABLE swap_attempts ADD COLUMN lockup_tx_hex TEXT");
        }
        if (!self::columnExists($pdo, 'swap_attempts', 'claim_tx_hex')) {
            $pdo->exec("ALTER TABLE swap_attempts ADD COLUMN claim_tx_hex TEXT");
        }
        if (!self::columnExists($pdo, 'swap_attempts', 'provider_response_json')) {
            $pdo->exec("ALTER TABLE swap_attempts ADD COLUMN provider_response_json TEXT");
        }

        // Drop the legacy users.pin_hash column (PIN feature removed). Uses
        // the SQLite table-rebuild dance for compatibility with SQLite < 3.35.
        if (self::columnExists($pdo, 'users', 'pin_hash')) {
            $pdo->exec("
                BEGIN;
                CREATE TABLE users_new (
                    id              TEXT PRIMARY KEY,
                    username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash   TEXT NOT NULL,
                    role            TEXT NOT NULL CHECK (role IN ('admin','user')),
                    created_at      INTEGER NOT NULL
                );
                INSERT INTO users_new (id, username, password_hash, role, created_at)
                    SELECT id, username, password_hash, role, created_at FROM users;
                DROP TABLE users;
                ALTER TABLE users_new RENAME TO users;
                COMMIT;
            ");
        }
    }

    private static function columnExists(\PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }

    private static function tableExists(\PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private static function indexExists(\PDO $pdo, string $name): bool {
        $stmt = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?"
        );
        $stmt->execute([$name]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Read a deployment-time setting, preferring a PHP constant defined in
     * user_config.php over the env var of the same name. Returns the raw
     * string or `false` when neither source has a value (mirroring
     * getenv()'s convention so the parse helpers below stay drop-in).
     */
    private static function settingValue(string $name) {
        if (defined($name)) {
            $v = constant($name);
            if ($v === null) return false;
            return (string) $v;
        }
        return getenv($name);
    }

    /**
     * Parse CASHUPAY_FREE_TRIAL_UNTIL into a unix timestamp. Accepts an
     * integer-as-string (unix seconds) or any strtotime-parseable date.
     * Returns null when the env var is missing or unparseable.
     */
    private static function parseFreeTrialUntilEnv($raw): ?int {
        if ($raw === false || $raw === '' || $raw === null) return null;
        $raw = trim((string)$raw);
        if (ctype_digit($raw)) return (int)$raw;
        $ts = strtotime($raw);
        if ($ts === false) {
            error_log("CASHUPAY: CASHUPAY_FREE_TRIAL_UNTIL='{$raw}' is not a valid date; ignoring");
            return null;
        }
        return (int)$ts;
    }

    /**
     * Parse CASHUPAY_FREE_TRIAL_REVENUE_SATS into a positive integer sat cap.
     */
    private static function parseFreeTrialCapEnv($raw): ?int {
        if ($raw === false || $raw === '' || $raw === null) return null;
        $raw = trim((string)$raw);
        if (!ctype_digit($raw)) {
            error_log("CASHUPAY: CASHUPAY_FREE_TRIAL_REVENUE_SATS='{$raw}' is not a non-negative integer; ignoring");
            return null;
        }
        return (int)$raw;
    }

    /**
     * Generate a unique ID
     */
    public static function generateId(string $prefix = ''): string {
        $bytes = random_bytes(12);
        $id = bin2hex($bytes);
        return $prefix ? $prefix . '_' . $id : $id;
    }

    /**
     * Get current Unix timestamp
     */
    public static function timestamp(): int {
        return time();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }

    /**
     * Execute query with parameters
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Insert row and return ID
     */
    public static function insert(string $table, array $data): string|int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, array_values($data));

        return self::getInstance()->lastInsertId();
    }

    /**
     * Update rows
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * Delete rows
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
}
