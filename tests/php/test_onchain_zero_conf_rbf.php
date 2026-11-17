<?php
/**
 * Zero-conf hardening for the on-chain rail (onchain_min_confs = 0).
 *
 *  1. An unconfirmed payment that signals BIP-125 replace-by-fee does NOT
 *     settle a zero-conf invoice: it is detected (New -> Processing) but
 *     counts as pending until it has a confirmation; a non-RBF mempool
 *     payment still settles at once.
 *  2. An invoice settled on a mempool-only payment stays under watch: it is
 *     returned by pollPending() while its payment has no confirmation, and
 *     when the payment vanishes (replaced / double-spent / evicted) the
 *     invoice flips Settled -> Invalid and fires InvoiceInvalid. A payment
 *     that confirms instead leaves the invoice Settled and ends the watch.
 *
 * Driven through pollInvoice()/pollPending() with the scripted provider seam
 * (OnchainProviderFactory::$testProvider); no network.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

fresh_db();
make_store('s1');
Database::query("UPDATE stores SET onchain_min_confs = 0, onchain_address_mode = 'xpub' WHERE id = 's1'");

function mk_invoice(string $id, string $addr, int $sats = 50000): void {
    Database::insert('invoices', [
        'id' => $id, 'store_id' => 's1', 'status' => 'New',
        'amount' => (string)$sats, 'currency' => 'sat', 'amount_sats' => $sats,
        'payment_rail' => 'onchain',
        'onchain_address' => $addr, 'onchain_amount_sat' => $sats,
        'created_at' => time(), 'expiration_time' => time() + 3600,
    ]);
}
function status_of(string $id): string {
    return (string)Database::fetchOne("SELECT status FROM invoices WHERE id = ?", [$id])['status'];
}
function events_for(string $id): array {
    $rows = Database::fetchAll(
        "SELECT event_type FROM webhook_deliveries WHERE invoice_id = ? ORDER BY rowid",
        [$id]
    );
    return array_map(fn($r) => $r['event_type'], $rows);
}

$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 800000;
    public function addressTransactions(string $address, ?int $sinceHeight = null): array { return $this->obs; }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

$T = str_repeat('a', 64);
$U = str_repeat('b', 64);
$V = str_repeat('c', 64);

// ---- 1. RBF-signalling mempool payment: detected, not settled ----------------
mk_invoice('rbf', 'addr_rbf');
$fake->obs = [new OnchainTxObservation($T, 0, 50000, 0, null, replaceable: true)];
$r = OnchainPayments::pollInvoice('rbf');
assert_eq('Processing', $r['status'], 'RBF payment is detected (Processing)…');
assert_eq(0, $r['total_confirmed'], '…but counts as pending, not confirmed');
assert_eq(50000, $r['total_pending'], 'pending total carries the amount');
assert_eq('Processing', status_of('rbf'), 'not Settled at zero-conf');

// Same payment, still RBF, still unconfirmed on a later poll: unchanged.
$r = OnchainPayments::pollInvoice('rbf');
assert_eq('Processing', $r['status'], 'stays Processing while unconfirmed');

// Once mined (1 conf) the RBF signal no longer matters: settles.
$fake->obs = [new OnchainTxObservation($T, 0, 50000, 1, 800000, replaceable: true)];
$r = OnchainPayments::pollInvoice('rbf');
assert_eq('Settled', $r['status'], 'RBF payment settles once it has a confirmation');
assert_eq(50000, $r['total_confirmed'], 'confirmed total after mining');

// ---- control: a non-RBF mempool payment settles immediately ----------------
mk_invoice('plain', 'addr_plain');
$fake->obs = [new OnchainTxObservation($U, 0, 50000, 0, null, replaceable: false)];
$r = OnchainPayments::pollInvoice('plain');
assert_eq('Settled', $r['status'], 'non-RBF mempool payment settles at zero-conf');
assert_eq('onchain', Database::fetchOne("SELECT settled_rail FROM invoices WHERE id = 'plain'")['settled_rail'], 'settled_rail recorded');

// ---- 2. the settled zero-conf invoice is kept under watch ----------------------
// Fresh (never polled by cron): pollPending must pick it up.
Database::query("UPDATE invoices SET onchain_last_polled_at = NULL WHERE id = 'plain'");
Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'rbf'", [time()]); // throttled out
$pending = OnchainPayments::pollPending(60, 20);
assert_true(array_key_exists('plain', $pending), 'settled zero-conf invoice with an unconfirmed payment is polled');
assert_eq('Settled', status_of('plain'), 'payment still in the mempool: stays Settled');

// Throttle applies to the watch too.
$pending = OnchainPayments::pollPending(60, 20);
assert_false(array_key_exists('plain', $pending), 'watch respects the poll interval');

// The payment disappears (replaced / double-spent). One miss alone is only
// remembered (a provider hiccup must not cancel a paid order)…
Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'plain'", [time() - 120]);
$fake->obs = [];
$pending = OnchainPayments::pollPending(60, 20);
assert_true(array_key_exists('plain', $pending), 'watched invoice polled when due');
assert_eq('Settled', $pending['plain']['status'], 'first miss: still Settled');
assert_eq(
    OnchainPayments::ZERO_CONF_MISSING_STATUS,
    Database::fetchOne("SELECT additional_status FROM invoices WHERE id = 'plain'")['additional_status'],
    'first miss is stamped on additional_status'
);
// …and a payment that comes back clears the strike…
$fake->obs = [new OnchainTxObservation($U, 0, 50000, 0, null, replaceable: false)];
Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'plain'", [time() - 120]);
$pending = OnchainPayments::pollPending(60, 20);
assert_eq('Settled', $pending['plain']['status'], 'payment back: still Settled');
assert_null(
    Database::fetchOne("SELECT additional_status FROM invoices WHERE id = 'plain'")['additional_status'],
    'strike cleared when the payment reappears'
);
// …while two consecutive misses reverse the settlement: Settled -> Invalid + InvoiceInvalid.
$fake->obs = [];
Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'plain'", [time() - 120]);
$pending = OnchainPayments::pollPending(60, 20);
assert_eq('Settled', $pending['plain']['status'], 'second sequence, first miss: still Settled');
Database::query("UPDATE invoices SET onchain_last_polled_at = ? WHERE id = 'plain'", [time() - 120]);
$pending = OnchainPayments::pollPending(60, 20);
assert_eq('Invalid', $pending['plain']['status'], 'vanished zero-conf payment flips the invoice to Invalid on the second miss');
assert_eq('InvoiceInvalid', $pending['plain']['transition'], 'InvoiceInvalid fired');
assert_eq('Invalid', status_of('plain'), 'persisted status is Invalid');
$log = Database::fetchOne("SELECT * FROM admin_event_log WHERE invoice_id = 'plain' AND context = 'zero_conf_reversed'");
assert_not_null($log, 'the reversal is written to the admin log');

// An Invalid invoice leaves the watch set (not Settled any more).
Database::query("UPDATE invoices SET onchain_last_polled_at = NULL WHERE id = 'plain'");
$pending = OnchainPayments::pollPending(60, 20);
assert_false(array_key_exists('plain', $pending), 'reversed invoice is not polled again');

// ---- the watch ends when the payment confirms -----------------------------------
mk_invoice('conf', 'addr_conf');
$fake->obs = [new OnchainTxObservation($V, 0, 50000, 0, null)];
assert_eq('Settled', OnchainPayments::pollInvoice('conf')['status'], 'settles at zero-conf');
Database::query("UPDATE invoices SET onchain_last_polled_at = NULL WHERE id = 'conf'");
$fake->obs = [new OnchainTxObservation($V, 0, 50000, 1, 800000)];
$pending = OnchainPayments::pollPending(60, 20);
assert_true(array_key_exists('conf', $pending), 'watched until confirmed');
assert_eq('Settled', $pending['conf']['status'], 'a confirming payment keeps the invoice Settled');
Database::query("UPDATE invoices SET onchain_last_polled_at = NULL WHERE id = 'conf'");
$pending = OnchainPayments::pollPending(60, 20);
assert_false(array_key_exists('conf', $pending), 'once confirmed the invoice leaves the watch set');

// ---- the watch is bounded in time -------------------------------------------------
mk_invoice('old', 'addr_old');
$fake->obs = [new OnchainTxObservation(str_repeat('d', 64), 0, 50000, 0, null)];
assert_eq('Settled', OnchainPayments::pollInvoice('old')['status'], 'settles at zero-conf');
Database::query(
    "UPDATE invoices SET onchain_last_polled_at = NULL, paid_at = ? WHERE id = 'old'",
    [time() - OnchainPayments::ZERO_CONF_WATCH_SEC - 60]
);
$pending = OnchainPayments::pollPending(60, 20);
assert_false(array_key_exists('old', $pending), 'a settlement older than the watch window is not re-polled');

OnchainProviderFactory::$testProvider = null;
echo "PASS test_onchain_zero_conf_rbf\n";
