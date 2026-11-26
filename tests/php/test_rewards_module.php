<?php
/**
 * Rewards module primitives (includes/rewards.php): store settings, username
 * rules, micro-unit money, account creation, and the ledger-guarded balance
 * movements (earn / reserve / commit / release / reverse) with their
 * once-per-invoice idempotency. The invoice-side orchestration is covered by
 * test_rewards_invoice_flow.php.
 */
declare(strict_types=1);

require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rewards.php';

// Deterministic prices: $100,000 / BTC and €50,000 / BTC (so 1 USD = 0.5 EUR,
// 1 sat = $0.001 = €0.0005); GBP has no quote at all. Never the network.
final class StubPriceProvider implements PriceProvider {
    public static array $prices = ['USD' => 100000.0, 'EUR' => 50000.0];
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return self::$prices[strtoupper($currency)] ?? null; }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider()];

function has_col(string $table, string $col): bool {
    $r = Database::fetchOne("SELECT COUNT(*) AS n FROM pragma_table_info('{$table}') WHERE name = ?", [$col]);
    return (int)($r['n'] ?? 0) > 0;
}
function expect_invalid(callable $fn, string $what): void {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    fail("$what: expected InvalidArgumentException");
}

// ---------- schema ----------
foreach (['rewards_enabled', 'rewards_percent', 'rewards_currency'] as $c) {
    assert_true(has_col('stores', $c), "stores.$c exists");
}
foreach (['rewards_account_id', 'rewards_discount_amount', 'rewards_applied_micro', 'rewards_spend_state',
          'rewards_earned_micro', 'rewards_earn_currency', 'rewards_claimed_at',
          'onchain_amount_alternates', 'ecx_amount_alternates'] as $c) {
    assert_true(has_col('invoices', $c), "invoices.$c exists");
}

// ---------- settings ----------
make_store('s_usd', 'https://mint.example');
Database::update('stores', ['default_currency' => 'USD'], 'id = ?', ['s_usd']);
make_store('s_sat', 'https://mint.example');

$off = Rewards::forStore('s_usd');
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], $off, 'defaults: off, 0%, no currency');
assert_false(Rewards::isEnabledForStore('s_usd'), 'off by default');

assert_eq(1.0, Rewards::parsePercent('1'), 'integer percent');
assert_eq(2.5, Rewards::parsePercent(' 2.5 '), 'float percent, trimmed');
assert_eq(0.1234, Rewards::parsePercent('0.1234'), 'four decimals accepted');
assert_eq(50.0, Rewards::parsePercent('50'), 'cap is inclusive');
expect_invalid(fn() => Rewards::parsePercent('50.0001'), 'above cap');
expect_invalid(fn() => Rewards::parsePercent('0'), 'zero');
expect_invalid(fn() => Rewards::parsePercent('-1'), 'negative');
expect_invalid(fn() => Rewards::parsePercent('1.23456'), 'five decimals');
expect_invalid(fn() => Rewards::parsePercent('abc'), 'text');
expect_invalid(fn() => Rewards::parsePercent('1e1'), 'scientific notation');
expect_invalid(fn() => Rewards::parsePercent(''), 'empty');

expect_invalid(fn() => Rewards::setStoreSettings('s_usd', true, null), 'enable without percent');
expect_invalid(fn() => Rewards::setStoreSettings('nope', true, 1.0), 'unknown store');
Rewards::setStoreSettings('s_usd', true, 1.5);
$on = Rewards::forStore('s_usd');
assert_eq(['enabled' => true, 'percent' => 1.5, 'currency' => 'USD'], $on, 'enabling pins the currency to the default currency');
assert_true(Rewards::isEnabledForStore('s_usd'), 'enabled');
// Disabling keeps the percentage and currency so re-enabling remembers them.
Rewards::setStoreSettings('s_usd', false, null);
assert_eq(['enabled' => false, 'percent' => 1.5, 'currency' => 'USD'], Rewards::forStore('s_usd'), 'disable keeps pct + currency');
assert_false(Rewards::isEnabledForStore('s_usd'));
Rewards::setStoreSettings('s_usd', true, 2.0);
assert_eq(2.0, Rewards::forStore('s_usd')['percent'], 'percent updated on re-enable');
// Changing the default currency later never moves the rewards currency by itself.
Database::update('stores', ['default_currency' => 'EUR'], 'id = ?', ['s_usd']);
assert_eq('USD', Rewards::forStore('s_usd')['currency'], 'rewards currency is decoupled from default_currency');
Database::update('stores', ['default_currency' => 'USD'], 'id = ?', ['s_usd']);

