<?php
/**
 * EcxRates: CoinMarketCap payload parsing, the no-price policy (null, never a
 * guess), szat conversion for fiat / sats / cross-currency invoices via the
 * Bitcoin providers' USD rate, and the fiat / sats-equivalent helpers.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/ecx/rates.php';

// ---- parseCmcUsd ------------------------------------------------------------
$body = json_encode(['data' => [[
    'id' => 42032, 'name' => 'eCash', 'symbol' => 'ECX', 'slug' => 'ecashdotcom',
    'quotes' => [['name' => '2781', 'price' => 41.78958023164774, 'volume24h' => 22880.7]],
]]]);
assert_eq(41.78958023164774, EcxRates::parseCmcUsd($body), 'price of the USD quote (convert id 2781)');
// Wrong listing id / wrong convert id / missing price -> null (not an outage).
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 1, 'quotes' => [['name' => '2781', 'price' => 5]]]]])));
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 42032, 'quotes' => [['name' => '2790', 'price' => 5]]]]])));
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 42032, 'quotes' => [['name' => '2781']]]]])));
assert_null(EcxRates::parseCmcUsd(json_encode(['status' => ['error_code' => 400]])));
$threw = false;
try { EcxRates::parseCmcUsd('<html>nope</html>'); } catch (RuntimeException $e) { $threw = true; }
assert_true($threw, 'malformed JSON throws (provider failure, cooldown)');

// Hostile shapes: a price that is present but not a plain decimal is a
// malformed body (throw -> cooldown), never silently 0/1/INF; wrong types
// elsewhere are skipped without PHP warnings; nulls mean "no price yet".
$row = fn(array $quote) => json_encode(['data' => [['id' => 42032, 'quotes' => [$quote]]]]);
foreach (['[41.78]', '{"v":41.78}', 'true', '"1e400"', '"1e2"', '" 41.78"', '"41.78\\n"', '"-41.78"', '"0x29"'] as $bad) {
    $threw = false;
    try { EcxRates::parseCmcUsd(str_replace('"__P__"', $bad, $row(['name' => '2781', 'price' => '__P__']))); } catch (RuntimeException $e) { $threw = true; }
    assert_true($threw, "non-decimal price {$bad} is malformed");
}
assert_null(EcxRates::parseCmcUsd($row(['name' => '2781', 'price' => null])), 'null price = no price yet');
assert_eq(41.78, EcxRates::parseCmcUsd($row(['name' => 2781, 'price' => '41.78'])), 'integer convert id + string price accepted');
assert_null(EcxRates::parseCmcUsd($row(['name' => ['2781'], 'price' => 5])), 'array convert id is skipped (no Array-to-string warning)');
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => [42032], 'quotes' => [['name' => '2781', 'price' => 5]]]]])), 'array coin id is skipped');
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 42032, 'quotes' => 'nope']]])), 'quotes not a list -> no price');
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 42032, 'quotes' => ['nope']]]])), 'quote not an object -> no price');
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => 'nope'])), 'data not a list -> no price');
assert_null(EcxRates::parseCmcUsd(json_encode(['data' => [['id' => 42032.5, 'quotes' => [['name' => '2781', 'price' => 5]]]]])), 'fractional id does not match');
$threw = false;
try { EcxRates::parseCmcUsd(str_repeat('[', 40) . '1' . str_repeat(']', 40)); } catch (RuntimeException $e) { $threw = true; }
assert_true($threw, 'deep nesting is malformed');
$threw = false;
try { EcxRates::parseCmcUsd("\x1b[31m" . $row(['name' => '2781', 'price' => 41.78])); } catch (RuntimeException $e) { $threw = true; }
assert_true($threw, 'escape-prefixed body is malformed');

// Cache rows of the wrong shape are ignored (and never fatal).
foreach (['abc', ['x' => 1], true, '1e3', -1, 0] as $garbage) {
    Config::set('rate_ECX_USD', ['rate' => $garbage, 'timestamp' => time(), 'provider' => 'test']);
    Config::set('rate_lastgood_ECX_USD', ['rate' => $garbage, 'timestamp' => time(), 'provider' => 'test']);
    assert_null(EcxRates::lastGoodUsd(), 'garbage last-good ' . json_encode($garbage) . ' ignored');
}
Config::set('rate_ECX_USD', ['rate' => 40.0, 'timestamp' => 'yesterday', 'provider' => 'test']);
Config::set('rate_lastgood_ECX_USD', 'not-an-object');
assert_null(EcxRates::lastGoodUsd(), 'non-object last-good row ignored');
Config::set('rate_ECX_USD', null);
Config::set('rate_lastgood_ECX_USD', null);

// ---- quote URL + env seam gate -------------------------------------------------
assert_eq('https://api.coinmarketcap.com/data-api/v3/cryptocurrency/quote/latest?id=42032&convertId=2781',
    EcxRates::quoteUrl());
putenv('CASHUPAY_CMC_API_BASE=http://127.0.0.1:9/cmc');
Config::set('allow_private_endpoints', null);
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=0');
assert_eq('https://api.coinmarketcap.com', EcxRates::apiBase(), 'seam ignored without the private-endpoints opt-in');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
assert_eq('http://127.0.0.1:9/cmc', EcxRates::apiBase(), 'seam honoured with the opt-in');
putenv('CASHUPAY_CMC_API_BASE');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');

// ---- no price -> null everywhere -----------------------------------------------
EcxRates::$testEcxUsd = 0.0; // simulate "no quote"
assert_null(EcxRates::getEcxUsd());
assert_null(EcxRates::getEcxPrice('USD'));
assert_null(EcxRates::convertToSzats('10', 'USD'), 'no price -> no szat amount (eCash left off the invoice)');
assert_null(EcxRates::szatsToFiat(100, 'USD'));
assert_null(EcxRates::szatToSatRatio());

// ---- conversions ---------------------------------------------------------------
EcxRates::$testEcxUsd = 40.0;
// Scripted BTC providers: USD 100k, EUR 80k.
$btc = new class implements PriceProvider {
    public function getName(): string { return 'scripted'; }
    public function getBtcPrice(string $currency): ?float {
        return match (strtoupper($currency)) { 'USD' => 100000.0, 'EUR' => 80000.0, default => null };
    }
};
ExchangeRates::$testProviders = ['scripted' => $btc];

assert_eq(40.0, EcxRates::getEcxPrice('USD'));
$q = EcxRates::convertToSzats('10', 'USD');
assert_eq(25000000, $q['szats'], '$10 at $40/ECX = 0.25 ECX = 25,000,000 szats');
assert_eq(40.0, $q['rate']);
// EUR via the cross rate: ECX/EUR = 40 * 80000 / 100000 = 32.
assert_eq(32.0, EcxRates::getEcxPrice('EUR'));
assert_eq(50000000, EcxRates::convertToSzats('16', 'EUR')['szats'], '16 EUR / 32 = 0.5 ECX');
// Sats invoice through the BTC:ECX ratio: 1 BTC = 100000/40 = 2500 ECX, so
// 10,000 sats = 0.0001 BTC = 0.25 ECX.
$qs = EcxRates::convertToSzats('10000', 'sat');
assert_eq(25000000, $qs['szats'], '10,000 sats -> 25,000,000 szats');
assert_eq(25000000, EcxRates::convertToSzats('10000', 'SATS')['szats'], 'SATS alias');
assert_eq(250000000000, EcxRates::convertToSzats('1', 'BTC')['szats'], '1 BTC -> 2500 ECX = 2.5e11 szats');
// Rounding: half-up on the last szat, never a szat short.
assert_eq(3, EcxRates::convertToSzats('0.000001', 'USD')['szats'], '$0.000001 / 40 = 2.5 szats -> 3');
// Fiat display + fold ratio.
assert_eq('10.00', EcxRates::szatsToFiat(25000000, 'USD'));
assert_eq(0.0004, EcxRates::szatToSatRatio(), '1 szat = 40/100000 sat');
// A currency none of the BTC providers quote -> null (not an exception).
assert_null(EcxRates::getEcxPrice('XYZ'));
assert_null(EcxRates::convertToSzats('5', 'XYZ'));

ExchangeRates::$testProviders = null;
EcxRates::$testEcxUsd = null;
echo "ok\n";
