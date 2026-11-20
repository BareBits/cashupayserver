<?php
/**
 * Idle login timeout (Settings → My Account → Login timeout).
 *
 * Before this the admin session had no timeout of its own: the cookie died
 * with the browser and the server side was whatever the host's PHP garbage
 * collector did (24 idle minutes by default, swept by a cron the app could
 * not reach on Debian hosts). Pinned:
 *
 *  - the default is 7 days; the stored value must be one of the offered
 *    choices (anything else is refused on write and ignored on read);
 *  - a session idle for longer than the timeout is ended on its next check
 *    and sessionTimedOut() reports it for that request; activity inside the
 *    window moves last_activity forward;
 *  - a pre-upgrade session (login_time only) uses sign-in as its activity
 *    stamp; a server-seeded session with no stamp at all starts its clock now;
 *  - shortening the timeout bites existing sessions on their next check;
 *  - sessions are stored under <data dir>/sessions with PHP's GC aligned to
 *    the timeout, and the cookie lifetime is the timeout;
 *  - admin.php: get_login_timeout reports the value + choices, and
 *    save_login_timeout persists an offered value, refuses others, and the
 *    unauthenticated gate answers with code session_timeout / not_authenticated.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

/** Reset Auth's per-request cache so the next check re-reads, the way a new request would. */
function new_request(): void {
    $ref = new ReflectionClass(Auth::class);
    foreach (['sessionChecked' => false, 'timedOut' => false] as $name => $value) {
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }
}

// ---- default + validation -----------------------------------------------------
assert_eq(7 * 86400, Auth::LOGIN_TIMEOUT_DEFAULT, 'default is 7 days');
assert_eq(7 * 86400, Auth::loginTimeout(), 'unset config reads the default');
assert_true(isset(Auth::LOGIN_TIMEOUT_CHOICES[Auth::LOGIN_TIMEOUT_DEFAULT]), 'default is an offered choice');

$threw = false;
try { Auth::setLoginTimeout(1234); } catch (\InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'a value outside the offered choices is refused');
assert_eq(7 * 86400, Auth::loginTimeout(), 'refused write leaves the value alone');

Config::set(Auth::LOGIN_TIMEOUT_KEY, 42);   // a stale/hand-edited value
assert_eq(7 * 86400, Auth::loginTimeout(), 'a stored value that is not a choice reads as the default');

Auth::setLoginTimeout(3600);
assert_eq(3600, Auth::loginTimeout(), 'an offered value round-trips');
Auth::setLoginTimeout(Auth::LOGIN_TIMEOUT_DEFAULT);

// ---- storage + cookie wiring --------------------------------------------------
Auth::createUser('alice', 'alice-pass-1234', Auth::ROLE_ADMIN);
assert_true(Auth::login('alice', 'alice-pass-1234'), 'login ok');
assert_eq($dataDir . '/sessions', ini_get('session.save_path'), 'sessions live under the data directory');
assert_true(is_dir($dataDir . '/sessions'), 'sessions directory created');
assert_eq((string)(7 * 86400), ini_get('session.gc_maxlifetime'), 'PHP GC lifetime follows the timeout');
assert_eq('1', ini_get('session.gc_probability'), 'GC runs in-process (Debian ships 0)');
assert_eq(7 * 86400, session_get_cookie_params()['lifetime'], 'cookie lifetime is the timeout, not browser-session');
assert_true(isset($_SESSION['last_activity']), 'login stamps last_activity');
assert_true(isset($_SESSION['cookie_sent_at']), 'login records the cookie issue time');

// ---- activity inside the window keeps the session and moves the stamp ----------
$_SESSION['last_activity'] = time() - 3600;
new_request();
assert_true(Auth::isLoggedIn(), 'an hour idle is well within 7 days');
assert_false(Auth::sessionTimedOut(), 'not reported as timed out');
assert_true(time() - (int)$_SESSION['last_activity'] <= 2, 'activity stamp refreshed by the check');

// ---- idle past the timeout ends the session ------------------------------------
$_SESSION['last_activity'] = time() - 7 * 86400 - 5;
new_request();
assert_false(Auth::isLoggedIn(), 'idle longer than the timeout ends the session');
assert_true(Auth::sessionTimedOut(), 'reported as timed out for this request');
assert_null(Auth::currentUser(), 'currentUser is null');
assert_true(empty($_SESSION['user_id']), 'identity cleared from the session');
assert_true(!isset($_SESSION['last_activity']), 'activity stamp cleared too');
new_request();
assert_true(Auth::sessionTimedOut(), 'the timeout is remembered by the (now anonymous) session for the lock screen');
assert_true(Auth::login('alice', 'alice-pass-1234'), 'sign in again');
assert_false(Auth::sessionTimedOut(), 'signing in clears it');
Auth::logout();

// ---- shortening the timeout bites existing sessions ----------------------------
Auth::initSession();
assert_true(Auth::login('alice', 'alice-pass-1234'), 'login again');
$_SESSION['last_activity'] = time() - 20 * 60;
new_request();
assert_true(Auth::isLoggedIn(), '20 minutes idle is fine at 7 days');
Auth::setLoginTimeout(900);
$_SESSION['last_activity'] = time() - 20 * 60;   // the check above refreshed it; idle again
new_request();
assert_false(Auth::isLoggedIn(), 'the same session is gone once the timeout drops to 15 minutes');
assert_true(Auth::sessionTimedOut(), 'and it is reported as a timeout');
Auth::setLoginTimeout(Auth::LOGIN_TIMEOUT_DEFAULT);

