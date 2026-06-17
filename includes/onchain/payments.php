<?php
/**
 * CashuPayServer - On-chain payment lifecycle.
 *
 * Address allocation, provider polling, state-machine transitions and
 * webhook firing for invoices configured with an on-chain payment method.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../webhook_sender.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/provider.php';

class OnchainPayments {
    /**
     * Raised by allocateAddress() when the store is in static-address mode
     * and every tweak slot in [0, tweak_range-1] is currently held by an
     * open invoice. Caller should surface a user-visible "try again later".
     */
    public const ERR_TWEAK_SLOTS_EXHAUSTED = 'onchain_static_tweak_slots_exhausted';

    /**
     * Atomically allocate the next receive address for a store.
     *
     * For xpub-mode stores: derives a fresh address at m/0/{next_index}.
     * For static-mode stores: returns the merchant's static address plus
     * a unique sat-tweak from the configured range so this invoice's
     * expected total is unambiguous among currently-open invoices.
     *
     * @param int|null $baseAmountSat Required for static mode (the un-tweaked
     *                                expected amount in sats). Ignored for xpub.
     * @return array{address:string, index:?int, tip_height:?int, tweak:?int}|null
     *         null if store has no on-chain method configured.
     * @throws RuntimeException with message ERR_TWEAK_SLOTS_EXHAUSTED when
     *         static mode has no free tweak slot.
     */
    public static function allocateAddress(string $storeId, ?int $baseAmountSat = null): ?array {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM stores WHERE id = ?"
            );
            $stmt->execute([$storeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->commit();
                return null;
            }

            $mode = $row['onchain_address_mode'] ?? 'xpub';
            $now = time();

            if ($mode === 'static') {
                $address = $row['onchain_static_address'] ?? null;
                if (!$address) {
                    $pdo->commit();
                    return null;
                }
                if ($baseAmountSat === null) {
                    $pdo->rollBack();
                    throw new RuntimeException('allocateAddress(): baseAmountSat is required for static mode');
                }
                $tweak = self::pickTweakSlot($pdo, $row, $address, (int)$baseAmountSat, $now);
                if ($tweak === null) {
                    $pdo->rollBack();
                    throw new RuntimeException(self::ERR_TWEAK_SLOTS_EXHAUSTED);
                }
                $tip = self::currentTipBestEffort($row);
                $pdo->commit();
                return ['address' => $address, 'index' => null, 'tip_height' => $tip, 'tweak' => $tweak];
            }

            // xpub mode (default)
            if (empty($row['onchain_xpub'])) {
                $pdo->commit();
                return null;
            }

            // Counter is keyed by xpub, not by store, so two stores sharing
            // the same xpub never derive the same address, and re-pasting a
            // previously used xpub resumes from where it left off.
            $xpubHash = hash('sha256', $row['onchain_xpub']);
            $pdo->prepare(
                "INSERT INTO onchain_xpub_state (xpub_hash, next_index, updated_at)
                 VALUES (?, 0, ?) ON CONFLICT(xpub_hash) DO NOTHING"
            )->execute([$xpubHash, $now]);
            $sel = $pdo->prepare(
                "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?"
            );
            $sel->execute([$xpubHash]);
            $index = (int)$sel->fetchColumn();
            $pdo->prepare(
                "UPDATE onchain_xpub_state SET next_index = next_index + 1, updated_at = ?
                  WHERE xpub_hash = ?"
            )->execute([$now, $xpubHash]);

            // Mirror the index back to the stores row too, for backwards-
            // compat reporting (admin dashboard surfaces it as nextIndex).
            $pdo->prepare(
                "UPDATE stores SET onchain_next_index = ? WHERE id = ?"
            )->execute([$index + 1, $storeId]);

            $address = OnchainWallet::deriveAddress(
                $row['onchain_xpub'],
                $row['onchain_address_type'] ?: 'P2WPKH',
                $row['onchain_network'] ?: 'mainnet',
                $index
            );

            $tip = self::currentTipBestEffort($row);

            $pdo->commit();
            return ['address' => $address, 'index' => $index, 'tip_height' => $tip, 'tweak' => null];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pick the lowest tweak in [0, tweak_range-1] such that
     * (baseAmountSat + tweak) is not the expected total of any currently-
     * open invoice on the same address. Uniqueness is on TOTAL amount,
     * not tweak value — two invoices with different base amounts (e.g.
     * created in fiat at slightly different rates) could otherwise pick
     * the same total. A slot is "in use" while the invoice is still
     * attributable: status New/Processing AND we are still within
     * (expiration_time + onchain_confirm_timeout_sec). Held slots release
     * naturally once that window passes so the pool can recycle.
     *
     * Runs inside the caller's transaction. Returns null when no tweak in
     * the range yields a unique total (pool exhausted).
     */
    private static function pickTweakSlot(PDO $pdo, array $store, string $address, int $baseAmountSat, int $now): ?int {
        $range = max(1, (int)($store['onchain_static_tweak_range'] ?? 1000));
        $confirmTimeout = (int)($store['onchain_confirm_timeout_sec'] ?? 86400);
        // SQLite quirk: bound parameters arrive with TEXT affinity, and
        // `INTEGER > TEXT` compares by storage class (integers always
        // sort *below* text), so the naive `(expiration_time + ?) > ?`
        // silently returns false. CAST the bound timestamp to INTEGER
        // to force a numeric comparison.
        $stmt = $pdo->prepare(
            "SELECT onchain_amount_sat FROM invoices
              WHERE onchain_address = ?
                AND onchain_amount_sat IS NOT NULL
                AND status IN ('New','Processing')
                AND (expiration_time + CAST(? AS INTEGER)) > CAST(? AS INTEGER)"
        );
        $stmt->execute([$address, $confirmTimeout, $now]);
        $usedTotals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $usedTotals[(int)$t] = true;
        }
        for ($i = 0; $i < $range; $i++) {
            if (!isset($usedTotals[$baseAmountSat + $i])) {
                return $i;
            }
        }
        return null;
    }

    private static function currentTipBestEffort(array $store): ?int {
        // Capture the chain tip at allocation time. The poller compares
        // each observation's block_height against this to discard payments
        // that existed on a re-used address BEFORE the invoice was created.
        // Provider call is best-effort: if it fails, we just leave the
        // tip NULL and skip filtering (old behavior).
        try {
            $provider = OnchainProviderFactory::forStore($store);
            return $provider->currentTipHeight();
        } catch (Throwable $_) {
            return null;
        }
    }

    /**
     * Atomically allocate the next address on the same xpub counter, intended
     * as the destination of a submarine-swap claim transaction. Shares the
     * counter with {@see allocateAddress} so addresses never collide between
     * pay-to-xpub and swap-claim allocations; uses the same `m/0/{i}` branch
     * so funds remain discoverable by the merchant's wallet via xpub scan.
     *
     * Unlike allocateAddress, this does NOT set anything on the invoices
     * table — the caller persists merchant_address on swap_attempts.
     *
     * @return array{address:string, index:int}|null  null if store has no xpub
     */
    public static function allocateClaimAddress(string $storeId): ?array {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ?");
            $stmt->execute([$storeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Static-address mode is incompatible with submarine swaps:
            // a swap claim landing in the static address would arrive with
            // an arbitrary amount and could collide with an open invoice's
            // tweaked total, leading to wrong attribution. Stores in static
            // mode fall back to non-swap rails for their invoices.
            if (!$row || ($row['onchain_address_mode'] ?? 'xpub') === 'static' || empty($row['onchain_xpub'])) {
                $pdo->commit();
                return null;
            }
            $xpubHash = hash('sha256', $row['onchain_xpub']);
            $now = time();
            $pdo->prepare(
                "INSERT INTO onchain_xpub_state (xpub_hash, next_index, updated_at)
                 VALUES (?, 0, ?) ON CONFLICT(xpub_hash) DO NOTHING"
            )->execute([$xpubHash, $now]);
            $sel = $pdo->prepare(
                "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?"
            );
            $sel->execute([$xpubHash]);
            $index = (int)$sel->fetchColumn();
            $pdo->prepare(
                "UPDATE onchain_xpub_state SET next_index = next_index + 1, updated_at = ?
                  WHERE xpub_hash = ?"
            )->execute([$now, $xpubHash]);
            $pdo->prepare(
                "UPDATE stores SET onchain_next_index = ? WHERE id = ?"
            )->execute([$index + 1, $storeId]);

            $address = OnchainWallet::deriveAddress(
                $row['onchain_xpub'],
                $row['onchain_address_type'] ?: 'P2WPKH',
                $row['onchain_network'] ?: 'mainnet',
                $index
            );
            $pdo->commit();
            return ['address' => $address, 'index' => $index];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Atomically derive the next receive address from an ARBITRARY xpub —
     * used for fee-redirect invoices, where the payment is pointed at a fee
     * payee's xpub (dev / upstream / hosting) rather than the merchant's.
     *
     * Shares the same per-xpub `onchain_xpub_state` counter as
     * {@see allocateAddress}, so a fee xpub gets its own monotonic index
     * stream and never re-derives a used address — even if the same xpub is
     * also a merchant's receive xpub on another store. Unlike allocateAddress
     * it does NOT touch any stores row (the fee xpub belongs to no store).
     *
     * @param array|null $tipProviderStore A store row whose provider config is
     *        used for a best-effort chain-tip read (the redirect invoice is
     *        polled with the paying store's provider, so tip filtering only
     *        works when the fee xpub is on that store's network — callers
     *        enforce a network match before getting here).
     * @return array{address:string, index:int, tip_height:?int}
     */
    public static function allocateFeeAddress(
        string $xpub,
        string $network,
        string $type,
        ?array $tipProviderStore = null
    ): array {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $xpubHash = hash('sha256', $xpub);
            $now = time();
            $pdo->prepare(
                "INSERT INTO onchain_xpub_state (xpub_hash, next_index, updated_at)
                 VALUES (?, 0, ?) ON CONFLICT(xpub_hash) DO NOTHING"
            )->execute([$xpubHash, $now]);
            $sel = $pdo->prepare(
                "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?"
            );
            $sel->execute([$xpubHash]);
            $index = (int)$sel->fetchColumn();
            $pdo->prepare(
                "UPDATE onchain_xpub_state SET next_index = next_index + 1, updated_at = ?
                  WHERE xpub_hash = ?"
            )->execute([$now, $xpubHash]);

            $address = OnchainWallet::deriveAddress($xpub, $type ?: 'P2WPKH', $network ?: 'mainnet', $index);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Tip read is outside the txn (best-effort network call); failure
        // leaves tip NULL which simply disables historical-UTXO filtering.
        $tip = $tipProviderStore !== null ? self::currentTipBestEffort($tipProviderStore) : null;
        return ['address' => $address, 'index' => $index, 'tip_height' => $tip];
    }

    /**
     * Poll the configured provider for an invoice's address and apply any
     * resulting lifecycle transitions.
     *
     * @return array{
     *   total_confirmed:int, total_pending:int, observation_count:int,
     *   status:string, transition:?string
     * }
     */
    public static function pollInvoice(string $invoiceId): array {
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) {
            throw new Exception("Invoice not found: {$invoiceId}");
        }
        $address = $invoice['onchain_address'] ?? null;
        if (!$address) {
            return ['total_confirmed' => 0, 'total_pending' => 0, 'observation_count' => 0,
                    'status' => $invoice['status'], 'transition' => null];
        }
        $store = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$invoice['store_id']]);
        if (!$store) {
            throw new Exception("Store not found: {$invoice['store_id']}");
        }

        $provider = OnchainProviderFactory::forStore($store);
        // Pass the invoice's allocation tip as a paging hint: any tx confirmed
        // before the invoice existed is filtered out below, so the provider can
        // stop walking chain history once it pages below that height.
        $createdTipHint = isset($invoice['onchain_created_tip_height'])
            ? (int)$invoice['onchain_created_tip_height']
            : null;
        $observations = $provider->addressTransactions($address, $createdTipHint);

        $minConfs = (int)($store['onchain_min_confs'] ?? 1);
        $now = time();
        $isStaticMode = ($store['onchain_address_mode'] ?? 'xpub') === 'static';

        // Filter out historical UTXOs on a re-used address — any tx confirmed
        // strictly before the invoice was created. onchain_created_tip_height
        // is the chain tip at allocation time; an observation with
        // block_height < that height was mined before the invoice existed.
        // Mempool observations (block_height === null) are always kept.
        // Legacy invoices without a recorded tip skip the filter.
        $createdTip = $invoice['onchain_created_tip_height'] ?? null;
        if ($createdTip !== null) {
            $observations = array_values(array_filter($observations, function ($obs) use ($createdTip) {
                return $obs->blockHeight === null || $obs->blockHeight >= (int)$createdTip;
            }));
        }

        if ($isStaticMode) {
            // Static mode: address is shared across invoices, so we can't
            // attribute every UTXO blindly. Only attribute a tx whose amount
            // matches the invoice total exactly AND no other open invoice on
            // the same address expects that same amount.
            $expected = (int)($invoice['onchain_amount_sat'] ?? 0);
            $confirmTimeout = (int)($store['onchain_confirm_timeout_sec'] ?? 86400);
            // Each poll re-evaluates ambiguity from scratch so the flag
            // can clear naturally once competing invoices expire.
            $candidates = [];
            foreach ($observations as $obs) {
                if ($obs->amountSat !== $expected) {
                    continue;
                }
                $existing = Database::fetchOne(
                    "SELECT invoice_id FROM onchain_payments WHERE txid = ? AND vout = ?",
                    [$obs->txid, $obs->vout]
                );
                if ($existing) {
                    if ($existing['invoice_id'] === $invoiceId) {
                        self::upsertObservation($invoiceId, $obs, $now);
                    }
                    continue;
                }
                $competitors = Database::fetchAll(
                    "SELECT id FROM invoices
                      WHERE onchain_address = ?
                        AND onchain_amount_sat = ?
                        AND status IN ('New','Processing')
                        AND id != ?
                        AND (expiration_time + CAST(? AS INTEGER)) > CAST(? AS INTEGER)",
                    [$address, $expected, $invoiceId, $confirmTimeout, $now]
                );
                if (empty($competitors)) {
                    self::upsertObservation($invoiceId, $obs, $now);
                } else {
                    $candidates[] = [
                        'txid' => $obs->txid,
                        'vout' => $obs->vout,
                        'amount_sat' => $obs->amountSat,
                        'confirmations' => $obs->confirmations,
                        'block_height' => $obs->blockHeight,
                        'first_seen_at' => $now,
                    ];
                    foreach ($competitors as $c) {
                        self::appendManualCandidate($c['id'], [
                            'txid' => $obs->txid,
                            'vout' => $obs->vout,
                            'amount_sat' => $obs->amountSat,
                            'confirmations' => $obs->confirmations,
                            'block_height' => $obs->blockHeight,
                            'first_seen_at' => $now,
                        ]);
                    }
                }
            }
            self::setManualCandidates($invoiceId, $candidates);
        } else {
            // xpub mode: each address belongs to exactly one invoice, so any
            // UTXO at it is unambiguously this invoice's. Sum them all.
            foreach ($observations as $obs) {
                self::upsertObservation($invoiceId, $obs, $now);
            }
        }

        // Recompute totals from the persisted state (canonical source).
        $rows = Database::fetchAll(
            "SELECT amount_sat, confirmations FROM onchain_payments WHERE invoice_id = ?",
            [$invoiceId]
        );
        $totalConfirmed = 0;
        $totalPending = 0;
        foreach ($rows as $r) {
            if ((int)$r['confirmations'] >= $minConfs) {
                $totalConfirmed += (int)$r['amount_sat'];
            } else {
                $totalPending += (int)$r['amount_sat'];
            }
        }

        $transition = self::applyTransitions(
            $invoice,
            $store,
            totalConfirmed: $totalConfirmed,
            totalPending: $totalPending,
            anyObservation: count($rows) > 0,
            now: $now
        );

        return [
            'total_confirmed' => $totalConfirmed,
            'total_pending' => $totalPending,
            'observation_count' => count($rows),
            'status' => $transition['status'] ?? $invoice['status'],
            'transition' => $transition['fired'] ?? null,
        ];
    }

    /**
     * Build the BTC-OnChain payment-method block for the Greenfield API.
     */
    public static function formatPaymentMethod(array $invoice): ?array {
        $address = $invoice['onchain_address'] ?? null;
        if (!$address) {
            return null;
        }
        $amountSat = (int)($invoice['onchain_amount_sat'] ?? 0);
        $btc = bcdiv((string)$amountSat, '100000000', 8);
        // Strip trailing zeros from the BTC amount for the BIP21 URI.
        $btcDisplay = rtrim(rtrim($btc, '0'), '.');
        $btcDisplay = $btcDisplay === '' ? '0' : $btcDisplay;
        return [
            'destination' => $address,
            'paymentLink' => "bitcoin:{$address}?amount={$btcDisplay}",
            'amount' => (string)$amountSat,
            'due' => (string)$amountSat,
            'rate' => $invoice['exchange_rate'] ?? null,
        ];
    }

    /**
     * Run pollInvoice() for every New/Processing invoice that has an
     * on-chain address. Used by the cron task.
     *
     * @param int $minIntervalSec Minimum seconds between consecutive polls of the same invoice
     * @param int $batchLimit Maximum invoices to poll per call
     */
    public static function pollPending(int $minIntervalSec = 60, int $batchLimit = 20): array {
        $now = time();
        $rows = Database::fetchAll(
            "SELECT id FROM invoices
              WHERE onchain_address IS NOT NULL
                AND status IN ('New', 'Processing')
                AND (last_polled_at IS NULL OR (? - last_polled_at) >= ?)
              ORDER BY last_polled_at ASC NULLS FIRST
              LIMIT ?",
            [$now, $minIntervalSec, $batchLimit]
        );
        $results = [];
        foreach ($rows as $r) {
            try {
                Database::query(
                    "UPDATE invoices SET last_polled_at = ? WHERE id = ?",
                    [$now, $r['id']]
                );
                $results[$r['id']] = self::pollInvoice($r['id']);
            } catch (Throwable $e) {
                error_log("on-chain poll failed for {$r['id']}: " . $e->getMessage());
                $results[$r['id']] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Resolve an ambiguous static-address payment by attributing a specific
     * (txid, vout) to the chosen invoice. Inserts the observation into
     * onchain_payments (which triggers the normal settlement path on the
     * next poll), clears manual_confirmation flags on the chosen invoice,
     * and scrubs the same (txid, vout) from every other invoice's
     * candidate list (it is now claimed and can't be re-attributed).
     *
     * @throws RuntimeException if the candidate isn't listed on the invoice
     *         or if the (txid, vout) is already attributed to a different
     *         invoice.
     */
    public static function manuallyAttribute(string $invoiceId, string $txid, int $vout): void {
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) {
            throw new RuntimeException("Invoice not found: {$invoiceId}");
        }
        $existing = Database::fetchOne(
            "SELECT invoice_id FROM onchain_payments WHERE txid = ? AND vout = ?",
            [$txid, $vout]
        );
        if ($existing && $existing['invoice_id'] !== $invoiceId) {
            throw new RuntimeException("Transaction already attributed to another invoice");
        }
        $candidates = self::decodeCandidates($invoice['onchain_manual_candidates'] ?? null);
        $match = null;
        foreach ($candidates as $c) {
            if (($c['txid'] ?? '') === $txid && (int)($c['vout'] ?? -1) === $vout) {
                $match = $c;
                break;
            }
        }
        if ($match === null) {
            throw new RuntimeException("Candidate {$txid}:{$vout} not listed on this invoice");
        }

        $now = time();
        if (!$existing) {
            Database::insert('onchain_payments', [
                'id' => Database::generateId('och'),
                'invoice_id' => $invoiceId,
                'txid' => $txid,
                'vout' => $vout,
                'amount_sat' => (int)$match['amount_sat'],
                'confirmations' => (int)($match['confirmations'] ?? 0),
                'block_height' => $match['block_height'] ?? null,
                'first_seen_at' => (int)($match['first_seen_at'] ?? $now),
                'last_seen_at' => $now,
            ]);
        }

        // Clear the candidate from every invoice that listed it (this one
        // resolves; the others lose this candidate but stay open).
        self::scrubCandidateGlobally($txid, $vout);

        // Run the state machine from persisted onchain_payments rows so the
        // newly-attributed payment immediately drives the invoice to its
        // next state. We deliberately do NOT re-contact the provider here:
        // (a) the operator is acting on already-confirmed information, so
        // round-tripping to Esplora/bitcoind is unnecessary, and (b) the
        // attribution must still succeed if the provider is currently
        // unavailable. The next scheduled poll will refresh confirmation
        // counts via the provider in the normal flow.
        self::driveStateFromPersisted($invoiceId);
    }

    /**
     * Recompute totals from onchain_payments and run the state machine
     * without contacting the provider. Used by manuallyAttribute() and
     * any other path that has already mutated onchain_payments.
     */
    private static function driveStateFromPersisted(string $invoiceId): void {
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) return;
        $store = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$invoice['store_id']]);
        if (!$store) return;
        $minConfs = (int)($store['onchain_min_confs'] ?? 1);
        $rows = Database::fetchAll(
            "SELECT amount_sat, confirmations FROM onchain_payments WHERE invoice_id = ?",
            [$invoiceId]
        );
        $totalConfirmed = 0;
        $totalPending = 0;
        foreach ($rows as $r) {
            if ((int)$r['confirmations'] >= $minConfs) {
                $totalConfirmed += (int)$r['amount_sat'];
            } else {
                $totalPending += (int)$r['amount_sat'];
            }
        }
        self::applyTransitions(
            $invoice,
            $store,
            totalConfirmed: $totalConfirmed,
            totalPending: $totalPending,
            anyObservation: count($rows) > 0,
            now: time()
        );
    }

    /**
     * Count invoices currently flagged as needing manual confirmation.
     * Scoped to one store when storeId is provided; otherwise global.
     */
    public static function countNeedingManualConfirmation(?string $storeId = null): int {
        if ($storeId === null) {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM invoices WHERE onchain_needs_manual_confirmation = 1"
            );
        } else {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM invoices
                  WHERE onchain_needs_manual_confirmation = 1 AND store_id = ?",
                [$storeId]
            );
        }
        return (int)($row['n'] ?? 0);
    }

    /**
     * List invoices needing manual confirmation, newest first. Returns the
     * full invoice row with onchain_manual_candidates decoded into a PHP array.
     *
     * @param string[]|null $storeIds Filter to these stores (null = all)
     * @return array<int, array>
     */
    public static function listNeedingManualConfirmation(?array $storeIds = null): array {
        if ($storeIds === null) {
            $rows = Database::fetchAll(
                "SELECT * FROM invoices WHERE onchain_needs_manual_confirmation = 1
                  ORDER BY created_at DESC"
            );
        } else {
            if (empty($storeIds)) return [];
            $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
            $rows = Database::fetchAll(
                "SELECT * FROM invoices WHERE onchain_needs_manual_confirmation = 1
                   AND store_id IN ({$placeholders})
                  ORDER BY created_at DESC",
                $storeIds
            );
        }
        foreach ($rows as &$r) {
            $r['onchain_manual_candidates_decoded'] = self::decodeCandidates($r['onchain_manual_candidates'] ?? null);
        }
        return $rows;
    }

    // ---------- internals ----------

    private static function decodeCandidates(?string $json): array {
        if ($json === null || $json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replace the candidate list on an invoice. Empty list clears the
     * manual-confirmation flag too.
     */
    private static function setManualCandidates(string $invoiceId, array $candidates): void {
        if (empty($candidates)) {
            Database::query(
                "UPDATE invoices SET onchain_needs_manual_confirmation = 0,
                                      onchain_manual_candidates = NULL
                  WHERE id = ?",
                [$invoiceId]
            );
            return;
        }
        Database::query(
            "UPDATE invoices SET onchain_needs_manual_confirmation = 1,
                                  onchain_manual_candidates = ?
              WHERE id = ?",
            [json_encode(array_values($candidates), JSON_UNESCAPED_SLASHES), $invoiceId]
        );
    }

    /**
     * Add a candidate to an invoice's list if not already present.
     */
    private static function appendManualCandidate(string $invoiceId, array $candidate): void {
        $row = Database::fetchOne(
            "SELECT onchain_manual_candidates FROM invoices WHERE id = ?",
            [$invoiceId]
        );
        if (!$row) return;
        $list = self::decodeCandidates($row['onchain_manual_candidates'] ?? null);
        foreach ($list as $existing) {
            if (($existing['txid'] ?? '') === $candidate['txid']
                && (int)($existing['vout'] ?? -1) === (int)$candidate['vout']) {
                return; // already present
            }
        }
        $list[] = $candidate;
        self::setManualCandidates($invoiceId, $list);
    }

    /**
     * Remove a candidate (matched by txid+vout) from every invoice that
     * holds it. Called after manual attribution so the same tx isn't
     * offered as a candidate elsewhere.
     */
    private static function scrubCandidateGlobally(string $txid, int $vout): void {
        $rows = Database::fetchAll(
            "SELECT id, onchain_manual_candidates FROM invoices
              WHERE onchain_needs_manual_confirmation = 1"
        );
        foreach ($rows as $r) {
            $list = self::decodeCandidates($r['onchain_manual_candidates'] ?? null);
            $filtered = array_values(array_filter($list, function ($c) use ($txid, $vout) {
                return !(($c['txid'] ?? '') === $txid && (int)($c['vout'] ?? -1) === $vout);
            }));
            if (count($filtered) !== count($list)) {
                self::setManualCandidates($r['id'], $filtered);
            }
        }
    }

    private static function upsertObservation(string $invoiceId, OnchainTxObservation $obs, int $now): void {
        $existing = Database::fetchOne(
            "SELECT id, first_seen_at FROM onchain_payments WHERE txid = ? AND vout = ?",
            [$obs->txid, $obs->vout]
        );
        if ($existing) {
            Database::query(
                "UPDATE onchain_payments
                    SET confirmations = ?, block_height = ?, last_seen_at = ?
                  WHERE id = ?",
                [$obs->confirmations, $obs->blockHeight, $now, $existing['id']]
            );
            return;
        }
        Database::insert('onchain_payments', [
            'id' => Database::generateId('och'),
            'invoice_id' => $invoiceId,
            'txid' => $obs->txid,
            'vout' => $obs->vout,
            'amount_sat' => $obs->amountSat,
            'confirmations' => $obs->confirmations,
            'block_height' => $obs->blockHeight,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Walk the on-chain state machine and fire webhooks for any transition.
     *
     * @return array{status:?string, fired:?string}
     */
    private static function applyTransitions(
        array $invoice,
        array $store,
        int $totalConfirmed,
        int $totalPending,
        bool $anyObservation,
        int $now
    ): array {
        $amount = (int)($invoice['onchain_amount_sat'] ?? 0);
        $status = $invoice['status'];
        $fired = null;

        // First mempool sighting flips New -> Processing and starts TTCW.
        if ($status === 'New' && $anyObservation) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Processing');
            Database::query(
                "UPDATE invoices SET onchain_first_seen_at = COALESCE(onchain_first_seen_at, ?) WHERE id = ?",
                [$now, $invoice['id']]
            );
            $status = 'Processing';
            $fired = 'InvoiceReceivedPayment';
            $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoice['id']]);
        }

        // Settled when total confirmed reaches the invoice amount.
        if ($status !== 'Settled' && $totalConfirmed >= $amount && $amount > 0) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Settled', null, 'onchain');
            return ['status' => 'Settled', 'fired' => $fired ?: 'InvoiceSettled'];
        }

        // Confirmation window expired without crossing the threshold.
        $firstSeen = (int)($invoice['onchain_first_seen_at'] ?? 0);
        $timeout = (int)($store['onchain_confirm_timeout_sec'] ?? 86400);
        if ($status === 'Processing' && $firstSeen > 0 && ($now - $firstSeen) >= $timeout && $totalConfirmed < $amount) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Invalid');
            return ['status' => 'Invalid', 'fired' => 'InvoiceInvalid'];
        }

        return ['status' => $status, 'fired' => $fired];
    }
}
