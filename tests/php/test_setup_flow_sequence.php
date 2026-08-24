<?php
/**
 * Onboarding wizard screen sequencing (SetupFlow).
 *
 * The wizard's shape is conditional in three ways and each one has bitten
 * before: WordPress mode has no password screen, add_store mode has neither
 * the pre- nor the post-store screens, and the zero-conf screen only exists
 * once an on-chain destination has been saved. Getting any of them wrong
 * either strands the operator on a screen with no exit or advertises a step
 * count that never materialises.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/setup_flow.php';

// --- Standalone first run -------------------------------------------------

$noOnchain = SetupFlow::stepSequence('', false, false);
assert_eq(
    ['terms', 'security', 'password', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $noOnchain,
    'standalone without an on-chain rail drops zeroconf'
);

$withOnchain = SetupFlow::stepSequence('', false, true);
assert_eq(
    ['terms', 'security', 'password', 'store', 'onchain', 'zeroconf', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $withOnchain,
    'standalone with an on-chain rail includes zeroconf'
);

// The terms-of-service gate opens every first run and is never skipped.
assert_eq('terms', $withOnchain[0], 'a first run opens on the terms-of-service gate');

// --- WordPress mode has no password screen --------------------------------

$wp = SetupFlow::stepSequence('', true, true);
assert_false(in_array('password', $wp, true), 'WordPress mode supplies its own auth');
assert_eq('terms', $wp[0], 'WordPress mode still opens on the terms gate');
assert_eq(
    ['terms', 'security', 'store', 'onchain', 'zeroconf', 'lightning', 'swaps', 'mints', 'discount', 'cron', 'done'],
    $wp,
    'WordPress swaps the password screen for the Bitcoin-discount screen'
);

// --- Security screen skip (data dir outside the web root) ------------------
//
// The screen proves the database can't be fetched over HTTP; with the data
// directory outside the web root that exposure is impossible, so the screen
// is dropped ($includeSecurity = false) and the counter stays honest. The
// caller keeps it in the flow whenever a PHP requirement is missing, because
// the screen is also where that blocking error renders.

$noSecurity = SetupFlow::stepSequence('', false, false, false);
assert_eq(
    ['terms', 'password', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $noSecurity,
    'standalone with the data dir outside the web root drops the security screen'
);
assert_eq('password', SetupFlow::nextStep('terms', $noSecurity), 'terms then goes straight to the password screen');

$wpNoSecurity = SetupFlow::stepSequence('', true, false, false);
assert_eq(
    ['terms', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'discount', 'cron', 'done'],
    $wpNoSecurity,
    'WordPress with the data dir outside the web root drops both password and security'
);
assert_eq('store', SetupFlow::nextStep('terms', $wpNoSecurity), 'in WordPress terms then goes straight to the store screen');

// Omitting the flag keeps the screen — every historical call site behaves
// as before.
assert_true(
    in_array('security', SetupFlow::stepSequence('', false, false), true),
    'the security screen stays by default'
);

// add_store never had the security screen; the flag must not disturb it.
assert_eq(
    SetupFlow::stepSequence('add_store', false, true),
    SetupFlow::stepSequence('add_store', false, true, false),
    'add_store is unaffected by the security flag'
);

// The bundled test PHP satisfies every requirement, so the skip decision is
// purely the webroot test on this rig.
assert_eq([], SetupFlow::missingRequirements(), 'the bundled test PHP passes all requirement checks');

// --- The Bitcoin-discount screen is WordPress-only ------------------------
//
// It configures a WooCommerce checkout discount, so outside WordPress there
// is nothing for it to act on; and it sits after mints, meaning it renders
// post-setup_complete and must survive the redirect-if-set-up guard.
assert_false(in_array('discount', $withOnchain, true), 'standalone installs never see the discount screen');
assert_false(in_array('discount', $noOnchain, true), 'standalone without on-chain never sees it either');
assert_eq('discount', SetupFlow::nextStep('mints', $wp), 'in WordPress the discount screen follows mints');
assert_eq('cron', SetupFlow::nextStep('discount', $wp), 'and cron follows the discount screen');
assert_eq('cron', SetupFlow::nextStep('mints', $withOnchain), 'standalone still goes mints straight to cron');
assert_true(
    in_array('discount', SetupFlow::POST_COMPLETION, true),
    'discount renders after setup_complete and must survive the redirect guard'
);
assert_null(SetupFlow::backStep('discount', $wp), 'discount is past the point of no return — no Back');
assert_true(SetupFlow::isKnownStep('discount'), 'discount is a real screen');

// --- Discount percent parsing ---------------------------------------------
//
// Whole numbers 0–100 only; the ELEX plugin that applies the discount blocks
// fractional values in its own settings form, so accepting them here would
// strand the merchant later. Empty means "no discount", not an error.
assert_eq(0, SetupFlow::parseDiscountPercent(''), 'empty input reads as declining the discount');
assert_eq(0, SetupFlow::parseDiscountPercent('0'), 'zero is a valid answer');
assert_eq(2, SetupFlow::parseDiscountPercent(' 2 '), 'surrounding whitespace is tolerated');
assert_eq(100, SetupFlow::parseDiscountPercent('100'), 'the top of the range is inclusive');
assert_null(SetupFlow::parseDiscountPercent('101'), 'above 100 is rejected');
assert_null(SetupFlow::parseDiscountPercent('-1'), 'negative is rejected');
assert_null(SetupFlow::parseDiscountPercent('1.5'), 'fractional values are rejected, not rounded');
assert_null(SetupFlow::parseDiscountPercent('2,5'), 'comma decimals are rejected too');
assert_null(SetupFlow::parseDiscountPercent('abc'), 'non-numeric input is rejected');
assert_null(SetupFlow::parseDiscountPercent('1e2'), 'scientific notation is not a percent');

// --- add_store mode runs only the store-scoped screens --------------------

$addStore = SetupFlow::stepSequence('add_store', false, true);
assert_eq(
    ['store', 'onchain', 'zeroconf', 'lightning', 'swaps', 'mints'],
    $addStore,
    'add_store skips security/password up front and cron/done at the end'
);
assert_eq(
    ['store', 'onchain', 'lightning', 'swaps', 'mints'],
    SetupFlow::stepSequence('add_store', false, false),
    'add_store drops zeroconf with no on-chain rail too'
);
assert_eq('store', SetupFlow::firstStep('add_store'), 'add_store opens on the store screen');
assert_eq('terms', SetupFlow::firstStep(''), 'a first run opens on the terms gate');
// add_store belongs to an already-configured instance, so it never re-shows terms.
assert_false(in_array('terms', $addStore, true), 'add_store never re-shows the terms gate');
// The Bitcoin discount is a site-wide WooCommerce setting, not a per-store
// one — asking again while adding a second store would silently rewrite it.
assert_false(in_array('discount', $addStore, true), 'add_store never shows the discount screen');

// --- next / prev ----------------------------------------------------------

assert_eq('security', SetupFlow::nextStep('terms', $withOnchain), 'the safety check follows the terms gate');
assert_eq('zeroconf', SetupFlow::nextStep('onchain', $withOnchain), 'zeroconf follows onchain');
assert_eq('lightning', SetupFlow::nextStep('onchain', $noOnchain), 'without zeroconf, lightning follows onchain');
assert_null(SetupFlow::nextStep('done', $withOnchain), 'done is terminal');
assert_null(SetupFlow::nextStep('mints', $addStore), 'mints is terminal in add_store mode');
assert_null(SetupFlow::nextStep('cron', $addStore), 'a screen outside the sequence has no next');

assert_eq('store', SetupFlow::prevStep('onchain', $withOnchain), 'store precedes onchain');
assert_eq('terms', SetupFlow::prevStep('security', $withOnchain), 'the terms gate precedes the safety check');
assert_null(SetupFlow::prevStep('terms', $withOnchain), 'the first screen has no previous');
assert_null(SetupFlow::prevStep('zeroconf', $noOnchain), 'a screen outside the sequence has no previous');

// --- Back targets ---------------------------------------------------------
//
// The store exists by the time any later screen renders, so Back must never
// land on the password screen — re-submitting it trips the "an admin already
// exists" guard and shows a 403 mid-wizard.
assert_null(SetupFlow::backStep('store', $withOnchain), 'Back from store must not reach the password screen');
assert_null(SetupFlow::backStep('terms', $withOnchain), 'the terms gate is first and has no Back');
assert_null(SetupFlow::backStep('security', $withOnchain), 'the safety check has no Back (it must not return to terms)');
assert_null(SetupFlow::backStep('password', $withOnchain), 'the password screen has no Back');
assert_eq('store', SetupFlow::backStep('onchain', $withOnchain), 'Back from onchain returns to store');
assert_eq('onchain', SetupFlow::backStep('zeroconf', $withOnchain), 'Back from zeroconf returns to onchain');
assert_eq('onchain', SetupFlow::backStep('lightning', $noOnchain), 'Back skips the absent zeroconf screen');
// The post-completion screens are past the point of no return: setup_complete
// is already set and the store is live.
assert_null(SetupFlow::backStep('cron', $withOnchain), 'cron is past the point of no return');
assert_null(SetupFlow::backStep('done', $withOnchain), 'done is past the point of no return');
// In add_store mode the store screen is genuinely first, so still no Back.
assert_null(SetupFlow::backStep('store', $addStore), 'store is the first add_store screen');

// --- Step validation ------------------------------------------------------

assert_true(SetupFlow::isKnownStep('terms'), 'terms is a real screen');
assert_true(SetupFlow::isKnownStep('mints'), 'mints is a real screen');
assert_false(SetupFlow::isKnownStep(''), 'an empty step is not a screen');
assert_false(SetupFlow::isKnownStep('4'), 'the pre-slug numeric steps are gone');
assert_false(SetupFlow::isKnownStep('MINTS'), 'slugs are matched case-sensitively');

// --- The add_store hand-off panel ----------------------------------------
//
// It exists to show a silently generated wallet seed exactly once before
// returning to admin. It is renderable but deliberately outside the counted
// sequence, so it must never be routed to as a next/back target.
assert_true(
    SetupFlow::isKnownStep(SetupFlow::ADD_STORE_COMPLETE),
    'the hand-off panel is renderable'
);
assert_false(
    in_array(SetupFlow::ADD_STORE_COMPLETE, SetupFlow::ADD_STORE_STEPS, true),
    'it must not be counted as one of the add_store questions'
);
assert_false(
    in_array(SetupFlow::ADD_STORE_COMPLETE, $addStore, true),
    'and it must not appear in a generated sequence'
);
assert_null(
    SetupFlow::backStep(SetupFlow::ADD_STORE_COMPLETE, $addStore),
    'the hand-off panel has no Back — the store is already created'
);
assert_null(
    SetupFlow::nextStep(SetupFlow::ADD_STORE_COMPLETE, $addStore),
    'and nothing follows it in the sequence'
);

// --- On-chain state detection ---------------------------------------------

assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState(null),
    'no store id means nothing is configured'
);
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_missing'),
    'an unknown store id means nothing is configured'
);

make_store('store_bare');
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_bare'),
    'a fresh store has no on-chain destination'
);

make_store('store_xpub');
Database::update('stores', [
    'onchain_address_mode' => 'xpub',
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_xpub']);
assert_eq(
    ['configured' => true, 'hasXpub' => true],
    SetupFlow::onchainState('store_xpub'),
    'an xpub store is configured and swap-capable'
);

// A static address is a valid receive destination but cannot back submarine
// swaps — those derive a fresh address per swap.
make_store('store_static');
Database::update('stores', [
    'onchain_address_mode' => 'static',
    'onchain_static_address' => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
], 'id = ?', ['store_static']);
assert_eq(
    ['configured' => true, 'hasXpub' => false],
    SetupFlow::onchainState('store_static'),
    'a static address is configured but not swap-capable'
);

// Switching a store that once had an xpub over to static mode must report the
// active mode, not the leftover column.
Database::update('stores', [
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_static']);
assert_eq(
    ['configured' => true, 'hasXpub' => false],
    SetupFlow::onchainState('store_static'),
    'a stale xpub column must not resurrect swap capability in static mode'
);

// ...and the mirror image: static mode selected but no address saved yet.
make_store('store_static_empty');
Database::update('stores', [
    'onchain_address_mode' => 'static',
    'onchain_xpub' => 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic',
], 'id = ?', ['store_static_empty']);
assert_eq(
    ['configured' => false, 'hasXpub' => false],
    SetupFlow::onchainState('store_static_empty'),
    'static mode with no address saved is not configured'
);

echo "ok\n";
