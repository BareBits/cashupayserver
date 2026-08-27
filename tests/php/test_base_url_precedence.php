<?php
/**
 * Config::getBaseUrl precedence with a deployment-time pin.
 *
 * Installers that know the served URL up front (the WordPress plugin's
 * alongside install) write CASHUPAY_BASE_URL into user_config.php. The
 * documented order is: database base_url (operator-saved) beats the pin,
 * the pin beats Host-header autodetection, and the pin is normalized
 * (trailing slash trimmed) like every other base-URL source. This file pins
 * that order — in particular that a database value silently overrides the
 * constant, which is deliberate (an operator's explicit choice wins) but
 * easy to regress in either direction.
 *
 * Constants are process-sticky, so the constant is defined once up front and
 * the database value is flipped around it; pure autodetection (no constant
 * at all) is covered by test_base_url_windows_backslash.php.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

define('CASHUPAY_BASE_URL', 'https://shop.example/barebits/');

require_once dirname(__DIR__, 2) . '/includes/config.php';

// Autodetection would produce http://pay.test — the assertions below prove
// it never gets the chance while the pin (or a DB value) exists.
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/index.php';

// --- No database value: the pin wins over autodetection ----------------------
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'the CASHUPAY_BASE_URL pin beats Host-header autodetection, trailing slash trimmed');

// --- A database base_url beats the pin ---------------------------------------
Config::set('base_url', 'https://operator-choice.example/pay/');
assert_eq('https://operator-choice.example/pay', Config::getBaseUrl(),
    'an operator-saved base_url overrides the deployment pin');

// --- Clearing the database value falls back to the pin, not autodetection ----
Config::set('base_url', '');
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'an empty database value falls back to the pin');
Config::delete('base_url');
assert_eq('https://shop.example/barebits', Config::getBaseUrl(),
    'a deleted database value falls back to the pin');

echo "test_base_url_precedence: ok\n";
