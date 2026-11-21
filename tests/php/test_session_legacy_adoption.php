<?php
/**
 * Sessions created by a release that still used PHP's default session
 * directory are carried into <data dir>/sessions on the first request after
 * an upgrade (Auth::adoptLegacySessionFile), so the operator who just clicked
 * "Update now" stays signed in and the old dashboard's progress polling keeps
 * working. Pinned:
 *
 *  - a legacy file named by the cookie's id is moved across and its data is
 *    live in $_SESSION; the operator is still signed in;
 *  - a file already present under data/sessions is never overwritten;
 *  - a malformed cookie id never becomes a path; a legacy file that is not
 *    one of ours (no user_id) is left alone;
 *  - a moved session from before the credential fingerprint is still retired
 *    by validateSessionUser (one re-login, the 2026-11 audit decision).
 */
declare(strict_types=1);

require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$legacyDir = sys_get_temp_dir() . '/cashupay_legacy_sessions_' . bin2hex(random_bytes(4));
mkdir($legacyDir, 0700);
$ownDir = $dataDir . '/sessions';

Auth::createUser('alice', 'alice-pass-1234', Auth::ROLE_ADMIN);
$alice = Database::fetchOne("SELECT id, password_hash FROM users WHERE username = 'alice'");
$fp = Auth::credentialFingerprint((string)$alice['password_hash']);

/** Serialized the way PHP's default 'php' session handler writes it. */
function legacy_payload(array $vars): string {
    $out = '';
    foreach ($vars as $k => $v) {
        $out .= $k . '|' . serialize($v);
    }
    return $out;
}

/** Start the next "request": fresh session state, PHP's save_path back at the legacy dir. */
function next_request(string $legacyDir, string $cookieId): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_SESSION = [];
    $ref = new ReflectionClass(Auth::class);
    foreach (['sessionChecked' => false, 'timedOut' => false, 'sessionUser' => null] as $name => $value) {
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }
    ini_set('session.save_path', $legacyDir);
    $_COOKIE['cashupay_session'] = $cookieId;
    session_id($cookieId);
}

// ---- 1. a signed-in v1.9+ session moves across and stays signed in ---------------
$id1 = bin2hex(random_bytes(16));
file_put_contents("$legacyDir/sess_$id1", legacy_payload([
    'user_id' => $alice['id'], 'user_role' => 'admin', 'login_time' => time() - 600, 'auth_fp' => $fp,
]));
next_request($legacyDir, $id1);
Auth::initSession();
assert_eq($ownDir, ini_get('session.save_path'), 'save_path now points at data/sessions');
assert_true(is_file("$ownDir/sess_$id1"), 'legacy session file moved into data/sessions');
assert_false(file_exists("$legacyDir/sess_$id1"), 'original removed from the legacy directory');
assert_eq($alice['id'], $_SESSION['user_id'] ?? null, 'session data is live after the move');
$user = Auth::currentUser();
assert_eq($alice['id'], $user['id'] ?? null, 'the operator is still signed in');
assert_true(isset($_SESSION['last_activity']), 'the moved session starts its idle clock');

// ---- 2. an existing file under data/sessions wins -------------------------------
$id2 = bin2hex(random_bytes(16));
file_put_contents("$ownDir/sess_$id2", legacy_payload(['marker' => 'ours']));
file_put_contents("$legacyDir/sess_$id2", legacy_payload(['user_id' => 'intruder', 'marker' => 'legacy']));
next_request($legacyDir, $id2);
Auth::initSession();
assert_eq('ours', $_SESSION['marker'] ?? null, 'the newer file was not overwritten');
assert_true(file_exists("$legacyDir/sess_$id2"), 'legacy duplicate left untouched');

// ---- 3. malformed id: never a path; foreign file: not adopted ---------------------
$bad = '../../etc/passwd';
file_put_contents("$legacyDir/sess_x", 'user_id|s:1:"x";');
next_request($legacyDir, bin2hex(random_bytes(16)));
$_COOKIE['cashupay_session'] = $bad;
Auth::initSession();
assert_false(file_exists("$ownDir/sess_$bad"), 'a malformed cookie id creates nothing');
assert_true(is_dir($ownDir), 'own directory intact');

$id4 = bin2hex(random_bytes(16));
file_put_contents("$legacyDir/sess_$id4", legacy_payload(['cart' => [1, 2, 3]]));   // some other app's session
next_request($legacyDir, $id4);
Auth::initSession();
assert_true(file_exists("$legacyDir/sess_$id4"), 'a session without user_id is not ours and stays put');
assert_false(isset($_SESSION['cart']), 'and its data is not loaded');

// ---- 4. pre-fingerprint (v1.7/v1.8) session moves but is retired -----------------
$id5 = bin2hex(random_bytes(16));
file_put_contents("$legacyDir/sess_$id5", legacy_payload([
    'user_id' => $alice['id'], 'user_role' => 'admin', 'login_time' => time() - 600,
]));
next_request($legacyDir, $id5);
Auth::initSession();
assert_true(is_file("$ownDir/sess_$id5"), 'pre-fingerprint session file is moved too');
assert_null(Auth::currentUser(), 'but without auth_fp it is retired: one re-login after the upgrade');

// ---- 5. second request for an adopted session is a plain hit ------------------------
next_request($legacyDir, $id1);
Auth::initSession();
assert_eq($alice['id'], Auth::currentUser()['id'] ?? null, 'adopted session keeps working on later requests');

session_write_close();
array_map('unlink', glob("$legacyDir/sess_*") ?: []);
rmdir($legacyDir);
cleanup_db($dataDir);
echo "test_session_legacy_adoption: ok\n";
