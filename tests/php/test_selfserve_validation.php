<?php
/**
 * Self-serve input validation: currency allowlist, amount format per unit,
 * note length/sanitization, and the sats max guard. This is untrusted public
 * input, so each must reject malformed values. See includes/selfserve.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/selfserve.php';

$satStore = 'store_ss_sat';
make_store($satStore, 'https://mint.example'); // default_currency defaults to 'sat'

$fiatStore = 'store_ss_fiat';
make_store($fiatStore, 'https://mint.example');
Database::update('stores', ['default_currency' => 'USD'], 'id = ?', [$fiatStore]);

/** Assert that $fn() throws SelfServeValidationException. */
function assert_rejects(callable $fn, string $msg): void {
    $threw = false;
    try { $fn(); } catch (SelfServeValidationException $e) { $threw = true; }
    assert_true($threw, $msg);
}

// ---- Currency allowlist ----

assert_eq(['sat'], SelfServe::allowedCurrencies($satStore), 'sat-only store offers just sat');
assert_eq(['sat', 'USD'], SelfServe::allowedCurrencies($fiatStore), 'fiat store offers sat + USD');

assert_eq('sat', SelfServe::validateCurrency($satStore, 'sat'), 'sat accepted');
assert_eq('sat', SelfServe::validateCurrency($satStore, 'SATS'), 'SATS normalizes to sat');
assert_eq('USD', SelfServe::validateCurrency($fiatStore, 'usd'), 'usd normalizes to USD');
assert_rejects(fn() => SelfServe::validateCurrency($satStore, 'USD'), 'USD rejected for sat-only store');
assert_rejects(fn() => SelfServe::validateCurrency($fiatStore, 'EUR'), 'EUR rejected (not the store default)');
assert_rejects(fn() => SelfServe::validateCurrency($fiatStore, 'BTC'), 'BTC rejected (not offered)');

// ---- Amount format ----

assert_eq('100', SelfServe::validateAmount('100', 'sat'), 'whole sats ok');
assert_eq('1', SelfServe::validateAmount(' 1 ', 'sat'), 'trimmed sats ok');
assert_rejects(fn() => SelfServe::validateAmount('', 'sat'), 'empty rejected');
assert_rejects(fn() => SelfServe::validateAmount('0', 'sat'), 'zero sats rejected');
assert_rejects(fn() => SelfServe::validateAmount('1.5', 'sat'), 'fractional sats rejected');
assert_rejects(fn() => SelfServe::validateAmount('-5', 'sat'), 'negative rejected');
assert_rejects(fn() => SelfServe::validateAmount('1e3', 'sat'), 'exponent rejected');
assert_rejects(fn() => SelfServe::validateAmount('abc', 'sat'), 'non-numeric rejected');
assert_rejects(fn() => SelfServe::validateAmount('10,000', 'sat'), 'thousands separator rejected');

assert_eq('1.50', SelfServe::validateAmount('1.50', 'USD'), 'fiat 2dp ok');
assert_eq('10', SelfServe::validateAmount('10', 'USD'), 'fiat whole ok');
assert_rejects(fn() => SelfServe::validateAmount('1.555', 'USD'), 'fiat 3dp rejected');
assert_rejects(fn() => SelfServe::validateAmount('0', 'USD'), 'fiat zero rejected');

// ---- Notes ----

assert_eq('', SelfServe::validateNotes(''), 'empty note ok');
assert_eq('Coffee', SelfServe::validateNotes('  Coffee  '), 'note trimmed');
assert_eq('a b', SelfServe::validateNotes("a\tb"), 'control chars become spaces');
$max = str_repeat('x', SelfServe::NOTES_MAX_LEN);
assert_eq($max, SelfServe::validateNotes($max), 'note at max length ok');
assert_rejects(fn() => SelfServe::validateNotes(str_repeat('x', SelfServe::NOTES_MAX_LEN + 1)), 'over-long note rejected');

// ---- Max guard ----

SelfServe::setStoreMaxSats($satStore, 5000);
SelfServe::assertWithinMax($satStore, 1);      // MIN_SATS, no throw
SelfServe::assertWithinMax($satStore, 5000);   // exactly max, no throw
assert_rejects(fn() => SelfServe::assertWithinMax($satStore, 5001), 'over max rejected');
assert_rejects(fn() => SelfServe::assertWithinMax($satStore, 0), 'below min rejected');

echo "test_selfserve_validation: ok\n";
