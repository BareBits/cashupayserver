<?php
/**
 * admin.php — the cash back rewards admin half (includes/rewards.php):
 *
 *   1. GET ?api=rewards_accounts: one store or '__all__', username substring
 *      search, pagination, per-store settings summary; 401 anonymous, 403 for
 *      a ROLE_USER account, 404 for an unknown store.
 *   2. GET ?api=export_rewards_csv: the nine columns, the same filters, and
 *      Security::csvCell neutralising a formula-shaped username (the
 *      validator only admits letters + digits, so the hostile row is seeded
 *      straight into the table to exercise the escaping path).
 *   3. POST action=save_rewards_settings: enable (percent parsed, currency
 *      pinned), every parsePercent refusal as a 400, disable keeps the
 *      percentage.
 *   4. POST action=update_store default_currency with rewards balances:
 *      409 + needsRewardsCurrencyDecision without a decision (nothing saved),
 *      'convert' with a stub price provider converts every balance and the
 *      reservations, a rate outage answers 400 and saves nothing, 'keep'
 *      saves the default currency and leaves the balances alone.
 *   5. GET ?api=invoice_detail carries RewardsFlow::invoiceView as `rewards`.
 *
 * 1, 2, 3, 5 run over real HTTP (php -S + cookie jar) with the USD rate
 * pre-seeded in the rate cache so the served process never hits the network;
 * 4 runs admin.php in a subprocess that fakes the admin session and installs
 * ExchangeRates::$testProviders, since a stub provider cannot reach a
 * separate server process.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/http_driver.php';
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/auth.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards.php';
require_once dirname(__DIR__, 2) . '/app/includes/security.php';

Auth::setAdminPassword('admin-pw-1234');
Auth::createUser('staff', 'staffpw1234', Auth::ROLE_USER);
Config::set('allow_private_endpoints', false);
Config::set('last_external_cron_at', time());

// Deterministic USD rate for the served process (5-minute cache, read from
// the config table). $100,000 / BTC: 1 USD = 1,000 sats.
Config::set('rate_usd', ['rate' => 100000.0, 'timestamp' => time(), 'provider' => 'stub']);

// ---------- stores + accounts ----------
make_store('store_rw_a', 'https://mint.example');
Database::update('stores', ['name' => 'Alpha', 'default_currency' => 'USD'], 'id = ?', ['store_rw_a']);
make_store('store_rw_b', 'https://mint.example');
Database::update('stores', ['name' => 'Beta', 'default_currency' => 'sat'], 'id = ?', ['store_rw_b']);
make_store('store_rw_c', 'https://mint.example');
Database::update('stores', ['name' => 'Gamma', 'default_currency' => 'sat'], 'id = ?', ['store_rw_c']);

Rewards::setStoreSettings('store_rw_a', true, 2.5);   // rewards currency pinned to USD
assert_eq('USD', Rewards::forStore('store_rw_a')['currency'], 'store A rewards currency pinned to USD');

$alice = Rewards::getOrCreateAccount('store_rw_a', 'Alice');
assert_true(Rewards::credit($alice['id'], 'inv_seed_alice', 1250000, 'USD'), 'Alice credited 1.25 USD');
$bob = Rewards::getOrCreateAccount('store_rw_a', 'Bob');
assert_true(Rewards::credit($bob['id'], 'inv_seed_bob', 500000, 'USD'), 'Bob credited 0.50 USD');
// Older activity for Bob so the ordering (newest activity first) is observable.
Database::update('rewards_accounts', ['last_activity_at' => time() - 3600], 'id = ?', [$bob['id']]);

// Store B (sat): one real account plus a formula-shaped username seeded
// straight into the table (normalizeUsername would refuse it).
$carol = Rewards::getOrCreateAccount('store_rw_b', 'Carol');
assert_true(Rewards::credit($carol['id'], 'inv_seed_carol', 1500 * Rewards::MICRO, 'sat'), 'Carol credited 1500 sats');
$hostileName = '=HYPERLINK("http://evil.test")';
Database::insert('rewards_accounts', [
    'id' => 'rwa_hostile', 'store_id' => 'store_rw_b',
    'username' => $hostileName, 'username_key' => strtolower($hostileName),
    'balance_micro' => 7 * Rewards::MICRO, 'reserved_micro' => 0, 'earned_micro' => 7 * Rewards::MICRO, 'spent_micro' => 0,
    'created_at' => time() - 7200, 'updated_at' => time() - 7200, 'last_activity_at' => time() - 7200,
]);

// Invoices for the detail endpoint (store A, USD).
$now = time();
$invBase = [
    'store_id' => 'store_rw_a', 'currency' => 'USD', 'payment_rail' => 'mint',
    'created_at' => $now - 600, 'expiration_time' => $now + 3000, 'exchange_rate' => 100000.0,
];
// Partially covered, settled, earn credited to Alice.
Database::insert('invoices', $invBase + [
    'id' => 'inv_rw_partial', 'status' => 'Settled', 'amount' => '10.00', 'amount_sats' => 8750,
    'settled_rail' => 'mint', 'paid_at' => $now - 60,
    'rewards_account_id' => $alice['id'], 'rewards_discount_amount' => '1.25', 'rewards_applied_micro' => 1250000,
    'rewards_spend_state' => 'committed', 'rewards_earned_micro' => 218750, 'rewards_earn_currency' => 'USD',
    'rewards_claimed_at' => $now - 60,
]);
// Nothing applied, earn unclaimed and still claimable.
Database::insert('invoices', $invBase + [
    'id' => 'inv_rw_unclaimed', 'status' => 'Settled', 'amount' => '4.00', 'amount_sats' => 4000,
    'settled_rail' => 'mint', 'paid_at' => $now - 60,
    'rewards_earned_micro' => 100000, 'rewards_earn_currency' => 'USD',
]);
// Fully covered by rewards: settled on the rewards rail with nothing owed.
Database::insert('invoices', $invBase + [
    'id' => 'inv_rw_full', 'status' => 'Settled', 'amount' => '0.50', 'amount_sats' => 0,
    'settled_rail' => 'rewards', 'paid_at' => $now - 30,
    'rewards_account_id' => $bob['id'], 'rewards_discount_amount' => '0.50', 'rewards_applied_micro' => 500000,
    'rewards_spend_state' => 'committed', 'rewards_earned_micro' => 0, 'rewards_earn_currency' => 'USD',
]);

$srv = HttpDriver::start($dataDir);

function login(HttpDriver $srv, string $user, string $pw): string {
    $r = $srv->post('/admin.php', ['action' => 'login', 'username' => $user, 'password' => $pw]);
    assert_eq(200, $r['status'], "$user login: " . $r['body']);
    $tok = json_decode($r['body'], true)['csrfToken'] ?? '';
    assert_true($tok !== '', 'csrf token issued');
    return $tok;
}
function get_json(HttpDriver $srv, string $path, int $expect = 200): array {
    $r = $srv->get($path);
    assert_eq($expect, $r['status'], "GET $path -> " . substr($r['body'], 0, 300));
    $j = json_decode($r['body'], true);
    assert_true(is_array($j), "GET $path returned JSON");
    return $j;
}
function usernames(array $j): array {
    return array_map(fn($a) => $a['username'], $j['accounts']);
}

// ---------- anonymous + ROLE_USER gates ----------
$r = $srv->get('/admin.php?api=rewards_accounts&store_id=__all__');
assert_eq(401, $r['status'], 'anonymous rewards_accounts is 401');
$r = $srv->get('/admin.php?api=export_rewards_csv&store_id=__all__');
assert_eq(401, $r['status'], 'anonymous export is 401');

$tok = login($srv, 'staff', 'staffpw1234');
$r = $srv->get('/admin.php?api=rewards_accounts&store_id=__all__');
assert_eq(403, $r['status'], 'ROLE_USER rewards_accounts is 403: ' . $r['body']);
$r = $srv->get('/admin.php?api=export_rewards_csv&store_id=__all__');
assert_eq(403, $r['status'], 'ROLE_USER export_rewards_csv is 403');
assert_false(str_contains($r['body'], 'Alice'), 'no account data leaks to ROLE_USER');
$r = $srv->post('/admin.php', ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '1'], ['X-CSRF-Token' => $tok]);
assert_eq(403, $r['status'], 'ROLE_USER save_rewards_settings is 403');
assert_false(Rewards::forStore('store_rw_b')['enabled'], 'nothing saved for ROLE_USER');

// ---------- admin: rewards_accounts ----------
$srv->newSession();
$tok = login($srv, 'admin', 'admin-pw-1234');

$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=store_rw_a');
assert_eq(2, $j['total'], 'store A has two accounts');
assert_eq(['Alice', 'Bob'], usernames($j), 'newest activity first');
$a = $j['accounts'][0];
assert_eq('store_rw_a', $a['storeId'], 'storeId');
assert_eq('Alpha', $a['storeName'], 'storeName joined');
assert_eq('USD', $a['currency'], 'rewards currency on the row');
assert_eq(1250000, $a['balanceMicro'], 'balanceMicro');
assert_eq('1.25', $a['balance'], 'balance in whole units');
assert_eq('1.25 USD', $a['balanceDisplay'], 'balanceDisplay');
assert_eq(1250, $a['balanceSats'], 'sats equivalent from the cached USD rate');
assert_null($a['balanceFiat'], 'fiat store: no fiat secondary');
assert_eq('1.25', $a['earned'], 'earned');
assert_eq('0.00', $a['spent'], 'spent');
assert_eq('0.00', $a['reserved'], 'reserved');
assert_true(is_int($a['lastActivityAt']) && $a['lastActivityAt'] > 0, 'lastActivityAt');
assert_true(is_int($a['createdAt']) && $a['createdAt'] > 0, 'createdAt');

$byId = [];
foreach ($j['stores'] as $s) { $byId[$s['id']] = $s; }
assert_eq(3, count($byId), 'every store is listed in stores');
assert_eq(['id' => 'store_rw_a', 'name' => 'Alpha', 'enabled' => true, 'percent' => 2.5, 'currency' => 'USD', 'defaultCurrency' => 'USD'], $byId['store_rw_a'], 'store A summary');
assert_eq(false, $byId['store_rw_b']['enabled'], 'store B rewards off');
assert_null($byId['store_rw_b']['currency'], 'store B has no rewards currency yet');
assert_eq('sat', $byId['store_rw_b']['defaultCurrency'], 'store B default currency (what an enable from the view would pin)');

// Search: case-insensitive substring on the username.
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=store_rw_a&q=BO');
assert_eq(1, $j['total'], 'search total');
assert_eq(['Bob'], usernames($j), 'search matches Bob');
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=store_rw_a&q=zzz');
assert_eq(0, $j['total'], 'no match total');
assert_eq([], $j['accounts'], 'no match accounts');
// LIKE wildcards in the search text are literal.
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=__all__&q=%25');
assert_eq(0, $j['total'], 'a literal % matches nothing');

// Sat store: sats shown, fiat secondary null when the store itself is sat-priced.
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=store_rw_b&q=carol');
assert_eq('sat', $j['accounts'][0]['currency'], 'sat currency');
assert_eq('1,500 sats', $j['accounts'][0]['balanceDisplay'], 'sats display');
assert_eq(1500, $j['accounts'][0]['balanceSats'], 'balanceSats on a sat store');
assert_null($j['accounts'][0]['balanceFiat'], 'no fiat equivalent on a sat-priced store');

// All stores + pagination.
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=__all__');
assert_eq(4, $j['total'], 'all stores: four accounts');
assert_eq(4, count($j['accounts']), 'all accounts on one page');
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=__all__&limit=2&offset=0');
assert_eq(2, count($j['accounts']), 'page 1 of 2');
assert_eq(4, $j['total'], 'total is independent of the page');
assert_eq(2, $j['limit'], 'limit echoed');
$page1 = usernames($j);
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=__all__&limit=2&offset=2');
assert_eq(2, count($j['accounts']), 'page 2 of 2');
assert_eq(2, $j['offset'], 'offset echoed');
assert_eq([], array_intersect($page1, usernames($j)), 'pages do not overlap');
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=__all__&limit=2&offset=4');
assert_eq([], $j['accounts'], 'past the end: empty page');
// An empty store_id means all stores too; an unknown one is a 404.
$j = get_json($srv, '/admin.php?api=rewards_accounts&store_id=');
assert_eq(4, $j['total'], 'empty store_id = all stores');
get_json($srv, '/admin.php?api=rewards_accounts&store_id=store_nope', 404);

// ---------- admin: CSV export ----------
function csv_rows(string $body): array {
    $lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $body)), fn($l) => trim($l) !== ''));
    return array_map(fn($l) => str_getcsv($l, ',', '"', ''), $lines);
}
$r = $srv->get('/admin.php?api=export_rewards_csv&store_id=__all__');
assert_eq(200, $r['status'], 'export ok');
assert_true(str_starts_with($r['headers']['content-type'] ?? '', 'text/csv'), 'text/csv content type: ' . ($r['headers']['content-type'] ?? ''));
assert_true(str_contains($r['headers']['content-disposition'] ?? '', 'rewards-'), 'attachment filename');
$rows = csv_rows($r['body']);
assert_eq(['Store', 'Username', 'Currency', 'Balance', 'Reserved', 'Earned', 'Spent', 'Created at (UTC)', 'Last activity (UTC)'], $rows[0], 'CSV header');
assert_eq(5, count($rows), 'header + four accounts');
$byUser = [];
foreach (array_slice($rows, 1) as $row) {
    assert_eq(9, count($row), 'nine cells per row');
    $byUser[$row[1]] = $row;
}
assert_eq(['Alpha', 'Alice', 'USD', '1.250000', '0.000000', '1.250000', '0.000000'], array_slice($byUser['Alice'], 0, 7), 'Alice row');
assert_true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $byUser['Alice'][7]) === 1, 'created_at formatted');
assert_true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $byUser['Alice'][8]) === 1, 'last_activity formatted');
assert_eq(['Beta', 'Carol', 'sat', '1500.000000'], array_slice($byUser['Carol'], 0, 4), 'Carol row in sats');
// The formula-shaped username is neutralised: the cell starts with an apostrophe.
assert_true(isset($byUser["'" . $hostileName]), 'hostile username neutralised by csvCell: ' . json_encode(array_keys($byUser)));
assert_false(isset($byUser[$hostileName]), 'raw formula never appears as a cell');
assert_eq(Security::csvCell($hostileName), "'" . $hostileName, 'csvCell contract');

$r = $srv->get('/admin.php?api=export_rewards_csv&store_id=store_rw_a');
$rows = csv_rows($r['body']);
assert_eq(3, count($rows), 'store filter: header + two rows');
$r = $srv->get('/admin.php?api=export_rewards_csv&store_id=__all__&q=ali');
$rows = csv_rows($r['body']);
assert_eq(2, count($rows), 'search filter: header + Alice');
assert_eq('Alice', $rows[1][1], 'search filter row');

// ---------- admin: save_rewards_settings ----------
function post_json(HttpDriver $srv, string $tok, array $post, int $expect): array {
    $r = $srv->post('/admin.php', $post, ['X-CSRF-Token' => $tok]);
    assert_eq($expect, $r['status'], json_encode($post) . ' -> ' . substr($r['body'], 0, 300));
    return json_decode($r['body'], true) ?? [];
}
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '1.5'], 200);
assert_eq(true, $j['success'], 'enable succeeds');
assert_eq(['enabled' => true, 'percent' => 1.5, 'currency' => 'sat', 'live' => true], $j['settings'], 'settings echoed, currency pinned to sat');
$rowB = Config::getStore('store_rw_b');
assert_eq(1, (int)$rowB['rewards_enabled'], 'persisted enabled');
assert_eq(1.5, (float)$rowB['rewards_percent'], 'persisted percent');
assert_eq('sat', $rowB['rewards_currency'], 'persisted currency');

$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '0'], 400);
assert_true(str_contains($j['error'], 'between'), 'zero percent refused: ' . $j['error']);
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => 'abc'], 400);
assert_true(str_contains($j['error'], 'number'), 'non-numeric refused: ' . $j['error']);
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '1.23456'], 400);
assert_true(str_contains($j['error'], 'decimal'), 'five decimals refused: ' . $j['error']);
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '50.5'], 400);
assert_true(str_contains($j['error'], 'between'), 'above the cap refused: ' . $j['error']);
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => ''], 400);
assert_true(str_contains($j['error'], 'number'), 'blank percent refused when enabling: ' . $j['error']);
assert_eq(1.5, (float)Config::getStore('store_rw_b')['rewards_percent'], 'refusals leave the stored percent alone');
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_nope', 'enabled' => '1', 'percent' => '1'], 400);
assert_true(str_contains($j['error'], 'Store not found'), 'unknown store');
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'enabled' => '1', 'percent' => '1'], 400);
assert_true(str_contains($j['error'], 'Store ID'), 'missing store id');

// Disable: the percent is remembered (and an invalid typed value is ignored).
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '0', 'percent' => 'garbage'], 200);
assert_eq(['enabled' => false, 'percent' => 1.5, 'currency' => 'sat', 'live' => false], $j['settings'], 'disabled, percent remembered');
assert_eq(0, (int)Config::getStore('store_rw_b')['rewards_enabled'], 'persisted disabled');
// Disable with a valid new percent stores it for the next enable.
$j = post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '0', 'percent' => '3'], 200);
assert_eq(3.0, (float)$j['settings']['percent'], 'valid percent saved along with a disable');
// Re-enable without a percent still needs one (nothing is implied).
post_json($srv, $tok, ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => ''], 400);
// CSRF is enforced like every other admin POST.
$r = $srv->post('/admin.php', ['action' => 'save_rewards_settings', 'store_id' => 'store_rw_b', 'enabled' => '1', 'percent' => '1']);
assert_eq(403, $r['status'], 'missing CSRF token is refused');

// ---------- admin: invoice_detail carries `rewards` ----------
$j = get_json($srv, '/admin.php?api=invoice_detail&id=inv_rw_partial');
assert_true(isset($j['rewards']) && is_array($j['rewards']), 'invoice_detail has a rewards block');
$rw = $j['rewards'];
assert_eq(true, $rw['enabled'], 'store rewards enabled');
assert_eq('USD', $rw['rewardsCurrency'], 'rewards currency');
assert_eq('Alice', $rw['applied']['username'], 'applied username');
assert_eq('1.25', $rw['applied']['discountAmount'], 'applied discount');
assert_eq('USD', $rw['applied']['currency'], 'applied currency');
assert_eq('committed', $rw['applied']['state'], 'applied state');
assert_eq(false, $rw['applied']['fullCoverage'], 'partial coverage');
assert_eq('8.75', $rw['effectiveAmount'], 'effective amount');
assert_eq('10.00', $rw['originalAmount'], 'original amount');
assert_eq('0.21 USD', $rw['earned']['display'], 'earn display (floor to cents)');
assert_eq('Alice', $rw['earned']['claimedTo'], 'earn credited to Alice');
assert_eq(false, $rw['earned']['claimable'], 'credited earn is not claimable');
assert_eq(false, $rw['canApply'], 'settled invoice cannot take rewards');
// The invoice's own API block (Invoice::formatForApi) rides along too.
assert_eq('1.25', $j['invoice']['rewards']['appliedAmount'] ?? null, 'formatForApi rewards block present');

$j = get_json($srv, '/admin.php?api=invoice_detail&id=inv_rw_unclaimed');
assert_null($j['rewards']['applied'], 'nothing applied');
assert_eq(true, $j['rewards']['earned']['claimable'], 'unclaimed earn within the window is claimable');
assert_null($j['rewards']['earned']['claimedTo'], 'no claimant yet');
assert_eq($now - 60 + Rewards::CLAIM_WINDOW_SEC, $j['rewards']['earned']['claimDeadline'], 'claim deadline = paid_at + window');

$j = get_json($srv, '/admin.php?api=invoice_detail&id=inv_rw_full');
assert_eq(true, $j['rewards']['applied']['fullCoverage'], 'full coverage flagged');
assert_eq('Bob', $j['rewards']['applied']['username'], 'full coverage payer');
assert_eq('0.00', $j['rewards']['effectiveAmount'], 'nothing owed');
assert_eq('rewards', $j['detail']['settledRail'], 'settled on the rewards rail');
assert_eq('rewards', $j['invoice']['paymentRail'] ?? null, 'paymentRail reads rewards');

$srv->stop();

// ---------- update_store: default-currency switch with balances ----------
//
// Subprocess runner: a fake admin session + CSRF token, a stub price provider
// fed from T_PRICES, then admin.php. Store A keeps its USD balances from
// above (Alice 1.25, Bob 0.50); Alice also holds an open reservation so the
// conversion of invoices.rewards_applied_micro is observable.
Database::insert('invoices', $invBase + [
    'id' => 'inv_rw_open', 'status' => 'New', 'amount' => '3.00', 'amount_sats' => 2500,
    'rewards_account_id' => $alice['id'], 'rewards_discount_amount' => '0.50', 'rewards_applied_micro' => 500000,
    'rewards_spend_state' => 'reserved',
]);
assert_true(Rewards::reserve($alice['id'], 'inv_rw_open', 500000, 'USD'), 'Alice reserves 0.50 USD');
$aliceBefore = Rewards::getAccountById($alice['id']);
assert_eq(750000, (int)$aliceBefore['balance_micro'], 'Alice spendable 0.75 USD');
assert_eq(500000, (int)$aliceBefore['reserved_micro'], 'Alice reserved 0.50 USD');

$root = dirname(__DIR__, 2) . '/app';
$runner = $dataDir . '/admin_rewards_runner.php';
file_put_contents($runner, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
define('CASHUPAY_DATA_DIR', getenv('T_DATA_DIR'));
$_SERVER['HTTP_HOST'] = 'pay.test';
$_SERVER['SCRIPT_NAME'] = '/admin.php';
$_SERVER['REQUEST_URI'] = '/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %s;
require %s;
require %s;
require %s;
final class StubPriceProvider implements PriceProvider {
    public function __construct(private array $prices) {}
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float { return $this->prices[strtoupper($currency)] ?? null; }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider(json_decode((string)getenv('T_PRICES'), true) ?: [])];
Auth::initSession();
$admin = Database::fetchOne("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1");
$_SESSION['user_id'] = $admin['id'];
$_SESSION['user_role'] = 'admin';
$_SESSION['auth_fp'] = hash('sha256', 'cashupay-session:' . (string)$admin['password_hash']);
$_SESSION['login_time'] = time();
$_POST = json_decode((string)getenv('T_POST'), true);
$_POST['csrf_token'] = Auth::generateCsrfToken();
register_shutdown_function(function () {
    $c = http_response_code();
    echo "\nHTTP_STATUS:" . ($c === false ? 200 : $c);
});
require %s;
PHP,
    var_export($root . '/includes/database.php', true),
    var_export($root . '/includes/config.php', true),
    var_export($root . '/includes/auth.php', true),
    var_export($root . '/includes/rates.php', true),
    var_export($root . '/admin.php', true)
));

/** @return array{status:int, json:?array} */
function admin_post(array $post, array $prices): array {
    global $dataDir, $runner;
    $env = 'T_DATA_DIR=' . escapeshellarg($dataDir)
        . ' T_POST=' . escapeshellarg(json_encode($post))
        . ' T_PRICES=' . escapeshellarg(json_encode($prices))
        . ' CASHUPAY_UPDATER_DISABLED=1 CASHUPAY_DEV_FEE_BRANCH_ENDPOINT=disabled CASHUPAY_USAGE_STATS_ENDPOINT=disabled';
    $out = [];
    $rc = 0;
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $out, $rc);
    $raw = implode("\n", $out);
    if (!preg_match('/^(.*)\nHTTP_STATUS:(\d+)$/s', $raw, $m)) {
        fail("admin runner produced unparseable output (rc=$rc): " . substr($raw, 0, 800));
    }
    return ['status' => (int)$m[2], 'json' => json_decode(trim($m[1]), true)];
}
function clear_rate_cache(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'rate_%'");
    Config::clearCache();
}
function store_raw(string $id): array {
    Config::clearCache();
    return Database::fetchOne("SELECT * FROM stores WHERE id = ?", [$id]);
}
$switch = ['action' => 'update_store', 'store_id' => 'store_rw_a', 'default_currency' => 'EUR'];
$prices = ['USD' => 100000.0, 'EUR' => 50000.0]; // 1 USD = 0.5 EUR

