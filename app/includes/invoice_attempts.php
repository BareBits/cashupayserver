<?php
/**
 * Per-invoice payment-path attempt log (invoice_rail_attempts).
 *
 * Every time the server tries to source a payment method for an invoice —
 * a Strike invoice, an LNURL fetch, an NWC make_invoice, a CLINK noffer
 * request, a submarine swap with a provider, a Cashu mint quote, an
 * on-chain address — one row records the attempt and its result, so the
 * admin invoice detail can show which methods were tried, in what order,
 * and why the ones that failed did. Settlement-check failures (the pollers
 * that confirm payment) are appended to the same log.
 *
 * Contexts:
 *   checkout  invoice creation (Invoice::create)
 *   refresh   long-lived invoice Lightning re-sourcing (Invoice::refreshLightning)
 *   poll      settlement checks after creation (payment page + cron pollers)
 *
 * Rails use the admin-log category vocabulary: strike | lnurl | nwc |
 * noffer | swap | mint | onchain | fee.
 *
 * Two message columns, deliberately separate:
 *   reason  the sanitized, fixed server-side phrase — the same text that
 *           goes to the payer-facing receive_errors; safe for any viewer.
 *   detail  the raw error text (exception message, provider reason). Admin
 *           only — it can name hosts and quote wallet-provided text. It
 *           must still never contain an NWC connection URI or a Strike API
 *           key; callers pass masked labels (NwcUri::displayLabel,
 *           StrikeClient::maskKey) and the exceptions those clients throw
 *           don't embed the credential.
 *
 * Buffering: invoice_rail_attempts has a foreign key to invoices, but the
 * creation walk runs BEFORE the invoice row is inserted. Invoice::create
 * calls beginBuffer() with the pre-generated id; record() then queues rows
 * in memory and flush() persists them right after the insert. A create()
 * that throws leaves nothing behind (no invoice, no rows). Outside a
 * buffer, record() writes straight to the table.
 *
 * Recording never throws — a logging failure must not break checkout or
 * the pollers.
 */

require_once __DIR__ . '/database.php';

class InvoiceAttempts {
    public const CONTEXT_CHECKOUT = 'checkout';
    public const CONTEXT_REFRESH = 'refresh';
    public const CONTEXT_POLL = 'poll';
    public const CONTEXTS = [self::CONTEXT_CHECKOUT, self::CONTEXT_REFRESH, self::CONTEXT_POLL];

    public const OK = 'ok';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const OUTCOMES = [self::OK, self::FAILED, self::SKIPPED];

    public const RAILS = ['strike', 'lnurl', 'nwc', 'noffer', 'swap', 'mint', 'onchain', 'ecx', 'fee'];

    /** Rows kept per invoice; the oldest beyond it are pruned on insert. */
    public const PER_INVOICE_CAP = 300;

    /** Poll-context writes: skip if an identical row is younger than this. */
    public const POLL_THROTTLE_SEC = 600;

    /** Longest reason / detail / label persisted. */
    private const MAX_TEXT_LEN = 300;

    /** @var array<string, list<array<string,mixed>>> rows queued per invoice id */
    private static array $buffers = [];

    /**
     * Start queueing rows for an invoice whose row does not exist yet (see
     * class doc). Replaces any stale buffer left by an aborted create.
     */
    public static function beginBuffer(string $invoiceId): void {
        self::$buffers[$invoiceId] = [];
    }

    /** True while beginBuffer() is active for the invoice. */
    public static function isBuffering(string $invoiceId): bool {
        return array_key_exists($invoiceId, self::$buffers);
    }

    /** Persist the queued rows (in order) now that the invoice row exists. */
    public static function flush(string $invoiceId): void {
        if (!array_key_exists($invoiceId, self::$buffers)) {
            return;
        }
        $rows = self::$buffers[$invoiceId];
        unset(self::$buffers[$invoiceId]);
        foreach ($rows as $row) {
            self::insertRow($row);
        }
        if ($rows !== []) {
            self::prune($invoiceId);
        }
    }

    /** Drop queued rows without writing them (create() aborted). */
    public static function discard(string $invoiceId): void {
        unset(self::$buffers[$invoiceId]);
    }

