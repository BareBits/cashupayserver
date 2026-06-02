<?php
/**
 * CashuPayServer — Stats dashboard aggregation
 *
 * Read-only aggregation over invoices and melts for the admin stats page.
 * Mint-unit caveat: amount_sats in the invoices table is normalized to the
 * store's mint unit, which equals sats only for sat/msat mints. Fiat-unit
 * mints would aggregate incorrectly here — same pre-existing assumption
 * dev_fee.php makes.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dev_fee.php';
require_once __DIR__ . '/rates.php';

class Stats {
    public const ALL_STORES = '__all__';

    /**
     * Resolve a range key ('all' | '6m' | '1m' | '1w') to a Unix start
     * timestamp, or null for 'all'.
     */
    public static function rangeStart(string $range): ?int {
        $now = time();
        switch ($range) {
            case '6m': return $now - 6 * 30 * 86400;
            case '1m': return $now - 30 * 86400;
            case '1w': return $now - 7 * 86400;
            case 'all':
            default:   return null;
        }
    }

    /**
     * Build a WHERE-fragment list (clauses + params) for the given store
     * filter. Returns ['clauses' => [...], 'params' => [...]].
     */
    private static function storeWhere(string $storeId, string $column = 'store_id'): array {
        if ($storeId === self::ALL_STORES || $storeId === '') {
            return ['clauses' => [], 'params' => []];
        }
        return ['clauses' => ["{$column} = ?"], 'params' => [$storeId]];
    }

    /**
     * Append a created_at >= ? clause when range is bounded.
     */
    private static function rangeWhere(?int $startTs, string $column = 'created_at'): array {
        if ($startTs === null) {
            return ['clauses' => [], 'params' => []];
        }
        return ['clauses' => ["{$column} >= ?"], 'params' => [$startTs]];
    }

    /**
     * Compose a final WHERE clause from N fragments.
     */
    private static function compose(array ...$frags): array {
        $clauses = [];
        $params = [];
        foreach ($frags as $f) {
            foreach ($f['clauses'] as $c) $clauses[] = $c;
            foreach ($f['params']  as $p) $params[]  = $p;
        }
        $sql = $clauses ? (' WHERE ' . implode(' AND ', $clauses)) : '';
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Best-effort current BTC price in the chosen display currency. Falls
     * back to USD if the store doesn't have a default_currency set.
     */
    public static function btcPrice(string $currency, ?string $storeId = null): ?float {
        $providers = ['coingecko', 'binance'];
        if ($storeId !== null && $storeId !== self::ALL_STORES && $storeId !== '') {
            $p = Config::getStorePriceProviders($storeId);
            $providers = [$p['primary'], $p['secondary']];
        }
        try {
            return (float) ExchangeRates::getBtcPrice($currency, $providers[0], $providers[1]);
        } catch (\Throwable $e) {
            error_log('Stats::btcPrice failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve which fiat currency to show next to sat values. Falls back to
     * USD when the store's default_currency is sats/msats (those aren't a
     * useful fiat parenthetical and would cause the BTC price lookup to
     * return 0).
     */
    public static function displayCurrency(string $storeId): string {
        if ($storeId === self::ALL_STORES || $storeId === '') {
            return 'USD';
        }
        $store = Config::getStore($storeId);
        $cur = $store['default_currency'] ?? 'USD';
        if (!is_string($cur) || $cur === '') {
            return 'USD';
        }
        $upper = strtoupper($cur);
        if (in_array($upper, ['SAT', 'SATS', 'MSAT'], true)) {
            return 'USD';
        }
        return $upper;
    }

    /**
     * Full summary block: financials + fees + effective fee %.
     */
    public static function summary(string $storeId, string $range): array {
        $startTs = self::rangeStart($range);

        // Revenue + invoice count + on-chain count, all from paid invoices
        // in range. On-chain classification: onchain_first_seen_at IS NOT NULL.
        $whereInv = self::compose(
            ['clauses' => ["status = 'Settled'", 'amount_sats IS NOT NULL'], 'params' => []],
            self::storeWhere($storeId),
            self::rangeWhere($startTs)
        );

        $row = Database::fetchOne(
            "SELECT
                COALESCE(SUM(amount_sats), 0) AS revenue,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(CASE WHEN onchain_first_seen_at IS NOT NULL THEN amount_sats ELSE 0 END), 0) AS onchain_revenue,
                COALESCE(SUM(CASE WHEN onchain_first_seen_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS onchain_count,
                COALESCE(SUM(CASE WHEN onchain_first_seen_at IS NULL THEN amount_sats ELSE 0 END), 0) AS lightning_revenue,
                COALESCE(SUM(CASE WHEN onchain_first_seen_at IS NULL THEN 1 ELSE 0 END), 0) AS lightning_count
             FROM invoices" . $whereInv['sql'],
            $whereInv['params']
        );
        $revenue          = (int) $row['revenue'];
        $invoiceCount     = (int) $row['invoice_count'];
        $onchainRevenue   = (int) $row['onchain_revenue'];
        $lightningRevenue = (int) $row['lightning_revenue'];
        $onchainCount     = (int) $row['onchain_count'];
        $lightningCount   = (int) $row['lightning_count'];

        // Fees paid in the same date window. Filter by note.
        $feesPaidByNote = self::feesPaidByNote($storeId, $startTs);

        // Network fees (Lightning routing / mint fees) — sum from melts in range.
        $whereMelt = self::compose(
            self::storeWhere($storeId),
            self::rangeWhere($startTs)
        );
        $networkFees = (int) Database::fetchOne(
            "SELECT COALESCE(SUM(network_fee_sats), 0) AS s FROM melts" . $whereMelt['sql'],
            $whereMelt['params']
        )['s'];

        $totalFeesPaid = $feesPaidByNote['upstream'] + $feesPaidByNote['dev'] + $feesPaidByNote['hosting'] + $networkFees;
        $profit = $revenue - $totalFeesPaid;
        $effectiveFeePct = $revenue > 0 ? ($totalFeesPaid / $revenue * 100.0) : 0.0;

        // Lifetime fees owed (DevFee::computeOwed is per-store, lifetime).
        $owed = self::lifetimeOwed($storeId);

        $currency = self::displayCurrency($storeId);
        $btcPrice = self::btcPrice($currency, $storeId);

        return [
            'range' => $range,
            'range_start' => $startTs,
            'store_id' => $storeId,
            'currency' => $currency,
            'btc_price' => $btcPrice,
            'revenue_sats' => $revenue,
            'invoice_count' => $invoiceCount,
            'lightning_revenue_sats' => $lightningRevenue,
            'lightning_count' => $lightningCount,
            'onchain_revenue_sats' => $onchainRevenue,
            'onchain_count' => $onchainCount,
            'fees_paid' => [
                'upstream' => $feesPaidByNote['upstream'],
                'dev' => $feesPaidByNote['dev'],
                'hosting' => $feesPaidByNote['hosting'],
                'network' => $networkFees,
                'total' => $totalFeesPaid,
            ],
            'fees_owed' => $owed,
            'profit_sats' => $profit,
            'effective_fee_pct' => $effectiveFeePct,
        ];
    }

    /**
     * Sum of melts.amount_sats grouped by fee note, filtered by store + range.
     */
    private static function feesPaidByNote(string $storeId, ?int $startTs): array {
        $out = ['upstream' => 0, 'dev' => 0, 'hosting' => 0];
        $map = [
            'upstream' => FEE_NOTE_UPSTREAM,
            'dev'      => FEE_NOTE_DEV,
            'hosting'  => FEE_NOTE_HOSTING,
        ];
        foreach ($map as $key => $note) {
            $where = self::compose(
                ['clauses' => ['note = ?'], 'params' => [$note]],
                self::storeWhere($storeId),
                self::rangeWhere($startTs)
            );
            $out[$key] = (int) Database::fetchOne(
                "SELECT COALESCE(SUM(amount_sats), 0) AS s FROM melts" . $where['sql'],
                $where['params']
            )['s'];
        }
        return $out;
    }

    /**
     * Lifetime owed across the selected store(s). For ALL_STORES we sum the
     * per-store DevFee::computeOwed results so the numbers match what cron
     * would settle on the next tick.
     */
    private static function lifetimeOwed(string $storeId): array {
        $totals = ['upstream' => 0, 'dev' => 0, 'hosting' => 0, 'total' => 0];
        $storeIds = ($storeId === self::ALL_STORES || $storeId === '')
            ? array_column(Database::fetchAll("SELECT id FROM stores"), 'id')
            : [$storeId];
        foreach ($storeIds as $sid) {
            $o = DevFee::computeOwed($sid);
            $totals['upstream'] += (int) $o['upstream_owed'];
            $totals['dev']      += (int) $o['dev_owed'];
            $totals['hosting']  += (int) $o['hosting_owed'];
        }
        $totals['total'] = $totals['upstream'] + $totals['dev'] + $totals['hosting'];
        return $totals;
    }

    public const UPGRADE_BANNER_THRESHOLD_USD = 250.0;
    public const UPGRADE_BANNER_DISMISS_SECS  = 90 * 86400;
    public const UPGRADE_BANNER_DISMISS_KEY   = 'upgrade_banner_dismissed_at';

    /**
     * Eligibility check for the "upgrade to full BareBits" nudge on the stats
     * dashboard. Returns null when the banner should stay hidden — dismissed
     * within the last 90 days, BTC price unavailable, or revenue below the
     * threshold across all stores. Otherwise returns the numbers used to
     * decide so the caller can surface them for debugging if desired.
     *
     * Threshold logic: last 30 days > $250 OR average of non-zero buckets in
     * the last 180 days (six rolling 30-day windows) > $250. Sats are
     * converted to USD using the current BTC price — see Stats::btcPrice().
     */
    public static function upgradeBannerState(): ?array {
        $dismissedAt = (int) Config::get(self::UPGRADE_BANNER_DISMISS_KEY, 0);
        if ($dismissedAt > 0 && (time() - $dismissedAt) < self::UPGRADE_BANNER_DISMISS_SECS) {
            return null;
        }

        $btcUsd = self::btcPrice('USD');
        if ($btcUsd === null || $btcUsd <= 0) {
            return null;
        }

        $now = time();
        $bucketSats = [];
        for ($i = 0; $i < 6; $i++) {
            $start = $now - ($i + 1) * 30 * 86400;
            $end   = $now - $i * 30 * 86400;
            $bucketSats[$i] = (int) Database::fetchOne(
                "SELECT COALESCE(SUM(amount_sats), 0) AS s
                 FROM invoices
                 WHERE status = 'Settled'
                   AND amount_sats IS NOT NULL
                   AND created_at >= ?
                   AND created_at <  ?",
                [$start, $end]
            )['s'];
        }

        $satsToUsd = function (int $sats) use ($btcUsd): float {
            return ($sats / 100000000.0) * $btcUsd;
        };

        $last30Usd = $satsToUsd($bucketSats[0]);

        $nonZeroUsd = [];
        foreach ($bucketSats as $sats) {
            if ($sats > 0) {
                $nonZeroUsd[] = $satsToUsd($sats);
            }
        }
        $avgUsd = $nonZeroUsd ? array_sum($nonZeroUsd) / count($nonZeroUsd) : 0.0;

        if ($last30Usd <= self::UPGRADE_BANNER_THRESHOLD_USD
            && $avgUsd    <= self::UPGRADE_BANNER_THRESHOLD_USD) {
            return null;
        }

        return [
            'thresholdUsd'      => self::UPGRADE_BANNER_THRESHOLD_USD,
            'revenueLast30dUsd' => round($last30Usd, 2),
            'avgMonthlyUsd'     => round($avgUsd, 2),
            'btcUsd'            => $btcUsd,
        ];
    }

    /**
     * Record the operator's dismissal of the upgrade banner.
     */
    public static function dismissUpgradeBanner(): void {
        Config::set(self::UPGRADE_BANNER_DISMISS_KEY, time());
    }

    /**
     * Chart data for the financials section. type ∈ {revenue, count, fees}.
     */
    public static function chart(string $storeId, string $range, string $type): array {
        $startTs = self::rangeStart($range);

        if ($type === 'fees') {
            $feesPaid = self::feesPaidByNote($storeId, $startTs);
            // Network fees: sum of melts.network_fee_sats in range, same
            // window as feesPaidByNote so chart slices stay consistent.
            $whereMelt = self::compose(
                self::storeWhere($storeId),
                self::rangeWhere($startTs)
            );
            $network = (int) Database::fetchOne(
                "SELECT COALESCE(SUM(network_fee_sats), 0) AS s FROM melts" . $whereMelt['sql'],
                $whereMelt['params']
            )['s'];
            return [
                'labels' => ['Upstream dev fee', 'Dev fee', 'Hosting fee', 'Network fees'],
                'data'   => [$feesPaid['upstream'], $feesPaid['dev'], $feesPaid['hosting'], $network],
            ];
        }

        // revenue / count breakdowns: Lightning vs On-chain.
        $whereInv = self::compose(
            ['clauses' => ["status = 'Settled'", 'amount_sats IS NOT NULL'], 'params' => []],
            self::storeWhere($storeId),
            self::rangeWhere($startTs)
        );
        if ($type === 'count') {
            $row = Database::fetchOne(
                "SELECT
                    SUM(CASE WHEN onchain_first_seen_at IS NOT NULL THEN 1 ELSE 0 END) AS onchain,
                    SUM(CASE WHEN onchain_first_seen_at IS NULL THEN 1 ELSE 0 END) AS lightning
                 FROM invoices" . $whereInv['sql'],
                $whereInv['params']
            );
        } else {
            $row = Database::fetchOne(
                "SELECT
                    SUM(CASE WHEN onchain_first_seen_at IS NOT NULL THEN amount_sats ELSE 0 END) AS onchain,
                    SUM(CASE WHEN onchain_first_seen_at IS NULL THEN amount_sats ELSE 0 END) AS lightning
                 FROM invoices" . $whereInv['sql'],
                $whereInv['params']
            );
        }
        return [
            'labels' => ['Lightning', 'On-chain'],
            'data'   => [(int)($row['lightning'] ?? 0), (int)($row['onchain'] ?? 0)],
        ];
    }

    /**
     * Paginated payouts (user-initiated / auto-melt; melts.note IS NULL).
     */
    public static function payouts(string $storeId, string $range, int $page, int $perPage = 10): array {
        return self::melts($storeId, $range, $page, $perPage, null);
    }

    /**
     * Paginated fee payments (melts.note IS NOT NULL).
     */
    public static function feePayments(string $storeId, string $range, int $page, int $perPage = 10): array {
        return self::melts($storeId, $range, $page, $perPage, 'fee');
    }

    private static function melts(string $storeId, string $range, int $page, int $perPage, ?string $filter): array {
        $startTs = self::rangeStart($range);
        $base = self::storeWhere($storeId);
        $rangeF = self::rangeWhere($startTs);

        if ($filter === 'fee') {
            $note = ['clauses' => ['note IS NOT NULL'], 'params' => []];
        } else {
            $note = ['clauses' => ['note IS NULL'], 'params' => []];
        }
        $w = self::compose($base, $rangeF, $note);

        $total = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM melts" . $w['sql'],
            $w['params']
        )['c'];

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT * FROM melts" . $w['sql'] . " ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($w['params'], [$perPage, $offset])
        );

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'rows' => $rows,
        ];
    }

    /**
     * Column order for the combined CSV. Leading `source` discriminates
     * which underlying table each row came from; shared columns
     * (id, store_id, created_at, amount_sats) come next so cross-table
     * filtering in a spreadsheet stays easy; then invoice-only, then
     * melt-only.
     */
    public static function combinedColumns(): array {
        return [
            'source',
            // shared
            'id', 'store_id', 'created_at', 'amount_sats',
            // invoice-only
            'status', 'additional_status', 'amount', 'currency',
            'exchange_rate', 'quote_id', 'bolt11', 'mint_url',
            'metadata', 'checkout_config', 'expiration_time', 'last_polled_at',
            'onchain_address', 'onchain_address_index', 'onchain_amount_sat',
            'onchain_first_seen_at', 'onchain_created_tip_height',
            // melt-only
            'network_fee_sats', 'destination', 'preimage', 'note',
        ];
    }

    /**
     * Yield all melts matching the filter, one row at a time, for CSV export.
     */
    public static function streamMelts(string $storeId, ?string $range, ?string $filter): \Generator {
        $startTs = $range !== null ? self::rangeStart($range) : null;
        $w = self::compose(
            self::storeWhere($storeId),
            self::rangeWhere($startTs),
            $filter === 'fee'
                ? ['clauses' => ['note IS NOT NULL'], 'params' => []]
                : ($filter === 'payout'
                    ? ['clauses' => ['note IS NULL'], 'params' => []]
                    : ['clauses' => [], 'params' => []])
        );
        $rows = Database::fetchAll(
            "SELECT * FROM melts" . $w['sql'] . " ORDER BY created_at ASC",
            $w['params']
        );
        foreach ($rows as $r) yield $r;
    }

    /**
     * Yield all invoices matching the filter, one row at a time, for CSV.
     */
    public static function streamInvoices(string $storeId, ?string $range, bool $paidOnly): \Generator {
        $startTs = $range !== null ? self::rangeStart($range) : null;
        $extra = $paidOnly
            ? ['clauses' => ["status = 'Settled'"], 'params' => []]
            : ['clauses' => [], 'params' => []];
        $w = self::compose(
            $extra,
            self::storeWhere($storeId),
            self::rangeWhere($startTs)
        );
        $rows = Database::fetchAll(
            "SELECT * FROM invoices" . $w['sql'] . " ORDER BY created_at ASC",
            $w['params']
        );
        foreach ($rows as $r) yield $r;
    }
}
