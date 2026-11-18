<?php
/**
 * CashuPayServer - eCash (ECX) exchange rates.
 *
 * ECX is young (forked 2026-10-29) and the regular exchange-rate providers
 * don't quote it yet: CoinGecko lists it (`ecash-com`) but returns no price.
 * CoinMarketCap does track it (UCID 42032, slug `ecashdotcom`), so the ECX/USD
 * price comes from CoinMarketCap's public web endpoint — the one its own site
 * reads — which needs no API key. Every other currency is derived through USD
 * with the regular Bitcoin providers (ECX/EUR = ECX/USD x BTC/EUR / BTC/USD),
 * so the operator's price-provider preferences keep applying and only one CMC
 * quote is ever needed.
 *
 * Policy decided with the operator: when no ECX price can be obtained (live
 * or cached) the eCash option is simply NOT offered on the invoice — never a
 * stale guess, never a blocked checkout.
 *
 * Cache: Config keys rate_ECX_USD (CACHE_TTL) and rate_lastgood_ECX_USD, the
 * same shape ExchangeRates uses for BTC, so the stats dashboard can read the
 * last-good price for display even during an outage.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rates.php';
require_once __DIR__ . '/../safe_http.php';
require_once __DIR__ . '/../endpoint_health.php';
require_once __DIR__ . '/../response_shape.php';

final class EcxRates {
    /** CoinMarketCap UCID of eCash (ECX, ecash.com). */
    public const CMC_ID = 42032;
    /** CoinMarketCap's convert id for USD. */
    public const CMC_USD_ID = 2781;

    /** Live quote reused for this long (same as the BTC providers). */
    public const CACHE_TTL = 300;
    /** A quote this old is still served when the provider is down. */
    public const STALE_TTL = 3600;

    private const CACHE_KEY = 'rate_ECX_USD';
    private const LASTGOOD_KEY = 'rate_lastgood_ECX_USD';
    private const HEALTH_KEY = 'rates:cmc';

    /**
     * Test seam: when set, getEcxUsd() returns this instead of contacting
     * CoinMarketCap (null = behave normally; 0.0 = simulate "no price").
     */
    public static ?float $testEcxUsd = null;

    /**
     * CoinMarketCap API base. Test rigs point this at a local mock via
     * CASHUPAY_CMC_API_BASE, honoured only while private endpoints are
     * allowed (the same opt-in the e2e rigs set), so a stray variable on a
     * stock deployment can never redirect price lookups.
     */
    public static function apiBase(): string {
        $env = getenv('CASHUPAY_CMC_API_BASE');
        if ($env !== false && trim($env) !== '' && \SafeHttp::privateEndpointsAllowed()) {
            return rtrim(trim($env), '/');
        }
        return 'https://api.coinmarketcap.com';
    }

    public static function quoteUrl(): string {
        return self::apiBase() . '/data-api/v3/cryptocurrency/quote/latest?id=' . self::CMC_ID
            . '&convertId=' . self::CMC_USD_ID;
    }

    /**
     * ECX price in USD: fresh cache, else a live CoinMarketCap quote, else the
     * stale cache. Null when none of those yields a price — the caller then
     * leaves eCash off the invoice. Never throws.
     */
    public static function getEcxUsd(): ?float {
        if (self::$testEcxUsd !== null) {
            return self::$testEcxUsd > 0 ? self::$testEcxUsd : null;
        }
        $cached = self::getCached(false);
        if ($cached !== null) {
            return $cached;
        }
        if (!EndpointHealth::isCoolingDown(self::HEALTH_KEY)) {
            try {
                $live = self::fetchCmcUsd();
                if ($live !== null && self::isSane($live)) {
                    EndpointHealth::recordSuccess(self::HEALTH_KEY);
                    self::saveToCache($live);
                    return $live;
                }
                error_log('EcxRates: CoinMarketCap returned no usable ECX price');
                EndpointHealth::recordFailure(self::HEALTH_KEY);
            } catch (\Throwable $e) {
                error_log('EcxRates: CoinMarketCap failed: ' . $e->getMessage());
                EndpointHealth::recordFailure(self::HEALTH_KEY);
            }
        }
        return self::getCached(true);
    }

    /**
     * Parse CoinMarketCap's quote payload. Public for the unit test.
     * Shape: {"data":[{"id":42032,"symbol":"ECX","quotes":[{"name":"2781","price":41.78}]}]}
     */
    public static function parseCmcUsd(string $body): ?float {
        $data = ResponseShape::decodeJsonArray($body);
        if ($data === null) {
            throw new RuntimeException('malformed JSON response');
        }
        $rows = $data['data'] ?? null;
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ResponseShape::integer($row['id'] ?? null) !== self::CMC_ID) {
                continue;
            }
            $quotes = $row['quotes'] ?? null;
            if (!is_array($quotes)) {
                continue;
            }
            foreach ($quotes as $q) {
                if (!is_array($q)) {
                    continue;
                }
                // `name` is the convert id as a string ("2781"); tolerate an int.
                if (ResponseShape::integer($q['name'] ?? null) !== self::CMC_USD_ID) {
                    continue;
                }
                if (!array_key_exists('price', $q) || $q['price'] === null) {
                    return null; // listed, but no price yet
                }
                $price = ResponseShape::decimal($q['price']);
                if ($price === null) {
                    // Present but not a plain decimal (array, bool, "1e400",
                    // control characters...): a malformed body, not "no price".
                    throw new RuntimeException('malformed response: price is not a decimal number');
                }
                return $price;
            }
        }
        return null;
    }

    private static function fetchCmcUsd(): ?float {
        $result = \SafeHttp::request(self::quoteUrl(), [
            'maxBytes' => 256 * 1024,
            'timeout' => 10,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => ['Accept: application/json'],
            // The seam above only applies while private endpoints are allowed,
            // so a LAN mock is reachable exactly when the operator opted in.
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '' || $result['status'] !== 200 || $result['body'] === '') {
            throw new RuntimeException("HTTP {$result['status']}: " . ($result['error'] ?: \SafeHttp::logExcerpt($result['body'])));
        }
        return self::parseCmcUsd($result['body']);
    }

    /** ECX is a sub-cent to a few-hundred-dollar asset; anything else is garbage. */
    private static function isSane(float $price): bool {
        return $price > 0.000001 && $price < 1000000;
    }

    /**
     * ECX price in $currency. USD straight from the quote; SAT/BTC as the
     * BTC:ECX ratio; any other fiat via the Bitcoin providers' cross rate.
     * Null when either leg is unavailable. Never throws.
     */
    public static function getEcxPrice(string $currency, ?string $primary = null, ?string $secondary = null): ?float {
        $currency = strtoupper($currency);
        $usd = self::getEcxUsd();
        if ($usd === null) {
            return null;
        }
        if ($currency === 'USD') {
            return $usd;
        }
        try {
            $btcUsd = ExchangeRates::getBtcPrice('USD', $primary, $secondary);
            if ($btcUsd === null || $btcUsd <= 0) {
                return null;
            }
            if ($currency === 'BTC') {
                return $usd / $btcUsd;
            }
            if ($currency === 'SAT' || $currency === 'SATS') {
                return $usd / $btcUsd * 100000000;
            }
            if ($currency === 'MSAT') {
                return $usd / $btcUsd * 100000000000;
            }
            $btcFiat = ExchangeRates::getBtcPrice($currency, $primary, $secondary);
            if ($btcFiat === null || $btcFiat <= 0) {
                return null;
            }
            return $usd * $btcFiat / $btcUsd;
        } catch (\Throwable $e) {
            error_log("EcxRates: cross rate for {$currency} unavailable: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert an invoice amount to szats (1 ECX = 100,000,000 szats).
     * Returns ['szats' => int, 'rate' => float] — rate is the ECX price in
     * $currency the conversion used — or null when no price is available.
     * Sats/BTC-denominated amounts go through the BTC:ECX ratio.
     */
    public static function convertToSzats(string $amount, string $currency, ?string $primary = null, ?string $secondary = null): ?array {
        $currency = strtoupper($currency);
        if ($currency === 'SATS') {
            $currency = 'SAT';
        }
        $rate = self::getEcxPrice($currency, $primary, $secondary);
        if ($rate === null || $rate <= 0) {
            return null;
        }
        // amount in $currency / (ECX price in $currency) = ECX; x 1e8 = szats.
        // Half-up rounding on the last szat (an invoice must not come out a
        // szat short of what the payer is shown).
        $ecx = bcdiv((string)$amount, self::fmt($rate), 16);
        $szats = bcadd(bcmul($ecx, '100000000', 8), '0.5', 8);
        $szats = (int)bcdiv($szats, '1', 0);
        if ($szats < 0) {
            return null;
        }
        return ['szats' => $szats, 'rate' => $rate];
    }

    /** szats -> fiat string with 2 decimals, or null when the price is unavailable. */
    public static function szatsToFiat(int $szats, string $currency): ?string {
        $rate = self::getEcxPrice($currency);
        if ($rate === null || $rate <= 0) {
            return null;
        }
        $ecx = bcdiv((string)$szats, '100000000', 8);
        return bcmul($ecx, self::fmt($rate), 2);
    }

    /**
     * How many sats one szat is worth right now (for folding ECX figures into
     * the sats-denominated dashboard totals). Null without both prices.
     */
    public static function szatToSatRatio(): ?float {
        $usd = self::getEcxUsd();
        if ($usd === null) {
            return null;
        }
        try {
            $btcUsd = ExchangeRates::getBtcPrice('USD');
        } catch (\Throwable $e) {
            return null;
        }
        if ($btcUsd === null || $btcUsd <= 0) {
            return null;
        }
        return $usd / $btcUsd;
    }

    /** Non-scientific decimal spelling of a float for bcmath. */
    private static function fmt(float $v): string {
        return rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.') ?: '0';
    }

    private static function getCached(bool $allowStale): ?float {
        $data = Config::get(self::CACHE_KEY);
        if (!is_array($data)) {
            return null;
        }
        $maxAge = $allowStale ? self::STALE_TTL : self::CACHE_TTL;
        $timestamp = ResponseShape::integer($data['timestamp'] ?? null);
        if ($timestamp === null || time() - $timestamp > $maxAge) {
            return null;
        }
        $rate = ResponseShape::decimal($data['rate'] ?? null);
        return ($rate !== null && self::isSane($rate)) ? $rate : null;
    }

    private static function saveToCache(float $rate): void {
        $payload = ['rate' => $rate, 'timestamp' => time(), 'provider' => 'coinmarketcap'];
        Config::set(self::CACHE_KEY, $payload);
        Config::set(self::LASTGOOD_KEY, $payload);
    }

    /** Last price ever obtained (any age) — display only, never for quoting. */
    public static function lastGoodUsd(): ?float {
        $data = Config::get(self::LASTGOOD_KEY);
        $rate = ResponseShape::decimal(is_array($data) ? ($data['rate'] ?? null) : null);
        return ($rate !== null && self::isSane($rate)) ? $rate : null;
    }
}
