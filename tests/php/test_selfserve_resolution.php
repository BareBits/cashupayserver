<?php
/**
 * Self-serve enable resolution (tri-state per-store override over the site
 * default, gated on the store being payment-capable) and max-sats resolution
 * (per-store override over the site value over the built-in default).
 * See includes/selfserve.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/selfserve.php';

// A payment-capable store (has a mint + seed phrase).
$paid = 'store_ss_paid';
make_store($paid, 'https://mint.example');

// A store with no payment method configured (mint_url NULL).
$bare = 'store_ss_bare';
make_store($bare);

// ---- Enable resolution ----

// Default: site disabled → off everywhere.
assert_false(SelfServe::siteEnabled(), 'site default off');
assert_false(SelfServe::isEnabledForStore($paid), 'off when site off + inherit');

// Site on → capable store inherits on.
SelfServe::setSiteEnabled(true);
assert_true(SelfServe::isEnabledForStore($paid), 'inherits site on');

// Per-store force off beats site on.
SelfServe::setStoreOverride($paid, SelfServe::FORCE_OFF);
assert_eq(SelfServe::FORCE_OFF, SelfServe::storeOverride($paid), 'override persisted (off)');
assert_false(SelfServe::isEnabledForStore($paid), 'force off beats site on');

// Per-store force on beats site off.
SelfServe::setSiteEnabled(false);
SelfServe::setStoreOverride($paid, SelfServe::FORCE_ON);
assert_true(SelfServe::isEnabledForStore($paid), 'force on beats site off');

// Back to inherit → follows the (off) site default again.
SelfServe::setStoreOverride($paid, SelfServe::INHERIT);
assert_false(SelfServe::isEnabledForStore($paid), 'inherit follows site off again');

// ---- Payment-capability gate ----

// A bare store is never enabled, even forced on with the site on.
SelfServe::setSiteEnabled(true);
SelfServe::setStoreOverride($bare, SelfServe::FORCE_ON);
assert_false(SelfServe::isEnabledForStore($bare), 'bare store never enabled (no payment method)');

// Unknown store id → false, not an error.
assert_false(SelfServe::isEnabledForStore('store_does_not_exist'), 'unknown store false');

// Invalid tri-state is rejected.
$threw = false;
try { SelfServe::setStoreOverride($paid, 7); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'invalid tri-state rejected');

// ---- Max-sats resolution ----

// Default built-in when nothing set.
assert_eq(SelfServe::DEFAULT_MAX_SATS, SelfServe::siteMaxSats(), 'site max defaults to built-in');
assert_eq(SelfServe::DEFAULT_MAX_SATS, SelfServe::effectiveMaxSats($paid), 'effective max = built-in default');

// Site override.
SelfServe::setSiteMaxSats(123456);
assert_eq(123456, SelfServe::siteMaxSats(), 'site max set');
assert_eq(123456, SelfServe::effectiveMaxSats($paid), 'effective = site max when no store override');

// Per-store override wins.
SelfServe::setStoreMaxSats($paid, 1000);
assert_eq(1000, SelfServe::storeMaxSats($paid), 'store max override persisted');
assert_eq(1000, SelfServe::effectiveMaxSats($paid), 'effective = store override');

// Clearing the store override falls back to the site value.
SelfServe::setStoreMaxSats($paid, null);
assert_null(SelfServe::storeMaxSats($paid), 'store max cleared');
assert_eq(123456, SelfServe::effectiveMaxSats($paid), 'effective back to site max');

// Clearing the site value falls back to the built-in default.
SelfServe::setSiteMaxSats(null);
assert_eq(SelfServe::DEFAULT_MAX_SATS, SelfServe::effectiveMaxSats($paid), 'effective back to built-in');

// Non-positive maxes are rejected.
$threw = false;
try { SelfServe::setSiteMaxSats(0); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'zero site max rejected');
$threw = false;
try { SelfServe::setStoreMaxSats($paid, -5); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'negative store max rejected');

echo "test_selfserve_resolution: ok\n";
