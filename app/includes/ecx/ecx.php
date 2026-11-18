<?php
/**
 * CashuPayServer - eCash (ECX) rail: per-store configuration, invoice-time
 * allocation and the ECX fee ledger.
 *
 * ECX (ecash.com) is the 2026 Drivechain hard fork of Bitcoin. Its addresses
 * and xpubs are byte-identical to Bitcoin's, so this rail is the on-chain
 * engine (includes/onchain/payments.php) run with Chain::ecx(): same
 * derivation, same Esplora watcher, same state machine; its own invoice /
 * store columns, its own observations table, its own explorer defaults, and
 * amounts in szats (1 ECX = 100,000,000 szats) priced through EcxRates.
 *
 * Fees in ECX. A payment taken in ECX accrues the same dev / hosting fee
 * percentages as any other, but the fee is owed IN ECX: it can only be
 * collected by pointing an invoice's ECX address at the fee payee (the same
 * redirect mechanism the Bitcoin rails use — gate: owed >= the invoice's szat
 * amount, largest owed first), because there is no Lightning / mint path for
 * ECX the cron could melt it over. ECX revenue and ECX fee credits therefore
 * live in their own ledger (ecx_fee_credits) and never mix with the sats
 * ledger (melts / DevFee::computeOwed), which excludes ECX-settled invoices.
 * Only the ECX address of an invoice is ever redirected; the Lightning and
 * Bitcoin rails on the same invoice keep their own routing.
 *
 * Dev-fee destination: the same BareBits zpub as the Bitcoin dev fee, under
 * the install's per-install branch (includes/fee_branch.php) — so an ECX
 * fee address derives exactly like a Bitcoin one, and since the per-xpub
 * counter is shared, no address is ever shown for both chains. Hosting-fee
 * destination: the store's hosting_fee_ecx_xpub (set by the deployer).
 */

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dev_fee.php';
require_once __DIR__ . '/../fee_branch.php';
require_once __DIR__ . '/../free_trial.php';
require_once __DIR__ . '/../onchain/chain.php';
require_once __DIR__ . '/../onchain/wallet.php';
require_once __DIR__ . '/../onchain/payments.php';
require_once __DIR__ . '/rates.php';

final class EcxConfig {
    /** Is an ECX address source (xpub or static address) saved on this store? */
    public static function isConfigured(array $store): bool {
        $mode = (string)($store['ecx_address_mode'] ?? 'xpub');
        if ($mode === 'static') {
            return trim((string)($store['ecx_static_address'] ?? '')) !== '';
        }
        return trim((string)($store['ecx_xpub'] ?? '')) !== '';
    }

    /** Configured AND the operator left "offer eCash to customers" on. */
    public static function isOffered(array $store): bool {
        return self::isConfigured($store) && (int)($store['ecx_offer_enabled'] ?? 1) === 1;
    }

    /**
     * Store-row shape the on-chain engine reads for ECX. ECX has a single
     * network; the engine asks the row for the chain's network column, which
     * the stores table does not carry, so it is pinned here.
     */
    public static function engineStore(array $store): array {
        $store['ecx_network'] = Chain::ECX_NETWORK;
        return $store;
    }
}

final class EcxRail {
    /**
     * Price + allocate the ECX leg of a new invoice. Returns the column values
     * to persist on the invoice, or null when eCash is not offered on this
     * invoice: not configured / switched off, no ECX price (operator rule:
     * never show eCash without a price), or the allocation failed (logged;
     * the invoice still goes out with its other rails).
     *
     * @return array{
     *   ecx_address:string, ecx_address_index:?int, ecx_amount_szat:int,
     *   ecx_amount_tweak_szats:?int, ecx_created_tip_height:?int,
     *   ecx_exchange_rate:float, ecx_fee_note:?string, ecx_fee_destination:?string
     * }|null
     */
    public static function allocateForInvoice(string $storeId, array $store, string $amount, string $currency, string $invoiceId): ?array {
        require_once __DIR__ . '/../invoice_attempts.php';
        if (!EcxConfig::isOffered($store)) {
            return null;
        }
        $ch = Chain::ecx();
        $store = EcxConfig::engineStore($store);

        $quote = EcxRates::convertToSzats(
            $amount, $currency,
            $store['price_provider_primary'] ?? null,
            $store['price_provider_secondary'] ?? null
        );
        if ($quote === null || $quote['szats'] <= 0) {
            InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_CHECKOUT, $ch->attemptRail,
                'eCash price', InvoiceAttempts::SKIPPED, 'no eCash (ECX) price was available',
                'ECX/' . strtoupper($currency) . ' rate unavailable from CoinMarketCap and cache; eCash not offered on this invoice');
            return null;
        }
        $szats = (int)$quote['szats'];

