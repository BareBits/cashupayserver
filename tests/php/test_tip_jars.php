<?php
/**
 * Tip jars (includes/tip_jars.php): CRUD + validation, per-store name
 * uniqueness, the legacy /pay/{store} default-jar resolution, settled totals,
 * the invoice metadata snapshot / live-row fallback, and the upgrade
 * migration's default-jar backfill for stores that already had the feature on.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/tip_jars.php';
require_once dirname(__DIR__, 2) . '/app/includes/selfserve.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';

make_store('s1', 'https://mint.example');
make_store('s2', 'https://mint.example');
Config::set('base_url', 'https://pay.example.com');
Config::set('url_mode', 'clean');

/** Assert that $fn() throws InvalidArgumentException. */
function assert_rejects(callable $fn, string $msg): void {
    $threw = false;
    try { $fn(); } catch (InvalidArgumentException $e) { $threw = true; }
    assert_true($threw, $msg);
}

// ---------------- create ----------------

assert_eq(0, TipJar::countForStore('s1'), 'no jars to start with');
assert_null(TipJar::legacyDefault('s1'), 'no default jar when the store has none');

$coffee = TipJar::create('s1', ['name' => '  Coffee fund ', 'emoji' => '☕', 'message' => "Thanks!\nEvery sat helps."]);
assert_true(str_starts_with($coffee['id'], 'jar_'), 'id prefix');
assert_eq('Coffee fund', $coffee['name'], 'name trimmed');
assert_eq('☕', $coffee['emoji'], 'emoji stored');
assert_eq("Thanks!\nEvery sat helps.", $coffee['message'], 'message keeps its newline');
assert_eq(1, (int)$coffee['enabled'], 'enabled by default');
assert_eq('s1', $coffee['store_id'], 'store id');

$plain = TipJar::create('s1', ['name' => 'Donations']);
assert_null($plain['emoji'], 'no emoji -> NULL (default shown)');
assert_eq('', $plain['message'], 'no message -> empty string');

// Validation.
assert_rejects(fn() => TipJar::create('s1', ['name' => '']), 'empty name rejected');
assert_rejects(fn() => TipJar::create('s1', ['name' => "  \t "]), 'whitespace name rejected');
assert_rejects(fn() => TipJar::create('s1', ['name' => str_repeat('x', TipJar::MAX_NAME_LEN + 1)]), 'overlong name rejected');
assert_eq(TipJar::MAX_NAME_LEN, mb_strlen(TipJar::create('s1', ['name' => str_repeat('y', TipJar::MAX_NAME_LEN)])['name']), 'max-length name accepted');
assert_rejects(fn() => TipJar::create('s1', ['name' => 'Long msg', 'message' => str_repeat('m', TipJar::MAX_MESSAGE_LEN + 1)]), 'overlong message rejected');
assert_rejects(fn() => TipJar::create('s1', ['name' => 'Bad emoji', 'emoji' => str_repeat('🫙', 9)]), 'overlong emoji rejected');
assert_rejects(fn() => TipJar::create('s1', ['name' => 'Ctl emoji', 'emoji' => "\x01"]), 'control-char emoji rejected');
assert_rejects(fn() => TipJar::create('store_missing', ['name' => 'Orphan']), 'unknown store rejected');
// Multi-codepoint emoji are fine.
$fam = TipJar::create('s1', ['name' => 'Family', 'emoji' => '👨‍👩‍👧']);
assert_eq('👨‍👩‍👧', $fam['emoji'], 'ZWJ emoji accepted');
// Control characters in a name collapse to a space.
$ctl = TipJar::create('s1', ['name' => "Line\x00one\ttwo"]);
assert_eq('Line one two', $ctl['name'], 'control chars in name collapsed');

