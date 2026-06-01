<?php
/**
 * CashuPayServer — Development Fee + Hosting Fee + Upstream Dev Fee
 *
 * Implements the three-tier fee structure introduced under the Modified MIT
 * License (see LICENSE.md). All math is in sats. Settlement is driven from
 * the cron tick (see cron.php → "Task 2.5"); each fee fires independently
 * once the owed amount crosses the per-fee threshold.
 *
 * Fee semantics:
 *   - Upstream dev fee (CASHUPAY_UPSTREAM_DEV_FEE_PERCENT, default 0.5%):
 *       base = revenue − network_costs. Paid to the original CashuPayServer
 *       author via the existing cypherpunk.today Cashu-token sink.
 *   - Dev fee (CASHUPAY_DEV_FEE_PERCENT, hard-coded 2%):
 *       base = revenue − network_costs − upstream_paid. Paid to the LNURL
 *       configured by CASHUPAY_DEV_FEE_LNURL (default fees@getbarebits.com)
 *       with "Deployment: $DEPLOYMENT_ID" attached as a memo (LUD-12 comment
 *       if commentAllowed permits; otherwise LUD-18 payerdata.name; otherwise
 *       paid without a memo and a warning is logged).
 *   - Hosting fee (per-store hosting_fee_percent, default 0%):
 *       base = revenue (no network-cost decrement). Paid to the store's
 *       configured hosting_fee_destination LNURL.
 *
 * Idempotency: paid totals are derived from the melts table by tag/note;
 * each settle pass insert the melts row in the same transaction that the
 * proofs are spent, so a successful Lightning payment can never be double-
 * counted on a subsequent settle pass.
 *
 * Modifying CASHUPAY_DEV_FEE_PERCENT or pointing CASHUPAY_DEV_FEE_LNURL at
 * an address you control to capture this fee is a violation of the
 * Modified MIT License (see LICENSE.md, section 2).
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/lightning_address.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;

// MANDATORY: this constant is intentionally hard-coded and not env-overridable.
if (!defined('CASHUPAY_DEV_FEE_PERCENT')) {
    define('CASHUPAY_DEV_FEE_PERCENT', 2);
}

// Default dev fee destination. CASHUPAY_DEV_FEE_LNURL env override exists
// purely to support test deployments; production must use the default.
if (!defined('CASHUPAY_DEV_FEE_LNURL')) {
    $envLnurl = getenv('CASHUPAY_DEV_FEE_LNURL');
    define('CASHUPAY_DEV_FEE_LNURL', ($envLnurl !== false && $envLnurl !== '') ? $envLnurl : 'fees@getbarebits.com');
}

// Per-fee threshold in sats. Fees do not settle until at least this many
// sats are owed (avoids churning melts for tiny amounts).
if (!defined('CASHUPAY_FEE_SETTLE_THRESHOLD_SATS')) {
    define('CASHUPAY_FEE_SETTLE_THRESHOLD_SATS', 1000);
}

// Melt note tags written to the `melts.note` column.
const FEE_NOTE_UPSTREAM = 'UPSTREAM_DEV_FEE';
const FEE_NOTE_DEV      = 'DEV_FEE';
const FEE_NOTE_HOSTING  = 'HOSTING_FEE';

/**
 * MeltLog — insert-only audit log of every successful outbound melt.
 *
 * Used by every melt call-site (user manual withdraw, auto-melt, the three
 * fee payments). The `note` column distinguishes fee payments from user
 * withdrawals and powers fee-paid aggregation. Failed melts are not logged
 * here in this PR; that will be revisited when the stats dashboard lands.
 */
class MeltLog {
    /**
     * @param string      $storeId
     * @param int         $amountSats       Sats delivered to the destination
     * @param int         $networkFeeSats   Sats consumed by network/mint fees
     * @param string      $destination      BOLT-11 / LNURL / sink URL
     * @param string|null $preimage         Lightning preimage when available
     * @param string|null $note             One of FEE_NOTE_*, or NULL for user/auto-melt
     */
    public static function record(
        string $storeId,
        int $amountSats,
        int $networkFeeSats,
        string $destination,
        ?string $preimage,
        ?string $note
    ): int {
        return (int) Database::insert('melts', [
            'store_id' => $storeId,
            'amount_sats' => max(0, $amountSats),
            'network_fee_sats' => max(0, $networkFeeSats),
            'destination' => $destination,
            'preimage' => $preimage,
            'note' => $note,
            'created_at' => time(),
        ]);
    }
}

