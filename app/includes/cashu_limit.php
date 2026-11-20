<?php
/**
 * CashuPayServer - per-store maximum payment size for the Cashu path.
 *
 * Two columns on the stores table, settable from the Cashu section of the
 * store's Lightning payments card (never from the onboarding wizard):
 *
 *   stores.cashu_max_invoice_sats   the largest payment (in sats) the store's
 *                                   Cashu mint may issue a Lightning invoice
 *                                   for. NULL = unlimited (the default, and
 *                                   the historical behavior).
 *   stores.cashu_max_respect_always "Respect size even if other lightning
 *                                   options fail" (default ON). Decides what
 *                                   happens to a payment ABOVE the maximum:
 *
 *     ON   (BLOCK) the mint never issues the invoice. The walk records a
 *          FAILED mint attempt (same shape as a mint with no liquidity /
 *          no response) and moves on; other Lightning paths and the
 *          on-chain rail still serve. With nothing else payable the
 *          creation fails with a clear error.
 *     OFF  (DEFER) the mint is moved to the END of the Lightning walk for
 *          that payment, so it only issues the invoice when every other
 *          Lightning path has failed. When another path served the
 *          invoice the mint is recorded as SKIPPED for the size.
 *
 * Payments at or below the maximum (and stores with no maximum) are ALLOW:
 * the mint keeps its configured slot in the order.
 *
 * The comparison is on the payment's sat value — the same conversion the
 * Lightning destinations use (ExchangeRates::convertToSats at creation /
 * refresh). An unknown value (<= 0, e.g. a rate lookup failure) can't be
 * compared and is treated as ALLOW so a rate hiccup never changes routing.
 *
 * Deliberately NOT a notification source: a size decision writes attempt-log
 * rows (and an admin-log line when the invoice ends up on-chain only), never
 * an e-mail. Paying an invoice directly with a Cashu ecash token is a
 * different path and is unaffected.
 */

require_once __DIR__ . '/database.php';

class CashuLimit {
    /** The mint keeps its configured slot. */
    public const ALLOW = 'allow';
    /** Above the maximum, respect OFF: the mint goes last in the walk. */
    public const DEFER = 'defer';
    /** Above the maximum, respect ON: the mint is not used. */
    public const BLOCK = 'block';

    /** Largest accepted maximum (21M BTC in sats). */
    public const MAX_SATS_CEILING = 2_100_000_000_000_000;

    /** Attempt-log reason (payer-safe fixed phrase) for a size block. */
    public const REASON_BLOCKED = 'the amount is above the store\'s Cashu maximum';
    /** Attempt-log reason for a deferral where another path served the invoice. */
    public const REASON_DEFERRED = 'the amount is above the store\'s Cashu maximum; tried only after the other Lightning paths';

    /**
     * The effective limit from a store row (as read by Config::getStore).
     *
     * @return array{max_sats: ?int, respect_always: bool}
     */
    public static function fromRow(?array $store): array {
        $raw = $store['cashu_max_invoice_sats'] ?? null;
        $max = ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
        // Column absent (pre-migration row shape in tests) → the default ON.
        $respect = array_key_exists('cashu_max_respect_always', $store ?? [])
            && $store['cashu_max_respect_always'] !== null
            ? ((int)$store['cashu_max_respect_always'] === 1)
            : true;
        return ['max_sats' => $max, 'respect_always' => $respect];
    }

    /** The effective limit for a store id. */
    public static function forStore(string $storeId): array {
        $row = Database::fetchOne(
            "SELECT cashu_max_invoice_sats, cashu_max_respect_always FROM stores WHERE id = ?",
            [$storeId]
        );
        return self::fromRow($row ?: null);
    }

    /**
     * What the Lightning walk does with the mint for a payment of
     * $targetSats under $limit (see class doc): ALLOW, DEFER or BLOCK.
     *
     * @param array{max_sats: ?int, respect_always: bool} $limit
     */
    public static function decide(array $limit, int $targetSats): string {
        $max = $limit['max_sats'] ?? null;
        if ($max === null || $targetSats <= 0 || $targetSats <= $max) {
            return self::ALLOW;
        }
        return !empty($limit['respect_always']) ? self::BLOCK : self::DEFER;
    }

    /**
     * Admin-only detail text for the attempt log: names both figures so the
     * operator can see how far over the limit the payment was.
     */
    public static function detail(string $decision, int $targetSats, array $limit): string {
        $max = (int)($limit['max_sats'] ?? 0);
        $figures = sprintf('payment %s sat, store maximum %s sat', number_format($targetSats), number_format($max));
        if ($decision === self::BLOCK) {
            return 'Cashu mint not used: ' . $figures
                . '; "respect size even if other Lightning options fail" is on';
        }
        return 'Cashu mint deferred to the end of the Lightning walk: ' . $figures
            . '; another Lightning path served the invoice, so the mint was not asked';
    }

    /**
     * Parse the posted maximum. Blank or 0 means unlimited (null); anything
     * else must be a whole number of sats.
     *
     * @throws InvalidArgumentException with the operator-facing message
     */
    public static function parseMaxSats(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            throw new InvalidArgumentException('The Cashu maximum must be a whole number of sats (blank for no limit).');
        }
        if (strlen($raw) > 16 || (int)$raw > self::MAX_SATS_CEILING) {
            throw new InvalidArgumentException('The Cashu maximum is larger than the total bitcoin supply.');
        }
        $sats = (int)$raw;
        return $sats === 0 ? null : $sats;
    }

    /**
     * Persist the limit. Direct UPDATE on purpose — the Config::updateStore
     * allowlist stays tight (same routing as RailOrder::setLnOrder).
     */
    public static function set(string $storeId, ?int $maxSats, bool $respectAlways): void {
        if ($maxSats !== null && ($maxSats <= 0 || $maxSats > self::MAX_SATS_CEILING)) {
            throw new InvalidArgumentException('Invalid Cashu maximum.');
        }
        Database::query(
            "UPDATE stores SET cashu_max_invoice_sats = ?, cashu_max_respect_always = ? WHERE id = ?",
            [$maxSats, $respectAlways ? 1 : 0, $storeId]
        );
    }

    /**
     * Move the mint step to the end of a Lightning walk (DEFER). Steps
     * without a mint come back unchanged.
     *
     * @param array<int, array{kind:string, destinations?:array}> $steps
     * @return array<int, array{kind:string, destinations?:array}>
     */
    public static function deferMintStep(array $steps): array {
        $mint = null;
        $rest = [];
        foreach ($steps as $step) {
            if ($mint === null && ($step['kind'] ?? '') === 'mint') {
                $mint = $step;
                continue;
            }
            $rest[] = $step;
        }
        if ($mint !== null) {
            $rest[] = $mint;
        }
        return $rest;
    }
}
