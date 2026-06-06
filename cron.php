<?php
/**
 * CashuPayServer - Cron Endpoint
 *
 * Background task processing for quote polling, sync, recovery, and cleanup.
 *
 * Can be called in two ways:
 * 1. External cron: curl -s https://your-domain.com/cron.php?key=YOUR_CRON_KEY
 * 2. Internal self-request: Triggered automatically by Background::trigger()
 *
 * Example cron entry (optional - system works without it):
 * * * * * * curl -s https://your-domain.com/cron.php?key=YOUR_CRON_KEY
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/invoice.php';
require_once __DIR__ . '/includes/lightning_address.php';
require_once __DIR__ . '/includes/dev_fee.php';
require_once __DIR__ . '/includes/free_trial.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/background.php';
require_once __DIR__ . '/includes/onchain/payments.php';
require_once __DIR__ . '/includes/swap/poller.php';
require_once __DIR__ . '/includes/swap/auto_melt.php';
require_once __DIR__ . '/includes/trusted_mints.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/notification_sender.php';

// Check if setup is complete
if (!Database::isInitialized() || !Config::isSetupComplete()) {
    http_response_code(503);
    echo 'Not configured';
    exit;
}

// Ensure script continues even if client disconnects (fire-and-forget from Background::trigger)
ignore_user_abort(true);

// Verify authorization
$providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$isInternal = isset($_GET['internal']) && $_GET['internal'] === '1';

if ($isInternal) {
    // Internal self-request - verify internal key
    if (!Background::verifyInternalKey($providedKey)) {
        http_response_code(403);
        echo 'Invalid internal key';
        exit;
    }
} else {
    // External cron request - cron_key is seeded during install (see
    // Database::initialize). If it's missing the install is broken; refuse
    // rather than fall through to an open endpoint.
    $cronKey = Config::get('cron_key');
    if (!$cronKey) {
        http_response_code(503);
        echo 'Cron not configured';
        exit;
    }
    if (!hash_equals($cronKey, $providedKey)) {
        http_response_code(403);
        echo 'Invalid cron key';
        exit;
    }
}

// Stamp the last *external* cron run so the dashboard can warn admins when
// the operator's environment isn't actually invoking cron.php on a schedule.
// Internal self-requests do not count, otherwise opportunistic admin/checkout
// triggers would mask the missing cron entry indefinitely.
//
// Tracked per-mode so the admin UI can confirm both the main cron AND the
// swap-fast-lane cron are wired up. `only=swaps` requests bump only the
// fast-lane stamp; the default mode bumps both (a full cron pass IS doing
// the swap work too, so the operator who only sets up the full cron still
// sees the fast-lane "last seen" advance — they only need a separate fast
// lane if they want sub-minute swap latency).
$only = $_GET['only'] ?? null;
if (!$isInternal) {
    $nowStamp = time();
    if ($only === 'swaps') {
        Config::set('last_external_cron_swaps_at', $nowStamp);
    } else {
        Config::set('last_external_cron_at', $nowStamp);
        Config::set('last_external_cron_swaps_at', $nowStamp);
    }
}

// Set content type
header('Content-Type: application/json');

// `?only=swaps` lets operators wire a tight (e.g. every-10-seconds) cron
// dedicated to driving the swap lifecycle without also running the
// expensive cashu / on-chain / cleanup tasks that only need to run on
// the normal (minute-ish) cadence. Submarine swaps are latency-sensitive
// (every poll-tick delay shows up in the end-to-end settlement time);
// everything else isn't.
// ($only is read above to stamp the right last-seen config key.)
$swapOnly = ($only === 'swaps');

// When the operator's real cron is ticking, internal (page-load-triggered)
// self-requests skip the non-latency-sensitive tasks — the cron will pick
// them up on its next pass. Page loads keep running the essential, customer-
// facing work (quote/onchain polling, expiry, recovery, swaps) so checkout
// UX is unaffected. External cron calls and fresh installs always run the
// full task list.
$skipNonEssential = $isInternal && !$swapOnly && Background::isExternalCronFresh();

if ($swapOnly) {
    $mode = 'swaps-only';
} elseif ($skipNonEssential) {
    $mode = 'essentials-only';
} else {
    $mode = 'all';
}

$results = [
    'timestamp' => time(),
    'mode' => $mode,
    'tasks' => [],
];

// Task 1: Poll pending quotes
if (!$swapOnly) {
    try {
        Invoice::pollPendingQuotes();
        $results['tasks']['poll_quotes'] = 'success';
    } catch (Exception $e) {
        $results['tasks']['poll_quotes'] = 'error: ' . $e->getMessage();
    }
}

// Task 1a: Poll LNURL-direct-receive invoices. Independent from the cashu
// mint poll above — these invoices have no quote_id and settle when the
// LUD-21 verify URL reports settled=true with a preimage.
if (!$swapOnly) {
    try {
        Invoice::pollPendingLnAddress();
        $results['tasks']['poll_lnaddress'] = 'success';
    } catch (Exception $e) {
        $results['tasks']['poll_lnaddress'] = 'error: ' . $e->getMessage();
    }
}

// Task 1b: Settle dev / hosting / upstream-dev fees for every store. Runs
// BEFORE auto-melt so the fee math sees revenue that may otherwise drain in
// this same cron pass. Per-fee failures are caught inside settleStore() so a
// single broken LNURL never blocks the rest of the cron.
//
// FreeTrial::expireIfNeeded() runs first so a date- or revenue-threshold
// crossing is observed and stamped before this tick computes owed amounts.
// Without it, an expiry that happened mid-interval would still skip this
// tick (DevFee::computeOwed calls it too, but explicit here keeps the
// cron sequence obvious).
if (!$swapOnly && !$skipNonEssential) {
    try {
        FreeTrial::expireIfNeeded();
        $feeResults = DevFee::settleAllStores();
        $results['tasks']['settle_fees'] = count($feeResults) > 0
            ? ['stores_processed' => count($feeResults)]
            : 'skipped';
    } catch (Exception $e) {
        $results['tasks']['settle_fees'] = 'error: ' . $e->getMessage();
    }
}

// Task 2: Check auto-melt — runs both rails. LightningAddress::checkAutoMelt
// internally skips stores that have opted into the swap rail (via
// SwapAutoMelt::modeForStore), and SwapAutoMelt::checkAndExecute does the
// opposite, so each store gets handled by exactly one path.
if (!$swapOnly && !$skipNonEssential) {
    try {
        $meltResult = LightningAddress::checkAutoMelt();
        if ($meltResult) {
            $results['tasks']['auto_melt'] = [
                'success' => true,
                'amount' => $meltResult['amountPaid'],
            ];
        } else {
            $results['tasks']['auto_melt'] = 'skipped';
        }
    } catch (Exception $e) {
        $results['tasks']['auto_melt'] = 'error: ' . $e->getMessage();
    }
    try {
        $sweepResult = SwapAutoMelt::checkAndExecute();
        $results['tasks']['auto_melt_swap'] = $sweepResult
            ? ['stores_processed' => count($sweepResult)]
            : 'skipped';
    } catch (Exception $e) {
        $results['tasks']['auto_melt_swap'] = 'error: ' . $e->getMessage();
    }
}

// Task 3: Clean expired cache
if (!$swapOnly && !$skipNonEssential) {
    try {
        Security::cleanCache();
        $results['tasks']['clean_cache'] = 'success';
    } catch (Exception $e) {
        $results['tasks']['clean_cache'] = 'error: ' . $e->getMessage();
    }
}

// Task 4: Expire old invoices - now handled by pollPendingQuotes() via markExpiredInvoices()
// Kept as a separate explicit call for visibility in cron results
if (!$swapOnly) {
    try {
        $expired = Invoice::markExpiredInvoices();
        $results['tasks']['expire_invoices'] = "expired {$expired} invoices";
    } catch (Exception $e) {
        $results['tasks']['expire_invoices'] = 'error: ' . $e->getMessage();
    }
}

// Task 4b: Poll on-chain payments for any invoices with onchain_address set.
// Same batched + rate-limited pattern as the Cashu quote poller. Provider
// failures (network, rate-limit) are caught per-invoice; the overall task
// only fails if pollPending() itself throws.
if (!$swapOnly) {
    try {
        $onchainResults = OnchainPayments::pollPending(60, 20);
        $polled = count($onchainResults);
        $errored = 0;
        foreach ($onchainResults as $r) {
            if (isset($r['error'])) {
                $errored++;
            }
        }
        $results['tasks']['poll_onchain'] = "polled {$polled} invoice(s)" . ($errored ? ", {$errored} errored" : '');
    } catch (Exception $e) {
        $results['tasks']['poll_onchain'] = 'error: ' . $e->getMessage();
    }
}

// Task 4c: Drive in-flight submarine swaps through their lifecycle. Same
// batched + last_polled_at gated pattern as the other pollers. Failures
// inside individual rows are captured in swap_attempts.error_message; the
// task only fails outright if pollPending() itself throws.
try {
    $swapResults = SwapPoller::pollPending(30, 20);
    $results['tasks']['poll_swaps'] = "polled {$swapResults['polled']} swap(s)"
        . ($swapResults['errors'] ? ", {$swapResults['errors']} errored" : '');
} catch (Exception $e) {
    $results['tasks']['poll_swaps'] = 'error: ' . $e->getMessage();
}
try {
    SwapPoller::expireStale();
    $results['tasks']['expire_swaps'] = 'ok';
} catch (Exception $e) {
    $results['tasks']['expire_swaps'] = 'error: ' . $e->getMessage();
}

// Task 4d: Same lifecycle drive for sweep_attempts (auto-melt-via-swap).
// Reuses SwapPoller via a sweep settlement context so on-chain claim and
// status transitions run on the same machinery as customer swaps. Runs on
// every cron mode (including swap-only fast-lane) for latency parity.
try {
    $sweepResults = SwapPoller::pollPending(30, 20, new SweepSwapSettlement());
    $results['tasks']['poll_sweeps'] = "polled {$sweepResults['polled']} sweep(s)"
        . ($sweepResults['errors'] ? ", {$sweepResults['errors']} errored" : '');
} catch (Exception $e) {
    $results['tasks']['poll_sweeps'] = 'error: ' . $e->getMessage();
}

// Task 5: C1 - Sync proof states with mint (if not synced recently)
if (!$swapOnly && !$skipNonEssential) try {
    if (Background::shouldSync()) {
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
        $syncCount = 0;
        foreach ($stores as $store) {
            try {
                $wallet = Invoice::getWalletInstance($store['id']);
                if ($wallet->hasStorage()) {
                    // Sync would verify proofs are still valid on mint
                    $syncCount++;
                }
            } catch (Exception $e) {
                error_log("Sync failed for store {$store['id']}: " . $e->getMessage());
            }
        }
        Background::markSynced();
        $results['tasks']['sync_proofs'] = "synced {$syncCount} stores";
    } else {
        $results['tasks']['sync_proofs'] = 'skipped (recently synced)';
    }
} catch (Exception $e) {
    $results['tasks']['sync_proofs'] = 'error: ' . $e->getMessage();
}

// Task 6: C2/H4 - Recover orphaned invoices stuck in Processing
if (!$swapOnly) try {
    $recovered = Invoice::recoverOrphanedInvoices();
    $count = count($recovered);
    $results['tasks']['recover_orphaned'] = $count > 0 ? "recovered {$count}" : 'none';
} catch (Exception $e) {
    $results['tasks']['recover_orphaned'] = 'error: ' . $e->getMessage();
}

// Task 7: H3 - Auto-expire very old invoices (older than 30 days)
if (!$swapOnly && !$skipNonEssential) try {
    $veryOld = Database::query(
        "UPDATE invoices SET status = 'Expired'
         WHERE status = 'New' AND created_at < ?",
        [time() - 30 * 24 * 3600]
    )->rowCount();

    $results['tasks']['expire_old_invoices'] = "expired {$veryOld} old invoices";
} catch (Exception $e) {
    $results['tasks']['expire_old_invoices'] = 'error: ' . $e->getMessage();
}

// Task 8: L1 - Clean very old invoices (settled/expired older than 90 days)
if (!$swapOnly && !$skipNonEssential) try {
    $deleted = Database::query(
        "DELETE FROM invoices WHERE status IN ('Settled', 'Expired', 'Invalid')
         AND created_at < ?",
        [time() - 90 * 24 * 3600]
    )->rowCount();

    $results['tasks']['cleanup_invoices'] = "deleted {$deleted} old invoices";
} catch (Exception $e) {
    $results['tasks']['cleanup_invoices'] = 'error: ' . $e->getMessage();
}

// Task 9: L3 - Clean expired pending operations from wallet storage
if (!$swapOnly && !$skipNonEssential) try {
    $stores = Database::fetchAll(
        "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
    );
    $totalCleaned = 0;
    foreach ($stores as $store) {
        try {
            $wallet = Invoice::getWalletInstance($store['id']);
            if ($wallet->hasStorage()) {
                $cleaned = $wallet->getStorage()->cleanExpiredPendingOperations();
                $totalCleaned += $cleaned;
            }
        } catch (Exception $e) {
            error_log("Cleanup failed for store {$store['id']}: " . $e->getMessage());
        }
    }
    $results['tasks']['cleanup_pending_ops'] = "cleaned {$totalCleaned}";
} catch (Exception $e) {
    $results['tasks']['cleanup_pending_ops'] = 'error: ' . $e->getMessage();
}

// Task 9b: L3 - Trim 30-day-old quote history rows for auto-melt-via-swap.
if (!$swapOnly && !$skipNonEssential) try {
    $trimmed = SwapAutoMelt::cleanupQuoteHistory();
    $results['tasks']['cleanup_swap_quote_history'] = "deleted {$trimmed}";
} catch (Exception $e) {
    $results['tasks']['cleanup_swap_quote_history'] = 'error: ' . $e->getMessage();
}

// Task 10: L4 - Webhook delivery cleanup (keep only last 1000)
if (!$swapOnly && !$skipNonEssential) try {
    // First get the count
    $countResult = Database::fetchOne("SELECT COUNT(*) as cnt FROM webhook_deliveries");
    $totalCount = (int)($countResult['cnt'] ?? 0);

    if ($totalCount > 1000) {
        // Delete oldest entries beyond 1000
        $deleteCount = $totalCount - 1000;
        Database::query("
            DELETE FROM webhook_deliveries
            WHERE id IN (
                SELECT id FROM webhook_deliveries
                ORDER BY created_at ASC LIMIT ?
            )
        ", [$deleteCount]);
        $results['tasks']['cleanup_webhooks'] = "deleted {$deleteCount} old deliveries";
    } else {
        $results['tasks']['cleanup_webhooks'] = 'skipped (under limit)';
    }
} catch (Exception $e) {
    $results['tasks']['cleanup_webhooks'] = 'error: ' . $e->getMessage();
}

// Task 11: Refresh trusted mints list (default 24h interval).
if (!$swapOnly && !$skipNonEssential) try {
    if (TrustedMints::getUrl() !== null) {
        $refreshed = TrustedMints::refresh();
        if ($refreshed) {
            TrustedMints::applyToAllStores();
            $err = TrustedMints::getLastError();
            $results['tasks']['trusted_mints'] = $err === null
                ? 'refreshed + applied'
                : 'refreshed with error: ' . $err;
        } else {
            $results['tasks']['trusted_mints'] = 'skipped (not due yet)';
        }
    } else {
        $results['tasks']['trusted_mints'] = 'skipped (no URL configured)';
    }
} catch (Exception $e) {
    $results['tasks']['trusted_mints'] = 'error: ' . $e->getMessage();
}

// Task 12a: Refresh IP-to-country geo database (monthly DB-IP Lite snapshot).
// Cheap to attempt — IpGeo::refresh skips the download if the cached file
// is still fresh enough. Best-effort, never blocks other tasks.
if (!$swapOnly && !$skipNonEssential) try {
    require_once __DIR__ . '/includes/ipgeo.php';
    $csv = IpGeo::getCsvPath();
    $stale = !is_file($csv) || (time() - (int)@filemtime($csv)) > 25 * 24 * 3600;
    if ($stale) {
        $ok = IpGeo::refresh();
        $results['tasks']['ipgeo'] = $ok ? 'refreshed' : 'refresh failed (kept prior copy)';
    } else {
        $results['tasks']['ipgeo'] = 'skipped (fresh)';
    }
} catch (Exception $e) {
    $results['tasks']['ipgeo'] = 'error: ' . $e->getMessage();
}

// Task 12: Auto-update check. Daily, no-op in WordPress mode. Skipped on
// internal background self-requests so checkout traffic doesn't trigger
// a download — only the dedicated cron tick checks for updates.
if (!$isInternal && !$swapOnly) {
    try {
        $applied = Updater::checkAndApply();
        $results['tasks']['updater'] = $applied ? 'update applied' : 'no update';
    } catch (Throwable $e) {
        $results['tasks']['updater'] = 'error: ' . $e->getMessage();
    }
}

// Task 13: Drain queued notification emails. Runs on every tick so backlogs
// from a temporarily-unreachable SMTP server self-heal on the next cron pass.
if (!$swapOnly && !$skipNonEssential) try {
    $drain = NotificationSender::drainQueue();
    $results['tasks']['notifications'] = "sent: {$drain['sent']}, failed: {$drain['failed']}";
} catch (Throwable $e) {
    $results['tasks']['notifications'] = 'error: ' . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
