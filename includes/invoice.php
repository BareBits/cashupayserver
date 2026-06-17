<?php
/**
 * CashuPayServer - Invoice Module
 *
 * Invoice creation, management, and payment detection.
 * Supports per-store wallet configuration.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/webhook_sender.php';
require_once __DIR__ . '/notification_sender.php';
require_once __DIR__ . '/urls.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';
require_once __DIR__ . '/onchain/payments.php';
require_once __DIR__ . '/swap/factory.php';
require_once __DIR__ . '/swap/config.php';
require_once __DIR__ . '/swap/quote_fetcher.php';
require_once __DIR__ . '/swap/poller.php';
require_once __DIR__ . '/crypto/secp256k1.php';
require_once __DIR__ . '/crypto/taproot.php';
require_once __DIR__ . '/lnurl_receive.php';
require_once __DIR__ . '/store_ln_addresses.php';
require_once __DIR__ . '/fee_redirect.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\Proof;
use Cashu\ProofState;

class Invoice {
    /**
     * Create a new invoice
     *
     * Uses per-store mint configuration and supports multi-mint fallback.
     */
    public static function create(string $storeId, array $options): array {
        $amount = $options['amount'];
        $currency = $options['currency'] ?? 'sat';
        $metadata = $options['metadata'] ?? null;
        $checkout = $options['checkout'] ?? null;

        // Get store configuration
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $cashuConfigured = Config::isStoreConfigured($storeId);
        // A store is "on-chain configured" if it has either an xpub (default
        // mode) or a static receive address (alternative mode for merchants
        // without an xpub). Treating only xpub as configured caused
        // static-mode invoices to be created without an on-chain payment
        // method block.
        $onchainMode = $store['onchain_address_mode'] ?? 'xpub';
        $onchainConfigured = ($onchainMode === 'static')
            ? !empty($store['onchain_static_address'])
            : !empty($store['onchain_xpub']);
        if (!$cashuConfigured && !$onchainConfigured) {
            throw new Exception(
                'Store has no payment methods configured. Add a Cashu mint or an on-chain xpub.'
            );
        }

        $exchangeFee = (float)($store['exchange_fee_percent'] ?? 0);
        $primaryProvider = $store['price_provider_primary'] ?? 'coingecko';
        $secondaryProvider = $store['price_provider_secondary'] ?? 'binance';

        // Get exchange rate for fiat currencies (used by both payment methods).
        $exchangeRate = null;
        if (!in_array(strtoupper($currency), ['SAT', 'SATS', 'BTC'])) {
            $exchangeRate = ExchangeRates::getBtcPrice($currency, $primaryProvider, $secondaryProvider);
        }

        // ---- Fee-redirect path: when a fee (dev / upstream / hosting) is
        // already owed in an amount >= this invoice, route the rails that fee
        // can cover straight to its destination instead of the merchant. The
        // remaining offered rails fall through to the normal merchant logic
        // below, so an invoice can be mixed (e.g. lightning -> fee payee,
        // on-chain -> merchant). Whichever rail the customer actually pays
        // decides attribution at settlement (see Invoice::railIsFeeRouted); any
        // fee not collected this way is still melted out by the cron. A single
        // fee owns whichever rails it covers — see FeeRedirect::decide. ----
        $invoiceSats = (int) ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
        $lnAutoMeltForRedirect = (int)($store['auto_melt_enabled'] ?? 0) === 1;
        $lnAddrsForRedirect = $lnAutoMeltForRedirect
            ? StoreLnAddresses::addressesForStore($storeId)
            : [];
        $lightningCapable = $cashuConfigured
            || !empty($lnAddrsForRedirect)
            || (SwapsConfig::isEnabledForStore($storeId) && $onchainConfigured);
        $offeredRails = [];
        if ($lightningCapable)   { $offeredRails[] = 'lightning'; }
        if ($onchainConfigured)  { $offeredRails[] = 'onchain'; }

        $feeRoute       = FeeRedirect::decide($storeId, $store, $invoiceSats, $offeredRails);
        $feeNote        = $feeRoute['note'] ?? null;
        $feeRails       = $feeRoute['rails'] ?? [];
        $feeDestination = $feeRoute['destination'] ?? null;
        // Per-rail fee destinations (null = that rail stays with the merchant).
        $feeLightning   = $feeRoute['lightning'] ?? null; // ['bolt11','verify_url','destination']
        $feeOnchain     = $feeRoute['onchain'] ?? null;   // ['address','index','tip_height','destination']

        // ---- LNURL direct-receive path: route LN payment straight to the
        // merchant's auto-cashout LN address when the host supports LUD-21
        // (verify URL) so we can detect settlement without running the LN
        // node ourselves. Wins over swap and mint when eligible. ----
        $lnurlAttempt = null;          // ['bolt11','verify_url','amount_sats'] on success
        $lnurlOverrideReason = null;   // set when override-gate fired; recorded on the fallback invoice
        $lnAutoMeltEnabled = (int)($store['auto_melt_enabled'] ?? 0) === 1;
        // Ordered fallback chain: try each address in priority order until one
        // yields a usable invoice. The single auto_melt_address column was
        // replaced by the store_ln_addresses table.
        $lnAddresses = $lnAutoMeltEnabled
            ? StoreLnAddresses::addressesForStore($storeId)
            : [];
        // Skip the merchant lightning rail entirely when a fee owns it — the
        // single lightning option on this invoice is the fee LNURL's bolt11.
        if ($feeLightning === null && !empty($lnAddresses)) {
            $lnurlTargetSats = (int) ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
            $feesDueSats = LnUrlReceive::feesDueSats($storeId);
            $decision = LnUrlReceive::shouldOverride($feesDueSats, $lnurlTargetSats);
            // Log every routing decision so the override mechanism is
            // auditable from production logs — both fired and skipped cases.
            // The override gate is amount-vs-fees based, independent of which
            // address is used, so it's decided once for the whole chain.
            error_log(sprintf(
                '[lnurl-override] store=%s invoice_sats=%d fees_due_sats=%d override=%s reason=%s',
                $storeId, $lnurlTargetSats, $feesDueSats,
                $decision['override'] ? '1' : '0',
                $decision['reason']
            ));
            if ($decision['override']) {
                // Skip LNURL this invoice and remember why; the mint-rail
                // path below will record this on the invoice so settlement
                // can fire the immediate-settle-and-forward handler.
                $lnurlOverrideReason = $decision['reason'];
            } elseif ($lnurlTargetSats > 0) {
                // Walk the priority chain. First address to return a usable
                // invoice wins; the rest are only tried when earlier ones are
                // down / out of range / lack a LUD-21 verify URL.
                foreach ($lnAddresses as $priority => $lnAddress) {
                    if (!StoreLnAddresses::isValid($lnAddress)) {
                        error_log(sprintf(
                            '[lnurl-receive] skipping malformed address store=%s priority=%d address=%s',
                            $storeId, $priority, $lnAddress
                        ));
                        continue;
                    }
                    try {
                        $probed = LnUrlReceive::probeAndFetchInvoice(
                            $lnAddress, $lnurlTargetSats
                        );
                    } catch (Throwable $e) {
                        error_log("[lnurl-receive] probe threw for store {$storeId} address {$lnAddress}: " . $e->getMessage());
                        $probed = null;
                    }
                    if ($probed !== null) {
                        $lnurlAttempt = [
                            'bolt11' => $probed['bolt11'],
                            'verify_url' => $probed['verify_url'],
                            'amount_sats' => $lnurlTargetSats,
                            // The LN address this bolt11 was fetched from. Persisted
                            // as ln_destination so the admin invoice view can show
                            // where a lightning payment was sent.
                            'address' => $lnAddress,
                        ];
                        if ($priority > 0) {
                            error_log(sprintf(
                                '[lnurl-receive] using fallback address store=%s priority=%d address=%s',
                                $storeId, $priority, $lnAddress
                            ));
                        }
                        break;
                    }
                    error_log(sprintf(
                        '[lnurl-receive] probe failed for store=%s priority=%d address=%s amount_sats=%d; trying next',
                        $storeId, $priority, $lnAddress, $lnurlTargetSats
                    ));
                }
                if ($lnurlAttempt === null) {
                    error_log(sprintf(
                        '[lnurl-receive] all %d address(es) failed for store=%s amount_sats=%d; falling back to swap/mint/onchain',
                        count($lnAddresses), $storeId, $lnurlTargetSats
                    ));
                }
            }
        }

        // ---- Submarine-swap path: replaces the cashu mint with a non-custodial
        // LN→on-chain swap that settles directly to the merchant's xpub. ----
        // Suppressed when a fee owns the lightning rail (the fee supplies the
        // bolt11) or the on-chain rail (a swap invoice must stay lightning-only,
        // so we don't pair it with a fee-owned on-chain address).
        $swapAttempt = null; // populated by self::trySwapCreate on success
        if ($lnurlAttempt === null && $feeLightning === null && $feeOnchain === null
            && SwapsConfig::isEnabledForStore($storeId) && $onchainConfigured) {
            // Target = what the merchant wants to receive on-chain in sats.
            $targetSats = ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
            $swapFailures = []; // per-provider reasons, populated by trySwapCreate
            $swapAttempt = self::trySwapCreate($storeId, $store, $targetSats, $swapFailures);
            if ($swapAttempt === null && SwapsConfig::strictNoMintFallback()) {
                // Surface each provider's reason so the operator can act
                // (typically: "Boltz: amount 10000 sat outside range [50000, 5000000]").
                $detail = $swapFailures
                    ? ' Provider attempts: ' . implode('; ', $swapFailures) . '.'
                    : '';
                throw new Exception(
                    'Submarine swap could not be created for ' . $targetSats . ' sat target.'
                    . ' Strict mode is on (no mint fallback).' . $detail
                );
            }
        }

        // ---- Cashu / Lightning path: try mint quote(s) if a mint is configured ----
        // Skipped when the swap path won — the swap supplies its own BOLT11 and
        // the customer must pay that exact invoice (mint would create a different one).
        $quote = null;
        $usedMintUrl = null;
        $amountInMintUnit = null;
        if ($cashuConfigured && $swapAttempt === null && $lnurlAttempt === null
            && $feeLightning === null) {
            $mintUnit = $store['mint_unit'];
            $amountInMintUnit = ExchangeRates::convertToMintUnit(
                $amount, $currency, $mintUnit, $exchangeFee, $primaryProvider, $secondaryProvider
            );
            require_once __DIR__ . '/mint_reliability.php';
            $allMints = Config::getStoreAllMintUrls($storeId);
            $lastError = null;
            foreach ($allMints as $tryMintUrl) {
                try {
                    $wallet = self::getWalletForStore($storeId, $tryMintUrl);
                    $quote = $wallet->requestMintQuote($amountInMintUnit);
                    $usedMintUrl = $tryMintUrl;
                    MintReliability::recordQuoteSuccess($tryMintUrl, $storeId);
                    break;
                } catch (Exception $e) {
                    $lastError = $e;
                    error_log("Mint quote failed for $tryMintUrl: " . $e->getMessage());
                    $kind = MintReliability::classifyException($e, 'requestMintQuote');
                    MintReliability::recordQuoteFailure($tryMintUrl, $storeId, $kind, $e->getMessage());
                    continue;
                }
            }
            if ($quote === null && !$onchainConfigured) {
                throw new Exception(
                    'Failed to get mint quote from all configured mints. '
                    . 'Last error: ' . ($lastError ? $lastError->getMessage() : 'Unknown')
                );
            }
        }

        // ---- On-chain path: allocate a receive address ----
        // Skipped on swap-rail invoices — the customer is paying via Lightning,
        // and offering a pay-to-address option in parallel would create a
        // second settlement path the swap lifecycle is not aware of.
        //
        // In xpub mode the allocation derives a fresh address per invoice. In
        // static-address mode it returns the shared address plus a per-invoice
        // tweak (in sats) that makes the expected total unique among open
        // invoices, so incoming txs can be attributed by exact amount match.
        $onchainAddress = null;
        $onchainIndex = null;
        $onchainAmountSat = null;
        $onchainAmountTweakSats = null;
        $onchainCreatedTipHeight = null;
        if ($onchainConfigured && $swapAttempt === null && $lnurlAttempt === null
            && $feeOnchain === null) {
            $baseAmountSat = (int)ExchangeRates::convertToSats((string)$amount, $currency, 'sat');
            try {
                $allocation = OnchainPayments::allocateAddress($storeId, $baseAmountSat);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === OnchainPayments::ERR_TWEAK_SLOTS_EXHAUSTED) {
                    throw new RuntimeException(
                        'All on-chain payment slots are temporarily reserved. Please try again in a few minutes.'
                    );
                }
                throw $e;
            }
            if ($allocation !== null) {
                $onchainAddress = $allocation['address'];
                $onchainIndex = $allocation['index'];
                $onchainCreatedTipHeight = $allocation['tip_height'] ?? null;
                $tweak = $allocation['tweak'] ?? null;
                $onchainAmountTweakSats = $tweak;
                $onchainAmountSat = $baseAmountSat + ($tweak !== null ? (int)$tweak : 0);
            }
        }

        // Fee-redirect on-chain rail: the fee payee's xpub-derived address
        // (allocated in FeeRedirect::buildRails) replaces the merchant's
        // on-chain rail on this invoice. The customer pays the exact invoice
        // amount — no uniqueness tweak, since each fee address is fresh.
        if ($feeOnchain !== null) {
            $onchainAddress = $feeOnchain['address'];
            $onchainIndex = $feeOnchain['index'] ?? null;
            $onchainAmountSat = $invoiceSats;
            $onchainAmountTweakSats = null;
            $onchainCreatedTipHeight = $feeOnchain['tip_height'] ?? null;
        }

        // Calculate expiration. Swap-rail invoices use the provider's BOLT11
        // expiration plus an extra grace window for cron to drive the claim;
        // mint-rail and onchain-rail use the existing default.
        $expiration = ($quote && isset($quote->expiry))
            ? $quote->expiry
            : (time() + Config::getInvoiceExpiration());

        // Generate invoice ID
        $invoiceId = Database::generateId('inv');
        $now = Database::timestamp();

        // Decide payment_rail + final field values
        $lnurlVerifyUrl = null;
        // The Lightning destination the bolt11 points at (LN address / LNURL).
        // Only set for lnaddress-rail invoices; NULL for mint/swap/onchain.
        $lnDestination = null;
        if ($feeLightning !== null) {
            // Fee-redirect lightning rail: the customer pays the fee LNURL's
            // bolt11 directly. Rides payment_rail='lnaddress' so the existing
            // verify-URL poller detects settlement (the LNURL poller is the
            // only one gated on payment_rail).
            $paymentRail = 'lnaddress';
            $bolt11Final = $feeLightning['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $invoiceSats;
            $lnurlVerifyUrl = $feeLightning['verify_url'];
            $lnDestination = $feeLightning['destination'] ?? null;
        } elseif ($lnurlAttempt !== null) {
            $paymentRail = 'lnaddress';
            $bolt11Final = $lnurlAttempt['bolt11'];
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $lnurlAttempt['amount_sats'];
            $lnurlVerifyUrl = $lnurlAttempt['verify_url'];
            $lnDestination = $lnurlAttempt['address'] ?? null;
        } elseif ($swapAttempt !== null) {
            $paymentRail = 'swap';
            $bolt11Final = $swapAttempt['swap']->invoice;
            $mintUrlFinal = null;
            $quoteIdFinal = null;
            $amountSatsFinal = $swapAttempt['swap']->invoiceAmountSats;
        } else {
            $paymentRail = $quote ? 'mint' : ($onchainAddress ? 'onchain' : 'mint');
            $bolt11Final = $quote ? $quote->request : null;
            $mintUrlFinal = $usedMintUrl;
            $quoteIdFinal = $quote ? $quote->quote : null;
            $amountSatsFinal = $amountInMintUnit;
        }

        // A fee invoice always carries the canonical sat value so revenue math
        // and the fee credit (recordFeeRedirectCredit) reflect the amount the
        // customer actually paid, independent of which rail/mint unit the
        // merchant side used. Mirrors the previous createRedirectInvoice path.
        if ($feeNote !== null) {
            $amountSatsFinal = $invoiceSats;
        }

        Database::insert('invoices', [
            'id' => $invoiceId,
            'store_id' => $storeId,
            'status' => 'New',
            'additional_status' => 'None',
            'amount' => $amount,
            'currency' => $currency,
            'amount_sats' => $amountSatsFinal,
            'exchange_rate' => $exchangeRate,
            'quote_id' => $quoteIdFinal,
            'bolt11' => $bolt11Final,
            'mint_url' => $mintUrlFinal,
            'onchain_address' => $onchainAddress,
            'onchain_address_index' => $onchainIndex,
            'onchain_amount_sat' => $onchainAmountSat,
            'onchain_amount_tweak_sats' => $onchainAmountTweakSats,
            'onchain_created_tip_height' => $onchainCreatedTipHeight,
            'payment_rail' => $paymentRail,
            'lnurl_verify_url' => $lnurlVerifyUrl,
            'lnurl_override_reason' => $lnurlOverrideReason,
            'ln_destination' => $lnDestination,
            'fee_redirect_note' => $feeNote,
            'fee_redirect_destination' => $feeDestination,
            'fee_redirect_rails' => $feeRails ? implode(',', $feeRails) : null,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'checkout_config' => $checkout ? json_encode($checkout) : null,
            'created_at' => $now,
            'expiration_time' => $expiration,
        ]);

        // Persist the swap_attempts row, now that the invoice exists for the FK.
        if ($swapAttempt !== null) {
            self::persistSwapAttempt($invoiceId, $storeId, $swapAttempt, $now);
        }

        $invoice = self::getById($invoiceId);

        // Fire InvoiceCreated webhook
        WebhookSender::fireEvent($storeId, 'InvoiceCreated', $invoice);

        return $invoice;
    }

    /**
     * Try each configured swap provider in order. Returns null if none could
     * service this request (provider unreachable, amount out of range, lockup
     * verification failed). The caller then either falls back to the mint or
     * errors out depending on the site's strict-fallback setting.
     *
     * On success returns:
     *   ['swap' => SwapCreateResult,
     *    'provider' => string,
     *    'network' => string,
     *    'claim_privkey' => string (32 bytes),
     *    'claim_pubkey' => string (33 bytes),
     *    'preimage' => string (32 bytes),
     *    'preimage_hash' => string (32 bytes),
     *    'merchant_address' => string,
     *    'merchant_address_index' => int,
     *    'lockup_fee_sats' => int,
     *    'percent_fee_sats' => int]
     */
    private static function trySwapCreate(string $storeId, array $store, int $targetSats,
                                          ?array &$failureReasons = null): ?array {
        if ($failureReasons === null) $failureReasons = [];
        if ($targetSats <= 0) return null;
        $localMin = SwapsConfig::minimumTargetSats();
        if ($localMin !== null && $targetSats < $localMin) {
            $failureReasons[] = "site min override ({$localMin} sat) blocks {$targetSats} sat target";
            return null;
        }
        $network = $store['onchain_network'] ?? 'mainnet';

        // When auto-select-cheapest is on, rankedForSite() fetches quotes from
        // every reachable provider in parallel and reorders them by total cost
        // (subject to the 10%-cheaper threshold). When off, it returns the
        // configured priority order with no cached quotes — behaviour matches
        // the historical sequential path.
        foreach (SwapProviderFactory::rankedForSite($network, $targetSats) as ['provider' => $provider, 'quote' => $cachedQuote]) {
            $name = $provider->getName();
            try {
                $pairInfo = $cachedQuote ?? $provider->getReversePairInfo($network);
                if ($targetSats < $pairInfo->minSats || $targetSats > $pairInfo->maxSats) {
                    $failureReasons[] = sprintf(
                        "%s: %d sat outside range [%d, %d]",
                        $name, $targetSats, $pairInfo->minSats, $pairInfo->maxSats
                    );
                    continue;
                }

                // Allocate destination address (shared xpub counter).
                $alloc = OnchainPayments::allocateClaimAddress($storeId);
                if ($alloc === null) {
                    // Should not happen — caller guards on $onchainConfigured.
                    $failureReasons[] = "{$name}: store has no on-chain xpub allocated";
                    return null;
                }

                // Per-swap fresh keypair + preimage.
                $claimPriv = random_bytes(32);
                $claimPubPoint = Secp256k1::generatorMult(Secp256k1::bytesToGmp($claimPriv));
                if ($claimPubPoint === null) {
                    $failureReasons[] = "{$name}: claim key derivation failed (retry)";
                    continue;
                }
                $claimPub = Secp256k1::pointToCompressed($claimPubPoint);
                $preimage = random_bytes(32);
                $preimageHash = hash('sha256', $preimage, true);

                $swap = $provider->createReverseSwap(
                    $network,
                    $targetSats,
                    bin2hex($claimPub),
                    bin2hex($preimageHash)
                );

                // Verify the lockup_address Boltz returned matches what we
                // compute locally from the parsed swap tree. Defends against
                // a buggy or hostile provider that would give us an address
                // whose script-path we can't satisfy.
                if (!self::verifySwapLockup($swap, $claimPub, $network)) {
                    error_log("swap: lockup address mismatch from {$name}; trying next");
                    $failureReasons[] = "{$name}: lockup address mismatch (provider returned non-matching script tree)";
                    continue;
                }

                $lockupFee = $pairInfo->lockupFeeSats;
                $percentFee = max(0, $swap->invoiceAmountSats - $targetSats - $lockupFee);

                return [
                    'swap' => $swap,
                    'provider' => $name,
                    'network' => $network,
                    'claim_privkey' => $claimPriv,
                    'claim_pubkey' => $claimPub,
                    'preimage' => $preimage,
                    'preimage_hash' => $preimageHash,
                    'merchant_address' => $alloc['address'],
                    'merchant_address_index' => $alloc['index'],
                    'lockup_fee_sats' => $lockupFee,
                    'percent_fee_sats' => $percentFee,
                    'quotes_audit' => SwapQuoteFetcher::lastAuditTrail(),
                ];
            } catch (Throwable $e) {
                error_log("swap: provider {$name} failed: " . $e->getMessage());
                $failureReasons[] = "{$name}: " . $e->getMessage();
                continue;
            }
        }
        return null;
    }

    /**
     * Recompute the Taproot output key from claim+refund pubkeys + the parsed
     * swap-tree leaves, and compare against the provider-returned lockup
     * address. Returns false on any mismatch.
     */
    private static function verifySwapLockup(SwapCreateResult $swap, string $claimPub33, string $network): bool {
        try {
            $claimLeafHash  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->claimLeafScript);
            $refundLeafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $swap->refundLeafScript);
            $merkleRoot = Taproot::tapBranchHash($claimLeafHash, $refundLeafHash);
            $refundPub33 = hex2bin($swap->refundPublicKeyHex);
            $internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
            [$outKey, $_parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
            $expected = Taproot::encodeP2trAddress($outKey, $network);
            return strcasecmp($expected, $swap->lockupAddress) === 0;
        } catch (Throwable $e) {
            error_log('swap: lockup verification threw: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Persist the swap_attempts row after the parent invoice has been inserted.
     */
    private static function persistSwapAttempt(string $invoiceId, string $storeId, array $att, int $now): void {
        $swap = $att['swap'];
        $quotesAuditJson = !empty($att['quotes_audit'])
            ? json_encode($att['quotes_audit'])
            : null;
        Database::getInstance()->prepare(
            "INSERT INTO swap_attempts (
                invoice_id, store_id, provider, network, direction,
                swap_id_external, status,
                preimage_hex, preimage_hash_hex,
                claim_pubkey_hex, claim_privkey_hex, refund_pubkey_hex,
                lockup_address, timeout_block_height,
                claim_leaf_script_hex, refund_leaf_script_hex,
                lightning_invoice,
                target_onchain_amount_sats, invoice_amount_sats,
                swap_lockup_fee_sats, swap_percent_fee_sats,
                merchant_address, merchant_address_index,
                provider_response_json, quotes_compared_json,
                created_at, updated_at
            ) VALUES (
                :invoice_id, :store_id, :provider, :network, :direction,
                :swap_id_external, :status,
                :preimage_hex, :preimage_hash_hex,
                :claim_pubkey_hex, :claim_privkey_hex, :refund_pubkey_hex,
                :lockup_address, :timeout_block_height,
                :claim_leaf_script_hex, :refund_leaf_script_hex,
                :lightning_invoice,
                :target_onchain_amount_sats, :invoice_amount_sats,
                :swap_lockup_fee_sats, :swap_percent_fee_sats,
                :merchant_address, :merchant_address_index,
                :provider_response_json, :quotes_compared_json,
                :created_at, :updated_at
            )"
        )->execute([
            ':invoice_id' => $invoiceId,
            ':store_id' => $storeId,
            ':provider' => $att['provider'],
            ':network' => $att['network'],
            ':direction' => 'reverse',
            ':swap_id_external' => $swap->swapId,
            ':status' => 'swap.created',
            ':preimage_hex' => bin2hex($att['preimage']),
            ':preimage_hash_hex' => bin2hex($att['preimage_hash']),
            ':claim_pubkey_hex' => bin2hex($att['claim_pubkey']),
            ':claim_privkey_hex' => bin2hex($att['claim_privkey']),
            ':refund_pubkey_hex' => $swap->refundPublicKeyHex,
            ':lockup_address' => $swap->lockupAddress,
            ':timeout_block_height' => $swap->timeoutBlockHeight,
            ':claim_leaf_script_hex' => bin2hex($swap->claimLeafScript),
            ':refund_leaf_script_hex' => bin2hex($swap->refundLeafScript),
            ':lightning_invoice' => $swap->invoice,
            ':target_onchain_amount_sats' => $swap->onchainAmountSats,
            ':invoice_amount_sats' => $swap->invoiceAmountSats,
            ':swap_lockup_fee_sats' => $att['lockup_fee_sats'],
            ':swap_percent_fee_sats' => $att['percent_fee_sats'],
            ':merchant_address' => $att['merchant_address'],
            ':merchant_address_index' => $att['merchant_address_index'],
            ':provider_response_json' => $swap->rawResponse ? json_encode($swap->rawResponse) : null,
            ':quotes_compared_json' => $quotesAuditJson,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * Get invoice by ID
     */
    public static function getById(string $id): ?array {
        return Database::fetchOne(
            "SELECT * FROM invoices WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get invoices by store
     */
    public static function getByStore(string $storeId, ?string $status = null, int $limit = 50, int $offset = 0): array {
        $sql = "SELECT * FROM invoices WHERE store_id = ?";
        $params = [$storeId];

        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll($sql, $params);
    }

    /**
     * Update invoice status
     */
    public static function updateStatus(string $invoiceId, string $status, ?string $additionalStatus = null, ?string $settledRail = null): void {
        $updates = ['status' => $status];

        if ($additionalStatus !== null) {
            $updates['additional_status'] = $additionalStatus;
        }

        // Capture the moment we recognise the invoice as paid + which rail
        // actually moved the funds. Used by the admin invoices view.
        if ($status === 'Settled') {
            $updates['paid_at'] = time();
            if ($settledRail !== null) {
                $updates['settled_rail'] = $settledRail;
            }
        }

        // Settlement is terminal and fires InvoiceSettled + a merchant
        // notification, so guard the transition: only the caller that actually
        // flips the row to Settled proceeds. The on-chain poller, mint poll,
        // and offline reconcile can all reach this concurrently. Non-settlement
        // transitions keep their previous unconditional behaviour.
        if ($status === 'Settled') {
            $changed = Database::update('invoices', $updates, 'id = ? AND status != ?', [$invoiceId, 'Settled']);
            if ($changed !== 1) {
                return; // already settled by another path
            }
        } else {
            Database::update('invoices', $updates, 'id = ?', [$invoiceId]);
        }

        // Get updated invoice for webhook
        $invoice = self::getById($invoiceId);

        // Per-rail attribution: an invoice can be mixed (some rails point at a
        // fee payee, the rest at the merchant). Whether THIS settlement is a
        // fee payment depends on which rail actually paid (settled_rail), not
        // on the invoice merely having a fee route. This branch covers the
        // on-chain + mint settlement paths; the lnaddress path settles via
        // markLnAddressPaid and attributes there.
        $settledToFee = $status === 'Settled' && $invoice
            && self::settledRailIsFeeRouted($invoice);
        if ($settledToFee) {
            self::recordFeeRedirectCredit($invoice, $invoice['lnurl_preimage'] ?? null);
        }

        // Fire appropriate webhook
        $eventType = match ($status) {
            'Processing' => 'InvoiceProcessing',
            'Provisional' => 'InvoiceProvisional',
            'Settled' => 'InvoiceSettled',
            'Expired' => 'InvoiceExpired',
            'Invalid' => 'InvoiceInvalid',
            default => null,
        };

        if ($eventType && $invoice) {
            WebhookSender::fireEvent($invoice['store_id'], $eventType, $invoice);
            // The InvoiceSettled webhook always fires (the customer genuinely
            // paid, so order fulfillment proceeds), but the merchant "you were
            // paid" notification is suppressed only when the rail that paid
            // went to the fee payee — for a merchant rail the merchant really
            // was paid and should be notified.
            if ($status === 'Settled' && !$settledToFee) {
                NotificationSender::queueInvoicePaid($invoice);
            }
        }
    }

    /**
     * Map the rail that actually settled an invoice to a logical customer rail.
     * The on-chain poller reports 'onchain'; the mint, lnaddress and swap rails
     * are all lightning from the customer's point of view.
     */
    private static function settledRailToLogical(?string $settledRail): ?string {
        return match ($settledRail) {
            'onchain' => 'onchain',
            'mint', 'lnaddress', 'swap' => 'lightning',
            default => null,
        };
    }

    /**
     * Does the given logical rail ('lightning' | 'onchain') point at the fee
     * payee on this invoice? Reads the fee_redirect_rails CSV written at
     * creation. A NULL/empty value means a normal (all-merchant) invoice.
     */
    public static function railIsFeeRouted(array $invoice, string $logicalRail): bool {
        $raw = (string)($invoice['fee_redirect_rails'] ?? '');
        if ($raw === '') {
            return false;
        }
        $rails = array_map('trim', explode(',', $raw));
        return in_array($logicalRail, $rails, true);
    }

    /**
     * Was the rail that actually settled this invoice routed to the fee payee?
     * Combines settled_rail -> logical rail mapping with the per-rail fee flags.
     */
    public static function settledRailIsFeeRouted(array $invoice): bool {
        $logical = self::settledRailToLogical($invoice['settled_rail'] ?? null);
        return $logical !== null && self::railIsFeeRouted($invoice, $logical);
    }

    /**
     * Record the fee-paid credit for a fee-routed settlement. The rail the
     * customer paid went straight to the fee payee (no cashu proofs spent), so
     * we log a melts row tagged via='redirect' under the fee's note.
     * DevFee::computeOwed sums melts by note, so this immediately reduces the
     * owed amount and stops the cron from melting the same fee out of the
     * wallet.
     *
     * Caller must have confirmed the SETTLED rail is fee-routed
     * (see settledRailIsFeeRouted); we self-guard here too. The credited amount
     * comes from the rail that actually paid: the on-chain rail credits
     * onchain_amount_sat (what the customer sent on-chain), every lightning
     * rail credits amount_sats.
     *
     * Idempotent: guarded by a SELECT and a UNIQUE partial index on
     * (invoice_id) WHERE via='redirect', so dual-rail settlement races can't
     * double-credit.
     */
    private static function recordFeeRedirectCredit(array $invoice, ?string $preimage): void {
        $note = $invoice['fee_redirect_note'] ?? null;
        if (empty($note) || !self::settledRailIsFeeRouted($invoice)) {
            return;
        }
        $invoiceId = (string)$invoice['id'];
        $existing = Database::fetchOne(
            "SELECT 1 AS x FROM melts WHERE invoice_id = ? AND via = 'redirect' LIMIT 1",
            [$invoiceId]
        );
        if ($existing) {
            return;
        }
        // Credit the amount the paying rail actually moved.
        $logical = self::settledRailToLogical($invoice['settled_rail'] ?? null);
        $amount = $logical === 'onchain'
            ? (int)($invoice['onchain_amount_sat'] ?? $invoice['amount_sats'] ?? 0)
            : (int)($invoice['amount_sats'] ?? 0);
        $amount = max(0, $amount);
        try {
            Database::insert('melts', [
                'store_id' => (string)$invoice['store_id'],
                'amount_sats' => $amount,
                'network_fee_sats' => 0,
                'destination' => (string)($invoice['fee_redirect_destination'] ?? ''),
                'preimage' => $preimage ?: null,
                'note' => (string)$note,
                'via' => 'redirect',
                'invoice_id' => $invoiceId,
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            // Lost a race to the other rail's poller (UNIQUE violation) — the
            // credit already exists, so this is a no-op, not an error.
            error_log("[fee-redirect] credit insert skipped for {$invoiceId}: " . $e->getMessage());
            return;
        }
        error_log(sprintf(
            '[fee-redirect] credited %d sats to %s via redirect (invoice=%s rail=%s)',
            $amount, (string)$note, $invoiceId, (string)($invoice['settled_rail'] ?? '?')
        ));
    }

    /**
     * Mark expired invoices without contacting the mint
     *
     * @return int Number of invoices marked as expired
     */
    public static function markExpiredInvoices(): int {
        $stmt = Database::query(
            "UPDATE invoices SET status = 'Expired'
             WHERE status = 'New' AND expiration_time < ?",
            [time()]
        );
        return $stmt->rowCount();
    }

    /**
     * Poll pending quotes and process payments with rate limiting and backoff
     *
     * @param int $minInterval Minimum seconds between polls for the same invoice (default 30)
     * @param int $batchLimit Maximum invoices to poll per call (default 10)
     */
    public static function pollPendingQuotes(int $minInterval = 30, int $batchLimit = 10): void {
        // First, mark all expired invoices without contacting the mint
        self::markExpiredInvoices();

        $now = time();

        // Fetch invoices that need polling with backoff strategy:
        // - Not expired
        // - Not recently polled (respects minInterval)
        // - Ordered by last_polled_at (NULL first = never polled)
        // - Limited batch size to avoid hammering mint
        $pendingInvoices = Database::fetchAll(
            "SELECT * FROM invoices
             WHERE status = 'New'
             AND quote_id IS NOT NULL
             AND expiration_time > ?
             AND (last_polled_at IS NULL OR (? - last_polled_at) >= ?)
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?",
            [$now, $now, $minInterval, $batchLimit]
        );

        if (empty($pendingInvoices)) {
            return;
        }

        foreach ($pendingInvoices as $invoice) {
            try {
                // Update last_polled_at before polling (so we don't re-poll on failure)
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);

                // Get wallet for the exact mint that issued this invoice's quote
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);

                // Check quote status
                $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

                if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                    if ($quoteStatus->isIssued()) {
                        self::completeIssuedInvoice($invoice, $wallet);
                    } else {
                        self::mintAndStoreTokens($invoice, $wallet);
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error polling invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Mint tokens and store proofs
     */
    private static function mintAndStoreTokens(array $invoice, Wallet $wallet): void {
        self::clearWebhookQueue();

        // Mark as Processing BEFORE minting
        Database::update('invoices', ['status' => 'Processing'], 'id = ?', [$invoice['id']]);

        // Mint tokens - library stores proofs in cashu_proofs with quote_id
        $proofs = $wallet->mint($invoice['quote_id'], $invoice['amount_sats']);

        // Update invoice status in a transaction
        Database::beginTransaction();

        try {
            self::queueWebhook($invoice['store_id'], 'InvoiceReceivedPayment', $invoice);

            // Status-guarded settle: the customer browser polls every ~2s while
            // cron and the API can poll the same invoice concurrently. Only the
            // caller that actually flips the row New/Processing -> Settled may
            // fire InvoiceSettled + the merchant notification; without this gate
            // every racing poller re-fires them. rowCount()===0 means another
            // path already settled, so we bail (the mint() above is idempotent
            // via the library's deterministic secrets / pending-op rebuild).
            $settled = Database::update(
                'invoices',
                ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            if ($settled !== 1) {
                Database::rollback();
                self::clearWebhookQueue();
                return;
            }
            $updatedInvoice = self::getById($invoice['id']);
            self::queueWebhook($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);

            Database::commit();

            self::flushWebhookQueue();
            NotificationSender::queueInvoicePaid($updatedInvoice);
            self::maybeFireOverrideSettled($updatedInvoice);
        } catch (Exception $e) {
            Database::rollback();
            self::clearWebhookQueue();
            throw $e;
        }
    }

    /**
     * Mint-rail invoices that landed on the mint rail because the LNURL
     * override gate fired (lnurl_override_reason IS NOT NULL) need to
     * immediately settle owed fees + auto-melt the remainder to the
     * merchant's LN address. See {@see LnUrlReceive::handleOverrideSettled}.
     *
     * Best-effort wrapper around the handler — any failure is logged but
     * doesn't propagate, because the customer invoice is already paid and
     * the cron's regular DevFee::settleStore + auto-melt loop will catch up
     * on the next tick if this synchronous attempt fails.
     */
    private static function maybeFireOverrideSettled(?array $invoice): void {
        if ($invoice === null) {
            return;
        }
        if (empty($invoice['lnurl_override_reason'])) {
            return;
        }
        if (($invoice['settled_rail'] ?? null) !== 'mint') {
            return;
        }
        try {
            LnUrlReceive::handleOverrideSettled((string)$invoice['id']);
        } catch (Throwable $e) {
            error_log('[lnurl-override] handler failed for invoice '
                . ($invoice['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * Poll LUD-21 verify URLs for LNURL-direct-receive invoices. Mirrors
     * {@see pollPendingQuotes}: rate-limited via last_polled_at, batched, and
     * settles the invoice with payment_rail='lnaddress' on settled=true.
     *
     * Unlike the mint path, no tokens get minted into our wallet — the
     * payment lands directly at the merchant's LN address. We just record
     * the settlement (with preimage for cryptographic proof) and fire the
     * InvoiceSettled webhook.
     */
    public static function pollPendingLnAddress(int $minInterval = 30, int $batchLimit = 10): void {
        self::markExpiredInvoices();
        $now = time();

        $pending = Database::fetchAll(
            "SELECT * FROM invoices
              WHERE status = 'New'
                AND payment_rail = 'lnaddress'
                AND lnurl_verify_url IS NOT NULL
                AND expiration_time > ?
                AND (last_polled_at IS NULL OR (? - last_polled_at) >= ?)
              ORDER BY
                  CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                  last_polled_at ASC
              LIMIT ?",
            [$now, $now, $minInterval, $batchLimit]
        );

        if (empty($pending)) {
            return;
        }

        foreach ($pending as $invoice) {
            try {
                Database::update('invoices', ['last_polled_at' => $now], 'id = ?', [$invoice['id']]);
                $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
                if ($result['state'] === 'paid') {
                    self::markLnAddressPaid($invoice, $result['preimage']);
                }
            } catch (Throwable $e) {
                error_log("[lnurl-receive] poll failed for invoice {$invoice['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Single-invoice variant of {@see pollPendingLnAddress} for the live
     * payment-page poll. No rate-limit gate; the customer's tab is already
     * waiting for us.
     */
    public static function pollSingleLnAddress(string $invoiceId): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }
        if (($invoice['payment_rail'] ?? null) !== 'lnaddress') {
            return;
        }
        if (!in_array($invoice['status'], ['New', 'Processing'], true)) {
            return;
        }
        if ($invoice['status'] === 'New' && (int)$invoice['expiration_time'] < time()) {
            self::updateStatus((string)$invoice['id'], 'Expired');
            return;
        }
        if (empty($invoice['lnurl_verify_url'])) {
            return;
        }
        try {
            Database::update('invoices', ['last_polled_at' => time()], 'id = ?', [$invoice['id']]);
            $result = LnUrlReceive::pollVerifyUrl((string)$invoice['lnurl_verify_url']);
            if ($result['state'] === 'paid') {
                self::markLnAddressPaid($invoice, $result['preimage']);
            }
        } catch (Throwable $e) {
            error_log("[lnurl-receive] single poll failed for {$invoiceId}: " . $e->getMessage());
        }
    }

    /**
     * Mark an LNURL-rail invoice as Settled. Stores the preimage from the
     * LUD-21 verify response as cryptographic settlement proof, then fires
     * webhooks + notification queue parallel to the mint-rail settlement
     * path. No minting because the funds went straight to the merchant LN
     * address — there are no proofs in our wallet.
     */
    private static function markLnAddressPaid(array $invoice, ?string $preimage): void {
        Database::beginTransaction();
        try {
            // Status-guarded settle (see mintAndStoreTokens): the lnaddress poll
            // can run from the checkout poll, cron, and the API at once. Only the
            // winner of the New/Processing -> Settled transition records the fee
            // credit + fires webhooks/notifications below.
            $settled = Database::update(
                'invoices',
                [
                    'status' => 'Settled',
                    'paid_at' => time(),
                    'settled_rail' => 'lnaddress',
                    'lnurl_preimage' => $preimage ?: null,
                ],
                'id = ? AND status != ?',
                [$invoice['id'], 'Settled']
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }

        if ($settled !== 1) {
            return; // already settled by another poller
        }

        $updated = self::getById((string)$invoice['id']);
        if ($updated !== null) {
            // The lightning rail just settled (settled_rail='lnaddress'). If
            // that rail was routed to a fee payee, the customer paid the fee
            // LNURL directly: record the credit and skip the merchant "paid"
            // notice. Otherwise it was the merchant's own LN address and the
            // merchant should be notified.
            $settledToFee = self::railIsFeeRouted($updated, 'lightning');
            if ($settledToFee) {
                self::recordFeeRedirectCredit($updated, $preimage);
            }
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updated);
            if (!$settledToFee) {
                NotificationSender::queueInvoicePaid($updated);
            }
        }
        error_log(sprintf(
            '[lnurl-receive] invoice=%s store=%s settled via lnaddress (preimage=%s)',
            $invoice['id'], $invoice['store_id'],
            $preimage ? substr($preimage, 0, 8) . '…' : 'missing'
        ));
    }

    /**
     * Format invoice for API response
     */
    public static function formatForApi(array $invoice): array {
        // Get store's mint unit for proper display
        $mintUnit = Config::getStoreMintUnit($invoice['store_id']);

        $result = [
            'id' => $invoice['id'],
            'storeId' => $invoice['store_id'],
            'amount' => $invoice['amount'],
            'currency' => $invoice['currency'],
            'status' => $invoice['status'],
            'additionalStatus' => $invoice['additional_status'],
            'createdTime' => $invoice['created_at'],
            'expirationTime' => $invoice['expiration_time'],
            'paidTime' => $invoice['paid_at'] ?? null,
            // settled_rail is the rail that actually moved funds; fall back to
            // payment_rail (the rail chosen at create time) for rows that
            // settled before the column existed, or for the Greenfield API
            // path that marks Settled without knowing the rail.
            'paymentRail' => $invoice['settled_rail'] ?: ($invoice['payment_rail'] ?? null),
            'checkoutLink' => Urls::payment($invoice['id']),
        ];

        // Per-store network drives mempool.space URLs in the admin UI. We
        // can't always look up the store (deleted store_id), so default to
        // mainnet on lookup failure.
        $store = Config::getStore($invoice['store_id']);
        $result['network'] = $store['onchain_network'] ?? 'mainnet';

        // Destination + customer-side txid populated per rail. Used by the
        // admin invoices view; null for Lightning-only.
        $rail = $result['paymentRail'];
        if ($rail === 'onchain' && !empty($invoice['onchain_address'])) {
            $result['destination'] = $invoice['onchain_address'];
            $oc = Database::fetchOne(
                "SELECT txid FROM onchain_payments
                  WHERE invoice_id = ?
                  ORDER BY first_seen_at ASC, id ASC
                  LIMIT 1",
                [$invoice['id']]
            );
            if ($oc) {
                $result['txid'] = $oc['txid'];
            }
        } elseif ($rail === 'swap') {
            $sa = Database::fetchOne(
                "SELECT merchant_address, status, lockup_txid, claim_txid
                   FROM swap_attempts
                  WHERE invoice_id = ?
                  ORDER BY id DESC
                  LIMIT 1",
                [$invoice['id']]
            );
            if ($sa) {
                $result['destination'] = $sa['merchant_address'];
                $result['swapStatus'] = $sa['status'];
                // Show the customer-side on-chain event (the provider's
                // lockup). claimTxid is also exposed so the UI can reveal
                // both in a hover tooltip.
                if (!empty($sa['lockup_txid'])) {
                    $result['txid'] = $sa['lockup_txid'];
                }
                if (!empty($sa['claim_txid'])) {
                    $result['claimTxid'] = $sa['claim_txid'];
                }
            }
        } elseif (($rail === 'lnaddress' || $rail === 'mint') && !empty($invoice['bolt11'])) {
            // Lightning rails have no block-chain txid; surface the bolt11 as the
            // "TxID" (rendered copy-only, not as an explorer link) and the LN
            // address / LNURL it was sent to as the destination. The mint rail
            // has no lnurl destination (paid to the mint), so ln_destination is
            // NULL there and the destination cell stays empty.
            $result['txid'] = $invoice['bolt11'];
            $result['txidIsLightning'] = true;
            if (!empty($invoice['ln_destination'])) {
                $result['destination'] = $invoice['ln_destination'];
                $result['destinationIsLightning'] = true;
            }
        }

        // Aggregate payment methods (Lightning + on-chain, both optional).
        $methods = [];
        if ($invoice['bolt11']) {
            $methods['BTC-LightningNetwork'] = [
                'paymentLink' => 'lightning:' . $invoice['bolt11'],
                'destination' => $invoice['bolt11'],
            ];
        }
        $onchain = OnchainPayments::formatPaymentMethod($invoice);
        if ($onchain !== null) {
            $methods['BTC-OnChain'] = $onchain;
        }
        if (!empty($methods)) {
            $result['checkout'] = ['paymentMethods' => $methods];
        }

        // Fee-redirect: surface the badge data for the admin invoice list. An
        // invoice can be mixed — some rails go to a fee payee, the rest to the
        // merchant — so we expose which rails are fee-routed and, once settled,
        // whether the rail that actually paid was a fee rail.
        if (!empty($invoice['fee_redirect_note'])) {
            $feeLabels = [
                'UPSTREAM_DEV_FEE' => 'upstream dev fee',
                'DEV_FEE' => 'dev fee',
                'HOSTING_FEE' => 'hosting fee',
            ];
            $railsRaw = (string)($invoice['fee_redirect_rails'] ?? '');
            $rails = $railsRaw === '' ? [] : array_map('trim', explode(',', $railsRaw));
            $isSettled = ($invoice['status'] ?? '') === 'Settled';
            // Mixed = the merchant still owns a rail that is actually present on
            // this invoice (lightning bolt11 / on-chain address) but isn't
            // fee-routed. Drives the "either / or" wording in the badge.
            $merchantOwnsRail =
                (!empty($invoice['bolt11']) && !in_array('lightning', $rails, true))
                || (!empty($invoice['onchain_address']) && !in_array('onchain', $rails, true));
            $result['feeRedirect'] = [
                'note' => $invoice['fee_redirect_note'],
                'label' => $feeLabels[$invoice['fee_redirect_note']] ?? 'fees',
                'destination' => $invoice['fee_redirect_destination'] ?? null,
                'rails' => $rails,
                'mixed' => $merchantOwnsRail,
                // Decided only at settlement: did the paying rail go to the fee?
                'settled' => $isSettled,
                'settledToFee' => $isSettled && self::settledRailIsFeeRouted($invoice),
            ];
        }

        // Include converted amount in mint unit
        if ($invoice['amount_sats']) {
            $result['amountInMintUnit'] = $invoice['amount_sats'];
            $result['mintUnit'] = $mintUnit;
        }

        if ($invoice['exchange_rate']) {
            $result['exchangeRate'] = [
                'rate' => $invoice['exchange_rate'],
                'currency' => $invoice['currency'],
            ];
        }

        // Include metadata
        if ($invoice['metadata']) {
            $result['metadata'] = json_decode($invoice['metadata'], true);
        }

        // Include checkout config
        if ($invoice['checkout_config']) {
            $checkoutConfig = json_decode($invoice['checkout_config'], true);
            if (isset($checkoutConfig['redirectURL'])) {
                $result['checkout']['redirectURL'] = $checkoutConfig['redirectURL'];
            }
            if (isset($checkoutConfig['redirectAutomatically'])) {
                $result['checkout']['redirectAutomatically'] = $checkoutConfig['redirectAutomatically'];
            }
        }

        return $result;
    }

    /**
     * Cache for wallet instances per store+mint
     */
    private static array $walletCache = [];

    /**
     * Webhook queue for deferred delivery after transaction commit
     */
    private static array $webhookQueue = [];

    /**
     * Get or create wallet instance for a store
     *
     * @param string $storeId Store ID
     * @param string|null $mintUrl Optional specific mint URL (for backup mints)
     * @return Wallet
     */
    public static function getWalletForStore(string $storeId, ?string $mintUrl = null): Wallet {
        $store = Config::getStore($storeId);
        if (!$store) {
            throw new Exception('Store not found');
        }

        $mintUrl = $mintUrl ?? $store['mint_url'];
        $mintUnit = $store['mint_unit'] ?? 'sat';
        $seedPhrase = $store['seed_phrase'];

        if (empty($mintUrl) || empty($seedPhrase)) {
            throw new Exception('Store wallet not configured');
        }

        $cacheKey = $storeId . '|' . $mintUrl . '|' . $mintUnit;

        if (!isset(self::$walletCache[$cacheKey])) {
            $wallet = new Wallet($mintUrl, $mintUnit, Database::getDbPath());
            $wallet->loadMint();
            $wallet->initFromMnemonic($seedPhrase);

            self::$walletCache[$cacheKey] = $wallet;
        }

        return self::$walletCache[$cacheKey];
    }

    /**
     * Get wallet instance for a store (public accessor)
     */
    public static function getWalletInstance(string $storeId): Wallet {
        return self::getWalletForStore($storeId);
    }

    // =========================================================================
    // WEBHOOK QUEUE
    // =========================================================================

    private static function queueWebhook(string $storeId, string $event, array $data): void {
        self::$webhookQueue[] = compact('storeId', 'event', 'data');
    }

    private static function flushWebhookQueue(): void {
        foreach (self::$webhookQueue as $item) {
            WebhookSender::fireEvent($item['storeId'], $item['event'], $item['data']);
        }
        self::$webhookQueue = [];
    }

    private static function clearWebhookQueue(): void {
        self::$webhookQueue = [];
    }

    // =========================================================================
    // SINGLE INVOICE POLLING
    // =========================================================================

    /**
     * Poll a single invoice's quote status
     */
    public static function pollSingleQuote(string $invoiceId): void {
        $invoice = self::getById($invoiceId);
        if (!$invoice) {
            return;
        }

        // Only process New or Processing invoices
        if (!in_array($invoice['status'], ['New', 'Processing'])) {
            return;
        }

        // Submarine-swap rail: advance the swap state machine inline so
        // settlement isn't dependent on the cron poller (Task 4c). The
        // single-row poll reuses SwapPoller's atomic last_polled_at gate, so
        // it's safe against the cron task and other concurrent checkout polls
        // hitting the same swap, and the gate's min-interval keeps us off the
        // provider API on every 2s checkout tick. Cron's expireStale() remains
        // the backstop (incl. HTLC cancellation) for customers who close the
        // page before invoice.settled. A swap invoice has no Cashu quote or
        // on-chain pay-to-address, so none of the rails below apply.
        if (($invoice['payment_rail'] ?? null) === 'swap') {
            try {
                SwapPoller::pollByInvoiceId($invoiceId);
            } catch (Throwable $e) {
                error_log("swap poll failed for {$invoiceId}: " . $e->getMessage());
            }
            return;
        }

        // LNURL direct-receive: poll the LUD-21 verify URL. For a normal
        // lnaddress invoice there's no on-chain address and nothing else to
        // check. A fee-redirect invoice, however, can offer BOTH lightning
        // (fee LNURL) and on-chain (fee xpub) rails on the same row, so after
        // the verify poll we fall through to the on-chain poll below when an
        // address is present and the invoice is still open.
        if (($invoice['payment_rail'] ?? null) === 'lnaddress') {
            self::pollSingleLnAddress($invoiceId);
            if (empty($invoice['onchain_address'])) {
                return;
            }
            $invoice = self::getById($invoiceId);
            if (!$invoice || !in_array($invoice['status'], ['New', 'Processing'])) {
                return;
            }
        }

        // Best-effort on-chain poll first — if the invoice has an on-chain
        // address, this can transition state independent of any Cashu quote.
        if (!empty($invoice['onchain_address'])) {
            try {
                OnchainPayments::pollInvoice($invoiceId);
                $invoice = self::getById($invoiceId);
                if (!$invoice || !in_array($invoice['status'], ['New', 'Processing'])) {
                    return;
                }
            } catch (Throwable $e) {
                error_log("on-chain poll failed for {$invoiceId}: " . $e->getMessage());
            }
        }

        // No Cashu quote means nothing more to do here.
        if (empty($invoice['quote_id'])) {
            // Check expiration on on-chain-only invoices.
            if ($invoice['status'] === 'New' && $invoice['expiration_time'] < time()) {
                self::updateStatus($invoice['id'], 'Expired');
            }
            return;
        }

        // Check expiration (only for New invoices) for the Cashu side.
        if ($invoice['status'] === 'New' && $invoice['expiration_time'] < time()) {
            self::updateStatus($invoice['id'], 'Expired');
            return;
        }

        try {
            $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
            $quoteStatus = $wallet->checkMintQuote($invoice['quote_id']);

            error_log("CashuPayServer: Quote {$invoice['quote_id']} state: {$quoteStatus->state}");

            if ($quoteStatus->isPaid() || $quoteStatus->isIssued()) {
                error_log("CashuPayServer: Quote is paid/issued, processing...");
                if ($quoteStatus->isIssued()) {
                    self::completeIssuedInvoice($invoice, $wallet);
                } elseif ($invoice['status'] === 'New') {
                    self::mintAndStoreTokens($invoice, $wallet);
                } elseif ($invoice['status'] === 'Processing') {
                    self::mintAndStoreTokens($invoice, $wallet);
                }
            }
        } catch (Exception $e) {
            error_log("CashuPayServer: Error polling single quote {$invoice['id']}: " . $e->getMessage());
        }
    }

    // =========================================================================
    // ISSUED QUOTE HANDLING
    // =========================================================================

    private static function completeIssuedInvoice(array $invoice, Wallet $wallet): void {
        if ($wallet->hasStorage()) {
            $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
            if (!empty($proofs)) {
                Database::beginTransaction();
                try {
                    // Status-guarded settle (see mintAndStoreTokens): only fire
                    // webhooks/notifications if this caller actually settled the
                    // row, so concurrent pollers don't double-fire.
                    $settled = Database::update(
                        'invoices',
                        ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                        'id = ? AND status != ?',
                        [$invoice['id'], 'Settled']
                    );
                    Database::commit();

                    if ($settled !== 1) {
                        return; // already settled by another poller
                    }

                    $updatedInvoice = self::getById($invoice['id']);
                    WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
                    NotificationSender::queueInvoicePaid($updatedInvoice);
                    self::maybeFireOverrideSettled($updatedInvoice);
                    return;
                } catch (Exception $e) {
                    Database::rollback();
                    throw $e;
                }
            }
        }

        error_log("CashuPayServer: ISSUED quote {$invoice['quote_id']} has no proofs in storage - invoice {$invoice['id']}");
    }

    // =========================================================================
    // ORPHANED INVOICE RECOVERY
    // =========================================================================

    /**
     * Recover orphaned invoices stuck in Processing state
     */
    public static function recoverOrphanedInvoices(): array {
        $recovered = [];

        $stuck = Database::fetchAll(
            "SELECT * FROM invoices WHERE status = 'Processing' AND created_at < ?",
            [time() - 60]
        );

        foreach ($stuck as $invoice) {
            try {
                $wallet = self::getWalletForStore($invoice['store_id'], $invoice['mint_url'] ?? null);
                if ($wallet->hasStorage() && $invoice['quote_id']) {
                    $proofs = $wallet->getStorage()->getProofsByQuoteId($invoice['quote_id']);
                    if (!empty($proofs)) {
                        // Status-guarded settle: a regular poller may settle this
                        // row between our SELECT and here. Only fire webhooks/
                        // notifications if we actually won the transition.
                        $settled = Database::update(
                            'invoices',
                            ['status' => 'Settled', 'paid_at' => time(), 'settled_rail' => 'mint'],
                            'id = ? AND status != ?',
                            [$invoice['id'], 'Settled']
                        );
                        if ($settled !== 1) {
                            continue; // already settled elsewhere
                        }
                        $recovered[] = $invoice['id'];

                        $updatedInvoice = self::getById($invoice['id']);
                        WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $updatedInvoice);
                        NotificationSender::queueInvoicePaid($updatedInvoice);
                        self::maybeFireOverrideSettled($updatedInvoice);

                        error_log("CashuPayServer: Recovered orphaned invoice {$invoice['id']}");
                    }
                }
            } catch (Exception $e) {
                error_log("CashuPayServer: Error recovering invoice {$invoice['id']}: " . $e->getMessage());
            }
        }

        return $recovered;
    }

    // =========================================================================
    // PER-STORE BALANCE OPERATIONS (OFFLINE-FIRST)
    // =========================================================================
    // These methods read directly from local storage without contacting the mint.
    // Ecash is offline-first - local storage is the source of truth for proofs.

    /**
     * Get total balance for a store (reads from local storage)
     *
     * This is the default offline-first method. Reads directly from SQLite
     * without contacting the mint. Use for balance display, threshold checks,
     * and any operation that doesn't require mint verification.
     */
    public static function getBalance(string $storeId): int {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return 0;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        return $storage->getBalance();
    }

    /**
     * Get unspent proofs for a store (reads from local storage)
     *
     * Returns Proof objects directly from SQLite storage.
     * No mint contact required - ecash proofs are stored locally.
     */
    public static function getUnspentProofs(string $storeId): array {
        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return [];
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        return $storage->getProofsAsObjects(ProofState::UNSPENT);
    }

    /**
     * Mark proofs as spent for a store (updates local storage)
     */
    public static function markProofsSpent(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::SPENT);
    }

    /**
     * Mark proofs as pending for a store (updates local storage)
     *
     * Marks proofs as PENDING in local storage. Used when proofs are sent
     * but not yet confirmed spent (e.g., token export, melt in progress).
     */
    public static function markProofsPending(string $storeId, array $secrets): void {
        if (empty($secrets)) {
            return;
        }

        $store = Config::getStore($storeId);
        if (!$store || empty($store['mint_url'])) {
            return;
        }

        $storage = new WalletStorage(
            Database::getDbPath(),
            $store['mint_url'],
            $store['mint_unit'] ?? 'sat'
        );
        $storage->updateProofsState($secrets, ProofState::PENDING);
    }

    /**
     * Store proofs as unspent for a store
     */
    public static function storeProofs(string $storeId, array $proofs): void {
        if (empty($proofs)) {
            return;
        }

        $wallet = self::getWalletForStore($storeId);
        $wallet->getStorage()->storeProofs($proofs);
    }

    /**
     * Check pending proofs at the mint and update their state
     */
    public static function checkPendingProofs(string $storeId): array {
        try {
            $wallet = self::getWalletForStore($storeId);

            $rows = [];
            if ($wallet->hasStorage()) {
                $rows = $wallet->getStorage()->getProofs(ProofState::PENDING);
            }

            if (empty($rows)) {
                return ['checked' => 0, 'spent' => 0, 'recovered' => 0];
            }

            // Build Y values for batch check
            $Ys = [];
            $proofMap = [];
            foreach ($rows as $row) {
                $secret = $row['secret'];
                $Y = \Cashu\Crypto::hashToCurve($secret);
                $YHex = bin2hex(\Cashu\Secp256k1::compressPoint($Y));
                $Ys[] = $YHex;
                $proofMap[$YHex] = $secret;
            }

            // Check with mint
            $store = Config::getStore($storeId);
            $client = new \Cashu\MintClient($store['mint_url']);
            $response = $client->post('checkstate', ['Ys' => $Ys]);

            // Separate into spent and unspent
            $spentSecrets = [];
            $unspentSecrets = [];
            foreach ($response['states'] ?? [] as $i => $state) {
                $mintState = $state['state'] ?? ProofState::UNSPENT;
                $YHex = $Ys[$i];
                if (!isset($proofMap[$YHex])) continue;

                if ($mintState === ProofState::SPENT) {
                    $spentSecrets[] = $proofMap[$YHex];
                } else {
                    $unspentSecrets[] = $proofMap[$YHex];
                }
            }

            // Update database
            if (!empty($spentSecrets)) {
                $wallet->getStorage()->updateProofsState($spentSecrets, ProofState::SPENT);
            }

            if (!empty($unspentSecrets)) {
                $wallet->getStorage()->updateProofsState($unspentSecrets, ProofState::UNSPENT);
            }

            return [
                'checked' => count($rows),
                'spent' => count($spentSecrets),
                'recovered' => count($unspentSecrets)
            ];
        } catch (\Exception $e) {
            error_log("CashuPayServer: Error checking pending proofs: " . $e->getMessage());
            return ['checked' => 0, 'spent' => 0, 'recovered' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if an exception indicates the mint is unreachable
     *
     * This includes connection errors, timeouts, and other network issues.
     * Used to determine when to fall back to offline token export.
     */
    public static function isMintUnreachable(\Exception $e): bool {
        $message = strtolower($e->getMessage());

        // cURL connection/network errors
        $networkErrors = [
            'http request failed',
            'could not resolve',
            'connection refused',
            'connection timed out',
            'operation timed out',
            'failed to connect',
            'network is unreachable',
            'no route to host',
            'ssl connect error',
            'couldn\'t connect to server',
            'recv failure',
            'send failure',
            'tls handshake',
        ];

        foreach ($networkErrors as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        // Check for specific HTTP errors that indicate server issues
        // 5xx errors, 0 (no response), certain 4xx that indicate server problems
        if ($e instanceof \Cashu\CashuException) {
            // CashuException with "HTTP request failed" means network error
            if (strpos($message, 'http request failed') !== false) {
                return true;
            }
        }

        return false;
    }
}
