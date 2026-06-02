<?php
/**
 * CashuPayServer — User Configuration (TEMPLATE)
 * ===============================================
 *
 * Deployment-time settings, as an alternative to environment variables.
 *
 * USAGE
 * -----
 * This file is a *template*. To customize a deployment:
 *
 *   cp user_config.example.php user_config.php
 *
 * then edit `user_config.php` (which is gitignored — your edits won't be
 * touched by upstream pulls). Both files live at the project root, and the
 * loader looks specifically for `user_config.php`.
 *
 * Each setting below is a PHP `define()` line, commented out by default.
 * To activate a setting in your copy:
 *   1. Uncomment the `define()` line
 *   2. Edit the value
 *   3. Restart PHP-FPM / the web server so the constant is picked up
 *
 * Precedence: any value defined here overrides the equivalent environment
 * variable of the same name. A commented-out line falls back to the env
 * var (and then to the built-in default if neither is set).
 *
 * Some settings are read once on first migration and seeded into the
 * database (the free trial below is one of these). For those, editing
 * this file AFTER first install has no effect — the seeded values are
 * authoritative. To re-seed, delete both the relevant `config` rows AND
 * the `free_trial_seeded` marker row, then restart.
 *
 * For backwards compatibility, `includes/config.local.php` continues to
 * work for the CASHUPAY_DATA_DIR override (the only setting that file
 * historically held); new settings should go here instead.
 */

// =============================================================================
// FREE TRIAL  (seeded once on first migration)
// =============================================================================
// While a free trial is active, all three platform fees are waived:
//   - upstream dev fee (0.5%)
//   - dev fee (2%)
//   - per-store hosting fee
// Network fees (Lightning routing, on-chain miner fees) are real sats spent
// on melts and cannot be waived.
//
// Set EITHER threshold below, or BOTH (whichever fires first ends the trial).
// Both unset → no trial at all.
//
// On expiry, the platform fees apply only to revenue earned AFTER the expiry
// instant — revenue accrued during the trial is never retroactively charged.

// CASHUPAY_FREE_TRIAL_UNTIL — calendar end of the trial.
// Accepts either:
//   - A unix timestamp (integer):    1893456000
//   - Any strtotime()-parseable date string:
//       '2027-01-01'
//       '2026-12-31 23:59:59 UTC'
//       '+90 days'        (evaluated at first-migration time, not per request)
// A date in the past at seed time is silently treated as "no trial".
//
// define('CASHUPAY_FREE_TRIAL_UNTIL', '2027-01-01');

// CASHUPAY_FREE_TRIAL_REVENUE_SATS — revenue cap, in sats, summed across
// all stores in the deployment. When cumulative paid-invoice sats reach
// this value, the trial ends.
// Must be a positive integer; zero or negative is silently treated as
// "no trial".
//
// define('CASHUPAY_FREE_TRIAL_REVENUE_SATS', 500000);
