<?php
/**
 * The e-mailed password-reset link must never be built from the request's
 * Host header: the reset request is anonymous, so a forged Host would mail
 * the operator a genuine-looking link to an attacker's domain with the real
 * token in it. Config::getTrustedBaseUrl() only yields operator-chosen URLs:
 *
 *  - null on a fresh install (nothing recorded yet);
 *  - the URL the admin last signed in from, recorded by Auth::establishSession
 *    (only a request that proved the admin credential writes it);
 *  - the configured base_url beats the recorded one; CASHUPAY_BASE_URL beats
 *    both (checked by way of the precedence helper's inputs);
 *  - a non-admin sign-in records nothing;
 *  - Config::getBaseUrl() still auto-detects from the request for same-client
 *    links (unchanged behaviour).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

ini_set('session.save_path', sys_get_temp_dir());
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/admin.php';

assert_null(Config::getTrustedBaseUrl(), 'fresh install: nothing trustworthy yet');

// The request-derived URL follows whatever Host the client sent...
$_SERVER['HTTP_HOST'] = 'evil.example';
assert_eq('http://evil.example', Config::getBaseUrl(), 'getBaseUrl auto-detects from Host (same-client links)');
// ...but is never promoted to a trusted URL by an anonymous request.
assert_null(Config::getTrustedBaseUrl(), 'an anonymous request records nothing');

// A non-admin sign-in records nothing either.
make_store('store_staff');
Auth::createUser('staff', 'staff-pass-1234', Auth::ROLE_USER, ['store_staff']);
$_SERVER['HTTP_HOST'] = 'staff-host.example';
assert_true(Auth::login('staff', 'staff-pass-1234'), 'staff login');
assert_null(Config::getTrustedBaseUrl(), 'a user-role sign-in does not record a base URL');
Auth::logout();

// The admin signing in from their real URL records it.
Auth::createUser('root', 'root-pass-1234', Auth::ROLE_ADMIN);
$_SERVER['HTTP_HOST'] = 'pay.merchant.example';
$_SERVER['HTTPS'] = 'on';
assert_true(Auth::login('root', 'root-pass-1234'), 'admin login');
assert_eq('https://pay.merchant.example', Config::getTrustedBaseUrl(), 'admin sign-in origin is trusted');

// A later forged-Host anonymous request does not move it.
unset($_SERVER['HTTPS']);
$_SERVER['HTTP_HOST'] = 'evil.example';
assert_eq('https://pay.merchant.example', Config::getTrustedBaseUrl(), 'forged Host cannot replace the recorded URL');

// The admin moving to a new URL (and signing in there) updates it.
$_SERVER['HTTP_HOST'] = 'new.merchant.example';
$_SERVER['SCRIPT_NAME'] = '/shop/admin.php';
Auth::logout();
assert_true(Auth::login('root', 'root-pass-1234'), 'admin login at new URL');
assert_eq('http://new.merchant.example/shop', Config::getTrustedBaseUrl(), 'recorded URL follows the admin, path included');

// An explicit base_url wins over the recorded one.
Config::set('base_url', 'https://configured.example/pay/');
assert_eq('https://configured.example/pay', Config::getTrustedBaseUrl(), 'configured base_url beats the recorded origin');

echo "ok\n";