// No decision -> 409, nothing saved.
$res = admin_post($switch, $prices);
assert_eq(409, $res['status'], 'switch without a decision is 409: ' . json_encode($res['json']));
assert_eq(true, $res['json']['needsRewardsCurrencyDecision'] ?? null, 'flag set');
assert_eq('USD', $res['json']['from'], 'from currency');
assert_eq('EUR', $res['json']['to'], 'to currency');
assert_eq(2, $res['json']['accounts'], 'account count');
assert_true(str_contains((string)$res['json']['error'], 'kept in USD'), 'operator-facing message: ' . $res['json']['error']);
assert_eq('USD', store_raw('store_rw_a')['default_currency'], 'default currency untouched');
assert_eq('USD', store_raw('store_rw_a')['rewards_currency'], 'rewards currency untouched');
// A bogus decision value counts as no decision.
$res = admin_post($switch + ['rewards_currency_action' => 'maybe'], $prices);
assert_eq(409, $res['status'], 'unknown decision value is still 409');

// Convert during a rate outage -> 400, nothing saved (not even the currency).
clear_rate_cache();
$res = admin_post($switch + ['rewards_currency_action' => 'convert'], ['USD' => 100000.0]);
assert_eq(400, $res['status'], 'convert with no EUR rate is 400: ' . json_encode($res['json']));
assert_true(str_contains((string)$res['json']['error'], 'Exchange rate unavailable'), 'rate outage message: ' . $res['json']['error']);
assert_eq('USD', store_raw('store_rw_a')['default_currency'], 'outage: default currency not saved');
assert_eq('USD', store_raw('store_rw_a')['rewards_currency'], 'outage: rewards currency not saved');
assert_eq(750000, (int)Rewards::getAccountById($alice['id'])['balance_micro'], 'outage: Alice balance untouched');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM rewards_ledger WHERE kind = 'convert'")['n'], 'outage: no convert ledger rows');