// Names are unique per store, case-insensitively — but free across stores.
assert_rejects(fn() => TipJar::create('s1', ['name' => 'coffee FUND']), 'duplicate name (case-insensitive) rejected');
$other = TipJar::create('s2', ['name' => 'Coffee fund']);
assert_eq('Coffee fund', $other['name'], 'same name allowed in another store');

// ---------------- get / list / update ----------------

assert_eq($coffee['id'], TipJar::get($coffee['id'], 's1')['id'], 'get by id + store');
assert_null(TipJar::get($coffee['id'], 's2'), 'get is store-scoped');
$ids = array_column(TipJar::listByStore('s1'), 'id');
assert_eq($coffee['id'], $ids[0], 'list is oldest first');
assert_eq(5, count($ids), 'list has every jar of the store');

$upd = TipJar::update($plain['id'], 's1', ['name' => 'Donations!', 'emoji' => '💝', 'message' => 'Thank you for your donation']);
assert_eq('Donations!', $upd['name'], 'name updated');
assert_eq('💝', $upd['emoji'], 'emoji updated');
assert_eq('Thank you for your donation', $upd['message'], 'message updated');
$upd = TipJar::update($plain['id'], 's1', ['enabled' => false]);
assert_eq(0, (int)$upd['enabled'], 'disabled');
assert_eq("Thank you for your donation", $upd['message'], 'partial update leaves other fields alone');
$upd = TipJar::update($plain['id'], 's1', ['emoji' => '']);
assert_null($upd['emoji'], 'clearing the emoji stores NULL');
assert_rejects(fn() => TipJar::update($plain['id'], 's1', ['name' => 'COFFEE fund']), 'rename onto another jar\'s name rejected');
$same = TipJar::update($coffee['id'], 's1', ['name' => 'coffee fund']);
assert_eq('coffee fund', $same['name'], 'a jar may re-case its own name');
assert_rejects(fn() => TipJar::update($coffee['id'], 's2', ['name' => 'x']), 'update is store-scoped');
assert_rejects(fn() => TipJar::update('jar_nope', 's1', ['name' => 'x']), 'unknown jar rejected');

// Only enabled jars are listed with $onlyEnabled.
assert_eq(4, count(TipJar::listByStore('s1', true)), 'onlyEnabled skips the disabled jar');

// ---------------- legacy default ----------------

// Oldest ENABLED jar wins; disabling it moves the default to the next one.
assert_eq($coffee['id'], TipJar::legacyDefault('s1')['id'], 'oldest enabled jar is the default');
TipJar::update($coffee['id'], 's1', ['enabled' => false]);
$next = TipJar::legacyDefault('s1');
assert_not_null($next, 'a default still exists');
assert_neq($coffee['id'], $next['id'], 'disabled jar is no longer the default');
assert_neq($plain['id'], $next['id'], 'the other disabled jar is skipped too');
TipJar::update($coffee['id'], 's1', ['enabled' => true]);
assert_eq($coffee['id'], TipJar::legacyDefault('s1')['id'], 're-enabled jar is the default again');

// ensureDefault: no-op when jars exist, creates "Tip Jar" otherwise.
assert_null(TipJar::ensureDefault('s1'), 'ensureDefault is a no-op with jars present');
make_store('s3', 'https://mint.example');
$made = TipJar::ensureDefault('s3');
assert_not_null($made, 'ensureDefault creates a jar');
assert_eq(TipJar::DEFAULT_NAME, $made['name'], 'default jar name');
assert_null(TipJar::ensureDefault('s3'), 'second call is a no-op');
assert_eq(1, TipJar::countForStore('s3'), 'exactly one jar');

// ---------------- totals ----------------