Rewards::setStoreSettings('s_sat', true, 1.0);
assert_eq('sat', Rewards::forStore('s_sat')['currency'], 'sat store pins sat');

// A row from before the migration (columns missing) reads as off.
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], Rewards::settingsFromRow(['id' => 'x']), 'legacy row shape');
assert_eq(['enabled' => false, 'percent' => 0.0, 'currency' => null], Rewards::settingsFromRow(null), 'null row');

// ---------- usernames ----------
$n = Rewards::normalizeUsername('  Alice42 ');
assert_eq(['display' => 'Alice42', 'key' => 'alice42'], $n, 'trim + lower-case key, display kept');
assert_eq('5551234567', Rewards::normalizeUsername('5551234567')['key'], 'phone number digits');
expect_invalid(fn() => Rewards::normalizeUsername('ab'), 'too short');
expect_invalid(fn() => Rewards::normalizeUsername(str_repeat('a', 65)), 'too long');
expect_invalid(fn() => Rewards::normalizeUsername('(555) 123-4567'), 'punctuation refused');
expect_invalid(fn() => Rewards::normalizeUsername('alice bob'), 'inner space refused');
expect_invalid(fn() => Rewards::normalizeUsername('al_ice'), 'underscore refused');
expect_invalid(fn() => Rewards::normalizeUsername('ålice'), 'non-ASCII refused');
expect_invalid(fn() => Rewards::normalizeUsername(''), 'empty');
assert_eq('a' . str_repeat('1', 63), Rewards::normalizeUsername('a' . str_repeat('1', 63))['display'], '64 chars accepted');

