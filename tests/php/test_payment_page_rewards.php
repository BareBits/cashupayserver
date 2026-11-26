<?php
/**
 * Cash back rewards on the customer payment page (payment.php), over a real
 * HTTP round trip (php -S + router.php) against a mock mint, with the
 * exchange rate seeded into the cache the server reads:
 *
 *   - Render: a New invoice of a rewards store offers "Apply rewards" (with
 *     the inline username form + hint); a store with rewards off and a tip
 *     jar invoice do not; the amount line of an invoice without rewards is
 *     unchanged.
 *   - rewards_apply (funded account): JSON contract; the reloaded page shows
 *     the original struck through, the effective amount with the re-sourced
 *     sats, the "Rewards applied" line and Remove; unknown user → 400 with
 *     the exact RewardsFlow text; rewards_remove → back to the original.
 *   - Full coverage: the apply settles the invoice; the page renders the
 *     success screen with 0.00 owed and no claim prompt (nothing earned).
 *   - Claim: a settled invoice with an unclaimed earn shows the prompt with
 *     the amount; the JSON poll carries it; rewards_claim credits and the
 *     re-render shows the credited line; another username is refused;
 *     outside the 24 h window nothing is shown and the claim is refused.
 *   - Sat store: whole-sat discount line.
 *   - Safety: a username like <img …> is refused by validation and never
 *     reflected unescaped; unknown invoice → 404; every rewards POST counts
 *     against the payment_post bucket (the GET bucket is untouched) and the
 *     limit answers 429 JSON.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
require __DIR__ . '/http_driver.php';
define('CASHUPAY_IN_CRON', true);
$dataDir = fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards_flow.php';
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

// $100,000 / BTC in THIS process (Invoice::create); the server process reads
// the same figure from the seeded rate cache (seed_rate below).
final class StubPriceProvider implements PriceProvider {
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float {
        return strtoupper($currency) === 'USD' ? 100000.0 : null;
    }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider()];
function seed_rate(): void {
    $payload = ['rate' => 100000.0, 'timestamp' => time(), 'provider' => 'stub'];
    Config::set('rate_usd', $payload);
    Config::set('rate_lastgood_usd', $payload);
}
seed_rate();
function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }

$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 3600]);
Config::set('allow_private_endpoints', true);
Config::set('fee_tracking_start_at', time() + 86400 * 365);

make_store('s_usd', $mint['url']);
Database::update('stores', ['default_currency' => 'USD', 'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_usd']);
Rewards::setStoreSettings('s_usd', true, 2.0);
make_store('s_sat', $mint['url']);
Rewards::setStoreSettings('s_sat', true, 5.0);
make_store('s_off', $mint['url']);
Database::update('stores', ['default_currency' => 'USD', 'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_off']);

$alice = Rewards::getOrCreateAccount('s_usd', 'Alice');
Rewards::credit($alice['id'], 'seed_alice', 1500000, 'USD');     // $1.50
$bob = Rewards::getOrCreateAccount('s_usd', 'Bob');
Rewards::credit($bob['id'], 'seed_bob', 20000000, 'USD');        // $20.00
$frank = Rewards::getOrCreateAccount('s_sat', 'Frank');
Rewards::credit($frank['id'], 'seed_frank', 500 * Rewards::MICRO, 'sat');

$srv = HttpDriver::start($dataDir, ['CASHUPAY_PAYMENT_POST_RATE_LIMIT' => '40']);
$posts = 0;
$gets = 0;
function page(string $id): string {
    global $srv, $gets;
    $gets++;
    $r = $srv->get('/payment.php?id=' . rawurlencode($id));
    assert_eq(200, $r['status'], "GET payment page $id");
    return $r['body'];
}
function poll(string $id): array {
    global $srv, $gets;
    $gets++;
    $r = $srv->get('/payment.php?id=' . rawurlencode($id) . '&json=1');
    assert_eq(200, $r['status'], "poll $id");
    return json_decode($r['body'], true);
}
/** @return array{status:int, json:array, body:string} */
function post(string $id, string $action, array $fields = []): array {
    global $srv, $posts;
    $posts++;
    $r = $srv->post('/payment.php?id=' . rawurlencode($id), ['action' => $action] + $fields);
    return ['status' => $r['status'], 'json' => json_decode($r['body'], true) ?? [], 'body' => $r['body']];
}
/** Inner HTML of the first `<div class="amount">` inside the given screen. */
function amount_line(string $html, string $screenId): string {
    $start = strpos($html, 'id="' . $screenId . '"');
    assert_true($start !== false, "screen $screenId present");
    assert_true(preg_match('/<div class="amount">(.*?)<\/div>/s', $html, $m, 0, $start) === 1, "amount line in $screenId");
    return $m[1];
}
function has_hidden(string $html, string $id): ?bool {
    if (!preg_match('/class="([^"]*)" id="' . preg_quote($id, '/') . '"/', $html, $m)) {
        return null;
    }
    return str_contains(' ' . $m[1] . ' ', ' hidden ');
}
function rate_count(string $bucket): int {
    global $dataDir;
    $file = $dataDir . '/cache/' . md5('rate_' . $bucket . '_127.0.0.1') . '.cache';
    if (!is_file($file)) return 0;
    $data = json_decode((string)file_get_contents($file), true);
    return (int)($data['data']['count'] ?? 0);
}

