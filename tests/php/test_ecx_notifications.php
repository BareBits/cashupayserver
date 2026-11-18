<?php
/**
 * Merchant "invoice paid" notification and payer receipt for an eCash (ECX)
 * settlement: the asset that actually moved is named (szats / ECX, not the
 * sats the invoice was quoted at), the rail is labelled "eCash (ECX)" (never
 * the raw settled_rail value), and the receipt carries the ECX receiving
 * address plus the txid(s) from ecx_payments. A Bitcoin / Lightning
 * settlement of an invoice that merely OFFERED eCash is formatted exactly as
 * before (regression guard for the shared helpers).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

if (!defined('CASHUPAY_SMTP_HOST')) {
    define('CASHUPAY_SMTP_HOST', 'smtp.test');
}
if (!defined('CASHUPAY_SMTP_FROM_ADDRESS')) {
    define('CASHUPAY_SMTP_FROM_ADDRESS', 'noreply@test');
}

$store = 'st_ecx_mail';
make_store($store, 'https://m.example.com');
Database::update('stores', ['notifications_enabled' => 1], 'id = ?', [$store]);
Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', true);
Config::set('notifications_payer_receipt_enabled', true);
Config::set('notifications_to_email', 'merchant@example.com');
$now = time();
$T = str_repeat('e', 63) . '1';

// ---- an invoice paid in eCash --------------------------------------------------
Database::insert('invoices', [
    'id' => 'inv_ecx_paid', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'ecx',
    'payment_rail' => 'onchain',
    'amount' => '10.00', 'currency' => 'USD', 'amount_sats' => 10000,
    'onchain_address' => 'bc1qbtcleg', 'onchain_amount_sat' => 10000,
    'ecx_address' => 'bc1qecxleg', 'ecx_amount_szat' => 25000000, 'ecx_exchange_rate' => 40.0,
    'ecx_first_seen_at' => $now - 60,
    'created_at' => $now - 120, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('ecx_payments', [
    'id' => 'ecxp_1', 'invoice_id' => 'inv_ecx_paid', 'txid' => $T, 'vout' => 1,
    'amount_sat' => 25000000, 'confirmations' => 2, 'block_height' => 984800,
    'first_seen_at' => $now - 60, 'last_seen_at' => $now,
]);
$inv = Invoice::getById('inv_ecx_paid');

assert_eq('25,000,000 szats (0.25 ECX)', NotificationSender::ecxAmountLine($inv), 'eCash amount line');
assert_null(NotificationSender::ecxAmountLine(['settled_rail' => 'onchain', 'ecx_amount_szat' => 5]), 'only for ecx settlements');
assert_null(NotificationSender::ecxAmountLine(['settled_rail' => 'ecx', 'ecx_amount_szat' => 0]), 'no amount -> no line');

NotificationSender::queueInvoicePaid($inv);
$row = Database::fetchOne("SELECT * FROM notification_queue WHERE invoice_id = ? AND event_type = ?", ['inv_ecx_paid', 'InvoicePaid']);
assert_not_null($row, 'merchant notification queued');
assert_true(str_contains($row['subject'], '25,000,000 szats (0.25 ECX)'), 'subject names the ECX amount: ' . $row['subject']);
assert_false(str_contains($row['subject'], '10,000 sats'), 'subject does not present the sats quote as what was received');
assert_true(str_contains($row['body'], "Amount:      25,000,000 szats (0.25 ECX)"), 'body amount line in szats: ' . $row['body']);
assert_true(str_contains($row['body'], 'Amount (fiat): 10.00 USD'), 'what the customer was billed stays');
assert_true(str_contains($row['body'], 'Paid via:    eCash (ECX)'), 'rail named, not the raw "ecx"');
assert_true(str_contains($row['body'], 'Receiving addr: bc1qecxleg (ECX chain)'), 'ECX receiving address');
assert_true(str_contains($row['body'], "Txid:           {$T}:1"), 'ECX txid:vout from ecx_payments');
assert_false(str_contains($row['body'], 'bc1qbtcleg'), 'the unpaid Bitcoin leg is not shown');
assert_false(preg_match('/\becx\b/', $row['body']) === 1, 'raw rail id never appears in the body');

// Payer receipt for the same invoice.
assert_true(NotificationSender::queuePayerReceipt($inv, 'buyer@example.com'));
$rec = Database::fetchOne("SELECT * FROM notification_queue WHERE invoice_id = ? AND event_type = ?", ['inv_ecx_paid', 'PayerReceipt']);
assert_not_null($rec, 'payer receipt queued');
assert_true(str_contains($rec['body'], 'Amount:         25,000,000 szats (0.25 ECX)'), 'receipt amount in szats: ' . $rec['body']);
assert_true(str_contains($rec['body'], 'Amount (fiat):  10.00 USD'));
assert_true(str_contains($rec['body'], 'Payment method: eCash (ECX)'), 'receipt method label');
assert_true(str_contains($rec['body'], 'Receiving addr: bc1qecxleg (ECX chain)'));
assert_true(str_contains($rec['body'], "Txid:           {$T}:1"));
assert_false(str_contains($rec['body'], '10,000 sats'), 'receipt never claims sats were received');

// ---- the same invoice shape settled over its Bitcoin leg: unchanged wording ----------
Database::insert('invoices', [
    'id' => 'inv_btc_paid', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'onchain',
    'payment_rail' => 'onchain',
    'amount' => '10.00', 'currency' => 'USD', 'amount_sats' => 10000,
    'onchain_address' => 'bc1qbtcleg2', 'onchain_amount_sat' => 10000, 'onchain_first_seen_at' => $now - 60,
    'ecx_address' => 'bc1qecxleg2', 'ecx_amount_szat' => 25000000,
    'created_at' => $now - 120, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
Database::insert('onchain_payments', [
    'id' => 'och_1', 'invoice_id' => 'inv_btc_paid', 'txid' => str_repeat('b', 64), 'vout' => 0,
    'amount_sat' => 10000, 'confirmations' => 1, 'block_height' => 900000,
    'first_seen_at' => $now - 60, 'last_seen_at' => $now,
]);
$btc = Invoice::getById('inv_btc_paid');
NotificationSender::queueInvoicePaid($btc);
$row = Database::fetchOne("SELECT * FROM notification_queue WHERE invoice_id = ? AND event_type = ?", ['inv_btc_paid', 'InvoicePaid']);
assert_true(str_contains($row['subject'], '10,000 sats'), 'bitcoin settlement: sats subject as before');
assert_false(str_contains($row['body'], 'szats'), 'no eCash wording on a bitcoin settlement');
assert_false(str_contains($row['body'], 'Paid via'), 'no extra rail line on a bitcoin settlement (unchanged template)');
assert_true(NotificationSender::queuePayerReceipt($btc, 'buyer@example.com'));
$rec = Database::fetchOne("SELECT * FROM notification_queue WHERE invoice_id = ? AND event_type = ?", ['inv_btc_paid', 'PayerReceipt']);
assert_true(str_contains($rec['body'], 'Amount:         10,000 sats'));
assert_true(str_contains($rec['body'], 'Payment method: On-chain Bitcoin'));
assert_true(str_contains($rec['body'], 'Receiving addr: bc1qbtcleg2'));
assert_false(str_contains($rec['body'], 'bc1qecxleg2'), 'the unpaid eCash leg is not on a bitcoin receipt');

// ---- Lightning settlement of an eCash-offering invoice: untouched ----------------------
Database::insert('invoices', [
    'id' => 'inv_ln_paid', 'store_id' => $store, 'status' => 'Settled', 'settled_rail' => 'mint',
    'payment_rail' => 'mint', 'mint_url' => 'https://m.example.com',
    'amount' => '5.00', 'currency' => 'USD', 'amount_sats' => 5000,
    'ecx_address' => 'bc1qecxleg3', 'ecx_amount_szat' => 12500000,
    'created_at' => $now - 120, 'expiration_time' => $now + 3600, 'paid_at' => $now,
]);
$ln = Invoice::getById('inv_ln_paid');
NotificationSender::queueInvoicePaid($ln);
$row = Database::fetchOne("SELECT * FROM notification_queue WHERE invoice_id = ? AND event_type = ?", ['inv_ln_paid', 'InvoicePaid']);
assert_true(str_contains($row['subject'], '5,000 sats'));
assert_false(str_contains($row['body'], 'ECX'), 'lightning settlement carries no eCash wording');

echo "ok\n";
