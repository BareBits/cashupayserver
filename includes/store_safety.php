<?php
/**
 * CashuPayServer - Store money-safety helpers
 *
 * Small, dependency-light helpers shared by the export / withdraw / mint-change
 * paths:
 *
 *   - proof-state transitions with an explicit "from" set (CAS on the
 *     cashu_proofs row, so a proof the cron just spent is never exported);
 *   - the set of input secrets reserved by in-flight melt/swap journals;
 *   - fee-aware proof selection for exports;
 *   - fail-closed NUT-07 checkstate parsing (a short, empty or re-ordered
 *     reply is an error, never "spent");
 *   - what a wallet namespace holds, and what blocks a mint change or a
 *     backup-mint removal.
 *
 * Library contract: the wallet library is gaining ProofState::EXPORTED,
 * WalletStorage::transitionProofStates(), getReservedInputSecrets() and
 * Wallet::selectProofsWithFees(). Until they land, every helper below falls
 * back to an equivalent built on the public storage API / the shared SQLite
 * file. Each fallback is marked with a TODO(library) so the follow-up can
 * delete it.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../cashu-wallet-php/CashuWallet.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\ProofState;

class StoreSafety {
    /**
     * Proof state for "handed to a third party as a token" — distinct from
     * PENDING (reserved by an in-flight mint operation) on purpose: the mint
     * reporting an exported proof UNSPENT only means nobody has redeemed it
     * yet, so nothing may put it back into the spendable balance on its own.
     *
     * TODO(library): replace with ProofState::EXPORTED once the library ships it
     * (same string value, so stored rows are compatible either way).
     */
    const PROOF_STATE_EXPORTED = 'EXPORTED';

    /** Swap/sweep attempt statuses that are finished (mirrors SwapPoller). */
    private const SWAP_TERMINAL = [
        'invoice.settled', 'swap.expired', 'transaction.refunded', 'transaction.failed',
        'invoice.expired', 'claim.confirmed', 'error',
    ];

    public static function exportedState(): string {
        return defined('\Cashu\ProofState::EXPORTED') ? \Cashu\ProofState::EXPORTED : self::PROOF_STATE_EXPORTED;
    }

    /**
     * The wallet namespace id for a mint + unit, exactly as the library derives
     * it (Wallet trims the trailing slash before building its storage).
     */
    public static function walletId(string $mintUrl, string $unit): string {
        $mintUrl = rtrim($mintUrl, '/');
        if (method_exists(WalletStorage::class, 'deriveWalletId')) {
            return WalletStorage::deriveWalletId($mintUrl, $unit);
        }
        // TODO(library): drop once deriveWalletId() exists.
        return substr(hash('sha256', $mintUrl . ':' . $unit), 0, 16);
    }

    public static function storage(string $mintUrl, string $unit): WalletStorage {
        return new WalletStorage(Database::getDbPath(), rtrim($mintUrl, '/'), $unit);
    }

    /**
     * Move proofs from one of the $from states to $to, atomically, and report
     * how many rows actually changed.
     *
     * With $requireAll, nothing changes unless EVERY secret is currently in a
     * $from state — the caller is about to hand these proofs out and must not
     * do so when another request already reserved or spent one of them.
     *
     * @param string[] $secrets
     * @param string[] $from
     */
    public static function transitionProofStates(
        string $mintUrl,
        string $unit,
        array $secrets,
        string $to,
        array $from,
        bool $requireAll = false
    ): int {
        $secrets = array_values(array_unique(array_filter(array_map('strval', $secrets), 'strlen')));
        if (empty($secrets) || empty($from)) {
            return 0;
        }
        $storage = self::storage($mintUrl, $unit);
        if (method_exists($storage, 'transitionProofStates')) {
            if ($requireAll) {
                $states = $storage->getProofsStatesBySecrets($secrets);
                foreach ($secrets as $s) {
                    if (!isset($states[$s]) || !in_array($states[$s], $from, true)) {
                        return 0;
                    }
                }
            }
            return (int)$storage->transitionProofStates($secrets, $to, $from);
        }

        // TODO(library): fallback until WalletStorage::transitionProofStates() lands.
        // The proofs live in the server's own SQLite file, so a CAS UPDATE on
        // cashu_proofs inside a write transaction is exactly as atomic as the
        // library's version will be.
        $walletId = self::walletId($mintUrl, $unit);
        $own = !Database::inTransaction();
        if ($own) {
            Database::beginImmediate();
        }
        try {
            $inSecrets = implode(',', array_fill(0, count($secrets), '?'));
            $inFrom = implode(',', array_fill(0, count($from), '?'));
            if ($requireAll) {
                $rows = Database::fetchAll(
                    "SELECT secret FROM cashu_proofs
                     WHERE wallet_id = ? AND secret IN ($inSecrets) AND state IN ($inFrom)",
                    array_merge([$walletId], $secrets, $from)
                );
                if (count($rows) !== count($secrets)) {
                    if ($own) {
                        Database::rollback();
                    }
                    return 0;
                }
            }
            $spentAt = $to === ProofState::SPENT ? time() : null;
            $changed = Database::query(
                "UPDATE cashu_proofs SET state = ?, spent_at = ?
                 WHERE wallet_id = ? AND secret IN ($inSecrets) AND state IN ($inFrom)",
                array_merge([$to, $spentAt, $walletId], $secrets, $from)
            )->rowCount();
            if ($own) {
                Database::commit();
            }
            return (int)$changed;
        } catch (\Throwable $e) {
            if ($own) {
                Database::rollback();
            }
            throw $e;
        }
    }

    /**
     * Input secrets that an unfinished melt or swap journal still owns. The
     * mint may briefly report them UNSPENT after a timeout even though the
     * original request is queued; only the library's recovery may touch them.
     *
     * @return string[]
     */
    public static function reservedInputSecrets(WalletStorage $storage): array {
        if (method_exists($storage, 'getReservedInputSecrets')) {
            return array_values(array_map('strval', $storage->getReservedInputSecrets()));
        }
        // TODO(library): fallback until getReservedInputSecrets() lands.
        $reserved = [];
        foreach (['melt', 'swap'] as $type) {
            foreach ($storage->getPendingOperations($type) as $op) {
                foreach ((array)($op['data']['input_secrets'] ?? []) as $secret) {
                    $reserved[(string)$secret] = true;
                }
            }
        }
        return array_keys($reserved);
    }

    /**
     * Select the smallest input set that still covers $amount plus the NUT-02
     * input fee of the inputs themselves (two-pass: select, add the fee of
     * what was selected, re-select, until the selection is stable).
     *
     * @param \Cashu\Proof[] $proofs
     * @return \Cashu\Proof[]
     * @throws \Cashu\InsufficientBalanceException
     */
    public static function selectProofsWithFees(Wallet $wallet, array $proofs, int $amount): array {
        if (method_exists($wallet, 'selectProofsWithFees')) {
            return $wallet->selectProofsWithFees($proofs, $amount);
        }
        // TODO(library): fallback until Wallet::selectProofsWithFees() lands.
        // Each pass selects for amount + the fee of the previous selection;
        // once the new selection's fee no longer grows, it covers the amount
        // and its own fee.
        $fee = 0;
        $selected = [];
        for ($i = 0; $i < 8; $i++) {
            $selected = Wallet::selectProofs($proofs, $amount + $fee);
            $newFee = $wallet->calculateFee($selected);
            if ($newFee <= $fee) {
                break;
            }
            $fee = $newFee;
        }
        if (Wallet::sumProofs($selected) - $wallet->calculateFee($selected) < $amount) {
            throw new \Cashu\InsufficientBalanceException(
                'Insufficient balance: have ' . Wallet::sumProofs($proofs) . ', need ' . $amount . ' plus fees'
            );
        }
        return $selected;
    }

    /**
     * Normalise a NUT-07 checkstate reply, failing closed.
     *
     * @param string[] $Ys hex Y values, in request order
     * @param mixed $response decoded mint reply
     * @return string[] upper-cased states, one per Y, in request order
     * @throws \Exception when the reply is missing, short, long or re-ordered
     */
    public static function validateStates(array $Ys, $response): array {
        $states = is_array($response) ? ($response['states'] ?? null) : null;
        if (!is_array($states) || count($states) !== count($Ys)) {
            throw new \Exception('Mint returned an incomplete proof state response');
        }
        $out = [];
        $i = 0;
        foreach ($states as $state) {
            if (!is_array($state)) {
                throw new \Exception('Mint returned a malformed proof state response');
            }
            if (isset($state['Y']) && !hash_equals(strtolower((string)$Ys[$i]), strtolower((string)$state['Y']))) {
                throw new \Exception('Mint returned proof states in an unexpected order');
            }
            $out[] = strtoupper((string)($state['state'] ?? ''));
            $i++;
        }
        return $out;
    }

    /**
     * "All spent" is a claim that the recipient redeemed the token, so it must
     * be proved for every proof — never assumed from an empty or short reply.
     */
    public static function proofStatesAllSpent(array $Ys, $response): bool {
        if (empty($Ys)) {
            return false;
        }
        foreach (self::validateStates($Ys, $response) as $state) {
            if ($state !== ProofState::SPENT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ask a mint for the state of the given Ys.
     *
     * @param string[] $Ys
     * @return array<string, string> Y => upper-cased state
     */
    public static function checkStatesByY(string $mintUrl, array $Ys): array {
        $Ys = array_values($Ys);
        if (empty($Ys)) {
            return [];
        }
        $client = new \Cashu\MintClient(rtrim($mintUrl, '/'));
        $response = $client->post('checkstate', ['Ys' => $Ys]);
        $states = self::validateStates($Ys, $response);
        $out = [];
        foreach ($Ys as $i => $Y) {
            $out[$Y] = $states[$i];
        }
        return $out;
    }

    /**
     * Does the namespace hold anything a store could still need: proofs in
     * any state, a NUT-13 counter, or an unfinished journal?
     */
    public static function namespaceHasData(string $mintUrl, string $unit): bool {
        if (rtrim($mintUrl, '/') === '') {
            return false;
        }
        $walletId = self::walletId($mintUrl, $unit);
        foreach (['cashu_proofs', 'cashu_counters', 'cashu_pending_operations'] as $table) {
            $exists = Database::fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            if (!$exists) {
                continue;
            }
            $row = Database::fetchOne("SELECT 1 AS x FROM {$table} WHERE wallet_id = ? LIMIT 1", [$walletId]);
            if ($row) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a namespace holds right now.
     *
     * @return array{balance: int, exported: int, pending: int, journals: int}
     */
    public static function namespaceHoldings(string $mintUrl, string $unit): array {
        $storage = self::storage($mintUrl, $unit);
        $exported = 0;
        foreach ($storage->getProofs(self::exportedState()) as $row) {
            $exported += (int)$row['amount'];
        }
        $pending = 0;
        foreach ($storage->getProofs(ProofState::PENDING) as $row) {
            $pending += (int)$row['amount'];
        }
        return [
            'balance' => $storage->getBalance(),
            'exported' => $exported,
            'pending' => $pending,
            'journals' => count($storage->getPendingOperations()),
        ];
    }

    /**
     * Is this mint+unit still on the store's backup list (enabled or not)?
     * A mint that stays listed is still enumerated by getStoreWalletAccounts(),
     * so its funds stay visible and reconciled after a primary switch.
     */
    public static function isMintRetainedAsBackup(string $storeId, string $mintUrl, string $unit): bool {
        $mintUrl = rtrim($mintUrl, '/');
        $unit = strtolower($unit);
        if ($mintUrl === '') {
            return false;
        }
        foreach (Config::getStoreBackupMints($storeId) as $backup) {
            if (rtrim((string)$backup['mint_url'], '/') === $mintUrl
                && strtolower((string)($backup['unit'] ?? 'sat')) === $unit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reasons a store's primary mint must not be switched right now, in plain
     * language. Empty when nothing is outstanding.
     *
     * @return string[]
     */
    public static function storeChangeBlockers(string $storeId): array {
        $reasons = [];

        $openQuotes = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM invoices
             WHERE store_id = ? AND status IN ('New', 'Processing')
               AND quote_id IS NOT NULL AND quote_id <> ''",
            [$storeId]
        );
        if ((int)($openQuotes['c'] ?? 0) > 0) {
            $reasons[] = (int)$openQuotes['c'] . ' invoice(s) still waiting for payment at the current mint';
        }

        $pendingMelts = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM melts WHERE store_id = ? AND status = 'pending'",
            [$storeId]
        );
        if ((int)($pendingMelts['c'] ?? 0) > 0) {
            $reasons[] = (int)$pendingMelts['c'] . ' fee payment(s) still being settled';
        }

        $pendingHooks = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM webhook_deliveries wd
             JOIN webhooks w ON w.id = wd.webhook_id
             WHERE w.store_id = ? AND wd.status = 'pending'",
            [$storeId]
        );
        if ((int)($pendingHooks['c'] ?? 0) > 0) {
            $reasons[] = (int)$pendingHooks['c'] . ' webhook delivery(ies) not yet delivered';
        }

        $swaps = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM swap_attempts
             WHERE store_id = ? AND status NOT IN (" . self::swapTerminalPlaceholders() . ")",
            array_merge([$storeId], self::SWAP_TERMINAL)
        );
        if ((int)($swaps['c'] ?? 0) > 0) {
            $reasons[] = (int)$swaps['c'] . ' customer swap(s) in flight';
        }
        $sweeps = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM sweep_attempts
             WHERE store_id = ? AND status NOT IN (" . self::swapTerminalPlaceholders() . ")",
            array_merge([$storeId], self::SWAP_TERMINAL)
        );
        if ((int)($sweeps['c'] ?? 0) > 0) {
            $reasons[] = (int)$sweeps['c'] . ' cashout swap(s) in flight';
        }

        foreach (Config::getStoreWalletAccounts($storeId) as $account) {
            try {
                $held = self::namespaceHoldings($account['mint_url'], $account['unit']);
            } catch (\Throwable $e) {
                $reasons[] = 'funds at ' . $account['mint_url'] . ' that could not be checked (' . $e->getMessage() . ')';
                continue;
            }
            $label = $account['primary'] ? 'the current mint' : 'backup mint ' . $account['mint_url'];
            if ($held['journals'] > 0) {
                $reasons[] = $held['journals'] . ' withdrawal(s) or swap(s) still in progress at ' . $label;
            }
            if ($held['exported'] > 0) {
                $reasons[] = $held['exported'] . ' ' . $account['unit'] . ' in exported tokens nobody has cashed in at ' . $label;
            }
        }

        return $reasons;
    }

    /**
     * What a backup mint account still holds, in plain language.
     *
     * @return array{reasons: string[]}|null null when the row is unknown
     */
    public static function backupMintHoldings(int $backupMintId): ?array {
        $row = Database::fetchOne('SELECT store_id, mint_url, unit FROM store_mints WHERE id = ?', [$backupMintId]);
        if (!$row) {
            return null;
        }
        $unit = strtolower((string)($row['unit'] ?? 'sat'));
        try {
            $held = self::namespaceHoldings((string)$row['mint_url'], $unit);
        } catch (\Throwable $e) {
            // Cannot verify: treat as "holds something" rather than silently
            // allowing the removal.
            return ['reasons' => ['funds that could not be checked (' . $e->getMessage() . ')']];
        }
        $reasons = [];
        if ($held['balance'] > 0) {
            $reasons[] = "{$held['balance']} {$unit} of ecash";
        }
        if ($held['exported'] > 0) {
            $reasons[] = "{$held['exported']} {$unit} in exported tokens nobody has cashed in";
        }
        if ($held['pending'] > 0 || $held['journals'] > 0) {
            $reasons[] = max($held['journals'], 1) . ' transfer(s) still in progress';
        }
        return ['reasons' => $reasons];
    }

    private static function swapTerminalPlaceholders(): string {
        return implode(',', array_fill(0, count(self::SWAP_TERMINAL), '?'));
    }
}