/**
 * DevFee — owed-amount math + per-store settlement loop.
 */
class DevFee {
    /**
     * Settle dev / hosting / upstream fees for every store with accrued
     * revenue. Called from cron.php as a separate task before auto-melt.
     */
    public static function settleAllStores(): array {
        $results = [];
        $stores = Database::fetchAll(
            "SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL"
        );
        foreach ($stores as $store) {
            $result = self::settleStore($store['id']);
            if ($result !== null) {
                $results[] = ['store_id' => $store['id']] + $result;
            }
        }
        return $results;
    }

    /**
     * Settle dev / hosting / upstream fees for a single store. Returns null
     * if nothing was owed; otherwise an array describing what was attempted.
     */
    public static function settleStore(string $storeId): ?array {
        $owed = self::computeOwed($storeId);
        if ($owed['revenue'] <= 0) {
            return null;
        }

        $threshold = CASHUPAY_FEE_SETTLE_THRESHOLD_SATS;
        $outcomes = [];

        // 1) Upstream dev fee first — counts as a network cost reducing the
        //    dev-fee base, so paying it before the dev fee keeps the math
        //    self-consistent within a single tick.
        if ($owed['upstream_owed'] >= $threshold) {
            $outcomes['upstream'] = self::payUpstream($storeId, $owed['upstream_owed']);
            // After paying upstream, recompute so the dev fee math uses the
            // updated upstream_paid.
            if (($outcomes['upstream']['success'] ?? false) === true) {
                $owed = self::computeOwed($storeId);
            }
        }

        // 2) Dev fee.
        if ($owed['dev_owed'] >= $threshold) {
            $outcomes['dev'] = self::payViaLnurl(
                $storeId,
                CASHUPAY_DEV_FEE_LNURL,
                $owed['dev_owed'],
                FEE_NOTE_DEV
            );
        }

        // 3) Hosting fee.
        if ($owed['hosting_owed'] >= $threshold) {
            $store = Config::getStore($storeId);
            $hostingDest = $store['hosting_fee_destination'] ?? null;
            if ($hostingDest === null || $hostingDest === '') {
                $outcomes['hosting'] = ['success' => false, 'error' => 'hosting_fee_destination not configured'];
            } else {
                $outcomes['hosting'] = self::payViaLnurl(
                    $storeId,
                    $hostingDest,
                    $owed['hosting_owed'],
                    FEE_NOTE_HOSTING
                );
            }
        }

        return ['owed' => $owed, 'outcomes' => $outcomes];
    }

    /**
     * Pure aggregation: compute revenue / network cost / paid totals and
     * how much is owed for each fee type. All values in sats.
     */
    public static function computeOwed(string $storeId): array {
        $start = (int) Config::get('fee_tracking_start_at', 0);

        // Revenue: sum of paid invoices' sat value created after the
        // migration timestamp. amount_sats may be null on legacy rows; those
        // are excluded by the filter.
        $revenue = (int) Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s
             FROM invoices
             WHERE store_id = ? AND status = 'Settled'
               AND amount_sats IS NOT NULL
               AND created_at >= ?",
            [$storeId, $start]
        )['s'];

        $networkCost = (int) Database::fetchOne(
            "SELECT COALESCE(SUM(network_fee_sats), 0) AS s FROM melts WHERE store_id = ?",
            [$storeId]
        )['s'];

        $upstreamPaid = self::sumPaid($storeId, FEE_NOTE_UPSTREAM);
        $devPaid      = self::sumPaid($storeId, FEE_NOTE_DEV);
        $hostingPaid  = self::sumPaid($storeId, FEE_NOTE_HOSTING);

        $store = Config::getStore($storeId);
        $hostingPct = (float)($store['hosting_fee_percent'] ?? 0);

        $upstreamBase = max(0, $revenue - $networkCost);
        $upstreamOwed = max(0, (int) floor($upstreamBase * CASHUPAY_UPSTREAM_DEV_FEE_PERCENT / 100) - $upstreamPaid);

