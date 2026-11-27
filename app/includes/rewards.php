<?php
/**
 * Cash back rewards — the per-store, opt-in loyalty ledger.
 *
 * A store that enables rewards credits a flat percentage of every settled
 * invoice (tip jars excluded) to a rewards ACCOUNT the payer names with a
 * username of their choosing (letters and digits, no password — usability over
 * security, by design). The payer can later apply that balance to any New
 * invoice of the same store, which lowers what is owed; a balance that covers
 * the whole invoice settles it outright on the 'rewards' rail.
 *
 * Money model
 *   Balances are kept in the store's REWARDS CURRENCY (stores.rewards_currency:
 *   the store's default currency when the feature was first enabled — 'sat' or
 *   a fiat code) as integer MICRO-UNITS: 1 unit = 1,000,000 micro, so 1 sat =
 *   1,000,000 micro on a sat store and $0.005 is 5,000 micro. Fractions of a
 *   cent / sat therefore carry over exactly between invoices; only display and
 *   spending round (display: 2 decimals for fiat, whole sats for sat; spending:
 *   whole units of the invoice's currency).
 *
 *   Each account holds balance_micro (spendable now) and reserved_micro (held
 *   by open invoices the balance was applied to). Applying rewards moves micro
 *   from balance to reserved ('reserve'); settlement moves it from reserved to
 *   the lifetime spent counter ('commit'); an invoice that expires or is voided
 *   hands it back ('release'). A settlement credits the earn ('earn'); a
 *   zero-conf settlement later reversed to Invalid takes it back again
 *   ('reverse', floored at zero). Every movement is a rewards_ledger row keyed
 *   UNIQUE on (invoice_id, kind), which is what makes each of those steps
 *   happen at most once per invoice no matter how many pollers race.
 *
 * Earn basis (operator decision): the percentage applies to what the payer
 * actually paid, i.e. the invoice amount net of any rewards applied — never to
 * the part covered by rewards. Currency scope: per store; accounts are never
 * shared between stores.
 *
 * Auth is enforced by the callers (admin.php / payment.php), not here. The
 * invoice-side orchestration (apply / settle / earn / release) lives in
 * RewardsFlow (includes/rewards_flow.php) and uses the primitives below.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';

class Rewards {
    /** Micro-units per whole unit of the rewards currency. */
    public const MICRO = 1000000;
    /** Operator-set bounds for the percentage (inclusive). */
    public const MIN_PERCENT = 0.0001;
    public const MAX_PERCENT = 50.0;
    /** Decimal places accepted on the percentage. */
    public const PERCENT_DECIMALS = 4;
    /** Username rules: trimmed, letters and digits only, matched case-insensitively. */
    public const USERNAME_MIN = 3;
    public const USERNAME_MAX = 64;
    public const USERNAME_PATTERN = '/^[A-Za-z0-9]+$/';
    /** How long after settlement an unclaimed earn can still be claimed. */
    public const CLAIM_WINDOW_SEC = 86400;
    /** The settled_rail of an invoice fully covered by rewards. */
    public const RAIL = 'rewards';

    public const KIND_EARN = 'earn';
    public const KIND_RESERVE = 'reserve';
    public const KIND_COMMIT = 'commit';
    public const KIND_RELEASE = 'release';
    public const KIND_REVERSE = 'reverse';
    public const KIND_CONVERT = 'convert';

    public const SPEND_RESERVED = 'reserved';
    public const SPEND_COMMITTED = 'committed';
    public const SPEND_RELEASED = 'released';

    // ---------------- Store settings ----------------

    /**
     * Rewards settings from a stores row. Tolerates rows read before the
     * migration ran (missing columns = feature off).
     *
     * @return array{enabled:bool, percent:float, currency:?string}
     */
    public static function settingsFromRow(?array $store): array {
        $enabled = !empty($store['rewards_enabled']);
        $percent = isset($store['rewards_percent']) ? (float)$store['rewards_percent'] : 0.0;
        $currency = isset($store['rewards_currency']) && $store['rewards_currency'] !== ''
            ? self::normalizeCurrency((string)$store['rewards_currency']) : null;
        return ['enabled' => $enabled, 'percent' => $percent, 'currency' => $currency];
    }

    /** @return array{enabled:bool, percent:float, currency:?string} */
    public static function forStore(string $storeId): array {
        return self::settingsFromRow(Config::getStore($storeId));
    }

    /** Feature live for this store: switched on with a positive percentage and a currency. */
    public static function isEnabledForStore(string $storeId): bool {
        return self::isEnabled(Config::getStore($storeId));
    }

    public static function isEnabled(?array $store): bool {
        $s = self::settingsFromRow($store);
        return $s['enabled'] && $s['percent'] > 0 && $s['currency'] !== null;
    }

    /**
     * Parse the operator's percentage input. Floats are accepted ("1", "2.5",
     * "0.25"), at most PERCENT_DECIMALS decimals, within (0, MAX_PERCENT].
     * Throws InvalidArgumentException with an operator-facing message.
     */
    public static function parsePercent($raw): float {
        $txt = trim((string)$raw);
        if ($txt === '' || !is_numeric($txt) || stripos($txt, 'e') !== false) {
            throw new InvalidArgumentException('Rewards percentage must be a number');
        }
        if (!preg_match('/^\d+(\.\d{1,' . self::PERCENT_DECIMALS . '})?$/', $txt)) {
            throw new InvalidArgumentException(
                'Rewards percentage may have at most ' . self::PERCENT_DECIMALS . ' decimal places'
            );
        }
        $pct = (float)$txt;
        if ($pct < self::MIN_PERCENT || $pct > self::MAX_PERCENT) {
            throw new InvalidArgumentException(
                'Rewards percentage must be between ' . self::MIN_PERCENT . ' and ' . self::MAX_PERCENT
            );
        }
        return $pct;
    }

    /**
     * Persist the per-store settings. Direct UPDATE on purpose — the
     * Config::updateStore allowlist stays tight. Enabling for the first time
     * pins the rewards currency to the store's current default currency; it
     * is never changed here (see convertStoreCurrency / keepCurrency for the
     * default-currency switch).
     *
     * @param bool       $enabled
     * @param float|null $percent  required when enabling; when disabling a
     *                             null leaves the stored percentage as it is
     *                             so re-enabling remembers it
     */
    public static function setStoreSettings(string $storeId, bool $enabled, ?float $percent): void {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new InvalidArgumentException('Store not found');
        }
        if ($enabled && ($percent === null || $percent <= 0)) {
            throw new InvalidArgumentException('Set a rewards percentage to enable rewards');
        }
        if ($percent !== null && ($percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT)) {
            throw new InvalidArgumentException(
                'Rewards percentage must be between ' . self::MIN_PERCENT . ' and ' . self::MAX_PERCENT
            );
        }
        $updates = ['rewards_enabled' => $enabled ? 1 : 0];
        if ($percent !== null) {
            $updates['rewards_percent'] = $percent;
        }
        $current = self::settingsFromRow($store);
        if ($enabled && $current['currency'] === null) {
            $updates['rewards_currency'] = self::normalizeCurrency(Config::getStoreDefaultCurrency($storeId));
        }
        Database::update('stores', $updates, 'id = ?', [$storeId]);
    }

    /** 'sat' for sats, upper-cased code otherwise. */
    public static function normalizeCurrency(string $currency): string {
        $u = strtoupper(trim($currency));
        return ($u === 'SAT' || $u === 'SATS') ? 'sat' : $u;
    }

    public static function isSatCurrency(string $currency): bool {
        return self::normalizeCurrency($currency) === 'sat';
    }

    // ---------------- Usernames ----------------

    /**
     * Trim and validate a payer-chosen username. Letters and digits only,
     * USERNAME_MIN..USERNAME_MAX characters; the match key is lower-cased so
     * "Alice" and "alice" are the same account. Throws InvalidArgumentException
     * with a payer-facing message.
     *
     * @return array{display:string, key:string}
     */
    public static function normalizeUsername($raw): array {
        $name = trim((string)$raw);
        $len = strlen($name);
        if ($len < self::USERNAME_MIN) {
            throw new InvalidArgumentException('Username must be at least ' . self::USERNAME_MIN . ' characters');
        }
        if ($len > self::USERNAME_MAX) {
            throw new InvalidArgumentException('Username must be at most ' . self::USERNAME_MAX . ' characters');
        }
        if (!preg_match(self::USERNAME_PATTERN, $name)) {
            throw new InvalidArgumentException('Username may only contain letters and numbers');
        }
        return ['display' => $name, 'key' => strtolower($name)];
    }

    // ---------------- Money helpers ----------------

    /** Non-scientific decimal spelling for bcmath. */
    private static function dec(float $v, int $scale = 12): string {
        return number_format($v, $scale, '.', '');
    }

    /**
     * Decimal string in whole currency units -> micro-units (floor). Accepts
     * "12.5", "0.005", "100". Negative or malformed input throws.
     */
    public static function toMicro(string $amount): int {
        $txt = trim($amount);
        if ($txt === '' || !preg_match('/^\d+(\.\d+)?$/', $txt)) {
            throw new InvalidArgumentException('Invalid amount');
        }
        return (int)bcmul($txt, (string)self::MICRO, 0);
    }

    /**
     * Micro-units -> decimal string in whole units with the currency's
     * natural precision: whole sats on a sat store (floor), 2 decimals on a
     * fiat one (floor). Used for display and for the amount actually spent.
     */
    public static function fromMicro(int $micro, string $currency): string {
        $micro = max(0, $micro);
        if (self::isSatCurrency($currency)) {
            return bcdiv((string)$micro, (string)self::MICRO, 0);
        }
        return bcdiv((string)$micro, (string)self::MICRO, 2);
    }

    /** Exact micro -> decimal (6 places), for bookkeeping / tests. */
    public static function fromMicroExact(int $micro): string {
        return bcdiv((string)$micro, (string)self::MICRO, 6);
    }

    /** "12 sats" / "1.25 USD" for payer- and operator-facing copy. */
    public static function formatMicro(int $micro, string $currency): string {
        $cur = self::normalizeCurrency($currency);
        if ($cur === 'sat') {
            $n = (int)self::fromMicro($micro, 'sat');
            return number_format($n) . ' ' . ($n === 1 ? 'sat' : 'sats');
        }
        return self::fromMicro($micro, $cur) . ' ' . $cur;
    }

    /**
     * Micro-units of $currency -> sats at the current rate (floor). Null when
     * no rate is available. 1:1 (scaled) on a sat currency.
     */
    public static function microToSats(int $micro, string $currency, ?array $store = null): ?int {
        $cur = self::normalizeCurrency($currency);
        if ($micro <= 0) {
            return 0;
        }
        if ($cur === 'sat') {
            return (int)bcdiv((string)$micro, (string)self::MICRO, 0);
        }
        $price = self::btcPrice($cur, $store);
        if ($price === null) {
            return null;
        }
        // sats = fiat / price * 1e8 ; fiat = micro / 1e6  =>  sats = micro * 100 / price
        return (int)bcdiv(bcmul((string)$micro, '100', 0), self::dec($price), 0);
    }

    /**
     * Sats -> micro-units of $currency at the current rate (floor). Null when
     * no rate is available.
     */
    public static function satsToMicro(int $sats, string $currency, ?array $store = null): ?int {
        $cur = self::normalizeCurrency($currency);
        if ($sats <= 0) {
            return 0;
        }
        if ($cur === 'sat') {
            return $sats * self::MICRO;
        }
        $price = self::btcPrice($cur, $store);
        if ($price === null) {
            return null;
        }
        // micro = sats * price / 100 (see microToSats)
        return (int)bcdiv(bcmul((string)$sats, self::dec($price), 8), '100', 0);
    }

    /**
     * Sats -> micro-units of $currency at a KNOWN price (fiat per BTC), e.g.
     * the invoice's own exchange_rate at settlement. Null price = current rate.
     */
    public static function satsToMicroAtRate(int $sats, string $currency, ?float $price, ?array $store = null): ?int {
        if ($price === null || $price <= 0 || self::isSatCurrency($currency)) {
            return self::satsToMicro($sats, $currency, $store);
        }
        if ($sats <= 0) {
            return 0;
        }
        return (int)bcdiv(bcmul((string)$sats, self::dec($price), 8), '100', 0);
    }

    /**
     * Micro-units in one currency -> micro-units in another at current rates,
     * via sats (floor). Null when a rate is missing.
     */
    public static function convertMicro(int $micro, string $from, string $to, ?array $store = null): ?int {
        $from = self::normalizeCurrency($from);
        $to = self::normalizeCurrency($to);
        if ($from === $to) {
            return $micro;
        }
        if ($micro <= 0) {
            return 0;
        }
        if ($from === 'sat') {
            return self::satsToMicro((int)bcdiv((string)$micro, (string)self::MICRO, 0), $to, $store);
        }
        $pFrom = self::btcPrice($from, $store);
        if ($pFrom === null) {
            return null;
        }
        if ($to === 'sat') {
            return self::microToSats($micro, $from, $store) === null
                ? null : self::microToSats($micro, $from, $store) * self::MICRO;
        }
        $pTo = self::btcPrice($to, $store);
        if ($pTo === null) {
            return null;
        }
        // micro_to = micro_from * pTo / pFrom
        return (int)bcdiv(bcmul((string)$micro, self::dec($pTo), 8), self::dec($pFrom), 0);
    }

    /**
     * Micro-units of the rewards currency expressed in whole units of an
     * invoice's currency (floor, natural precision), e.g. for the discount
     * line. Null when a rate is missing.
     */
    public static function microToAmount(int $micro, string $rewardsCurrency, string $invoiceCurrency, ?array $store = null): ?string {
        $m = self::convertMicro($micro, $rewardsCurrency, $invoiceCurrency, $store);
        if ($m === null) {
            return null;
        }
        return self::fromMicro($m, $invoiceCurrency);
    }

    private static function btcPrice(string $currency, ?array $store): ?float {
        try {
            $p = ExchangeRates::getBtcPrice(
                $currency,
                $store['price_provider_primary'] ?? null,
                $store['price_provider_secondary'] ?? null
            );
        } catch (Throwable $e) {
            error_log('[rewards] rate lookup failed for ' . $currency . ': ' . $e->getMessage());
            return null;
        }
        return ($p !== null && $p > 0) ? $p : null;
    }

    /**
     * The earn for a settlement: percent of the NET amount paid, in micro of
     * the rewards currency (floor). $netMicro is what the payer actually paid
     * expressed in the rewards currency.
     */
    public static function earnMicro(int $netMicro, float $percent): int {
        if ($netMicro <= 0 || $percent <= 0) {
            return 0;
        }
        return (int)bcdiv(bcmul((string)$netMicro, self::dec($percent, 6), 6), '100', 0);
    }

    // ---------------- Accounts ----------------

    public static function getAccount(string $storeId, string $username): ?array {
        $n = self::normalizeUsername($username);
        return Database::fetchOne(
            "SELECT * FROM rewards_accounts WHERE store_id = ? AND username_key = ?",
            [$storeId, $n['key']]
        );
    }

    public static function getAccountById(string $accountId): ?array {
        return Database::fetchOne("SELECT * FROM rewards_accounts WHERE id = ?", [$accountId]);
    }

    /**
     * Find or create the account for a username. The INSERT races a concurrent
     * creator on the UNIQUE (store_id, username_key) index; the loser re-reads.
     */
    public static function getOrCreateAccount(string $storeId, string $username): array {
        $n = self::normalizeUsername($username);
        $existing = Database::fetchOne(
            "SELECT * FROM rewards_accounts WHERE store_id = ? AND username_key = ?",
            [$storeId, $n['key']]
        );
        if ($existing) {
            return $existing;
        }
        $now = time();
        try {
            Database::insert('rewards_accounts', [
                'id' => Database::generateId('rwa'),
                'store_id' => $storeId,
                'username' => $n['display'],
                'username_key' => $n['key'],
                'balance_micro' => 0,
                'reserved_micro' => 0,
                'earned_micro' => 0,
                'spent_micro' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'last_activity_at' => $now,
            ]);
        } catch (Throwable $e) {
            // Lost the race to a concurrent creator: the row exists now.
            $again = Database::fetchOne(
                "SELECT * FROM rewards_accounts WHERE store_id = ? AND username_key = ?",
                [$storeId, $n['key']]
            );
            if ($again) {
                return $again;
            }
            throw $e;
        }
        return Database::fetchOne(
            "SELECT * FROM rewards_accounts WHERE store_id = ? AND username_key = ?",
            [$storeId, $n['key']]
        );
    }

    /**
     * Accounts for the admin list: one store or every store ($storeId null),
     * optional username search (prefix / substring, case-insensitive), newest
     * activity first. Each row carries the store's name and rewards currency.
     */
    public static function listAccounts(string|array|null $storeId, string $search = '', int $limit = 100, int $offset = 0): array {
        [$where, $params] = self::listWhere($storeId, $search);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        return Database::fetchAll(
            "SELECT a.*, s.name AS store_name, s.rewards_currency AS currency
               FROM rewards_accounts a JOIN stores s ON s.id = a.store_id
              WHERE {$where}
              ORDER BY a.last_activity_at DESC, a.username_key ASC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function countAccounts(string|array|null $storeId, string $search = ''): int {
        [$where, $params] = self::listWhere($storeId, $search);
        return (int)Database::fetchOne(
            "SELECT COUNT(*) AS n FROM rewards_accounts a JOIN stores s ON s.id = a.store_id WHERE {$where}",
            $params
        )['n'];
    }

    /** Totals for a store's accounts (outstanding = spendable + reserved). */
    public static function storeTotals(string $storeId): array {
        $r = Database::fetchOne(
            "SELECT COUNT(*) AS accounts,
                    COALESCE(SUM(balance_micro), 0) AS balance_micro,
                    COALESCE(SUM(reserved_micro), 0) AS reserved_micro,
                    COALESCE(SUM(earned_micro), 0) AS earned_micro,
                    COALESCE(SUM(spent_micro), 0) AS spent_micro
               FROM rewards_accounts WHERE store_id = ?",
            [$storeId]
        ) ?? [];
        return [
            'accounts' => (int)($r['accounts'] ?? 0),
            'balanceMicro' => (int)($r['balance_micro'] ?? 0),
            'reservedMicro' => (int)($r['reserved_micro'] ?? 0),
            'earnedMicro' => (int)($r['earned_micro'] ?? 0),
            'spentMicro' => (int)($r['spent_micro'] ?? 0),
        ];
    }

    private static function listWhere(string|array|null $storeId, string $search): array {
        $where = ['1 = 1'];
        $params = [];
        if (is_array($storeId)) {
            // A store admin's scope: the stores granted to them (none → nothing).
            if ($storeId === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'a.store_id IN (' . implode(',', array_fill(0, count($storeId), '?')) . ')';
                $params = array_merge($params, array_values($storeId));
            }
        } elseif ($storeId !== null && $storeId !== '' && $storeId !== '__all__') {
            $where[] = 'a.store_id = ?';
            $params[] = $storeId;
        }
        $q = strtolower(trim($search));
        if ($q !== '') {
            $where[] = "a.username_key LIKE ? ESCAPE '\\'";
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Ledger rows for one account, newest first. */
    public static function ledgerForAccount(string $accountId, int $limit = 200): array {
        $limit = max(1, min(1000, $limit));
        return Database::fetchAll(
            "SELECT * FROM rewards_ledger WHERE account_id = ? ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            [$accountId]
        );
    }

    // ---------------- Balance movements ----------------
    //
    // Each movement is one ledger row + one guarded account UPDATE, inside
    // an IMMEDIATE transaction opened here unless the caller already holds
    // one (then it joins). The UNIQUE (invoice_id, kind) ledger index makes
    // every invoice-keyed movement idempotent: a duplicate insert throws,
    // the account is left untouched and the method reports false.

    /**
     * Credit an earn (or any invoice-keyed credit) to an account. Returns true
     * when this call moved the balance, false when the same (invoice, kind)
     * was credited before.
     */
    public static function credit(string $accountId, ?string $invoiceId, int $micro, string $currency, string $kind = self::KIND_EARN, ?string $note = null): bool {
        if ($micro <= 0) {
            return false;
        }
        return self::move($accountId, $invoiceId, $kind, $micro, $currency, $note, function (array $acct) use ($micro) {
            return [
                'sql' => "UPDATE rewards_accounts SET balance_micro = balance_micro + ?, earned_micro = earned_micro + ?,
                          updated_at = ?, last_activity_at = ? WHERE id = ?",
                'params' => [$micro, $micro, time(), time(), $acct['id']],
            ];
        });
    }

    /**
     * Hold $micro of an account's spendable balance for $invoiceId. False when
     * the balance is short or the invoice already holds a reservation.
     */
    public static function reserve(string $accountId, string $invoiceId, int $micro, string $currency): bool {
        if ($micro <= 0) {
            return false;
        }
        return self::move($accountId, $invoiceId, self::KIND_RESERVE, -$micro, $currency, null, function (array $acct) use ($micro) {
            return [
                'sql' => "UPDATE rewards_accounts SET balance_micro = balance_micro - ?, reserved_micro = reserved_micro + ?,
                          updated_at = ?, last_activity_at = ? WHERE id = ? AND balance_micro >= ?",
                'params' => [$micro, $micro, time(), time(), $acct['id'], $micro],
            ];
        });
    }

    /**
     * Turn $invoiceId's reservation into a spend (the invoice settled). The
     * amount is read from the reservation's ledger row. False when there is no
     * reservation or it was already committed / released.
     */
    public static function commit(string $accountId, string $invoiceId, string $currency): bool {
        $held = self::reservationMicro($accountId, $invoiceId);
        if ($held <= 0 || self::hasKind($invoiceId, self::KIND_RELEASE)) {
            return false;
        }
        return self::move($accountId, $invoiceId, self::KIND_COMMIT, 0, $currency, null, function (array $acct) use ($held) {
            return [
                'sql' => "UPDATE rewards_accounts SET reserved_micro = reserved_micro - ?, spent_micro = spent_micro + ?,
                          updated_at = ?, last_activity_at = ? WHERE id = ? AND reserved_micro >= ?",
                'params' => [$held, $held, time(), time(), $acct['id'], $held],
            ];
        });
    }

    /**
     * Hand $invoiceId's reservation back to the spendable balance (the invoice
     * expired, was voided, or the payer removed the rewards). False when there
     * is nothing to release or it was already committed / released.
     */
    public static function release(string $accountId, string $invoiceId, string $currency, ?string $note = null): bool {
        $held = self::reservationMicro($accountId, $invoiceId);
        if ($held <= 0 || self::hasKind($invoiceId, self::KIND_COMMIT)) {
            return false;
        }
        return self::move($accountId, $invoiceId, self::KIND_RELEASE, $held, $currency, $note, function (array $acct) use ($held) {
            return [
                'sql' => "UPDATE rewards_accounts SET reserved_micro = reserved_micro - ?, balance_micro = balance_micro + ?,
                          updated_at = ?, last_activity_at = ? WHERE id = ? AND reserved_micro >= ?",
                'params' => [$held, $held, time(), time(), $acct['id'], $held],
            ];
        });
    }

    /**
     * Take back an earn whose settlement was reversed (zero-conf payment gone).
     * The spendable balance is floored at zero: whatever was already spent
     * stays spent. False when nothing was earned on this invoice or it was
     * reversed before.
     */
    public static function reverseEarn(string $accountId, string $invoiceId, string $currency): bool {
        $earned = self::kindMicro($invoiceId, self::KIND_EARN);
        if ($earned <= 0) {
            return false;
        }
        return self::move($accountId, $invoiceId, self::KIND_REVERSE, -$earned, $currency, null, function (array $acct) use ($earned) {
            $take = min($earned, max(0, (int)$acct['balance_micro']));
            return [
                'sql' => "UPDATE rewards_accounts SET balance_micro = balance_micro - ?, earned_micro = MAX(0, earned_micro - ?),
                          updated_at = ?, last_activity_at = ? WHERE id = ?",
                'params' => [$take, $earned, time(), time(), $acct['id']],
            ];
        });
    }

    /**
     * Forget a RELEASED reservation so the invoice is a clean slate again.
     *
     * The payer may undo applied rewards before paying (operator decision
     * 13) and then apply them again; the UNIQUE (invoice_id, kind) ledger
     * index would otherwise refuse the second 'reserve' for the same
     * invoice. The reserve + release pair nets to zero on the account, so
     * dropping both rows changes no balance; the account's lifetime figures
     * and last_activity_at keep the trace. Refuses (returns false, nothing
     * deleted) unless the reservation was released and never committed —
     * an expired invoice's release is kept as history by its caller not
     * asking for this.
     */
    public static function forgetReservation(string $invoiceId): bool {
        if (!self::hasKind($invoiceId, self::KIND_RELEASE) || self::hasKind($invoiceId, self::KIND_COMMIT)) {
            return false;
        }
        $owns = !Database::inTransaction();
        if ($owns) {
            Database::beginImmediate();
        }
        try {
            Database::query(
                "DELETE FROM rewards_ledger WHERE invoice_id = ? AND kind IN (?, ?)",
                [$invoiceId, self::KIND_RESERVE, self::KIND_RELEASE]
            );
            if ($owns) {
                Database::commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($owns) {
                Database::rollback();
            }
            throw $e;
        }
    }

    /** Micro currently reserved for $invoiceId on $accountId (0 when none / already settled). */
    public static function reservationMicro(string $accountId, string $invoiceId): int {
        $r = Database::fetchOne(
            "SELECT delta_micro FROM rewards_ledger WHERE account_id = ? AND invoice_id = ? AND kind = ?",
            [$accountId, $invoiceId, self::KIND_RESERVE]
        );
        return $r ? -((int)$r['delta_micro']) : 0;
    }

    private static function kindMicro(string $invoiceId, string $kind): int {
        $r = Database::fetchOne(
            "SELECT delta_micro FROM rewards_ledger WHERE invoice_id = ? AND kind = ?",
            [$invoiceId, $kind]
        );
        return $r ? abs((int)$r['delta_micro']) : 0;
    }

    public static function hasKind(string $invoiceId, string $kind): bool {
        return Database::fetchOne(
            "SELECT 1 AS x FROM rewards_ledger WHERE invoice_id = ? AND kind = ? LIMIT 1",
            [$invoiceId, $kind]
        ) !== null;
    }

    /**
     * One ledger row + one guarded account update, atomically. $build gets the
     * locked account row and returns ['sql' => ..., 'params' => ...] for the
     * UPDATE; a guard that matches no row (short balance) rolls back.
     */
    private static function move(string $accountId, ?string $invoiceId, string $kind, int $delta, string $currency, ?string $note, callable $build): bool {
        $owns = !Database::inTransaction();
        if ($owns) {
            Database::beginImmediate();
        }
        try {
            $acct = Database::fetchOne("SELECT * FROM rewards_accounts WHERE id = ?", [$accountId]);
            if (!$acct) {
                throw new RuntimeException('Rewards account not found: ' . $accountId);
            }
            try {
                Database::insert('rewards_ledger', [
                    'account_id' => $accountId,
                    'store_id' => (string)$acct['store_id'],
                    'invoice_id' => $invoiceId,
                    'kind' => $kind,
                    'delta_micro' => $delta,
                    'currency' => self::normalizeCurrency($currency),
                    'note' => $note,
                    'created_at' => time(),
                ]);
            } catch (Throwable $e) {
                // UNIQUE (invoice_id, kind): this movement already happened.
                if ($owns) {
                    Database::rollback();
                }
                if (Database::isBusyError($e)) {
                    throw $e;
                }
                return false;
            }
            $u = $build($acct);
            $n = Database::query($u['sql'], $u['params'])->rowCount();
            if ($n !== 1) {
                if ($owns) {
                    Database::rollback();
                    return false;
                }
                throw new RuntimeException('Rewards balance guard failed for ' . $accountId . ' (' . $kind . ')');
            }
            if ($owns) {
                Database::commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($owns && Database::inTransaction()) {
                Database::rollback();
            }
            throw $e;
        }
    }

    // ---------------- Store currency switch ----------------

    /**
     * Does switching the store's default currency to $newCurrency need an
     * operator decision? True when rewards balances exist in another currency.
     */
    public static function currencySwitchNeedsDecision(array $store, string $newCurrency): bool {
        $s = self::settingsFromRow($store);
        if ($s['currency'] === null || self::normalizeCurrency($newCurrency) === $s['currency']) {
            return false;
        }
        $t = self::storeTotals((string)$store['id']);
        return $t['accounts'] > 0 && ($t['balanceMicro'] > 0 || $t['reservedMicro'] > 0);
    }

    /**
     * Operator chose "convert": every account's spendable and reserved balance
     * is converted once, at today's rate, and the store's rewards currency
     * becomes $newCurrency. Throws when no rate is available (nothing changed).
     */
    public static function convertStoreCurrency(string $storeId, string $newCurrency): void {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new InvalidArgumentException('Store not found');
        }
        $from = self::settingsFromRow($store)['currency'];
        $to = self::normalizeCurrency($newCurrency);
        if ($from === null || $from === $to) {
            Database::update('stores', ['rewards_currency' => $to], 'id = ?', [$storeId]);
            return;
        }
        // Probe the rate before touching anything.
        if (self::convertMicro(self::MICRO, $from, $to, $store) === null) {
            throw new RuntimeException('Exchange rate unavailable; rewards balances were not converted');
        }
        Database::beginImmediate();
        try {
            $now = time();
            foreach (Database::fetchAll("SELECT * FROM rewards_accounts WHERE store_id = ?", [$storeId]) as $a) {
                $newBal = self::convertMicro((int)$a['balance_micro'], $from, $to, $store);
                $newRes = self::convertMicro((int)$a['reserved_micro'], $from, $to, $store);
                $newEarned = self::convertMicro((int)$a['earned_micro'], $from, $to, $store);
                $newSpent = self::convertMicro((int)$a['spent_micro'], $from, $to, $store);
                if ($newBal === null || $newRes === null || $newEarned === null || $newSpent === null) {
                    throw new RuntimeException('Exchange rate unavailable; rewards balances were not converted');
                }
                Database::insert('rewards_ledger', [
                    'account_id' => $a['id'], 'store_id' => $storeId, 'invoice_id' => null,
                    'kind' => self::KIND_CONVERT, 'delta_micro' => $newBal - (int)$a['balance_micro'],
                    'currency' => $to, 'created_at' => $now,
                    'note' => 'converted ' . self::fromMicroExact((int)$a['balance_micro']) . ' ' . $from
                        . ' to ' . self::fromMicroExact($newBal) . ' ' . $to,
                ]);
                Database::update('rewards_accounts', [
                    'balance_micro' => $newBal, 'reserved_micro' => $newRes,
                    'earned_micro' => $newEarned, 'spent_micro' => $newSpent, 'updated_at' => $now,
                ], 'id = ?', [$a['id']]);
            }
            // Open reservations were converted with the accounts.
            foreach (Database::fetchAll(
                "SELECT id, rewards_applied_micro FROM invoices WHERE store_id = ? AND rewards_spend_state = ? AND rewards_applied_micro IS NOT NULL",
                [$storeId, self::SPEND_RESERVED]
            ) as $inv) {
                $m = self::convertMicro((int)$inv['rewards_applied_micro'], $from, $to, $store);
                if ($m !== null) {
                    Database::update('invoices', ['rewards_applied_micro' => $m], 'id = ?', [$inv['id']]);
                }
            }
            Database::update('stores', ['rewards_currency' => $to], 'id = ?', [$storeId]);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Operator chose "keep": balances stay in the old rewards currency and
     * only the display converts. Nothing to persist — rewards_currency is
     * already decoupled from default_currency — but kept as an explicit
     * entry point so callers read as a decision.
     */
    public static function keepCurrency(string $storeId): void {
        // Intentionally a no-op; see docblock.
    }

    // ---------------- API shapes ----------------

    /** camelCase account row for the admin API / CSV. */
    public static function formatAccountForApi(array $row, ?array $store = null): array {
        $cur = self::normalizeCurrency((string)($row['currency'] ?? $store['rewards_currency'] ?? 'sat'));
        $bal = (int)$row['balance_micro'];
        $out = [
            'id' => $row['id'],
            'storeId' => $row['store_id'],
            'storeName' => $row['store_name'] ?? null,
            'username' => $row['username'],
            'currency' => $cur,
            'balanceMicro' => $bal,
            'balance' => self::fromMicro($bal, $cur),
            'balanceDisplay' => self::formatMicro($bal, $cur),
            'reservedMicro' => (int)$row['reserved_micro'],
            'reserved' => self::fromMicro((int)$row['reserved_micro'], $cur),
            'earnedMicro' => (int)$row['earned_micro'],
            'earned' => self::fromMicro((int)$row['earned_micro'], $cur),
            'spentMicro' => (int)$row['spent_micro'],
            'spent' => self::fromMicro((int)$row['spent_micro'], $cur),
            'createdAt' => (int)$row['created_at'],
            'lastActivityAt' => (int)$row['last_activity_at'],
        ];
        // Sat equivalents (and, for sat stores, a fiat equivalent in the store
        // currency) so the admin list can show both. Rate outages leave them null.
        if ($cur === 'sat') {
            $out['balanceSats'] = (int)self::fromMicro($bal, 'sat');
            $disp = $store !== null ? self::normalizeCurrency((string)($store['default_currency'] ?? 'sat')) : 'sat';
            $out['balanceFiat'] = ($disp !== 'sat' && $bal > 0) ? self::microToAmount($bal, 'sat', $disp, $store) : null;
            $out['balanceFiatCurrency'] = $disp !== 'sat' ? $disp : null;
        } else {
            $out['balanceSats'] = $bal > 0 ? self::microToSats($bal, $cur, $store) : 0;
            $out['balanceFiat'] = null;
            $out['balanceFiatCurrency'] = null;
        }
        return $out;
    }
}
