<?php
/**
 * Per-store strict-no-mint-fallback resolution.
 *
 * The onboarding wizard sets stores.strict_no_mint_fallback to FORCE_ON when
 * the operator declines Cashu mints. Before that column existed the setting
 * was site-wide only, so a mint-free store on a multi-store install had no way
 * to say "never issue a mint invoice for me". SwapsConfig resolves the
 * per-store tri-state first and only then falls back to the config key.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/swap/config.php';

make_store('store_inherit');
make_store('store_off');
make_store('store_on');

// --- Fresh stores inherit, and the site default is off. -------------------
assert_eq(false, SwapsConfig::strictNoMintFallback(), 'site default is off');
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_inherit'),
    'a store with no override follows the site default'
);

// --- Per-store overrides win over the site value in both directions. ------
SwapsConfig::setStoreStrictOverride('store_off', SwapsConfig::FORCE_OFF);
SwapsConfig::setStoreStrictOverride('store_on', SwapsConfig::FORCE_ON);

assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_on'),
    'FORCE_ON is strict even while the site default is off'
);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_off'),
    'FORCE_OFF is not strict'
);

// Flipping the site default must move the inheriting store and leave the two
// explicit overrides exactly where they are.
SwapsConfig::setStrictNoMintFallback(true);
assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_inherit'),
    'the inheriting store follows the site default up'
);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_off'),
    'FORCE_OFF still wins when the site default flips on'
);
assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_on'),
    'FORCE_ON is unaffected by the site default'
);

// --- Unknown store ids resolve to the site default rather than throwing. ---
assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_does_not_exist'),
    'a missing store falls back to the site value'
);

// --- The tri-state is validated on write. ---------------------------------
$threw = false;
try {
    SwapsConfig::setStoreStrictOverride('store_on', 7);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'an out-of-range tri-state must be rejected');
assert_eq(
    true,
    SwapsConfig::strictNoMintFallbackForStore('store_on'),
    'the rejected write must not have changed the stored value'
);

// --- The column is outside Config::updateStore's allowlist. ---------------
// Keeping it out is deliberate (same as swaps_enabled); this pins that so a
// future allowlist edit has to be a conscious decision.
Config::updateStore('store_off', ['strict_no_mint_fallback' => 1]);
assert_eq(
    false,
    SwapsConfig::strictNoMintFallbackForStore('store_off'),
    'updateStore must not be able to write the strict flag'
);

echo "ok\n";
