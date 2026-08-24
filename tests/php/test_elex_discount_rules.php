<?php
/**
 * Rule-array merge for the ELEX Discount Per Payment Method option.
 *
 * cashupay_elex_upsert_discount_rule is the piece of the Bitcoin-discount
 * auto-configuration that decides what gets written into the ELEX plugin's
 * option — and the one invariant that must never break is "a merchant's
 * existing rule for the gateway survives every completion-screen re-render".
 * The function is pure (no WordPress calls) precisely so this file can pin
 * that behaviour without a WordPress install; the ABSPATH define below only
 * satisfies the helper file's direct-access guard.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
require_once dirname(__DIR__, 2) . '/wordpress/elex-discount.php';

const GW = 'btcpaygf_default';

// --- Adding to an empty option --------------------------------------------

$res = cashupay_elex_upsert_discount_rule([], GW, 2, 'BareBits (Bitcoin + Lightning)', 'Bitcoin discount');
assert_eq('added', $res['action'], 'an empty option gains a rule');
assert_eq(1, count($res['rules']), 'exactly one rule is added');
assert_eq(
    [
        'id' => GW,
        'type' => 'BareBits (Bitcoin + Lightning)',
        'discount_type' => 'percentage',
        'value' => '2',
        'row_label' => 'Bitcoin discount',
        'checkbox_value' => 'yes',
    ],
    $res['rules'][0],
    'the row matches the shape the ELEX settings form submits (v1.3.2), value as string'
);

// --- Never overwrite an existing rule for the gateway ---------------------

$merchantRule = [
    'id' => GW,
    'type' => 'BTCPay',
    'discount_type' => 'fixed',
    'value' => '7',
    'row_label' => 'My own label',
    'checkbox_value' => 'no',
];
$res = cashupay_elex_upsert_discount_rule([$merchantRule], GW, 2, 'BareBits', 'Bitcoin discount');
assert_eq('kept_existing', $res['action'], 'an existing rule for the gateway wins');
assert_eq([$merchantRule], $res['rules'], 'and is preserved byte-for-byte — even a disabled fixed-amount one');

// --- Rules for other gateways are untouched and ours is appended ----------

$chequeRule = [
    'id' => 'cheque',
    'type' => 'Check payments',
    'discount_type' => 'percentage',
    'value' => '5',
    'row_label' => 'Check discount',
    'checkbox_value' => 'yes',
];
$res = cashupay_elex_upsert_discount_rule([$chequeRule], GW, 3, 'BareBits', 'Bitcoin discount');
assert_eq('added', $res['action'], 'a rule for a different gateway does not block ours');
assert_eq(2, count($res['rules']), 'both rules present');
assert_eq($chequeRule, $res['rules'][0], 'the other gateway\'s rule is untouched');
assert_eq(GW, $res['rules'][1]['id'], 'ours is appended after it');
assert_eq('3', $res['rules'][1]['value'], 'with the chosen percentage');

// --- Corrupt option contents are dropped, not fatal -----------------------
//
// The option is plain WordPress data any plugin or WP-CLI call can mangle;
// a non-array entry must not take down the completion screen.
$res = cashupay_elex_upsert_discount_rule(
    ['junk-string', 42, $chequeRule, null],
    GW, 1, 'BareBits', 'Bitcoin discount'
);
assert_eq('added', $res['action'], 'junk entries do not block the add');
assert_eq(2, count($res['rules']), 'junk entries are dropped, real ones kept');
assert_eq($chequeRule, $res['rules'][0], 'the surviving rule is the real one');

// String keys (some import/export tools re-key the option) still work and
// come back re-indexed, which the ELEX form template iterates fine.
$res = cashupay_elex_upsert_discount_rule(['a' => $merchantRule], GW, 2, 'BareBits', 'Bitcoin discount');
assert_eq('kept_existing', $res['action'], 'a string-keyed existing rule is still found');
assert_eq([$merchantRule], $res['rules'], 'and survives re-indexed');

echo "ok\n";
