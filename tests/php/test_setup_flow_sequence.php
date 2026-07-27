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
    ['security', 'password', 'store', 'onchain', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $noOnchain,
    'standalone without an on-chain rail drops zeroconf'
);

$withOnchain = SetupFlow::stepSequence('', false, true);
assert_eq(
    ['security', 'password', 'store', 'onchain', 'zeroconf', 'lightning', 'swaps', 'mints', 'cron', 'done'],
    $withOnchain,
    'standalone with an on-chain rail includes zeroconf'
);

// --- WordPress mode has no password screen --------------------------------

$wp = SetupFlow::stepSequence('', true, true);
assert_false(in_array('password', $wp, true), 'WordPress mode supplies its own auth');
assert_eq('security', $wp[0], 'WordPress mode still starts with the safety check');
assert_eq(9, count($wp), 'WordPress with on-chain is one screen shorter than standalone');

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
assert_eq('security', SetupFlow::firstStep(''), 'a first run opens on the safety check');

// --- next / prev ----------------------------------------------------------

assert_eq('zeroconf', SetupFlow::nextStep('onchain', $withOnchain), 'zeroconf follows onchain');
assert_eq('lightning', SetupFlow::nextStep('onchain', $noOnchain), 'without zeroconf, lightning follows onchain');
assert_null(SetupFlow::nextStep('done', $withOnchain), 'done is terminal');
assert_null(SetupFlow::nextStep('mints', $addStore), 'mints is terminal in add_store mode');
assert_null(SetupFlow::nextStep('cron', $addStore), 'a screen outside the sequence has no next');

assert_eq('store', SetupFlow::prevStep('onchain', $withOnchain), 'store precedes onchain');
assert_null(SetupFlow::prevStep('security', $withOnchain), 'the first screen has no previous');
assert_null(SetupFlow::prevStep('zeroconf', $noOnchain), 'a screen outside the sequence has no previous');

// --- Back targets ---------------------------------------------------------
//
// The store exists by the time any later screen renders, so Back must never
// land on the password screen — re-submitting it trips the "an admin already
// exists" guard and shows a 403 mid-wizard.
assert_null(SetupFlow::backStep('store', $withOnchain), 'Back from store must not reach the password screen');
assert_null(SetupFlow::backStep('security', $withOnchain), 'the first screen has no Back');
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
