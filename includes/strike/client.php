<?php
/**
 * CashuPayServer — Strike API client
 *
 * Speaks the Strike REST API (https://docs.strike.me) with a merchant-supplied
 * API key to mint Lightning invoices for the receive rails. Strike lightning
 * addresses (name@strike.me) don't support LUD-21 verify, so the LNURL
 * direct-receive rail can never confirm settlement against them — this client
 * is the supported way to take Lightning payments into a Strike account:
 *
 *   create invoice  — POST /invoices        (scope partner.invoice.create)
 *   quote invoice   — POST /invoices/{id}/quote
 *                                           (scope partner.invoice.quote.generate)
 *   read invoice    — GET  /invoices/{id}   (scope partner.invoice.read)
 *
 * Those three scopes are all the key ever needs; none of them can move funds
 * out of the account. The spend-capable endpoints (payment quotes/executions)
 * are never called.
 *
 * The API key is a bearer credential for the merchant's Strike account. It is
 * stored server-side (store_ln_addresses, and per-invoice like nwc_uri) and
 * must never be echoed to browsers or logs — use maskKey() for anything
 * user-facing.
 *
 * Invoices are always denominated in BTC for the exact sat amount, so what the
 * payer pays is exactly what the store priced; any fiat conversion is the
 * merchant's Strike account setting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../safe_http.php';

// Wall-clock budget for one Strike API operation. createInvoiceWithQuote is
// two sequential HTTPS round trips and shares this budget across both, so a
// slow API can't tarpit invoice creation past it. The checkout path tightens
// this via STRIKE_TIMEOUT_SEC in user_config.php (see
// Invoice::directReceiveTimeoutSec); settlement polls and the save-time probe
// keep the default.
if (!defined('STRIKE_DEFAULT_TIMEOUT_SEC')) {
    define('STRIKE_DEFAULT_TIMEOUT_SEC', 10);
}

/** Structured Strike API failure carrying the HTTP status where known. */
class StrikeException extends \RuntimeException
{
    /** HTTP status of the failing response; 0 = transport/validation failure. */
    public int $httpStatus;
    /** Strike error `code` string (e.g. UNAUTHORIZED, RATE_LIMITED), '' if none. */
    public string $strikeCode;

    public function __construct(string $message, int $httpStatus = 0, string $strikeCode = '')
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->strikeCode = $strikeCode;
    }
}

class StrikeClient
{
    /**
     * API base. Test environments point this at a local mock via the
     * CASHUPAY_STRIKE_API_BASE env var (mirrors CASHU_LNURL_URL_TEMPLATE for
     * the LNURL rail) so the suite never touches the real API.
     */
    public static function apiBase(): string
    {
        $base = getenv('CASHUPAY_STRIKE_API_BASE');
        if ($base !== false && $base !== '') {
            return rtrim($base, '/');
        }
        return 'https://api.strike.me/v1';
    }