$now = time();
$mkInvoice = function (string $id, string $store, ?string $jar, string $status, int $sats, ?string $metadata = null) use ($now) {
    Database::insert('invoices', [
        'id' => $id,
        'store_id' => $store,
        'status' => $status,
        'amount' => (string)$sats,
        'currency' => 'SAT',
        'amount_sats' => $sats,
        'metadata' => $metadata,
        'created_at' => $now,
        'expiration_time' => $now + 3600,
        'tip_jar_id' => $jar,
    ]);
};
$mkInvoice('inv_c1', 's1', $coffee['id'], 'Settled', 1000);
$mkInvoice('inv_c2', 's1', $coffee['id'], 'Settled', 250);
$mkInvoice('inv_c3', 's1', $coffee['id'], 'New', 99999);      // unpaid: not counted
$mkInvoice('inv_c4', 's1', $coffee['id'], 'Expired', 5000);   // never paid: not counted
$mkInvoice('inv_d1', 's1', $plain['id'], 'Settled', 42);
$mkInvoice('inv_n1', 's1', null, 'Settled', 777);             // not from a jar
$mkInvoice('inv_o1', 's2', $other['id'], 'Settled', 10);      // other store

$totals = TipJar::receivedByStore('s1');
assert_eq(['sats' => 1250, 'count' => 2], $totals[$coffee['id']], 'settled sats + count per jar');
assert_eq(['sats' => 42, 'count' => 1], $totals[$plain['id']], 'second jar totals');
assert_false(isset($totals[$fam['id']]), 'jar with no payments is absent');
assert_false(isset($totals[$other['id']]), 'other store\'s jar is not in this store\'s totals');

$api = TipJar::formatForApi(TipJar::get($coffee['id'], 's1'), $totals[$coffee['id']]);
assert_eq(1250, $api['receivedSats'], 'formatForApi carries the total');
assert_eq(2, $api['receivedCount'], 'formatForApi carries the count');
assert_eq('☕', $api['emoji'], 'formatForApi emoji');
assert_eq('https://pay.example.com/pay/s1/' . $coffee['id'], $api['url'], 'formatForApi url');
$apiPlain = TipJar::formatForApi(TipJar::get($plain['id'], 's1'));
assert_eq(TipJar::DEFAULT_EMOJI, $apiPlain['emoji'], 'default emoji resolved for display');
assert_null($apiPlain['emojiRaw'], 'raw emoji stays null');
assert_eq(0, $apiPlain['receivedSats'], 'no totals -> 0');

// ---------------- metadata snapshot + forInvoice ----------------

$snap = TipJar::metadataSnapshot(TipJar::get($coffee['id'], 's1'));
assert_eq($coffee['id'], $snap['tipJarId'], 'snapshot id');
assert_eq('coffee fund', $snap['tipJarName'], 'snapshot name');
$mkInvoice('inv_snap', 's1', $coffee['id'], 'Settled', 5, json_encode($snap));