    /**
     * Record one attempt.
     *
     * @param string      $context  one of CONTEXTS
     * @param string      $rail     one of RAILS
     * @param string|null $label    safe destination identity: LN address,
     *                              noffer string, masked NWC label, masked
     *                              Strike key, mint URL, swap provider name,
     *                              on-chain address source
     * @param string      $outcome  one of OUTCOMES
     * @param string|null $reason   sanitized phrase (payer-safe); null on ok
     * @param string|null $detail   raw admin-only text
     */
    public static function record(
        string $invoiceId,
        ?string $storeId,
        string $context,
        string $rail,
        ?string $label,
        string $outcome,
        ?string $reason = null,
        ?string $detail = null
    ): void {
        try {
            if (!in_array($context, self::CONTEXTS, true)) {
                $context = self::CONTEXT_CHECKOUT;
            }
            if (!in_array($outcome, self::OUTCOMES, true)) {
                $outcome = self::FAILED;
            }
            $row = [
                'invoice_id' => $invoiceId,
                'store_id' => $storeId,
                'timestamp' => time(),
                'context' => $context,
                'rail' => $rail,
                'label' => self::clip($label),
                'outcome' => $outcome,
                'reason' => self::clip($reason),
                'detail' => self::clip($detail),
            ];
            if (array_key_exists($invoiceId, self::$buffers)) {
                self::$buffers[$invoiceId][] = $row;
                return;
            }
            if ($context === self::CONTEXT_POLL && self::recentIdentical($row)) {
                return;
            }
            self::insertRow($row);
            self::prune($invoiceId);
        } catch (Throwable $e) {
            error_log('[invoice-attempts] failed to record attempt: ' . $e->getMessage());
        }
    }

    /**
     * A settlement-check failure: records the (throttled) poll attempt AND
     * the matching admin_event_log row, so the Log tab and the invoice
     * detail stay in step. Replaces the bare AdminLog::log('…', 'poll', …)
     * calls in the pollers.
     */
    public static function pollFailure(
        string $invoiceId,
        ?string $storeId,
        string $rail,
        ?string $label,
        string $message
    ): void {
        self::record($invoiceId, $storeId, self::CONTEXT_POLL, $rail, $label, self::FAILED,
            'the payment status could not be checked', $message);
        require_once __DIR__ . '/admin_log.php';
        AdminLog::log($rail, 'poll', $storeId, $invoiceId, $label, $message);
    }

    /** All recorded attempts for an invoice, oldest first. */
    public static function listForInvoice(string $invoiceId): array {
        return Database::fetchAll(
            "SELECT * FROM invoice_rail_attempts WHERE invoice_id = ? ORDER BY timestamp ASC, id ASC",
            [$invoiceId]
        );
    }

