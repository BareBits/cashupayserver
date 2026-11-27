<?php
/**
 * admin.php gates for the three session roles, driven over real HTTP
 * (php -S + cookie jars): admin, a store admin holding store A only, and a
 * user holding store A only, against stores A and B.
 *
 *  Reads (?api=)
 *   - dashboard / stores list only the caller's stores; a foreign store_id
 *     reads as 404 (not 403: its existence is not disclosed);
 *   - invoices: a foreign store_id is 403; no store_id lists only the
 *     caller's stores; invoice_detail of a foreign invoice is 404;
 *   - products / tip_jars: own store 200, foreign 403;
 *   - api_keys / export_info / proofs / list_transfers / products_manage:
 *     store admin 200 on A, 403 on B; user 403 on A;
 *   - stats_summary / customers / rewards_accounts / export_all_invoices_csv:
 *     store admin: '__all__' covers only A, store B explicit is 403; user 403;
 *   - global admin reads (cron_url, update_status, get_trusted_mints_settings,
 *     export_diagnostic_report, scan_stranded_funds): store admin 403.
 *  Writes (action=)
 *   - store-scoped settings (update_store, save_store_privacy, save_cashu_limit,
 *     save_store_selfserve, create_tip_jar, create_product, save_rewards_settings,
 *     save_store_notifications, save_store_trusted_mints, create_api_key):
 *     store admin on A 200, on B 403, user 403;
 *   - indirect ids: delete_api_key of a key on B is 403 for the store admin
 *     and the key survives; remove_backup_mint of a row on B likewise;
 *   - helpers (generate_seed, validate_seed, test_mint URL policy,
 *     validate_onchain_xpub, upload_product_image): store admin passes the
 *     role gate, user 403;
 *   - global writes (create_store, delete_store, save_url_mode,
 *     save_notifications_settings, save_login_timeout, save_trusted_mints_settings,
 *     admin_reenable_mint, save_developer_settings): store admin 403;
 *   - send_test_notification: with the held store's id it is the store
 *     admin's to call (SMTP not configured -> 400, not 403); without one 403;
 *   - get_admin_log: store admin '__all__' sees only store-A rows.
 *  User management
 *   - list_users: admin sees all; store admin sees admins (read-only), itself,
 *     users on A, and the user on A+B with only A disclosed (hiddenStoreCount
 *     1, canManage false, canEditStores true); the user on B only is absent;
 *     a user with no stores is listed as manageable;
 *   - create_user by store admin: user on A ok; store_admin on A ok; admin
 *     role refused; store B refused; user role for the plain user 403;
 *   - update_user: store admin toggles the shared store of the A+B user
 *     (B grant kept), cannot change its role / e-mail, cannot grant B;
 *     admin can change roles; last-admin demotion refused;
 *   - delete_user / reset_password: store admin on an A-only user ok, on the
 *     A+B user refused, on an admin refused, on itself refused;
 *   - set_recovery_email: a plain user may set its own; request_password_reset
 *     for that address mints a token for the user-role account.
 *  Pairing (api-keys/authorize.php)
 *   - store admin approve on A mints a key, on B is refused; user sees the
 *     cannot-pair notice and approve does nothing.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';
require_once dirname(__DIR__, 2) . '/app/includes/security.php';
require_once dirname(__DIR__, 2) . '/app/includes/admin_log.php';
require_once dirname(__DIR__, 2) . '/app/includes/tip_jars.php';
require_once dirname(__DIR__, 2) . '/app/includes/selfserve.php';

Auth::setAdminPassword('admin-pw-1234');
Config::set('allow_private_endpoints', false);
Config::set('last_external_cron_at', time());
Config::set('rate_usd', ['rate' => 100000.0, 'timestamp' => time(), 'provider' => 'stub']);

make_store('store_a', 'https://mint-a.example');
Database::update('stores', ['name' => 'Alpha'], 'id = ?', ['store_a']);
make_store('store_b', 'https://mint-b.example');
Database::update('stores', ['name' => 'Beta'], 'id = ?', ['store_b']);
add_backup_mint('store_b', 'https://backup-b.example', 10);
$backupBId = (int)Database::fetchOne("SELECT id FROM store_mints WHERE store_id = 'store_b'")['id'];
$keyB = Auth::createApiKey('store_b', 'beta-key');
$keyA = Auth::createApiKey('store_a', 'alpha-key');

$saId = Auth::createUser('sa_alpha', 'sa-alpha-pw-1234', Auth::ROLE_STORE_ADMIN, ['store_a'], 'sa@example.com');
$userAId = Auth::createUser('user_alpha', 'user-alpha-pw-1234', Auth::ROLE_USER, ['store_a']);
$userABId = Auth::createUser('user_both', 'user-both-pw-1234', Auth::ROLE_USER, ['store_a', 'store_b']);
$userBId = Auth::createUser('user_beta', 'user-beta-pw-1234', Auth::ROLE_USER, ['store_b']);
$userNoneId = Auth::createUser('user_none', 'user-none-pw-1234', Auth::ROLE_USER, ['store_a']);
Database::delete('user_store_access', 'user_id = ?', [$userNoneId]); // its only store "was deleted"

$now = time();
foreach ([['inv_a1', 'store_a'], ['inv_b1', 'store_b']] as [$iid, $sid]) {
    Database::insert('invoices', [
        'id' => $iid, 'store_id' => $sid, 'amount' => '100', 'currency' => 'sat', 'status' => 'Settled',
        'payment_rail' => 'mint', 'created_at' => $now - 60, 'expiration_time' => $now + 3000, 'paid_at' => $now - 30,
        'customer_email' => $sid . '@payer.example',
    ]);
}
AdminLog::log('security', 'admin', 'store_a', null, 'seed', 'alpha event');
AdminLog::log('security', 'admin', 'store_b', null, 'seed', 'beta event');
AdminLog::log('security', 'admin', null, null, 'seed', 'site event');

$srv = HttpDriver::start($dataDir);

function login(HttpDriver $srv, string $user, string $pw): string {
    $srv->newSession();
    $r = $srv->post('/admin.php', ['action' => 'login', 'username' => $user, 'password' => $pw]);
    assert_eq(200, $r['status'], "$user login: " . $r['body']);
    $tok = json_decode($r['body'], true)['csrfToken'] ?? '';
    assert_true($tok !== '', 'csrf token issued');
    return $tok;
}
function act(HttpDriver $srv, string $tok, string $action, array $fields = []): array {
    return $srv->post('/admin.php', ['action' => $action] + $fields, ['X-CSRF-Token' => $tok]);
}
function j(array $r): array {
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : [];
}

// =====================================================================================
// Store admin (store A)
// =====================================================================================
$tok = login($srv, 'sa_alpha', 'sa-alpha-pw-1234');

// ---- reads ----
$r = $srv->get('/admin.php?api=dashboard');
assert_eq(200, $r['status']);
assert_eq(['store_a'], array_column(j($r)['stores'], 'id'), 'dashboard lists only the held store');
$r = $srv->get('/admin.php?api=dashboard&store_id=store_b');
assert_eq(404, $r['status'], 'foreign store on the dashboard reads as not found');
$r = $srv->get('/admin.php?api=dashboard&store_id=store_a');
assert_eq(200, $r['status']);
assert_true(isset(j($r)['trustedMints']['enabled']), 'dashboard payload carries the trusted-mints switch');
$r = $srv->get('/admin.php?api=stores');
assert_eq(['store_a'], array_column(j($r), 'id'), 'stores list scoped');

$r = $srv->get('/admin.php?api=invoices');
assert_eq(200, $r['status']);
assert_eq(['inv_a1'], array_column(j($r), 'id'), 'invoice list without store_id covers only held stores');
$r = $srv->get('/admin.php?api=invoices&store_id=store_b');
assert_eq(403, $r['status'], 'foreign store invoices refused');
$r = $srv->get('/admin.php?api=invoice_detail&id=inv_b1');
assert_eq(404, $r['status'], 'foreign invoice detail is not found');
$r = $srv->get('/admin.php?api=invoice_detail&id=inv_a1');
assert_eq(200, $r['status']);
assert_true(j($r)['isAdmin'] === true, 'store admin gets the manager view of its invoice');

foreach (['products', 'tip_jars', 'get_backup_mints', 'get_offline_cashu'] as $api) {
    $r = $srv->get("/admin.php?api={$api}&store_id=store_a");
    assert_eq(200, $r['status'], "$api own store: " . $r['body']);
    $r = $srv->get("/admin.php?api={$api}&store_id=store_b");
    assert_eq(403, $r['status'], "$api foreign store");
}
foreach (['api_keys', 'export_info', 'proofs', 'list_transfers', 'products_manage'] as $api) {
    $r = $srv->get("/admin.php?api={$api}&store_id=store_a");
    assert_eq(200, $r['status'], "$api own store: " . $r['body']);
    $r = $srv->get("/admin.php?api={$api}&store_id=store_b");
    assert_eq(403, $r['status'], "$api foreign store: " . $r['body']);
}
$labels = array_column(j($srv->get('/admin.php?api=api_keys&store_id=store_a')), 'label');
assert_true(in_array('alpha-key', $labels, true) && !in_array('beta-key', $labels, true), 'only store A keys listed');

// Reporting: '__all__' is the held set.
$r = $srv->get('/admin.php?api=stats_summary&store_id=__all__&range=all');
assert_eq(200, $r['status'], 'stats all: ' . $r['body']);
$r = $srv->get('/admin.php?api=stats_summary&store_id=store_b&range=all');
assert_eq(403, $r['status'], 'stats of a foreign store refused');
$r = $srv->get('/admin.php?api=customers&store_id=__all__');
assert_eq(200, $r['status']);
assert_eq(['store_a@payer.example'], array_column(j($r)['customers'], 'email'), "customers 'all' = held stores");
$r = $srv->get('/admin.php?api=customers&store_id=store_b');
assert_eq(403, $r['status']);
$r = $srv->get('/admin.php?api=rewards_accounts&store_id=__all__');
assert_eq(200, $r['status']);
assert_eq(['store_a'], array_column(j($r)['stores'], 'id'), 'rewards store list scoped');
$r = $srv->get('/admin.php?api=rewards_accounts&store_id=store_b');
assert_eq(404, $r['status'], 'rewards of a foreign store: not found');
$r = $srv->get('/admin.php?api=export_all_invoices_csv&store_id=__all__');
assert_eq(200, $r['status']);
assert_true(str_contains($r['body'], 'inv_a1') && !str_contains($r['body'], 'inv_b1'), 'CSV export scoped');
$r = $srv->get('/admin.php?api=export_customers_csv&store_id=__all__');
assert_true(str_contains($r['body'], 'store_a@payer') && !str_contains($r['body'], 'store_b@payer'), 'customers CSV scoped');
$r = act($srv, $tok, 'get_admin_log', ['store_id' => '__all__']);
assert_eq(200, $r['status']);
$msgs = implode(' ', array_column(j($r)['entries'] ?? j($r)['rows'] ?? [], 'message'));
assert_true(str_contains($msgs, 'alpha event'), 'log shows the held store: ' . $r['body']);
assert_false(str_contains($msgs, 'beta event'), 'log hides the foreign store');
assert_false(str_contains($msgs, 'site event'), 'log hides site-wide entries');
$r = act($srv, $tok, 'get_admin_log', ['store_id' => 'store_b']);
assert_eq(403, $r['status'], 'foreign store log refused');

foreach (['cron_url', 'update_status', 'get_trusted_mints_settings', 'export_diagnostic_report', 'scan_stranded_funds',
          'usage_stats_status', 'get_login_timeout', 'list_disabled_mints'] as $api) {
    $r = $srv->get("/admin.php?api={$api}");
    assert_eq(403, $r['status'], "$api is admin-only");
}

// ---- store-scoped writes ----
$writes = [
    'update_store' => ['name' => 'Alpha renamed'],
    'save_store_privacy' => ['hide_store_name' => '1', 'hide_note' => '0'],
    'save_cashu_limit' => ['max_sats' => '5000', 'respect_always' => '1'],
    'save_store_selfserve' => ['enabled' => '1', 'max_sats' => ''],
    'create_tip_jar' => ['name' => 'Jar', 'emoji' => '🙏', 'message' => 'thanks'],
    'create_product' => ['title' => 'Widget', 'price' => '10', 'enabled' => '1'],
    'save_rewards_settings' => ['enabled' => '1', 'percent' => '1'],
    'save_store_notifications' => ['enabled' => '0', 'email' => ''],
    'save_store_trusted_mints' => ['enabled' => '0'],
    'save_payment_order' => ['first_method' => 'lightning'],
];
foreach ($writes as $action => $fields) {
    $r = act($srv, $tok, $action, ['store_id' => 'store_a'] + $fields);
    assert_true(in_array($r['status'], [200], true), "$action on the held store: {$r['status']} {$r['body']}");
    $r = act($srv, $tok, $action, ['store_id' => 'store_b'] + $fields);
    assert_eq(403, $r['status'], "$action on a foreign store");
}
assert_eq('Alpha renamed', Config::getStore('store_a')['name'], 'rename landed');
assert_eq('Beta', Config::getStore('store_b')['name'], 'foreign store untouched');
assert_eq(0, (int)Config::getStore('store_a')['trusted_mints_enabled'], 'trusted mints opt-out saved');
assert_eq(1, (int)Config::getStore('store_b')['trusted_mints_enabled']);
$r = act($srv, $tok, 'save_store_trusted_mints', ['store_id' => 'store_a', 'enabled' => '1']);
assert_eq(200, $r['status']);
assert_eq(1, (int)Config::getStore('store_a')['trusted_mints_enabled'], 'switched back on');

$r = act($srv, $tok, 'create_api_key', ['store_id' => 'store_a', 'label' => 'sa-made']);
assert_eq(200, $r['status'], 'store admin mints a key on its store: ' . $r['body']);
$made = j($r);
assert_eq(Auth::DEFAULT_STORE_KEY_PERMISSIONS, $made['permissions'], 'store-level permissions only, no wildcard');
$r = act($srv, $tok, 'create_api_key', ['store_id' => 'store_b', 'label' => 'nope']);
assert_eq(403, $r['status'], 'no key on a foreign store');
$r = act($srv, $tok, 'delete_api_key', ['key_id' => $keyB['id']]);
assert_eq(403, $r['status'], 'deleting a foreign store key refused');
assert_not_null(Database::fetchOne("SELECT id FROM api_keys WHERE id = ?", [$keyB['id']]), 'foreign key survives');
$r = act($srv, $tok, 'delete_api_key', ['key_id' => $made['id']]);
assert_eq(200, $r['status'], 'own key deleted');
$r = act($srv, $tok, 'remove_backup_mint', ['id' => (string)$backupBId]);
assert_eq(403, $r['status'], 'removing a foreign backup mint refused');
assert_not_null(Database::fetchOne("SELECT id FROM store_mints WHERE id = ?", [$backupBId]), 'foreign backup survives');
$r = act($srv, $tok, 'set_invoice_allow_any_mint', ['invoice_id' => 'inv_b1', 'allow' => '1']);
assert_eq(403, $r['status'], 'foreign invoice flag refused');
$r = act($srv, $tok, 'transfer_token', ['id' => 'tr_missing']);
assert_eq(403, $r['status'], 'unknown transfer id refused below admin (not 404)');

// helpers a store admin needs
$r = act($srv, $tok, 'generate_seed');
assert_eq(200, $r['status'], 'generate_seed for a store admin');
assert_true(count(explode(' ', j($r)['seedPhrase'])) >= 12);
$r = act($srv, $tok, 'validate_seed', ['seed_phrase' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about']);
assert_eq(200, $r['status']);
$r = act($srv, $tok, 'test_mint', ['mint_url' => 'file:///etc/passwd']);
assert_eq(200, $r['status'], 'test_mint reaches the URL policy');
assert_true(j($r)['success'] === false, 'policy refuses file://');
$r = act($srv, $tok, 'validate_onchain_xpub', ['xpub' => 'not-an-xpub', 'network' => 'mainnet']);
assert_true(in_array($r['status'], [200, 400], true), 'validate_onchain_xpub passes the role gate: ' . $r['status']);
$r = act($srv, $tok, 'send_test_notification', ['store_id' => 'store_a', 'to' => 'x@example.com']);
assert_eq(400, $r['status'], 'held store test mail: past the gate (SMTP unset -> 400): ' . $r['body']);
$r = act($srv, $tok, 'send_test_notification', ['to' => 'x@example.com']);
assert_eq(403, $r['status'], 'global test mail stays admin-only');
$r = act($srv, $tok, 'send_test_notification', ['store_id' => 'store_b', 'to' => 'x@example.com']);
assert_eq(403, $r['status']);

// global writes
foreach ([
    'create_store' => ['name' => 'Nope'],
    'delete_store' => ['store_id' => 'store_a'],
    'save_url_mode' => ['mode' => 'router'],
    'save_notifications_settings' => ['enabled' => '0'],
    'save_login_timeout' => ['seconds' => '3600'],
    'save_trusted_mints_settings' => ['url' => ''],
    'admin_reenable_mint' => ['mint_url' => 'https://mint-a.example'],
    'save_developer_settings' => [],
    'save_auto_update_enabled' => ['enabled' => '0'],
    'save_update_channel' => ['channel' => 'stable'],
    'refresh_trusted_mints' => [],
    'reset_all_mint_counters' => [],
] as $action => $fields) {
    $r = act($srv, $tok, $action, $fields);
    assert_eq(403, $r['status'], "$action is admin-only: " . $r['body']);
}
assert_eq(2, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM stores")['c'], 'no store created or deleted');

// ---- user management as the store admin ----
$r = act($srv, $tok, 'list_users');
assert_eq(200, $r['status']);
$lu = j($r);
$byName = array_column($lu['users'], null, 'username');
assert_eq(['store_a'], array_column($lu['stores'], 'id'), 'grantable stores = held stores');
assert_eq(['store_admin', 'user'], $lu['roles'], 'a store admin may hand out these roles');
assert_true(isset($byName['admin']) && $byName['admin']['canManage'] === false && $byName['admin']['canEditStores'] === false, 'admin listed read-only');
assert_true($byName['sa_alpha']['isSelf'] === true && $byName['sa_alpha']['canManage'] === false, 'self not manageable here');
assert_true($byName['user_alpha']['canManage'] === true, 'A-only user fully manageable');
assert_true(isset($byName['user_both']), 'A+B user listed');
assert_eq(['store_a'], $byName['user_both']['storeIds'], 'only the shared store disclosed');
assert_eq(1, $byName['user_both']['hiddenStoreCount']);
assert_true($byName['user_both']['canManage'] === false && $byName['user_both']['canEditStores'] === true, 'A+B user: shared stores editable only');
assert_true(!isset($byName['user_beta']), 'B-only user not listed');
assert_true(isset($byName['user_none']) && $byName['user_none']['canManage'] === true, 'store-less user manageable');

$r = act($srv, $tok, 'create_user', ['username' => 'sa_made_user', 'password' => 'sa-made-pw-1234', 'role' => 'user', 'store_ids' => ['store_a'], 'email' => 'made@example.com']);
assert_eq(200, $r['status'], 'store admin creates a user: ' . $r['body']);
$madeUserId = j($r)['id'];
assert_eq(['store_a'], Auth::getUserStoreIds($madeUserId));
assert_eq('made@example.com', Auth::getUserById($madeUserId)['email']);
$r = act($srv, $tok, 'create_user', ['username' => 'sa_made_sa', 'password' => 'sa-made-pw-1234', 'role' => 'store_admin', 'store_ids' => ['store_a']]);
assert_eq(200, $r['status'], 'store admin creates a store admin: ' . $r['body']);
$madeSaId = j($r)['id'];
$r = act($srv, $tok, 'create_user', ['username' => 'sa_made_admin', 'password' => 'sa-made-pw-1234', 'role' => 'admin']);
assert_eq(400, $r['status'], 'store admin cannot create an admin');
$r = act($srv, $tok, 'create_user', ['username' => 'sa_made_b', 'password' => 'sa-made-pw-1234', 'role' => 'user', 'store_ids' => ['store_a', 'store_b']]);
assert_eq(400, $r['status'], 'store admin cannot grant a store it does not hold');
assert_null(Auth::getUserByUsername('sa_made_b'));
$r = act($srv, $tok, 'create_user', ['username' => 'sa_made_none', 'password' => 'sa-made-pw-1234', 'role' => 'user']);
assert_eq(400, $r['status'], 'a user needs a store');

// update_user on the A+B user: toggle the shared store off, B stays.
$r = act($srv, $tok, 'update_user', ['user_id' => $userABId, 'store_ids' => ['']]);
assert_eq(200, $r['status'], 'shared store removed: ' . $r['body']);
assert_eq(['store_b'], Auth::getUserStoreIds($userABId), 'the foreign grant is kept');
$r = act($srv, $tok, 'list_users');
assert_true(!isset(array_column(j($r)['users'], null, 'username')['user_both']), 'no shared store any more: dropped from the list');
Auth::setUserStoreIds($userABId, ['store_a', 'store_b']);
$r = act($srv, $tok, 'update_user', ['user_id' => $userABId, 'role' => 'store_admin']);
assert_eq(400, $r['status'], 'cannot change the role of a partially held account');
$r = act($srv, $tok, 'update_user', ['user_id' => $userABId, 'email' => 'x@example.com']);
assert_eq(400, $r['status'], 'cannot change the e-mail of a partially held account');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'store_ids' => ['store_b']]);
assert_eq(400, $r['status'], 'cannot grant a store it does not hold');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'store_admin', 'email' => 'ua@example.com']);
assert_eq(200, $r['status'], 'user -> store admin on a fully held account: ' . $r['body']);
assert_eq('store_admin', Auth::getUserById($userAId)['role']);
assert_eq('ua@example.com', Auth::getUserById($userAId)['email']);
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'admin']);
assert_eq(400, $r['status'], 'cannot grant admin');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'user']);
assert_eq(200, $r['status']);
$r = act($srv, $tok, 'update_user', ['user_id' => $saId, 'role' => 'user']);
assert_eq(400, $r['status'], 'cannot edit itself here');
$r = act($srv, $tok, 'update_user', ['user_id' => Auth::getUserByUsername('admin')['id'], 'store_ids' => ['store_a']]);
assert_eq(400, $r['status'], 'cannot edit an admin');
$r = act($srv, $tok, 'update_user', ['user_id' => $userBId, 'store_ids' => ['store_a']]);
assert_eq(404, $r['status'], 'an invisible account is not found');

// delete / reset
$r = act($srv, $tok, 'reset_password', ['user_id' => $userABId, 'new_password' => 'new-pw-12345']);
assert_eq(403, $r['status'], 'no reset on a partially held account');
$r = act($srv, $tok, 'reset_password', ['user_id' => Auth::getUserByUsername('admin')['id'], 'new_password' => 'new-pw-12345']);
assert_eq(403, $r['status'], 'no reset on an admin');
$r = act($srv, $tok, 'reset_password', ['user_id' => $madeUserId, 'new_password' => 'new-pw-12345']);
assert_eq(200, $r['status'], 'reset on a managed account');
$r = act($srv, $tok, 'delete_user', ['user_id' => $userABId]);
assert_eq(400, $r['status'], 'no delete on a partially held account');
$r = act($srv, $tok, 'delete_user', ['user_id' => $saId]);
assert_eq(400, $r['status'], 'no self delete');
$r = act($srv, $tok, 'delete_user', ['user_id' => $userBId]);
assert_eq(400, $r['status'], 'invisible account: refused');
assert_not_null(Auth::getUserById($userBId));
$r = act($srv, $tok, 'delete_user', ['user_id' => $madeSaId]);
assert_eq(200, $r['status'], 'managed store admin deleted');
assert_null(Auth::getUserById($madeSaId));

// =====================================================================================
// User (store A)
// =====================================================================================
$tok = login($srv, 'user_alpha', 'user-alpha-pw-1234');
$r = $srv->get('/admin.php?api=dashboard&store_id=store_a');
assert_eq(200, $r['status'], 'user views its store');
assert_eq(['store_a'], array_column(j($r)['stores'], 'id'));
$r = $srv->get('/admin.php?api=invoice_detail&id=inv_a1');
assert_eq(200, $r['status']);
assert_true(j($r)['isAdmin'] === false, 'user gets the limited view');
$r = $srv->get('/admin.php?api=tip_jars&store_id=store_a');
assert_true(j($r)['isAdmin'] === false, 'user cannot manage jars');
foreach (['api_keys', 'export_info', 'proofs', 'products_manage', 'stats_summary', 'customers', 'rewards_accounts'] as $api) {
    $r = $srv->get("/admin.php?api={$api}&store_id=store_a");
    assert_eq(403, $r['status'], "$api refused for a user: " . $r['body']);
}
foreach (['update_store' => ['store_id' => 'store_a', 'name' => 'x'], 'create_tip_jar' => ['store_id' => 'store_a', 'name' => 'x'],
          'generate_seed' => [], 'test_mint' => ['mint_url' => 'http://127.0.0.1:1'], 'list_users' => [],
          'create_user' => ['username' => 'x', 'password' => 'abcdefgh1', 'role' => 'user', 'store_ids' => ['store_a']],
          'save_store_trusted_mints' => ['store_id' => 'store_a', 'enabled' => '0'],
          'manual_melt' => ['store_id' => 'store_a', 'address' => 'x@y', 'amount' => '1'],
          'reveal_store_seed' => ['store_id' => 'store_a', 'password' => 'user-alpha-pw-1234']] as $action => $fields) {
    $r = act($srv, $tok, $action, $fields);
    assert_eq(403, $r['status'], "$action refused for a user: " . $r['body']);
}
$r = act($srv, $tok, 'cart_checkout', ['store_id' => 'store_b', 'items' => json_encode([['title' => 'x', 'price' => '1', 'quantity' => 1]])]);
assert_eq(403, $r['status'], 'cart checkout on a foreign store refused');
$r = act($srv, $tok, 'set_recovery_email', ['email' => 'alpha-user@example.com']);
assert_eq(200, $r['status'], 'a user sets its own recovery e-mail: ' . $r['body']);
assert_eq('alpha-user@example.com', Auth::getUserById($userAId)['email']);
$r = act($srv, $tok, 'change_own_password', ['current_password' => 'user-alpha-pw-1234', 'new_password' => 'user-alpha-pw-5678']);
assert_eq(200, $r['status'], 'own password change still works');

// Reset by e-mail for the user-role account: a token is minted for it.
$srv->newSession();
$r = $srv->post('/admin.php', ['action' => 'request_password_reset', 'email' => 'alpha-user@example.com']);
assert_eq(200, $r['status']);
assert_true(str_contains($r['body'], 'If an account has that email'), 'generic wording, no "admin": ' . $r['body']);
$tokRow = Database::fetchOne("SELECT user_id FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL", [$userAId]);
assert_not_null($tokRow, 'reset token minted for the user-role account');

// =====================================================================================
// Admin: full powers + role changes
// =====================================================================================
$tok = login($srv, 'admin', 'admin-pw-1234');
$r = $srv->get('/admin.php?api=dashboard');
$ids = array_column(j($r)['stores'], 'id'); sort($ids);
assert_eq(['store_a', 'store_b'], $ids, 'admin sees every store');
$r = act($srv, $tok, 'list_users');
$lu = j($r);
assert_eq(Auth::ROLES, $lu['roles']);
$byName = array_column($lu['users'], null, 'username');
assert_true(isset($byName['user_beta']) && $byName['user_beta']['canManage'] === true, 'admin manages everyone');
assert_eq(2, count($byName['user_both']['storeIds']), 'admin sees every grant');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'admin']);
assert_eq(200, $r['status'], 'admin promotes: ' . $r['body']);
assert_eq([], Auth::getUserStoreIds($userAId), 'promotion drops grants');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'user']);
assert_eq(400, $r['status'], 'demotion without a store list is refused');
assert_eq('admin', Auth::getUserById($userAId)['role'], 'unchanged');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'role' => 'store_admin', 'store_ids' => ['store_b']]);
assert_eq(200, $r['status'], 'demotion with stores: ' . $r['body']);
assert_eq('store_admin', Auth::getUserById($userAId)['role']);
assert_eq(['store_b'], Auth::getUserStoreIds($userAId));
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'store_ids' => ['store_a', 'store_b', 'store_zzz']]);
assert_eq(400, $r['status'], 'unknown store refused');
$r = act($srv, $tok, 'update_user', ['user_id' => $userAId, 'store_ids' => ['']]);
assert_eq(400, $r['status'], 'an empty store set is refused');
$r = act($srv, $tok, 'create_user', ['username' => 'second_admin', 'password' => 'second-admin-pw-1', 'role' => 'admin']);
assert_eq(200, $r['status'], 'admin creates an admin without stores: ' . $r['body']);
$adminRow = Auth::getUserByUsername('admin');
$r = act($srv, $tok, 'update_user', ['user_id' => j($r)['id'], 'role' => 'user', 'store_ids' => ['store_a']]);
assert_eq(200, $r['status'], 'the second admin can be demoted');
$r = act($srv, $tok, 'update_user', ['user_id' => $adminRow['id'], 'role' => 'user', 'store_ids' => ['store_a']]);
assert_eq(400, $r['status'], 'cannot edit own account here');

// =====================================================================================
// Pairing page
// =====================================================================================
$tok = login($srv, 'sa_alpha', 'sa-alpha-pw-1234');
$r = $srv->get('/api-keys/authorize.php?applicationName=TestApp&permissions=btcpay.store.cancreateinvoice');
assert_eq(200, $r['status']);
assert_true(str_contains($r['body'], 'value="store_a"') && !str_contains($r['body'], 'value="store_b"'), 'chooser lists held stores only');
$csrf = HttpDriver::wizardCsrf($r['body']);
$r = $srv->post('/api-keys/authorize.php?applicationName=TestApp&permissions=btcpay.store.cancreateinvoice',
    ['action' => 'approve', 'csrf_token' => $csrf, 'store_id' => 'store_b', 'approved_permissions' => ['btcpay.store.cancreateinvoice']]);
assert_true(str_contains($r['body'], 'do not have access'), 'approve on a foreign store refused');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM api_keys WHERE store_id = 'store_b' AND label = 'TestApp'")['c']);
$r = $srv->post('/api-keys/authorize.php?applicationName=TestApp&permissions=btcpay.store.cancreateinvoice',
    ['action' => 'approve', 'csrf_token' => $csrf, 'store_id' => 'store_a', 'approved_permissions' => ['btcpay.store.cancreateinvoice']]);
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM api_keys WHERE store_id = 'store_a' AND label = 'TestApp'")['c'], 'approve on the held store mints a key');
$tok = login($srv, 'user_beta', 'user-beta-pw-1234');
$r = $srv->get('/api-keys/authorize.php?applicationName=TestApp&permissions=btcpay.store.cancreateinvoice');
assert_true(preg_match('/cannot authorize\s+applications/', $r['body']) === 1, 'a user is told it cannot pair');
$csrf = HttpDriver::wizardCsrf($r['body']);
$before = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM api_keys")['c'];
$srv->post('/api-keys/authorize.php?applicationName=TestApp&permissions=btcpay.store.cancreateinvoice',
    ['action' => 'approve', 'csrf_token' => $csrf, 'store_id' => 'store_b', 'approved_permissions' => ['btcpay.store.cancreateinvoice']]);
assert_eq($before, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM api_keys")['c'], 'a user approves nothing');

$srv->stop();
echo "test_store_admin_gates: ok\n";