    /**
     * Shape check for a Strike API key: one unbroken alphanumeric token.
     * Deliberately loose on length — Strike documents the key only as "a
     * unique alphanumeric string", so we bound it rather than pin it.
     */
    public static function isValidKey(string $key): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9]{16,256}$/', trim($key));
    }

    /**
     * User/log-safe label for an API key. The key is a bearer credential for
     * the merchant's Strike account; only this masked form may appear in
     * dashboards, notifications, or error_log lines.
     */
    public static function maskKey(string $key): string
    {
        $key = trim($key);
        $tail = strlen($key) >= 4 ? substr($key, -4) : '';
        return 'Strike API (…' . $tail . ')';
    }

    /** Exact BTC decimal string for a sat amount (1 sat = 0.00000001 BTC). */
    public static function satsToBtc(int $sats): string
    {
        if ($sats < 0) {
            throw new InvalidArgumentException('negative sat amount');
        }
        return sprintf('%d.%08d', intdiv($sats, 100000000), $sats % 100000000);
    }

    /**
     * Parse a BTC decimal string back to sats without float rounding.
     * Returns null when the string isn't a plain BTC decimal or has
     * sub-sat precision.
     */
    public static function btcToSats(string $btc): ?int
    {
        $btc = trim($btc);
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $btc, $m)) {
            return null;
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > 8) {
            // Sub-sat digits must all be zero, or the amount isn't sat-exact.
            if (rtrim(substr($frac, 8), '0') !== '') {
                return null;
            }
            $frac = substr($frac, 0, 8);
        }
        $frac = str_pad($frac, 8, '0');
        return ((int)$m[1]) * 100000000 + (int)$frac;
    }

    /**
     * Create a BTC-denominated Strike invoice for exactly $amountSats and
     * quote it into a BOLT11. The two calls share one wall-clock budget.
     *
     * $description lands in the merchant's Strike history (Strike caps it at
     * 200 chars; we truncate defensively). $correlationId ties the Strike
     * invoice back to our invoice row for reconciliation in their dashboard
     * (Strike caps it at 40 chars — longer ids are omitted, not truncated,
     * since a truncated id no longer matches anything).
     *
     * @return array{bolt11:string,invoice_id:string,expiration_in_sec:?int}
     * @throws StrikeException on any failure
     */
    public static function createInvoiceWithQuote(
        string $key,
        int $amountSats,
        ?string $description = null,
        ?string $correlationId = null,
        ?int $timeoutSec = null
    ): array {
        if ($amountSats < 1) {
            throw new StrikeException('invoice amount must be at least 1 sat');
        }
        $deadline = microtime(true) + ($timeoutSec ?? (int)STRIKE_DEFAULT_TIMEOUT_SEC);

        $body = [
            'amount' => [
                'amount' => self::satsToBtc($amountSats),
                'currency' => 'BTC',
            ],
        ];
        if ($description !== null && $description !== '') {
            $body['description'] = mb_substr($description, 0, 200);
        }
        if ($correlationId !== null && $correlationId !== '' && strlen($correlationId) <= 40) {
            $body['correlationId'] = $correlationId;
        }

        $invoice = self::request($key, 'POST', '/invoices', $body, $deadline);
        $invoiceId = (string)($invoice['invoiceId'] ?? '');
        if ($invoiceId === '') {
            throw new StrikeException('Strike returned no invoiceId');
        }

        $quote = self::request($key, 'POST', '/invoices/' . rawurlencode($invoiceId) . '/quote', new stdClass(), $deadline);
        $bolt11 = (string)($quote['lnInvoice'] ?? '');
        if ($bolt11 === '') {
            throw new StrikeException('Strike quote returned no Lightning invoice');
        }
        // The payer pays sourceAmount; for a BTC invoice it must be sat-exact
        // for the requested amount. A mismatch means the API did something
        // unexpected (e.g. currency conversion crept in) — refuse the bolt11
        // rather than present the customer an invoice for the wrong amount.
        $source = $quote['sourceAmount'] ?? null;
        if (is_array($source) && strtoupper((string)($source['currency'] ?? '')) === 'BTC') {
            $quotedSats = self::btcToSats((string)($source['amount'] ?? ''));
            if ($quotedSats !== $amountSats) {
                throw new StrikeException(sprintf(
                    'Strike quoted %s sats for a %d sat invoice',
                    $quotedSats === null ? 'a non-sat-exact amount' : (string)$quotedSats,
                    $amountSats
                ));
            }
        }

        $expSec = null;
        if (isset($quote['expirationInSec']) && is_numeric($quote['expirationInSec'])) {
            $expSec = (int)$quote['expirationInSec'];
        }

        return [
            'bolt11' => $bolt11,
            'invoice_id' => $invoiceId,
            'expiration_in_sec' => $expSec,
        ];
    }

    /**
     * Read a Strike invoice's state. Returns one of:
     *   ['state' => 'paid']      — Strike reports PAID
     *   ['state' => 'pending']   — UNPAID / PENDING
     *   ['state' => 'cancelled'] — CANCELLED at Strike
     *
     * @throws StrikeException when the API can't be reached or rejects the key
     */
    public static function findInvoice(string $key, string $invoiceId, ?int $timeoutSec = null): array
    {
        $deadline = microtime(true) + ($timeoutSec ?? (int)STRIKE_DEFAULT_TIMEOUT_SEC);
        $data = self::request($key, 'GET', '/invoices/' . rawurlencode($invoiceId), null, $deadline);
        $state = strtoupper((string)($data['state'] ?? ''));
        if ($state === 'PAID') {
            return ['state' => 'paid'];
        }
        if ($state === 'CANCELLED') {
            return ['state' => 'cancelled'];
        }
        return ['state' => 'pending'];
    }

    /**
     * Save-time probe: exercise all three scopes the receive rail needs by
     * issuing a real 1-sat invoice, quoting it, and reading it back. A key
     * missing any scope fails here instead of silently dropping Lightning
     * from the checkout later. Side effect: each probe leaves one 1-sat
     * UNPAID invoice in the merchant's Strike history (its quote simply
     * expires; nothing is ever paid).
     *
     * Returns ['ok' => bool, 'error' => ?string]. The error is operator-
     * facing and never contains the key.
     */
    public static function probeKey(string $key, ?int $timeoutSec = null): array
    {
        try {
            $made = self::createInvoiceWithQuote(
                $key,
                1,
                'BareBits connection test (1 sat) — safe to ignore, never paid',
                null,
                $timeoutSec
            );
            self::findInvoice($key, $made['invoice_id'], $timeoutSec);
        } catch (StrikeException $e) {
            return ['ok' => false, 'error' => self::describeFailure($e)];
        } catch (\Throwable $e) {
            error_log('[strike] probe threw: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'the Strike API could not be reached'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Fixed operator/payer-facing phrase for a failed Strike call. Never
     * echoes API-provided text — the result lands on the public payment page
     * (receive_errors) and in save-time error messages.
     */
    public static function describeFailure(\Throwable $e): string
    {
        if ($e instanceof StrikeException) {
            if ($e->httpStatus === 401 || $e->httpStatus === 403) {
                return 'the Strike API rejected the key (check the key and that it has the create, quote, and read invoice scopes)';
            }
            if ($e->httpStatus === 429) {
                return 'the Strike API is rate-limiting requests';
            }
            if ($e->httpStatus >= 500) {
                return 'the Strike API reported a server error';
            }
            if ($e->httpStatus >= 400) {
                return 'the Strike API refused to issue an invoice';
            }
        }
        return 'the Strike API could not be reached';
    }

    /**
     * One authenticated JSON round trip against the API within the shared
     * deadline. $body: array/object = JSON POST body, null = GET.
     *
     * @return array decoded JSON object
     * @throws StrikeException
     */
    private static function request(string $key, string $method, string $path, $body, float $deadline): array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.5) {
            throw new StrikeException('Strike API budget exhausted before request');
        }
        $timeout = (int)max(1, ceil($remaining));

        $headers = [
            'Authorization: Bearer ' . trim($key),
            'Accept: application/json',
        ];
        $opts = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'connectTimeout' => $timeout,
            // The real API is public HTTPS; the mock in tests is a local
            // http host, allowed via the same operator opt-in the LNURL and
            // on-chain clients honour.
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
            'followRedirects' => false,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts['headers'] = $headers;
            $opts['body'] = json_encode($body);
        }

        $result = \SafeHttp::request(self::apiBase() . $path, $opts);
        if ($result['error'] !== '' || $result['status'] === 0) {
            throw new StrikeException(
                'Strike API request failed: ' . ($result['error'] !== '' ? $result['error'] : 'no response'),
                0
            );
        }
        $status = (int)$result['status'];
        $decoded = json_decode((string)$result['body'], true);
        if ($status < 200 || $status >= 300) {
            // Strike errors carry {data:{code,message}}; keep only the code —
            // free-text messages never leave this method.
            $code = '';
            if (is_array($decoded)) {
                $code = (string)($decoded['data']['code'] ?? $decoded['code'] ?? '');
            }
            throw new StrikeException(
                'Strike API HTTP ' . $status . ($code !== '' ? " ({$code})" : ''),
                $status,
                $code
            );
        }
        if (!is_array($decoded)) {
            throw new StrikeException('Strike API returned invalid JSON', $status);
        }
        return $decoded;
    }
}