$live = TipJar::forInvoice(Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_snap'"));
assert_true($live['live'], 'live row preferred');
assert_eq('coffee fund', $live['name'], 'live name');
TipJar::update($coffee['id'], 's1', ['name' => 'Coffee Fund 2', 'message' => 'edited']);
$live = TipJar::forInvoice(Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_snap'"));
assert_eq('Coffee Fund 2', $live['name'], 'edits show through on existing invoices');
assert_eq('edited', $live['message'], 'edited message shows');
assert_null(TipJar::forInvoice(Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_n1'")), 'non-jar invoice -> null');

// ---------------- delete ----------------

TipJar::delete($coffee['id'], 's2'); // wrong store: no-op
assert_not_null(TipJar::get($coffee['id'], 's1'), 'delete is store-scoped');
TipJar::delete($coffee['id'], 's1');
assert_null(TipJar::get($coffee['id'], 's1'), 'deleted');
$row = Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_snap'");
assert_null($row['tip_jar_id'], 'invoice reference nulled on delete');
assert_eq('Settled', $row['status'], 'invoice itself survives');
$fallback = TipJar::forInvoice($row);
assert_not_null($fallback, 'snapshot fallback after delete');
assert_false($fallback['live'], 'marked as not live');
assert_eq('coffee fund', $fallback['name'], 'snapshot name (pre-edit, as created)');
assert_eq('☕', $fallback['emoji'], 'snapshot emoji');
assert_null(TipJar::forInvoice(Database::fetchOne("SELECT * FROM invoices WHERE id = 'inv_c1'")), 'deleted jar + no snapshot -> null');
assert_false(isset(TipJar::receivedByStore('s1')[$coffee['id']]), 'deleted jar drops out of the totals');
TipJar::delete('jar_nope', 's1'); // unknown: no-op, no throw

// ---------------- upgrade migration backfill ----------------
// Stores with the master switch already on get a default jar when the
// tip_jar_id column is first added; stores with it off, or that already own a
// jar, do not. Simulate the pre-upgrade state (column + table absent).
make_store('s_on', 'https://mint.example');
make_store('s_off', 'https://mint.example');
make_store('s_has', 'https://mint.example');
SelfServe::setStoreOverride('s_on', SelfServe::FORCE_ON);
SelfServe::setStoreOverride('s_off', SelfServe::FORCE_OFF);
SelfServe::setStoreOverride('s_has', SelfServe::FORCE_ON);
SelfServe::setStoreOverride('s1', SelfServe::FORCE_OFF);
SelfServe::setStoreOverride('s2', SelfServe::FORCE_OFF);
SelfServe::setStoreOverride('s3', SelfServe::FORCE_OFF);
$pdo = Database::getInstance();
// The current getInstance() marker is the store-admin set (and the rewards
// and Cashu-maximum sets before it): drop them too, or the marker is still
// present and migrations never re-run.
$pdo->exec("ALTER TABLE stores DROP COLUMN trusted_mints_enabled");
$pdo->exec("DROP TABLE user_store_access");
$pdo->exec("ALTER TABLE stores DROP COLUMN rewards_percent");
$pdo->exec("ALTER TABLE stores DROP COLUMN rewards_enabled");
$pdo->exec("ALTER TABLE stores DROP COLUMN rewards_currency");
$pdo->exec("DROP TABLE rewards_ledger");
$pdo->exec("DROP TABLE rewards_accounts");
foreach (['rewards_account_id', 'rewards_discount_amount', 'rewards_applied_micro', 'rewards_spend_state',
          'rewards_earned_micro', 'rewards_earn_currency', 'rewards_claimed_at',
          'onchain_amount_alternates', 'ecx_amount_alternates'] as $c) {
    $pdo->exec("ALTER TABLE invoices DROP COLUMN {$c}");
}
$pdo->exec("ALTER TABLE stores DROP COLUMN cashu_max_invoice_sats");
$pdo->exec("ALTER TABLE stores DROP COLUMN cashu_max_respect_always");
$pdo->exec("UPDATE invoices SET tip_jar_id = NULL");
$pdo->exec("ALTER TABLE invoices DROP COLUMN tip_jar_id");
$pdo->exec("DROP TABLE tip_jars");
$ref = new ReflectionProperty('Database', 'instance');
$ref->setAccessible(true);
$ref->setValue(null, null);
Database::getInstance(); // marker missing -> migrations re-run
assert_eq(1, TipJar::countForStore('s_on'), 'store with the switch on got a default jar');
assert_eq(TipJar::DEFAULT_NAME, TipJar::legacyDefault('s_on')['name'], 'backfilled jar is the default one');
assert_eq(0, TipJar::countForStore('s_off'), 'store with the switch off got nothing');
assert_eq(0, TipJar::countForStore('s1'), 'switched-off store with (now dropped) jars got nothing');
// A second migration run (marker present) must not add more jars, even for a
// store that has since deleted its only jar on purpose.
TipJar::delete(TipJar::legacyDefault('s_on')['id'], 's_on');
$ref->setValue(null, null);
Database::getInstance();
assert_eq(0, TipJar::countForStore('s_on'), 'no re-backfill once the column exists');

echo "test_tip_jars: ok\n";