$HINT = 'Enter the username you chose when you earned rewards. Many people use their phone number.';
$CLAIM_COPY = 'Pick a username to claim them. Pick a difficult to predict username, there is no password, many people use their phone number. Unclaimed rewards disappear.';

// ---------------------------------------------------------------- render
$inv = Invoice::create('s_usd', ['amount' => '10.00', 'currency' => 'USD']);
$A = $inv['id'];
$html = page($A);
assert_eq('10.00 USD (10,000 sats)', amount_line($html, 'payment-pending'), 'plain amount line without rewards');
assert_true(str_contains($html, 'id="rewards-apply-btn">Apply rewards</button>'), 'Apply rewards button');
assert_true(str_contains($html, 'class="rewards-form hidden" id="rewards-apply-form"'), 'inline form starts hidden');
assert_true(str_contains($html, 'id="rewards-apply-username" name="username"'), 'username input');
assert_true(str_contains($html, 'autocomplete="off" inputmode="text"'), 'input attributes');
assert_true(str_contains($html, 'maxlength="64" pattern="[A-Za-z0-9]+" required'), 'client-side pattern mirrors Rewards::USERNAME_PATTERN');
assert_true(str_contains($html, $HINT), 'username hint');
assert_true(str_contains($html, 'id="rewards-apply-submit">Apply</button>'), 'Apply submit');
assert_true(str_contains($html, 'id="rewards-apply-cancel">Cancel</button>'), 'Cancel');
assert_false(str_contains($html, 'id="rewards-applied-block"'), 'nothing applied yet');
assert_false(str_contains($html, 'id="rewards-remove-btn"'), 'no Remove yet');
assert_eq(true, has_hidden($html, 'rewards-claim'), 'claim block rendered hidden on a New invoice (filled by the poll later)');
assert_eq(true, has_hidden($html, 'rewards-credited'), 'credited line hidden');
assert_true(str_contains($html, $CLAIM_COPY), 'claim copy present in the DOM');
assert_true(str_contains($html, 'let rewardsState = {"earned":null,"applied":null}'), 'JS bootstrap state');
$p = poll($A);
assert_eq(['earned' => null, 'applied' => null], $p['rewards'], 'poll of a New invoice carries an empty rewards object');

$off = Invoice::create('s_off', ['amount' => '10.00', 'currency' => 'USD']);
$html = page($off['id']);
assert_false(str_contains($html, 'id="rewards-apply-btn"'), 'store with rewards off: no Apply button');
assert_false(str_contains($html, 'id="rewards-claim"'), 'store with rewards off: no claim block');
assert_false(str_contains($html, 'class="rewards-'), 'store with rewards off: no rewards markup at all (the static JS still names the ids)');
assert_eq('10.00 USD (10,000 sats)', amount_line($html, 'payment-pending'));

$jar = Invoice::create('s_usd', ['amount' => '5.00', 'currency' => 'USD']);
Database::update('invoices', ['tip_jar_id' => 'jar_x'], 'id = ?', [$jar['id']]);
$html = page($jar['id']);
assert_false(str_contains($html, 'id="rewards-apply-btn"'), 'tip jar invoice: no Apply button');
assert_false(str_contains($html, 'id="rewards-applied-block"'), 'tip jar invoice: nothing applied');

