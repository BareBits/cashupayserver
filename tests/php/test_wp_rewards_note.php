<?php
/**
 * The WooCommerce rewards order note: the pure pieces of
 * wordpress/btcpay-integration.php that turn a Greenfield invoice's
 * `rewards` block into the sentence(s) the merchant reads on the order.
 *
 * Everything about the wording — the three stories (partial application,
 * full coverage, cash back earned), how amounts and usernames are rendered,
 * and what is dropped when the server sends something unexpected — is
 * decided by functions with no WordPress calls, precisely so this file can
 * pin them without a WordPress install. The live wiring (the
 * woocommerce_payment_complete hook, the Greenfield fetch, the once-only
 * meta flag) is covered by tests/wordpress/test_wp_rewards_order_note.py.
 * The ABSPATH define satisfies the file's direct-access guard; the
 * add_action/add_filter stubs swallow the hook registrations it makes at
 * load time.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

define('ABSPATH', sys_get_temp_dir() . '/');
function add_action(...$args): void {}
function add_filter(...$args): void {}
require_once dirname(__DIR__, 2) . '/wordpress/btcpay-integration.php';

// --- Amount rendering --------------------------------------------------------
//
// The server sends decimal strings at the currency's natural precision
// (2 decimals fiat, whole sats). The note repeats them verbatim with the
// currency spelled the way the payment page does.
assert_eq('1.20 USD', barebits_rewards_format_amount('1.20', 'USD'), 'fiat amount + code');
assert_eq('1.20 USD', barebits_rewards_format_amount('1.20', 'usd'), 'currency code is upper-cased');
assert_eq('12 sats', barebits_rewards_format_amount('12', 'sat'), 'sat currency reads as sats');
assert_eq('12 sats', barebits_rewards_format_amount('12', 'SATS'), 'the SATS spelling too');
assert_eq('1 sat', barebits_rewards_format_amount('1', 'sat'), 'singular sat');
assert_eq('0 sats', barebits_rewards_format_amount('0', 'sat'), 'zero is plural');
assert_eq('0.00001500 BTC', barebits_rewards_format_amount('0.00001500', 'BTC'), 'BTC-scale strings survive verbatim');
assert_eq('5', barebits_rewards_format_amount('5', ''), 'missing currency: amount alone');
assert_eq('5', barebits_rewards_format_amount('5', null), 'null currency: amount alone');
assert_eq('5', barebits_rewards_format_amount('5', 'not a code'), 'garbage currency is dropped, amount kept');
assert_eq('7', barebits_rewards_format_amount(7, null), 'integers are accepted');
assert_eq('0.000015 BTC', barebits_rewards_format_amount(0.000015, 'BTC'), 'floats are spelled out, never 1.5E-5');
assert_eq('', barebits_rewards_format_amount('-1', 'USD'), 'negative amounts are refused');
assert_eq('', barebits_rewards_format_amount('1e3', 'USD'), 'scientific notation is refused');
assert_eq('', barebits_rewards_format_amount('abc', 'USD'), 'non-numeric is refused');
assert_eq('', barebits_rewards_format_amount(null, 'USD'), 'missing amount is refused');
assert_eq('', barebits_rewards_format_amount(['1'], 'USD'), 'arrays are refused');

assert_true(barebits_rewards_amount_is_zero(''), 'empty is nothing to report');
assert_true(barebits_rewards_amount_is_zero('0'), 'bare zero');
assert_true(barebits_rewards_amount_is_zero('0.00 USD'), 'formatted zero');
assert_true(barebits_rewards_amount_is_zero('0 sats'), 'zero sats');
assert_false(barebits_rewards_amount_is_zero('0.01 USD'), 'a cent counts');
assert_false(barebits_rewards_amount_is_zero('10'), 'ten counts');

// --- Username rendering ------------------------------------------------------
//
// The server issues letters and digits only. Anything else is not something
// the server produced and is dropped (never "escaped" into a mangled name).
assert_eq('alice', barebits_rewards_username('alice'), 'plain username');
assert_eq('5551234567', barebits_rewards_username(' 5551234567 '), 'phone-number style, trimmed');
assert_eq('Alice42', barebits_rewards_username('Alice42'), 'case preserved as the payer typed it');
assert_eq('', barebits_rewards_username('<b>alice</b>'), 'markup is dropped, not stripped into "balice"');
assert_eq('', barebits_rewards_username('alice bob'), 'spaces are dropped');
assert_eq('alice', barebits_rewards_username("alice\n"), 'surrounding whitespace is trimmed');
assert_eq('', barebits_rewards_username("ali\nce"), 'embedded control characters are dropped');
assert_eq('', barebits_rewards_username(str_repeat('a', 65)), 'over-long names are dropped');
assert_eq('', barebits_rewards_username(null), 'null is empty');
assert_eq('', barebits_rewards_username(42), 'non-strings are empty');

// --- Exact decimal subtraction ------------------------------------------------
assert_eq('8.80', barebits_rewards_decimal_sub('10.00', '1.20'), 'fiat remainder');
assert_eq('1000', barebits_rewards_decimal_sub('1500', '500'), 'whole-unit remainder');
assert_eq('0.00001000', barebits_rewards_decimal_sub('0.00001500', '0.00000500'), 'BTC-scale remainder');
assert_eq('8.80', barebits_rewards_decimal_sub('10', '1.20'), 'mixed precisions use the larger');
assert_eq('0.00', barebits_rewards_decimal_sub('1.20', '1.20'), 'zero remainder keeps the scale');
assert_eq('0.05', barebits_rewards_decimal_sub('1.00', '0.95'), 'leading zero preserved');
assert_null(barebits_rewards_decimal_sub('1.00', '2.00'), 'negative results are refused');
assert_null(barebits_rewards_decimal_sub('abc', '1'), 'malformed operands are refused');
assert_null(barebits_rewards_decimal_sub('1', '-1'), 'signed operands are refused');
assert_null(barebits_rewards_decimal_sub('123456789012345678', '1'), 'beyond exact integer range is refused');

// --- The three stories --------------------------------------------------------

// Partial application, invoice currency known: the remainder is spelled out.
$partial = [
    'appliedAmount' => '1.20', 'appliedCurrency' => 'USD', 'appliedUsername' => 'alice',
    'fullCoverage' => false,
    'earnedAmount' => '0.09', 'earnedCurrency' => 'USD', 'earnedClaimed' => true, 'earnedUsername' => 'alice',
];
assert_eq(
    'Customer applied 1.20 USD of BareBits rewards (account alice); the remaining 8.80 USD was paid in Bitcoin. '
    . 'Customer earned 0.09 USD in BareBits rewards (claimed by alice).',
    barebits_rewards_order_note($partial, ['amount' => '10.00', 'currency' => 'USD', 'paymentRail' => 'lnaddress']),
    'partial application with auto-credited earn'
);

// Without the invoice the remainder cannot be computed; the sentence stays true.
assert_eq(
    'Customer applied 1.20 USD of BareBits rewards (account alice); the remaining amount was paid in Bitcoin. '
    . 'Customer earned 0.09 USD in BareBits rewards (claimed by alice).',
    barebits_rewards_order_note($partial),
    'no invoice context: no remainder figure'
);

// Applied in the rewards currency while the invoice is in another: no figure.
assert_eq(
    'Customer applied 500 sats of BareBits rewards (account alice); the remaining amount was paid in Bitcoin.',
    barebits_rewards_order_note(
        ['appliedAmount' => '500', 'appliedCurrency' => 'sat', 'appliedUsername' => 'alice', 'fullCoverage' => false],
        ['amount' => '0.00001500', 'currency' => 'BTC']
    ),
    'currency mismatch: remainder omitted rather than computed across currencies'
);

// Same currency as the invoice at BTC scale: the figure comes out exact.
assert_eq(
    'Customer applied 0.00000500 BTC of BareBits rewards (account alice); the remaining 0.00001000 BTC was paid in Bitcoin.',
    barebits_rewards_order_note(
        ['appliedAmount' => '0.00000500', 'appliedCurrency' => 'BTC', 'appliedUsername' => 'alice', 'fullCoverage' => false],
        ['amount' => '0.00001500', 'currency' => 'BTC']
    ),
    'BTC-denominated invoice'
);

// An eCash-settled invoice names the right coin.
assert_eq(
    'Customer applied 1.20 USD of BareBits rewards (account alice); the remaining 8.80 USD was paid in eCash.',
    barebits_rewards_order_note(
        ['appliedAmount' => '1.20', 'appliedCurrency' => 'USD', 'appliedUsername' => 'alice'],
        ['amount' => '10.00', 'currency' => 'USD', 'paymentRail' => 'ecx']
    ),
    'ECX rail'
);

// Full coverage: nothing was paid in Bitcoin and nothing is earned (net basis).
assert_eq(
    'Paid entirely with BareBits rewards (account alice).',
    barebits_rewards_order_note([
        'appliedAmount' => '10.00', 'appliedCurrency' => 'USD', 'appliedUsername' => 'alice',
        'fullCoverage' => true,
        'earnedAmount' => '0.00', 'earnedCurrency' => 'USD', 'earnedClaimed' => false, 'earnedUsername' => null,
    ], ['amount' => '10.00', 'currency' => 'USD', 'paymentRail' => 'rewards']),
    'full coverage'
);
assert_eq(
    'Paid entirely with BareBits rewards (account alice).',
    barebits_rewards_order_note(['fullCoverage' => true, 'appliedUsername' => 'alice']),
    'full coverage flag alone is enough'
);
assert_eq(
    'Paid entirely with BareBits rewards.',
    barebits_rewards_order_note(['fullCoverage' => true, 'appliedUsername' => '<script>']),
    'full coverage with an unusable username drops the account clause'
);

// Earn only (no rewards applied): claimed vs unclaimed.
assert_eq(
    'Customer earned 0.09 USD in BareBits rewards (claimed by alice).',
    barebits_rewards_order_note([
        'appliedAmount' => null, 'appliedCurrency' => null, 'appliedUsername' => null, 'fullCoverage' => false,
        'earnedAmount' => '0.09', 'earnedCurrency' => 'USD', 'earnedClaimed' => true, 'earnedUsername' => 'alice',
    ]),
    'earn claimed on the paid page'
);
assert_eq(
    'Customer earned 15 sats in BareBits rewards (unclaimed).',
    barebits_rewards_order_note([
        'appliedAmount' => null, 'appliedCurrency' => null, 'appliedUsername' => null, 'fullCoverage' => false,
        'earnedAmount' => '15', 'earnedCurrency' => 'sat', 'earnedClaimed' => false, 'earnedUsername' => null,
    ]),
    'earn not (yet) claimed'
);
assert_eq(
    'Customer earned 0.09 USD in BareBits rewards (claimed).',
    barebits_rewards_order_note(['earnedAmount' => '0.09', 'earnedCurrency' => 'USD', 'earnedClaimed' => true, 'earnedUsername' => 'bad name']),
    'claimed with an unusable username still says claimed'
);

// --- Nothing to say ------------------------------------------------------------
assert_eq('', barebits_rewards_order_note([]), 'empty block: empty note');
assert_eq('', barebits_rewards_order_note([
    'appliedAmount' => null, 'appliedCurrency' => null, 'appliedUsername' => null, 'fullCoverage' => false,
    'earnedAmount' => null, 'earnedCurrency' => null, 'earnedClaimed' => false, 'earnedUsername' => null,
]), 'all-null block: empty note');
assert_eq('', barebits_rewards_order_note(['earnedAmount' => '0.00', 'earnedCurrency' => 'USD']), 'zero earn: empty note');
assert_eq('', barebits_rewards_order_note(['appliedAmount' => '0', 'appliedCurrency' => 'sat', 'appliedUsername' => 'alice']), 'zero applied: empty note');
assert_eq('', barebits_rewards_order_note(['appliedAmount' => 'lots', 'appliedCurrency' => 'USD', 'appliedUsername' => 'alice']), 'malformed applied amount: dropped');
assert_eq('', barebits_rewards_order_note(['earnedAmount' => ['0.09'], 'earnedCurrency' => 'USD', 'earnedClaimed' => true]), 'malformed earn amount: dropped');

// --- Odd currencies -------------------------------------------------------------
assert_eq(
    'Customer applied 1.20 of BareBits rewards (account alice); the remaining amount was paid in Bitcoin.',
    barebits_rewards_order_note(['appliedAmount' => '1.20', 'appliedCurrency' => '', 'appliedUsername' => 'alice'], ['amount' => '10.00', 'currency' => 'USD']),
    'empty applied currency: amount alone, no cross-currency remainder'
);
assert_eq(
    'Customer earned 3 in BareBits rewards (unclaimed).',
    barebits_rewards_order_note(['earnedAmount' => '3', 'earnedCurrency' => 'x y z', 'earnedClaimed' => false]),
    'garbage earn currency: amount alone'
);
assert_eq(
    'Customer earned 2.50 EUR in BareBits rewards (unclaimed).',
    barebits_rewards_order_note(['earnedAmount' => '2.50', 'earnedCurrency' => 'eur', 'earnedClaimed' => 0]),
    'lower-case currency normalised; falsy claimed flag reads as unclaimed'
);

// --- Username escaping in the composed note -----------------------------------
//
// The note is stored as a WooCommerce comment and printed through WordPress'
// own escaping; the composer additionally refuses to carry anything that is
// not a server-shaped username, so markup can never ride along.
$hostile = barebits_rewards_order_note([
    'appliedAmount' => '1.20', 'appliedCurrency' => 'USD', 'appliedUsername' => 'alice<img src=x onerror=alert(1)>',
    'earnedAmount' => '0.09', 'earnedCurrency' => 'USD', 'earnedClaimed' => true, 'earnedUsername' => '"; DROP TABLE --',
]);
assert_true(strpos($hostile, '<') === false && strpos($hostile, '"') === false, 'no markup or quotes survive: ' . $hostile);
assert_eq(
    'Customer applied 1.20 USD of BareBits rewards; the remaining amount was paid in Bitcoin. '
    . 'Customer earned 0.09 USD in BareBits rewards (claimed).',
    $hostile,
    'hostile usernames are omitted, the money story stays'
);

// The meta flag the live hook writes carries the plugin prefix.
assert_eq('barebits_rewards_note_added', BAREBITS_REWARDS_NOTE_META, 'meta key is prefixed');

echo "OK\n";
