<?php
/**
 * Invoice::markExpiredInvoices with an eCash (ECX) address: an invoice that
 * offers eCash is left to the ECX chain watcher until
 * expiration_time + the store's ecx_confirm_timeout_sec (a payment broadcast
 * in the last minute must still be attributed), exactly as the Bitcoin
 * address keeps an invoice alive through onchain_confirm_timeout_sec. On a
 * dual-rail invoice BOTH windows must lapse. Expiry still fires the
 * InvoiceExpired webhook. Stores without the column value fall back to the
 * 86400 s default.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$now = time();
make_store('s_short');
make_store('s_default');
Database::update('stores', [
    'onchain_confirm_timeout_sec' => 600, 'ecx_confirm_timeout_sec' => 3600,
], 'id = ?', ['s_short']);
Database::insert('webhooks', [
    'id' => 'wh1', 'store_id' => 's_short', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'shh',
    'events' => json_encode([]), 'enabled' => 1, 'created_at' => $now,
]);

function mk(string $id, string $store, int $expiredAgo, array $extra = []): void {
    $now = time();
    Database::insert('invoices', [
        'id' => $id, 'store_id' => $store, 'status' => 'New', 'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1000,
        'created_at' => $now - $expiredAgo - 900, 'expiration_time' => $now - $expiredAgo,
    ] + $extra);
}
function status(string $id): string {
    return (string)Database::fetchOne("SELECT status FROM invoices WHERE id = ?", [$id])['status'];
}

// Expired 30 minutes ago, confirmation windows: onchain 600 s, ecx 3600 s.
mk('ln_only', 's_short', 1800);                                                   // nothing chain-watched
mk('btc_only', 's_short', 1800, ['onchain_address' => 'bc1qbtc1']);               // bitcoin window (600) lapsed
mk('ecx_only', 's_short', 1800, ['ecx_address' => 'bc1qecx1', 'payment_rail' => 'ecx']); // ecx window (3600) open
mk('dual', 's_short', 1800, ['onchain_address' => 'bc1qbtc2', 'ecx_address' => 'bc1qecx2']); // ecx keeps it alive
mk('ecx_lapsed', 's_short', 3600 + 120, ['ecx_address' => 'bc1qecx3', 'payment_rail' => 'ecx']); // ecx window lapsed
mk('ecx_default', 's_default', 3600 + 120, ['ecx_address' => 'bc1qecx4']);        // default window 86400 still open
mk('ecx_default_lapsed', 's_default', 86400 + 120, ['ecx_address' => 'bc1qecx5']);
mk('ecx_fresh', 's_short', -600, ['ecx_address' => 'bc1qecx6']);                  // not even expired yet

$n = Invoice::markExpiredInvoices();
assert_eq('Expired', status('ln_only'), 'no chain address: expires at expiration_time');
assert_eq('Expired', status('btc_only'), 'bitcoin window lapsed');
assert_eq('New', status('ecx_only'), 'eCash address keeps the invoice open through the ECX window');
assert_eq('New', status('dual'), 'dual-rail: the longer (ECX) window wins');
assert_eq('Expired', status('ecx_lapsed'), 'ECX window lapsed -> expired');
assert_eq('New', status('ecx_default'), 'store without an explicit value: 86400 s default window');
assert_eq('Expired', status('ecx_default_lapsed'), 'default window lapsed');
assert_eq('New', status('ecx_fresh'), 'unexpired invoice untouched');
assert_eq(4, $n, 'four invoices flipped');
$events = Database::fetchAll("SELECT invoice_id, event_type FROM webhook_deliveries ORDER BY invoice_id");
assert_eq(['btc_only', 'ecx_lapsed', 'ln_only'], array_column($events, 'invoice_id'), 'InvoiceExpired queued for the s_short flips');
assert_eq(['InvoiceExpired'], array_values(array_unique(array_column($events, 'event_type'))));

// Shrinking the store's ECX window retroactively expires the still-open ones.
Database::update('stores', ['ecx_confirm_timeout_sec' => 60], 'id = ?', ['s_short']);
Invoice::markExpiredInvoices();
assert_eq('Expired', status('ecx_only'));
assert_eq('Expired', status('dual'), 'both windows lapsed now');
assert_eq('New', status('ecx_fresh'));

// An ECX payment seen (Processing) is the chain watcher's business, never the sweep's.
Database::update('invoices', ['status' => 'Processing', 'ecx_first_seen_at' => time() - 10], 'id = ?', ['ecx_fresh']);
Database::update('invoices', ['expiration_time' => time() - 999999], 'id = ?', ['ecx_fresh']);
Invoice::markExpiredInvoices();
assert_eq('Processing', status('ecx_fresh'), 'Processing invoices are not swept');

echo "ok\n";