// ---------------------------------------------------------------- apply
seed_rate();
$r = post($A, 'rewards_apply', ['username' => '  alice ']);
assert_eq(200, $r['status'], 'apply ok: ' . $r['body']);
assert_eq([
    'ok' => true, 'status' => 'New', 'fullCoverage' => false,
    'applied' => ['username' => 'Alice', 'discountAmount' => '1.50', 'currency' => 'USD', 'display' => '1.50 USD'],
], $r['json'], 'rewards_apply JSON contract');
assert_eq(8500, (int)row($A)['amount_sats'], 'Lightning re-sourced at the discounted sats');
$html = page($A);
assert_eq('<s class="amount-original">10.00 USD</s><span class="amount-effective">8.50 USD (8,500 sats)</span>',
    amount_line($html, 'payment-pending'), 'pending amount: original struck through, effective amount');
assert_eq('<s class="amount-original">10.00 USD</s><span class="amount-effective">8.50 USD (8,500 sats)</span>',
    amount_line($html, 'payment-success'), 'success amount line matches');
assert_true(str_contains($html, 'id="rewards-applied-text">Rewards applied: −1.50 USD (Alice)</span>'), 'Rewards applied line');
assert_true(str_contains($html, 'id="rewards-remove-btn">Remove</button>'), 'Remove button on the pending screen');
assert_false(str_contains($html, 'id="rewards-apply-btn"'), 'no second Apply while applied');
// The success screen's copy of the line has no Remove.
$successStart = strpos($html, 'id="payment-success"');
assert_true(str_contains(substr($html, $successStart), 'Rewards applied: −1.50 USD (Alice)'), 'success screen also names the discount');
assert_false(str_contains(substr($html, $successStart, strpos($html, '<script') - $successStart), 'rewards-remove-btn'), 'but offers no Remove');

// A second apply is refused (already applied).
seed_rate();
$r = post($A, 'rewards_apply', ['username' => 'Bob']);
assert_eq(400, $r['status']);
assert_eq(['ok' => false, 'error' => RewardsFlow::ERR_NOT_APPLICABLE], $r['json']);

// ---------------------------------------------------------------- remove
seed_rate();
$r = post($A, 'rewards_remove');
assert_eq(200, $r['status'], 'remove: ' . $r['body']);
assert_eq(['ok' => true], $r['json']);
assert_eq(10000, (int)row($A)['amount_sats'], 'back to the full sats');
$html = page($A);
assert_eq('10.00 USD (10,000 sats)', amount_line($html, 'payment-pending'), 'plain amount again');
assert_true(str_contains($html, 'id="rewards-apply-btn">Apply rewards</button>'), 'Apply offered again');
assert_false(str_contains($html, 'id="rewards-remove-btn"'), 'no Remove');
$r = post($A, 'rewards_remove');
assert_eq(400, $r['status']);
assert_eq(['ok' => false, 'error' => RewardsFlow::ERR_NOT_APPLIED], $r['json'], 'nothing to remove');

// ---------------------------------------------------------------- refusals
seed_rate();
$r = post($A, 'rewards_apply', ['username' => 'Nobody1']);
assert_eq(400, $r['status']);
assert_eq(['ok' => false, 'error' => RewardsFlow::ERR_NO_REWARDS], $r['json'], 'unknown username: exact text');
$r = post($A, 'rewards_apply', ['username' => 'ab']);
assert_eq(400, $r['status']);
assert_eq('Username must be at least 3 characters', $r['json']['error']);
$r = post($A, 'rewards_apply', []);
assert_eq(400, $r['status'], 'missing username');
assert_eq('Username must be at least 3 characters', $r['json']['error']);
$r = post('inv_missing', 'rewards_apply', ['username' => 'Alice']);
assert_eq(404, $r['status']);
assert_eq(['ok' => false, 'error' => 'Invoice not found'], $r['json']);
assert_eq(404, post('inv_missing', 'rewards_remove')['status']);
assert_eq(404, post('inv_missing', 'rewards_claim', ['username' => 'Alice'])['status']);
assert_eq(10000, (int)row($A)['amount_sats'], 'refusals never touched the invoice');