        // Fee redirect (ECX ledger): a dev / hosting fee owed in ECX that
        // covers this whole invoice takes the ECX address.
        $fee = null;
        try {
            $fee = EcxFees::decide($storeId, $store, $szats);
        } catch (\Throwable $e) {
            error_log('[ecx] fee decision failed for ' . $invoiceId . ': ' . $e->getMessage());
        }
        if ($fee !== null) {
            InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_CHECKOUT, 'fee',
                $fee['destination'], InvoiceAttempts::OK, null,
                'eCash address routed to the fee payee (' . $fee['note'] . '); merchant ECX address not allocated');
            return [
                'ecx_address' => $fee['address'],
                'ecx_address_index' => $fee['index'],
                'ecx_amount_szat' => $szats,
                'ecx_amount_tweak_szats' => null,
                'ecx_created_tip_height' => $fee['tip_height'],
                'ecx_exchange_rate' => (float)$quote['rate'],
                'ecx_fee_note' => $fee['note'],
                'ecx_fee_destination' => $fee['destination'],
            ];
        }

        try {
            $alloc = OnchainPayments::allocateAddress($storeId, $szats, $ch);
        } catch (\Throwable $e) {
            $reason = ($e instanceof \RuntimeException && $e->getMessage() === OnchainPayments::ERR_TWEAK_SLOTS_EXHAUSTED)
                ? 'all eCash payment slots are temporarily reserved'
                : 'an eCash address could not be allocated';
            error_log('[ecx] address allocation failed store=' . $storeId . ': ' . $e->getMessage());
            InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_CHECKOUT, $ch->attemptRail,
                'local address', InvoiceAttempts::FAILED, $reason, $e->getMessage());
            return null;
        }
        if ($alloc === null) {
            return null;
        }
        $tweak = $alloc['tweak'] ?? null;
        InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_CHECKOUT, $ch->attemptRail,
            'local address', InvoiceAttempts::OK, null,
            'eCash address ' . $alloc['address']
            . ($tweak !== null ? ' (static address, amount tweak ' . (int)$tweak . ' szat)' : ' (xpub-derived)'));
        return [
            'ecx_address' => $alloc['address'],
            'ecx_address_index' => $alloc['index'],
            'ecx_amount_szat' => $szats + ($tweak !== null ? (int)$tweak : 0),
            'ecx_amount_tweak_szats' => $tweak,
            'ecx_created_tip_height' => $alloc['tip_height'] ?? null,
            'ecx_exchange_rate' => (float)$quote['rate'],
            'ecx_fee_note' => null,
            'ecx_fee_destination' => null,
        ];
    }

    /** Is a detected ECX payment still waiting for confirmations on this invoice? */
    public static function isPending(array $invoice): bool {
        return ($invoice['status'] ?? '') === 'Processing' && !empty($invoice['ecx_first_seen_at']);
    }

    /**
     * Public explorer URL of the first ECX transaction seen paying this
     * invoice, or null. Mirrors payment.php's Bitcoin helper.
     */
    public static function txUrl(array $invoice): ?string {
        if (($invoice['status'] ?? '') !== 'Settled' && !self::isPending($invoice)) {
            return null;
        }
        if (empty($invoice['ecx_address'])) {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT txid FROM ecx_payments WHERE invoice_id = ? ORDER BY first_seen_at ASC, id ASC LIMIT 1",
            [$invoice['id']]
        );
        if (!$row || empty($row['txid'])) {
            return null;
        }
        return Chain::ecx()->txUrl((string)$row['txid']);
    }
}

final class EcxFees {
    public const TABLE = 'ecx_fee_credits';

