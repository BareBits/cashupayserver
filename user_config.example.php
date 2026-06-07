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

// =============================================================================
// AUTO-UPDATE CHANNEL
// =============================================================================
// Which release channel this install tracks. The auto-updater (run from
// cron.php) fetches the latest build attached to the matching channel-* tag
// on https://github.com/BareBits/cashupayserver and overlays it on this
// install, preserving data/ and user_config.php.
//
// Values:
//   'main'    — stable. Receives commits merged to main.
//   'testing' — pre-release. Receives commits pushed to the testing branch.
//
// This sets the deployment-time default. The admin can override it at
// runtime from the Settings page; once overridden, the database value wins.
//
// define('CASHUPAY_UPDATE_CHANNEL', 'main');

// CASHUPAY_AUTO_UPDATE_ENABLED — explicit opt-in for the auto-updater.
// Defaults to false: fresh installs do NOT auto-update. To enable cron-driven
// updates on this install, set this constant to true (or set the env var of
// the same name to a non-empty, non-"0" string). The updater respects the
// CASHUPAY_UPDATE_CHANNEL setting above when fetching.
//
// Operators who don't want auto-update can leave this alone, or run the
// WordPress plugin build (auto-update is skipped in WP mode regardless).
//
// define('CASHUPAY_AUTO_UPDATE_ENABLED', true);

// =============================================================================
// EMAIL NOTIFICATIONS — SMTP (optional)
// =============================================================================
// Outbound email is opt-in: notifications stay off until the admin enables
// them in the Settings UI. The SMTP settings below are only consulted if
// notifications are turned on for the deployment.
//
// If CASHUPAY_SMTP_HOST is unset, the sender falls back to PHP's built-in
// mail() function, which relies on a working local MTA (sendmail / postfix /
// msmtp). Many shared hosts do not have one, so configuring SMTP explicitly
// is strongly recommended for reliable delivery.
//
// CASHUPAY_SMTP_HOST          SMTP server hostname (e.g. 'smtp.sendgrid.net').
//                             Unset → use PHP mail().
// CASHUPAY_SMTP_PORT          SMTP server port (typical: 587 for STARTTLS,
//                             465 for implicit TLS, 25 for unauthenticated).
// CASHUPAY_SMTP_USERNAME      Auth username. Unset → no auth.
// CASHUPAY_SMTP_PASSWORD      Auth password. Treat this file as a secret.
// CASHUPAY_SMTP_ENCRYPTION    'tls' (STARTTLS, port 587), 'ssl' (implicit
//                             TLS, port 465), or 'none' (cleartext, dev only).
// CASHUPAY_SMTP_FROM_ADDRESS  Envelope + header From address. REQUIRED to send.
// CASHUPAY_SMTP_FROM_NAME     Display name for the From header (default:
//                             'CashuPayServer').
//
// define('CASHUPAY_SMTP_HOST', 'smtp.example.com');
// define('CASHUPAY_SMTP_PORT', 587);
// define('CASHUPAY_SMTP_USERNAME', 'apikey');
// define('CASHUPAY_SMTP_PASSWORD', 'replace-me');
// define('CASHUPAY_SMTP_ENCRYPTION', 'tls');
// define('CASHUPAY_SMTP_FROM_ADDRESS', 'notifications@example.com');
// define('CASHUPAY_SMTP_FROM_NAME', 'CashuPayServer');

// =============================================================================
// AUTO-MELT VIA SUBMARINE SWAP
// =============================================================================
// When a store opts into "Auto-withdraw via submarine swap" (instead of the
// Lightning-address path), the cron sweeps the mint balance through a
// reverse submarine swap to the store's on-chain xpub.
//
// Two cost gates protect against sweeping at unfavourable rates. A sweep
// runs only when BOTH are satisfied:
//   1. Mint balance ≥ CASHUPAY_AUTO_MELT_SWAP_MIN_SATS (cheap pre-flight; we
//      don't even fetch a quote below this).
//   2. Best available swap-provider quote's total cost (percent fee +
//      lockup miner fee + claim miner fee estimate) is ≤
//      CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT % of the amount being swept.
//
// Both are display-only knobs in the admin UI: defaults below drive
// behaviour, the UI just shows the active values so operators can size
// their balances. To change them, edit this file and restart PHP-FPM.

// CASHUPAY_AUTO_MELT_SWAP_MIN_SATS — static floor in satoshis. Defaults to
// 5000 (~$5 at typical rates) which covers the swap-provider minimums and
// is the smallest amount where the percent-fee gate has any realistic
// chance of being satisfied.
//
// define('CASHUPAY_AUTO_MELT_SWAP_MIN_SATS', 5000);

// CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT — percent cap on the swap-provider's
// total fees relative to the amount being swept. Defaults to 1.0 (i.e.
// total swap cost must not exceed 1% of the sweep amount). Accepts a
// float; values < 0.1 will almost never be satisfiable in practice.
//
// define('CASHUPAY_AUTO_MELT_SWAP_MAX_FEE_PCT', 1.0);

// CASHUPAY_STRIKE_URL — destination for the "get a free lightning address"
// Strike links shown in the auto-withdrawal settings. Defaults to
// 'http://strike.me'. Override to point merchants at a referral/localized URL.
//
// define('CASHUPAY_STRIKE_URL', 'http://strike.me');

// LNURL DIRECT-RECEIVE
// --------------------
// When a store has an auto-withdraw Lightning address configured and the
// host supports LUD-21 verify URLs, incoming Lightning payments route
// directly to that address instead of through the cashu mint. An invoice
// smaller than the accumulated upstream/dev/hosting fees the store owes
// routes via the mint instead, so the resulting balance can cover the
// owed fees before the merchant payout.
//
// LNURL_RECEIVE_PROBE_TIMEOUT_SEC (default 5): wall-clock budget for the
// per-invoice LNURL probe (well-known + callback). Slower hosts cause
// the probe to fail and the invoice falls back to mint/swap.
//
// define('LNURL_RECEIVE_PROBE_TIMEOUT_SEC', 5);
