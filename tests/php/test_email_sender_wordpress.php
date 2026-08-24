<?php
/**
 * When BareBits runs as a WordPress plugin, email delivery is delegated to
 * WordPress's wp_mail() rather than our own PHPMailer/SMTP stack — so whatever
 * the site configured (WP Mail SMTP, FluentSMTP, the host MTA) is used, and our
 * SMTP settings UI is greyed out. These pin that delegation:
 *   - send() calls wp_mail() with the recipient/subject/body, and
 *   - a wp_mail() failure becomes a RuntimeException carrying WordPress's own
 *     reason (captured off the wp_mail_failed action), and
 *   - the temporary wp_mail_failed listener is always cleaned up.
 *
 * This file defines CASHUPAY_WORDPRESS + WordPress function stubs process-wide,
 * so it must stay standalone (the runner executes each test in its own PHP
 * subprocess).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

// Simulate the WordPress plugin runtime before the sender is loaded.
define('CASHUPAY_WORDPRESS', true);

$GLOBALS['__wp_mail_calls'] = [];
$GLOBALS['__wp_mail_return'] = true;
$GLOBALS['__wp_actions'] = [];

class WP_Error_Stub {
    public function __construct(private string $code, private string $msg) {}
    public function get_error_message(): string { return $this->msg; }
}

function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
    $GLOBALS['__wp_mail_calls'][] = compact('to', 'subject', 'message');
    if ($GLOBALS['__wp_mail_return'] !== true) {
        // Mirror WP core: a failed send fires wp_mail_failed with a WP_Error.
        do_action('wp_mail_failed', new WP_Error_Stub('wp_mail_failed', 'stub SMTP down'));
    }
    return $GLOBALS['__wp_mail_return'];
}
function add_action($hook, $cb) { $GLOBALS['__wp_actions'][$hook][] = $cb; }
function remove_action($hook, $cb) {
    foreach ($GLOBALS['__wp_actions'][$hook] ?? [] as $i => $c) {
        if ($c === $cb) { unset($GLOBALS['__wp_actions'][$hook][$i]); }
    }
}
function do_action($hook, ...$args) {
    foreach ($GLOBALS['__wp_actions'][$hook] ?? [] as $cb) { $cb(...$args); }
}

require_once dirname(__DIR__, 2) . '/includes/email_sender.php';

// --- success path: delegates to wp_mail, does not throw ---
EmailSender::send('to@example.com', 'Subject line', 'Body text');
assert_eq(1, count($GLOBALS['__wp_mail_calls']), 'wp_mail called exactly once');
assert_eq('to@example.com', $GLOBALS['__wp_mail_calls'][0]['to'], 'recipient passed through');
assert_eq('Subject line', $GLOBALS['__wp_mail_calls'][0]['subject'], 'subject passed through');
assert_eq('Body text', $GLOBALS['__wp_mail_calls'][0]['message'], 'body passed through');

// listener must be cleaned up — no leaked wp_mail_failed hook after send.
assert_true(
    empty(array_filter($GLOBALS['__wp_actions']['wp_mail_failed'] ?? [])),
    'wp_mail_failed listener removed after a successful send'
);

// --- failure path: wp_mail false -> RuntimeException with WP's reason ---
$GLOBALS['__wp_mail_return'] = false;
$msg = null;
try {
    EmailSender::send('to@example.com', 'Subject', 'Body');
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
}
assert_not_null($msg, 'a wp_mail failure must throw');
assert_true(str_contains((string)$msg, 'stub SMTP down'), 'captured WordPress error surfaced: ' . $msg);
assert_true(
    empty(array_filter($GLOBALS['__wp_actions']['wp_mail_failed'] ?? [])),
    'wp_mail_failed listener removed even after a failed send'
);

echo "test_email_sender_wordpress: OK\n";
