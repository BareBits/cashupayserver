<?php
/**
 * Settlement context for the swap lifecycle (poller + claimer).
 *
 * The same reverse-swap machinery drives two distinct flows:
 *   - "customer" — a customer paid a Lightning invoice through a swap
 *     provider; we receive on-chain to the parent invoice's allocated
 *     address. Backed by the swap_attempts table; settlement flips the
 *     invoice to Settled.
 *   - "sweep"    — the merchant's own auto-melt routine paid a swap
 *     provider's invoice (via cashu melt) to move the mint balance
 *     on-chain. Backed by sweep_attempts; settlement fires a notification
 *     and nothing else (no parent invoice to update).
 *
 * SwapPoller and SwapClaimer both take a context so they can address the
 * right table and run the right tail-end action without branching on a
 * string flag internally.
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../webhook_sender.php';

interface SwapSettlementContext {
    /** Table name that holds the swap rows this context drives. */
    public function tableName(): string;

    /**
     * Called once when a row transitions to a successful terminal state
     * (provider reported invoice.settled and our local row is up-to-date).
     */
    public function onSettled(array $row): void;

    /**
     * Called once when a row transitions to a failed/expired terminal
     * state. $providerStatus is the provider's status string; $message is
     * a short human-readable explanation.
     */
    public function onInvalid(array $row, string $providerStatus, string $message): void;
}

/**
 * Customer-invoice flow: row lives in swap_attempts; settlement flips the
 * parent invoice to Settled (with the swap rail recorded). Errors mark the
 * parent invoice Invalid/PaidLate, mirroring the historical poller.
 */
final class CustomerSwapSettlement implements SwapSettlementContext {
    public function tableName(): string { return 'swap_attempts'; }

    public function onSettled(array $row): void {
        $pdo = Database::getInstance();
        $pdo->prepare(
            "UPDATE invoices SET status = 'Settled', additional_status = 'PaidNormal',
                                 paid_at = ?, settled_rail = 'swap'
             WHERE id = ?"
        )->execute([time(), $row['invoice_id']]);
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$row['invoice_id']]);
        if ($invoice) {
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceSettled', $invoice);
        }
    }

    public function onInvalid(array $row, string $providerStatus, string $message): void {
        $pdo = Database::getInstance();
        // Only flip if still in a non-terminal state — a late-arriving
        // expiry tick shouldn't downgrade an already-Settled invoice.
        $pdo->prepare(
            "UPDATE invoices SET status = 'Invalid', additional_status = 'PaidLate'
              WHERE id = ? AND status = 'New'"
        )->execute([$row['invoice_id']]);
        $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$row['invoice_id']]);
        if ($invoice) {
            WebhookSender::fireEvent($invoice['store_id'], 'InvoiceInvalid', $invoice);
        }
    }
}
