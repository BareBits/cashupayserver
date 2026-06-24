<?php
/**
 * Self-serve invoices — site- and per-store-level configuration + input
 * validation for the public, unauthenticated "create your own invoice" page
 * (pay.php, reached at /pay/{storeId}).
 *
 * Normally an invoice can only be created by an authenticated caller (the admin
 * "Request Payment" modal or a Greenfield API key). Self-serve lets a customer
 * create and pay an invoice themselves: they pick an amount + currency and an
 * optional note, we validate it (untrusted input), then hand off to
 * Invoice::create and the regular /payment/{id} display page.
 *
 * Config keys (stored in the `config` table via Config::*):
 *   selfserve_enabled   — bool, site default (off)
 *   selfserve_max_sats  — int, site default maximum invoice size in sats.
 *                         Caps how much liquidity a single self-serve invoice
 *                         can lock up. Defaults to DEFAULT_MAX_SATS.
 *
 * Per-store overrides live on the stores table:
 *   stores.selfserve_enabled   — tri-state: -1 inherit site / 0 force off /
 *                                1 force on (mirrors stores.swaps_enabled).
 *   stores.selfserve_max_sats  — INTEGER, NULL = inherit the site value.
 *
 * The toggle resolution + max resolution mirror the submarine-swaps pattern in
 * includes/swap/config.php so the admin UI and operator mental model stay
 * consistent.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

final class SelfServe {
    public const INHERIT   = -1;
    public const FORCE_OFF = 0;
    public const FORCE_ON  = 1;

    /** Default per-invoice maximum (sats) when nothing is configured. */
    public const DEFAULT_MAX_SATS = 500000;

    /** Smallest invoice we will create, in sats. */
    public const MIN_SATS = 1;

    /** Hard ceiling on the optional customer note length, in characters. */
    public const NOTES_MAX_LEN = 200;

    // ------------------------------------------------------------------
    // Site-wide toggle
    // ------------------------------------------------------------------

    public static function siteEnabled(): bool {
        return (bool)Config::get('selfserve_enabled', false);
    }

    public static function setSiteEnabled(bool $enabled): void {
        Config::set('selfserve_enabled', $enabled);
    }

    // ------------------------------------------------------------------
    // Site-wide maximum invoice size (sats)
    // ------------------------------------------------------------------

    public static function siteMaxSats(): int {
        $raw = Config::get('selfserve_max_sats', null);
        if ($raw === null || (int)$raw <= 0) {
            return self::DEFAULT_MAX_SATS;
        }
        return (int)$raw;
    }

    /**
     * Persist the site-wide max. Pass null to clear it back to the built-in
     * default (DEFAULT_MAX_SATS).
     */
    public static function setSiteMaxSats(?int $sats): void {
        if ($sats === null) {
            Config::delete('selfserve_max_sats');
            return;
        }
        if ($sats <= 0) {
            throw new InvalidArgumentException('Self-serve max must be a positive number of sats');
        }
        Config::set('selfserve_max_sats', $sats);
    }

    // ------------------------------------------------------------------
    // Per-store override (tri-state) + resolution
    // ------------------------------------------------------------------

    /**
     * Raw per-store tri-state override (-1 inherit / 0 off / 1 on). Defaults
     * to INHERIT when the column is NULL (older rows) or the store is missing.
     */
    public static function storeOverride(string $storeId): int {
        $row = Database::fetchOne(
            "SELECT selfserve_enabled FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['selfserve_enabled'] === null) {
            return self::INHERIT;
        }
        return (int)$row['selfserve_enabled'];
    }

    public static function setStoreOverride(string $storeId, int $tri): void {
        if (!in_array($tri, [self::INHERIT, self::FORCE_OFF, self::FORCE_ON], true)) {
            throw new InvalidArgumentException("Invalid selfserve_enabled tri-state: {$tri}");
        }
        Database::query(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            [$tri, $storeId]
        );
    }

    /**
     * Is self-serve effectively enabled for this store? Combines the per-store
     * tri-state with the site default, and additionally requires the store to
     * actually be able to take a payment — otherwise the public page would
     * render a form that always errors on submit.
     */
    public static function isEnabledForStore(string $storeId): bool {
        $store = Config::getStore($storeId);
        if (!$store) {
            return false;
        }
        if (!self::storeIsPaymentCapable($store)) {
            return false;
        }
        $tri = $store['selfserve_enabled'] === null
            ? self::INHERIT
            : (int)$store['selfserve_enabled'];
        return match ($tri) {
            self::FORCE_ON  => true,
            self::FORCE_OFF => false,
            default         => self::siteEnabled(),
        };
    }

    /**
     * A store can take a payment if it has a Cashu mint configured or an
     * on-chain destination (xpub, or a static address in static mode). Mirrors
     * the capability check at the top of Invoice::create.
     */
    public static function storeIsPaymentCapable(array $store): bool {
        $cashuConfigured = !empty($store['mint_url']) && !empty($store['seed_phrase']);
        $mode = $store['onchain_address_mode'] ?? 'xpub';
        $onchainConfigured = ($mode === 'static')
            ? !empty($store['onchain_static_address'])
            : !empty($store['onchain_xpub']);
        return $cashuConfigured || $onchainConfigured;
    }

    // ------------------------------------------------------------------
    // Per-store maximum invoice size (sats)
    // ------------------------------------------------------------------

    /**
     * Raw per-store override (sats), or null when the store inherits the site
     * value. Returned value is always > 0 when non-null.
     */
    public static function storeMaxSats(string $storeId): ?int {
        $row = Database::fetchOne(
            "SELECT selfserve_max_sats FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row || $row['selfserve_max_sats'] === null) {
            return null;
        }
        $v = (int)$row['selfserve_max_sats'];
        return $v > 0 ? $v : null;
    }

    public static function setStoreMaxSats(string $storeId, ?int $sats): void {
        if ($sats !== null && $sats <= 0) {
            throw new InvalidArgumentException('Per-store self-serve max must be a positive number of sats');
        }
        Database::query(
            "UPDATE stores SET selfserve_max_sats = ? WHERE id = ?",
            [$sats, $storeId]
        );
    }

    /**
     * Resolved maximum invoice size in sats for a store: per-store override
     * wins, else the site value, else the built-in default.
     */
    public static function effectiveMaxSats(string $storeId): int {
        $store = self::storeMaxSats($storeId);
        if ($store !== null) {
            return $store;
        }
        return self::siteMaxSats();
    }

    // ------------------------------------------------------------------
    // Currency options
    // ------------------------------------------------------------------

    /**
     * Currencies the customer may choose on the self-serve form: always 'sat',
     * plus the store's configured default display currency when that is a fiat
     * (i.e. not sat). Per the product decision, no other fiat currencies are
     * offered. Returned codes are normalized: 'sat' lowercase, fiat uppercase.
     *
     * @return string[]
     */
    public static function allowedCurrencies(string $storeId): array {
        $out = ['sat'];
        $default = Config::getStoreDefaultCurrency($storeId); // 'sat' or e.g. 'USD'
        $norm = strtoupper($default) === 'SATS' ? 'sat' : $default;
        if (strtolower($norm) !== 'sat') {
            $out[] = strtoupper($norm);
        }
        return $out;
    }

    /**
     * Validate that a customer-supplied currency code is one we offer for this
     * store. Returns the normalized code, or throws.
     */
    public static function validateCurrency(string $storeId, string $currency): string {
        $currency = trim($currency);
        $norm = strtoupper($currency) === 'SATS' ? 'sat' : $currency;
        foreach (self::allowedCurrencies($storeId) as $allowed) {
            if (strcasecmp($allowed, $norm) === 0) {
                return $allowed;
            }
        }
        throw new SelfServeValidationException('Unsupported currency.');
    }

    // ------------------------------------------------------------------
    // Amount + notes validation (untrusted input)
    // ------------------------------------------------------------------

    /**
     * Validate a raw amount string for a given currency and return it as a
     * clean decimal string suitable for Invoice::create / ExchangeRates. Throws
     * SelfServeValidationException on any malformed / out-of-range input.
     *
     * For 'sat'/'BTC'-style units only whole numbers are accepted; fiat allows
     * up to 2 decimal places. This does NOT enforce the sats max — that needs a
     * rate conversion and is done by the caller via assertWithinMax().
     */
    public static function validateAmount(string $raw, string $currency): string {
        $raw = trim($raw);
        if ($raw === '') {
            throw new SelfServeValidationException('Please enter an amount.');
        }
        // Reject anything that isn't a plain positive decimal. No signs,
        // exponents, thousands separators, or whitespace.
        $isSatUnit = in_array(strtoupper($currency), ['SAT', 'SATS', 'MSAT', 'BTC'], true);
        if ($isSatUnit && strtoupper($currency) !== 'BTC') {
            // Whole sats only.
            if (!preg_match('/^\d{1,15}$/', $raw)) {
                throw new SelfServeValidationException('Amount must be a whole number of sats.');
            }
            if ((int)$raw < self::MIN_SATS) {
                throw new SelfServeValidationException('Amount is too small.');
            }
            return $raw;
        }
        // BTC or fiat: allow up to 8 / 2 decimals respectively.
        $maxDecimals = strtoupper($currency) === 'BTC' ? 8 : 2;
        if (!preg_match('/^\d{1,12}(\.\d{1,' . $maxDecimals . '})?$/', $raw)) {
            throw new SelfServeValidationException('Amount is not a valid number.');
        }
        if ((float)$raw <= 0) {
            throw new SelfServeValidationException('Amount must be greater than zero.');
        }
        return $raw;
    }

    /**
     * Validate + normalize the optional note. Empty is allowed (returns ''). We
     * strip control characters (except none are kept — notes are single-line)
     * and enforce the length cap. Throws when over the cap so the customer gets
     * clear feedback rather than silent truncation.
     */
    public static function validateNotes(string $raw): string {
        // Normalize newlines/tabs to spaces, strip other control chars.
        $notes = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $notes = trim((string)$notes);
        if ($notes === '') {
            return '';
        }
        if (mb_strlen($notes) > self::NOTES_MAX_LEN) {
            throw new SelfServeValidationException(
                'Note is too long (max ' . self::NOTES_MAX_LEN . ' characters).'
            );
        }
        return $notes;
    }

    /**
     * Assert a computed sats amount is within [MIN_SATS, effective max]. Throws
     * SelfServeValidationException otherwise.
     */
    public static function assertWithinMax(string $storeId, int $sats): void {
        if ($sats < self::MIN_SATS) {
            throw new SelfServeValidationException('Amount is too small.');
        }
        $max = self::effectiveMaxSats($storeId);
        if ($sats > $max) {
            throw new SelfServeValidationException(
                'Amount exceeds the maximum of ' . number_format($max) . ' sats.'
            );
        }
    }
}

/**
 * Thrown for any invalid customer-supplied self-serve input. The message is
 * safe to surface directly to the (untrusted) customer.
 */
class SelfServeValidationException extends Exception {}
