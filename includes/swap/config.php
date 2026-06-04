<?php
/**
 * Site- and per-store-level configuration helpers for submarine swaps.
 *
 * Config keys used (all stored in the existing `config` table via Config::*):
 *   swaps_enabled            — bool, site default (off)
 *   swaps_provider_order     — string[]; lowercase provider names in
 *                              preference order; first one reachable wins.
 *                              Default ["zeus","boltz"].
 *   swaps_strict_no_mint_fallback — bool; if true and all providers fail
 *                                   at invoice-creation, the invoice errors
 *                                   instead of falling back to the mint.
 *   swaps_minimum_target_sats — int; optional override (UX guard) above
 *                               the provider's own minimum.
 *   swaps_auto_select_cheapest — bool; if true (default), fetch quotes from
 *                                every enabled provider in parallel and pick
 *                                the cheapest when it beats the priority
 *                                leader by more than the threshold.
 *   swaps_auto_select_threshold_pct — int 1..90; percent the cheapest must
 *                                     undercut the priority leader to win.
 *                                     Default 10.
 *   swaps_boltz_regtest_url   — string; required for the boltz provider on
 *                               regtest networks.
 *
 * Per-store override lives on stores.swaps_enabled as a tri-state:
 *   -1 → inherit site default
 *    0 → force off
 *    1 → force on
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

final class SwapsConfig {
    public const INHERIT = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON = 1;

    public const DEFAULT_PROVIDER_ORDER = ['zeus', 'boltz'];

    public static function siteEnabled(): bool {
        return (bool)Config::get('swaps_enabled', false);
    }

    public static function setSiteEnabled(bool $enabled): void {
        Config::set('swaps_enabled', $enabled);
    }

    /**
     * @return string[]
     */
    public static function providerOrder(): array {
        $raw = Config::get('swaps_provider_order', self::DEFAULT_PROVIDER_ORDER);
        if (!is_array($raw)) return self::DEFAULT_PROVIDER_ORDER;
        $out = [];
        foreach ($raw as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = strtolower($name);
            }
        }
        return $out !== [] ? $out : self::DEFAULT_PROVIDER_ORDER;
    }

    /**
     * @param string[] $providers
     */
    public static function setProviderOrder(array $providers): void {
        $clean = array_values(array_filter(
            array_map(fn($p) => is_string($p) ? strtolower(trim($p)) : '', $providers),
            fn($p) => $p !== ''
        ));
        Config::set('swaps_provider_order', $clean ?: self::DEFAULT_PROVIDER_ORDER);
    }

    public static function strictNoMintFallback(): bool {
        return (bool)Config::get('swaps_strict_no_mint_fallback', false);
    }

    public static function setStrictNoMintFallback(bool $strict): void {
        Config::set('swaps_strict_no_mint_fallback', $strict);
    }

    public static function minimumTargetSats(): ?int {
        $v = Config::get('swaps_minimum_target_sats', null);
        return is_numeric($v) ? max(0, (int)$v) : null;
    }

    public static function setMinimumTargetSats(?int $sats): void {
        Config::set('swaps_minimum_target_sats', $sats === null ? null : max(0, $sats));
    }

    public const DEFAULT_AUTO_SELECT_THRESHOLD_PCT = 10;

    public static function autoSelectCheapest(): bool {
        return (bool)Config::get('swaps_auto_select_cheapest', true);
    }

    public static function setAutoSelectCheapest(bool $enabled): void {
        Config::set('swaps_auto_select_cheapest', $enabled);
    }

    public static function autoSelectThresholdPct(): int {
        $v = Config::get('swaps_auto_select_threshold_pct', self::DEFAULT_AUTO_SELECT_THRESHOLD_PCT);
        if (!is_numeric($v)) return self::DEFAULT_AUTO_SELECT_THRESHOLD_PCT;
        return max(1, min(90, (int)$v));
    }

    public static function setAutoSelectThresholdPct(int $pct): void {
        Config::set('swaps_auto_select_threshold_pct', max(1, min(90, $pct)));
    }

    /**
     * Effective enabled flag for a store: per-store override falls back to
     * the site default. Returns false if the store does not have an xpub
     * configured — submarine swaps require an on-chain destination.
     */
    public static function isEnabledForStore(string $storeId): bool {
        $row = Database::fetchOne(
            "SELECT swaps_enabled, onchain_xpub FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row) return false;
        if (empty($row['onchain_xpub'])) return false;
        $tri = isset($row['swaps_enabled']) ? (int)$row['swaps_enabled'] : self::INHERIT;
        return match ($tri) {
            self::FORCE_ON  => true,
            self::FORCE_OFF => false,
            default         => self::siteEnabled(),
        };
    }

    /**
     * Persist a tri-state per-store override.
     */
    public static function setStoreOverride(string $storeId, int $tri): void {
        if (!in_array($tri, [self::INHERIT, self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid swaps_enabled tri-state: {$tri}");
        }
        Database::exec(
            "UPDATE stores SET swaps_enabled = ? WHERE id = ?",
            [$tri, $storeId]
        );
    }
}
