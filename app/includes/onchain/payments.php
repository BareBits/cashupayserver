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
require_once __DIR__ . '/chain.php';

class OnchainPayments {
    /**
     * Raised by allocateAddress() when the store is in static-address mode
     * and every tweak slot in [0, tweak_range-1] is currently held by an
     * open invoice. Caller should surface a user-visible "try again later".
     */
    public const ERR_TWEAK_SLOTS_EXHAUSTED = 'onchain_static_tweak_slots_exhausted';

    /** Cron poll spacing for an unpaid long-lived invoice 1–7 days old. */
    public const LONG_LIVED_POLL_SEC_WEEK = 600;
    /** Cron poll spacing for an unpaid long-lived invoice older than a week. */
    public const LONG_LIVED_POLL_SEC_OLD = 3600;

    /**
     * A confirmed payment buried at least this many blocks is treated as
     * final: the reorg reconciliation in pollInvoice() will NOT drop it even
     * if the provider transiently stops reporting it. Shallower observations
     * that vanish from the provider's view are assumed reorged-out / evicted
     * and are removed so their (now phantom) value stops counting toward the
     * invoice total. We deliberately do not attempt to catch reorgs deeper
     * than this; operators who need settlement to survive deeper reorgs should
     * raise onchain_min_confs.
     */
    public const REORG_SAFETY_DEPTH = 5;

    /**
     * How long a zero-conf settlement stays under watch. An invoice settled
     * on a mempool-only payment keeps being polled until that payment has a
     * confirmation or this many seconds have passed; a payment that
     * vanishes meanwhile (replaced / double-spent) flips the invoice to
     * Invalid and fires InvoiceInvalid. Long enough to outlast any realistic
     * mempool eviction, short enough that the watch set stays small.
     */
    public const ZERO_CONF_WATCH_SEC = 72 * 3600;

