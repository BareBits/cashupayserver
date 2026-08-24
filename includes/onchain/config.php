<?php
/**
 * On-chain payment-offering configuration.
 *
 * Whether the on-chain rail is OFFERED to customers is deliberately separate
 * from whether an on-chain destination is CONFIGURED. A store can keep an xpub
 * — which submarine swaps require, since a swap settles on-chain to it — while
 * presenting a Lightning-only checkout (some merchants prefer Lightning for
 * speed). Callers still gate on "is a destination configured?" separately; this
 * class only answers "should we show the pay-to-address to customers?".
 *
 * The setting is per-store only: stores.onchain_offer_enabled, 0 off / 1 on.
 * Default on (NULL and legacy -1 "inherit" rows — from when a site-wide
 * default existed — resolve to on, so stores keep offering on-chain).
 */
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';

class OnchainConfig {
    /** Legacy sentinel still present in pre-store-only rows; resolves to on. */
    public const INHERIT = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON = 1;

    /** Raw per-store value (0 off / 1 on / legacy -1). Defaults to INHERIT
     *  when the column is NULL (older rows) or the store is missing. */
    public static function storeOverride(string $storeId): int {
        $row = Database::fetchOne(
            "SELECT onchain_offer_enabled FROM stores WHERE id = ?",
            [$storeId]
        );
        return ($row && $row['onchain_offer_enabled'] !== null)
            ? (int)$row['onchain_offer_enabled']
            : self::INHERIT;
    }

    /**
     * Whether the on-chain rail should be OFFERED to customers for this store.
     * Only an explicit 0 turns it off; NULL / legacy -1 resolve to on.
     * Independent of whether an xpub/static address is actually configured —
     * the caller gates on that separately (a store with no destination has
     * nothing to offer).
     */
    public static function isEnabledForStore(string $storeId): bool {
        return self::storeOverride($storeId) !== self::FORCE_OFF;
    }

    /**
     * Persist the per-store flag (0/1). Routed through a direct UPDATE (NOT
     * Config::updateStore — its allowlist stays tight).
     */
    public static function setStoreOverride(string $storeId, int $enabled): void {
        if (!in_array($enabled, [self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid onchain_offer_enabled value: {$enabled}");
        }
        Database::query(
            "UPDATE stores SET onchain_offer_enabled = ? WHERE id = ?",
            [$enabled, $storeId]
        );
    }
}
