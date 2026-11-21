<?php
/**
 * The Message-ID host of outgoing mail never comes from request data.
 *
 * PHPMailer defaults it to $_SERVER['SERVER_NAME'], which Apache fills from
 * the client's Host header, so an anonymous password-reset request sent with
 * `Host: evil.example` produced a genuine reset mail stamped
 * `Message-ID: <...@evil.example>` (seen in the Apache backend run of
 * tests/e2e/test_security_hardening.py). EmailSender::messageIdHostname()
 * picks the operator-trusted base URL's host, else the machine's own name.
 */
declare(strict_types=1);

require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/email_sender.php';

$_SERVER['SERVER_NAME'] = 'evil.example';
$_SERVER['HTTP_HOST'] = 'evil.example';

// Nothing trusted recorded: the machine name, never the request's host.
$h = EmailSender::messageIdHostname();
assert_true($h !== '' && $h !== 'evil.example', "no request-derived host: $h");
$own = gethostname();
assert_eq(is_string($own) && $own !== '' ? $own : 'localhost', $h, 'falls back to the machine hostname');

// The admin's recorded sign-in URL is trusted.
Config::set('admin_base_url', 'http://127.0.0.1:8080/shop');
assert_eq('127.0.0.1', EmailSender::messageIdHostname(), 'host of admin_base_url, port and path dropped');

// The operator pin outranks it; IPv6 literals lose their brackets.
Config::set('base_url', 'https://[2001:db8::10]:8443');
assert_eq('2001:db8::10', EmailSender::messageIdHostname(), 'bracketed v6 host unwrapped');
Config::set('base_url', 'https://pay.example');
assert_eq('pay.example', EmailSender::messageIdHostname(), 'pinned base_url host wins');

// And PHPMailer actually gets it: the builder sets Hostname before sending.
// (Transport is stubbed by the override only when it is set; here we only
// check the header the library would stamp.)
$ref = new ReflectionClass(PHPMailer\PHPMailer\PHPMailer::class);
$m = new PHPMailer\PHPMailer\PHPMailer(true);
$m->Hostname = EmailSender::messageIdHostname();
$srv = $ref->getMethod('serverHostname');
$srv->setAccessible(true);
assert_eq('pay.example', $srv->invoke($m), 'PHPMailer uses the configured Hostname for Message-ID');

echo "test_email_message_id_hostname: ok\n";