// ---------- money ----------
assert_eq(1000000, Rewards::MICRO);
assert_eq(12500000, Rewards::toMicro('12.5'), 'decimal to micro');
assert_eq(5000, Rewards::toMicro('0.005'), 'sub-cent to micro');
assert_eq(100000000, Rewards::toMicro('100'), 'integer to micro');
assert_eq(1, Rewards::toMicro('0.0000019'), 'beyond micro precision floors');
expect_invalid(fn() => Rewards::toMicro('-1'), 'negative amount');
expect_invalid(fn() => Rewards::toMicro('1,5'), 'comma decimal');
assert_eq('12.50', Rewards::fromMicro(12500000, 'USD'), 'fiat shows 2 decimals');
assert_eq('0.00', Rewards::fromMicro(5000, 'USD'), 'sub-cent floors to 0.00 on display');
assert_eq('12', Rewards::fromMicro(12900000, 'sat'), 'sats floor to whole sats');
assert_eq('0.005000', Rewards::fromMicroExact(5000), 'exact 6-place rendering');
assert_eq('1.25 USD', Rewards::formatMicro(1250000, 'usd'), 'fiat display');
assert_eq('1 sat', Rewards::formatMicro(1000000, 'sat'), 'singular sat');
assert_eq('1,250 sats', Rewards::formatMicro(1250000000, 'SATS'), 'thousands separator on sats');
assert_eq(12, Rewards::microToSats(12900000, 'sat'), 'sat micro -> sats floors');
assert_eq(7000000, Rewards::satsToMicro(7, 'sat'), 'sats -> sat micro');
assert_eq(0, Rewards::microToSats(0, 'USD'), 'zero never needs a rate');
assert_eq(0, Rewards::satsToMicro(0, 'USD'), 'zero never needs a rate');
assert_eq(123, Rewards::convertMicro(123, 'USD', 'usd'), 'same currency passes through');
// Known rate: $100,000 / BTC -> 1 sat = $0.001 = 1,000 micro.
assert_eq(1000, Rewards::satsToMicroAtRate(1, 'USD', 100000.0), 'sats -> fiat micro at a known rate');
assert_eq(1500000, Rewards::satsToMicroAtRate(1500, 'USD', 100000.0), '1500 sats = $1.50');
assert_eq(1500 * Rewards::MICRO, Rewards::satsToMicroAtRate(1500, 'sat', 100000.0), 'rate ignored on a sat currency');
// Live (stubbed) rates.
assert_eq(1500000, Rewards::satsToMicro(1500, 'USD'), '1500 sats -> $1.50 at $100k');
assert_eq(1500, Rewards::microToSats(1500000, 'USD'), '$1.50 -> 1500 sats');
assert_eq(2, Rewards::microToSats(2999, 'USD'), 'fiat -> sats floors');
assert_eq(500000, Rewards::convertMicro(1000000, 'USD', 'EUR'), '$1.00 -> €0.50');
assert_eq(2000000, Rewards::convertMicro(1000000, 'EUR', 'USD'), '€1.00 -> $2.00');
assert_eq(1000 * Rewards::MICRO, Rewards::convertMicro(1000000, 'USD', 'sat'), '$1.00 -> 1000 sats (in micro)');
assert_eq(1000000, Rewards::convertMicro(1000 * Rewards::MICRO, 'sat', 'USD'), '1000 sats -> $1.00');
assert_eq('0.50', Rewards::microToAmount(1000000, 'USD', 'EUR'), 'discount line in the invoice currency');
assert_eq('1000', Rewards::microToAmount(1000000, 'USD', 'sat'), 'discount line in sats');
assert_null(Rewards::satsToMicro(1, 'GBP'), 'no quote -> null, never a guess');
assert_null(Rewards::microToSats(1, 'GBP'), 'no quote -> null');
assert_null(Rewards::convertMicro(1, 'USD', 'GBP'), 'no quote on either side -> null');
assert_null(Rewards::convertMicro(1, 'GBP', 'USD'), 'no quote on either side -> null');
assert_eq(1000, Rewards::satsToMicroAtRate(1, 'USD', null), 'null rate falls back to the live rate');
// Earn math: percent of the net amount, floored to micro.
assert_eq(1000000, Rewards::earnMicro(100 * Rewards::MICRO, 1.0), '1% of 100 = 1');
assert_eq(500000, Rewards::earnMicro(50 * Rewards::MICRO, 1.0), '1% of 50 sats = 0.5 sat (500,000 micro)');
assert_eq(12500, Rewards::earnMicro(Rewards::toMicro('0.50'), 2.5), '2.5% of $0.50 = $0.0125');
assert_eq(0, Rewards::earnMicro(0, 1.0), 'nothing paid, nothing earned');
assert_eq(0, Rewards::earnMicro(1000, 0.0), 'zero percent earns nothing');
assert_eq(1234, Rewards::earnMicro(1234 * 100, 1.0), 'exact');
assert_eq('sat', Rewards::normalizeCurrency('SATS'));
assert_eq('EUR', Rewards::normalizeCurrency(' eur '));

// ---------- accounts ----------
$a = Rewards::getOrCreateAccount('s_usd', 'Alice');
assert_true(str_starts_with($a['id'], 'rwa_'), 'account id prefix');
assert_eq('Alice', $a['username']);
assert_eq('alice', $a['username_key']);
assert_eq(0, (int)$a['balance_micro']);
$same = Rewards::getOrCreateAccount('s_usd', 'ALICE');
assert_eq($a['id'], $same['id'], 'case-insensitive match returns the same account');
assert_eq('Alice', $same['username'], 'display name is the first spelling');
assert_not_null(Rewards::getAccount('s_usd', 'alice'));
assert_null(Rewards::getAccount('s_usd', 'bob'), 'unknown username');
assert_null(Rewards::getAccount('s_sat', 'alice'), 'accounts are per store');
$aSat = Rewards::getOrCreateAccount('s_sat', 'alice');
assert_neq($a['id'], $aSat['id'], 'same username on another store is another account');
expect_invalid(fn() => Rewards::getOrCreateAccount('s_usd', 'a b'), 'invalid username on create');

