<?php
/**
 * Queue drain: marks rows sent on success; on failure, increments attempts
 * and records last_error without marking sent (so the next tick retries).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/notification_sender.php';
require_once dirname(__DIR__, 2) . '/includes/email_sender.php';

Config::set('notifications_enabled', true);
Config::set('notifications_invoice_paid_enabled', true);
Config::set('notifications_to_email', 'ops@example.com');

$store = 'store_drain';
make_store($store, 'https://m.example.com');
Database::update('stores', ['notifications_enabled' => 1], 'id = ?', [$store]);

// Enqueue two invoice-paid notifications.
NotificationSender::queueInvoicePaid([
    'id' => 'inv_a', 'store_id' => $store,
    'amount' => '1.00', 'currency' => 'USD', 'amount_sats' => 2500,
]);
NotificationSender::queueInvoicePaid([
    'id' => 'inv_b', 'store_id' => $store,
    'amount' => '2.00', 'currency' => 'USD', 'amount_sats' => 5000,
]);

// First drain: transport throws → both rows recorded as failed.
EmailSender::$transportOverride = function() {
    throw new RuntimeException('SMTP transient failure');
};
$result = NotificationSender::drainQueue();
assert_eq(0, $result['sent']);
assert_eq(2, $result['failed']);

$rows = Database::fetchAll("SELECT id, attempts, sent_at, last_error FROM notification_queue ORDER BY id");
assert_eq(2, count($rows));
foreach ($rows as $r) {
    assert_eq(1, (int)$r['attempts'], 'attempts incremented to 1');
    assert_eq(null, $r['sent_at'], 'sent_at still null on failure');
    assert_true(str_contains($r['last_error'] ?? '', 'transient failure'), 'last_error captured');
}

// Second drain with a healthy transport: both rows sent.
EmailSender::$transportOverride = function($to, $subject, $body) { /* ok */ };
$result = NotificationSender::drainQueue();
assert_eq(2, $result['sent']);
assert_eq(0, $result['failed']);

$rows = Database::fetchAll("SELECT sent_at, last_error FROM notification_queue ORDER BY id");
foreach ($rows as $r) {
    assert_not_null($r['sent_at'], 'sent_at populated on success');
    assert_eq(null, $r['last_error'], 'last_error cleared on success');
}

// pendingCount reflects only unsent rows.
assert_eq(0, NotificationSender::pendingCount());

echo "test_notification_queue_drain: ok\n";