    /**
     * Pure aggregation of the ECX ledger for one store. All values in szats.
     *
     *   revenue_szats  ecx_amount_szat of Settled invoices whose paying rail
     *                  was ECX, created after the fee-tracking start
     *   dev_owed       floor(revenue x CASHUPAY_DEV_FEE_PERCENT) - dev credits
     *   hosting_owed   floor(revenue x hosting_fee_percent) - hosting credits
     *
     * No network-cost decrement: nothing is ever melted for ECX. The free
     * trial zeroes both owed figures exactly as it does for sats.
     */
    public static function computeOwed(string $storeId): array {
        FreeTrial::expireIfNeeded();
        $start = (int)Config::get('fee_tracking_start_at', 0);
        $revenue = (int)Database::fetchOne(
            "SELECT COALESCE(SUM(ecx_amount_szat), 0) AS s FROM invoices
              WHERE store_id = ? AND status = 'Settled' AND settled_rail = 'ecx'
                AND ecx_amount_szat IS NOT NULL AND created_at >= ?",
            [$storeId, $start]
        )['s'];
        $devPaid = self::sumPaid($storeId, FEE_NOTE_DEV);
        $hostingPaid = self::sumPaid($storeId, FEE_NOTE_HOSTING);
        $store = Config::getStore($storeId);
        $hostingPct = (float)($store['hosting_fee_percent'] ?? 0);

        $devOwed = max(0, (int)floor($revenue * CASHUPAY_DEV_FEE_PERCENT / 100) - $devPaid);
        $hostingOwed = max(0, (int)floor($revenue * $hostingPct / 100) - $hostingPaid);
        $trialActive = FreeTrial::isActive();
        if ($trialActive) {
            $devOwed = 0;
            $hostingOwed = 0;
        }
        return [
            'revenue_szats' => $revenue,
            'dev_paid' => $devPaid,
            'hosting_paid' => $hostingPaid,
            'dev_owed' => $devOwed,
            'hosting_owed' => $hostingOwed,
            'trial_active' => $trialActive,
        ];
    }

    private static function sumPaid(string $storeId, string $note): int {
        $row = Database::fetchOne(
            "SELECT COALESCE(SUM(amount_szat), 0) AS s FROM " . self::TABLE . " WHERE store_id = ? AND note = ?",
            [$storeId, $note]
        );
        return (int)($row['s'] ?? 0);
    }

    /** Credits grouped by fee note, optionally since $startTs (stats). */
    public static function paidByNote(string $storeId, ?int $startTs): array {
        $out = ['dev' => 0, 'hosting' => 0];
        foreach (['dev' => FEE_NOTE_DEV, 'hosting' => FEE_NOTE_HOSTING] as $key => $note) {
            $sql = "SELECT COALESCE(SUM(amount_szat), 0) AS s FROM " . self::TABLE . " WHERE note = ?";
            $params = [$note];
            if ($storeId !== '' && $storeId !== '__all__') {
                $sql .= ' AND store_id = ?';
                $params[] = $storeId;
            }
            if ($startTs !== null) {
                $sql .= ' AND created_at >= ?';
                $params[] = $startTs;
            }
            $out[$key] = (int)Database::fetchOne($sql, $params)['s'];
        }
        return $out;
    }

