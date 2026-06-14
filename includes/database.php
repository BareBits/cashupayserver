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
            $hasLatestMigration = $hasConfig && self::columnExists(self::$instance, 'stores', 'product_sort');
            // The auto-withdraw → auto-cashout rename is a data-only migration
            // (config key + notification event labels) with no schema artifact
            // to mark it done, so probe for the legacy config key directly. The
            // config table is keyed on `key`, so this is a cheap point lookup
            // that returns false once the migration has run.
            $needsCashoutRename = $hasConfig
                && self::$instance
                    ->query("SELECT 1 FROM config WHERE key = 'notifications_auto_withdraw_enabled' LIMIT 1")
                    ->fetch() !== false;

            if ($hasConfig && (!$hasUsers || !$hasReliability || !$hasLatestMigration || $needsCashoutRename)) {
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
            -- Auto-cashout settings (per-store). The ordered list of Lightning
            -- addresses (with priority/fallback) lives in store_ln_addresses;
            -- there is intentionally no auto_melt_address column here.
            auto_melt_enabled INTEGER NOT NULL DEFAULT 0,
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
            paid_at INTEGER DEFAULT NULL,
            settled_rail TEXT DEFAULT NULL,
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
        // Static-address mode: alternative to xpub for merchants without an
        // extended public key. When mode='static', onchain_static_address is
        // reused for every invoice and per-invoice tweaks (in sats) make each
        // expected total unique so incoming txs can be attributed.
        if (!self::columnExists($pdo, 'stores', 'onchain_address_mode')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_address_mode TEXT NOT NULL DEFAULT 'xpub'");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_static_address')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_static_address TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'stores', 'onchain_static_tweak_range')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN onchain_static_tweak_range INTEGER NOT NULL DEFAULT 1000");
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
        // Static-address mode bookkeeping. tweak_sats is the per-invoice sat
        // offset added to the base amount so each open invoice has a unique
        // total. needs_manual_confirmation + manual_candidates handle the case
        // where multiple invoices match the same incoming tx amount.
        if (!self::columnExists($pdo, 'invoices', 'onchain_amount_tweak_sats')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_amount_tweak_sats INTEGER DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'onchain_needs_manual_confirmation')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_needs_manual_confirmation INTEGER NOT NULL DEFAULT 0");
        }
        if (!self::columnExists($pdo, 'invoices', 'onchain_manual_candidates')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN onchain_manual_candidates TEXT DEFAULT NULL");
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

        // Fee-redirect feature: invoices whose entire payment is pointed at a
        // fee destination (dev / upstream / hosting) instead of the merchant.
        //   - invoices.fee_redirect_note: which fee this invoice settles
        //     (one of the FEE_NOTE_* tags) or NULL for a normal invoice.
        //   - invoices.fee_redirect_destination: the LNURL / xpub-derived
        //     address the funds were pointed at (audit + admin display).
        //   - melts.via: distinguishes a fee paid by redirect ('redirect',
        //     no cashu proofs spent) from one melted out of the wallet
        //     ('wallet'). computeOwed() counts both via the note column.
        //   - melts.invoice_id: links a redirect credit back to its invoice
        //     and is the idempotency key so settlement can't double-credit.
        if (!self::columnExists($pdo, 'invoices', 'fee_redirect_note')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN fee_redirect_note TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'fee_redirect_destination')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN fee_redirect_destination TEXT DEFAULT NULL");
        }
        //   - invoices.fee_redirect_rails: CSV of the logical customer rails
        //     ('lightning','onchain') that point at the fee payee on this
        //     invoice. A mixed invoice routes some rails to the fee and the
        //     rest to the merchant, so settlement attribution is decided by
        //     which rail actually paid (see Invoice::railIsFeeRouted). NULL on
        //     normal invoices; for a fee invoice it lists a subset (or all) of
        //     the offered rails.
        if (!self::columnExists($pdo, 'invoices', 'fee_redirect_rails')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN fee_redirect_rails TEXT DEFAULT NULL");
        }
        //   - invoices.ln_destination: the Lightning destination the bolt11 on
        //     this invoice was fetched from — the merchant's LN address for a
        //     normal lnaddress-rail payment, or the fee payee's LNURL when the
        //     lightning rail is fee-routed. NULL for mint/onchain/swap rails
        //     (the mint-rail bolt11 has no lnurl destination). Surfaced as the
        //     "Destination" in the admin invoices view; the bolt11 itself is
        //     shown as the lightning "TxID".
        if (!self::columnExists($pdo, 'invoices', 'ln_destination')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN ln_destination TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'melts', 'via')) {
            $pdo->exec("ALTER TABLE melts ADD COLUMN via TEXT NOT NULL DEFAULT 'wallet'");
        }
        if (!self::columnExists($pdo, 'melts', 'invoice_id')) {
            $pdo->exec("ALTER TABLE melts ADD COLUMN invoice_id TEXT DEFAULT NULL");
        }
        if (!self::indexExists($pdo, 'idx_melts_invoice')) {
            $pdo->exec("CREATE INDEX idx_melts_invoice ON melts(invoice_id) WHERE invoice_id IS NOT NULL;");
        }
        // At most one redirect credit per invoice — the idempotency backstop
        // for settlement (a dual-rail invoice can be observed paid by both the
        // lightning and on-chain pollers).
        if (!self::indexExists($pdo, 'idx_melts_redirect_once')) {
            $pdo->exec("CREATE UNIQUE INDEX idx_melts_redirect_once ON melts(invoice_id) WHERE via = 'redirect';");
        }

        // Per-store on-chain destination for the hosting fee. The dev and
        // upstream fee on-chain xpubs are global config (see dev_fee.php);
        // hosting is per-store because each deployer's hosting payout differs.
        // Written directly via Database::update (NOT the Config::updateStore
        // allowlist) and intentionally absent from the settings UI.
        if (!self::columnExists($pdo, 'stores', 'hosting_fee_onchain_xpub')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN hosting_fee_onchain_xpub TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'stores', 'hosting_fee_onchain_network')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN hosting_fee_onchain_network TEXT NOT NULL DEFAULT 'mainnet'");
        }
        if (!self::columnExists($pdo, 'stores', 'hosting_fee_onchain_address_type')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN hosting_fee_onchain_address_type TEXT NOT NULL DEFAULT 'P2WPKH'");
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

        // Seed cron_key on first migration so cron.php is never reachable with
        // an unset key. Without this, the admin had to load the "Cron URL" page
        // to lazily generate one; before that, cron.php's auth check fell
        // through (any caller could trigger the full cron pipeline).
        $cronKeyRow = $pdo->query("SELECT value FROM config WHERE key = 'cron_key'")->fetchColumn();
        if ($cronKeyRow === false) {
            $now = time();
            $stmt = $pdo->prepare(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES ('cron_key', ?, ?, ?)"
            );
            $stmt->execute([bin2hex(random_bytes(32)), $now, $now]);
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
        // outbound mail (drained by cron) and dedupe identical auto-cashout
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
        // Payer-receipt feature needs a way to enforce a per-invoice send cap.
        // Adding invoice_id to the queue row is the simplest path — sent rows
        // stay around (sent_at IS NOT NULL), so a COUNT over them and any
        // still-pending rows tells us how many receipts we've accepted for a
        // given invoice. Nullable because pre-existing rows have no value.
        if (!self::columnExists($pdo, 'notification_queue', 'invoice_id')) {
            $pdo->exec("ALTER TABLE notification_queue ADD COLUMN invoice_id TEXT DEFAULT NULL");
        }
        if (!self::indexExists($pdo, 'idx_notification_queue_invoice')) {
            $pdo->exec("CREATE INDEX idx_notification_queue_invoice ON notification_queue(invoice_id) WHERE invoice_id IS NOT NULL");
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
        // Settlement metadata for the admin invoices view: when the invoice
        // was paid and which rail actually moved the funds (vs the rail that
        // was offered at creation, which payment_rail records). Nullable
        // because legacy Settled rows have no timestamp to backfill.
        if (!self::columnExists($pdo, 'invoices', 'paid_at')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN paid_at INTEGER DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'settled_rail')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN settled_rail TEXT DEFAULT NULL");
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
        // Auto-select-cheapest audit trail: JSON snapshot of every quote
        // fetched at invoice creation, the threshold in force, and the
        // chosen provider. Null for rows created before the feature
        // landed or when the feature was off.
        if (!self::columnExists($pdo, 'swap_attempts', 'quotes_compared_json')) {
            $pdo->exec("ALTER TABLE swap_attempts ADD COLUMN quotes_compared_json TEXT");
        }

        // Auto-melt via submarine swap: a per-store opt-in that replaces
        // Lightning-address auto-melt with an on-chain sweep over the
        // existing reverse-swap infrastructure. Tri-state matches the
        // swaps_enabled override convention: -1 inherit site default,
        // 0 force lightning, 1 force swap.
        if (!self::columnExists($pdo, 'stores', 'auto_melt_use_swap')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN auto_melt_use_swap INTEGER NOT NULL DEFAULT -1");
        }

        // Sweep-origin swaps. Mirrors swap_attempts but tied to a store
        // balance rather than a customer invoice, so polling/claim can run
        // on the same machinery without polluting the invoice table.
        if (!self::tableExists($pdo, 'sweep_attempts')) {
            $pdo->exec("
                CREATE TABLE sweep_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
                    lockup_tx_hex TEXT,
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
                    claim_tx_hex TEXT,
                    melt_preimage TEXT,
                    balance_sats_at_create INTEGER NOT NULL,
                    quote_total_cost_sats INTEGER NOT NULL,
                    provider_response_json TEXT,
                    quotes_compared_json TEXT,
                    error_message TEXT,
                    last_polled_at INTEGER,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_sweep_attempts_status')) {
            $pdo->exec("CREATE INDEX idx_sweep_attempts_status ON sweep_attempts(status, last_polled_at);");
        }
        if (!self::indexExists($pdo, 'idx_sweep_attempts_store')) {
            $pdo->exec("CREATE INDEX idx_sweep_attempts_store ON sweep_attempts(store_id, created_at DESC);");
        }

        // Rolling 30-day quote-history backing the per-store auto-melt-via-swap
        // gate. The gate stops requesting fresh quotes once we have a few
        // recent ones that don't satisfy the percent threshold, so a high-fee
        // environment doesn't hammer providers for quotes that can never
        // settle. One row per (store, provider, fetch).
        if (!self::tableExists($pdo, 'swap_quote_history')) {
            $pdo->exec("
                CREATE TABLE swap_quote_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id TEXT NOT NULL,
                    provider TEXT NOT NULL,
                    network TEXT NOT NULL,
                    fetched_at INTEGER NOT NULL,
                    fee_percent REAL NOT NULL,
                    lockup_fee_sats INTEGER NOT NULL,
                    claim_fee_estimate_sats INTEGER NOT NULL,
                    min_sats INTEGER NOT NULL,
                    max_sats INTEGER NOT NULL,
                    balance_sats_at_fetch INTEGER NOT NULL,
                    total_cost_sats_at_fetch INTEGER NOT NULL,
                    met_threshold INTEGER NOT NULL DEFAULT 0,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_swap_quote_history_store_time')) {
            $pdo->exec("CREATE INDEX idx_swap_quote_history_store_time ON swap_quote_history(store_id, fetched_at DESC);");
        }

        // LNURL direct-receive: route incoming LN payments straight to the
        // auto-cashout LN address when the host supports LUD-21 verify URLs,
        // bypassing both the cashu mint and submarine swap. lnurl_verify_url
        // is what we poll to detect settlement; lnurl_preimage is the
        // cryptographic proof the verify URL returned on settled=true.
        if (!self::columnExists($pdo, 'invoices', 'lnurl_verify_url')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN lnurl_verify_url TEXT DEFAULT NULL");
        }
        if (!self::columnExists($pdo, 'invoices', 'lnurl_preimage')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN lnurl_preimage TEXT DEFAULT NULL");
        }
        // Set on mint-rail invoices that landed on the mint rail BECAUSE the
        // override gate fired (fees-due exceeded threshold). On settlement,
        // these trigger immediate DevFee::settleStore + auto-melt so that
        // accumulated owed fees clear without waiting for cron.
        if (!self::columnExists($pdo, 'invoices', 'lnurl_override_reason')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN lnurl_override_reason TEXT DEFAULT NULL");
        }
        // Cached at save-time admin probe of the LN-address host's LUD-21
        // support. NULL = unknown / not probed, 0 = no verify URL field
        // (warn the operator), 1 = verify URL field present. Runtime decisions
        // re-probe anyway since host config can drift.
        if (!self::columnExists($pdo, 'stores', 'lnurl_supports_verify')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN lnurl_supports_verify INTEGER DEFAULT NULL");
        }
        if (!self::indexExists($pdo, 'idx_invoices_lnaddress_pending')) {
            $pdo->exec(
                "CREATE INDEX idx_invoices_lnaddress_pending ON invoices(status, payment_rail)"
            );
        }

        // Ordered, multi-address Lightning-address fallback. Replaces the single
        // stores.auto_melt_address column: a merchant can list several addresses
        // tried in priority order (position ASC) for both receiving (LNURL
        // direct-receive invoice presentation) and withdrawing (auto-melt). The
        // per-address supports_verify mirrors the old stores.lnurl_supports_verify
        // cache (NULL=unknown, 0=no LUD-21 verify URL, 1=present).
        if (!self::tableExists($pdo, 'store_ln_addresses')) {
            $pdo->exec("
                CREATE TABLE store_ln_addresses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id TEXT NOT NULL,
                    position INTEGER NOT NULL,
                    address TEXT NOT NULL,
                    supports_verify INTEGER DEFAULT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
        }
        if (!self::indexExists($pdo, 'idx_store_ln_addresses_pos')) {
            $pdo->exec("CREATE UNIQUE INDEX idx_store_ln_addresses_pos ON store_ln_addresses(store_id, position);");
        }
        if (!self::indexExists($pdo, 'idx_store_ln_addresses_addr')) {
            $pdo->exec("CREATE UNIQUE INDEX idx_store_ln_addresses_addr ON store_ln_addresses(store_id, address);");
        }
        // Backfill the existing single address into position 0 before the
        // legacy columns are dropped below. Guarded on columnExists so it only
        // runs on databases upgrading from the single-address schema, and skips
        // stores that already have rows (idempotent re-runs).
        if (self::columnExists($pdo, 'stores', 'auto_melt_address')) {
            $hasVerifyCol = self::columnExists($pdo, 'stores', 'lnurl_supports_verify');
            $verifySelect = $hasVerifyCol ? 'lnurl_supports_verify' : 'NULL AS lnurl_supports_verify';
            $rows = $pdo->query(
                "SELECT id, auto_melt_address, {$verifySelect}
                   FROM stores
                  WHERE auto_melt_address IS NOT NULL AND auto_melt_address != ''"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $ins = $pdo->prepare(
                "INSERT OR IGNORE INTO store_ln_addresses (store_id, position, address, supports_verify)
                 VALUES (?, 0, ?, ?)"
            );
            foreach ($rows as $row) {
                $verify = $row['lnurl_supports_verify'];
                $ins->execute([
                    $row['id'],
                    $row['auto_melt_address'],
                    $verify === null ? null : (int)$verify,
                ]);
            }
        }
        // Drop the now-superseded single-address columns. SQLite 3.35+ supports
        // ALTER TABLE DROP COLUMN; on older engines this throws — we tolerate
        // that (the columns simply linger unused, since no code reads them).
        foreach (['auto_melt_address', 'lnurl_supports_verify'] as $deadCol) {
            if (self::columnExists($pdo, 'stores', $deadCol)) {
                try {
                    $pdo->exec("ALTER TABLE stores DROP COLUMN {$deadCol}");
                } catch (\Throwable $e) {
                    error_log("[migration] could not drop stores.{$deadCol} (old SQLite?); leaving unused: " . $e->getMessage());
                }
            }
        }

        // Offline Cashu acceptance (NUT-12 DLEQ). Per-store opt-in + risk
        // controls, the 'cashu' invoice rail, and the Provisional reconcile
        // bookkeeping. See includes/offline_cashu.php.
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_enabled')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_enabled INTEGER NOT NULL DEFAULT 0");
        }
        // Policy floor: 'dleq' = DLEQ-verified + trusted-mint allowlist + caps
        // (active). 'p2pk' = additionally require P2PK-locked tokens (NUT-11,
        // not yet implemented; stored but treated as unavailable in the UI).
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_policy')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_policy TEXT NOT NULL DEFAULT 'dleq'");
        }
        // Per-transaction cap (mint smallest unit). 0 = no per-tx cap.
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_max_per_tx')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_max_per_tx INTEGER NOT NULL DEFAULT 0");
        }
        // Aggregate outstanding (un-reconciled) exposure cap. 0 = no cap.
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_max_outstanding')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_max_outstanding INTEGER NOT NULL DEFAULT 0");
        }
        // Accept offline tokens from ANY mint (bypass the allowlist). Still
        // bounded by what can be DLEQ-verified — i.e. mints whose keys are
        // cached or that are reachable to fetch keys at accept time.
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_accept_all_mints')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_accept_all_mints INTEGER NOT NULL DEFAULT 0");
        }
        // Allow a per-transaction "accept from any mint" override to be set at
        // invoice-creation time (the request UI shows the checkbox only when
        // this is on). The override itself is recorded per-invoice below.
        if (!self::columnExists($pdo, 'stores', 'offline_cashu_per_tx_override')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN offline_cashu_per_tx_override INTEGER NOT NULL DEFAULT 0");
        }
        // Per-invoice flag: accept this specific payment offline from any mint,
        // bypassing the allowlist (only honored when the store enables the
        // per-transaction override above).
        if (!self::columnExists($pdo, 'invoices', 'cashu_offline_allow_any_mint')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN cashu_offline_allow_any_mint INTEGER NOT NULL DEFAULT 0");
        }
        // The raw cashu token captured for a Provisional invoice, swapped at the
        // mint on reconnect to settle it.
        if (!self::columnExists($pdo, 'invoices', 'cashu_offline_token')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN cashu_offline_token TEXT DEFAULT NULL");
        }
        // Reason an offline-accepted invoice failed reconciliation (e.g. double-spent).
        if (!self::columnExists($pdo, 'invoices', 'cashu_offline_fail_reason')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN cashu_offline_fail_reason TEXT DEFAULT NULL");
        }

        // Per-store allowlist of mints whose tokens may be accepted offline.
        // Intentionally distinct from store_mints (failover for issuing): a
        // merchant can hold offline acceptance to a higher trust bar.
        if (!self::tableExists($pdo, 'store_offline_mints')) {
            $pdo->exec("
                CREATE TABLE store_offline_mints (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id TEXT NOT NULL,
                    mint_url TEXT NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
                    UNIQUE(store_id, mint_url)
                );
            ");
        }

        // Replay guard + exposure ledger for offline-accepted proofs. The Y
        // value (hash_to_curve of the secret) uniquely identifies a proof; the
        // PRIMARY KEY makes re-presenting the same proof offline a no-op.
        if (!self::tableExists($pdo, 'cashu_offline_locks')) {
            $pdo->exec("
                CREATE TABLE cashu_offline_locks (
                    y TEXT PRIMARY KEY,
                    invoice_id TEXT NOT NULL,
                    store_id TEXT NOT NULL,
                    amount INTEGER NOT NULL,
                    created_at INTEGER NOT NULL
                );
            ");
            $pdo->exec("CREATE INDEX idx_offline_locks_invoice ON cashu_offline_locks(invoice_id);");
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

        // ---- Product catalog + shopping cart ----
        // Products are per-store. Price is stored as a decimal string in the
        // product's own `currency` (a snapshot of the store's display currency
        // at creation: 'sat' or a fiat code). Mixed-currency products in one
        // store/cart are fine — everything converts to sats at checkout.
        if (!self::tableExists($pdo, 'products')) {
            $pdo->exec("
                CREATE TABLE products (
                    id TEXT PRIMARY KEY,
                    store_id TEXT NOT NULL,
                    title TEXT NOT NULL,
                    price TEXT NOT NULL,
                    currency TEXT NOT NULL,
                    image_type TEXT NOT NULL DEFAULT 'none',  -- 'none' | 'emoji' | 'upload'
                    image_value TEXT,                         -- emoji grapheme or uploaded filename
                    purchase_count INTEGER NOT NULL DEFAULT 0,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
                );
            ");
            $pdo->exec("CREATE INDEX idx_products_store ON products(store_id);");
        }

        // Per-invoice cart line items. product_id is nullable: custom line
        // items have none, and a deleted product nulls it (SET NULL) while the
        // snapshot title/price survive for the receipt. amount_sats is the
        // line total in sats at checkout; display_amount/currency snapshot the
        // store-currency equivalent shown in parentheses on the checkout page.
        if (!self::tableExists($pdo, 'invoice_items')) {
            $pdo->exec("
                CREATE TABLE invoice_items (
                    id TEXT PRIMARY KEY,
                    invoice_id TEXT NOT NULL,
                    store_id TEXT NOT NULL,
                    product_id TEXT,
                    title TEXT NOT NULL,
                    unit_price TEXT NOT NULL,
                    unit_currency TEXT NOT NULL,
                    quantity INTEGER NOT NULL,
                    amount_sats INTEGER NOT NULL,
                    display_amount TEXT,
                    display_currency TEXT,
                    image_type TEXT,
                    image_value TEXT,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
                );
            ");
            $pdo->exec("CREATE INDEX idx_invoice_items_invoice ON invoice_items(invoice_id);");
            $pdo->exec("CREATE INDEX idx_invoice_items_product ON invoice_items(product_id);");
        }

        // Per-store default sort for the product catalog in the request modal.
        if (!self::columnExists($pdo, 'stores', 'product_sort')) {
            $pdo->exec("ALTER TABLE stores ADD COLUMN product_sort TEXT NOT NULL DEFAULT 'most_purchased'");
        }

        // Idempotency flag for the settle-time purchase-count reconciliation
        // (see Cart::reconcileSettledCounts). Settlement happens on several
        // rails that don't share a choke-point, so we reconcile from cron and
        // mark each cart invoice counted exactly once here.
        if (!self::columnExists($pdo, 'invoices', 'cart_purchase_counted')) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN cart_purchase_counted INTEGER NOT NULL DEFAULT 0");
        }

        // "Auto-withdraw" was renamed to "auto-cashout" (UI + persisted state).
        // Carry forward the notifications toggle so existing installs keep their
        // setting, and re-label any in-flight notification rows so pending emails
        // still send and the 48h failure-dedupe window survives the rename.
        // Idempotent: each step is a no-op once the legacy key/rows are gone.
        $legacyToggle = 'notifications_auto_withdraw_enabled';
        $newToggle = 'notifications_auto_cashout_enabled';
        $legacyRow = $pdo->prepare("SELECT value, created_at, updated_at FROM config WHERE key = ?");
        $legacyRow->execute([$legacyToggle]);
        $legacy = $legacyRow->fetch(\PDO::FETCH_ASSOC);
        if ($legacy !== false) {
            // Don't clobber a value written under the new key post-upgrade.
            $hasNew = $pdo->prepare("SELECT 1 FROM config WHERE key = ?");
            $hasNew->execute([$newToggle]);
            if ($hasNew->fetchColumn() === false) {
                $pdo->prepare(
                    "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)"
                )->execute([$newToggle, $legacy['value'], $legacy['created_at'], $legacy['updated_at']]);
            }
            $pdo->prepare("DELETE FROM config WHERE key = ?")->execute([$legacyToggle]);
        }

        $eventRenames = [
            'AutoWithdrawSuccess' => 'AutoCashoutSuccess',
            'AutoWithdrawFailure' => 'AutoCashoutFailure',
        ];
        foreach (['notification_queue', 'notification_log'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            $stmt = $pdo->prepare("UPDATE {$table} SET event_type = ? WHERE event_type = ?");
            foreach ($eventRenames as $oldEvent => $newEvent) {
                $stmt->execute([$newEvent, $oldEvent]);
            }
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
