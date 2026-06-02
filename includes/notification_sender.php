<?php
/**
 * CashuPayServer - Notification Sender Module
 *
 * Queues email notifications for invoice settlements and auto-withdrawal
 * results, applying:
 *   - the site-wide master switch + per-type toggle gate,
 *   - per-store opt-in (with site-wide fallback "to" address),
 *   - 48h dedupe on identical auto-withdraw failures (store + destination).
 *
 * Emails sit in `notification_queue` until cron drains them — the request
 * path never blocks on SMTP. See includes/email_sender.php for transport.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email_sender.php';

class NotificationSender {
    public const EVENT_INVOICE_PAID = 'InvoicePaid';
    public const EVENT_AUTO_WITHDRAW_SUCCESS = 'AutoWithdrawSuccess';
    public const EVENT_AUTO_WITHDRAW_FAILURE = 'AutoWithdrawFailure';

    // 48-hour window for suppressing identical auto-withdraw failure emails.
    private const FAILURE_DEDUPE_WINDOW_SEC = 48 * 60 * 60;

    // Cap drain work per cron tick. Real-world traffic is low, but a backlog
    // of failed deliveries shouldn't be able to wedge the whole cron run.
    private const DRAIN_BATCH = 25;

    /**
     * Queue an "invoice paid" email for a settled invoice. Idempotent at
     * the gate level — if the gate is closed (global off, per-type off,
     * store disabled, no recipient) nothing is enqueued.
     */
    public static function queueInvoicePaid(array $invoice): void {
        if (!self::isEventEnabled('notifications_invoice_paid_enabled')) {
            return;
        }
        $storeId = $invoice['store_id'] ?? null;
        if (!$storeId) return;

        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $invoiceId = $invoice['id'] ?? '(unknown)';
        $amountSats = $invoice['amount_sats'] ?? null;
        $amount = $invoice['amount'] ?? null;
        $currency = $invoice['currency'] ?? '';

        $satsLine = $amountSats !== null
            ? number_format((int)$amountSats) . ' sats'
            : '(sats amount unavailable)';

        // amount/currency reflect what the customer was billed (e.g. "12.50 USD"
        // for a fiat-priced invoice, or "1500 sat" for a sats-priced one).
        $fiatLine = ($amount !== null && $currency !== '')
            ? trim($amount . ' ' . $currency)
            : '(fiat amount unavailable)';

        $storeName = self::storeName($storeId);
        $subject = "Invoice paid: {$invoiceId} ({$satsLine})";
        $body = "An invoice was paid.\n\n"
              . "Store:       {$storeName}\n"
              . "Invoice ID:  {$invoiceId}\n"
              . "Amount:      {$satsLine}\n"
              . "Amount (fiat): {$fiatLine}\n";

        self::enqueue($storeId, self::EVENT_INVOICE_PAID, $recipient, $subject, $body, null);
    }

    /**
     * Queue an auto-withdrawal success email.
     */
    public static function queueAutoWithdrawSuccess(
        string $storeId,
        int $amountSats,
        string $destination
    ): void {
        if (!self::isEventEnabled('notifications_auto_withdraw_enabled')) {
            return;
        }
        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $storeName = self::storeName($storeId);
        $sats = number_format($amountSats) . ' sats';
        $subject = "Auto-withdrawal succeeded: {$sats} to {$destination}";
        $body = "An auto-withdrawal completed successfully.\n\n"
              . "Store:        {$storeName}\n"
              . "Amount:       {$sats}\n"
              . "Destination:  {$destination}\n";

        // Successful withdrawals are not deduped — operators want to see each one.
        self::enqueue($storeId, self::EVENT_AUTO_WITHDRAW_SUCCESS, $recipient, $subject, $body, null);
    }

    /**
     * Queue an auto-withdrawal failure email, with 48h dedupe on
     * (store_id, destination). Identical repeats are silently dropped.
     */
    public static function queueAutoWithdrawFailure(
        string $storeId,
        string $destination,
        string $errorMessage,
        ?int $attemptedAmountSats = null
    ): void {
        if (!self::isEventEnabled('notifications_auto_withdraw_enabled')) {
            return;
        }
        $recipient = self::resolveRecipient($storeId);
        if ($recipient === null) return;

        $dedupeKey = self::failureDedupeKey($destination);
        if (self::isWithinDedupeWindow($storeId, self::EVENT_AUTO_WITHDRAW_FAILURE, $dedupeKey)) {
            return;
        }

        $storeName = self::storeName($storeId);
        $amountLine = $attemptedAmountSats !== null
            ? number_format($attemptedAmountSats) . ' sats'
            : '(amount unavailable)';
        $subject = "Auto-withdrawal failed: {$destination}";
        $body = "An auto-withdrawal failed.\n\n"
              . "Store:        {$storeName}\n"
              . "Destination:  {$destination}\n"
              . "Amount:       {$amountLine}\n"
              . "Reason:       {$errorMessage}\n\n"
              . "Subsequent identical failures will be suppressed for 48 hours.\n";

        self::enqueue($storeId, self::EVENT_AUTO_WITHDRAW_FAILURE, $recipient, $subject, $body, $dedupeKey);
    }

    /**
     * Drain up to DRAIN_BATCH pending notifications from the queue.
     * Called from cron.php. Returns a small summary suitable for the cron
     * status JSON: ['sent' => N, 'failed' => N].
     */
    public static function drainQueue(): array {
        $rows = Database::fetchAll(
            "SELECT * FROM notification_queue
             WHERE sent_at IS NULL
             ORDER BY id ASC
             LIMIT ?",
            [self::DRAIN_BATCH]
        );

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                EmailSender::send($row['to_email'], $row['subject'], $row['body']);
                $now = time();
                Database::update(
                    'notification_queue',
                    ['sent_at' => $now, 'last_error' => null],
                    'id = ?',
                    [$row['id']]
                );
                // Record into the dedupe log so failure-suppression can see it.
                if (!empty($row['dedupe_key']) && !empty($row['store_id'])) {
                    self::recordDedupe($row['store_id'], $row['event_type'], $row['dedupe_key'], $now);
                }
                $sent++;
            } catch (Throwable $e) {
                Database::update(
                    'notification_queue',
                    [
                        'attempts' => (int)$row['attempts'] + 1,
                        'last_error' => substr($e->getMessage(), 0, 500),
                    ],
                    'id = ?',
                    [$row['id']]
                );
                error_log(
                    "NotificationSender: send failed for queue row {$row['id']}: " . $e->getMessage()
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Lightweight check used by the admin UI: are there any unsent rows?
     */
    public static function pendingCount(): int {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM notification_queue WHERE sent_at IS NULL"
        );
        return (int)($row['c'] ?? 0);
    }

    // ------------------------------------------------------------------------
    // Gating / recipient resolution
    // ------------------------------------------------------------------------

    private static function isEventEnabled(string $perTypeKey): bool {
        if (Config::get('notifications_enabled', false) !== true) {
            return false;
        }
        return Config::get($perTypeKey, false) === true;
    }

    /**
     * Resolve the "to" address for a store. Per-store override wins; site-wide
     * notifications_to_email is the fallback. Returns null when the store has
     * notifications disabled or no recipient is configured anywhere.
     */
    private static function resolveRecipient(string $storeId): ?string {
        $store = Config::getStore($storeId);
        if ($store === null) return null;
        if ((int)($store['notifications_enabled'] ?? 0) !== 1) return null;

        $perStore = trim((string)($store['notification_email'] ?? ''));
        if ($perStore !== '') {
            return $perStore;
        }
        $siteWide = trim((string)Config::get('notifications_to_email', ''));
        return $siteWide !== '' ? $siteWide : null;
    }

    private static function storeName(string $storeId): string {
        $store = Config::getStore($storeId);
        return (string)($store['name'] ?? $storeId);
    }

    // ------------------------------------------------------------------------
    // Dedupe
    // ------------------------------------------------------------------------

    /**
     * Per the spec: "identical" failure = same store + same destination.
     * Error text is intentionally NOT part of the key.
     */
    private static function failureDedupeKey(string $destination): string {
        return hash('sha256', strtolower($destination));
    }

    private static function isWithinDedupeWindow(string $storeId, string $eventType, string $dedupeKey): bool {
        $row = Database::fetchOne(
            "SELECT sent_at FROM notification_log
             WHERE store_id = ? AND event_type = ? AND dedupe_key = ?",
            [$storeId, $eventType, $dedupeKey]
        );
        if ($row === null) return false;
        $sentAt = (int)$row['sent_at'];
        return ($sentAt + self::FAILURE_DEDUPE_WINDOW_SEC) > time();
    }

    private static function recordDedupe(string $storeId, string $eventType, string $dedupeKey, int $sentAt): void {
        // Upsert: SQLite supports ON CONFLICT REPLACE via a delete-then-insert.
        Database::delete(
            'notification_log',
            'store_id = ? AND event_type = ? AND dedupe_key = ?',
            [$storeId, $eventType, $dedupeKey]
        );
        Database::insert('notification_log', [
            'store_id' => $storeId,
            'event_type' => $eventType,
            'dedupe_key' => $dedupeKey,
            'sent_at' => $sentAt,
        ]);
    }

    // ------------------------------------------------------------------------
    // Queue insert
    // ------------------------------------------------------------------------

    private static function enqueue(
        string $storeId,
        string $eventType,
        string $toEmail,
        string $subject,
        string $body,
        ?string $dedupeKey
    ): void {
        Database::insert('notification_queue', [
            'store_id' => $storeId,
            'event_type' => $eventType,
            'to_email' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'dedupe_key' => $dedupeKey,
            'created_at' => time(),
        ]);
    }
}