    /**
     * API shape for the admin invoice detail. $includeDetail controls the
     * raw admin-only text; the sanitized reason always rides along.
     *
     * @return list<array{timestamp:int, context:string, rail:string,
     *               label:?string, outcome:string, reason:?string, detail?:?string}>
     */
    public static function formatForApi(array $rows, bool $includeDetail): array {
        $out = [];
        foreach ($rows as $r) {
            $entry = [
                'timestamp' => (int)$r['timestamp'],
                'context' => (string)$r['context'],
                'rail' => (string)$r['rail'],
                'label' => $r['label'] !== null ? (string)$r['label'] : null,
                'outcome' => (string)$r['outcome'],
                'reason' => $r['reason'] !== null ? (string)$r['reason'] : null,
            ];
            if ($includeDetail) {
                $entry['detail'] = $r['detail'] !== null ? (string)$r['detail'] : null;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Reconstruct a creation-time attempt list for an invoice created before
     * this log existed, from what the row itself still knows: the sanitized
     * receive_errors failures (no timestamps beyond created_at, no labels)
     * and the rail that won (payment_rail + ln_destination / mint_url), plus
     * the on-chain address when one was offered. Same shape as
     * formatForApi (detail is never available here).
     */
    public static function legacyFromInvoice(array $invoice): array {
        $created = (int)($invoice['created_at'] ?? 0);
        $out = [];
        $errors = [];
        if (!empty($invoice['receive_errors'])) {
            $decoded = json_decode((string)$invoice['receive_errors'], true);
            if (is_array($decoded)) {
                $errors = $decoded;
            }
        }
        foreach ($errors as $err) {
            if (!is_array($err) || !isset($err['type'])) {
                continue;
            }
            $out[] = [
                'timestamp' => $created,
                'context' => self::CONTEXT_CHECKOUT,
                'rail' => self::railFromReceiveErrorType((string)$err['type']),
                'label' => null,
                'outcome' => self::FAILED,
                'reason' => isset($err['reason']) ? (string)$err['reason'] : null,
            ];
        }
        if (!empty($invoice['bolt11'])) {
            $rail = (string)($invoice['payment_rail'] ?? '');
            $feeRails = array_filter(explode(',', (string)($invoice['fee_redirect_rails'] ?? '')));
            $label = null;
            switch ($rail) {
                case 'mint':
                    $label = $invoice['mint_url'] ?? null;
                    break;
                case 'swap':
                    $label = null;
                    break;
                default:
                    $label = $invoice['ln_destination'] ?? null;
            }
            $out[] = [
                'timestamp' => $created,
                'context' => self::CONTEXT_CHECKOUT,
                'rail' => in_array('lightning', $feeRails, true) ? 'fee' : self::railFromPaymentRail($rail),
                'label' => $label !== null ? (string)$label : null,
                'outcome' => self::OK,
                'reason' => null,
            ];
        }
        if (!empty($invoice['onchain_address'])) {
            $feeRails = array_filter(explode(',', (string)($invoice['fee_redirect_rails'] ?? '')));
            $out[] = [
                'timestamp' => $created,
                'context' => self::CONTEXT_CHECKOUT,
                'rail' => in_array('onchain', $feeRails, true) ? 'fee' : 'onchain',
                'label' => !empty($invoice['strike_receive_request_id']) ? 'Strike receive request' : 'local address',
                'outcome' => self::OK,
                'reason' => null,
            ];
        }
        return $out;
    }

    /** receive_errors type -> rail (they share the vocabulary except 'lnurl' = lnaddress). */
    private static function railFromReceiveErrorType(string $type): string {
        return in_array($type, self::RAILS, true) ? $type : 'lnurl';
    }

    /** invoices.payment_rail -> rail. */
    public static function railFromPaymentRail(string $paymentRail): string {
        switch ($paymentRail) {
            case 'lnaddress': return 'lnurl';
            case 'strike':
            case 'nwc':
            case 'noffer':
            case 'swap':
            case 'mint':
            case 'onchain':
                return $paymentRail;
            default:
                return $paymentRail !== '' ? $paymentRail : 'mint';
        }
    }

    // ---- internals --------------------------------------------------------

    private static function clip(?string $s): ?string {
        if ($s === null) {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, self::MAX_TEXT_LEN);
        }
        return substr($s, 0, self::MAX_TEXT_LEN);
    }

    private static function insertRow(array $row): void {
        Database::insert('invoice_rail_attempts', $row);
    }

    /** Poll throttle: an identical failure for this invoice within the window. */
    private static function recentIdentical(array $row): bool {
        $hit = Database::fetchOne(
            "SELECT id FROM invoice_rail_attempts
              WHERE invoice_id = ? AND context = ? AND rail = ? AND outcome = ?
                AND COALESCE(detail, '') = ? AND timestamp > ?
              LIMIT 1",
            [
                $row['invoice_id'], $row['context'], $row['rail'], $row['outcome'],
                (string)($row['detail'] ?? ''), time() - self::POLL_THROTTLE_SEC,
            ]
        );
        return $hit !== null && $hit !== false;
    }

    /** Keep the newest PER_INVOICE_CAP rows of one invoice. */
    private static function prune(string $invoiceId): void {
        Database::query(
            "DELETE FROM invoice_rail_attempts
              WHERE invoice_id = ? AND id IN (
                  SELECT id FROM invoice_rail_attempts
                   WHERE invoice_id = ?
                   ORDER BY timestamp DESC, id DESC
                   LIMIT -1 OFFSET ?
              )",
            [$invoiceId, $invoiceId, self::PER_INVOICE_CAP]
        );
    }
}
