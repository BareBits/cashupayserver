<?php
/**
 * Bitcoin-discount auto-configuration: the pure pieces of
 * wordpress/elex-discount.php.
 *
 * cashupay_elex_upsert_discount_rule decides what gets written into the ELEX
 * plugin's option — and the one invariant that must never break is "a
 * merchant's existing rule for the gateway survives every completion-screen
 * re-render". cashupay_parse_discount_percent validates the merchant's
 * onboarding answer before anything is installed or written. Both are pure
 * (no WordPress calls) precisely so this file can pin them without a
 * WordPress install; the ABSPATH define below only satisfies the helper
 * file's direct-access guard.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
require_once dirname(__DIR__, 2) . '/wordpress/elex-discount.php';

const GW = 'btcpaygf_default';

// --- Discount percent parsing ---------------------------------------------
//
// Whole numbers 0–100 only; the ELEX plugin that applies the discount renders
// a step-1 number input in its own settings form, so accepting fractions here
// would strand the merchant later. Empty means "no discount", not an error;
// null means re-render the form with an error rather than saving anything.
assert_eq(0, cashupay_parse_discount_percent(''), 'empty input reads as declining the discount');
assert_eq(0, cashupay_parse_discount_percent('0'), 'zero is a valid answer');
assert_eq(5, cashupay_parse_discount_percent('5'), 'a plain whole percent parses');
assert_eq(7, cashupay_parse_discount_percent(' 7 '), 'surrounding whitespace is tolerated');
assert_eq(100, cashupay_parse_discount_percent('100'), 'the top of the range is inclusive');
assert_null(cashupay_parse_discount_percent('101'), 'above 100 is rejected');
assert_null(cashupay_parse_discount_percent('-1'), 'negative is rejected');
assert_null(cashupay_parse_discount_percent('2.5'), 'fractional values are rejected, not rounded');
assert_null(cashupay_parse_discount_percent('abc'), 'non-numeric input is rejected');

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