        $devBase = max(0, $revenue - $networkCost - $upstreamPaid);
        $devOwed = max(0, (int) floor($devBase * CASHUPAY_DEV_FEE_PERCENT / 100) - $devPaid);

        $hostingOwed = max(0, (int) floor($revenue * $hostingPct / 100) - $hostingPaid);

        return [
            'revenue' => $revenue,
            'network_cost' => $networkCost,
            'upstream_paid' => $upstreamPaid,
            'dev_paid' => $devPaid,
            'hosting_paid' => $hostingPaid,
            'upstream_owed' => $upstreamOwed,
            'dev_owed' => $devOwed,
            'hosting_owed' => $hostingOwed,
        ];
    }

    private static function sumPaid(string $storeId, string $note): int {
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(amount_sats), 0) AS s FROM melts WHERE store_id = ? AND note = ?",
            [$storeId, $note]
        );
        return (int)($row['s'] ?? 0);
    }

    /**
     * Pay the upstream dev fee in mint units via the existing Cashu-token
     * sink (cypherpunk.today). The owed amount is in sats; we convert to
     * the store's mint unit before splitting proofs.
     */
    private static function payUpstream(string $storeId, int $owedSats): array {
        $store = Config::getStore($storeId);
        $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
        $isFiatMint = !in_array($mintUnit, ['sat', 'sats', 'msat']);

        if ($isFiatMint) {
            $providers = Config::getStorePriceProviders($storeId);
            $amountInMintUnit = (int) ExchangeRates::convertSatsToMintUnit(
                $owedSats, $mintUnit, $providers['primary'], $providers['secondary']
            );
        } else {
            $amountInMintUnit = $owedSats;
        }

        if ($amountInMintUnit < 1) {
            return ['success' => false, 'error' => 'amount too small after conversion'];
        }

        $send = UpstreamDevFee::sendToSink($storeId, $amountInMintUnit);
        if (($send['success'] ?? false) !== true) {
            return ['success' => false, 'error' => $send['error'] ?? 'unknown'];
        }

        MeltLog::record(
            $storeId,
            $owedSats,
            0,
            CASHUPAY_UPSTREAM_DEV_FEE_SINK_URL,
            null,
            FEE_NOTE_UPSTREAM
        );

        return ['success' => true, 'amount_sats' => $owedSats];
    }

    /**
     * Pay a fee via Lightning Address / LNURL-pay with the deployment-id
     * memo attached. Records a melts row on success.
     */
    private static function payViaLnurl(
        string $storeId,
        string $destination,
        int $owedSats,
        string $noteTag
    ): array {
        $deploymentId = (string) Config::get('deployment_id', 'ANONYMOUS');
        $memo = "Deployment: {$deploymentId}";

        // Resolve LNURL parameters once so we can decide which memo channel
        // (LUD-12 comment vs LUD-18 payerdata) the server supports.
        $params = LnurlPay::resolve($destination);
        if ($params === null) {
            return ['success' => false, 'error' => "could not resolve LNURL {$destination}"];
        }

        $amountMsats = $owedSats * 1000;
        if ($amountMsats < ($params['minSendable'] ?? 0)) {
            return ['success' => false, 'error' => 'amount below minSendable'];
        }
        if ($amountMsats > ($params['maxSendable'] ?? PHP_INT_MAX)) {
            return ['success' => false, 'error' => 'amount above maxSendable'];
        }

        // Try LUD-12 (comment), then LUD-18 (payerData.name) as fallback.
        // Either way, the BOLT-11 invoice is what we melt.
        try {
            $bolt11 = LnurlPay::fetchInvoice($params, $owedSats, $memo);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'fetchInvoice: ' . $e->getMessage()];
        }

        try {
            $result = LightningAddress::meltToBolt11($storeId, $bolt11, $owedSats);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'meltToBolt11: ' . $e->getMessage()];
        }

        $networkFeeSats = self::networkFeeSats($storeId, $result['fee'] ?? 0);

        MeltLog::record(
            $storeId,
            $owedSats,
            $networkFeeSats,
            $destination,
            $result['preimage'] ?? null,
            $noteTag
        );

        return ['success' => true, 'amount_sats' => $owedSats, 'network_fee_sats' => $networkFeeSats];
    }

    /**
     * Convert a wallet-reported fee to sats. For sat mints it's a no-op; for
     * fiat mints we convert via ExchangeRates so the dev-fee base shrinks
     * honestly regardless of mint unit.
     */
    private static function networkFeeSats(string $storeId, int $feeInMintUnit): int {
        if ($feeInMintUnit <= 0) {
            return 0;
        }
        $store = Config::getStore($storeId);
        $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
        if (in_array($mintUnit, ['sat', 'sats', 'msat'])) {
            return $mintUnit === 'msat' ? (int)ceil($feeInMintUnit / 1000) : $feeInMintUnit;
        }
        $providers = Config::getStorePriceProviders($storeId);
        return (int) ExchangeRates::convertMintUnitToSats(
            $feeInMintUnit, $mintUnit, $providers['primary'], $providers['secondary']
        );
    }
}

