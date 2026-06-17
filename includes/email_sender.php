<?php
/**
 * CashuPayServer - Email Sender Module
 *
 * Thin PHPMailer wrapper. SMTP settings are resolved per-field through a
 * three-layer cascade (see resolveConfig):
 *
 *   per-store override (when the store has smtp_override_enabled = 1)
 *     -> global settings saved in the admin UI (config table, smtp_* keys)
 *       -> user_config.php constants / env vars (CASHUPAY_SMTP_*)
 *         -> built-in default (or empty)
 *
 * A blank value at one layer never blanks the layer below it; it simply
 * cascades down. When no host resolves at all, send() falls back to PHP's
 * built-in mail(), which depends on a working local MTA.
 *
 * Read by includes/notification_sender.php from the cron drain loop. Not
 * called inline on the request path for queued mail, so a slow SMTP server
 * cannot delay invoice settlement.
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
     * Logical field => [config table key, stores column, user_config constant].
     * The three names happen to align, but keeping them explicit documents the
     * cascade and leaves room for them to diverge.
     */
    private const FIELDS = [
        'host'         => ['smtp_host',         'smtp_host',         'CASHUPAY_SMTP_HOST'],
        'port'         => ['smtp_port',         'smtp_port',         'CASHUPAY_SMTP_PORT'],
        'username'     => ['smtp_username',     'smtp_username',     'CASHUPAY_SMTP_USERNAME'],
        'password'     => ['smtp_password',     'smtp_password',     'CASHUPAY_SMTP_PASSWORD'],
        'encryption'   => ['smtp_encryption',   'smtp_encryption',   'CASHUPAY_SMTP_ENCRYPTION'],
        'from_address' => ['smtp_from_address', 'smtp_from_address', 'CASHUPAY_SMTP_FROM_ADDRESS'],
        'from_name'    => ['smtp_from_name',    'smtp_from_name',    'CASHUPAY_SMTP_FROM_NAME'],
    ];

    /**
     * Send a plain-text email.
     *
     * $storeId, when given, lets a store's SMTP override (if enabled) win over
     * the global settings — used by the queue drain so each store's mail can go
     * through its own server. Throws on failure (queue drain catches and records
     * last_error). The caller is responsible for any retry/backoff policy.
     */
    public static function send(string $to, string $subject, string $body, ?string $storeId = null): void {
        if (self::$transportOverride !== null) {
            (self::$transportOverride)($to, $subject, $body);
            return;
        }
        $cfg = self::resolveConfig($storeId);

        $fromAddress = $cfg['from_address'];
        if ($fromAddress === '') {
            throw new RuntimeException(
                'No From address is configured (set it in Settings or CASHUPAY_SMTP_FROM_ADDRESS); '
                . 'cannot send notification email.'
            );
        }
        $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : 'CashuPayServer';

        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $body;

        if ($cfg['host'] !== '') {
            self::configureSmtp($mailer, $cfg);
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

    private static function configureSmtp(PHPMailer $mailer, array $cfg): void {
        $mailer->isSMTP();
        $mailer->Host = $cfg['host'];

        $port = $cfg['port'];
        if ($port !== '' && ctype_digit((string)$port)) {
            $mailer->Port = (int)$port;
        } else {
            $mailer->Port = 587;
        }

        if ($cfg['username'] !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $cfg['username'];
            $mailer->Password = $cfg['password'];
        } else {
            $mailer->SMTPAuth = false;
        }

        $encryption = strtolower(trim($cfg['encryption']));
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
     * Resolve the effective SMTP config as a string map keyed by the logical
     * names in self::FIELDS. Implements the per-field cascade documented at the
     * top of this file. Every DB touch is defensive: if the config/stores
     * lookup throws (e.g. update.php sending a recovery mail mid-migration),
     * the field falls through to the user_config.php constant.
     */
    public static function resolveConfig(?string $storeId = null): array {
        $store = ($storeId !== null && $storeId !== '') ? self::storeOverride($storeId) : null;

        $result = [];
        foreach (self::FIELDS as $key => [$configKey, $storeCol, $constName]) {
            $val = '';
            if ($store !== null && isset($store[$storeCol])) {
                $sv = (string)$store[$storeCol];
                if ($sv !== '') {
                    $val = $sv;
                }
            }
            if ($val === '') {
                $gv = self::dbValue($configKey);
                if ($gv !== '') {
                    $val = $gv;
                }
            }
            if ($val === '') {
                $val = self::constValue($constName);
            }
            $result[$key] = $val;
        }
        return $result;
    }

    /**
     * Return the store row when the store exists and has SMTP override enabled;
     * null otherwise (override off, store missing, or DB unavailable).
     */
    private static function storeOverride(string $storeId): ?array {
        try {
            if (!class_exists('Config')) {
                require_once __DIR__ . '/config.php';
            }
            $store = Config::getStore($storeId);
            if ($store === null) {
                return null;
            }
            if ((int)($store['smtp_override_enabled'] ?? 0) !== 1) {
                return null;
            }
            return $store;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Read a global SMTP setting from the config table, '' when unset/blank.
     */
    private static function dbValue(string $key): string {
        try {
            if (!class_exists('Config')) {
                require_once __DIR__ . '/config.php';
            }
            $v = Config::get($key, '');
            return is_string($v) ? $v : (string)$v;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Mirrors Database::settingValue: prefer the PHP constant (user_config.php)
     * over the env var of the same name. Returns '' when neither is set.
     */
    private static function constValue(string $name): string {
        if (defined($name)) {
            $v = constant($name);
            return $v === null ? '' : (string)$v;
        }
        $env = getenv($name);
        return $env === false ? '' : (string)$env;
    }

    /**
     * Whether the deployment has a usable SMTP host (from any layer). Used by
     * the admin UI to decide whether to warn that mail() is the fallback, and
     * by the payer-receipt gate.
     */
    public static function isSmtpConfigured(): bool {
        return self::resolveConfig(null)['host'] !== '';
    }
}