// ---------- movements ----------
$cur = 'USD';
assert_true(Rewards::credit($a['id'], 'inv_e1', 1500000, $cur), 'earn credited');
assert_false(Rewards::credit($a['id'], 'inv_e1', 1500000, $cur), 'same invoice earn is idempotent');
$row = Rewards::getAccountById($a['id']);
assert_eq(1500000, (int)$row['balance_micro'], 'balance after one earn');
assert_eq(1500000, (int)$row['earned_micro'], 'lifetime earned');
assert_true(Rewards::credit($a['id'], 'inv_e2', 500000, $cur));
assert_eq(2000000, (int)Rewards::getAccountById($a['id'])['balance_micro'], '$2.00 after two earns');
assert_false(Rewards::credit($a['id'], 'inv_e3', 0, $cur), 'zero credit is a no-op');

// Reserve: short balance refused, exact balance allowed, duplicate refused.
assert_false(Rewards::reserve($a['id'], 'inv_s1', 2500000, $cur), 'cannot reserve more than the balance');
assert_eq(2000000, (int)Rewards::getAccountById($a['id'])['balance_micro'], 'refused reservation leaves balance');
assert_false(Rewards::hasKind('inv_s1', Rewards::KIND_RESERVE), 'refused reservation leaves no ledger row');
assert_true(Rewards::reserve($a['id'], 'inv_s1', 1200000, $cur), 'reservation within balance');
assert_false(Rewards::reserve($a['id'], 'inv_s1', 100000, $cur), 'one reservation per invoice');
$row = Rewards::getAccountById($a['id']);
assert_eq(800000, (int)$row['balance_micro'], 'spendable drops');
assert_eq(1200000, (int)$row['reserved_micro'], 'reserved grows');
assert_eq(1200000, Rewards::reservationMicro($a['id'], 'inv_s1'), 'reservation readable');
assert_eq(0, Rewards::reservationMicro($a['id'], 'inv_none'));

// Two open invoices cannot both spend the same balance.
assert_true(Rewards::reserve($a['id'], 'inv_s2', 800000, $cur), 'second invoice takes the rest');
assert_false(Rewards::reserve($a['id'], 'inv_s3', 1, $cur), 'nothing left for a third');

// Commit the first, release the second.
assert_true(Rewards::commit($a['id'], 'inv_s1', $cur), 'commit on settle');
assert_false(Rewards::commit($a['id'], 'inv_s1', $cur), 'commit is idempotent');
assert_false(Rewards::release($a['id'], 'inv_s1', $cur), 'committed reservation cannot be released');
$row = Rewards::getAccountById($a['id']);
assert_eq(800000, (int)$row['reserved_micro'], 'only the second reservation is still held');
assert_eq(1200000, (int)$row['spent_micro'], 'lifetime spent');
assert_true(Rewards::release($a['id'], 'inv_s2', $cur, 'expired'), 'release on expiry');
assert_false(Rewards::release($a['id'], 'inv_s2', $cur), 'release is idempotent');
assert_false(Rewards::commit($a['id'], 'inv_s2', $cur), 'released reservation cannot be committed');
$row = Rewards::getAccountById($a['id']);
assert_eq(800000, (int)$row['balance_micro'], 'released micro is spendable again');
assert_eq(0, (int)$row['reserved_micro'], 'nothing held');
assert_false(Rewards::commit($a['id'], 'inv_never', $cur), 'commit without reservation');
assert_false(Rewards::release($a['id'], 'inv_never', $cur), 'release without reservation');

