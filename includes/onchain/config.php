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
 * Site default: config key `onchain_payments_enabled` (bool, default true, so
 * existing stores keep offering on-chain).
 * Per-store override lives on stores.onchain_offer_enabled as a tri-state:
 *   -1 inherit the site default / 0 force off / 1 force on.
 *
 * Mirrors the SwapsConfig / SelfServe tri-state convention.
 */
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';

class OnchainConfig {
    public const INHERIT = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON = 1;

    public static function siteEnabled(): bool {
        return (bool)Config::get('onchain_payments_enabled', true);
    }

    public static function setSiteEnabled(bool $enabled): void {
        Config::set('onchain_payments_enabled', $enabled);
    }

    /** Raw per-store tri-state override (-1 inherit / 0 off / 1 on). Defaults
     *  to INHERIT when the column is NULL (older rows) or the store is missing. */
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
     * Whether the on-chain rail should be OFFERED to customers for this store,
     * combining the per-store tri-state with the site default. Independent of
     * whether an xpub/static address is actually configured — the caller gates
     * on that separately (a store with no destination has nothing to offer).
     */
    public static function isEnabledForStore(string $storeId): bool {
        return match (self::storeOverride($storeId)) {
            self::FORCE_ON  => true,
            self::FORCE_OFF => false,
            default         => self::siteEnabled(),
        };
    }

    /**
     * Persist the per-store tri-state. Routed through a direct UPDATE (NOT
     * Config::updateStore — its allowlist stays tight).
     */
    public static function setStoreOverride(string $storeId, int $tri): void {
        if (!in_array($tri, [self::INHERIT, self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid onchain_offer_enabled tri-state: {$tri}");
        }
        Database::query(
            "UPDATE stores SET onchain_offer_enabled = ? WHERE id = ?",
            [$tri, $storeId]
        );
    }
}
