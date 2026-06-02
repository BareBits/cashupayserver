<?php
/**
 * CashuPayServer - Email Sender Module
 *
 * Thin PHPMailer wrapper. Picks SMTP transport when CASHUPAY_SMTP_HOST is
 * defined (typically in user_config.php); otherwise falls back to PHP's
 * built-in mail() function, which depends on a working local MTA.
 *
 * Read by includes/notification_sender.php from the cron drain loop. Not
 * called inline on the request path, so a slow SMTP server cannot delay
 * invoice settlement.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailSender {
    /**
     * Test-only override. If set to a callable `fn(string $to, string $subject,
     * string $body): void`, send() invokes it instead of touching PHPMailer.
     * Throwing from the callable simulates a transport failure.
     */
    public static $transportOverride = null;

    /**
     * Send a plain-text email.
     *
     * Throws on failure (queue drain catches and records last_error). The
     * caller is responsible for any retry/backoff policy.
     */
    public static function send(string $to, string $subject, string $body): void {
        if (self::$transportOverride !== null) {
            (self::$transportOverride)($to, $subject, $body);
            return;
        }
        $fromAddress = self::settingValue('CASHUPAY_SMTP_FROM_ADDRESS');
        if ($fromAddress === false || $fromAddress === '') {
            throw new RuntimeException(
                'CASHUPAY_SMTP_FROM_ADDRESS is not set; cannot send notification email.'
            );
        }
        $fromName = self::settingValue('CASHUPAY_SMTP_FROM_NAME');
        if ($fromName === false || $fromName === '') {
            $fromName = 'CashuPayServer';
        }

        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $body;

        $smtpHost = self::settingValue('CASHUPAY_SMTP_HOST');
        if ($smtpHost !== false && $smtpHost !== '') {
            self::configureSmtp($mailer, $smtpHost);
        } else {
            $mailer->isMail();
        }

        try {
            $mailer->send();
        } catch (PHPMailerException $e) {
            // PHPMailerException's message is operator-facing safe and includes
            // the SMTP server reply when available.
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private static function configureSmtp(PHPMailer $mailer, string $host): void {
        $mailer->isSMTP();
        $mailer->Host = $host;

        $port = self::settingValue('CASHUPAY_SMTP_PORT');
        if ($port !== false && $port !== '' && ctype_digit((string)$port)) {
            $mailer->Port = (int)$port;
        } else {
            $mailer->Port = 587;
        }

        $username = self::settingValue('CASHUPAY_SMTP_USERNAME');
        $password = self::settingValue('CASHUPAY_SMTP_PASSWORD');
        if ($username !== false && $username !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = ($password !== false) ? $password : '';
        } else {
            $mailer->SMTPAuth = false;
        }

        $encryption = self::settingValue('CASHUPAY_SMTP_ENCRYPTION');
        $encryption = is_string($encryption) ? strtolower(trim($encryption)) : '';
        switch ($encryption) {
            case 'ssl':
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            case 'none':
            case '':
                if ($mailer->Port === 587) {
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mailer->SMTPSecure = '';
                    $mailer->SMTPAutoTLS = false;
                }
                break;
            case 'tls':
            default:
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
        }
    }

    /**
     * Mirrors Database::settingValue: prefer the PHP constant (user_config.php)
     * over the env var of the same name. Returns false when neither is set.
     */
    private static function settingValue(string $name) {
        if (defined($name)) {
            $v = constant($name);
            if ($v === null) return false;
            return (string) $v;
        }
        return getenv($name);
    }

    /**
     * Whether the deployment has SMTP credentials configured. Used by the
     * admin UI to decide whether to warn that mail() is the fallback.
     */
    public static function isSmtpConfigured(): bool {
        $host = self::settingValue('CASHUPAY_SMTP_HOST');
        return $host !== false && $host !== '';
    }
}
