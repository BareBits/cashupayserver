<?php
/**
 * Cash back rewards, invoice side (includes/rewards_flow.php + the Invoice /
 * webhook integration), against a mock mint and a scripted price:
 *
 *   A. Partial apply on a mint-rail USD invoice: new mint quote at the lower
 *      sats, amount_sats updated, rewards columns + reservation, attempt rows,
 *      old quote parked, exchange_rate kept, view / API shapes.
 *   B. Remove restores the full amount, releases, and the invoice can be
 *      applied to again (the payer's undo).
 *   C. Settlement with rewards applied: reservation committed, earn = percent
 *      of the NET amount, auto-credited (claimed_at set), webhook payload
 *      frozen WITH the rewards block, idempotent on a second settle.
 *   D. Earn without an account: unclaimed, claimable for 24h, claimEarned
 *      creates the account, is idempotent for the same username, refuses
 *      another username, refuses outside the window.
 *   E. Full coverage settles at once on the 'rewards' rail with amount_sats 0.
 *   F. Refusals: too small, unknown / zero balance (same message), tip jar,
 *      store off, non-New, already applied, bad username; tip jar and store
 *      off earn nothing at settlement.
 *   G. Two invoices cannot spend the same balance.
 *   H. Expiry releases the reservation (and the webhook payload has no block).
 *   I. Invalid after Settled reverses the earn.
 *   J. Sat-currency store: sat invoice, and a USD invoice earning sats at the
 *      invoice's own exchange rate; a sat invoice on a USD store.
 *   K. No Lightning rail at all: amount_sats and the unique on-chain address
 *      move with a floor.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_mint_stub.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards_flow.php';
require_once dirname(__DIR__, 2) . '/app/includes/rates.php';

// $100,000 / BTC: 1 sat = $0.001. Never the network.
final class StubPriceProvider implements PriceProvider {
    public static float $usd = 100000.0;
    public function getName(): string { return 'stub'; }
    public function getBtcPrice(string $currency): ?float {
        return strtoupper($currency) === 'USD' ? self::$usd : null;
    }
}
ExchangeRates::$testProviders = ['stub' => new StubPriceProvider()];
function rate_cache_clear(): void {
    Database::query("DELETE FROM config WHERE key LIKE 'rate_%'");
    Config::clearCache();
}
function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function acct(string $store, string $user): array { return Rewards::getAccount($store, $user); }
function parked(string $id): int {
    return (int)Database::fetchOne("SELECT COUNT(*) AS c FROM invoice_ln_quotes WHERE invoice_id = ?", [$id])['c'];
}
function deliveries(string $id, string $event): array {
    return Database::fetchAll("SELECT payload FROM webhook_deliveries WHERE invoice_id = ? AND event_type = ? ORDER BY rowid", [$id, $event]);
}
function last_payload(string $id, string $event): array {
    $d = deliveries($id, $event);
    assert_true(count($d) >= 1, "$event delivery enqueued for $id");
    return json_decode((string)end($d)['payload'], true);
}
function attempts(string $id, string $context): array {
    return array_values(array_map(
        fn(array $r): array => [$r['rail'], $r['outcome'], $r['detail']],
        array_filter(InvoiceAttempts::listForInvoice($id), fn(array $r): bool => $r['context'] === $context)
    ));
}
function ledger_kinds(string $invoiceId): array {
    $k = array_column(Database::fetchAll("SELECT kind FROM rewards_ledger WHERE invoice_id = ? ORDER BY id", [$invoiceId]), 'kind');
    sort($k);
    return $k;
}
function mint_quote_amounts(string $dir): array {
    $out = [];
    foreach (explode("\n", (string)@file_get_contents($dir . '/requests.log')) as $line) {
        if (!str_starts_with($line, 'POST /v1/mint/quote/bolt11')) continue;
        $body = json_decode(explode("\t", $line, 2)[1] ?? '', true) ?: [];
        $out[] = (int)($body['amount'] ?? 0);
    }
    return $out;
}

$mint = start_mock_mint(['quotes' => [], 'quote_ttl' => 3600]);
Config::set('allow_private_endpoints', true);
// Settled revenue accrues a dev fee that would redirect the small invoices
// below to the fee LNURL (no mock host here); the fee path has its own test
// (test_rewards_fee_redirect.php). Start fee tracking in the future.
Config::set('fee_tracking_start_at', time() + 86400 * 365);

// ---- stores ----
make_store('s_usd', $mint['url']);
Database::update('stores', ['default_currency' => 'USD', 'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_usd']);
Rewards::setStoreSettings('s_usd', true, 2.0);            // 2 %, balances in USD
make_store('s_sat', $mint['url']);
Database::update('stores', ['price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_sat']);
Rewards::setStoreSettings('s_sat', true, 1.0);            // 1 %, balances in sats
make_store('s_off', $mint['url']);
Database::update('stores', ['default_currency' => 'USD', 'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub'], 'id = ?', ['s_off']);
foreach (['s_usd', 's_sat', 's_off'] as $sid) {
    Database::insert('webhooks', ['id' => 'wh_' . $sid, 'store_id' => $sid, 'url' => 'http://127.0.0.1:1/never',
        'secret' => 'shh', 'events' => json_encode([]), 'enabled' => 1, 'created_at' => Database::timestamp()]);
}
assert_eq('USD', Rewards::forStore('s_usd')['currency']);
assert_eq('sat', Rewards::forStore('s_sat')['currency']);

// ======================================================================
// A. partial apply on a mint-rail USD invoice
// ======================================================================
$alice = Rewards::getOrCreateAccount('s_usd', 'Alice');
assert_true(Rewards::credit($alice['id'], 'seed_alice', 1500000, 'USD'), 'Alice starts with $1.50');

$inv = Invoice::create('s_usd', ['amount' => '10.00', 'currency' => 'USD']);
$A = $inv['id'];
assert_eq('mint', $inv['payment_rail'], 'created on the mint rail');
assert_eq(10000, (int)$inv['amount_sats'], '$10 = 10 000 sats');
assert_eq(100000.0, (float)$inv['exchange_rate'], 'rate recorded at creation');
$oldBolt11 = $inv['bolt11'];
$oldQuote = $inv['quote_id'];

$view = RewardsFlow::invoiceView($inv);
assert_true($view['enabled'] && $view['canApply'], 'fresh invoice can take rewards');
assert_null($view['applied']);
assert_null($view['earned']);
assert_eq('10.00', $view['effectiveAmount']);
assert_eq(2.0, $view['percent']);
assert_eq('USD', $view['rewardsCurrency']);
assert_null(RewardsFlow::apiBlock($inv), 'no rewards block before anything happened');
assert_false(array_key_exists('rewards', Invoice::formatForApi($inv)), 'formatForApi omits the key when null');

mock_mint_clear_requests($mint['dir']);
$res = RewardsFlow::applyToInvoice($A, ' alice ');
assert_true($res['ok'], 'apply ok: ' . json_encode($res));
assert_eq('New', $res['status']);
assert_false($res['fullCoverage']);
assert_eq('Alice', $res['applied']['username'], 'display spelling of the account, not the input');
assert_eq('1.50', $res['applied']['discountAmount']);
assert_eq('USD', $res['applied']['currency']);
assert_eq(1500000, $res['applied']['micro']);
assert_eq('1.50 USD', $res['applied']['display']);
assert_eq('reserved', $res['applied']['state']);
assert_false($res['applied']['fullCoverage']);

$a = row($A);
assert_eq('New', $a['status']);
assert_eq('1.50', $a['rewards_discount_amount'], 'discount recorded in the invoice currency');
assert_eq(1500000, (int)$a['rewards_applied_micro'], 'reservation in rewards micro');
assert_eq('reserved', $a['rewards_spend_state']);
assert_eq($alice['id'], $a['rewards_account_id']);
assert_eq(8500, (int)$a['amount_sats'], 'Lightning re-sourced at the discounted sats');
assert_neq($oldBolt11, $a['bolt11'], 'new bolt11');
assert_neq($oldQuote, $a['quote_id'], 'new mint quote');
assert_eq('mint', $a['payment_rail']);
assert_eq([8500], mint_quote_amounts($mint['dir']), 'the mint was asked for exactly the reduced amount');
assert_eq(1, parked($A), 'old quote parked for the grace poll');
assert_eq($oldBolt11, Database::fetchOne("SELECT bolt11 FROM invoice_ln_quotes WHERE invoice_id = ?", [$A])['bolt11']);
assert_eq(100000.0, (float)$a['exchange_rate'], 'rate unchanged (same price)');
assert_eq('8.50', RewardsFlow::effectiveAmount($a), 'effective amount = amount - discount');
$al = acct('s_usd', 'alice');
assert_eq(0, (int)$al['balance_micro'], 'whole balance reserved');
assert_eq(1500000, (int)$al['reserved_micro']);
assert_eq(['reserve'], ledger_kinds($A));
$att = attempts($A, 'rewards');
$rails = array_column($att, 0);
assert_true(in_array('rewards', $rails, true), 'rewards attempt row recorded: ' . json_encode($att));
assert_true(in_array('mint', $rails, true), 'the re-source itself is logged under the rewards context');
$rw = array_values(array_filter($att, fn($r) => $r[0] === 'rewards'))[0];
assert_eq('ok', $rw[1]);
assert_true(str_contains((string)$rw[2], '1.50 USD') && str_contains((string)$rw[2], 'Alice'), 'detail carries amount + username: ' . $rw[2]);
assert_false(str_contains((string)$rw[2], $alice['id']), 'detail does not leak the account id');

$view = RewardsFlow::invoiceView($a);
assert_false($view['canApply'], 'cannot apply twice');
assert_eq(RewardsFlow::BLOCKED_ALREADY_APPLIED, $view['applyBlockedReason']);
assert_eq('8.50', $view['effectiveAmount']);
assert_eq('10.00', $view['originalAmount']);
assert_eq('Alice', $view['applied']['username']);
$api = Invoice::formatForApi($a);
assert_eq([
    'appliedAmount' => '1.50', 'appliedCurrency' => 'USD', 'appliedUsername' => 'Alice', 'fullCoverage' => false,
    'earnedAmount' => null, 'earnedCurrency' => null, 'earnedClaimed' => false, 'earnedUsername' => null,
], $api['rewards'], 'formatForApi rewards block while applied');
assert_eq('mint', $api['paymentRail']);
assert_eq(8500, $api['amountInMintUnit']);

// A second apply (any user) is refused as already applied.
$again = RewardsFlow::applyToInvoice($A, 'Alice');
assert_false($again['ok']);
assert_eq(RewardsFlow::ERR_NOT_APPLICABLE, $again['error']);
assert_eq(RewardsFlow::BLOCKED_ALREADY_APPLIED, $again['reason']);

// ======================================================================
// B. remove: full amount restored, reservation released, re-apply possible
// ======================================================================
mock_mint_clear_requests($mint['dir']);
$rm = RewardsFlow::removeFromInvoice($A);
assert_true($rm['ok'], 'remove ok: ' . json_encode($rm));
$b = row($A);
assert_eq(10000, (int)$b['amount_sats'], 'back to the full sats');
assert_neq($a['bolt11'], $b['bolt11'], 'fresh bolt11 for the full amount');
assert_eq([10000], mint_quote_amounts($mint['dir']), 'mint asked for the full amount again');
assert_eq(2, parked($A), 'discounted quote parked too');
assert_null($b['rewards_discount_amount']);
assert_null($b['rewards_applied_micro']);
assert_null($b['rewards_spend_state']);
assert_null($b['rewards_account_id']);
assert_eq('10.00', RewardsFlow::effectiveAmount($b));
$al = acct('s_usd', 'alice');
assert_eq(1500000, (int)$al['balance_micro'], 'balance handed back');
assert_eq(0, (int)$al['reserved_micro']);
assert_eq([], ledger_kinds($A), 'undo leaves the invoice a clean slate in the ledger');
assert_true(RewardsFlow::invoiceView($b)['canApply'], 'can apply again after the undo');
assert_eq(RewardsFlow::ERR_NOT_APPLIED, RewardsFlow::removeFromInvoice($A)['error'], 'nothing to remove now');
$removed = array_values(array_filter(attempts($A, 'rewards'), fn($r) => $r[0] === 'rewards'));
assert_eq(2, count($removed), 'apply + remove rows');
assert_true(str_contains((string)$removed[1][2], 'removed'), $removed[1][2]);

// Re-apply (the payer changed their mind again).
$res = RewardsFlow::applyToInvoice($A, 'ALICE');
assert_true($res['ok'], 're-apply after undo: ' . json_encode($res));
assert_eq(8500, (int)row($A)['amount_sats']);
assert_eq(['reserve'], ledger_kinds($A));

// ======================================================================
// C. settle with rewards applied: commit + NET earn + auto-credit + webhook
// ======================================================================
assert_true(Invoice::updateStatus($A, 'Settled', null, 'mint'), 'settled');
$c = row($A);
assert_eq('Settled', $c['status']);
assert_eq('committed', $c['rewards_spend_state']);
assert_eq(170000, (int)$c['rewards_earned_micro'], '2% of the NET $8.50 = $0.17');
assert_eq('USD', $c['rewards_earn_currency']);
assert_not_null($c['rewards_claimed_at'], 'auto-credited because the account is known');
$al = acct('s_usd', 'alice');
assert_eq(170000, (int)$al['balance_micro'], 'earn credited, reservation gone');
assert_eq(0, (int)$al['reserved_micro']);
assert_eq(1500000, (int)$al['spent_micro']);
assert_eq(['commit', 'earn', 'reserve'], ledger_kinds($A));
$p = last_payload($A, 'InvoiceSettled');
assert_eq([
    'appliedAmount' => '1.50', 'appliedCurrency' => 'USD', 'appliedUsername' => 'Alice', 'fullCoverage' => false,
    'earnedAmount' => '0.17', 'earnedCurrency' => 'USD', 'earnedClaimed' => true, 'earnedUsername' => 'Alice',
], $p['invoice']['rewards'], 'webhook payload frozen WITH the rewards block');
assert_eq(8500, (int)$p['invoice']['amountSats']);
$view = RewardsFlow::invoiceView($c);
assert_false($view['canApply']);
assert_eq(RewardsFlow::BLOCKED_NOT_OPEN, $view['applyBlockedReason']);
assert_eq('committed', $view['applied']['state']);
assert_eq(170000, $view['earned']['micro']);
assert_eq('0.17 USD', $view['earned']['display']);
assert_eq(170, $view['earned']['sats']);
assert_null($view['earned']['fiatDisplay'], 'rewards currency is the store currency: no second display');
assert_false($view['earned']['claimable'], 'already credited');
assert_eq('Alice', $view['earned']['claimedTo']);
assert_eq((int)$c['paid_at'] + Rewards::CLAIM_WINDOW_SEC, $view['earned']['claimDeadline']);
// Claim by the credited user is idempotent; another user is refused.
$cl = RewardsFlow::claimEarned($A, 'alice');
assert_true($cl['ok']);
assert_eq('0.17 USD', $cl['credited']['display']);
assert_eq(170000, (int)acct('s_usd', 'alice')['balance_micro'], 'no double credit');
assert_eq(RewardsFlow::ERR_ALREADY_CLAIMED, RewardsFlow::claimEarned($A, 'Bob')['error']);
// Settling again is a no-op everywhere.
assert_false(Invoice::updateStatus($A, 'Settled', null, 'mint'));
RewardsFlow::onSettled($A);
assert_eq(170000, (int)acct('s_usd', 'alice')['balance_micro'], 'onSettled is idempotent');
assert_eq(1, count(deliveries($A, 'InvoiceSettled')));
assert_eq(RewardsFlow::ERR_NOT_APPLIED, RewardsFlow::removeFromInvoice($A)['error'], 'cannot remove after settlement');

// ======================================================================
// D. earn without an account: claim window
// ======================================================================
$inv2 = Invoice::create('s_usd', ['amount' => '20.00', 'currency' => 'USD']);
$D = $inv2['id'];
Invoice::updateStatus($D, 'Settled', null, 'mint');
$d = row($D);
assert_eq(400000, (int)$d['rewards_earned_micro'], '2% of $20 = $0.40');
assert_null($d['rewards_claimed_at'], 'unclaimed: no account known');
assert_null($d['rewards_account_id']);
assert_null($d['rewards_spend_state']);
$p = last_payload($D, 'InvoiceSettled');
assert_eq([
    'appliedAmount' => null, 'appliedCurrency' => null, 'appliedUsername' => null, 'fullCoverage' => false,
    'earnedAmount' => '0.40', 'earnedCurrency' => 'USD', 'earnedClaimed' => false, 'earnedUsername' => null,
], $p['invoice']['rewards']);
$view = RewardsFlow::invoiceView($d);
assert_null($view['applied']);
assert_true($view['earned']['claimable'], 'claimable inside the window');
assert_null($view['earned']['claimedTo']);
assert_eq('0.40 USD', $view['earned']['display']);
assert_null(Rewards::getAccount('s_usd', 'Bob'), 'Bob does not exist yet');
$cl = RewardsFlow::claimEarned($D, 'Bob');
assert_true($cl['ok'], 'claim: ' . json_encode($cl));
assert_eq(['micro' => 400000, 'display' => '0.40 USD', 'username' => 'Bob'], $cl['credited']);
$bob = acct('s_usd', 'bob');
assert_not_null($bob, 'claim created the account');
assert_eq(400000, (int)$bob['balance_micro']);
$d = row($D);
assert_eq($bob['id'], $d['rewards_account_id']);
assert_not_null($d['rewards_claimed_at']);
assert_eq('Bob', Invoice::formatForApi($d)['rewards']['earnedUsername']);
assert_true(Invoice::formatForApi($d)['rewards']['earnedClaimed']);
$cl2 = RewardsFlow::claimEarned($D, 'BOB');
assert_true($cl2['ok'], 'same username again: idempotent');
assert_eq(400000, (int)acct('s_usd', 'bob')['balance_micro'], 'not credited twice');
assert_eq(RewardsFlow::ERR_ALREADY_CLAIMED, RewardsFlow::claimEarned($D, 'Carol')['error']);
assert_false(RewardsFlow::invoiceView(row($D))['earned']['claimable']);
assert_eq('Bob', RewardsFlow::invoiceView(row($D))['earned']['claimedTo']);
// Outside the window: nothing to claim any more.
$inv3 = Invoice::create('s_usd', ['amount' => '5.00', 'currency' => 'USD']);
$D3 = $inv3['id'];
Invoice::updateStatus($D3, 'Settled', null, 'mint');
assert_eq(100000, (int)row($D3)['rewards_earned_micro']);
Database::update('invoices', ['paid_at' => time() - Rewards::CLAIM_WINDOW_SEC - 1], 'id = ?', [$D3]);
assert_false(RewardsFlow::invoiceView(row($D3))['earned']['claimable'], 'window closed');
assert_eq(RewardsFlow::ERR_CLAIM_CLOSED, RewardsFlow::claimEarned($D3, 'Late')['error']);
assert_null(Rewards::getAccount('s_usd', 'Late'), 'no account created for a refused claim');
assert_eq(RewardsFlow::ERR_NOTHING_TO_CLAIM, RewardsFlow::claimEarned('inv_missing', 'x1y')['error']);
assert_true(str_contains((string)RewardsFlow::claimEarned($D, 'a b')['error'], 'letters and numbers'), 'bad username message passes through');

// ======================================================================
// E. full coverage: Bob ($0.40) pays a $0.30 invoice outright
// ======================================================================
$inv4 = Invoice::create('s_usd', ['amount' => '0.30', 'currency' => 'USD']);
$E = $inv4['id'];
assert_eq(300, (int)$inv4['amount_sats']);
mock_mint_clear_requests($mint['dir']);
$res = RewardsFlow::applyToInvoice($E, 'bob');
assert_true($res['ok'], 'full coverage apply: ' . json_encode($res));
assert_eq('Settled', $res['status']);
assert_true($res['fullCoverage']);
assert_true($res['applied']['fullCoverage']);
assert_eq('0.30', $res['applied']['discountAmount']);
assert_eq('committed', $res['applied']['state']);
$e = row($E);
assert_eq('Settled', $e['status']);
assert_eq(Rewards::RAIL, $e['settled_rail'], 'settled on the rewards rail');
assert_eq(0, (int)$e['amount_sats'], 'nothing owed on any rail');
assert_eq('0.30', $e['rewards_discount_amount']);
assert_eq(300000, (int)$e['rewards_applied_micro']);
assert_eq('committed', $e['rewards_spend_state']);
assert_eq(0, (int)$e['rewards_earned_micro'], 'full coverage earns nothing (NET = 0)');
assert_eq('0.00', RewardsFlow::effectiveAmount($e));
assert_eq([], mint_quote_amounts($mint['dir']), 'no Lightning re-source on full coverage');
assert_eq(0, parked($E));
$bob = acct('s_usd', 'bob');
assert_eq(100000, (int)$bob['balance_micro'], '$0.10 left');
assert_eq(0, (int)$bob['reserved_micro']);
assert_eq(300000, (int)$bob['spent_micro']);
$p = last_payload($E, 'InvoiceSettled');
assert_eq('Settled', $p['invoice']['status']);
assert_eq(0, (int)$p['invoice']['amountSats']);
assert_eq([
    'appliedAmount' => '0.30', 'appliedCurrency' => 'USD', 'appliedUsername' => 'Bob', 'fullCoverage' => true,
    'earnedAmount' => null, 'earnedCurrency' => null, 'earnedClaimed' => false, 'earnedUsername' => null,
], $p['invoice']['rewards'], 'full-coverage payload');
$api = Invoice::formatForApi($e);
assert_eq('rewards', $api['paymentRail'], 'paymentRail reads rewards');
assert_eq('rewards', Invoice::effectiveRail($e));
assert_true($api['rewards']['fullCoverage']);
assert_false(array_key_exists('amountInMintUnit', $api), 'no mint-unit amount at 0');
assert_false(Invoice::settledRailIsFeeRouted($e), 'rewards rail is never fee-routed');
$view = RewardsFlow::invoiceView($e);
assert_null($view['earned']);
assert_true($view['applied']['fullCoverage']);
assert_eq('0.00', $view['effectiveAmount']);
$notif = Database::fetchOne("SELECT COUNT(*) AS c FROM notification_queue")['c'] ?? null;
assert_not_null($notif, 'notification queue readable');

// ======================================================================
// F. refusals
// ======================================================================
$inv5 = Invoice::create('s_usd', ['amount' => '10.00', 'currency' => 'USD']);
$F = $inv5['id'];
$carol = Rewards::getOrCreateAccount('s_usd', 'Carol');
Rewards::credit($carol['id'], 'seed_carol', 5000, 'USD');       // $0.005: floors to 0.00
$r = RewardsFlow::applyToInvoice($F, 'Carol');
assert_eq(RewardsFlow::ERR_TOO_SMALL, $r['error'], 'sub-cent balance is too small');
assert_eq(5000, (int)acct('s_usd', 'carol')['balance_micro'], 'nothing reserved on refusal');
assert_null(row($F)['rewards_spend_state']);
$r = RewardsFlow::applyToInvoice($F, 'Nobody1');
assert_eq(RewardsFlow::ERR_NO_REWARDS, $r['error'], 'unknown username');
Rewards::getOrCreateAccount('s_usd', 'Dave');                 // exists, zero balance
assert_eq(RewardsFlow::ERR_NO_REWARDS, RewardsFlow::applyToInvoice($F, 'Dave')['error'], 'zero balance reads the same as unknown');
assert_true(str_contains((string)RewardsFlow::applyToInvoice($F, 'a b')['error'], 'letters and numbers'), 'bad username');
assert_true(str_contains((string)RewardsFlow::applyToInvoice($F, 'ab')['error'], 'at least'), 'short username');
assert_eq(10000, (int)row($F)['amount_sats'], 'refused applies never touched the invoice');
assert_eq($inv5['bolt11'], row($F)['bolt11']);
// Tip jar invoice: neither applies nor earns.
Database::update('invoices', ['tip_jar_id' => 'jar_x'], 'id = ?', [$F]);
$r = RewardsFlow::applyToInvoice($F, 'Alice');
assert_eq(RewardsFlow::ERR_NOT_APPLICABLE, $r['error']);
assert_eq(RewardsFlow::BLOCKED_TIP_JAR, $r['reason']);
assert_eq(RewardsFlow::BLOCKED_TIP_JAR, RewardsFlow::applyBlockedReason(row($F), Config::getStore('s_usd')));
Invoice::updateStatus($F, 'Settled', null, 'mint');
assert_eq(0, (int)row($F)['rewards_earned_micro'], 'tip jar earns nothing');
assert_null(RewardsFlow::apiBlock(row($F)), 'nothing rewards-related on a tip jar');
assert_eq(RewardsFlow::ERR_NOTHING_TO_CLAIM, RewardsFlow::claimEarned($F, 'Alice')['error']);
assert_false(array_key_exists('rewards', last_payload($F, 'InvoiceSettled')['invoice']), 'payload has no block');
// Store with rewards off.
$inv6 = Invoice::create('s_off', ['amount' => '10.00', 'currency' => 'USD']);
$G0 = $inv6['id'];
assert_eq(RewardsFlow::BLOCKED_STORE_OFF, RewardsFlow::applyBlockedReason($inv6, Config::getStore('s_off')));
$r = RewardsFlow::applyToInvoice($G0, 'Alice');
assert_eq(RewardsFlow::ERR_NOT_APPLICABLE, $r['error']);
assert_eq(RewardsFlow::BLOCKED_STORE_OFF, $r['reason']);
$view = RewardsFlow::invoiceView($inv6);
assert_false($view['enabled']);
assert_false($view['canApply']);
Invoice::updateStatus($G0, 'Settled', null, 'mint');
assert_eq(0, (int)row($G0)['rewards_earned_micro'], 'store off at settlement earns nothing');
assert_null(RewardsFlow::apiBlock(row($G0)));
// Non-New.
assert_eq(RewardsFlow::BLOCKED_NOT_OPEN, RewardsFlow::applyBlockedReason(row($A), Config::getStore('s_usd')));
assert_eq(RewardsFlow::ERR_NOT_APPLICABLE, RewardsFlow::applyToInvoice($A, 'Alice')['error']);
assert_eq(RewardsFlow::ERR_NOT_APPLICABLE, RewardsFlow::applyToInvoice('inv_missing', 'Alice')['error']);

// ======================================================================
// G. two invoices cannot spend the same balance
// ======================================================================
$g1 = Invoice::create('s_usd', ['amount' => '10.00', 'currency' => 'USD']);
$g2 = Invoice::create('s_usd', ['amount' => '10.00', 'currency' => 'USD']);
assert_eq(170000, (int)acct('s_usd', 'alice')['balance_micro']);
$r1 = RewardsFlow::applyToInvoice($g1['id'], 'Alice');
assert_true($r1['ok'], 'first invoice takes the balance: ' . json_encode($r1));
assert_eq('0.17', $r1['applied']['discountAmount']);
assert_eq(9830, (int)row($g1['id'])['amount_sats']);
$r2 = RewardsFlow::applyToInvoice($g2['id'], 'Alice');
assert_false($r2['ok']);
assert_eq(RewardsFlow::ERR_NO_REWARDS, $r2['error'], 'nothing left for the second invoice');
assert_eq(10000, (int)row($g2['id'])['amount_sats']);

// ======================================================================
// H. expiry releases the reservation
// ======================================================================
assert_true(Invoice::updateStatus($g1['id'], 'Expired', null, null, ['New']));
$h = row($g1['id']);
assert_eq('Expired', $h['status']);
assert_eq('released', $h['rewards_spend_state']);
assert_eq('0.17', $h['rewards_discount_amount'], 'history kept on the row');
$al = acct('s_usd', 'alice');
assert_eq(170000, (int)$al['balance_micro'], 'balance back');
assert_eq(0, (int)$al['reserved_micro']);
assert_eq(['release', 'reserve'], ledger_kinds($g1['id']));
assert_eq('invoice expired', Database::fetchOne("SELECT note FROM rewards_ledger WHERE invoice_id = ? AND kind = 'release'", [$g1['id']])['note']);
assert_null(RewardsFlow::apiBlock($h), 'a released reservation is nothing rewards-related');
assert_false(array_key_exists('rewards', last_payload($g1['id'], 'InvoiceExpired')['invoice']));
assert_null(RewardsFlow::invoiceView($h)['applied']);
RewardsFlow::onClosed($g1['id'], 'Expired', 'New');
assert_eq(170000, (int)acct('s_usd', 'alice')['balance_micro'], 'onClosed is idempotent');
// Now Alice can spend on the second invoice.
assert_true(RewardsFlow::applyToInvoice($g2['id'], 'Alice')['ok'], 'released balance usable again');
Invoice::updateStatus($g2['id'], 'Invalid', null, null, ['New']);
assert_eq('released', row($g2['id'])['rewards_spend_state'], 'Invalid from New releases too');
assert_eq(170000, (int)acct('s_usd', 'alice')['balance_micro']);

// ======================================================================
// I. Invalid after Settled reverses the earn (zero-conf reversal shape)
// ======================================================================
$inv7 = Invoice::create('s_usd', ['amount' => '5.00', 'currency' => 'USD']);
$I = $inv7['id'];
Invoice::updateStatus($I, 'Settled', null, 'onchain');
assert_eq(100000, (int)row($I)['rewards_earned_micro']);
assert_true(RewardsFlow::claimEarned($I, 'Erin')['ok']);
assert_eq(100000, (int)acct('s_usd', 'erin')['balance_micro']);
assert_true(Invoice::updateStatus($I, 'Invalid', null, null, ['Settled']), 'reversal flips Settled -> Invalid');
$erin = acct('s_usd', 'erin');
assert_eq(0, (int)$erin['balance_micro'], 'earn taken back');
assert_eq(0, (int)$erin['earned_micro']);
assert_eq(['earn', 'reverse'], ledger_kinds($I));
assert_eq(1, count(deliveries($I, 'InvoiceInvalid')));
RewardsFlow::onClosed($I, 'Invalid', 'Settled');
assert_eq(['earn', 'reverse'], ledger_kinds($I), 'reverse is idempotent');
// An unclaimed earn reversed: nothing to take back, no account, no error.
$inv8 = Invoice::create('s_usd', ['amount' => '5.00', 'currency' => 'USD']);
Invoice::updateStatus($inv8['id'], 'Settled', null, 'onchain');
Invoice::updateStatus($inv8['id'], 'Invalid', null, null, ['Settled']);
assert_eq('Invalid', row($inv8['id'])['status']);
assert_eq(RewardsFlow::ERR_NOTHING_TO_CLAIM, RewardsFlow::claimEarned($inv8['id'], 'Erin')['error'], 'no claim on an invalid invoice');

// ======================================================================
// J. sat-currency store; cross-currency earns
// ======================================================================
$frank = Rewards::getOrCreateAccount('s_sat', 'Frank');
Rewards::credit($frank['id'], 'seed_frank', 500 * Rewards::MICRO, 'sat');  // 500 sats
$j1 = Invoice::create('s_sat', ['amount' => 2000, 'currency' => 'sat']);
mock_mint_clear_requests($mint['dir']);
$r = RewardsFlow::applyToInvoice($j1['id'], 'frank');
assert_true($r['ok'], json_encode($r));
assert_eq('500', $r['applied']['discountAmount'], 'whole sats discount');
assert_eq('sat', $r['applied']['currency']);
assert_eq('500 sats', $r['applied']['display']);
assert_eq(500 * Rewards::MICRO, $r['applied']['micro']);
$j = row($j1['id']);
assert_eq(1500, (int)$j['amount_sats']);
assert_eq([1500], mint_quote_amounts($mint['dir']));
assert_eq('1500', RewardsFlow::effectiveAmount($j));
assert_null($j['exchange_rate'], 'sat invoice carries no rate');
Invoice::updateStatus($j1['id'], 'Settled', null, 'mint');
$j = row($j1['id']);
assert_eq(15 * Rewards::MICRO, (int)$j['rewards_earned_micro'], '1% of the NET 1500 sats = 15 sats');
assert_eq('sat', $j['rewards_earn_currency']);
$fr = acct('s_sat', 'frank');
assert_eq(15 * Rewards::MICRO, (int)$fr['balance_micro'], 'auto-credited');
assert_eq(500 * Rewards::MICRO, (int)$fr['spent_micro']);
$api = Invoice::formatForApi($j);
assert_eq('500', $api['rewards']['appliedAmount']);
assert_eq('sat', $api['rewards']['appliedCurrency']);
assert_eq('15', $api['rewards']['earnedAmount']);
assert_eq('sat', $api['rewards']['earnedCurrency']);
$view = RewardsFlow::invoiceView($j);
assert_eq('15 sats', $view['earned']['display']);
assert_eq(15, $view['earned']['sats']);
assert_null($view['earned']['fiatDisplay'], 'store default is sat: no fiat line');
// A USD invoice on the sat store earns sats at the INVOICE's exchange rate,
// even when the price has moved by settlement time.
$j2 = Invoice::create('s_sat', ['amount' => '10.00', 'currency' => 'USD']);
assert_eq(10000, (int)$j2['amount_sats']);
assert_eq(100000.0, (float)$j2['exchange_rate']);
StubPriceProvider::$usd = 200000.0;   // $10 would now be 5 000 sats
rate_cache_clear();
Invoice::updateStatus($j2['id'], 'Settled', null, 'mint');
assert_eq(100 * Rewards::MICRO, (int)row($j2['id'])['rewards_earned_micro'], '1% of 10 000 sats at the quoted rate, not 50 at today\'s');
StubPriceProvider::$usd = 100000.0;
rate_cache_clear();
// A sat invoice on the USD store earns USD: 1000 sats = $1.00 -> 2% = $0.02.
$j3 = Invoice::create('s_usd', ['amount' => 1000, 'currency' => 'sat']);
Invoice::updateStatus($j3['id'], 'Settled', null, 'mint');
assert_eq(20000, (int)row($j3['id'])['rewards_earned_micro'], 'sat invoice earns in the store\'s USD');
assert_eq('USD', row($j3['id'])['rewards_earn_currency']);
// Applying USD rewards to a sat invoice: Alice ($0.17) on a 1000 sat invoice -> 170 sats off.
$j4 = Invoice::create('s_usd', ['amount' => 1000, 'currency' => 'sat']);
$r = RewardsFlow::applyToInvoice($j4['id'], 'alice');
assert_true($r['ok'], json_encode($r));
assert_eq('170', $r['applied']['discountAmount'], 'USD balance expressed in sats');
assert_eq('0.17 USD', $r['applied']['display'], 'display stays in the rewards currency');
assert_eq(170000, (int)row($j4['id'])['rewards_applied_micro'], 'reserved exactly what 170 sats are worth');
assert_eq(830, (int)row($j4['id'])['amount_sats']);
// Fiat display of a sat earn on a USD-default store with sat balances.
Database::update('stores', ['default_currency' => 'USD'], 'id = ?', ['s_sat']);
$view = RewardsFlow::invoiceView(row($j1['id']));
assert_eq('0.01 USD', $view['earned']['fiatDisplay'], '15 sats ≈ $0.015 -> floors to 0.01 USD');
Database::update('stores', ['default_currency' => 'sat'], 'id = ?', ['s_sat']);

// ======================================================================
// K. no Lightning rail: amount_sats + unique on-chain address move with a floor
// ======================================================================
make_store('s_oc', 'http://127.0.0.1:1'); // mint unreachable: no bolt11 can ever be sourced
Database::update('stores', ['default_currency' => 'USD', 'price_provider_primary' => 'stub', 'price_provider_secondary' => 'stub',
    'onchain_address_mode' => 'xpub'], 'id = ?', ['s_oc']);
Rewards::setStoreSettings('s_oc', true, 1.0);
$gina = Rewards::getOrCreateAccount('s_oc', 'Gina');
Rewards::credit($gina['id'], 'seed_gina', 2000000, 'USD'); // $2.00
$now = time();
Database::insert('invoices', [
    'id' => 'inv_oc', 'store_id' => 's_oc', 'status' => 'New', 'additional_status' => 'None',
    'amount' => '10.00', 'currency' => 'USD', 'amount_sats' => 10000, 'exchange_rate' => 100000.0,
    'onchain_address' => 'bcrt1qunique0000000000000000000000', 'onchain_amount_sat' => 10000,
    'created_at' => $now, 'expiration_time' => $now + 900,
]);
$r = RewardsFlow::applyToInvoice('inv_oc', 'Gina');
assert_true($r['ok'], 'apply on a bolt11-less invoice: ' . json_encode($r));
$k = row('inv_oc');
assert_eq('2.00', $k['rewards_discount_amount']);
assert_eq(8000, (int)$k['amount_sats'], 'plain sats figure moved');
assert_null($k['bolt11'], 'still no bolt11');
assert_eq(8000, (int)$k['onchain_amount_sat'], 'on-chain amount re-amounted');
assert_eq(8000, (int)$k['onchain_amount_floor_sat'], 'floor = smallest shown');
assert_eq(0, parked('inv_oc'));
assert_true(RewardsFlow::removeFromInvoice('inv_oc')['ok']);
$k = row('inv_oc');
assert_eq(10000, (int)$k['amount_sats']);
assert_eq(10000, (int)$k['onchain_amount_sat']);
assert_eq(8000, (int)$k['onchain_amount_floor_sat'], 'floor keeps the minimum ever shown');
assert_eq(2000000, (int)acct('s_oc', 'gina')['balance_micro']);

echo "test_rewards_invoice_flow: ok\n";
