<?php
/**
 * EcxRates against a live (mock) CoinMarketCap endpoint — the path the unit
 * test with the $testEcxUsd seam never exercises:
 *   - a fresh quote is fetched once, cached (rate_ECX_USD + rate_lastgood_ECX_USD,
 *     provider 'coinmarketcap') and served from cache for CACHE_TTL without a
 *     second request;
 *   - during an outage a quote younger than STALE_TTL is still served, the
 *     endpoint enters the `rates:cmc` cooldown and is not contacted again
 *     while cooling down; a quote older than STALE_TTL yields null (eCash is
 *     then left off invoices) while lastGoodUsd() keeps it for display;
 *   - an empty payload, an insane price and a non-JSON body are all "no
 *     price" (null) and start a cooldown, never a stale guess;
 *   - recovery: the next live quote clears the cooldown and refreshes both
 *     cache keys.
 * Driven over real HTTP against a php -S stub whose behaviour is switched
 * through files, so SafeHttp (private-endpoint gate included) is in the loop.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/ecx/rates.php';

// ---- stub CoinMarketCap -----------------------------------------------------------
$probe = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$name = stream_socket_get_name($probe, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($probe);
$dir = sys_get_temp_dir() . '/cmc_stub_' . bin2hex(random_bytes(4));
mkdir($dir, 0750, true);
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$mode = trim((string)@file_get_contents(__DIR__ . '/mode')) ?: 'ok';
$rawPrice = trim((string)@file_get_contents(__DIR__ . '/price'));
$price = $rawPrice === '' ? 41.5 : (float)$rawPrice;
file_put_contents(__DIR__ . '/hits', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
if ($mode === 'down') { http_response_code(500); echo 'cmc down'; exit; }
if ($mode === 'garbage') { header('Content-Type: text/html'); echo '<html>captcha</html>'; exit; }
header('Content-Type: application/json');
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$quotes = $mode === 'empty' ? [] : [['name' => (string)($q['convertId'] ?? ''), 'price' => $price]];
echo json_encode(['data' => [['id' => (int)($q['id'] ?? 0), 'symbol' => 'ECX', 'quotes' => $quotes]], 'status' => ['error_code' => '0']]);
PHP);
file_put_contents($dir . '/mode', 'ok');
file_put_contents($dir . '/price', '41.5');
file_put_contents($dir . '/hits', '');
$pid = (int)shell_exec(sprintf('%s -S 127.0.0.1:%d -t %s %s >/dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($dir), escapeshellarg($dir . '/router.php')));
register_shutdown_function(function () use ($pid, $dir) { @posix_kill($pid, 15); @cleanup_db($dir); });
for ($i = 0; $i < 120; $i++) {
    $h = @fopen("http://127.0.0.1:$port/", 'r');
    if ($h) { fclose($h); break; }
    usleep(50000);
}
file_put_contents($dir . '/hits', '');
function cmc_hits(string $dir): array {
    return array_values(array_filter(explode("\n", (string)file_get_contents($dir . '/hits'))));
}
function cmc_mode(string $dir, string $mode, ?string $price = null): void {
    file_put_contents($dir . '/mode', $mode);
    if ($price !== null) {
        file_put_contents($dir . '/price', $price);
    }
}
function cmc_clear_cooldown(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'endpoint_health_%'");
    Config::clearCache();
}
function cmc_age_cache(int $ageSec): void {
    $data = Config::get('rate_ECX_USD');
    $data['timestamp'] = time() - $ageSec;
    Config::set('rate_ECX_USD', $data);
}

putenv("CASHUPAY_CMC_API_BASE=http://127.0.0.1:$port");
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
Config::set('allow_private_endpoints', null);
assert_eq("http://127.0.0.1:$port", EcxRates::apiBase());

// ---- 1. live fetch, then cache ---------------------------------------------------------
assert_null(Config::get('rate_ECX_USD'), 'no cache yet');
assert_eq(41.5, EcxRates::getEcxUsd(), 'live quote');
$hits = cmc_hits($dir);
assert_eq(1, count($hits), 'exactly one request');
assert_true(str_contains($hits[0], '/data-api/v3/cryptocurrency/quote/latest?id=42032&convertId=2781'), 'CMC quote URL: ' . $hits[0]);
$cached = Config::get('rate_ECX_USD');
assert_eq(41.5, (float)$cached['rate']);
assert_eq('coinmarketcap', $cached['provider']);
assert_true(abs(time() - (int)$cached['timestamp']) < 5, 'fresh timestamp');
assert_eq(41.5, EcxRates::lastGoodUsd(), 'last-good mirrors the quote');
assert_eq(41.5, EcxRates::getEcxUsd());
assert_eq(41.5, EcxRates::getEcxUsd());
assert_eq(1, count(cmc_hits($dir)), 'served from cache: no further requests within CACHE_TTL');
assert_false(EndpointHealth::isCoolingDown('rates:cmc'));

// ---- 2. outage with a stale-but-usable cache ---------------------------------------------
cmc_mode($dir, 'down');
cmc_age_cache(EcxRates::CACHE_TTL + 60); // too old for the fresh path, young enough for the stale path
assert_eq(41.5, EcxRates::getEcxUsd(), 'provider down -> the stale quote (< STALE_TTL) is still served');
assert_eq(2, count(cmc_hits($dir)), 'the outage cost one request');
assert_true(EndpointHealth::isCoolingDown('rates:cmc'), 'failure recorded under rates:cmc');
assert_eq(41.5, EcxRates::getEcxUsd());
assert_eq(2, count(cmc_hits($dir)), 'cooling down: not contacted again');
// Stale beyond STALE_TTL: nothing to serve, eCash is left off invoices.
cmc_age_cache(EcxRates::STALE_TTL + 60);
assert_null(EcxRates::getEcxUsd(), 'quote older than an hour is never used');
assert_eq(41.5, EcxRates::lastGoodUsd(), 'display-only last price survives');
assert_null(EcxRates::convertToSzats('10', 'USD'), 'no szat amount without a price');

// ---- 3. unusable answers each start a cooldown and never produce a price ----------------
foreach ([['empty', '41.5'], ['ok', '5000000'], ['ok', '0'], ['garbage', '41.5']] as [$mode, $price]) {
    cmc_clear_cooldown();
    cmc_mode($dir, $mode, $price);
    $before = count(cmc_hits($dir));
    assert_null(EcxRates::getEcxUsd(), "mode={$mode} price={$price} -> no price");
    assert_eq($before + 1, count(cmc_hits($dir)), "mode={$mode}: one request");
    assert_true(EndpointHealth::isCoolingDown('rates:cmc'), "mode={$mode}: cooldown started");
    assert_eq(41.5, EcxRates::lastGoodUsd(), "mode={$mode}: last-good untouched");
}

// ---- 4. recovery clears the cooldown and refreshes both keys ------------------------------
cmc_clear_cooldown();
cmc_mode($dir, 'ok', '42.25');
assert_eq(42.25, EcxRates::getEcxUsd(), 'back to a live quote');
assert_false(EndpointHealth::isCoolingDown('rates:cmc'), 'success clears the cooldown');
assert_eq(42.25, (float)Config::get('rate_ECX_USD')['rate']);
assert_eq(42.25, EcxRates::lastGoodUsd());

// ---- 5. without the private-endpoints opt-in the LAN seam is ignored ------------------------
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=0');
assert_eq('https://api.coinmarketcap.com', EcxRates::apiBase(), 'a stray CASHUPAY_CMC_API_BASE cannot redirect a stock install');
// (not fetched here: the cache is fresh, so no network call is made)
assert_eq(42.25, EcxRates::getEcxUsd());
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');
putenv('CASHUPAY_CMC_API_BASE');

echo "ok\n";
