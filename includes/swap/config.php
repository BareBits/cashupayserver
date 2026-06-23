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
 *   swaps_fee_fallback_max_pct — float; if a prospective swap's total cost
 *                                exceeds this percent of the invoice amount,
 *                                fall back to a mint-issued LN invoice. 0/unset
 *                                disables this check. Layers config-file →
 *                                site → store (see fee-fallback section below).
 *   swaps_fee_fallback_max_sats — int; same, expressed as an absolute sats cap.
 *   swaps_boltz_regtest_url   — string; required for the boltz provider on
 *                               regtest networks.
 *
 * Per-store override lives on stores.swaps_enabled as a tri-state:
 *   -1 → inherit site default
 *    0 → force off
 *    1 → force on
 *
 * Per-store fee-fallback overrides live on stores.swaps_fee_fallback_max_pct
 * and stores.swaps_fee_fallback_max_sats (REAL/INTEGER, NULL = inherit the
 * site value, which itself falls back to the config-file constant).
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

    /* ---- Fee-too-high → mint fallback thresholds --------------------------
     *
     * When a store has a cashu mint enabled (and strict-no-mint-fallback is
     * OFF), a prospective submarine swap whose *total cost* — percent fee +
     * lockup miner fee + claim miner-fee estimate, i.e.
     * SwapQuoteFetcher::totalCostSats() — exceeds EITHER threshold is skipped,
     * and the invoice falls back to a mint-issued Lightning invoice.
     *
     * Both thresholds layer three ways: per-store column (NULL = inherit) →
     * site config value (unset = inherit) → config-file constant → 0. A value
     * of 0 disables that particular check; with both at 0 there is no
     * fee-based fallback (historical behaviour, so existing deployments are
     * unaffected until an operator opts in).
     */

    /** Config-file default for the percent threshold (0 = disabled). */
    public static function configFileFeeFallbackMaxPct(): float {
        if (defined('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT')) {
            $v = (float)CASHUPAY_SWAPS_FEE_FALLBACK_MAX_PCT;
            if ($v > 0) return $v;
        }
        return 0.0;
    }

    /** Config-file default for the absolute sats threshold (0 = disabled). */
    public static function configFileFeeFallbackMaxSats(): int {
        if (defined('CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS')) {
            $v = (int)CASHUPAY_SWAPS_FEE_FALLBACK_MAX_SATS;
            if ($v > 0) return $v;
        }
        return 0;
    }

    /** Site-wide percent threshold; inherits the config-file constant. */
    public static function feeFallbackMaxPct(): float {
        $v = Config::get('swaps_fee_fallback_max_pct', null);
        if ($v === null || !is_numeric($v)) return self::configFileFeeFallbackMaxPct();
        return max(0.0, (float)$v);
    }

    /** Pass null to clear the site value (inherit the config-file constant). */
    public static function setFeeFallbackMaxPct(?float $pct): void {
        if ($pct === null) {
            Config::delete('swaps_fee_fallback_max_pct');
        } else {
            Config::set('swaps_fee_fallback_max_pct', max(0.0, $pct));
        }
    }

    /** Site-wide absolute sats threshold; inherits the config-file constant. */
    public static function feeFallbackMaxSats(): int {
        $v = Config::get('swaps_fee_fallback_max_sats', null);
        if ($v === null || !is_numeric($v)) return self::configFileFeeFallbackMaxSats();
        return max(0, (int)$v);
    }

    /** Pass null to clear the site value (inherit the config-file constant). */
    public static function setFeeFallbackMaxSats(?int $sats): void {
        if ($sats === null) {
            Config::delete('swaps_fee_fallback_max_sats');
        } else {
            Config::set('swaps_fee_fallback_max_sats', max(0, $sats));
        }
    }

    /**
     * Resolve the effective fee-fallback thresholds for a store: a non-null
     * per-store column overrides the site value; otherwise the site value
     * (which itself falls back to the config-file constant) applies.
     *
     * @return array{pct: float, sats: int}
     */
    public static function effectiveFeeFallbackForStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT swaps_fee_fallback_max_pct, swaps_fee_fallback_max_sats FROM stores WHERE id = ?",
            [$storeId]
        );
        $pct = ($row && $row['swaps_fee_fallback_max_pct'] !== null)
            ? max(0.0, (float)$row['swaps_fee_fallback_max_pct'])
            : self::feeFallbackMaxPct();
        $sats = ($row && $row['swaps_fee_fallback_max_sats'] !== null)
            ? max(0, (int)$row['swaps_fee_fallback_max_sats'])
            : self::feeFallbackMaxSats();
        return ['pct' => $pct, 'sats' => $sats];
    }

    /**
     * Persist per-store fee-fallback overrides. NULL on a value clears that
     * override so the store inherits the site/config-file value. Written via a
     * direct UPDATE — these columns are intentionally kept outside
     * Config::updateStore's allowlist.
     */
    public static function setStoreFeeFallback(string $storeId, ?float $pct, ?int $sats): void {
        Database::query(
            "UPDATE stores SET swaps_fee_fallback_max_pct = ?, swaps_fee_fallback_max_sats = ? WHERE id = ?",
            [
                $pct === null ? null : max(0.0, $pct),
                $sats === null ? null : max(0, $sats),
                $storeId,
            ]
        );
    }

    /**
     * Does a prospective swap's total cost exceed either active threshold?
     * A threshold of 0 disables that particular check; with both at 0 this
     * always returns false (no fee-based fallback). OR semantics: either the
     * sats cap or the percent cap being exceeded triggers the fallback.
     */
    public static function swapFeeExceedsThreshold(
        int $totalCostSats, int $targetSats, float $maxPct, int $maxSats
    ): bool {
        if ($maxSats > 0 && $totalCostSats > $maxSats) {
            return true;
        }
        if ($maxPct > 0 && $targetSats > 0 && ($totalCostSats * 100.0) > ($targetSats * $maxPct)) {
            return true;
        }
        return false;
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
        Database::query(
            "UPDATE stores SET swaps_enabled = ? WHERE id = ?",
            [$tri, $storeId]
        );
    }
}