    /**
     * additional_status stamped on a Settled zero-conf invoice whose payment
     * was missing from the provider's view on the last watch poll. A second
     * consecutive miss reverses the settlement (Invalid); the payment
     * reappearing clears it. One poll alone never reverses: a provider
     * answering an empty page during a hiccup must not cancel a paid order.
     */
    public const ZERO_CONF_MISSING_STATUS = 'ZeroConfPaymentMissing';

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
    public static function allocateAddress(string $storeId, ?int $baseAmountSat = null, ?Chain $chain = null): ?array {
        $ch = $chain ?? Chain::btc();
        $pdo = Database::getInstance();
        Database::beginImmediate(); // write lock up front: read-before-write allocator (see Database::beginImmediate)
        // The allocation itself (slot/index reservation) happens under the write
        // lock; the chain-tip read is deferred until AFTER commit so we never
        // hold the SQLite write lock across a provider network round-trip — that
        // would block every other writer for the whole HTTP timeout and 500 them
        // on a slow provider. Mirrors allocateFeeAddress. $result stays null for
        // the "no on-chain method configured" early returns.
        $result = null;
        $storeRow = null;
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
            $storeRow = $row;

            $mode = $row[$ch->store['mode']] ?? 'xpub';
            $now = time();

            if ($mode === 'static') {
                $address = $row[$ch->store['static']] ?? null;
                if (!$address) {
                    $pdo->commit();
                    return null;
                }
                if ($baseAmountSat === null) {
                    $pdo->rollBack();
                    throw new RuntimeException('allocateAddress(): baseAmountSat is required for static mode');
                }
                $tweak = self::pickTweakSlot($pdo, $row, $address, (int)$baseAmountSat, $now, $ch);
                if ($tweak === null) {
                    $pdo->rollBack();
                    throw new RuntimeException(self::ERR_TWEAK_SLOTS_EXHAUSTED);
                }
                $result = ['address' => $address, 'index' => null, 'tweak' => $tweak];
                $pdo->commit();
            } elseif (empty($row[$ch->store['xpub']])) {
                // xpub mode (default) but no xpub configured.
                $pdo->commit();
                return null;
            } else {
                // Counter is keyed by xpub, not by store, so two stores sharing
                // the same xpub never derive the same address, and re-pasting a
                // previously used xpub resumes from where it left off.
                $xpubHash = hash('sha256', $row[$ch->store['xpub']]);
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
                // Per chain: the ECX card shows ecx_next_index.
                $pdo->prepare(
                    "UPDATE stores SET {$ch->store['next_index']} = ? WHERE id = ?"
                )->execute([$index + 1, $storeId]);

                $address = OnchainWallet::deriveAddress(
                    $row[$ch->store['xpub']],
                    $row[$ch->store['type']] ?: 'P2WPKH',
                    $ch->network($row),
                    $index
                );

                $result = ['address' => $address, 'index' => $index, 'tweak' => null];
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Tip read outside the txn (best-effort network call); failure leaves
        // tip NULL which simply disables historical-UTXO filtering.
        $result['tip_height'] = self::currentTipBestEffort($storeRow, $ch);
        return $result;
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
    private static function pickTweakSlot(PDO $pdo, array $store, string $address, int $baseAmountSat, int $now, ?Chain $ch = null): ?int {
        $ch = $ch ?? Chain::btc();
        $range = max(1, (int)($store[$ch->store['tweak_range']] ?? 1000));
        $confirmTimeout = (int)($store[$ch->store['timeout']] ?? 86400);
        // SQLite quirk: bound parameters arrive with TEXT affinity, and
        // `INTEGER > TEXT` compares by storage class (integers always
        // sort *below* text), so the naive `(expiration_time + ?) > ?`
        // silently returns false. CAST the bound timestamp to INTEGER
        // to force a numeric comparison.
        $stmt = $pdo->prepare(
            "SELECT {$ch->inv['amount']} FROM invoices
              WHERE {$ch->inv['address']} = ?
                AND {$ch->inv['amount']} IS NOT NULL
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

    /**
     * The index the shared per-xpub counter will hand out next for $xpub, or
     * $fallback when the key has never allocated. The counter lives in
     * onchain_xpub_state and is shared by every chain (and every store) using
     * the key, so this — not the per-store mirror column — is what the admin
     * "next address" previews and the cards' m/0/<n> labels must read: after
     * an eCash allocation the Bitcoin mirror column is behind by one.
     */
    public static function nextIndexForXpub(?string $xpub, int $fallback = 0): int {
        if ($xpub === null || trim($xpub) === '') {
            return $fallback;
        }
        $row = Database::fetchOne(
            "SELECT next_index FROM onchain_xpub_state WHERE xpub_hash = ?",
            [hash('sha256', $xpub)]
        );
        return $row ? (int)$row['next_index'] : $fallback;
    }

    /** Public: Invoice::create also stamps a Strike-minted address with the
     *  allocation-time tip, using the same best-effort semantics. */
    public static function currentTipBestEffort(array $store, ?Chain $chain = null): ?int {
        $ch = $chain ?? Chain::btc();
        // Capture the chain tip at allocation time. The poller compares
        // each observation's block_height against this to discard payments
        // that existed on a re-used address BEFORE the invoice was created.
        // Provider call is best-effort: if it fails, we just leave the
        // tip NULL and skip filtering (old behavior).
        try {
            $provider = OnchainProviderFactory::forStore($store, $ch);
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
        // Swap claims are a Bitcoin-only concern; read the Bitcoin columns.
        $ch = Chain::btc();
        $pdo = Database::getInstance();
        Database::beginImmediate(); // write lock up front: read-before-write allocator (see Database::beginImmediate)
        try {
            $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ?");
            $stmt->execute([$storeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Static-address mode is incompatible with submarine swaps:
            // a swap claim landing in the static address would arrive with
            // an arbitrary amount and could collide with an open invoice's
            // tweaked total, leading to wrong attribution. Stores in static
            // mode fall back to non-swap rails for their invoices.
            if (!$row || ($row[$ch->store['mode']] ?? 'xpub') === 'static' || empty($row[$ch->store['xpub']])) {
                $pdo->commit();
                return null;
            }
            $xpubHash = hash('sha256', $row[$ch->store['xpub']]);
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
                $row[$ch->store['xpub']],
                $row[$ch->store['type']] ?: 'P2WPKH',
                $ch->network($row),
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
     * @param int|null $branch Per-install dev-fee branch (FeeBranch::current):
     *        derive at <xpub>/<branch>/0/<index> with a counter of its own.
     *        Null = the plain <xpub>/0/<index> chain (hosting fee, legacy).
     * @return array{address:string, index:int, tip_height:?int, branch:?int}
     */
    public static function allocateFeeAddress(
        string $xpub,
        string $network,
        string $type,
        ?array $tipProviderStore = null,
        ?int $branch = null,
        ?Chain $chain = null
    ): array {
        // $chain only picks whose explorer answers the best-effort tip read;
        // derivation is chain-agnostic (ECX addresses are Bitcoin addresses)
        // and the per-xpub counter is shared across chains on purpose.
        $ch = $chain ?? Chain::btc();
        $pdo = Database::getInstance();
        Database::beginImmediate(); // write lock up front: read-before-write allocator (see Database::beginImmediate)
        try {
            // A branched chain (<xpub>/<branch>/0/<index>, the per-install
            // dev-fee branch) gets its own index stream, keyed separately from
            // the plain <xpub>/0/<index> chain, so switching to a branch starts
            // at index 0 under it and the plain chain's counter is untouched.
            $xpubHash = hash('sha256', $branch === null ? $xpub : $xpub . '/' . $branch);
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

            $address = OnchainWallet::deriveAddress($xpub, $type ?: 'P2WPKH', $network ?: 'mainnet', $index, $branch);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Tip read is outside the txn (best-effort network call); failure
        // leaves tip NULL which simply disables historical-UTXO filtering.
        $tip = $tipProviderStore !== null ? self::currentTipBestEffort($tipProviderStore, $ch) : null;
        return ['address' => $address, 'index' => $index, 'tip_height' => $tip, 'branch' => $branch];
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
    public static function pollInvoice(string $invoiceId, ?Chain $chain = null): array {
        $ch = $chain ?? Chain::btc();
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) {
            throw new Exception("Invoice not found: {$invoiceId}");
        }
        $address = $invoice[$ch->inv['address']] ?? null;
        if (!$address) {
            return ['total_confirmed' => 0, 'total_pending' => 0, 'observation_count' => 0,
                    'status' => $invoice['status'], 'transition' => null];
        }
        $store = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$invoice['store_id']]);
        if (!$store) {
            throw new Exception("Store not found: {$invoice['store_id']}");
        }

        $provider = OnchainProviderFactory::forStore($store, $ch);
        // Pass the invoice's allocation tip as a paging hint: any tx confirmed
        // before the invoice existed is filtered out below, so the provider can
        // stop walking chain history once it pages below that height.
        $createdTipHint = isset($invoice[$ch->inv['created_tip']])
            ? (int)$invoice[$ch->inv['created_tip']]
            : null;
        $observations = $provider->addressTransactions($address, $createdTipHint);

        $minConfs = (int)($store[$ch->store['min_confs']] ?? 1);
        $now = time();
        // A Strike-minted address (strike_receive_request_id set) is fresh
        // per invoice, so it always uses the unambiguous unique-address
        // attribution below — even when the STORE is in static mode (the
        // static config is then only the fallback source for invoices where
        // the Strike call failed).
        $isStaticMode = ($store[$ch->store['mode']] ?? 'xpub') === 'static'
            && (!$ch->isBtc() || empty($invoice['strike_receive_request_id']));

        // Filter out historical UTXOs on a re-used address — any tx confirmed
        // strictly before the invoice was created. onchain_created_tip_height
        // is the chain tip at allocation time; an observation with
        // block_height < that height was mined before the invoice existed.
        // Mempool observations (block_height === null) are always kept.
        // Legacy invoices without a recorded tip skip the filter.
        $createdTip = $invoice[$ch->inv['created_tip']] ?? null;
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
            $expected = (int)($invoice[$ch->inv['amount']] ?? 0);
            $confirmTimeout = (int)($store[$ch->store['timeout']] ?? 86400);
            // Each poll re-evaluates ambiguity from scratch so the flag
            // can clear naturally once competing invoices expire.
            $candidates = [];
            foreach ($observations as $obs) {
                if ($obs->amountSat !== $expected) {
                    continue;
                }
                $existing = Database::fetchOne(
                    "SELECT invoice_id FROM {$ch->paymentsTable} WHERE txid = ? AND vout = ?",
                    [$obs->txid, $obs->vout]
                );
                if ($existing) {
                    if ($existing['invoice_id'] === $invoiceId) {
                        self::upsertObservation($invoiceId, $obs, $now, $ch);
                    }
                    continue;
                }
                $competitors = Database::fetchAll(
                    "SELECT id FROM invoices
                      WHERE {$ch->inv['address']} = ?
                        AND {$ch->inv['amount']} = ?
                        AND status IN ('New','Processing')
                        AND id != ?
                        AND (expiration_time + CAST(? AS INTEGER)) > CAST(? AS INTEGER)",
                    [$address, $expected, $invoiceId, $confirmTimeout, $now]
                );
                if (empty($competitors)) {
                    self::upsertObservation($invoiceId, $obs, $now, $ch);
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
                        ], $ch);
                    }
                }
            }
            self::setManualCandidates($invoiceId, $candidates, $ch);
        } else {
            // xpub mode: each address belongs to exactly one invoice, so any
            // UTXO at it is unambiguously this invoice's. Sum them all.
            foreach ($observations as $obs) {
                self::upsertObservation($invoiceId, $obs, $now, $ch);
            }
        }

        // Reorg / dropped-tx reconciliation. The provider's response is the
        // address's CURRENT view; a (txid,vout) we previously persisted but that
        // is no longer reported has been reorged out or evicted from the mempool.
        // Without this, onchain_payments rows are only ever inserted/updated, so
        // a stale row keeps counting toward the total forever — a reorged +
        // double-spent payment would leave the invoice settled on phantom funds.
        // We cap the rollback at REORG_SAFETY_DEPTH: a payment already buried
        // >= that many blocks is treated as final, so a transient provider hiccup
        // that omits it can't drop a deeply confirmed payment. $observations is
        // the post-historical-filter set, which is exactly what the upserts above
        // were drawn from, so a live UTXO is always present here.
        $observedKeys = [];
        foreach ($observations as $obs) {
            $observedKeys[$obs->txid . ':' . $obs->vout] = true;
        }
        $persistedRows = Database::fetchAll(
            "SELECT id, txid, vout, confirmations FROM {$ch->paymentsTable} WHERE invoice_id = ?",
            [$invoiceId]
        );
        foreach ($persistedRows as $p) {
            if (isset($observedKeys[$p['txid'] . ':' . (int)$p['vout']])) {
                continue; // still live; its confirmations were just refreshed above
            }
            if ((int)$p['confirmations'] >= self::REORG_SAFETY_DEPTH) {
                continue; // deeply buried -> treat as final, ignore provider omission
            }
            // Vanished while still shallow: reorged out or dropped. Stop counting it.
            Database::query("DELETE FROM {$ch->paymentsTable} WHERE id = ?", [$p['id']]);
        }

        // Recompute totals from the persisted state (canonical source).
        // Zero-conf (minConfs = 0) stores count a mempool-only payment as
        // confirmed — unless the sender signalled replace-by-fee on it: such
        // a payment can be replaced by a conflicting one until it is mined,
        // so it stays pending (Processing) until it has a confirmation. The
        // signal rides on this poll's observations (keyed by txid:vout);
        // confirmed rows are unaffected.
        $replaceableKeys = [];
        foreach ($observations as $obs) {
            if ($obs->replaceable && $obs->confirmations === 0) {
                $replaceableKeys[$obs->txid . ':' . $obs->vout] = true;
            }
        }
        $rows = Database::fetchAll(
            "SELECT txid, vout, amount_sat, confirmations FROM {$ch->paymentsTable} WHERE invoice_id = ?",
            [$invoiceId]
        );
        $totalConfirmed = 0;
        $totalPending = 0;
        foreach ($rows as $r) {
            $confs = (int)$r['confirmations'];
            $rbfUnconfirmed = $confs === 0 && isset($replaceableKeys[$r['txid'] . ':' . (int)$r['vout']]);
            if ($confs >= $minConfs && !$rbfUnconfirmed) {
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
            now: $now,
            chain: $ch
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
    public static function formatPaymentMethod(array $invoice, ?Chain $chain = null): ?array {
        $ch = $chain ?? Chain::btc();
        $address = $invoice[$ch->inv['address']] ?? null;
        if (!$address) {
            return null;
        }
        $amountSat = (int)($invoice[$ch->inv['amount']] ?? 0);
        return [
            'destination' => $address,
            // bitcoin:<addr>?amount=<BTC> / ecash:<addr>?amount=<ECX>,
            // trailing zeros trimmed from the coin amount.
            'paymentLink' => $ch->paymentUri($address, $amountSat),
            'amount' => (string)$amountSat,
            'due' => (string)$amountSat,
            'rate' => $invoice[$ch->inv['rate']] ?? null,
        ];
    }

    /**
     * Run pollInvoice() for every New/Processing invoice that has an
     * on-chain address. Used by the cron task.
     *
     * Throttled via invoices.onchain_last_polled_at — its OWN column, not
     * the last_polled_at the Lightning rail pollers share. The on-chain rail
     * is the only one that rides ALONGSIDE another rail on the same invoice
     * (bolt11 + address); with a shared stamp, the invoice's Lightning
     * poller (15-30s interval, runs earlier in cron.php) re-stamped it on
     * every cron pass and this poller's 60s check never came due, so the
     * cron-side backup for "customer paid on-chain and closed the tab"
     * silently never ran on dual-rail invoices.
     *
     * @param int $minIntervalSec Minimum seconds between consecutive polls of the same invoice
     * @param int $batchLimit Maximum invoices to poll per call
     */
    public static function pollPending(int $minIntervalSec = 60, int $batchLimit = 20, ?Chain $chain = null): array {
        $ch = $chain ?? Chain::btc();
        $now = time();
        // Throttle as `column <= cutoff`, NOT `(? - column) >= ?`: PDO binds
        // execute() params as TEXT, and while the column's INTEGER affinity
        // coerces a param it is compared against directly, an arithmetic
        // expression has no affinity — `(? - column) >= ?` compared integer
        // against text, which is ALWAYS false in SQLite (integers sort below
        // text). The old shape meant a stamped invoice was never re-polled.
        // Same quirk SwapPoller::pollPending documents and works around.
        $cutoff = $now - $minIntervalSec;
        // Long-lived invoices stay New for up to 90 days; polling every one of
        // them each minute would starve fresh checkouts and burn the provider
        // budget. They back off with age while still unpaid (New): the base
        // interval for the first day, then LONG_LIVED_POLL_SEC_WEEK until a
        // week old, then LONG_LIVED_POLL_SEC_OLD. A detected payment
        // (Processing) and the payment page's own poll are never slowed.
        $dayAgo = $now - 86400;
        $weekAgo = $now - 7 * 86400;
        // The last OR branch is the zero-conf watch: an invoice settled on a
        // payment that still has no confirmation keeps being polled (for
        // ZERO_CONF_WATCH_SEC after settlement) so a replaced / evicted
        // payment is noticed — see applyTransitions. An invoice whose payment
        // went missing on the last poll (reconciliation dropped the row) is
        // kept in the set by its additional_status so the second look happens.
        $rows = Database::fetchAll(
            "SELECT id FROM invoices
              WHERE {$ch->inv['address']} IS NOT NULL
                AND (
                  (status IN ('New', 'Processing')
                    AND ({$ch->inv['last_polled']} IS NULL
                         OR ((long_lived = 0 OR status = 'Processing' OR created_at > ?) AND {$ch->inv['last_polled']} <= ?)
                         OR (long_lived = 1 AND status = 'New' AND created_at <= ? AND created_at > ? AND {$ch->inv['last_polled']} <= ?)
                         OR (long_lived = 1 AND status = 'New' AND created_at <= ? AND {$ch->inv['last_polled']} <= ?)))
                  OR (status = 'Settled' AND settled_rail = '{$ch->rail}'
                      AND paid_at IS NOT NULL AND paid_at >= ?
                      AND ({$ch->inv['last_polled']} IS NULL OR {$ch->inv['last_polled']} <= ?)
                      AND (additional_status = ?
                           OR EXISTS (SELECT 1 FROM {$ch->paymentsTable} op
                                       WHERE op.invoice_id = invoices.id AND op.confirmations < 1)))
                )
              ORDER BY {$ch->inv['last_polled']} ASC NULLS FIRST
              LIMIT ?",
            [
                $dayAgo, $cutoff,
                $dayAgo, $weekAgo, $now - self::LONG_LIVED_POLL_SEC_WEEK,
                $weekAgo, $now - self::LONG_LIVED_POLL_SEC_OLD,
                $now - self::ZERO_CONF_WATCH_SEC, $cutoff, self::ZERO_CONF_MISSING_STATUS,
                $batchLimit,
            ]
        );
        $results = [];
        foreach ($rows as $r) {
            try {
                Database::query(
                    "UPDATE invoices SET {$ch->inv['last_polled']} = ? WHERE id = ?",
                    [$now, $r['id']]
                );
                $results[$r['id']] = self::pollInvoice($r['id'], $ch);
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
    public static function manuallyAttribute(string $invoiceId, string $txid, int $vout, ?Chain $chain = null): void {
        $ch = $chain ?? Chain::btc();
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) {
            throw new RuntimeException("Invoice not found: {$invoiceId}");
        }
        $existing = Database::fetchOne(
            "SELECT invoice_id FROM {$ch->paymentsTable} WHERE txid = ? AND vout = ?",
            [$txid, $vout]
        );
        if ($existing && $existing['invoice_id'] !== $invoiceId) {
            throw new RuntimeException("Transaction already attributed to another invoice");
        }
        $candidates = self::decodeCandidates($invoice[$ch->inv['manual']] ?? null);
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
            // ON CONFLICT DO NOTHING: a background poller can observe and insert
            // this same (txid, vout) for the same invoice between the SELECT
            // above and here. The old plain INSERT threw an uncaught constraint
            // violation in that window; the (txid, vout) UNIQUE plus the
            // already-attributed guard above keep attribution correct, so a
            // racing duplicate is simply a no-op.
            Database::query(
                "INSERT INTO {$ch->paymentsTable}
                    (id, invoice_id, txid, vout, amount_sat, confirmations, block_height, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(txid, vout) DO NOTHING",
                [
                    Database::generateId($ch->paymentIdPrefix),
                    $invoiceId,
                    $txid,
                    $vout,
                    (int)$match['amount_sat'],
                    (int)($match['confirmations'] ?? 0),
                    $match['block_height'] ?? null,
                    (int)($match['first_seen_at'] ?? $now),
                    $now,
                ]
            );
        }

        // Clear the candidate from every invoice that listed it (this one
        // resolves; the others lose this candidate but stay open).
        self::scrubCandidateGlobally($txid, $vout, $ch);

        // Run the state machine from persisted onchain_payments rows so the
        // newly-attributed payment immediately drives the invoice to its
        // next state. We deliberately do NOT re-contact the provider here:
        // (a) the operator is acting on already-confirmed information, so
        // round-tripping to Esplora/bitcoind is unnecessary, and (b) the
        // attribution must still succeed if the provider is currently
        // unavailable. The next scheduled poll will refresh confirmation
        // counts via the provider in the normal flow.
        self::driveStateFromPersisted($invoiceId, $ch);
    }

    /**
     * Recompute totals from onchain_payments and run the state machine
     * without contacting the provider. Used by manuallyAttribute() and
     * any other path that has already mutated onchain_payments.
     */
    private static function driveStateFromPersisted(string $invoiceId, ?Chain $ch = null): void {
        $ch = $ch ?? Chain::btc();
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$invoice) return;
        $store = Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$invoice['store_id']]);
        if (!$store) return;
        $minConfs = (int)($store[$ch->store['min_confs']] ?? 1);
        $rows = Database::fetchAll(
            "SELECT amount_sat, confirmations FROM {$ch->paymentsTable} WHERE invoice_id = ?",
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
            now: time(),
            chain: $ch
        );
    }

    /**
     * Count invoices currently flagged as needing manual confirmation.
     * Scoped to one store when storeId is provided; otherwise global.
     */
    public static function countNeedingManualConfirmation(?string $storeId = null, ?Chain $chain = null): int {
        $ch = $chain ?? Chain::btc();
        if ($storeId === null) {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM invoices WHERE {$ch->inv['needs_manual']} = 1"
            );
        } else {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM invoices
                  WHERE {$ch->inv['needs_manual']} = 1 AND store_id = ?",
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
    public static function listNeedingManualConfirmation(?array $storeIds = null, ?Chain $chain = null): array {
        $ch = $chain ?? Chain::btc();
        if ($storeIds === null) {
            $rows = Database::fetchAll(
                "SELECT * FROM invoices WHERE {$ch->inv['needs_manual']} = 1
                  ORDER BY created_at DESC"
            );
        } else {
            if (empty($storeIds)) return [];
            $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
            $rows = Database::fetchAll(
                "SELECT * FROM invoices WHERE {$ch->inv['needs_manual']} = 1
                   AND store_id IN ({$placeholders})
                  ORDER BY created_at DESC",
                $storeIds
            );
        }
        foreach ($rows as &$r) {
            // Same key for both chains so the admin list renders either.
            $r['onchain_manual_candidates_decoded'] = self::decodeCandidates($r[$ch->inv['manual']] ?? null);
            $r['chain'] = $ch->id;
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
    private static function setManualCandidates(string $invoiceId, array $candidates, ?Chain $ch = null): void {
        $ch = $ch ?? Chain::btc();
        if (empty($candidates)) {
            Database::query(
                "UPDATE invoices SET {$ch->inv['needs_manual']} = 0,
                                      {$ch->inv['manual']} = NULL
                  WHERE id = ?",
                [$invoiceId]
            );
            return;
        }
        Database::query(
            "UPDATE invoices SET {$ch->inv['needs_manual']} = 1,
                                  {$ch->inv['manual']} = ?
              WHERE id = ?",
            [json_encode(array_values($candidates), JSON_UNESCAPED_SLASHES), $invoiceId]
        );
    }

    /**
     * Add a candidate to an invoice's list if not already present.
     *
     * Atomic read-modify-write. This JSON column is written from three racing
     * places: the owning invoice's own poll (setManualCandidates), other
     * competitors' polls (this append), and manual attribution
     * (scrubCandidateGlobally). A bare SELECT-then-UPDATE would let two of them
     * read the same list and clobber each other's write — a candidate UTXO then
     * silently vanishes from the operator's manual-confirmation queue. Holding
     * the write lock across the read and the write (beginImmediate) serialises
     * them: a lone setManualCandidates UPDATE can only land fully before or
     * fully after this block, never between its read and its write.
     */
    private static function appendManualCandidate(string $invoiceId, array $candidate, ?Chain $ch = null): void {
        $ch = $ch ?? Chain::btc();
        Database::beginImmediate();
        try {
            $row = Database::fetchOne(
                "SELECT {$ch->inv['manual']} FROM invoices WHERE id = ?",
                [$invoiceId]
            );
            if (!$row) {
                Database::rollback();
                return;
            }
            $list = self::decodeCandidates($row[$ch->inv['manual']] ?? null);
            foreach ($list as $existing) {
                if (($existing['txid'] ?? '') === $candidate['txid']
                    && (int)($existing['vout'] ?? -1) === (int)$candidate['vout']) {
                    Database::rollback(); // already present; nothing to write
                    return;
                }
            }
            $list[] = $candidate;
            self::setManualCandidates($invoiceId, $list, $ch); // inner UPDATE joins this tx
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Remove a candidate (matched by txid+vout) from every invoice that
     * holds it. Called after manual attribution so the same tx isn't
     * offered as a candidate elsewhere.
     */
    private static function scrubCandidateGlobally(string $txid, int $vout, ?Chain $ch = null): void {
        $ch = $ch ?? Chain::btc();
        // Atomic read-modify-write across every candidate list (see
        // appendManualCandidate). The whole scan + rewrite runs under the write
        // lock so a concurrent poll's append can't be lost between this read and
        // its write-back.
        Database::beginImmediate();
        try {
            $rows = Database::fetchAll(
                "SELECT id, {$ch->inv['manual']} FROM invoices
                  WHERE {$ch->inv['needs_manual']} = 1"
            );
            foreach ($rows as $r) {
                $list = self::decodeCandidates($r[$ch->inv['manual']] ?? null);
                $filtered = array_values(array_filter($list, function ($c) use ($txid, $vout) {
                    return !(($c['txid'] ?? '') === $txid && (int)($c['vout'] ?? -1) === $vout);
                }));
                if (count($filtered) !== count($list)) {
                    self::setManualCandidates($r['id'], $filtered, $ch);
                }
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    private static function upsertObservation(string $invoiceId, OnchainTxObservation $obs, int $now, ?Chain $ch = null): void {
        $ch = $ch ?? Chain::btc();
        // Single atomic upsert keyed on UNIQUE(txid, vout). The old
        // SELECT-then-INSERT/UPDATE was racy: two concurrent pollers (the
        // customer's ~2s browser poll and the cron pass) both observe the same
        // new UTXO, both miss the SELECT, and both fall through to a plain
        // INSERT — the loser then threw an uncaught SQLITE_CONSTRAINT, which in
        // the browser-driven path surfaced a 500 mid-payment. ON CONFLICT makes
        // the second observer a no-op-style update instead. first_seen_at and
        // invoice_id are left untouched on conflict (the first observer's
        // attribution wins), matching the previous UPDATE branch.
        Database::query(
            "INSERT INTO {$ch->paymentsTable}
                (id, invoice_id, txid, vout, amount_sat, confirmations, block_height, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(txid, vout) DO UPDATE SET
                confirmations = excluded.confirmations,
                block_height  = excluded.block_height,
                last_seen_at  = excluded.last_seen_at",
            [
                Database::generateId($ch->paymentIdPrefix),
                $invoiceId,
                $obs->txid,
                $obs->vout,
                $obs->amountSat,
                $obs->confirmations,
                $obs->blockHeight,
                $now,
                $now,
            ]
        );
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
        int $now,
        ?Chain $chain = null
    ): array {
        $ch = $chain ?? Chain::btc();
        $amount = (int)($invoice[$ch->inv['amount']] ?? 0);
        // A long-lived fiat invoice is re-quoted on page load; a payer who
        // already saw an earlier (smaller) amount and pays it still settles.
        // The floor is the smallest amount ever shown (Invoice::requoteForView).
        $floor = (int)($invoice[$ch->inv['floor']] ?? 0);
        if ($floor > 0 && $floor < $amount) {
            $amount = $floor;
        }
        $status = $invoice['status'];
        $fired = null;

        // Zero-conf watch: an invoice settled on a mempool-only payment whose
        // payment has since vanished (replaced by a conflicting spend, or
        // evicted) no longer has the funds it was settled on. Flip it to
        // Invalid — the merchant has to know before shipping — and fire
        // InvoiceInvalid so the shop reverses the order. Only reachable from
        // pollPending's watch branch / a direct poll of a Settled invoice;
        // a payment that merely confirmed leaves the watch set untouched.
        if ($status === 'Settled') {
            $missing = $amount > 0 && $totalConfirmed < $amount;
            $wasMissing = ($invoice['additional_status'] ?? null) === self::ZERO_CONF_MISSING_STATUS;
            if ($missing && !$wasMissing) {
                // First miss: remember it, stay Settled, look again next poll.
                Database::query(
                    "UPDATE invoices SET additional_status = ? WHERE id = ? AND status = 'Settled'",
                    [self::ZERO_CONF_MISSING_STATUS, $invoice['id']]
                );
                error_log(sprintf(
                    'on-chain %s: zero-conf payment missing from the provider view (%d sat confirmed of %d); '
                    . 'will reverse the settlement if it is still missing on the next poll',
                    $invoice['id'], $totalConfirmed, $amount
                ));
                return ['status' => $status, 'fired' => null];
            }
            if ($missing && $wasMissing) {
                require_once __DIR__ . '/../invoice.php';
                require_once __DIR__ . '/../admin_log.php';
                $flipped = Invoice::updateStatus($invoice['id'], 'Invalid', null, null, ['Settled']);
                if ($flipped) {
                    $msg = sprintf(
                        'zero-conf payment reversed: %d sat confirmed of %d after settlement; the funding transaction '
                        . 'disappeared from the mempool (replaced or double-spent). Invoice marked Invalid.',
                        $totalConfirmed, $amount
                    );
                    error_log("on-chain {$invoice['id']}: {$msg}");
                    AdminLog::log($ch->logCategory, 'zero_conf_reversed', $invoice['store_id'], $invoice['id'],
                        $invoice[$ch->inv['address']] ?? null, $msg);
                    require_once __DIR__ . '/../invoice_attempts.php';
                    InvoiceAttempts::record($invoice['id'], $invoice['store_id'], InvoiceAttempts::CONTEXT_POLL, $ch->attemptRail,
                        $invoice[$ch->inv['address']] ?? null, InvoiceAttempts::FAILED,
                        'the on-chain payment was reversed after settlement', $msg);
                    return ['status' => 'Invalid', 'fired' => 'InvoiceInvalid'];
                }
            }
            if (!$missing && $wasMissing) {
                // The payment is back (provider hiccup): clear the strike.
                Database::query(
                    "UPDATE invoices SET additional_status = NULL WHERE id = ? AND status = 'Settled' AND additional_status = ?",
                    [$invoice['id'], self::ZERO_CONF_MISSING_STATUS]
                );
            }
            return ['status' => $status, 'fired' => null];
        }

        // First mempool sighting flips New -> Processing and starts TTCW.
        if ($status === 'New' && $anyObservation) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Processing');
            Database::query(
                "UPDATE invoices SET {$ch->inv['first_seen']} = COALESCE({$ch->inv['first_seen']}, ?) WHERE id = ?",
                [$now, $invoice['id']]
            );
            $status = 'Processing';
            $fired = 'InvoiceReceivedPayment';
            $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoice['id']]);
        }

        // Settled when total confirmed reaches the invoice amount.
        if ($status !== 'Settled' && $totalConfirmed >= $amount && $amount > 0) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Settled', null, $ch->rail);
            return ['status' => 'Settled', 'fired' => $fired ?: 'InvoiceSettled'];
        }

        // Confirmation window expired without crossing the threshold.
        $firstSeen = (int)($invoice[$ch->inv['first_seen']] ?? 0);
        $timeout = (int)($store[$ch->store['timeout']] ?? 86400);
        if ($status === 'Processing' && $firstSeen > 0 && ($now - $firstSeen) >= $timeout && $totalConfirmed < $amount) {
            require_once __DIR__ . '/../invoice.php';
            Invoice::updateStatus($invoice['id'], 'Invalid');
            return ['status' => 'Invalid', 'fired' => 'InvoiceInvalid'];
        }

        return ['status' => $status, 'fired' => $fired];
    }
}