// Convert with both rates -> balances halve (USD -> EUR), reservations follow.
clear_rate_cache();
$res = admin_post($switch + ['rewards_currency_action' => 'convert'], $prices);
assert_eq(200, $res['status'], 'convert saves: ' . json_encode($res['json']));
assert_eq(true, $res['json']['success'] ?? null, 'convert success');
assert_eq('EUR', store_raw('store_rw_a')['default_currency'], 'default currency now EUR');
assert_eq('EUR', store_raw('store_rw_a')['rewards_currency'], 'rewards currency now EUR');
$aliceAfter = Rewards::getAccountById($alice['id']);
assert_eq(375000, (int)$aliceAfter['balance_micro'], 'Alice 0.75 USD -> 0.375 EUR');
assert_eq(250000, (int)$aliceAfter['reserved_micro'], 'Alice reserved 0.50 USD -> 0.25 EUR');
assert_eq(625000, (int)$aliceAfter['earned_micro'], 'Alice lifetime earned converted');
assert_eq(250000, (int)Rewards::getAccountById($bob['id'])['balance_micro'], 'Bob 0.50 USD -> 0.25 EUR');
assert_eq(250000, (int)Database::fetchOne("SELECT rewards_applied_micro FROM invoices WHERE id = 'inv_rw_open'")['rewards_applied_micro'], 'open reservation converted');
assert_eq(2, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM rewards_ledger WHERE kind = 'convert' AND currency = 'EUR'")['n'], 'one convert ledger row per account');

// Keep: the default currency changes, the balances and their currency stay.
clear_rate_cache();
$res = admin_post(['action' => 'update_store', 'store_id' => 'store_rw_a', 'default_currency' => 'USD', 'rewards_currency_action' => 'keep'], $prices);
assert_eq(200, $res['status'], 'keep saves: ' . json_encode($res['json']));
assert_eq('USD', store_raw('store_rw_a')['default_currency'], 'default currency back to USD');
assert_eq('EUR', store_raw('store_rw_a')['rewards_currency'], 'rewards currency kept in EUR');
assert_eq(375000, (int)Rewards::getAccountById($alice['id'])['balance_micro'], 'keep: Alice balance unchanged');
assert_eq(2, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM rewards_ledger WHERE kind = 'convert'")['n'], 'keep: no new ledger rows');

// Same currency as the rewards currency: no decision needed.
$res = admin_post(['action' => 'update_store', 'store_id' => 'store_rw_a', 'default_currency' => 'EUR'], $prices);
assert_eq(200, $res['status'], 'switching to the rewards currency itself needs no decision');

// A store with rewards on but no balances switches freely.
Rewards::setStoreSettings('store_rw_c', true, 1.0);
$res = admin_post(['action' => 'update_store', 'store_id' => 'store_rw_c', 'default_currency' => 'EUR'], $prices);
assert_eq(200, $res['status'], 'no balances: no decision needed: ' . json_encode($res['json']));
assert_eq('EUR', store_raw('store_rw_c')['default_currency'], 'store C default currency saved');

// Rewards off entirely (store B, balances exist but no rewards currency pin
// was ever... it was: setStoreSettings pinned sat above) — with balances in
// sat and a switch to USD, the decision is required even while rewards are
// currently disabled: the balances are still real money.
$res = admin_post(['action' => 'update_store', 'store_id' => 'store_rw_b', 'default_currency' => 'USD'], $prices);
assert_eq(409, $res['status'], 'disabled store with sat balances still needs the decision');

echo "ok\n";
