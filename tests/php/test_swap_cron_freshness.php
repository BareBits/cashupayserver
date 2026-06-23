<?php
/**
 * Background swap-cron-liveness gate: cronFreshForSwaps() / swapCronStaleness()
 * over the two external-cron stamps. Reverse swaps are claimed only from cron,
 * so a stale heartbeat must report "not fresh" and surface staleness detail.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/background.php';

$threshold = Background::SWAP_CRON_STALE_THRESHOLD_SECS;
$now = time();

// 1. Never run — neither stamp set → not fresh, staleness reports "never".
Config::delete('last_external_cron_at');
Config::delete('last_external_cron_swaps_at');
assert_false(Background::cronFreshForSwaps(), 'never-run cron is not fresh');
assert_eq(0, Background::lastExternalCronForSwaps(), 'last-run is 0 when never run');
$s = Background::swapCronStaleness();
assert_not_null($s, 'never-run → staleness payload present');
assert_eq(null, $s['lastExternalCronAt'], 'never-run lastExternalCronAt is null');
assert_eq(null, $s['secondsSince'], 'never-run secondsSince is null');
assert_eq($threshold, $s['thresholdSecs'], 'threshold echoed back');

// 2. Main cron just ran → fresh, no staleness payload.
Config::set('last_external_cron_at', $now - 30);
assert_true(Background::cronFreshForSwaps(), 'recent main cron → fresh');
assert_null(Background::swapCronStaleness(), 'fresh → no staleness payload');

// 3. Main cron stale, fast-lane fresh → freshest-of-two wins → fresh.
Config::set('last_external_cron_at', $now - ($threshold + 600));
Config::set('last_external_cron_swaps_at', $now - 45);
assert_true(Background::cronFreshForSwaps(), 'fresh swap fast-lane keeps swaps alive');
assert_eq($now - 45, Background::lastExternalCronForSwaps(), 'max() picks the fast-lane stamp');

// 4. Both stale → not fresh, staleness reports seconds since the freshest.
Config::set('last_external_cron_at', $now - ($threshold + 600));
Config::set('last_external_cron_swaps_at', $now - ($threshold + 120));
assert_false(Background::cronFreshForSwaps(), 'both stamps stale → not fresh');
$s = Background::swapCronStaleness();
assert_not_null($s, 'both stale → staleness payload present');
// Freshest is the swaps stamp at threshold+120 ago.
assert_eq($now - ($threshold + 120), $s['lastExternalCronAt'], 'reports freshest stamp');
assert_true($s['secondsSince'] >= $threshold, 'secondsSince exceeds threshold when stale');

// 5. Exactly at the threshold boundary is treated as stale (strict <).
Config::delete('last_external_cron_swaps_at');
Config::set('last_external_cron_at', $now - $threshold);
assert_false(Background::cronFreshForSwaps(), 'exactly at threshold → stale (strict <)');

// 6. Just inside the threshold is fresh.
Config::set('last_external_cron_at', $now - ($threshold - 5));
assert_true(Background::cronFreshForSwaps(), 'just inside threshold → fresh');

echo "test_swap_cron_freshness: ok\n";
