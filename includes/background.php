<?php
/**
 * CashuPayServer - Background Task System
 *
 * Non-blocking background task triggering for shared hosting without cron.
 * Background tasks run opportunistically via self-requests.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/urls.php';

class Background {
    /**
     * Trigger background processing without blocking the current request
     *
     * Fires a non-blocking self-request to cron.php to process background tasks.
     * Uses a short timeout (100ms) so the calling request doesn't wait.
     */
    public static function trigger(): void {
        // Internal key travels in a header (not the query string) so it
        // doesn't leak through webserver access logs.
        $url = Urls::cron() . '?internal=1';

        // Fire-and-forget curl (100ms timeout - enough for localhost self-request)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_SSL_VERIFYPEER => false, // For local development
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'X-CRON-KEY: ' . self::getInternalKey(),
            ],
        ]);
        @curl_exec($ch);
        // Note: curl_close() is a no-op since PHP 8.0, handle is auto-closed
    }

    /**
     * Get internal key for self-calls (prevents abuse)
     *
     * This key is auto-generated and stored in config.
     * It's used to authenticate internal background requests.
     */
    public static function getInternalKey(): string {
        $key = Config::get('internal_background_key');
        if (!$key) {
            $key = bin2hex(random_bytes(16));
            Config::set('internal_background_key', $key);
        }
        return $key;
    }

    /**
     * Verify an internal request key
     */
    public static function verifyInternalKey(string $providedKey): bool {
        $storedKey = Config::get('internal_background_key');
        if (!$storedKey) {
            return false;
        }
        return hash_equals($storedKey, $providedKey);
    }

    /**
     * Check if proof sync should be performed
     *
     * Returns true if sync hasn't been done in the last 5 minutes.
     */
    public static function shouldSync(): bool {
        $lastSync = Config::get('last_proof_sync', 0);
        return (time() - $lastSync) > 300; // 5 minutes
    }

    // ========================================================================
    // External-cron staleness detection
    //
    // External cron (the operator's `* * * * * curl /cron.php` entry) is
    // optional but recommended — it makes invoice polling, fee settlement,
    // and auto-melt much more responsive than the opportunistic admin /
    // checkout triggers we fall back to. cron.php stamps
    // `last_external_cron_at` on every non-internal invocation; the dashboard
    // checks the gap here. Fresh installs are grandfathered for 24h so
    // operators don't see the warning before they've had a chance to set
    // cron up.
    // ========================================================================

    public const CRON_STALE_THRESHOLD_SECS = 86400;

    // Window during which a recent `last_external_cron_at` stamp is taken as
    // proof that the operator's real cron is wired up and ticking. When an
    // internal (page-load-triggered) self-request lands inside this window,
    // cron.php runs only the latency-sensitive "essential" tasks and lets the
    // real cron pick up the housekeeping next tick. One hour is generous
    // enough that a cron with a long cadence (or a transient miss) doesn't
    // accidentally fall back to running everything on every page load.
    public const EXTERNAL_CRON_FRESH_THRESHOLD_SECS = 3600;

    /**
     * True when `last_external_cron_at` was stamped within the fresh window.
     * Used by cron.php to decide whether to skip non-essential tasks on an
     * internal self-request. Fresh installs (stamp never set) return false so
     * the opportunistic page-load triggers keep doing the full work until the
     * operator's cron proves itself.
     */
    public static function isExternalCronFresh(): bool {
        $lastExternal = (int) Config::get('last_external_cron_at', 0);
        if ($lastExternal === 0) {
            return false;
        }
        return (time() - $lastExternal) < self::EXTERNAL_CRON_FRESH_THRESHOLD_SECS;
    }

    /**
     * Return the cron-staleness warning state for the dashboard, or null
     * if the warning should not be shown (fresh install, recent external
     * cron run, or the operator has dismissed it since the last real run).
     */
    public static function cronStaleWarning(): ?array {
        $now = time();
        $installedAt = (int) Config::get('installed_at', 0);
        if ($installedAt === 0 || ($now - $installedAt) < self::CRON_STALE_THRESHOLD_SECS) {
            return null;
        }

        $lastExternal = (int) Config::get('last_external_cron_at', 0);
        if ($lastExternal > 0 && ($now - $lastExternal) < self::CRON_STALE_THRESHOLD_SECS) {
            return null;
        }

        // Dismissal is sticky only until the next real external cron run —
        // i.e. once a fresh `last_external_cron_at` arrives that is newer
        // than `cron_warning_dismissed_at`, the dismissal is implicitly
        // cleared and the warning can fire again on the next stale window.
        $dismissedAt = (int) Config::get('cron_warning_dismissed_at', 0);
        if ($dismissedAt > 0 && $dismissedAt >= $lastExternal) {
            return null;
        }

        return [
            'lastExternalCronAt' => $lastExternal > 0 ? $lastExternal : null,
            'thresholdSecs' => self::CRON_STALE_THRESHOLD_SECS,
        ];
    }

    /**
     * Record the operator's dismissal of the stale-cron warning. The
     * dismissal expires automatically when the next external cron call
     * lands (see cronStaleWarning()).
     */
    public static function dismissCronWarning(): void {
        Config::set('cron_warning_dismissed_at', time());
    }

    /**
     * Mark proof sync as completed
     */
    public static function markSynced(): void {
        Config::set('last_proof_sync', time());
    }

    /**
     * Get time since last sync in seconds
     */
    public static function getTimeSinceLastSync(): int {
        $lastSync = Config::get('last_proof_sync', 0);
        return time() - $lastSync;
    }

    // ========================================================================
    // Submarine-swap cron-liveness gate
    //
    // Reverse submarine swaps lock customer funds in an on-chain HTLC that
    // payserver can only recover by broadcasting a preimage claim before the
    // provider's timeout — and that claim is fired exclusively from cron
    // (SwapPoller::pollPending, run in every cron mode). The refund key belongs
    // to the provider, so a missed claim is an unrecoverable loss of funds the
    // customer has already paid. If the operator's external cron is not
    // ticking we must NOT hand out new swap invoices.
    //
    // Liveness is judged from the freshest of the two external-cron stamps
    // (`last_external_cron_at` from the main cron, `last_external_cron_swaps_at`
    // from the optional fast-lane). Both are written only on real external
    // invocations — internal page-load self-requests never stamp them — so a
    // fresh stamp is genuine proof the operator's cron is alive. The threshold
    // is deliberately well inside the provider's multi-hour on-chain timeout:
    // it stops new doomed invoices early while leaving any already-locked swap
    // ample headroom to be claimed once cron is restored.
    // ========================================================================

    public const SWAP_CRON_STALE_THRESHOLD_SECS = 3600; // 60 minutes

    /**
     * Unix timestamp of the most recent external cron run relevant to swaps
     * (max of the main and swap-fast-lane stamps), or 0 if neither has run.
     */
    public static function lastExternalCronForSwaps(): int {
        return max(
            (int) Config::get('last_external_cron_at', 0),
            (int) Config::get('last_external_cron_swaps_at', 0)
        );
    }

    /**
     * True when external cron has run recently enough that we can trust a swap
     * we create now will be claimed. False when it has never run or is stale.
     */
    public static function cronFreshForSwaps(): bool {
        $last = self::lastExternalCronForSwaps();
        if ($last === 0) {
            return false;
        }
        return (time() - $last) < self::SWAP_CRON_STALE_THRESHOLD_SECS;
    }

    /**
     * Staleness detail for the settings UI, or null when cron is fresh.
     *
     * @return array{lastExternalCronAt: ?int, secondsSince: ?int, thresholdSecs: int}|null
     */
    public static function swapCronStaleness(): ?array {
        if (self::cronFreshForSwaps()) {
            return null;
        }
        $last = self::lastExternalCronForSwaps();
        return [
            'lastExternalCronAt' => $last > 0 ? $last : null,
            'secondsSince'       => $last > 0 ? (time() - $last) : null,
            'thresholdSecs'      => self::SWAP_CRON_STALE_THRESHOLD_SECS,
        ];
    }
}
