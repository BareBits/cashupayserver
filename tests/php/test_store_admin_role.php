<?php
/**
 * Store admin role + per-user store grants (Auth, Database migration,
 * TrustedMints per-store opt-out).
 *
 *  - createUser: admins take no grants; store admins / users need >= 1 store
 *    (refused otherwise, nothing left behind); unknown store refused; the
 *    optional recovery e-mail is validated and stored.
 *  - storeIdsForUser / accessibleStoreIds / canViewStore / canManageStore:
 *    admin implicitly holds every store; store admin manages only its grants;
 *    a user views but never manages; nobody signed in sees nothing.
 *  - scopeStoreFilter / storeScopeSql: an admin's "all" is unrestricted, a
 *    store admin's "all" is its grant list, an empty list matches nothing.
 *  - setUserStoreIds replaces the set, clears it for admins, refuses unknown
 *    stores; deleting a store or a user removes its grants (FK cascade).
 *  - setUserRole: last-admin guard, promotion to admin drops the grants,
 *    demotion keeps them until the caller sets new ones.
 *  - listUsers carries email + storeIds, ordered admin / store admin / user.
 *  - findUserByEmail matches any role (admins first), so every account can
 *    reset its own password by e-mail.
 *  - Migration: an install whose users table still carries the two-role
 *    CHECK gets it widened WITHOUT losing the email column or the
 *    password_reset_tokens rows; existing 'user' accounts are granted every
 *    existing store when user_store_access first appears; re-running is a
 *    no-op; stores.trusted_mints_enabled is the new marker, default 1.
 *  - TrustedMints: a store switched off is skipped by applyToAllStores and
 *    applyToNewStore, and its invoices are not gated by the list's disabled
 *    flag (Config::getStoreAllMintUrls), while permanently_disabled still is.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';
require_once dirname(__DIR__, 2) . '/app/includes/trusted_mints.php';
require_once dirname(__DIR__, 2) . '/app/includes/mint_reliability.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

function new_request(): void {
    $ref = new ReflectionClass(Auth::class);
    foreach (['sessionChecked' => false, 'timedOut' => false] as $name => $value) {
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }
}
function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}
function has_col(string $table, string $col): bool {
    $r = Database::fetchOne("SELECT COUNT(*) AS n FROM pragma_table_info('{$table}') WHERE name = ?", [$col]);
    return (int)($r['n'] ?? 0) > 0;
}
function expect_throws(callable $fn, string $class, string $msg): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        assert_true($e instanceof $class, $msg . ' (got ' . get_class($e) . ': ' . $e->getMessage() . ')');
        return;
    }
    fail($msg . ': nothing thrown');
}

make_store('s_alpha', 'https://alpha.mint');
make_store('s_beta', 'https://beta.mint');
make_store('s_gamma', 'https://gamma.mint');

// ---- roles + constants -------------------------------------------------------------
assert_eq(['admin', 'store_admin', 'user'], Auth::ROLES, 'three roles');
assert_true(Auth::isValidRole('store_admin'));
assert_false(Auth::isValidRole('wizard'));
assert_false(Auth::isValidRole(null));

// ---- createUser --------------------------------------------------------------------
Auth::setAdminPassword('root-pw-1234');
$adminId = Auth::createUser('boss', 'boss-pw-1234', Auth::ROLE_ADMIN, ['s_alpha'], 'boss@example.com');
assert_eq([], Auth::getUserStoreIds($adminId), 'an admin takes no grants even when some are posted');
assert_eq('boss@example.com', Auth::getUserById($adminId)['email'], 'recovery e-mail stored');

expect_throws(fn() => Auth::createUser('nostore', 'nostore-pw-1234', Auth::ROLE_STORE_ADMIN),
    InvalidArgumentException::class, 'a store admin without stores is refused');
assert_null(Auth::getUserByUsername('nostore'), 'refused account is not created');
expect_throws(fn() => Auth::createUser('nostore', 'nostore-pw-1234', Auth::ROLE_USER, []),
    InvalidArgumentException::class, 'a user without stores is refused');
expect_throws(fn() => Auth::createUser('ghost', 'ghost-pw-1234', Auth::ROLE_USER, ['s_nope']),
    InvalidArgumentException::class, 'an unknown store is refused');
assert_null(Auth::getUserByUsername('ghost'), 'nothing left behind when the grants are refused');
expect_throws(fn() => Auth::createUser('bademail', 'bademail-pw-1234', Auth::ROLE_USER, ['s_alpha'], 'not-an-email'),
    InvalidArgumentException::class, 'an invalid e-mail is refused');
expect_throws(fn() => Auth::createUser('x', 'x-pw-1234', 'owner', ['s_alpha']),
    InvalidArgumentException::class, 'an unknown role is refused');

$saId = Auth::createUser('alice', 'alice-pw-1234', Auth::ROLE_STORE_ADMIN, ['s_alpha', 's_beta', 's_alpha'], 'alice@example.com');
assert_eq(2, count(Auth::getUserStoreIds($saId)), 'duplicate grants collapse');
$g = Auth::getUserStoreIds($saId); sort($g); assert_eq(["s_alpha", "s_beta"], $g, "store admin grants stored");
$userId = Auth::createUser('bob', 'bob-pw-1234', Auth::ROLE_USER, ['s_beta']);
assert_eq(['s_beta'], Auth::getUserStoreIds($userId), 'user grant stored');
$userNoEmailId = Auth::createUser('carol', 'carol-pw-1234', Auth::ROLE_USER, ['s_gamma'], '');
assert_null(Auth::getUserById($userNoEmailId)['email'], 'blank e-mail stored as NULL');

// ---- storeIdsForUser / session-based access ---------------------------------------
$allIds = Auth::storeIdsForUser(Auth::getUserById($adminId));
sort($allIds);
assert_eq(['s_alpha', 's_beta', 's_gamma'], $allIds, 'admin implicitly holds every store');
$saIds = Auth::storeIdsForUser(Auth::getUserById($saId));
sort($saIds);
assert_eq(['s_alpha', 's_beta'], $saIds, 'store admin holds its grants');

new_request();
assert_eq([], Auth::accessibleStoreIds(), 'nobody signed in: no stores');
assert_false(Auth::canViewStore('s_alpha'), 'anonymous cannot view');
assert_false(Auth::canManageStore('s_alpha'), 'anonymous cannot manage');
assert_null(Auth::currentRole());

assert_true(Auth::login('alice', 'alice-pw-1234'), 'store admin login');
new_request();
assert_eq('store_admin', Auth::currentRole());
assert_true(Auth::isStoreAdmin());
assert_true(Auth::isStoreManager());
assert_false(Auth::isAdmin(), 'a store admin is not an admin');
assert_true(Auth::canViewStore('s_alpha'));
assert_true(Auth::canManageStore('s_beta'));
assert_false(Auth::canViewStore('s_gamma'), 'ungranted store invisible');
assert_false(Auth::canManageStore('s_gamma'));
assert_false(Auth::canViewStore(''), 'empty store id never passes');
$ids = Auth::accessibleStoreIds(); sort($ids);
assert_eq(['s_alpha', 's_beta'], $ids);
$scope = Auth::scopeStoreFilter('__all__'); sort($scope);
assert_eq(['s_alpha', 's_beta'], $scope, "a store admin's 'all' is its grants");
assert_eq(['s_beta'], Auth::scopeStoreFilter('s_beta'), 'a held store passes through');
$sql = Auth::storeScopeSql(['s_alpha', 's_beta']);
assert_eq('store_id IN (?,?)', $sql['sql']);
assert_eq(['s_alpha', 's_beta'], $sql['params']);
assert_eq('1 = 0', Auth::storeScopeSql([])['sql'], 'no grants match nothing');
assert_eq('', Auth::storeScopeSql(null)['sql'], 'null means unrestricted');
Auth::logout();

new_request();
assert_true(Auth::login('bob', 'bob-pw-1234'), 'user login');
new_request();
assert_eq('user', Auth::currentRole());
assert_false(Auth::isStoreManager());
assert_true(Auth::canViewStore('s_beta'), 'user views its store');
assert_false(Auth::canManageStore('s_beta'), 'user never manages');
assert_false(Auth::canViewStore('s_alpha'));
Auth::logout();

new_request();
assert_true(Auth::login('boss', 'boss-pw-1234'), 'admin login');
new_request();
assert_true(Auth::isStoreManager());
assert_true(Auth::canManageStore('s_gamma'), 'admin manages everything');
assert_null(Auth::scopeStoreFilter(''), "an admin's 'all' is unrestricted");
assert_eq(['s_gamma'], Auth::scopeStoreFilter('s_gamma'));
Auth::logout();

// ---- setUserStoreIds ----------------------------------------------------------------
Auth::setUserStoreIds($saId, ['s_gamma']);
assert_eq(['s_gamma'], Auth::getUserStoreIds($saId), 'grants replaced');
expect_throws(fn() => Auth::setUserStoreIds($saId, ['s_gamma', 's_nope']),
    InvalidArgumentException::class, 'unknown store refused on update');
assert_eq(['s_gamma'], Auth::getUserStoreIds($saId), 'refused update leaves the grants alone');
Auth::setUserStoreIds($adminId, ['s_alpha']);
assert_eq([], Auth::getUserStoreIds($adminId), 'admins never carry grants');
expect_throws(fn() => Auth::setUserStoreIds('user_missing', ['s_alpha']),
    RuntimeException::class, 'unknown user refused');
Auth::setUserStoreIds($saId, ['s_alpha', 's_beta']);

// ---- cascades -------------------------------------------------------------------------
make_store('s_temp');
Auth::setUserStoreIds($userNoEmailId, ['s_gamma', 's_temp']);
Database::delete('stores', 'id = ?', ['s_temp']);
assert_eq(['s_gamma'], Auth::getUserStoreIds($userNoEmailId), 'deleting a store drops its grants');
$tmpUser = Auth::createUser('dave', 'dave-pw-1234', Auth::ROLE_USER, ['s_alpha']);
Auth::deleteUser($tmpUser);
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM user_store_access WHERE user_id = ?", [$tmpUser])['c'],
    'deleting a user drops its grants');

// ---- setUserRole ----------------------------------------------------------------------
expect_throws(fn() => Auth::setUserRole($saId, 'owner'), InvalidArgumentException::class, 'invalid role refused');
Auth::setUserRole($saId, Auth::ROLE_ADMIN);
assert_eq('admin', Auth::getUserById($saId)['role'], 'promoted');
assert_eq([], Auth::getUserStoreIds($saId), 'promotion to admin drops the grants');
Auth::setUserRole($saId, Auth::ROLE_STORE_ADMIN);
assert_eq('store_admin', Auth::getUserById($saId)['role'], 'demoted (three admins existed)');
Auth::setUserStoreIds($saId, ['s_alpha', 's_beta']);
Auth::setUserRole($userId, Auth::ROLE_STORE_ADMIN);
assert_eq(['s_beta'], Auth::getUserStoreIds($userId), 'user -> store admin keeps the grants');
Auth::setUserRole($userId, Auth::ROLE_USER);
// Last-admin guard: demote every admin but one, then try the last.
Auth::deleteUser($adminId);
$seedAdmin = Auth::getUserByUsername('admin');
expect_throws(fn() => Auth::setUserRole($seedAdmin['id'], Auth::ROLE_USER),
    RuntimeException::class, 'the only admin cannot be demoted');
assert_eq('admin', Auth::getUserById($seedAdmin['id'])['role']);
expect_throws(fn() => Auth::deleteUser($seedAdmin['id']), RuntimeException::class, 'the only admin cannot be deleted');

// ---- listUsers / findUserByEmail ---------------------------------------------------------
$list = Auth::listUsers();
assert_eq(['admin', 'alice', 'bob', 'carol'], array_column($list, 'username'), 'ordered admin, store admin, users');
$byName = array_column($list, null, 'username');
assert_eq([], $byName['admin']['storeIds'], 'admin row carries no grants');
$aliceIds = $byName['alice']['storeIds']; sort($aliceIds);
assert_eq(['s_alpha', 's_beta'], $aliceIds, 'store admin row carries its grants');
assert_eq('alice@example.com', $byName['alice']['email'], 'email in the list');
assert_true(!isset($byName['alice']['password_hash']), 'no hashes in the list');

Auth::setUserEmail($userId, 'Bob@Example.com');
$found = Auth::findUserByEmail('bob@example.com');
assert_eq('bob', $found['username'], 'a user-role account is found by e-mail (any role resets)');
Auth::setUserEmail($userNoEmailId, 'shared@example.com');
Auth::setUserEmail($seedAdmin['id'], 'shared@example.com');
assert_eq('admin', Auth::findUserByEmail('shared@example.com')['username'], 'admins win a shared address');
assert_eq('bob', Auth::findAdminByEmail('bob@example.com')['username'], 'legacy alias delegates');
assert_null(Auth::findUserByEmail(''), 'blank matches nothing');

// ---- migration: two-role CHECK widened, grants seeded, marker ---------------------------
$pdo = Database::getInstance();
// Rebuild the users table the way a pre-feature install has it.
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec("
    BEGIN;
    CREATE TABLE users_old (
        id TEXT PRIMARY KEY,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin','user')),
        created_at INTEGER NOT NULL,
        email TEXT DEFAULT NULL
    );
    INSERT INTO users_old (id, username, password_hash, role, created_at, email)
        SELECT id, username, password_hash, CASE role WHEN 'store_admin' THEN 'user' ELSE role END, created_at, email FROM users;
    DROP TABLE users;
    ALTER TABLE users_old RENAME TO users;
    DROP TABLE user_store_access;
    ALTER TABLE stores DROP COLUMN trusted_mints_enabled;
    COMMIT;
");
$pdo->exec('PRAGMA foreign_keys = ON');
// A live reset token must survive the rebuild.
$rawToken = Auth::createPasswordResetToken($userId);
assert_not_null(Auth::peekPasswordResetToken($rawToken), 'token valid before the migration');
$tokensBefore = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM password_reset_tokens")['c'];
expect_throws(fn() => $pdo->exec("INSERT INTO users (id, username, password_hash, role, created_at) VALUES ('u_x', 'x', 'h', 'store_admin', 1)"),
    PDOException::class, 'pre-feature CHECK refuses store_admin');
unset($pdo);
reset_db_singleton();
Database::getInstance(); // marker missing -> migrations re-run
assert_true(has_col('stores', 'trusted_mints_enabled'), 'marker column re-added');
$roleSql = (string)Database::fetchOne("SELECT sql FROM sqlite_master WHERE name = 'users'")['sql'];
assert_true(str_contains($roleSql, 'store_admin'), 'users CHECK widened');
assert_true(has_col('users', 'email'), 'email column preserved by the rebuild');
assert_eq('alice@example.com', Auth::getUserById($saId)['email'], 'email values preserved');
assert_eq($tokensBefore, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM password_reset_tokens")['c'],
    'reset tokens survive the rebuild (no cascade)');
assert_not_null(Auth::peekPasswordResetToken($rawToken), 'token still valid after the migration');
$seeded = Auth::getUserStoreIds($userId); sort($seeded);
assert_eq(['s_alpha', 's_beta', 's_gamma'], $seeded, "existing 'user' accounts are granted every store on upgrade");
assert_eq([], Auth::getUserStoreIds($seedAdmin['id']), 'admins get no grants on upgrade');
assert_eq(1, (int)Database::fetchOne("SELECT trusted_mints_enabled FROM stores WHERE id = 's_alpha'")['trusted_mints_enabled'],
    'trusted list stays on for existing stores');
Database::insert('users', ['id' => 'u_sa2', 'username' => 'erin', 'password_hash' => 'h', 'role' => 'store_admin', 'created_at' => 1]);
assert_eq('store_admin', Auth::getUserById('u_sa2')['role'], 'widened CHECK accepts store_admin');
// Re-run is a no-op: grants untouched, nothing duplicated.
Auth::setUserStoreIds($userId, ['s_beta']);
reset_db_singleton();
$pdo = Database::getInstance();
$ref = new ReflectionMethod('Database', 'runMigrations');
$ref->setAccessible(true);
$ref->invoke(null, $pdo);
assert_eq(['s_beta'], Auth::getUserStoreIds($userId), 'a second migration run does not re-seed');
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM sqlite_master WHERE name = 'users'")['c']);

// ---- TrustedMints per-store opt-out --------------------------------------------------
$list = ['version' => 1, 'mints' => [
    ['url' => 'https://t1.example.com'],
    ['url' => 'https://bad.example.com', 'disabled' => true, 'reason' => 'leaked'],
]];
file_put_contents($dataDir . '/' . TrustedMints::CACHE_FILENAME, json_encode($list));
make_store('s_on', 'https://own.example.com');
make_store('s_off', 'https://own.example.com');
add_backup_mint('s_off', 'https://bad.example.com', 50);
add_backup_mint('s_on', 'https://bad.example.com', 50);
assert_true(TrustedMints::appliesToStore('s_off'), 'default: list applies');
assert_true(TrustedMints::appliesToStore('s_unknown'), 'unknown store reports the site default');
TrustedMints::setAppliesToStore('s_off', false);
assert_false(TrustedMints::appliesToStore('s_off'));

TrustedMints::applyToAllStores();
$onBackups = array_column(Database::fetchAll("SELECT mint_url FROM store_mints WHERE store_id = 's_on'"), 'mint_url');
$offBackups = array_column(Database::fetchAll("SELECT mint_url FROM store_mints WHERE store_id = 's_off'"), 'mint_url');
assert_true(in_array('https://t1.example.com', $onBackups, true), 'opted-in store got the trusted backup');
assert_false(in_array('https://t1.example.com', $offBackups, true), 'opted-out store got nothing added');
TrustedMints::applyToNewStore('s_off');
$offBackups = array_column(Database::fetchAll("SELECT mint_url FROM store_mints WHERE store_id = 's_off'"), 'mint_url');
assert_false(in_array('https://t1.example.com', $offBackups, true), 'applyToNewStore respects the opt-out too');

assert_false(MintReliability::isAvailableForNewInvoices('https://bad.example.com'), 'disabled flag gates by default');
assert_true(MintReliability::isAvailableForNewInvoices('https://bad.example.com', false), 'opted-out stores ignore the flag');
assert_eq(['https://own.example.com', 'https://t1.example.com'], Config::getStoreAllMintUrls('s_on'), 'opted-in store: disabled mint filtered, trusted backup kept');
$offMints = Config::getStoreAllMintUrls('s_off');
assert_eq(['https://own.example.com', 'https://bad.example.com'], $offMints, 'opted-out store keeps its chosen mint');
// permanently_disabled is not a list policy: it always blocks.
Database::update('mint_reliability', ['permanently_disabled' => 1], 'mint_url = ?', ['https://bad.example.com']);
assert_eq(['https://own.example.com'], Config::getStoreAllMintUrls('s_off'), 'permanent disable still blocks an opted-out store');
TrustedMints::setAppliesToStore('s_off', true);
assert_true(TrustedMints::appliesToStore('s_off'), 'switched back on');

echo "test_store_admin_role: ok\n";