// XSS-shaped username: refused by validation, never reflected unescaped.
$evil = '<img src=x onerror=1>';
foreach (['rewards_apply', 'rewards_claim'] as $action) {
    $r = post($A, $action, ['username' => $evil]);
    assert_eq(400, $r['status'], "$action refuses the markup username");
    assert_true(in_array($r['json']['error'], ['Username may only contain letters and numbers', RewardsFlow::ERR_NOTHING_TO_CLAIM], true), "$action error: " . $r['body']);
    assert_false(str_contains($r['body'], '<img'), "$action never echoes raw markup");
    assert_false(str_contains($r['body'], 'onerror'), "$action never echoes the payload");
}
assert_false(str_contains(page($A), 'onerror=1'), 'page never carries the payload');
assert_eq(3, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM rewards_accounts")['c'], 'no account created for the refused name (Alice, Bob, Frank only)');

// ---------------------------------------------------------------- full coverage
$inv = Invoice::create('s_usd', ['amount' => '0.30', 'currency' => 'USD']);
$E = $inv['id'];
seed_rate();
$r = post($E, 'rewards_apply', ['username' => 'bob']);
assert_eq(200, $r['status'], 'full coverage apply: ' . $r['body']);
assert_eq('Settled', $r['json']['status']);
assert_true($r['json']['fullCoverage']);
assert_eq('0.30', $r['json']['applied']['discountAmount']);
assert_eq('Settled', row($E)['status']);
$html = page($E);
assert_true(str_contains($html, 'id="payment-success" class="success-animation show"'), 'success screen shown');
assert_true(str_contains($html, 'id="payment-pending" class="hidden"'), 'pending hidden');
assert_eq('<s class="amount-original">0.30 USD</s><span class="amount-effective">0.00 USD</span>', amount_line($html, 'payment-success'), 'nothing owed');
assert_true(str_contains($html, 'Rewards applied: −0.30 USD (Bob)'), 'full-coverage applied line');
assert_false(str_contains($html, 'id="rewards-remove-btn"'), 'no Remove once settled');
assert_eq(true, has_hidden($html, 'rewards-claim'), 'nothing earned on full coverage: no claim prompt');
assert_eq(true, has_hidden($html, 'rewards-credited'), 'no credited line either');
$p = poll($E);
assert_eq('Settled', $p['status']);
assert_null($p['rewards']['earned']);
assert_eq(['username' => 'Bob', 'discountAmount' => '0.30', 'currency' => 'USD', 'display' => '0.30 USD', 'fullCoverage' => true], $p['rewards']['applied']);

// ---------------------------------------------------------------- claim
$inv = Invoice::create('s_usd', ['amount' => '20.00', 'currency' => 'USD']);
$D = $inv['id'];
assert_true(Invoice::updateStatus($D, 'Settled', null, 'mint'), 'settled (no account known)');
assert_eq(400000, (int)row($D)['rewards_earned_micro'], '2% of $20');
$html = page($D);
assert_eq(false, has_hidden($html, 'rewards-claim'), 'claim prompt shown');
assert_true(str_contains($html, 'You are due <strong id="rewards-claim-amount">0.40 USD</strong> in rewards.'), 'claim text with the amount');
assert_true(str_contains($html, $CLAIM_COPY), 'claim copy');
assert_true(str_contains($html, 'id="rewards-claim-username" name="username"'), 'claim username input');
assert_true(str_contains($html, 'id="rewards-claim-submit">Claim rewards</button>'), 'Claim button');
assert_true(str_contains($html, 'id="rewards-claim-skip">Skip</button>'), 'Skip button');
assert_eq(true, has_hidden($html, 'rewards-credited'), 'credited line hidden while unclaimed');
assert_eq('20.00 USD (20,000 sats)', amount_line($html, 'payment-success'), 'no discount: plain amount');
assert_false(str_contains($html, 'Rewards applied:'), 'nothing applied');
assert_true(str_contains($html, 'let rewardsState = {"earned":{"display":"0.40 USD","fiatDisplay":null,"claimable":true,"claimedTo":null},"applied":null}'), 'bootstrap state carries the earn');
$p = poll($D);
assert_eq(['display' => '0.40 USD', 'fiatDisplay' => null, 'claimable' => true, 'claimedTo' => null], $p['rewards']['earned'], 'poll carries the claimable earn');
assert_null($p['rewards']['applied']);

$r = post($D, 'rewards_claim', ['username' => ' Carol ']);
assert_eq(200, $r['status'], 'claim: ' . $r['body']);
assert_eq(['ok' => true, 'credited' => ['display' => '0.40 USD', 'username' => 'Carol']], $r['json'], 'rewards_claim JSON contract');
assert_eq(400000, (int)Rewards::getAccount('s_usd', 'carol')['balance_micro'], 'credited');
$html = page($D);
assert_eq(true, has_hidden($html, 'rewards-claim'), 'prompt gone after the claim');
assert_eq(false, has_hidden($html, 'rewards-credited'), 'credited line shown');
assert_true(str_contains($html, '<span id="rewards-credited-amount">0.40 USD</span> in rewards credited to <strong id="rewards-credited-user">Carol</strong>.'), 'credited line text');
$p = poll($D);
assert_eq(['display' => '0.40 USD', 'fiatDisplay' => null, 'claimable' => false, 'claimedTo' => 'Carol'], $p['rewards']['earned']);
$r = post($D, 'rewards_claim', ['username' => 'carol']);
assert_eq(200, $r['status'], 'same username again is idempotent');
$r = post($D, 'rewards_claim', ['username' => 'Dave']);
assert_eq(400, $r['status']);
assert_eq(['ok' => false, 'error' => RewardsFlow::ERR_ALREADY_CLAIMED], $r['json']);

// Claim window closed: nothing shown, claim refused.
$inv = Invoice::create('s_usd', ['amount' => '5.00', 'currency' => 'USD']);
$L = $inv['id'];
Invoice::updateStatus($L, 'Settled', null, 'mint');
Database::update('invoices', ['paid_at' => time() - Rewards::CLAIM_WINDOW_SEC - 5], 'id = ?', [$L]);
$html = page($L);
assert_eq(true, has_hidden($html, 'rewards-claim'), 'window closed: no prompt');
assert_eq(true, has_hidden($html, 'rewards-credited'), 'window closed: no credited line');
assert_eq('<strong id="rewards-claim-amount"></strong>', substr($html, strpos($html, '<strong id="rewards-claim-amount">'), strlen('<strong id="rewards-claim-amount"></strong>')), 'amount slot empty');
$p = poll($L);
assert_eq(false, $p['rewards']['earned']['claimable'], 'poll: not claimable');
$r = post($L, 'rewards_claim', ['username' => 'Late1']);
assert_eq(400, $r['status']);
assert_eq(['ok' => false, 'error' => RewardsFlow::ERR_CLAIM_CLOSED], $r['json']);
assert_null(Rewards::getAccount('s_usd', 'Late1'));

// A claim on a New invoice has nothing to claim.
$r = post($A, 'rewards_claim', ['username' => 'Carol']);
assert_eq(400, $r['status']);
assert_eq(RewardsFlow::ERR_NOTHING_TO_CLAIM, $r['json']['error']);

// ---------------------------------------------------------------- sat store
$inv = Invoice::create('s_sat', ['amount' => 2000, 'currency' => 'sat']);
$J = $inv['id'];
$r = post($J, 'rewards_apply', ['username' => 'frank']);
assert_eq(200, $r['status'], 'sat apply: ' . $r['body']);
assert_eq(['username' => 'Frank', 'discountAmount' => '500', 'currency' => 'sat', 'display' => '500 sats'], $r['json']['applied']);
$html = page($J);
assert_eq('<s class="amount-original">2000 SAT</s><span class="amount-effective">1500 SAT</span>', amount_line($html, 'payment-pending'), 'sat amount line');
assert_true(str_contains($html, 'Rewards applied: −500 SAT (Frank)</span>'), 'sat applied line, no second currency');
assert_eq(1500, (int)row($J)['amount_sats']);

// ---------------------------------------------------------------- rate bucket
assert_eq($posts, rate_count('payment_post'), 'every rewards POST counted in the payment_post bucket');
assert_eq($gets, rate_count('payment_view'), 'GETs (page + poll) in the payment_view bucket');
while ($posts < 40) {
    post($A, 'rewards_remove');
}
$r = post($A, 'rewards_remove');
assert_eq(429, $r['status'], 'the POST budget answers 429');
assert_eq('Too many requests. Please wait a minute and try again.', $r['json']['error']);
assert_eq(200, $srv->get('/payment.php?id=' . $A)['status'], 'GETs still served: separate bucket');
assert_eq('New', json_decode($srv->get('/payment.php?id=' . $A . '&json=1')['body'], true)['status'], 'poll still served');

$srv->stop();
echo "test_payment_page_rewards: ok\n";