/**
 * LnurlPay — minimal LNURL-pay client with LUD-12 / LUD-18 memo support.
 *
 * The cashu-wallet-php library's LightningAddress::getInvoice only does
 * LUD-12 comment. We need the LUD-18 payerData.name fallback for the
 * deployment-id memo so fees can be attributed regardless of which LUDs
 * the endpoint advertises.
 */
class LnurlPay {
    /**
     * Resolve a Lightning Address (or raw LNURL) into payRequest params.
     * Returns the parsed JSON or null on failure. Includes payerData so we
     * can decide on LUD-18 support.
     */
    public static function resolve(string $address): ?array {
        // Lightning Address path: user@domain → well-known/lnurlp
        if (preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $address)) {
            [$user, $domain] = explode('@', $address, 2);
            $url = "https://{$domain}/.well-known/lnurlp/{$user}";
        } else {
            // Bare https URL fallback (LNURL-pay endpoint directly)
            if (!preg_match('#^https?://#', $address)) {
                return null;
            }
            $url = $address;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['callback'], $data['minSendable'], $data['maxSendable'])) {
            return null;
        }
        return [
            'callback' => $data['callback'],
            'minSendable' => (int)$data['minSendable'],
            'maxSendable' => (int)$data['maxSendable'],
            'commentAllowed' => (int)($data['commentAllowed'] ?? 0),
            'payerData' => $data['payerData'] ?? null,
        ];
    }

    /**
     * Request a BOLT-11 invoice from a resolved LNURL-pay endpoint,
     * attaching the memo via LUD-12 (comment) if commentAllowed permits;
     * otherwise via LUD-18 (payerdata.name) if the server advertises a name
     * field; otherwise logging a warning and paying without a memo.
     */
    public static function fetchInvoice(array $params, int $amountSats, string $memo): string {
        $amountMsats = $amountSats * 1000;
        $callback = $params['callback'];
        $separator = (strpos($callback, '?') !== false) ? '&' : '?';
        $url = $callback . $separator . 'amount=' . $amountMsats;

        $memoApplied = false;
        $commentAllowed = (int)($params['commentAllowed'] ?? 0);
        if ($commentAllowed >= strlen($memo)) {
            $url .= '&comment=' . urlencode($memo);
            $memoApplied = true;
        } elseif (self::payerDataSupportsName($params['payerData'] ?? null)) {
            $payerdata = json_encode(['name' => $memo]);
            $url .= '&payerdata=' . urlencode($payerdata);
            $memoApplied = true;
        }

        if (!$memoApplied) {
            error_log("LnurlPay: endpoint does not support comment ≥ "
                . strlen($memo) . " chars and has no payerData.name; paying without memo");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception("LNURL callback failed (HTTP {$httpCode})");
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['pr'])) {
            $err = $data['reason'] ?? $data['message'] ?? 'no pr field';
            throw new Exception("LNURL callback error: {$err}");
        }
        return (string) $data['pr'];
    }

    /**
     * LUD-18 advertises supported payer-data fields under payerData; we
     * specifically look for a writable `name` slot.
     */
    private static function payerDataSupportsName($payerData): bool {
        if (!is_array($payerData)) {
            return false;
        }
        return isset($payerData['name']) && is_array($payerData['name']);
    }
}
