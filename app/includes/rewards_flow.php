<?php
/**
 * Cash back rewards — the invoice-side orchestration.
 *
 * Rewards (includes/rewards.php) owns the accounts and the ledger-guarded
 * balance movements; this class is what payment.php, admin.php, the webhook
 * payload and every settlement path call. It decides whether a payer may
 * apply rewards to an invoice, applies and removes them (reserving the
 * balance and re-pricing every rail the invoice offers), turns the
 * reservation into a spend when the invoice settles, computes and credits the
 * earn, hands reservations back when an invoice closes unpaid, and takes an
 * earn back when a zero-conf settlement is reversed.
 *
 * Money model (operator decisions, locked)
 *   - The discount is recorded on the invoice in the INVOICE currency
 *     (invoices.rewards_discount_amount); what the payer owes is
 *     effectiveAmount() = amount − discount. The reservation is recorded in
 *     the rewards currency's micro-units (rewards_applied_micro) and is
 *     exactly the micro that corresponds to the discount actually taken —
 *     never more than the balance, so rounding across currencies always
 *     favours the payer by a fraction.
 *   - A balance that covers the whole invoice settles it at once on the
 *     'rewards' rail with amount_sats = 0 (nothing moves on any rail; the
 *     dev fee, which sums amount_sats, sees nothing to charge).
 *   - The earn is a percentage of what the payer actually paid (NET of the
 *     rewards discount), converted into the rewards currency at the invoice's
 *     own exchange rate when it has one. Tip-jar invoices and invoices of a
 *     store whose rewards are off at settlement earn nothing.
 *   - If an account is already tied to the invoice (rewards were applied),
 *     the earn is credited at settlement; otherwise the paid page offers a
 *     claim for CLAIM_WINDOW_SEC after paid_at (claimEarned).
 *   - Lifecycle of a reservation: apply → reserve; Settled → commit;
 *     Expired/Invalid from New → release; the payer's own undo → release and
 *     the ledger pair is forgotten so the same invoice can be applied again.
 *     Invalid after Settled (zero-conf reversal) → the earn is reversed.
 *
 * Re-pricing on apply / remove
 *   Lightning is re-sourced through Invoice::refreshLightning with the
 *   effective amount (opts 'rewards' + 'amount_override'); the previous
 *   bolt11 is parked and still polled, so a payer who scanned the earlier QR
 *   is settled for what they paid. On-chain / eCash amounts move with a floor
 *   (unique addresses) or a fresh tweak whose previous total stays reserved
 *   as an "alternate" (static shared address). Fee-routed rails are
 *   re-fetched from the same fee payee at the reduced amount.
 *
 * Every hook is idempotent and never throws into a settlement path: the
 * ledger's UNIQUE (invoice_id, kind) index makes each movement happen at most
 * once, and a failure in here is logged rather than allowed to break the
 * payment that just arrived.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/rewards.php';
require_once __DIR__ . '/invoice_attempts.php';

class RewardsFlow {
    /** Payer-facing errors, verbatim as the payment page shows them. */
    public const ERR_NO_REWARDS = 'No rewards found for that username';
    public const ERR_TOO_SMALL = 'Your rewards balance is too small to apply';
    public const ERR_NOT_APPLICABLE = 'Rewards cannot be applied to this invoice';
    public const ERR_RETRY = 'Rewards could not be applied right now, please try again';
    public const ERR_REMOVE_RETRY = 'Rewards could not be removed right now, please try again';
    public const ERR_NOT_APPLIED = 'No rewards are applied to this invoice';
    public const ERR_NOTHING_TO_CLAIM = 'There are no rewards to claim on this invoice';
    public const ERR_CLAIM_CLOSED = 'The time to claim the rewards on this invoice has passed';
    public const ERR_ALREADY_CLAIMED = 'The rewards on this invoice were already claimed';
    public const ERR_CLAIM_RETRY = 'Rewards could not be claimed right now, please try again';

    /** Blocked reasons (applyBlockedReason), payer-facing. */
    public const BLOCKED_STORE_OFF = 'This store does not offer rewards';
    public const BLOCKED_NOT_OPEN = 'This invoice is no longer open';
    public const BLOCKED_TIP_JAR = 'Rewards cannot be used on tips';
    public const BLOCKED_ALREADY_APPLIED = 'Rewards have already been applied to this invoice';
    public const BLOCKED_NO_RATE = 'The exchange rate for rewards is unavailable right now';

    // ---------------- Read side ----------------

    /**
     * Can rewards be applied to this invoice? null = yes; otherwise the
     * payer-facing reason: store rewards off, invoice not New, tip-jar
     * invoice, rewards already applied, or no exchange rate between the
     * rewards currency and the invoice currency (a sat store with sat
     * invoices never needs one).
     */
    public static function applyBlockedReason(array $invoice, array $store): ?string {
        if (!Rewards::isEnabled($store)) {
            return self::BLOCKED_STORE_OFF;
        }
        if (($invoice['status'] ?? '') !== 'New') {
            return self::BLOCKED_NOT_OPEN;
        }
        if (!empty($invoice['tip_jar_id'])) {
            return self::BLOCKED_TIP_JAR;
        }
        if (self::spendIsActive($invoice)) {
            return self::BLOCKED_ALREADY_APPLIED;
        }
        $s = Rewards::settingsFromRow($store);
        $invCur = Rewards::normalizeCurrency((string)($invoice['currency'] ?? 'sat'));
        if ($invCur !== $s['currency'] && Rewards::convertMicro(Rewards::MICRO, (string)$s['currency'], $invCur, $store) === null) {
            return self::BLOCKED_NO_RATE;
        }
        return null;
    }

    /**
     * What the payer owes in the invoice currency: amount − rewards discount,
     * at the natural precision of the two figures, never below zero.
     */
    public static function effectiveAmount(array $invoice): string {
        $amount = self::decimal((string)($invoice['amount'] ?? '0'));
        $discount = $invoice['rewards_discount_amount'] ?? null;
        if ($discount === null || $discount === '') {
            return $amount;
        }
        $discount = self::decimal((string)$discount);
        $scale = max(self::scaleOf($amount), self::scaleOf($discount));
        $net = bcsub($amount, $discount, $scale);
        if (bccomp($net, '0', $scale) < 0) {
            $net = bcadd('0', '0', $scale);
        }
        return $net;
    }

    /**
     * Everything the payment page and the admin invoice detail need, in one
     * shape (see the design contract):
     *   enabled, percent, rewardsCurrency, canApply, applyBlockedReason,
     *   applied (null | username, accountId, discountAmount, currency, micro,
     *   display, state, fullCoverage), effectiveAmount, originalAmount,
     *   currency, earned (null | micro, currency, display, fiatDisplay, sats,
     *   claimable, claimDeadline, claimedTo, claimedAt).
     */
    public static function invoiceView(array $invoice, ?array $store = null): array {
        $store = $store ?? Config::getStore((string)($invoice['store_id'] ?? '')) ?? [];
        $s = Rewards::settingsFromRow($store);
        $blocked = self::applyBlockedReason($invoice, $store);
        return [
            'enabled' => Rewards::isEnabled($store),
            'percent' => $s['percent'],
            'rewardsCurrency' => $s['currency'],
            'canApply' => $blocked === null,
            'applyBlockedReason' => $blocked,
            'applied' => self::appliedBlock($invoice, $s),
            'effectiveAmount' => self::effectiveAmount($invoice),
            'originalAmount' => (string)($invoice['amount'] ?? '0'),
            'currency' => (string)($invoice['currency'] ?? ''),
            'earned' => self::earnedBlock($invoice, $store, $s),
        ];
    }

    /**
     * The `rewards` block of Invoice::formatForApi and of the webhook payload;
     * null when nothing rewards-related happened on the invoice (nothing
     * applied — a released reservation of an unpaid invoice counts as
     * nothing — and nothing earned).
     */
    public static function apiBlock(array $invoice): ?array {
        $applied = self::spendIsActive($invoice);
        $earnedMicro = (int)($invoice['rewards_earned_micro'] ?? 0);
        if (!$applied && $earnedMicro <= 0) {
            return null;
        }
        $acct = !empty($invoice['rewards_account_id']) ? Rewards::getAccountById((string)$invoice['rewards_account_id']) : null;
        $earnCur = $earnedMicro > 0 ? self::earnCurrency($invoice) : null;
        $claimed = $earnedMicro > 0 && $invoice['rewards_claimed_at'] !== null;
        return [
            'appliedAmount' => $applied ? (string)$invoice['rewards_discount_amount'] : null,
            'appliedCurrency' => $applied ? (string)$invoice['currency'] : null,
            'appliedUsername' => $applied && $acct ? (string)$acct['username'] : null,
            'fullCoverage' => $applied && self::isFullCoverage($invoice),
            'earnedAmount' => $earnedMicro > 0 ? Rewards::fromMicro($earnedMicro, (string)$earnCur) : null,
            'earnedCurrency' => $earnCur,
            'earnedClaimed' => $claimed,
            'earnedUsername' => $claimed && $acct ? (string)$acct['username'] : null,
        ];
    }

    // ---------------- Apply / remove / claim ----------------

    /**
     * Apply the payer's whole balance to a New invoice, capped at the invoice
     * amount. Partial coverage reserves the balance, records the discount and
     * re-prices every rail at the effective amount; full coverage reserves,
     * zeroes amount_sats and settles the invoice on the 'rewards' rail.
     *
     * @return array{ok:bool, error:?string, status:string, fullCoverage:bool, applied:?array}
     *         error is one of the ERR_* strings (payer-facing); a blocked
     *         invoice also carries 'reason' (applyBlockedReason).
     */
    public static function applyToInvoice(string $invoiceId, string $username): array {
        require_once __DIR__ . '/invoice.php';
        $invoice = Invoice::getById($invoiceId);
        if (!$invoice) {
            return self::applyFailure(self::ERR_NOT_APPLICABLE, 'Unknown');
        }
        $store = Config::getStore((string)$invoice['store_id']);
        if (!$store) {
            return self::applyFailure(self::ERR_NOT_APPLICABLE, (string)$invoice['status']);
        }
        $blocked = self::applyBlockedReason($invoice, $store);
        if ($blocked !== null) {
            $out = self::applyFailure(self::ERR_NOT_APPLICABLE, (string)$invoice['status']);
            $out['reason'] = $blocked;
            return $out;
        }
        try {
            $name = Rewards::normalizeUsername($username);
        } catch (InvalidArgumentException $e) {
            return self::applyFailure($e->getMessage(), (string)$invoice['status']);
        }
        $storeId = (string)$invoice['store_id'];
        $account = Rewards::getAccount($storeId, $name['display']);
        // Unknown username and an empty balance read the same to the payer:
        // there is nothing here to apply (and no hint whether a name exists).
        if (!$account || (int)$account['balance_micro'] <= 0) {
            return self::applyFailure(self::ERR_NO_REWARDS, (string)$invoice['status']);
        }
        $s = Rewards::settingsFromRow($store);
        $rewardsCur = (string)$s['currency'];
        $invCur = Rewards::normalizeCurrency((string)$invoice['currency']);
        $balance = (int)$account['balance_micro'];

        // The discount in the invoice currency: the whole balance, floored to
        // the currency's natural precision, capped at the invoice amount.
        $discount = Rewards::microToAmount($balance, $rewardsCur, $invCur, $store);
        if ($discount === null) {
            return self::applyFailure(self::ERR_RETRY, (string)$invoice['status']);
        }
        $amount = self::decimal((string)$invoice['amount']);
        $scale = max(self::scaleOf($amount), self::scaleOf($discount));
        $full = bccomp($discount, $amount, $scale) >= 0;
        if ($full) {
            $discount = $amount;
        }
        if (bccomp($discount, '0', $scale) <= 0) {
            return self::applyFailure(self::ERR_TOO_SMALL, (string)$invoice['status']);
        }
        // Reserve exactly what the discount taken is worth in the rewards
        // currency — the floor above means this is never more than the
        // balance; the fraction left over stays spendable.
        $reserve = self::amountToRewardsMicro($discount, $invCur, $rewardsCur, $store);
        if ($reserve === null) {
            return self::applyFailure(self::ERR_RETRY, (string)$invoice['status']);
        }
        $reserve = min($reserve, $balance);
        if ($reserve <= 0) {
            return self::applyFailure(self::ERR_TOO_SMALL, (string)$invoice['status']);
        }

        // Reservation + invoice columns in one write transaction, re-checking
        // the invoice under the lock (another tab may have applied / paid).
        Database::beginImmediate();
        try {
            $fresh = Invoice::getById($invoiceId);
            if (!$fresh || $fresh['status'] !== 'New' || self::spendIsActive($fresh)) {
                Database::rollback();
                return self::applyFailure(self::ERR_NOT_APPLICABLE, (string)($fresh['status'] ?? 'Unknown'));
            }
            if (!Rewards::reserve((string)$account['id'], $invoiceId, $reserve, $rewardsCur)) {
                Database::rollback();
                return self::applyFailure(self::ERR_RETRY, 'New');
            }
            $updates = [
                'rewards_account_id' => (string)$account['id'],
                'rewards_discount_amount' => $discount,
                'rewards_applied_micro' => $reserve,
                'rewards_spend_state' => Rewards::SPEND_RESERVED,
            ];
            if ($full) {
                // Nothing is owed on any rail any more (operator decision 5).
                $updates['amount_sats'] = 0;
            }
            $n = Database::update('invoices', $updates, "id = ? AND status = 'New'", [$invoiceId]);
            if ($n !== 1) {
                Database::rollback();
                return self::applyFailure(self::ERR_NOT_APPLICABLE, 'Unknown');
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            error_log("[rewards] apply failed for {$invoiceId}: " . $e->getMessage());
            return self::applyFailure(self::ERR_RETRY, 'New');
        }
        InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_REWARDS, 'rewards', null,
            InvoiceAttempts::OK, null, sprintf(
                'rewards applied: -%s %s (%s) from account %s%s',
                $discount, $invCur, Rewards::formatMicro($reserve, $rewardsCur), $account['username'],
                $full ? ' - full coverage, settled on the rewards rail' : ''
            ));

        if ($full) {
            $settled = Invoice::updateStatus($invoiceId, 'Settled', null, Rewards::RAIL, ['New']);
            $row = Invoice::getById($invoiceId) ?? $fresh;
            if (!$settled && ($row['status'] ?? '') !== 'Settled') {
                // Closed by another path (expiry) in the same instant; its
                // hook already handled the reservation.
                return self::applyFailure(self::ERR_RETRY, (string)($row['status'] ?? 'Unknown'));
            }
            return [
                'ok' => true, 'error' => null, 'status' => 'Settled', 'fullCoverage' => true,
                'applied' => self::appliedBlock($row, $s),
            ];
        }

        // Partial: every rail is re-priced at the effective amount. A
        // Lightning rail that cannot be re-sourced leaves the payer with a
        // bolt11 for the full amount, so the apply is undone instead.
        if (!self::reprice($invoiceId, self::effectiveAmount(Invoice::getById($invoiceId) ?? $fresh))) {
            self::undoApply($invoiceId, (string)$account['id'], $rewardsCur, 'apply undone: the invoice could not be re-priced');
            InvoiceAttempts::record($invoiceId, $storeId, InvoiceAttempts::CONTEXT_REWARDS, 'rewards', null,
                InvoiceAttempts::FAILED, 'the invoice could not be re-priced after applying rewards',
                'rewards apply rolled back: Lightning could not be re-sourced at the discounted amount');
            return self::applyFailure(self::ERR_RETRY, 'New');
        }
        $row = Invoice::getById($invoiceId) ?? $fresh;
        return [
            'ok' => true, 'error' => null, 'status' => (string)$row['status'], 'fullCoverage' => false,
            'applied' => self::appliedBlock($row, $s),
        ];
    }

    /**
     * The payer's undo before paying: re-source every rail at the full
     * amount first (so the page never shows a discount the ledger no longer
     * holds), then hand the reservation back and clear the apply columns.
     * The reserve/release ledger pair is forgotten so the invoice can be
     * applied to again.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function removeFromInvoice(string $invoiceId): array {
        require_once __DIR__ . '/invoice.php';
        $invoice = Invoice::getById($invoiceId);
        if (!$invoice || $invoice['status'] !== 'New'
                || ($invoice['rewards_spend_state'] ?? null) !== Rewards::SPEND_RESERVED
                || empty($invoice['rewards_account_id'])) {
            return ['ok' => false, 'error' => self::ERR_NOT_APPLIED];
        }
        $store = Config::getStore((string)$invoice['store_id']) ?? [];
        $s = Rewards::settingsFromRow($store);
        $rewardsCur = (string)($s['currency'] ?? 'sat');
        $accountId = (string)$invoice['rewards_account_id'];
        $account = Rewards::getAccountById($accountId);

        if (!self::reprice($invoiceId, self::decimal((string)$invoice['amount']))) {
            return ['ok' => false, 'error' => self::ERR_REMOVE_RETRY];
        }

        Database::beginImmediate();
        try {
            $fresh = Invoice::getById($invoiceId);
            if (!$fresh || $fresh['status'] !== 'New' || ($fresh['rewards_spend_state'] ?? null) !== Rewards::SPEND_RESERVED) {
                Database::rollback();
                return ['ok' => false, 'error' => self::ERR_NOT_APPLIED];
            }
            Rewards::release($accountId, $invoiceId, $rewardsCur, 'removed by the payer');
            Rewards::forgetReservation($invoiceId);
            $n = Database::update('invoices', [
                'rewards_account_id' => null,
                'rewards_discount_amount' => null,
                'rewards_applied_micro' => null,
                'rewards_spend_state' => null,
            ], "id = ? AND status = 'New'", [$invoiceId]);
            if ($n !== 1) {
                Database::rollback();
                return ['ok' => false, 'error' => self::ERR_NOT_APPLIED];
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            error_log("[rewards] remove failed for {$invoiceId}: " . $e->getMessage());
            return ['ok' => false, 'error' => self::ERR_REMOVE_RETRY];
        }
        InvoiceAttempts::record($invoiceId, (string)$invoice['store_id'], InvoiceAttempts::CONTEXT_REWARDS, 'rewards', null,
            InvoiceAttempts::OK, null, sprintf(
                'rewards removed: %s %s (%s) returned to account %s',
                (string)$invoice['rewards_discount_amount'], Rewards::normalizeCurrency((string)$invoice['currency']),
                Rewards::formatMicro((int)$invoice['rewards_applied_micro'], $rewardsCur),
                $account['username'] ?? $accountId
            ));
        return ['ok' => true, 'error' => null];
    }

    /**
     * Claim an unclaimed earn on a Settled invoice within CLAIM_WINDOW_SEC of
     * paid_at. Creates the account when the username is new. Idempotent: a
     * second claim with the same username answers the same credit; another
     * username is refused.
     *
     * @return array{ok:bool, error:?string, credited?:array{micro:int, display:string, username:string}}
     */
    public static function claimEarned(string $invoiceId, string $username): array {
        require_once __DIR__ . '/invoice.php';
        $invoice = Invoice::getById($invoiceId);
        $earned = (int)($invoice['rewards_earned_micro'] ?? 0);
        if (!$invoice || $invoice['status'] !== 'Settled' || $earned <= 0) {
            return ['ok' => false, 'error' => self::ERR_NOTHING_TO_CLAIM];
        }
        try {
            $name = Rewards::normalizeUsername($username);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $cur = self::earnCurrency($invoice);
        if ($invoice['rewards_claimed_at'] !== null) {
            return self::claimAnswerForClaimed($invoice, $name['key'], $earned, $cur);
        }
        $deadline = (int)($invoice['paid_at'] ?? 0) + Rewards::CLAIM_WINDOW_SEC;
        if ((int)($invoice['paid_at'] ?? 0) <= 0 || time() > $deadline) {
            return ['ok' => false, 'error' => self::ERR_CLAIM_CLOSED];
        }
        try {
            $account = Rewards::getOrCreateAccount((string)$invoice['store_id'], $name['display']);
        } catch (Throwable $e) {
            error_log("[rewards] claim account failed for {$invoiceId}: " . $e->getMessage());
            return ['ok' => false, 'error' => self::ERR_CLAIM_RETRY];
        }
        Database::beginImmediate();
        try {
            // The CAS on rewards_claimed_at decides who claims; the credit
            // itself is additionally guarded by the ledger's (invoice, earn)
            // uniqueness.
            $n = Database::update('invoices', [
                'rewards_account_id' => (string)$account['id'],
                'rewards_claimed_at' => time(),
            ], "id = ? AND status = 'Settled' AND rewards_claimed_at IS NULL", [$invoiceId]);
            if ($n !== 1) {
                Database::rollback();
                $again = Invoice::getById($invoiceId);
                return $again ? self::claimAnswerForClaimed($again, $name['key'], $earned, $cur)
                    : ['ok' => false, 'error' => self::ERR_NOTHING_TO_CLAIM];
            }
            Rewards::credit((string)$account['id'], $invoiceId, $earned, $cur);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            error_log("[rewards] claim failed for {$invoiceId}: " . $e->getMessage());
            return ['ok' => false, 'error' => self::ERR_CLAIM_RETRY];
        }
        return [
            'ok' => true, 'error' => null,
            'credited' => [
                'micro' => $earned,
                'display' => Rewards::formatMicro($earned, $cur),
                'username' => (string)$account['username'],
            ],
        ];
    }

    // ---------------- Settlement hooks ----------------

    /**
     * The invoice just flipped to Settled: commit its reservation, compute
     * the earn on the NET amount paid and, when an account is tied to the
     * invoice, credit it right away. Must run BEFORE the InvoiceSettled
     * webhook payload is built (the payload is frozen at enqueue and carries
     * the rewards block). Joins the caller's open transaction when there is
     * one; idempotent; never throws.
     */
    public static function onSettled(string $invoiceId): void {
        try {
            $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
            if (!$invoice || $invoice['status'] !== 'Settled') {
                return;
            }
            $hasSpend = !empty($invoice['rewards_spend_state']);
            $store = Config::getStore((string)$invoice['store_id']);
            // Nothing applied and rewards off at this store: nothing to do
            // (and no transaction to open) for the overwhelmingly common case.
            if (!$hasSpend && !Rewards::isEnabled($store) && $invoice['rewards_earned_micro'] === null) {
                return;
            }
            $s = Rewards::settingsFromRow($store);
            $rewardsCur = (string)($s['currency'] ?? self::earnCurrency($invoice));
            $accountId = !empty($invoice['rewards_account_id']) ? (string)$invoice['rewards_account_id'] : null;

            $owns = !Database::inTransaction();
            if ($owns) {
                Database::beginImmediate();
            }
            try {
                // 1. Reservation -> spend.
                if ($accountId !== null && ($invoice['rewards_spend_state'] ?? null) === Rewards::SPEND_RESERVED) {
                    $committed = Rewards::commit($accountId, $invoiceId, $rewardsCur) || Rewards::hasKind($invoiceId, Rewards::KIND_COMMIT);
                    if ($committed) {
                        Database::update('invoices', ['rewards_spend_state' => Rewards::SPEND_COMMITTED],
                            'id = ? AND rewards_spend_state = ?', [$invoiceId, Rewards::SPEND_RESERVED]);
                    } else {
                        // Released before this settlement (the invoice expired
                        // and was paid late): the balance went back to the
                        // payer and the merchant collected the discounted
                        // amount. Rare; worth an operator-visible log line.
                        error_log(sprintf(
                            '[rewards] invoice=%s settled with a reservation that was already released; discount %s %s not charged to account %s',
                            $invoiceId, (string)$invoice['rewards_discount_amount'], (string)$invoice['currency'], $accountId
                        ));
                    }
                }
                // 2. The earn, once.
                if ($invoice['rewards_earned_micro'] === null) {
                    $earn = self::computeEarn($invoice, $store, $s);
                    if ($earn !== null) {
                        $n = Database::update('invoices', [
                            'rewards_earned_micro' => $earn,
                            'rewards_earn_currency' => $s['currency'] ?? $rewardsCur,
                        ], 'id = ? AND rewards_earned_micro IS NULL', [$invoiceId]);
                        if ($n === 1) {
                            $invoice['rewards_earned_micro'] = $earn;
                            $invoice['rewards_earn_currency'] = $s['currency'] ?? $rewardsCur;
                        }
                    } else {
                        error_log("[rewards] invoice={$invoiceId}: earn not computed (no exchange rate)");
                    }
                }
                // 3. Account known -> credit now (operator decision 15).
                $earned = (int)($invoice['rewards_earned_micro'] ?? 0);
                if ($earned > 0 && $accountId !== null && $invoice['rewards_claimed_at'] === null) {
                    Rewards::credit($accountId, $invoiceId, $earned, self::earnCurrency($invoice));
                    Database::update('invoices', ['rewards_claimed_at' => time()],
                        'id = ? AND rewards_claimed_at IS NULL', [$invoiceId]);
                }
                if ($owns) {
                    Database::commit();
                }
            } catch (Throwable $e) {
                if ($owns) {
                    Database::rollback();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            error_log("[rewards] onSettled failed for {$invoiceId}: " . $e->getMessage());
        }
    }

    /**
     * The invoice closed: Expired / Invalid from New hands an open
     * reservation back; Invalid after Settled (zero-conf reversal) takes a
     * credited earn back. Idempotent; never throws.
     */
    public static function onClosed(string $invoiceId, string $newStatus, ?string $previousStatus): void {
        if (!in_array($newStatus, ['Expired', 'Invalid'], true)) {
            return;
        }
        try {
            $invoice = Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
            if (!$invoice || empty($invoice['rewards_account_id'])) {
                return;
            }
            $accountId = (string)$invoice['rewards_account_id'];
            $store = Config::getStore((string)$invoice['store_id']);
            $s = Rewards::settingsFromRow($store);
            $cur = (string)($s['currency'] ?? self::earnCurrency($invoice));
            if (($invoice['rewards_spend_state'] ?? null) === Rewards::SPEND_RESERVED) {
                $released = Rewards::release($accountId, $invoiceId, $cur, 'invoice ' . strtolower($newStatus))
                    || Rewards::hasKind($invoiceId, Rewards::KIND_RELEASE);
                if ($released) {
                    Database::update('invoices', ['rewards_spend_state' => Rewards::SPEND_RELEASED],
                        'id = ? AND rewards_spend_state = ?', [$invoiceId, Rewards::SPEND_RESERVED]);
                }
            }
            // paid_at is only ever stamped by a settlement, so it tells a
            // reversal apart from a plain expiry even when the caller did
            // not know the previous status.
            $wasSettled = $previousStatus === 'Settled' || !empty($invoice['paid_at']);
            if ($newStatus === 'Invalid' && $wasSettled && (int)($invoice['rewards_earned_micro'] ?? 0) > 0
                    && $invoice['rewards_claimed_at'] !== null) {
                Rewards::reverseEarn($accountId, $invoiceId, self::earnCurrency($invoice));
            }
        } catch (Throwable $e) {
            error_log("[rewards] onClosed failed for {$invoiceId}: " . $e->getMessage());
        }
    }

    // ---------------- Internals ----------------

    /**
     * Re-price every rail of a New invoice at $amount (invoice currency):
     * Lightning through Invoice::refreshLightning (parking the old bolt11),
     * then on-chain / eCash. False when the Lightning side could not follow
     * — the caller undoes or refuses. An invoice without any Lightning rail
     * just has its plain sats figure moved (nothing ties amount_sats to a
     * bolt11), mirroring Invoice::requoteForView.
     */
    private static function reprice(string $invoiceId, string $amount): bool {
        $invoice = Invoice::getById($invoiceId);
        if (!$invoice || $invoice['status'] !== 'New') {
            return false;
        }
        $res = Invoice::refreshLightning($invoiceId, ['rewards' => true, 'amount_override' => $amount]);
        if (empty($res['ok'])) {
            $reason = (string)($res['reason'] ?? '');
            if ($reason !== 'unavailable' || !empty($invoice['bolt11'])) {
                error_log("[rewards] invoice={$invoiceId}: Lightning re-source failed ({$reason})");
                return false;
            }
            $store = Config::getStore((string)$invoice['store_id']) ?? [];
            $sats = self::amountToSats($amount, (string)$invoice['currency'], $store);
            if ($sats !== null) {
                Database::update('invoices', ['amount_sats' => $sats],
                    "id = ? AND status = 'New' AND bolt11 IS NULL", [$invoiceId]);
            }
        }
        Invoice::reamountChainsForRewards($invoiceId, $amount);
        return true;
    }

    /** Roll an apply back while the invoice is still New (see applyToInvoice). */
    private static function undoApply(string $invoiceId, string $accountId, string $rewardsCur, string $note): void {
        try {
            Database::beginImmediate();
            try {
                $row = Invoice::getById($invoiceId);
                if (!$row || $row['status'] !== 'New') {
                    // Settled meanwhile: onSettled committed the reservation
                    // against the payment that arrived; nothing to undo.
                    Database::rollback();
                    return;
                }
                Rewards::release($accountId, $invoiceId, $rewardsCur, $note);
                Rewards::forgetReservation($invoiceId);
                Database::update('invoices', [
                    'rewards_account_id' => null,
                    'rewards_discount_amount' => null,
                    'rewards_applied_micro' => null,
                    'rewards_spend_state' => null,
                ], "id = ? AND status = 'New'", [$invoiceId]);
                Database::commit();
            } catch (Throwable $e) {
                Database::rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log("[rewards] undo of a failed apply did not complete for {$invoiceId}: " . $e->getMessage());
        }
    }

    /**
     * The earn for a settled invoice in micro of the rewards currency, or
     * null when it cannot be computed (no exchange rate). Zero for tip jars
     * and stores whose rewards are off, and for a fully covered invoice.
     */
    private static function computeEarn(array $invoice, ?array $store, array $s): ?int {
        if (!Rewards::isEnabled($store) || !empty($invoice['tip_jar_id'])) {
            return 0;
        }
        $net = self::effectiveAmount($invoice);
        if (bccomp($net, '0', self::scaleOf($net)) <= 0) {
            return 0;
        }
        $rate = isset($invoice['exchange_rate']) && (float)$invoice['exchange_rate'] > 0 ? (float)$invoice['exchange_rate'] : null;
        $netMicro = self::amountToRewardsMicro($net, (string)$invoice['currency'], (string)$s['currency'], $store, $rate);
        if ($netMicro === null) {
            return null;
        }
        return Rewards::earnMicro($netMicro, (float)$s['percent']);
    }

    /**
     * An amount in the invoice currency -> micro of the rewards currency.
     * Same currency: exact. Otherwise via sats: a fiat invoice amount at the
     * invoice's own rate when given (what the payer was quoted), else at the
     * current rate; sats then into a fiat rewards currency at the current
     * rate. Null when a needed rate is missing.
     */
    private static function amountToRewardsMicro(string $amount, string $invoiceCurrency, string $rewardsCurrency, ?array $store, ?float $invoiceRate = null): ?int {
        $invCur = Rewards::normalizeCurrency($invoiceCurrency);
        $rewCur = Rewards::normalizeCurrency($rewardsCurrency);
        if ($invCur === $rewCur) {
            return Rewards::toMicro($amount);
        }
        $sats = self::amountToSats($amount, $invCur, $store, $invoiceRate);
        if ($sats === null) {
            return null;
        }
        if ($rewCur === 'sat') {
            return $sats * Rewards::MICRO;
        }
        return Rewards::satsToMicro($sats, $rewCur, $store);
    }

    /**
     * Invoice-currency amount -> whole sats (floor). sat/BTC/msat need no
     * rate; fiat uses $rate (fiat per BTC) when given, else the current
     * rate. Null when no rate is available.
     */
    private static function amountToSats(string $amount, string $currency, ?array $store, ?float $rate = null): ?int {
        $cur = strtoupper(Rewards::normalizeCurrency($currency));
        $amount = self::decimal($amount);
        if (in_array($cur, ['SAT', 'SATS', 'BTC', 'MSAT'], true)) {
            return (int)ExchangeRates::convertToSats($amount, $cur, 'sat');
        }
        if ($rate !== null && $rate > 0) {
            $btc = bcdiv($amount, number_format($rate, 12, '.', ''), 16);
            return (int)bcmul($btc, '100000000', 0);
        }
        try {
            $price = ExchangeRates::getBtcPrice($cur, $store['price_provider_primary'] ?? null, $store['price_provider_secondary'] ?? null);
        } catch (Throwable $e) {
            return null;
        }
        if ($price === null || $price <= 0) {
            return null;
        }
        $btc = bcdiv($amount, number_format($price, 12, '.', ''), 16);
        return (int)bcmul($btc, '100000000', 0);
    }

    private static function appliedBlock(array $invoice, array $s): ?array {
        if (!self::spendIsActive($invoice)) {
            return null;
        }
        $acct = !empty($invoice['rewards_account_id']) ? Rewards::getAccountById((string)$invoice['rewards_account_id']) : null;
        $micro = (int)($invoice['rewards_applied_micro'] ?? 0);
        $cur = (string)($s['currency'] ?? 'sat');
        return [
            'username' => (string)($acct['username'] ?? ''),
            'accountId' => (string)($invoice['rewards_account_id'] ?? ''),
            'discountAmount' => (string)$invoice['rewards_discount_amount'],
            'currency' => (string)$invoice['currency'],
            'micro' => $micro,
            'display' => Rewards::formatMicro($micro, $cur),
            'state' => (string)$invoice['rewards_spend_state'],
            'fullCoverage' => self::isFullCoverage($invoice),
        ];
    }

    private static function earnedBlock(array $invoice, array $store, array $s): ?array {
        $micro = (int)($invoice['rewards_earned_micro'] ?? 0);
        if ($micro <= 0) {
            return null;
        }
        $cur = self::earnCurrency($invoice);
        $claimed = $invoice['rewards_claimed_at'] !== null;
        $paidAt = (int)($invoice['paid_at'] ?? 0);
        $deadline = $paidAt > 0 ? $paidAt + Rewards::CLAIM_WINDOW_SEC : null;
        $acct = $claimed && !empty($invoice['rewards_account_id'])
            ? Rewards::getAccountById((string)$invoice['rewards_account_id']) : null;
        // A fiat equivalent in the store's default currency when that is a
        // fiat currency other than the rewards currency (a sat store shows
        // "12 sats (≈ 0.01 USD)"); rate outages leave it null.
        $disp = Rewards::normalizeCurrency((string)($store['default_currency'] ?? $store['mint_unit'] ?? 'sat'));
        $fiat = null;
        if ($disp !== 'sat' && $disp !== $cur) {
            $amt = Rewards::microToAmount($micro, $cur, $disp, $store);
            $fiat = $amt !== null ? $amt . ' ' . $disp : null;
        }
        return [
            'micro' => $micro,
            'currency' => $cur,
            'display' => Rewards::formatMicro($micro, $cur),
            'fiatDisplay' => $fiat,
            'sats' => Rewards::microToSats($micro, $cur, $store),
            'claimable' => ($invoice['status'] ?? '') === 'Settled' && !$claimed && $deadline !== null && $deadline > time(),
            'claimDeadline' => $deadline,
            'claimedTo' => $acct ? (string)$acct['username'] : null,
            'claimedAt' => $claimed ? (int)$invoice['rewards_claimed_at'] : null,
        ];
    }

    private static function claimAnswerForClaimed(array $invoice, string $usernameKey, int $earned, string $cur): array {
        $acct = !empty($invoice['rewards_account_id']) ? Rewards::getAccountById((string)$invoice['rewards_account_id']) : null;
        if ($acct && (string)$acct['username_key'] === $usernameKey) {
            return [
                'ok' => true, 'error' => null,
                'credited' => ['micro' => $earned, 'display' => Rewards::formatMicro($earned, $cur), 'username' => (string)$acct['username']],
            ];
        }
        return ['ok' => false, 'error' => self::ERR_ALREADY_CLAIMED];
    }

    /** Rewards are applied and not handed back (reserved or committed). */
    private static function spendIsActive(array $invoice): bool {
        return in_array($invoice['rewards_spend_state'] ?? null, [Rewards::SPEND_RESERVED, Rewards::SPEND_COMMITTED], true);
    }

    private static function isFullCoverage(array $invoice): bool {
        if (($invoice['settled_rail'] ?? null) === Rewards::RAIL) {
            return true;
        }
        $net = self::effectiveAmount($invoice);
        return !empty($invoice['rewards_discount_amount']) && bccomp($net, '0', self::scaleOf($net)) === 0;
    }

    /** The currency an earn was recorded in (falls back to the store's rewards currency). */
    private static function earnCurrency(array $invoice): string {
        if (!empty($invoice['rewards_earn_currency'])) {
            return Rewards::normalizeCurrency((string)$invoice['rewards_earn_currency']);
        }
        $s = Rewards::forStore((string)$invoice['store_id']);
        return (string)($s['currency'] ?? 'sat');
    }

    private static function applyFailure(string $error, string $status): array {
        return ['ok' => false, 'error' => $error, 'status' => $status, 'fullCoverage' => false, 'applied' => null];
    }

    /** Plain decimal spelling for bcmath ("1.5E-5" and ints included). */
    private static function decimal(string $v): string {
        $v = trim($v);
        if ($v === '' || !is_numeric($v)) {
            return '0';
        }
        if (stripos($v, 'e') !== false) {
            $v = rtrim(rtrim(number_format((float)$v, 12, '.', ''), '0'), '.');
            return $v === '' || $v === '-' ? '0' : $v;
        }
        return $v[0] === '.' ? '0' . $v : $v;
    }

    private static function scaleOf(string $v): int {
        $dot = strpos($v, '.');
        return $dot === false ? 0 : strlen($v) - $dot - 1;
    }
}