// Reverse an earn: floors at the spendable balance, never negative.
assert_true(Rewards::credit($a['id'], 'inv_e4', 3000000, $cur), 'earn $3.00');
assert_true(Rewards::reserve($a['id'], 'inv_s4', 3500000, $cur), 'reserve most of it');
// balance now 300000; reversing inv_e4 ($3.00) can only take what is spendable.
assert_true(Rewards::reverseEarn($a['id'], 'inv_e4', $cur), 'reverse');
assert_false(Rewards::reverseEarn($a['id'], 'inv_e4', $cur), 'reverse is idempotent');
assert_false(Rewards::reverseEarn($a['id'], 'inv_never', $cur), 'nothing to reverse');
$row = Rewards::getAccountById($a['id']);
assert_eq(0, (int)$row['balance_micro'], 'balance floored at zero');
assert_eq(3500000, (int)$row['reserved_micro'], 'reservations untouched by a reversal');
assert_eq(2000000, (int)$row['earned_micro'], 'lifetime earned reduced by the reversed earn');

// Ledger: every movement is one row with the right sign.
$ledger = Rewards::ledgerForAccount($a['id']);
$kinds = array_count_values(array_column($ledger, 'kind'));
assert_eq(3, $kinds['earn'], 'three earns');
assert_eq(3, $kinds['reserve'], 'three reservations');
assert_eq(1, $kinds['commit']);
assert_eq(1, $kinds['release']);
assert_eq(1, $kinds['reverse']);
foreach ($ledger as $l) {
    assert_eq('USD', $l['currency'], 'ledger rows carry the currency');
    if ($l['kind'] === 'reserve') assert_true((int)$l['delta_micro'] < 0, 'reserve is negative');
    if ($l['kind'] === 'earn') assert_true((int)$l['delta_micro'] > 0, 'earn is positive');
    if ($l['kind'] === 'release') assert_true((int)$l['delta_micro'] > 0, 'release is positive');
}
assert_eq('expired', Database::fetchOne("SELECT note FROM rewards_ledger WHERE invoice_id = 'inv_s2' AND kind = 'release'")['note']);

// A movement joins an open transaction instead of opening its own.
Database::beginImmediate();
assert_true(Rewards::credit($a['id'], 'inv_tx', 10, $cur), 'credit inside a caller transaction');
assert_true(Database::inTransaction(), 'caller transaction still open');
Database::rollback();
assert_false(Rewards::hasKind('inv_tx', 'earn'), 'rolled back with the caller');
assert_eq(0, (int)Rewards::getAccountById($a['id'])['balance_micro']);

// Unknown account throws.
try {
    Rewards::credit('rwa_missing', 'inv_x', 5, $cur);
    fail('credit to a missing account must throw');
} catch (RuntimeException $e) {
}

// ---------- listing ----------
Rewards::getOrCreateAccount('s_usd', 'Bob');
Rewards::getOrCreateAccount('s_usd', '5551234567');
assert_eq(3, Rewards::countAccounts('s_usd'), 'three accounts on the USD store');
assert_eq(1, Rewards::countAccounts('s_sat'));
assert_eq(4, Rewards::countAccounts(null), 'all stores');
assert_eq(4, Rewards::countAccounts('__all__'), '__all__ means every store');
assert_eq(1, Rewards::countAccounts('s_usd', 'bob'), 'search by username');
assert_eq(2, Rewards::countAccounts(null, 'ALICE'), 'search is case-insensitive across stores');
assert_eq(1, Rewards::countAccounts('s_usd', '555'), 'substring search');
assert_eq(0, Rewards::countAccounts('s_usd', '%'), 'LIKE wildcards are escaped');
$list = Rewards::listAccounts('s_usd');
assert_eq(3, count($list));
assert_eq('USD', $list[0]['currency'], 'list rows carry the store rewards currency');
assert_not_null($list[0]['store_name']);
assert_eq('Alice', Rewards::listAccounts('s_usd', 'ali')[0]['username']);
assert_eq(2, count(Rewards::listAccounts('s_usd', '', 2, 0)), 'limit');
assert_eq(1, count(Rewards::listAccounts('s_usd', '', 2, 2)), 'offset');