// ---- pre-upgrade session: login_time only ------------------------------------------
assert_true(Auth::login('alice', 'alice-pass-1234'), 'login again');
unset($_SESSION['last_activity']);
$_SESSION['login_time'] = time() - 3 * 86400;
new_request();
assert_true(Auth::isLoggedIn(), 'a legacy session 3 days after sign-in survives a 7-day timeout');
assert_true(isset($_SESSION['last_activity']), 'and is stamped going forward');
unset($_SESSION['last_activity']);
$_SESSION['login_time'] = time() - 8 * 86400;
new_request();
assert_false(Auth::isLoggedIn(), 'a legacy session 8 days after sign-in is retired');

// ---- server-seeded session without any stamp starts its clock now -------------------
$alice = Auth::getUserByUsername('alice');
$_SESSION['user_id'] = $alice['id'];
$_SESSION['user_role'] = 'admin';
$_SESSION['auth_fp'] = Auth::credentialFingerprint($alice['password_hash']);
unset($_SESSION['login_time'], $_SESSION['last_activity'], $_SESSION['login_timed_out_at']);
new_request();
assert_true(Auth::isLoggedIn(), 'no stamp at all is not treated as expired');
assert_true(isset($_SESSION['last_activity']), 'it gets a stamp on first check');

// ---- credential binding still runs after the timeout check -----------------------
Database::update('users', ['password_hash' => password_hash('other', PASSWORD_DEFAULT)], 'id = ?', [$alice['id']]);
new_request();
assert_false(Auth::isLoggedIn(), 'a mismatched credential still ends the session');
assert_false(Auth::sessionTimedOut(), 'and that is not reported as a timeout');
Auth::changePassword($alice['id'], 'alice-pass-1234');

// =============================================================================
// admin.php endpoints, each in a subprocess (admin.php echoes and exits).
// =============================================================================
$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/login_timeout_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$mode = (string)getenv('T_MODE');   // post | get | anon_get | expired_get
$_SERVER['REQUEST_METHOD'] = $mode === 'post' ? 'POST' : 'GET';
require %s;
require %s;
require %s;
Auth::initSession();
if ($mode !== 'anon_get') {
    $row = Database::fetchOne("SELECT * FROM users WHERE username = 'alice'");
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['user_role'] = $row['role'];
    $_SESSION['auth_fp'] = Auth::credentialFingerprint((string)$row['password_hash']);
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = $mode === 'expired_get' ? time() - 365 * 86400 : time();
}
if ($mode === 'post') {
    $_SERVER['REQUEST_URI'] = '/admin.php';
    $_POST = json_decode((string)getenv('T_POST'), true);
    $_POST['csrf_token'] = Auth::generateCsrfToken();
} else {
    $_GET = json_decode((string)getenv('T_GET'), true);
    $_SERVER['REQUEST_URI'] = '/admin.php?' . http_build_query($_GET);
}
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/admin.php', true)
));

/** @return array{status:int, json:?array} */
function admin_call(string $mode, array $params): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir) . ' T_MODE=' . escapeshellarg($mode)
        . ($mode === 'post' ? ' T_POST=' : ' T_GET=') . escapeshellarg(json_encode($params));
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}

/** A config value read raw, so this process's Config cache can't mask subprocess writes. */
function raw_config(string $key) {
    $row = Database::fetchOne("SELECT value FROM config WHERE key = ?", [$key]);
    return $row === null ? null : json_decode($row['value'], true);
}

$res = admin_call('get', ['api' => 'get_login_timeout']);
assert_eq(200, $res['status'], 'get_login_timeout ok: ' . json_encode($res['json']));
assert_eq(7 * 86400, $res['json']['seconds'] ?? null, 'reports the default');
assert_eq('7 days', $res['json']['choices'][7 * 86400] ?? null, 'reports the offered choices with labels');

$res = admin_call('post', ['action' => 'save_login_timeout', 'seconds' => '1800']);
assert_eq(200, $res['status'], 'save ok: ' . json_encode($res['json']));
assert_eq(1800, $res['json']['seconds'] ?? null, 'save echoes the new value');
assert_eq(1800, raw_config(Auth::LOGIN_TIMEOUT_KEY), 'persisted');

foreach (['999', '', 'abc', '-900', '1800.5'] as $bad) {
    $res = admin_call('post', ['action' => 'save_login_timeout', 'seconds' => $bad]);
    assert_eq(400, $res['status'], "save refuses '$bad'");
    assert_eq(1800, raw_config(Auth::LOGIN_TIMEOUT_KEY), "'$bad' left the stored value alone");
}

$res = admin_call('get', ['api' => 'get_login_timeout']);
assert_eq(1800, $res['json']['seconds'] ?? null, 'get sees the saved value');

// The auth gate tells a timed-out session from a plain missing one.
$res = admin_call('expired_get', ['api' => 'get_login_timeout']);
assert_eq(401, $res['status'], 'expired session gets 401');
assert_eq('session_timeout', $res['json']['code'] ?? null, 'with code session_timeout');
assert_true(str_contains((string)($res['json']['error'] ?? ''), 'timed out'), 'and a message that says so');

$res = admin_call('anon_get', ['api' => 'get_login_timeout']);
assert_eq(401, $res['status'], 'no session gets 401');
assert_eq('not_authenticated', $res['json']['code'] ?? null, 'with code not_authenticated');

echo "ok\n";
