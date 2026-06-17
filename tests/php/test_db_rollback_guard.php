<?php
/**
 * Database::rollback() must be safe to call when no transaction is active.
 * Settlement catch blocks now catch \Throwable and call rollback() on the way
 * out; if the failure happened before beginTransaction (or after commit), an
 * unguarded PDO::rollBack() would throw "no active transaction" and mask the
 * original error — and on the shared singleton PDO that cascades into later
 * writes. rollback() must no-op (return false) instead.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();

// 1. No active transaction: rollback() returns false, does not throw.
assert_false(Database::inTransaction(), 'no tx initially');
assert_false(Database::rollback(), 'rollback with no tx returns false');

// 2. Real transaction: inTransaction reflects state; rollback unwinds it.
Database::beginTransaction();
assert_true(Database::inTransaction(), 'inTransaction true after begin');
assert_true(Database::rollback(), 'rollback returns true for active tx');
assert_false(Database::inTransaction(), 'no tx after rollback');

// 3. Double rollback is harmless.
assert_false(Database::rollback(), 'second rollback no-ops');

// 4. A committed transaction is not rolled back afterwards.
Database::beginTransaction();
Database::commit();
assert_false(Database::inTransaction(), 'no tx after commit');
assert_false(Database::rollback(), 'rollback after commit no-ops');

echo "PASS test_db_rollback_guard\n";
exit(0);