$fmt = Rewards::formatAccountForApi($list[0], Config::getStore('s_usd'));
foreach (['id', 'storeId', 'username', 'currency', 'balanceMicro', 'balance', 'balanceDisplay', 'reserved', 'earned', 'spent',
          'balanceSats', 'balanceFiat', 'balanceFiatCurrency', 'lastActivityAt'] as $k) {
    assert_true(array_key_exists($k, $fmt), "api shape has $k");
}
$t = Rewards::storeTotals('s_usd');
assert_eq(3, $t['accounts']);
assert_eq(3500000, $t['reservedMicro'], 'totals: reserved');
assert_eq(0, $t['balanceMicro']);

// ---------- currency switch ----------
assert_false(Rewards::currencySwitchNeedsDecision(Config::getStore('s_usd'), 'usd'), 'same currency: no decision');
assert_true(Rewards::currencySwitchNeedsDecision(Config::getStore('s_usd'), 'EUR'), 'other currency with balances: decision');
make_store('s_empty', 'https://mint.example');
Rewards::setStoreSettings('s_empty', true, 1.0);
assert_false(Rewards::currencySwitchNeedsDecision(Config::getStore('s_empty'), 'EUR'), 'no accounts: no decision');
assert_false(Rewards::currencySwitchNeedsDecision(['id' => 's_empty'], 'EUR'), 'rewards never enabled: no decision');
// "convert" without a reachable rate changes nothing.
try {
    Rewards::convertStoreCurrency('s_usd', 'GBP');
    fail('convert without a rate must throw');
} catch (RuntimeException $e) {
}
assert_eq('USD', Rewards::forStore('s_usd')['currency'], 'currency unchanged after a failed convert');
assert_eq(3500000, Rewards::storeTotals('s_usd')['reservedMicro'], 'balances unchanged after a failed convert');
// Same-currency convert and "keep" are no-ops on balances.
Rewards::convertStoreCurrency('s_usd', 'usd');
assert_eq('USD', Rewards::forStore('s_usd')['currency']);
Rewards::keepCurrency('s_usd');
assert_eq(3500000, Rewards::storeTotals('s_usd')['reservedMicro']);
// A real convert: USD -> EUR halves every figure, including the open
// reservation recorded on the invoice, and stamps one ledger row per account.
$now = time();
Database::insert('invoices', ['id' => 'inv_open', 'store_id' => 's_usd', 'status' => 'New', 'amount' => '10', 'currency' => 'USD',
    'amount_sats' => 10000, 'created_at' => $now, 'expiration_time' => $now + 900,
    'rewards_account_id' => $a['id'], 'rewards_applied_micro' => 3500000, 'rewards_spend_state' => 'reserved']);
Rewards::credit(Rewards::getAccount('s_usd', 'bob')['id'], 'inv_bob', 1000000, 'USD');
Rewards::convertStoreCurrency('s_usd', 'EUR');
assert_eq('EUR', Rewards::forStore('s_usd')['currency'], 'rewards currency moved');
$t = Rewards::storeTotals('s_usd');
assert_eq(1750000, $t['reservedMicro'], 'reserved halved');
assert_eq(500000, $t['balanceMicro'], 'spendable halved');
assert_eq(1000000, (int)Rewards::getAccountById($a['id'])['earned_micro'], 'lifetime earned halved');
assert_eq(600000, (int)Rewards::getAccountById($a['id'])['spent_micro'], 'lifetime spent halved');
assert_eq(1750000, (int)Database::fetchOne("SELECT rewards_applied_micro FROM invoices WHERE id = 'inv_open'")['rewards_applied_micro'], 'open reservation on the invoice converted');
assert_eq(3, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM rewards_ledger WHERE kind = 'convert' AND store_id = 's_usd'")['n'], 'one convert row per account');
assert_eq('EUR', Database::fetchOne("SELECT currency FROM rewards_ledger WHERE kind = 'convert' LIMIT 1")['currency']);

// Deleting a store cascades to its accounts and ledger.
Database::query("PRAGMA foreign_keys = ON");
Database::delete('stores', 'id = ?', ['s_sat']);
assert_eq(0, Rewards::countAccounts('s_sat'), 'accounts cascade with the store');

echo "test_rewards_module: ok\n";
