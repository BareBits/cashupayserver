<?php
/**
 * CashuPayServer - configurable payment-rail ordering.
 *
 * Two per-store orderings, both stored as comma-separated lists on the
 * stores table (NULL = built-in default, so pre-existing stores keep the
 * historical behavior):
 *
 *   stores.ln_rail_order — the order the Lightning PATHS are tried when
 *   generating an invoice: the four destination TYPES ('strike',
 *   'lnaddress', 'nwc', 'noffer') plus the two paths that are not
 *   destinations — 'swap' (a submarine swap settling to the store's
 *   on-chain address) and 'cashu' (a quote from the store's Cashu mint).
 *   Ordering is by type only; entries of the same destination type keep
 *   their relative priority from the per-type lists in the Lightning
 *   payments card. Applied at read time by StoreLnAddresses::listForStore
 *   (destinations) and by Invoice::create's walk (swap / mint slots), so
 *   changing the order never rewrites the stored chain.
 *
 *   The swap and cashu entries are ORDER-ONLY: they never become
 *   store_ln_addresses rows, so the auto-cashout melt chain (which walks
 *   those rows) can never pay the mint balance out "to the mint" or into
 *   a swap — see lnDestinationTypes(). Default order puts both last, the
 *   historical behavior (destinations, then swap, then mint fallback).
 *
 *   stores.onchain_source_order — where an invoice's on-chain address
 *   comes from first: 'strike' (a receive request minted in the merchant's
 *   Strike account) or 'local' (the store's xpub-derived / static
 *   address). Whichever is listed first is tried first; the other is the
 *   fallback. Note xpub vs static is a mode (onchain_address_mode), not a
 *   chain — 'local' means whichever of the two the store is configured
 *   for. Walked by Invoice::create's on-chain block.
 */

require_once __DIR__ . '/database.php';

class RailOrder {
    /** Order-only Lightning path: submarine swap to the store's on-chain address. */
    public const LN_SWAP = 'swap';
    /** Order-only Lightning path: a mint quote from the store's Cashu mint. */
    public const LN_CASHU = 'cashu';
    /**
     * Historical (and default) Lightning path order — the four destination
     * types in chainFromLists' grouping, then the swap, then the mint as
     * the final fallback.
     */
    public const LN_DEFAULT = ['strike', 'lnaddress', 'nwc', 'noffer', self::LN_SWAP, self::LN_CASHU];
    /** The Lightning path types that are store_ln_addresses destinations. */
    public const LN_DESTINATION_TYPES = ['strike', 'lnaddress', 'nwc', 'noffer'];
    /** Historical (and default) on-chain source order. */
    public const ONCHAIN_DEFAULT = ['strike', 'local'];

