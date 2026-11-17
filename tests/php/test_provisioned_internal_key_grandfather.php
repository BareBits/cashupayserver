<?php
/**
 * provision.php used to hand the WordPress alongside install the store's
 * internal dashboard key, which carries only cancreateinvoice. Now that the
 * Greenfield API enforces key permissions, a shop upgraded with that key in
 * its gateway settings would 403 on webhook registration and invoice reads.
 *
 * A data-only migration widens that key once — to the default store key set
 * provision.php mints today — but ONLY on installs that actually provisioned
 * (config holds provision_consumed_at). Elsewhere the internal key was never
 * handed out and must stay create-only, because the dashboard payload gives
 * it to the limited user role for Request Payment.
 *
 * runMigrations() fires for an already-current install only when the gate in
 * Database::getInstance() detects the condition, so this test seeds the
 * legacy state, drops the connection singleton, and asserts the next
 * connection performs (or, without the stamp, refrains from) the grant.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';

/** Force the next Database call to reconnect and re-run the migration gate. */
function reset_db_singleton(): void {
    $ref = new ReflectionProperty('Database', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    $cacheRef = new ReflectionProperty('Config', 'cache');
    $cacheRef->setAccessible(true);
    $cacheRef->setValue(null, []);
}

function perms_of(string $rawKey): ?array {
    $row = Database::fetchOne("SELECT permissions FROM api_keys WHERE key_hash = ?", [hash('sha256', $rawKey)]);
    return $row === null ? null : json_decode((string)$row['permissions'], true);
}

make_store('store_a');
make_store('store_b');
$internalA = (string)Auth::getOrCreateInternalApiKey('store_a');
$internalB = (string)Auth::getOrCreateInternalApiKey('store_b');
assert_eq([Auth::PERM_CREATE_INVOICE], perms_of($internalA), 'a fresh internal key is create-only');

// An ordinary create-only key minted by an operator must never be touched:
// the grant is keyed on the internal label, not on the permission set alone.
$narrow = Auth::createApiKey('store_a', 'POS terminal', [Auth::PERM_CREATE_INVOICE])['key'];

// --- 1. Never provisioned: the internal key stays create-only --------------
reset_db_singleton();
assert_eq([Auth::PERM_CREATE_INVOICE], perms_of($internalA), 'without provision_consumed_at the internal key is left create-only');
assert_eq([Auth::PERM_CREATE_INVOICE], perms_of($narrow), 'and so is the operator key');

// --- 2. Provisioned install: the internal keys are widened once ------------
Config::set('provision_consumed_at', time() - 86400);
reset_db_singleton();
assert_eq(Auth::DEFAULT_STORE_KEY_PERMISSIONS, perms_of($internalA), 'a provisioned install\'s internal key gets the default store key set');
assert_eq(Auth::DEFAULT_STORE_KEY_PERMISSIONS, perms_of($internalB), 'for every store, since provision hands out the first-created one but the row label is per store');
assert_eq([Auth::PERM_CREATE_INVOICE], perms_of($narrow), 'the operator\'s create-only key is untouched');

// The widened key now passes the checks the plugin's calls go through.
$auth = ['permissions' => perms_of($internalA)];
assert_true(Auth::hasPermission($auth, Auth::PERM_MODIFY_WEBHOOKS), 'webhook management is granted');
assert_true(Auth::hasPermission($auth, Auth::PERM_VIEW_INVOICES), 'invoice reads are granted');
assert_true(!Auth::hasPermission($auth, '*'), 'but never the server-wide wildcard');

// --- 3. Idempotent: a second connection changes nothing ---------------------
$before = Database::fetchAll("SELECT id, permissions FROM api_keys ORDER BY id");
reset_db_singleton();
Database::getInstance();
$after = Database::fetchAll("SELECT id, permissions FROM api_keys ORDER BY id");
assert_eq($before, $after, 'a second run is a no-op');

// --- 4. A key an operator already re-scoped is not reverted -----------------
Database::update('api_keys', ['permissions' => json_encode([Auth::PERM_CREATE_INVOICE, Auth::PERM_VIEW_INVOICES])], 'key_hash = ?', [hash('sha256', $internalB)]);
reset_db_singleton();
assert_eq([Auth::PERM_CREATE_INVOICE, Auth::PERM_VIEW_INVOICES], perms_of($internalB), 'only the exact legacy create-only set is widened');

echo "test_provisioned_internal_key_grandfather: ok\n";
