<?php
/**
 * Admin sessions are bound to the credential they were minted with.
 *
 * Before this, a session stayed valid until the browser closed: resetting a
 * password (admin reset, e-mailed token, file reset) or deleting a user left
 * every existing session of that account usable, and the role was read from
 * the cookie, not the users table. Pinned:
 *
 *  - a session established by login() carries a credential fingerprint and
 *    isLoggedIn()/isAdmin()/currentUser() honour it;
 *  - changing the password from ANOTHER session invalidates this one on its
 *    next check; changing one's OWN password keeps the session alive;
 *  - a token reset (resetPasswordWithToken) invalidates sessions too;
 *  - deleting the user ends the session; demoting the role is seen at once;
 *  - a legacy session without a fingerprint (pre-upgrade cookie) is retired.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

ini_set('session.save_path', sys_get_temp_dir());
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

/** Reset Auth's per-request cache so the next check re-reads the DB, the way a new request would. */
function new_request(): void {
    $ref = new ReflectionClass(Auth::class);
    $p = $ref->getProperty('sessionChecked');
    $p->setAccessible(true);
    $p->setValue(null, false);
}

Auth::createUser('alice', 'alice-pass-1234', Auth::ROLE_ADMIN);
make_store('store_bob');
Auth::createUser('bob', 'bob-pass-1234', Auth::ROLE_USER, ['store_bob']);
$alice = Auth::getUserByUsername('alice');
$bob = Auth::getUserByUsername('bob');

// ---- login establishes a fingerprinted admin session ------------------------
assert_true(Auth::login('alice', 'alice-pass-1234'), 'login ok');
assert_true(isset($_SESSION['auth_fp']), 'session carries the credential fingerprint');
assert_eq(Auth::credentialFingerprint($alice['password_hash']), $_SESSION['auth_fp'], 'fingerprint matches the stored hash');
new_request();
assert_true(Auth::isLoggedIn(), 'logged in');
assert_true(Auth::isAdmin(), 'admin');
assert_eq('alice', Auth::currentUser()['username'], 'currentUser resolves');

// ---- own password change keeps the session -----------------------------------
Auth::changePassword($alice['id'], 'alice-new-pass-1234');
new_request();
assert_true(Auth::isLoggedIn(), 'changing your own password keeps your session');

// ---- a reset from elsewhere ends it --------------------------------------------
// Simulate another session (or the e-mailed token flow) changing the hash.
$token = Auth::createPasswordResetToken($alice['id']);
assert_true(Auth::resetPasswordWithToken($token, 'alice-reset-pass-1234'), 'token reset ok');
new_request();
assert_false(Auth::isLoggedIn(), 'token reset invalidates the existing session');
assert_false(Auth::isAdmin(), 'and it is no longer admin');
assert_null(Auth::currentUser(), 'currentUser is null');
assert_true(empty($_SESSION['user_id']), 'stale identity cleared from the session');

// ---- admin resets another user: that user's session dies ------------------------
assert_true(Auth::login('bob', 'bob-pass-1234'), 'bob logs in');
new_request();
assert_true(Auth::isLoggedIn(), 'bob logged in');
assert_false(Auth::isAdmin(), 'bob is not admin');
// The acting admin is a different session: mimic by changing without a matching user_id.
$saved = $_SESSION['user_id'];
$_SESSION['user_id'] = 'someone-else';
Auth::changePassword($bob['id'], 'bob-new-pass-1234');
$_SESSION['user_id'] = $saved;
new_request();
assert_false(Auth::isLoggedIn(), 'admin resetting bob ends bob\'s session');

// ---- role comes from the database, deletion ends the session -----------------
assert_true(Auth::login('bob', 'bob-new-pass-1234'), 'bob logs in again');
Database::update('users', ['role' => Auth::ROLE_ADMIN], 'id = ?', [$bob['id']]);
new_request();
assert_true(Auth::isAdmin(), 'promotion is visible without re-login');
Database::update('users', ['role' => Auth::ROLE_USER], 'id = ?', [$bob['id']]);
new_request();
assert_false(Auth::isAdmin(), 'demotion is visible without re-login');
assert_eq(Auth::ROLE_USER, $_SESSION['user_role'], 'cached role refreshed from the row');
Auth::deleteUser($bob['id']);
new_request();
assert_false(Auth::isLoggedIn(), 'deleting the user ends the session');

// ---- legacy session without a fingerprint is retired -------------------------
$_SESSION['user_id'] = $alice['id'];
$_SESSION['user_role'] = 'admin';
unset($_SESSION['auth_fp']);
new_request();
assert_false(Auth::isLoggedIn(), 'a cookie-only identity with no fingerprint is not trusted');
assert_false(Auth::isAdmin(), 'nor is its cached admin role');

echo "ok\n";