    /**
     * Parse a stored (or posted) CSV order against a known-type universe.
     * Defensive by design: unknown entries are dropped, duplicates
     * collapse to their first occurrence, and any missing types are
     * appended in default order — so a value written by a newer/older
     * version can never make a type silently unreachable. NULL/empty
     * yields the default.
     *
     * @param string[] $default the full type universe in default order
     * @return string[] a permutation of $default
     */
    private static function normalize(?string $csv, array $default): array {
        $out = [];
        foreach (explode(',', strtolower((string)$csv)) as $part) {
            $part = trim($part);
            if ($part !== '' && in_array($part, $default, true) && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        foreach ($default as $type) {
            if (!in_array($type, $out, true)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Strict save-time validation: the posted CSV must be exactly a
     * permutation of the known types (no unknowns, no duplicates, none
     * missing). Returns the parsed order; throws the operator-facing
     * message otherwise. Normalization stays lenient for reads; writes
     * are strict so a UI/API bug surfaces instead of half-applying.
     *
     * @param string[] $default the full type universe in default order
     * @return string[]
     */
    private static function parseStrict(string $csv, array $default, string $label): array {
        $parts = array_map('trim', explode(',', strtolower($csv)));
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        $sorted = $parts;
        $canonical = $default;
        sort($sorted);
        sort($canonical);
        if ($sorted !== $canonical) {
            throw new InvalidArgumentException(
                "Invalid {$label} order: expected exactly the types "
                . implode(', ', $default) . ' in some order.'
            );
        }
        return $parts;
    }

    /**
     * Strictly parse a posted Lightning type order (see parseStrict). Lets a
     * save handler validate up front — before probes / other writes — and
     * persist via setLnOrder only once the rest of the save succeeded.
     */
    public static function parseLnOrder(string $csv): array {
        // A client from before the swap and mint became orderable posts just
        // the four destination types: accept that exact legacy shape and
        // append the two order-only slots in default order (the behavior
        // such a client expects). Anything else must be the full permutation.
        $parts = array_values(array_filter(
            array_map('trim', explode(',', strtolower($csv))),
            static fn(string $p): bool => $p !== ''
        ));
        $sorted = $parts;
        $legacy = self::LN_DESTINATION_TYPES;
        sort($sorted);
        sort($legacy);
        if ($sorted === $legacy) {
            return array_merge($parts, [self::LN_SWAP, self::LN_CASHU]);
        }
        return self::parseStrict($csv, self::LN_DEFAULT, 'Lightning payment path');
    }

    /** Strictly parse a posted on-chain source order (see parseStrict). */
    public static function parseOnchainOrder(string $csv): array {
        return self::parseStrict($csv, self::ONCHAIN_DEFAULT, 'on-chain address source');
    }

    /** Effective Lightning type order for a store row (as read by getStore). */
    public static function lnOrderFromRow(?array $store): array {
        return self::normalize($store['ln_rail_order'] ?? null, self::LN_DEFAULT);
    }

    /** Effective Lightning type order for a store id. */
    public static function lnOrderForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT ln_rail_order FROM stores WHERE id = ?", [$storeId]
        );
        return self::normalize($row['ln_rail_order'] ?? null, self::LN_DEFAULT);
    }

    /**
     * The raw stored Lightning order CSV, or null when the store has never
     * set one. The distinction matters to listForStore: an EXPLICIT order
     * type-sorts the chain, while null keeps the stored per-row order
     * verbatim — the exact historical behavior, which for chains saved
     * through the admin UI is the default type grouping anyway, but for
     * chains written through the legacy shape-classified API contract may
     * interleave types deliberately.
     */
    public static function storedLnOrderCsv(string $storeId): ?string {
        $row = Database::fetchOne(
            "SELECT ln_rail_order FROM stores WHERE id = ?", [$storeId]
        );
        $csv = $row['ln_rail_order'] ?? null;
        return ($csv === null || trim((string)$csv) === '') ? null : (string)$csv;
    }

    /**
     * The destination-type subset of a Lightning order, in order — what the
     * melt chain and listForStore sort by. The 'swap' and 'cashu' slots are
     * dropped: neither is a payout destination.
     *
     * @param string[] $order permutation of LN_DEFAULT
     * @return string[]
     */
    public static function lnDestinationTypes(array $order): array {
        return array_values(array_filter(
            $order,
            static fn(string $t): bool => in_array($t, self::LN_DESTINATION_TYPES, true)
        ));
    }

    /**
     * Whether the Cashu mint is tried BEFORE the submarine swap in a
     * Lightning order. Strict no-mint-fallback ("when the swap fails, never
     * fall back to the mint") only has a meaning when the swap precedes the
     * mint; saving an order where the mint comes first clears the strict
     * flag (save_lightning_payments), and the swaps card refuses to turn
     * strict on while the order is this way round.
     *
     * @param string[] $order permutation of LN_DEFAULT
     */
    public static function cashuPrecedesSwap(array $order): bool {
        $cashuAt = array_search(self::LN_CASHU, $order, true);
        $swapAt = array_search(self::LN_SWAP, $order, true);
        if ($cashuAt === false || $swapAt === false) {
            return false;
        }
        return $cashuAt < $swapAt;
    }

    /** Effective on-chain source order for a store row (as read by getStore). */
    public static function onchainOrderFromRow(?array $store): array {
        return self::normalize($store['onchain_source_order'] ?? null, self::ONCHAIN_DEFAULT);
    }

    /** Effective on-chain source order for a store id. */
    public static function onchainOrderForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT onchain_source_order FROM stores WHERE id = ?", [$storeId]
        );
        return self::normalize($row['onchain_source_order'] ?? null, self::ONCHAIN_DEFAULT);
    }

    /**
     * Persist the Lightning type order from a posted CSV. Same direct-UPDATE
     * routing as OnchainConfig::setStoreOverride — the Config::updateStore
     * allowlist stays tight.
     */
    public static function setLnOrder(string $storeId, string $csv): void {
        $order = self::parseLnOrder($csv);
        Database::query(
            "UPDATE stores SET ln_rail_order = ? WHERE id = ?",
            [implode(',', $order), $storeId]
        );
    }

    /** Persist the on-chain source order from a posted CSV. */
    public static function setOnchainOrder(string $storeId, string $csv): void {
        $order = self::parseStrict($csv, self::ONCHAIN_DEFAULT, 'on-chain address source');
        Database::query(
            "UPDATE stores SET onchain_source_order = ? WHERE id = ?",
            [implode(',', $order), $storeId]
        );
    }

    /**
     * Stable-sort store_ln_addresses rows (each carrying a 'type') by the
     * store's configured type order. Rows of the same type keep their
     * relative (position) order. Rows with an unexpected type sort last,
     * in their stored order.
     *
     * The order may carry the order-only 'swap' / 'cashu' slots; no row ever
     * has those types, so they simply never match.
     *
     * @param array[] $rows
     * @param string[] $order permutation of LN_DEFAULT
     * @return array[]
     */
    public static function sortDestinations(array $rows, array $order): array {
        $rank = array_flip($order);
        $fallbackRank = count($order);
        $keyed = [];
        foreach ($rows as $i => $row) {
            $keyed[] = [$rank[$row['type'] ?? ''] ?? $fallbackRank, $i, $row];
        }
        usort($keyed, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });
        return array_map(static fn(array $k): array => $k[2], $keyed);
    }
}