    /**
     * Ordered redirect candidates: dev (global BareBits zpub + per-install
     * branch) and hosting (the store's ECX hosting xpub), largest owed first.
     * Pure; exposed for unit tests.
     *
     * @return array<int, array{key:string, note:string, owed:int, xpub:string, type:string, network:string, branch:?int}>
     */
    public static function candidates(array $store, array $owed): array {
        $list = [
            [
                'key' => 'dev',
                'note' => FEE_NOTE_DEV,
                'owed' => (int)($owed['dev_owed'] ?? 0),
                'xpub' => (string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB,
                'type' => (string)CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE,
                // The baked-in zpub is a mainnet key; a test rig that points
                // the dev xpub at a testnet key has no ECX dev destination.
                'network' => (string)CASHUPAY_DEV_FEE_ONCHAIN_NETWORK,
                'branch' => FeeBranch::current(),
            ],
            [
                'key' => 'hosting',
                'note' => FEE_NOTE_HOSTING,
                'owed' => (int)($owed['hosting_owed'] ?? 0),
                'xpub' => (string)($store['hosting_fee_ecx_xpub'] ?? ''),
                'type' => (string)($store['hosting_fee_ecx_address_type'] ?? 'P2WPKH'),
                'network' => Chain::ECX_NETWORK,
                'branch' => null,
            ],
        ];
        usort($list, static fn($a, $b) => $b['owed'] <=> $a['owed']);
        return $list;
    }

    /** Pure: can this candidate take the ECX address by configuration? */
    public static function covers(array $cand): bool {
        return ($cand['xpub'] ?? '') !== '' && ($cand['network'] ?? '') === Chain::ECX_NETWORK;
    }

    /**
     * Should this invoice's ECX address point at a fee payee? Null leaves it
     * with the merchant. Same rules as FeeRedirect::decide, on the ECX ledger:
     * GATE owed >= invoice szats; PRECEDENCE largest owed first; the dev fee
     * needs the install's branch (never the bare shared chain).
     *
     * @return array{note:string, fee_key:string, address:string, index:int, branch:?int, tip_height:?int, destination:string}|null
     */
    public static function decide(string $storeId, array $store, int $invoiceSzats): ?array {
        if ($invoiceSzats < 1) {
            return null;
        }
        $owed = self::computeOwed($storeId);
        foreach (self::candidates($store, $owed) as $cand) {
            if ($cand['owed'] < $invoiceSzats || !self::covers($cand)) {
                continue;
            }
            try {
                $branch = $cand['branch'];
                if ($cand['key'] === 'dev') {
                    if ($branch === null) {
                        $branch = FeeBranch::ensure(['throttle' => true]);
                    }
                    if ($branch === null) {
                        throw new \RuntimeException('no dev-fee branch allocated yet (see cron task fee_branch)');
                    }
                }
                $alloc = OnchainPayments::allocateFeeAddress(
                    $cand['xpub'], Chain::ECX_NETWORK, $cand['type'], EcxConfig::engineStore($store), $branch, Chain::ecx()
                );
            } catch (\Throwable $e) {
                error_log('[ecx-fee] address derivation failed for ' . $cand['key'] . ': ' . $e->getMessage()
                    . '; eCash address stays with the merchant');
                continue;
            }
            error_log(sprintf(
                '[ecx-fee] store=%s fee=%s owed=%d invoice_szats=%d address=%s',
                $storeId, $cand['key'], $cand['owed'], $invoiceSzats, $alloc['address']
            ));
            return [
                'note' => $cand['note'],
                'fee_key' => $cand['key'],
                'address' => $alloc['address'],
                'index' => $alloc['index'],
                'branch' => $alloc['branch'] ?? null,
                'tip_height' => $alloc['tip_height'] ?? null,
                'destination' => $alloc['address'],
            ];
        }
        return null;
    }

    /**
     * The invoice settled over its ECX address and that address belonged to
     * a fee payee: credit the fee. Idempotent (invoice_id UNIQUE).
     */
    public static function recordCredit(array $invoice): void {
        if (($invoice['settled_rail'] ?? '') !== 'ecx' || empty($invoice['ecx_fee_note'])) {
            return;
        }
        $amount = max(0, (int)($invoice['ecx_amount_szat'] ?? 0));
        try {
            Database::insert(self::TABLE, [
                'store_id' => (string)$invoice['store_id'],
                'invoice_id' => (string)$invoice['id'],
                'note' => (string)$invoice['ecx_fee_note'],
                'amount_szat' => $amount,
                'destination' => (string)($invoice['ecx_fee_destination'] ?? ''),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            error_log('[ecx-fee] credit insert skipped for ' . $invoice['id'] . ': ' . $e->getMessage());
            return;
        }
        error_log(sprintf('[ecx-fee] credited %d szats to %s (invoice=%s)',
            $amount, (string)$invoice['ecx_fee_note'], (string)$invoice['id']));
    }

    /** Did the paying rail of this settled invoice go to an ECX fee payee? */
    public static function settledToFee(array $invoice): bool {
        return ($invoice['settled_rail'] ?? '') === 'ecx' && !empty($invoice['ecx_fee_note']);
    }
}
