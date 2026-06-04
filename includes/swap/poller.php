<?php
/**
 * Swap lifecycle poller: drives swap_attempts (customer flow) and
 * sweep_attempts (auto-melt flow) rows through the Boltz/Zeus reverse-swap
 * status machine, builds + broadcasts the claim transaction when ready,
 * and runs the per-context tail-end action on terminal status.
 *
 * Called from cron.php each tick. Uses last_polled_at as an atomic gate so
 * two concurrent cron invocations don't both work the same row.
 *
 * The same code drives both tables — a SwapSettlementContext supplied by the
 * caller picks the target table and the on-settled / on-invalid handler.
 * When omitted, defaults to the historical customer/swap_attempts flow.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../webhook_sender.php';
require_once __DIR__ . '/factory.php';
require_once __DIR__ . '/claimer.php';
require_once __DIR__ . '/settlement_context.php';

final class SwapPoller {
    // Statuses we consider terminal — rows in these states are skipped on poll.
    private const TERMINAL_STATUSES = [
        'invoice.settled',
        'swap.expired',
        'transaction.refunded',
        'transaction.failed',
        'invoice.expired',
        'claim.confirmed', // our own terminal
        'error',
    ];

    /**
     * Walk active rows for the supplied context, advance state, attempt
     * claim where possible. Per-row failures are logged and don't halt
     * the loop.
     *
     * @return array{polled:int, errors:int}
     */
    public static function pollPending(
        int $minInterval = 30,
        int $batchLimit = 20,
        ?SwapSettlementContext $ctx = null
    ): array {
        $ctx = $ctx ?? new CustomerSwapSettlement();
        $table = $ctx->tableName();
        $now = time();
        $pdo = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL_STATUSES), '?'));
        // $minInterval is not user input — inline it instead of binding, to
        // avoid a PDO+SQLite quirk where integer parameters bound through an
        // execute() array get coerced to TEXT and break numeric comparisons.
        $mi = (int)$minInterval;
        $params = self::TERMINAL_STATUSES;
        array_push($params, $now, $batchLimit);

        $rows = $pdo->prepare(
            "SELECT * FROM {$table}
             WHERE status NOT IN ({$placeholders})
             AND (last_polled_at IS NULL OR (CAST(? AS INTEGER) - last_polled_at) >= {$mi})
             ORDER BY
                 CASE WHEN last_polled_at IS NULL THEN 0 ELSE 1 END,
                 last_polled_at ASC
             LIMIT ?"
        );
        $rows->execute($params);
        $rowList = $rows->fetchAll(PDO::FETCH_ASSOC);

        $polled = 0;
        $errors = 0;
        foreach ($rowList as $row) {
            // Atomic claim: only proceed if we successfully stamp last_polled_at.
            $upd = $pdo->prepare(
                "UPDATE {$table}
                    SET last_polled_at = ?, updated_at = ?
                  WHERE id = ?
                    AND (last_polled_at IS NULL OR (CAST(? AS INTEGER) - last_polled_at) >= {$mi})"
            );
            $upd->execute([$now, $now, $row['id'], $now]);
            if ($upd->rowCount() !== 1) {
                continue; // another poller has this row
            }

            try {
                self::processRow($row, $ctx);
                $polled++;
            } catch (Throwable $e) {
                $errors++;
                error_log("SwapPoller ({$table}) row {$row['id']}: " . $e->getMessage());
                $pdo->prepare(
                    "UPDATE {$table} SET error_message = ?, updated_at = ? WHERE id = ?"
                )->execute([substr($e->getMessage(), 0, 500), time(), $row['id']]);
            }
        }
        return ['polled' => $polled, 'errors' => $errors];
    }

    /**
     * Cancel held HTLCs for customer swap_attempts whose parent invoice has
     * expired locally before the customer paid. Best-effort: errors logged,
     * never thrown. Sets local status to 'invoice.expired' so the row is now
     * terminal.
     *
     * Customer-only: sweep rows don't have a parent invoice to track, and
     * the provider's own swap timeout drives them to a terminal status via
     * pollPending().
     */
    public static function expireStale(): void {
        $rows = Database::fetchAll(
            "SELECT sa.*, i.expiration_time AS inv_expiration, i.status AS inv_status
               FROM swap_attempts sa
               JOIN invoices i ON i.id = sa.invoice_id
              WHERE sa.status = 'swap.created'
                AND i.expiration_time < ?",
            [time()]
        );
        $ctx = new CustomerSwapSettlement();
        foreach ($rows as $row) {
            $provider = SwapProviderFactory::byName($row['provider']);
            if ($provider) {
                try {
                    $provider->cancelInvoice($row['network'], $row['swap_id_external']);
                } catch (Throwable $e) {
                    error_log("SwapPoller cancelInvoice {$row['id']}: " . $e->getMessage());
                }
            }
            self::transitionToInvalid($row, 'invoice.expired', 'Invoice expired before customer payment', $ctx);
        }
    }

    /**
     * Drive a single row through one tick of state.
     */
    private static function processRow(array $row, SwapSettlementContext $ctx): void {
        $provider = SwapProviderFactory::byName($row['provider']);
        if (!$provider) {
            throw new RuntimeException("Unknown provider in DB row: {$row['provider']}");
        }
        $status = $provider->getSwapStatus($row['network'], $row['swap_id_external']);
        if ($status === null) {
            // 404: provider forgot about the swap. Treat as failed.
            self::transitionToInvalid($row, 'transaction.failed', 'Provider returned 404 for swap', $ctx);
            return;
        }

        $table = $ctx->tableName();
        // Mirror status into our row, persisting the preimage if it appeared.
        if ($status->preimage && empty($row['preimage_hex'])) {
            Database::getInstance()->prepare(
                "UPDATE {$table} SET status = ?, preimage_hex = ?, updated_at = ? WHERE id = ?"
            )->execute([$status->status, $status->preimage, time(), $row['id']]);
            $row['preimage_hex'] = $status->preimage;
        } else {
            Database::getInstance()->prepare(
                "UPDATE {$table} SET status = ?, updated_at = ? WHERE id = ?"
            )->execute([$status->status, time(), $row['id']]);
        }
        $row['status'] = $status->status;

        switch ($status->status) {
            case 'swap.created':
            case 'minerfee.paid':
                // waiting for LN payment / setup. No action.
                return;

            case 'transaction.mempool':
            case 'transaction.confirmed':
                if (empty($row['claim_txid'])) {
                    if ($status->lockupTxHex === null) {
                        throw new RuntimeException('Provider returned ' . $status->status . ' without lockup tx hex');
                    }
                    SwapClaimer::buildAndBroadcast($row, $status->lockupTxHex, $ctx);
                }
                return;

            case 'invoice.settled':
                self::transitionToSettled($row, $ctx);
                return;

            case 'invoice.expired':
            case 'swap.expired':
            case 'transaction.refunded':
            case 'transaction.failed':
                self::transitionToInvalid($row, $status->status, "Provider reported {$status->status}", $ctx);
                return;

            default:
                // Unknown / future status string: leave the row alone so it'll
                // be polled again later. Log once so we can spot new states.
                error_log("SwapPoller: unrecognized status '{$status->status}' for {$row['id']}");
                return;
        }
    }

    private static function transitionToSettled(array $row, SwapSettlementContext $ctx): void {
        $pdo = Database::getInstance();
        $table = $ctx->tableName();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE {$table} SET status = 'invoice.settled', updated_at = ? WHERE id = ?"
            )->execute([time(), $row['id']]);
            $ctx->onSettled($row);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function transitionToInvalid(
        array $row,
        string $providerStatus,
        string $message,
        SwapSettlementContext $ctx
    ): void {
        $pdo = Database::getInstance();
        $table = $ctx->tableName();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE {$table} SET status = ?, error_message = ?, updated_at = ? WHERE id = ?"
            )->execute([$providerStatus, substr($message, 0, 500), time(), $row['id']]);
            $ctx->onInvalid($row, $providerStatus, $message);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
