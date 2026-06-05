<?php
/**
 * CashuPayServer - Lightning Address Module
 *
 * LNURL-pay resolution and auto-melt functionality.
 * Uses cashu-wallet-php library for Lightning address resolution.
 * Supports per-store wallet configuration.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/mint_reliability.php';
require_once __DIR__ . '/notification_sender.php';
require_once __DIR__ . '/swap/auto_melt.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\LightningAddress as CashuLightningAddress;

class LightningAddress {
    /**
     * Validate a Lightning address format
     */
    public static function isValid(string $address): bool {
        return CashuLightningAddress::isValid($address);
    }

    /**
     * Resolve Lightning address to LNURL-pay metadata
     */
    public static function resolve(string $address): ?array {
        return CashuLightningAddress::resolve($address);
    }

    /**
     * Get a BOLT11 invoice from a Lightning address
     */
    public static function getInvoice(string $address, int $amountSats, ?string $comment = null): string {
        return CashuLightningAddress::getInvoice($address, $amountSats, $comment);
    }

    /**
     * Melt tokens to a Lightning address
     *
     * IMPORTANT: Amount must ALWAYS be in SATOSHIS, not mint unit.
     * Lightning Network always operates in satoshis. For fiat mints,
     * the melt quote will return the cost in mint's unit (EUR/USD),
     * which is then paid with fiat proofs.
     *
     * @param string $storeId Store ID for wallet access
     * @param string $address Lightning address
     * @param int $amountSats Amount in SATOSHIS (Lightning is always sats)
     * @param string|null $comment Optional comment
     */
    public static function meltToAddress(string $storeId, string $address, int $amountSats, ?string $comment = null): array {
        // The reliability tracker needs to know which mint we're talking to;
        // grab it up front so failures in any stage can be attributed correctly.
        $mintUrl = Config::getStoreMintUrl($storeId);
        $wallet = null;
        $meltQuoteId = null;

        try {
            // LNURL resolution / Lightning Address invoice request: failures
            // here are wallet-side (LNURL host down, bad address, etc.).
            try {
                $bolt11 = CashuLightningAddress::getInvoice($address, $amountSats, $comment);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'getInvoice', null, null);
                throw $e;
            }

            try {
                $wallet = Invoice::getWalletInstance($storeId);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'requestMeltQuote', null, null);
                throw $e;
            }

            try {
                $meltQuote = $wallet->requestMeltQuote($bolt11);
                $meltQuoteId = $meltQuote->quote ?? null;
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'requestMeltQuote', null, $wallet);
                throw $e;
            }

            $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;
            $proofs = Invoice::getUnspentProofs($storeId);
            $balance = Wallet::sumProofs($proofs);
            $mintUnit = Config::getStoreMintUnit($storeId);

            if ($balance < $totalNeeded) {
                // Pre-flight; not a mint or wallet fault — see MintReliability.
                throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
            }

            $selectedProofs = Wallet::selectProofs($proofs, $totalNeeded);

            try {
                $result = $wallet->melt($meltQuote->quote, $selectedProofs);
            } catch (Exception $e) {
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }

            if (!$result['paid']) {
                $msg = ($result['pending'] ?? false)
                    ? 'Lightning payment pending - proofs marked as pending for recovery'
                    : 'Lightning payment failed';
                $e = new Exception($msg);
                self::recordMeltFailure($mintUrl, $address, $storeId, $e, 'melt', $meltQuoteId, $wallet);
                throw $e;
            }

            if ($mintUrl !== null && $mintUrl !== '') {
                MintReliability::recordWithdrawSuccess($mintUrl, $address, $storeId);
            }

            return [
                'success' => true,
                'preimage' => $result['preimage'],
                'amountPaid' => $meltQuote->amount,
                'fee' => $meltQuote->feeReserve - Wallet::sumProofs($result['change'] ?? []),
                'changeAmount' => Wallet::sumProofs($result['change'] ?? []),
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Classify and record a meltToAddress failure against the reliability
     * tracker. INSUFFICIENT_BALANCE is filtered out inside MintReliability.
     */
    private static function recordMeltFailure(
        ?string $mintUrl,
        ?string $address,
        string $storeId,
        Exception $e,
        string $stage,
        ?string $meltQuoteId,
        $wallet
    ): void {
        if ($mintUrl === null || $mintUrl === '') {
            return;
        }
        $kind = MintReliability::classifyException($e, $stage);
        MintReliability::recordWithdrawFailure(
            $mintUrl,
            $address,
            $storeId,
            $kind,
            $e->getMessage(),
            $meltQuoteId,
            $wallet
        );
    }

    /**
     * Check and perform auto-melt for all stores with auto-melt enabled
     * Called on each admin page load to check if any stores need auto-withdrawal
     */
    public static function checkAutoMelt(): ?array {
        // Get all stores with auto-melt enabled. Includes auto_melt_use_swap +
        // the on-chain fields {@see SwapAutoMelt::modeForStore} needs, so we
        // can hand stores that opted into swap-mode auto-melt over to
        // SwapAutoMelt::checkAndExecute without re-querying.
        $stores = Database::fetchAll(
            "SELECT id, name, mint_url, mint_unit, auto_melt_address, auto_melt_threshold,
                    auto_melt_use_swap, onchain_address_mode, onchain_xpub,
                    swaps_enabled,
                    price_provider_primary, price_provider_secondary
             FROM stores
             WHERE auto_melt_enabled = 1
               AND auto_melt_address IS NOT NULL
               AND auto_melt_address != ''
               AND mint_url IS NOT NULL
               AND seed_phrase IS NOT NULL"
        );

        if (empty($stores)) {
            return null;
        }

        $results = [];

        foreach ($stores as $store) {
            if (SwapAutoMelt::modeForStore($store) === 'swap') {
                continue;
            }
            try {
                // Check store balance from local storage (offline-first, no mint contact)
                // This prevents crashes when mint is unreachable
                $balance = Invoice::getBalance($store['id']);
                $mintUnit = strtolower($store['mint_unit'] ?? 'sat');
                $isFiatMint = !in_array($mintUnit, ['sat', 'sats', 'msat']);

                if ($balance >= $store['auto_melt_threshold']) {
                    // Fees (upstream dev / dev / hosting) are settled separately
                    // on the cron fee-settlement tick; auto-melt just empties the
                    // post-fee balance to the operator's Lightning address.
                    $meltAmountInMintUnit = $balance;

                    if ($meltAmountInMintUnit < 1) {
                        continue;
                    }

                    // For Lightning payments, we need the amount in SATS
                    // Convert from mint unit to sats if using a fiat mint
                    if ($isFiatMint) {
                        $meltAmountSats = ExchangeRates::convertMintUnitToSats(
                            $meltAmountInMintUnit,
                            $mintUnit,
                            $store['price_provider_primary'] ?? null,
                            $store['price_provider_secondary'] ?? null
                        );
                    } else {
                        $meltAmountSats = $meltAmountInMintUnit;
                    }

                    // Account for Lightning fee reserve - mints charge fees on top of invoice amount
                    // Use 1% fee buffer (min 2 sats, max 100 sats) to ensure we have enough for fees
                    $feeBuffer = max(2, min(100, (int)ceil($meltAmountSats * 0.01)));
                    $meltAmountSats = $meltAmountSats - $feeBuffer;

                    if ($meltAmountSats < 1) {
                        continue;
                    }

                    // Perform melt to Lightning address (amount in SATS)
                    // This is wrapped in try-catch to handle mint failures gracefully
                    try {
                        $result = self::meltToAddress(
                            $store['id'],
                            $store['auto_melt_address'],
                            $meltAmountSats,
                            'BareBits Lite auto-withdrawal'
                        );

                        // Record successful melt for fee-base accounting + future stats.
                        // Network fee converted to sats for fiat mints so the dev-fee
                        // base shrinks honestly regardless of mint unit.
                        $networkFeeSats = (int)($result['fee'] ?? 0);
                        if ($isFiatMint && $networkFeeSats > 0) {
                            $networkFeeSats = ExchangeRates::convertMintUnitToSats(
                                $networkFeeSats,
                                $mintUnit,
                                $store['price_provider_primary'] ?? null,
                                $store['price_provider_secondary'] ?? null
                            );
                        }
                        require_once __DIR__ . '/dev_fee.php';
                        MeltLog::record(
                            $store['id'],
                            $meltAmountSats,
                            $networkFeeSats,
                            $store['auto_melt_address'],
                            $result['preimage'] ?? null,
                            null
                        );

                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'amount' => $meltAmountSats,
                            'amountMintUnit' => $meltAmountInMintUnit,
                            'mintUnit' => $mintUnit,
                            'success' => true,
                        ];

                        NotificationSender::queueAutoWithdrawSuccess(
                            $store['id'],
                            $meltAmountSats,
                            $store['auto_melt_address']
                        );

                        error_log("Auto-melt: Sent {$meltAmountSats} sats (~{$meltAmountInMintUnit} {$mintUnit}) from store {$store['name']} to {$store['auto_melt_address']}");
                    } catch (Exception $meltError) {
                        // Melt operation failed (mint unreachable, insufficient funds, etc.)
                        // Log and continue - don't crash the entire admin page load
                        error_log("Auto-melt operation failed for store {$store['id']}: " . $meltError->getMessage());
                        NotificationSender::queueAutoWithdrawFailure(
                            $store['id'],
                            $store['auto_melt_address'],
                            $meltError->getMessage(),
                            $meltAmountSats
                        );
                        $results[] = [
                            'store_id' => $store['id'],
                            'store_name' => $store['name'],
                            'success' => false,
                            'error' => $meltError->getMessage(),
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Auto-melt check failed for store {$store['id']}: " . $e->getMessage());
                // Pre-flight failure (balance lookup, etc.) — still notify so
                // operators see that auto-withdrawal is wedged.
                if (!empty($store['auto_melt_address'])) {
                    NotificationSender::queueAutoWithdrawFailure(
                        $store['id'],
                        $store['auto_melt_address'],
                        $e->getMessage(),
                        null
                    );
                }
                $results[] = [
                    'store_id' => $store['id'],
                    'store_name' => $store['name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return empty($results) ? null : $results;
    }

    /**
     * Check if input is a BOLT-11 Lightning invoice
     */
    public static function isBolt11Invoice(string $input): bool {
        $input = strtolower(trim($input));
        return preg_match('/^ln(bc|tb|tbs|bcrt)[0-9]/', $input) === 1;
    }

    /**
     * Get the amount from a BOLT-11 invoice (if it has one)
     *
     * @param string $bolt11 The BOLT-11 invoice string
     * @param string|null $storeId Optional store ID for wallet access (fixes issue with random store selection)
     * @return array|null Array with 'amountSats', 'amountMintUnit', 'feeReserve' or null on error
     */
    public static function getBolt11Amount(string $bolt11, ?string $storeId = null): ?array {
        // Parse the bolt11 amount locally first (no network needed)
        $amountSats = self::parseBolt11Amount($bolt11);

        try {
            // Get wallet for the specified store, or fall back to first configured store
            if ($storeId) {
                $wallet = Invoice::getWalletInstance($storeId);
            } else {
                $stores = Database::fetchAll("SELECT id FROM stores WHERE mint_url IS NOT NULL AND seed_phrase IS NOT NULL LIMIT 1");
                if (empty($stores)) {
                    return ['amountSats' => $amountSats, 'amountMintUnit' => null, 'feeReserve' => null];
                }
                $wallet = Invoice::getWalletInstance($stores[0]['id']);
            }

            // Get melt quote (returns amount in mint's unit and fee estimate)
            $meltQuote = $wallet->requestMeltQuote($bolt11);

            return [
                'amountSats' => $amountSats,
                'amountMintUnit' => $meltQuote->amount,
                'feeReserve' => $meltQuote->feeReserve,
            ];
        } catch (Exception $e) {
            error_log("getBolt11Amount error: " . $e->getMessage());
            // Still return the locally-parsed amount even if melt quote fails
            return ['amountSats' => $amountSats, 'amountMintUnit' => null, 'feeReserve' => null, 'meltError' => $e->getMessage()];
        }
    }

    /**
     * Parse the amount from a BOLT-11 invoice string
     *
     * @param string $bolt11 The BOLT-11 invoice string
     * @return int Amount in satoshis (0 if no amount encoded or parse error)
     */
    public static function parseBolt11Amount(string $bolt11): int {
        $bolt11 = strtolower(trim($bolt11));

        // BOLT-11 format: ln<prefix><amount><multiplier><data>
        // Prefix: bc (mainnet), tb (testnet), tbs (signet), bcrt (regtest)
        // Amount: optional digits followed by optional multiplier
        // Multipliers: m (milli = 0.001), u (micro = 0.000001), n (nano = 0.000000001), p (pico = 0.000000000001)

        $patterns = [
            '/^lnbc(\d+)([munp]?)1/' => 'mainnet',
            '/^lntb(\d+)([munp]?)1/' => 'testnet',
            '/^lntbs(\d+)([munp]?)1/' => 'signet',
            '/^lnbcrt(\d+)([munp]?)1/' => 'regtest',
        ];

        foreach ($patterns as $pattern => $network) {
            if (preg_match($pattern, $bolt11, $matches)) {
                $amount = (int)$matches[1];
                $multiplier = $matches[2] ?? '';

                // Convert to satoshis based on multiplier
                // 1 BTC = 100,000,000 sats
                switch ($multiplier) {
                    case '':
                        // Amount is in BTC
                        return $amount * 100000000;
                    case 'm':
                        // Amount is in milli-BTC (0.001 BTC)
                        return $amount * 100000;
                    case 'u':
                        // Amount is in micro-BTC (0.000001 BTC)
                        return $amount * 100;
                    case 'n':
                        // Amount is in nano-BTC (0.000000001 BTC)
                        // 1 nano-BTC = 0.1 sat, round up
                        return (int)ceil($amount / 10);
                    case 'p':
                        // Amount is in pico-BTC (0.000000000001 BTC)
                        // 1 pico-BTC = 0.0001 sat, round up
                        return (int)ceil($amount / 10000);
                    default:
                        return 0;
                }
            }
        }

        // No amount prefix found (zero-amount invoice)
        // Check for invoices without amount (just prefix + 1 + data)
        if (preg_match('/^ln(bc|tb|tbs|bcrt)1/', $bolt11)) {
            return 0;
        }

        return 0;
    }

    /**
     * Melt tokens to a BOLT-11 invoice.
     *
     * Failures here flow through the mint reliability tracker on the same
     * terms as {@see meltToAddress}: a manual BOLT-11 melt (or a dev-fee
     * settlement, which also goes through this method) that fails counts
     * toward the mint's withdrawal-failure state and therefore toward stuck-
     * fund detection. Insufficient balance is filtered out inside
     * MintReliability.
     *
     * @param string $storeId Store ID for wallet access
     * @param string $bolt11 The BOLT-11 invoice
     * @param int|null $expectedAmount Optional expected amount (for amountless invoices)
     */
    public static function meltToBolt11(string $storeId, string $bolt11, ?int $expectedAmount = null): array {
        $mintUrl = Config::getStoreMintUrl($storeId);
        $wallet = null;
        $meltQuoteId = null;

        // BOLT-11 is a one-shot invoice, not a reusable address — pass null
        // so the reliability tracker doesn't use it for cross-mint
        // differential resolution (which is keyed on stable Lightning
        // addresses).
        $addressForRecord = null;

        try {
            $wallet = Invoice::getWalletInstance($storeId);
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', null, null);
            throw $e;
        }

        try {
            $meltQuote = $wallet->requestMeltQuote($bolt11);
            $meltQuoteId = $meltQuote->quote ?? null;
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'requestMeltQuote', null, $wallet);
            throw $e;
        }

        $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;

        // Get unspent proofs for this store
        $proofs = Invoice::getUnspentProofs($storeId);
        $balance = Wallet::sumProofs($proofs);

        $mintUnit = Config::getStoreMintUnit($storeId);

        if ($balance < $totalNeeded) {
            // Pre-flight; not a mint or wallet fault — see MintReliability.
            throw new Exception("Insufficient balance. Have: {$balance} {$mintUnit}, Need: {$totalNeeded} {$mintUnit}");
        }

        // Select proofs
        $selectedProofs = Wallet::selectProofs($proofs, $totalNeeded);

        // Execute melt
        try {
            $result = $wallet->melt($meltQuote->quote, $selectedProofs);
        } catch (Exception $e) {
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'melt', $meltQuoteId, $wallet);
            throw $e;
        }

        if (!$result['paid']) {
            $msg = ($result['pending'] ?? false)
                ? 'Lightning payment pending - proofs marked as pending for recovery'
                : 'Lightning payment failed';
            $e = new Exception($msg);
            self::recordMeltFailure($mintUrl, $addressForRecord, $storeId, $e, 'melt', $meltQuoteId, $wallet);
            throw $e;
        }

        if ($mintUrl !== null && $mintUrl !== '') {
            MintReliability::recordWithdrawSuccess($mintUrl, null, $storeId);
        }

        return [
            'success' => true,
            'preimage' => $result['preimage'],
            'amountPaid' => $meltQuote->amount,
            'fee' => $meltQuote->feeReserve - Wallet::sumProofs($result['change'] ?? []),
            'changeAmount' => Wallet::sumProofs($result['change'] ?? []),
        ];
    }
}

/**
 * UpstreamDevFee — pay the original CashuPayServer author via the existing
 * cypherpunk.today donation sink (Cashu-token POST mechanism).
 *
 * Previously this fee was charged on every withdrawal as an opt-out
 * "donation". It is now charged on the periodic fee-settlement cron tick
 * (see DevFee::settleStore) once ≥ 1000 sats are owed, and the sats paid
 * count as a network cost when computing the Modified MIT dev fee base.
 */
class UpstreamDevFee {
    /**
     * Send tokens to the upstream dev fee sink
     *
     * @param string $storeId Store ID for wallet access
     * @param int $amount Amount in mint units (NOT sats — caller should convert
     *                    if the store mint is fiat-denominated)
     */
    public static function sendToSink(string $storeId, int $amount): array {
        if ($amount < 1) {
            return ['success' => false, 'token' => null, 'error' => 'Amount too small'];
        }

        try {
            $wallet = Invoice::getWalletInstance($storeId);
            $proofs = Invoice::getUnspentProofs($storeId);

            // Check proof states at mint - filter out PENDING/SPENT proofs
            if (!empty($proofs)) {
                try {
                    $states = $wallet->checkProofState($proofs);
                    $validProofs = [];
                    $spentSecrets = [];

                    foreach ($states as $i => $state) {
                        $mintState = $state['state'] ?? ProofState::UNSPENT;
                        if ($mintState === ProofState::UNSPENT) {
                            $validProofs[] = $proofs[$i];
                        } elseif ($mintState === ProofState::SPENT) {
                            $spentSecrets[] = $proofs[$i]->secret;
                        }
                        // Skip PENDING proofs - they can't be used for split
                    }

                    if (!empty($spentSecrets)) {
                        Invoice::markProofsSpent($storeId, $spentSecrets);
                    }

                    $proofs = $validProofs;
                } catch (\Cashu\CashuException $e) {
                    error_log("UpstreamDevFee checkProofState failed: " . $e->getMessage());
                }
            }

            $balance = Wallet::sumProofs($proofs);

            $fee = $wallet->calculateFee($proofs);
            $totalNeeded = $amount + $fee;

            if ($balance < $totalNeeded) {
                return ['success' => false, 'token' => null, 'error' => 'Insufficient balance for upstream dev fee'];
            }

            if ($fee > $amount) {
                return ['success' => false, 'token' => null, 'error' => 'Fee exceeds upstream dev fee amount'];
            }

            $result = $wallet->split($proofs, $amount);
            $feeProofs = $result['send'];

            // Mark proofs as SPENT immediately - they're sent to the sink and gone from our wallet
            // Using PENDING causes race conditions: the mint may report different states depending on
            // when the sink processes the token, causing "proofs are pending" errors on exports
            $feeSecrets = array_map(fn($p) => $p->secret, $feeProofs);
            $wallet->getStorage()->updateProofsState($feeSecrets, ProofState::SPENT);

            $token = $wallet->serializeToken($feeProofs);

            self::postTokenToSink($token);

            return ['success' => true, 'token' => $token, 'error' => null];

        } catch (Exception $e) {
            error_log("UpstreamDevFee error: " . $e->getMessage());
            return ['success' => false, 'token' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST token to upstream dev fee sink (fire and forget)
     */
    public static function postTokenToSink(string $token): void {
        if (!defined('CASHUPAY_UPSTREAM_DEV_FEE_SINK_URL')) {
            error_log("Upstream dev fee sink URL not configured");
            return;
        }

        try {
            $ch = curl_init(CASHUPAY_UPSTREAM_DEV_FEE_SINK_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                error_log("Upstream dev fee sink POST failed: " . curl_error($ch));
            } elseif ($httpCode >= 400) {
                error_log("Upstream dev fee sink returned HTTP {$httpCode}: {$response}");
            } else {
                error_log("Upstream dev fee sent successfully to sink");
            }

        } catch (Exception $e) {
            error_log("Upstream dev fee sink error: " . $e->getMessage());
        }
    }

    /**
     * Calculate upstream dev fee amount from a given base amount (in sats).
     * Floor-rounded; minimum 1 sat when the base is > 0.
     */
    public static function calculateAmount(int $baseSats): int {
        if (!defined('CASHUPAY_UPSTREAM_DEV_FEE_PERCENT')) {
            return 0;
        }
        if ($baseSats < 1) {
            return 0;
        }
        return (int)floor($baseSats * CASHUPAY_UPSTREAM_DEV_FEE_PERCENT / 100);
    }
}
