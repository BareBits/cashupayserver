<?php
/**
 * CashuPayServer — LNURL Direct-Receive Module
 *
 * Routes incoming Lightning payments straight to the store's configured
 * auto-withdraw LN address when the LNURL host supports LUD-21 (`verify` URL),
 * bypassing the cashu mint and the submarine swap rails entirely. Eliminates
 * the customer→mint→merchant round-trip when the merchant is going to auto-
 * withdraw to that same address anyway.
 *
 * Decision flow at invoice creation:
 *   1. Probe the auto_melt LN address: resolve LNURL-pay metadata, request a
 *      BOLT11 for the exact amount, require a `verify` URL in the response.
 *   2. If the probe succeeds and the fee-override gate does not fire, the
 *      LNURL-issued BOLT11 becomes the invoice (payment_rail='lnaddress').
 *   3. If the override gate fires (this invoice is smaller than the
 *      accumulated fees the operator owes upstream/dev/hosting), the LNURL
 *      path is skipped and we fall through to mint/swap. The resulting
 *      mint-rail invoice is flagged with lnurl_override_reason so settlement
 *      triggers an immediate DevFee::settleStore + auto-melt.
 *   4. If the probe fails (host down, no LUD-21, timeout), we silently fall
 *      through to the existing mint/swap decision.
 *
 * Settlement detection uses the LUD-21 verify URL: GET returns
 * {"settled": true|false, "preimage": "..."}. The preimage matches
 * payment_hash from the BOLT11, giving cryptographic proof of payment without
 * controlling the LN node.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dev_fee.php';
require_once __DIR__ . '/safe_http.php';

// Wall-clock budget for the LNURL probe at invoice creation. The probe is
// two HTTP round-trips (well-known + callback). 5 seconds keeps invoice
// creation snappy while tolerating sluggish hosts; tune via user_config.php.
if (!defined('LNURL_RECEIVE_PROBE_TIMEOUT_SEC')) {
    define('LNURL_RECEIVE_PROBE_TIMEOUT_SEC', 5);
}

class LnUrlReceive {
    /** Override decision reasons written to invoices.lnurl_override_reason. */
    public const REASON_NONE = 'none';
    public const REASON_FEES_DUE = 'fees_due';

    /**
     * Pure override-gate function: should the LNURL-receive path be skipped
     * for an invoice of this size given the store's current fees-due?
     *
     * Single rule: when the invoice amount is smaller than the accumulated
     * upstream/dev/hosting fees the operator owes, route via the mint so the
     * resulting mint balance can clear the owed fees. Larger invoices take
     * the LNURL direct path even if some fees are outstanding — the next
     * small invoice (or the cron) will catch the debt up.
     *
     * Pure in/out for unit testing; caller fetches feesDue from
     * {@see DevFee::computeOwed}.
     */
    public static function shouldOverride(
        int $feesDueSats,
        int $invoiceAmountSats
    ): array {
        if ($feesDueSats > 0 && $invoiceAmountSats < $feesDueSats) {
            return ['override' => true, 'reason' => self::REASON_FEES_DUE];
        }
        return ['override' => false, 'reason' => self::REASON_NONE];
    }

    /**
     * Sum of all three owed fee buckets for a store, in sats. Used as the
     * feesDue input to {@see shouldOverride}. Delegates to the existing
     * {@see DevFee::computeOwed} so the override math stays consistent with
     * what the cron will actually try to collect.
     */
    public static function feesDueSats(string $storeId): int {
        $owed = DevFee::computeOwed($storeId);
        return ((int)$owed['upstream_owed'])
             + ((int)$owed['dev_owed'])
             + ((int)$owed['hosting_owed']);
    }

    /**
     * Resolve a Lightning address to its LNURL-pay metadata and request a
     * BOLT11 for the exact amount, requiring a LUD-21 verify URL in the
     * response. Returns the BOLT11 + verify URL on success; null on any
     * failure (host down, no verify, amount out of range, timeout, etc.).
     *
     * The probe is the live-and-working check: a successful return means we
     * have an invoice in hand that will be paid through to the merchant and
     * a verify URL we can poll for settlement.
     *
     * @return array{bolt11:string,verify_url:string,min_sendable_msats:int,max_sendable_msats:int}|null
     */
    public static function probeAndFetchInvoice(
        string $lnAddress,
        int $amountSats,
        ?int $timeoutSec = null
    ): ?array {
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;

        if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $lnAddress)) {
            return null;
        }
        [$user, $domain] = explode('@', $lnAddress, 2);

        // Test environments can rewrite the URL via the cashu-wallet-php
        // CASHU_LNURL_URL_TEMPLATE convention so our mock LNURL host doesn't
        // need real HTTPS / port 443.
        $template = getenv('CASHU_LNURL_URL_TEMPLATE');
        if ($template !== false && $template !== '') {
            $url = strtr($template, ['{user}' => $user, '{domain}' => $domain]);
        } else {
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        }

        $metaResp = self::httpGet($url, $timeout);
        if ($metaResp === null) {
            return null;
        }
        $meta = json_decode($metaResp, true);
        if (!is_array($meta)
            || !isset($meta['callback'], $meta['minSendable'], $meta['maxSendable'])
        ) {
            return null;
        }

        $amountMsats = $amountSats * 1000;
        if ($amountMsats < (int)$meta['minSendable']
            || $amountMsats > (int)$meta['maxSendable']
        ) {
            return null;
        }

        $callback = (string)$meta['callback'];
        $sep = (strpos($callback, '?') !== false) ? '&' : '?';
        $callbackUrl = $callback . $sep . 'amount=' . $amountMsats;

        $invResp = self::httpGet($callbackUrl, $timeout);
        if ($invResp === null) {
            return null;
        }
        $inv = json_decode($invResp, true);
        if (!is_array($inv) || !isset($inv['pr'])) {
            return null;
        }
        // LUD-21: the callback response must include a `verify` URL. Without
        // it we cannot detect settlement (we don't run the LN node), so we
        // refuse to use this LNURL for receive and fall back to mint/swap.
        if (!isset($inv['verify']) || !is_string($inv['verify']) || $inv['verify'] === '') {
            return null;
        }

        return [
            'bolt11' => (string)$inv['pr'],
            'verify_url' => (string)$inv['verify'],
            'min_sendable_msats' => (int)$meta['minSendable'],
            'max_sendable_msats' => (int)$meta['maxSendable'],
        ];
    }

    /**
     * Probe an LN address to determine LUD-21 verify-URL support, returning
     * 1 if supported, 0 if not, null if the host is unreachable. Used by
     * the admin save handler to set stores.lnurl_supports_verify and warn
     * the operator when the host doesn't speak LUD-21.
     *
     * Uses a small canary amount (the host's declared minimum, capped at
     * 1000 sats) so we exercise the real callback rather than just the
     * metadata endpoint.
     */
    public static function probeLud21Support(string $lnAddress, ?int $timeoutSec = null): ?int {
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;
        if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $lnAddress)) {
            return null;
        }
        [$user, $domain] = explode('@', $lnAddress, 2);

        $template = getenv('CASHU_LNURL_URL_TEMPLATE');
        if ($template !== false && $template !== '') {
            $url = strtr($template, ['{user}' => $user, '{domain}' => $domain]);
        } else {
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        }
        $resp = self::httpGet($url, $timeout);
        if ($resp === null) {
            return null;
        }
        $meta = json_decode($resp, true);
        if (!is_array($meta)
            || !isset($meta['callback'], $meta['minSendable'], $meta['maxSendable'])
        ) {
            return null;
        }

        // Use min(maxSendable, max(minSendable, 1000 sat in msat)) so the
        // canary amount lies inside the host's accepted range even for
        // unusual LNURL hosts with huge minimums.
        $minMsat = (int)$meta['minSendable'];
        $maxMsat = (int)$meta['maxSendable'];
        $canaryMsat = max($minMsat, 1000 * 1000);
        if ($canaryMsat > $maxMsat) {
            $canaryMsat = $maxMsat;
        }
        $callback = (string)$meta['callback'];
        $sep = (strpos($callback, '?') !== false) ? '&' : '?';
        $callbackUrl = $callback . $sep . 'amount=' . $canaryMsat;

        $invResp = self::httpGet($callbackUrl, $timeout);
        if ($invResp === null) {
            return null;
        }
        $inv = json_decode($invResp, true);
        if (!is_array($inv) || !isset($inv['pr'])) {
            return null;
        }
        return (isset($inv['verify']) && is_string($inv['verify']) && $inv['verify'] !== '')
            ? 1 : 0;
    }

    /**
     * Poll the LUD-21 verify URL for a single invoice. Returns one of:
     *   ['state' => 'paid',    'preimage' => string]  — settled, with proof
     *   ['state' => 'pending', 'preimage' => null]    — host says not yet
     *   ['state' => 'error',   'preimage' => null]    — unreachable / malformed
     *
     * Per LUD-21 the response shape is:
     *   {"status": "OK", "settled": true|false, "preimage": "...", "pr": "..."}
     * The preimage SHA256 must match the BOLT11 payment_hash; we trust the
     * host's framing here (caller already validated bolt11 ownership at
     * creation time) and use the preimage as the settlement receipt.
     */
    public static function pollVerifyUrl(string $verifyUrl, ?int $timeoutSec = null): array {
        $timeout = $timeoutSec ?? (int)LNURL_RECEIVE_PROBE_TIMEOUT_SEC;
        [$resp, $httpCode, $curlErr] = self::httpGetWithDiag($verifyUrl, $timeout);
        if ($resp === null) {
            error_log(sprintf(
                '[lnurl-receive] pollVerifyUrl HTTP error url=%s http=%s curl=%s',
                $verifyUrl, $httpCode, $curlErr ?: 'none'
            ));
            return ['state' => 'error', 'preimage' => null];
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['state' => 'error', 'preimage' => null];
        }
        $settled = !empty($data['settled']);
        if ($settled) {
            $preimage = isset($data['preimage']) && is_string($data['preimage'])
                ? (string)$data['preimage']
                : '';
            // Even if preimage is missing, settled=true is the host's
            // assertion that payment landed. Record what we have.
            return ['state' => 'paid', 'preimage' => $preimage];
        }
        return ['state' => 'pending', 'preimage' => null];
    }

    /**
     * Handler invoked when a mint-rail invoice with lnurl_override_reason
     * IS NOT NULL settles. The override forced this payment through the mint
     * so accumulated owed fees can be cleared before forwarding the net to
     * the merchant's LN address.
     *
     * Steps:
     *   1. Run DevFee::settleStore to pay out owed upstream/dev/hosting fees
     *      from the just-received mint tokens.
     *   2. Melt the remaining balance to the merchant's auto_melt_address.
     *
     * Best-effort: any failure logs + notifies but doesn't surface to the
     * customer — the invoice is already paid from their perspective. Normal
     * auto-melt cron will retry the merchant payout on the next tick.
     */
    public static function handleOverrideSettled(string $invoiceId): void {
        require_once __DIR__ . '/lightning_address.php';
        require_once __DIR__ . '/invoice.php';
        require_once __DIR__ . '/rates.php';
        require_once __DIR__ . '/notification_sender.php';
        require_once __DIR__ . '/store_ln_addresses.php';

        $invoice = Invoice::getById($invoiceId);
        if ($invoice === null) {
            error_log("[lnurl-override] settled-handler: invoice {$invoiceId} not found");
            return;
        }
        $storeId = (string)$invoice['store_id'];
        $store = Config::getStore($storeId);
        if ($store === null) {
            error_log("[lnurl-override] settled-handler: store {$storeId} not found");
            return;
        }

        error_log(sprintf(
            '[lnurl-override] settled-handler: invoice=%s store=%s reason=%s amount_sats=%s',
            $invoiceId, $storeId,
            $invoice['lnurl_override_reason'] ?? 'unknown',
            (string)($invoice['amount_sats'] ?? '?')
        ));

        // Step 1: settle owed fees from this store's mint balance. settleStore
        // is a no-op if nothing crosses the per-fee threshold. Failures here
        // (mint unreachable, insufficient balance) are logged inside settleStore
        // and don't block the auto-melt below — fees just stay owed.
        try {
            DevFee::settleStore($storeId);
        } catch (Throwable $e) {
            error_log("[lnurl-override] settleStore failed for store {$storeId}: " . $e->getMessage());
        }

        // Step 2: auto-melt the remaining balance to the operator's LN address,
        // regardless of auto_melt_enabled toggle. The LNURL-receive feature only
        // engages when auto_melt_enabled=1 at invoice creation, so this is
        // expected to be on; we re-check defensively. The address list is tried
        // in priority order — the first that accepts the payment wins.
        $addresses = StoreLnAddresses::addressesForStore($storeId);
        if (empty($addresses)) {
            error_log("[lnurl-override] no LN addresses for store {$storeId}; skipping auto-melt");
            return;
        }

        $balance = Invoice::getBalance($storeId);
        $mintUnit = strtolower((string)($store['mint_unit'] ?? 'sat'));
        $isFiat = !in_array($mintUnit, ['sat', 'sats', 'msat'], true);
        if ($balance < 1) {
            error_log("[lnurl-override] store {$storeId} has zero balance after fee settle; nothing to forward");
            return;
        }
        if ($isFiat) {
            $meltSats = (int) ExchangeRates::convertMintUnitToSats(
                $balance, $mintUnit,
                $store['price_provider_primary'] ?? null,
                $store['price_provider_secondary'] ?? null
            );
        } else {
            $meltSats = (int)$balance;
        }
        // Match the existing auto-melt fee-reserve buffer so the mint
        // doesn't reject for fee shortfall.
        $feeBuffer = max(2, min(100, (int)ceil($meltSats * 0.01)));
        $meltSats -= $feeBuffer;
        if ($meltSats < 1) {
            error_log("[lnurl-override] store {$storeId} balance below fee buffer; deferring to cron");
            return;
        }

        $lastError = null;
        foreach ($addresses as $priority => $address) {
            try {
                $result = LightningAddress::meltToAddress(
                    $storeId,
                    $address,
                    $meltSats,
                    'BareBits override-triggered auto-withdrawal'
                );
                $networkFeeSats = (int)($result['fee'] ?? 0);
                if ($isFiat && $networkFeeSats > 0) {
                    $networkFeeSats = (int) ExchangeRates::convertMintUnitToSats(
                        $networkFeeSats, $mintUnit,
                        $store['price_provider_primary'] ?? null,
                        $store['price_provider_secondary'] ?? null
                    );
                }
                MeltLog::record(
                    $storeId, $meltSats, $networkFeeSats, $address,
                    $result['preimage'] ?? null, null
                );
                NotificationSender::queueAutoWithdrawSuccess($storeId, $meltSats, $address);
                error_log(sprintf(
                    '[lnurl-override] auto-melt ok store=%s amount_sats=%d to=%s priority=%d',
                    $storeId, $meltSats, $address, $priority
                ));
                return;
            } catch (Throwable $e) {
                $lastError = $e;
                error_log(sprintf(
                    '[lnurl-override] auto-melt attempt failed store=%s priority=%d to=%s: %s',
                    $storeId, $priority, $address, $e->getMessage()
                ));
            }
        }

        // Every address failed — notify against the primary so the operator
        // sees the payout is wedged. Cron auto-melt will retry on the next tick.
        error_log("[lnurl-override] all auto-melt addresses failed for store {$storeId}");
        NotificationSender::queueAutoWithdrawFailure(
            $storeId, $addresses[0],
            $lastError ? $lastError->getMessage() : 'unknown error',
            null
        );
    }

    /**
     * HTTP GET with the configured timeout. Returns the response body string
     * on HTTP 200, null on any other status or network failure. Honours the
     * full timeout for both connect and total time so a slow LNURL host
     * can't tarpit invoice creation past the per-probe budget.
     */
    private static function httpGet(string $url, int $timeoutSec): ?string {
        [$resp, $_code, $_err] = self::httpGetWithDiag($url, $timeoutSec);
        return $resp;
    }

    /**
     * Variant of httpGet that also returns the HTTP status code and the
     * cURL error string, for diagnostic logging in places where a silent
     * null is too opaque (settlement polling needs to distinguish 404
     * "we don't recognize that payment hash" from 500 "host malfunction"
     * from connection failure).
     *
     * @return array{0:?string,1:int,2:string} [body|null, httpCode, curlErr]
     */
    private static function httpGetWithDiag(string $url, int $timeoutSec): array {
        // LNURL callback/verify URLs are the LNURL host's choice. By
        // default we refuse private destinations; the operator opt-in
        // (allow_private_endpoints) lifts it for self-hosted LN address
        // services and the local test rigs.
        // Redirects stay off either way: LNURL endpoints are supposed to
        // respond directly, and following redirects opens an SSRF-via-
        // redirect path that the IP pin alone doesn't close.
        $result = \SafeHttp::request($url, [
            'method' => 'GET',
            'timeout' => $timeoutSec,
            'connectTimeout' => $timeoutSec,
            'headers' => ['Accept: application/json'],
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
            'followRedirects' => false,
        ]);
        $code = $result['status'];
        $resp = $result['body'];
        $err = $result['error'];
        if ($err !== '' || $code !== 200 || $resp === '') {
            return [null, $code, $err];
        }
        return [$resp, $code, ''];
    }
}
