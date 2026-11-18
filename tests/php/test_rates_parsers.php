<?php
/**
 * Price-feed response parsing (CoinGecko, Binance, Kraken) against hostile
 * and malformed bodies. The contract (see PriceProvider in rates.php):
 *   - currency genuinely not listed      -> null  (per-currency miss, no cooldown)
 *   - body not the documented shape      -> throw (provider failure, cooldown)
 *   - price present but not a decimal    -> throw (malformed, cooldown)
 *   - price a decimal but out of band    -> returned; getBtcPrice() rejects it
 * plus the rate helpers (requireRate / sanitizeRate / isSaneRate / fmt) and
 * the end-to-end effect through getBtcPrice() with scripted providers.
 *
 * Network-free: the parse*() methods take the body as a string.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

function expect_throw(callable $fn, string $msg): string {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    fail($msg . ' (did not throw)');
    return '';
}

$hostileScalars = [
    '"95000"' => 'quoted number with no object',
    '[1e400]' => 'INF inside array',
    'true' => 'bare true',
    '' => 'empty body',
    '<html><body>Rate limited</body></html>' => 'HTML page',
    "\x1b[31m{\"bitcoin\":{\"usd\":1}}" => 'escape-prefixed JSON',
    str_repeat('[', 40) . '1' . str_repeat(']', 40) => 'nesting beyond cap',
];
$badPrices = ['"1e5"', '"1e400"', '" 95000"', '"95,000"', 'true', '[95000]', '{"v":95000}', '"-5"', '-5', '"0x1A"', '"95000\\n"', 'null'];

// ---- CoinGecko ----------------------------------------------------------------
assert_eq(95000.12, CoinGeckoProvider::parsePrice('{"bitcoin":{"usd":95000.12}}', 'USD'), 'CoinGecko happy path (case-insensitive currency)');
assert_eq(95000.0, CoinGeckoProvider::parsePrice('{"bitcoin":{"eur":"95000"}}', 'eur'), 'CoinGecko string price accepted');
assert_null(CoinGeckoProvider::parsePrice('{"bitcoin":{}}', 'xyz'), 'CoinGecko: unknown currency -> null');
assert_null(CoinGeckoProvider::parsePrice('{"bitcoin":{"usd":1}}', 'eur'), 'CoinGecko: other currency only -> null');
foreach ($hostileScalars as $body => $why) {
    expect_throw(fn() => CoinGeckoProvider::parsePrice($body, 'usd'), "CoinGecko throws on {$why}");
}
expect_throw(fn() => CoinGeckoProvider::parsePrice('{"status":{"error_code":429}}', 'usd'), 'CoinGecko: no bitcoin object -> malformed');
expect_throw(fn() => CoinGeckoProvider::parsePrice('{"bitcoin":"95000"}', 'usd'), 'CoinGecko: bitcoin not an object -> malformed');
expect_throw(fn() => CoinGeckoProvider::parsePrice('{"bitcoin":[95000]}', 'usd'), 'CoinGecko: bitcoin list -> malformed');
foreach ($badPrices as $v) {
    expect_throw(fn() => CoinGeckoProvider::parsePrice('{"bitcoin":{"usd":' . $v . '}}', 'usd'), "CoinGecko: price {$v} is malformed");
}
// Out-of-band but decimal: parse returns it; the caller rejects it.
assert_eq(0.0, CoinGeckoProvider::parsePrice('{"bitcoin":{"usd":0}}', 'usd'), 'CoinGecko: 0 parses (caller treats as insane)');
assert_eq(1.0E+20, CoinGeckoProvider::parsePrice('{"bitcoin":{"usd":1e20}}', 'usd'), 'CoinGecko: 1e20 JSON number parses (caller treats as insane)');

// ---- Binance ------------------------------------------------------------------
assert_eq(95000.5, BinanceProvider::parsePrice('{"symbol":"BTCUSDT","price":"95000.50000000"}', 'BTCUSDT'), 'Binance happy path');
assert_eq(95000.0, BinanceProvider::parsePrice('{"price":95000}', 'BTCUSDT'), 'Binance: symbol optional');
assert_null(BinanceProvider::parsePrice('{"symbol":"BTCUSDT"}', 'BTCUSDT'), 'Binance: no price -> null');
expect_throw(fn() => BinanceProvider::parsePrice('{"symbol":"ETHUSDT","price":"3000"}', 'BTCUSDT'), 'Binance: wrong symbol -> malformed (not priced as BTC)');
expect_throw(fn() => BinanceProvider::parsePrice('{"symbol":["BTCUSDT"],"price":"3000"}', 'BTCUSDT'), 'Binance: symbol array -> malformed');
foreach ($hostileScalars as $body => $why) {
    expect_throw(fn() => BinanceProvider::parsePrice($body, 'BTCUSDT'), "Binance throws on {$why}");
}
foreach ($badPrices as $v) {
    expect_throw(fn() => BinanceProvider::parsePrice('{"symbol":"BTCUSDT","price":' . $v . '}', 'BTCUSDT'), "Binance: price {$v} is malformed");
}

// ---- Kraken -------------------------------------------------------------------
$kraken = '{"error":[],"result":{"XXBTZUSD":{"a":["95001.0","1","1.000"],"c":["95000.1","0.05000000"]}}}';
assert_eq(95000.1, KrakenProvider::parsePrice($kraken), 'Kraken happy path');
assert_null(KrakenProvider::parsePrice('{"error":[],"result":{}}'), 'Kraken: empty result -> null');
assert_null(KrakenProvider::parsePrice('{"error":[]}'), 'Kraken: no result -> null');
assert_null(KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":{"a":["1"]}}}'), 'Kraken: no close price -> null');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":{"c":[]}}}'), 'Kraken: empty close list -> throw');
$msg = expect_throw(fn() => KrakenProvider::parsePrice('{"error":["EService:Unavailable"],"result":{}}'), 'Kraken: in-band error -> throw');
assert_true(str_contains($msg, 'EService:Unavailable'), 'Kraken error text is reported');
$msg = expect_throw(fn() => KrakenProvider::parsePrice("{\"error\":[\"\\u001b[31mEVIL\\nFORGED LINE\"],\"result\":{}}"), 'Kraken: hostile error text -> throw');
assert_false(str_contains($msg, "\x1b") || str_contains($msg, "\n"), 'Kraken error text is sanitized before it reaches the log');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":{"c":["1"]},"XXBTZEUR":{"c":["1"]}}}'), 'Kraken: two pairs -> ambiguous -> throw');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":"nope"}'), 'Kraken: result not an object -> throw');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":"95000"}}'), 'Kraken: pair not an object -> throw');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":{"c":[["95000"]]}}}'), 'Kraken: nested array close -> throw (was silently 1.0)');
expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"XXBTZUSD":{"c":"95000"}}}'), 'Kraken: c not a list -> throw');
foreach ($hostileScalars as $body => $why) {
    expect_throw(fn() => KrakenProvider::parsePrice($body), "Kraken throws on {$why}");
}
foreach ($badPrices as $v) {
    expect_throw(fn() => KrakenProvider::parsePrice('{"error":[],"result":{"X":{"c":[' . $v . ',"1"]}}}'), "Kraken: close {$v} is malformed");
}

// ---- rate helpers ---------------------------------------------------------------
assert_true(ExchangeRates::isSaneRate(95000.0), '95k sane');
assert_true(ExchangeRates::isSaneRate(ExchangeRates::MIN_SANE_RATE), 'lower bound inclusive');
assert_true(ExchangeRates::isSaneRate(ExchangeRates::MAX_SANE_RATE), 'upper bound inclusive');
assert_true(ExchangeRates::isSaneRate(15000000.0), 'JPY-scale rate sane');
foreach ([0.0, -1.0, 0.0000001, 1.0E+13 + 1, 1.0E+20, INF, -INF, NAN] as $bad) {
    assert_false(ExchangeRates::isSaneRate($bad), 'insane: ' . var_export($bad, true));
}
assert_eq(95000.5, ExchangeRates::requireRate('95000.5', 'x'), 'requireRate accepts a decimal string');
expect_throw(fn() => ExchangeRates::requireRate([1], 'x'), 'requireRate refuses an array');
expect_throw(fn() => ExchangeRates::requireRate('1e5', 'x'), 'requireRate refuses exponent strings');
assert_eq(95000.0, ExchangeRates::sanitizeRate(95000), 'sanitizeRate accepts in-band int');
assert_null(ExchangeRates::sanitizeRate(0), 'sanitizeRate refuses 0');
assert_null(ExchangeRates::sanitizeRate('abc'), 'sanitizeRate refuses garbage');
assert_null(ExchangeRates::sanitizeRate(1e14), 'sanitizeRate refuses out of band');

// fmt(): bcmath-safe spelling across the whole sane band.
foreach ([ExchangeRates::MIN_SANE_RATE, 0.00001, 0.5, 1.0, 95000.123456, 15000000.0, 9999999999999.0, ExchangeRates::MAX_SANE_RATE] as $r) {
    $s = ExchangeRates::fmt($r);
    assert_true(preg_match('/^[0-9]+(\.[0-9]+)?\z/', $s) === 1, "fmt({$r}) = {$s} has no exponent");
    bcdiv('1', $s, 8); // must not throw ValueError
}
assert_eq('0.000001', ExchangeRates::fmt(0.000001), 'fmt tiny');
assert_eq('10000000000000', ExchangeRates::fmt(1e13), 'fmt huge');
assert_eq('95000.5', ExchangeRates::fmt(95000.5), 'fmt trims zeros');

// ---- end to end through getBtcPrice() with scripted providers -------------------
final class BodyProvider implements PriceProvider {
    public int $calls = 0;
    public function __construct(private string $name, private string $body) {}
    public function getName(): string { return $this->name; }
    public function getBtcPrice(string $currency): ?float {
        $this->calls++;
        return CoinGeckoProvider::parsePrice($this->body, $currency);
    }
}
$evil = new BodyProvider('evil', '{"bitcoin":{"usd":[95000]}}');      // malformed -> throws
$huge = new BodyProvider('huge', '{"bitcoin":{"usd":1e20}}');         // insane -> rejected
$good = new BodyProvider('good', '{"bitcoin":{"usd":95000}}');
ExchangeRates::$testProviders = ['evil' => $evil, 'huge' => $huge, 'good' => $good];
assert_eq(95000.0, ExchangeRates::getBtcPrice('usd', 'evil', 'huge'), 'malformed and insane providers are skipped; healthy one prices');
assert_true(EndpointHealth::isCoolingDown('rates:evil'), 'malformed body earned a cooldown');
assert_true(EndpointHealth::isCoolingDown('rates:huge'), 'out-of-band price earned a cooldown');
assert_false(EndpointHealth::isCoolingDown('rates:good'), 'healthy provider not penalised');
$cached = Config::get('rate_usd');
assert_eq(95000.0, $cached['rate'], 'only the sane rate reached the cache');
assert_eq('good', $cached['provider'], 'cache records the healthy provider');
ExchangeRates::$testProviders = null;

echo "test_rates_parsers: all assertions passed\n";
