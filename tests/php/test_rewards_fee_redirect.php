<?php
/**
 * Cash back rewards on a fee-redirected invoice (dev fee owed >= the invoice,
 * Lightning rail routed to the fee LNURL): applying rewards re-fetches the
 * fee bolt11 from the SAME payee at the reduced sats — never from the
 * merchant or a mint — and the request still carries nothing but the amount.
 * Removing re-fetches at the full amount. Settlement over the reduced fee
 * bolt11 credits the fee for what was actually paid and runs the rewards
 * hook (commit + earn) before the webhook payload is built.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require __DIR__ . '/mock_lnurl_host.php';
define('CASHUPAY_IN_CRON', true);
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';
require_once dirname(__DIR__, 2) . '/app/includes/rewards_flow.php';
require_once dirname(__DIR__, 2) . '/app/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';

$host = start_mock_lnurl_host();
putenv('CASHU_LNURL_URL_TEMPLATE=' . $host['url_template']);
Config::set('allow_private_endpoints', true);
Config::set('fee_tracking_start_at', 0);
$now = time();
[$devUser] = explode('@', (string)CASHUPAY_DEV_FEE_LNURL, 2);

$store = 'store_fee_rw';
make_store($store, 'http://127.0.0.1:1'); // mint non-routable: never a fallback
StoreLnAddresses::replaceForStore($store, ['merchant@example.test']);
Rewards::setStoreSettings($store, true, 1.0);
Database::insert('webhooks', ['id' => 'wh1', 'store_id' => $store, 'url' => 'http://127.0.0.1:1/never',
    'secret' => 'shh', 'events' => json_encode([]), 'enabled' => 1, 'created_at' => Database::timestamp()]);
// 1% dev fee on 2M sats revenue = 20k owed: covers every invoice below.
Database::insert('invoices', [
    'id' => 'seed_rev', 'store_id' => $store, 'status' => 'Settled', 'amount' => '2000000',
    'currency' => 'sat', 'amount_sats' => 2000000, 'created_at' => $now - 60,
    'expiration_time' => $now + 3600, 'payment_rail' => 'lnaddress', 'settled_rail' => 'lnaddress', 'paid_at' => $now - 60,
]);
assert_true((int)DevFee::computeOwed($store)['dev_owed'] >= 20000, 'dev fee owed covers the invoices');

function row(string $id): array { return Database::fetchOne("SELECT * FROM invoices WHERE id = ?", [$id]); }
function parked(string $id): array {
    return Database::fetchAll("SELECT * FROM invoice_ln_quotes WHERE invoice_id = ? ORDER BY created_at, id", [$id]);
}
function requests_since(array $before): array {
    global $host;
    return array_slice(mock_lnurl_requests($host['dir']), count($before));
}
/** The fee host saw one lookup for the dev user and one amount-only callback. */
function assert_fee_only_requests(array $reqs, int $sats, string $label): void {
    global $devUser;
    assert_eq(["/.well-known/lnurlp/{$devUser}"], array_values(array_filter($reqs, fn($r) => str_starts_with($r, '/.well-known/'))), "$label: only the dev LNURL was looked up");
    $cbs = array_values(array_filter($reqs, fn($r) => str_starts_with($r, '/cb')));
    assert_eq(1, count($cbs), "$label: one callback");
    parse_str((string)parse_url($cbs[0], PHP_URL_QUERY), $q);
    assert_eq(['amount' => (string)($sats * 1000)], $q, "$label: callback carries the amount and nothing else");
    assert_false(stripos(implode("\n", $reqs), 'merchant') !== false, "$label: merchant address never contacted");
}

$jack = Rewards::getOrCreateAccount($store, 'Jack');
Rewards::credit($jack['id'], 'seed_jack', 1000 * Rewards::MICRO, 'sat');

// ---- creation: fee-redirected lightning rail ----
$before = mock_lnurl_requests($host['dir']);
$inv = Invoice::create($store, ['amount' => 5000, 'currency' => 'sat']);
$id = $inv['id'];
assert_eq(FEE_NOTE_DEV, $inv['fee_redirect_note'], 'dev fee claimed the invoice');
assert_eq('lightning', $inv['fee_redirect_rails']);
assert_eq('lnaddress', $inv['payment_rail']);
assert_eq((string)CASHUPAY_DEV_FEE_LNURL, $inv['ln_destination']);
assert_eq(5000, (int)$inv['amount_sats']);
assert_eq(0, (int)$inv['long_lived'], 'an ordinary (not long-lived) invoice');
assert_fee_only_requests(requests_since($before), 5000, 'create');
assert_eq('not_refreshable', Invoice::refreshLightning($id)['reason'], 'plain refresh is for long-lived invoices only');

// ---- apply: re-fetched from the fee payee at the reduced sats ----
$before = mock_lnurl_requests($host['dir']);
$r = RewardsFlow::applyToInvoice($id, 'Jack');
assert_true($r['ok'], 'apply: ' . json_encode($r));
assert_eq('1000', $r['applied']['discountAmount']);
$a = row($id);
assert_eq(4000, (int)$a['amount_sats'], 'reduced sats');
assert_neq($inv['bolt11'], $a['bolt11'], 'new fee bolt11');
assert_neq($inv['lnurl_verify_url'], $a['lnurl_verify_url']);
assert_eq('lnaddress', $a['payment_rail'], 'still the lnaddress rail');
foreach (['fee_redirect_note', 'fee_redirect_destination', 'fee_redirect_rails', 'ln_destination'] as $col) {
    assert_eq($inv[$col], $a[$col], "$col unchanged");
}
assert_fee_only_requests(requests_since($before), 4000, 'apply');
$p = parked($id);
assert_eq(1, count($p), 'old fee quote parked');
assert_eq($inv['bolt11'], $p[0]['bolt11']);
assert_eq(5000, (int)$p[0]['amount_sats']);
$att = array_values(array_filter(InvoiceAttempts::listForInvoice($id), fn($x) => $x['context'] === 'rewards'));
$rails = array_column($att, 'rail');
assert_true(in_array('fee', $rails, true), 'fee re-fetch logged under the rewards context: ' . json_encode($rails));
assert_true(in_array('rewards', $rails, true));
assert_true(Invoice::formatForApi($a)['feeRedirect']['rails'] === ['lightning'], 'API badge intact');
assert_eq('1000', Invoice::formatForApi($a)['rewards']['appliedAmount']);

// ---- remove: back to the full amount from the same payee ----
$before = mock_lnurl_requests($host['dir']);
assert_true(RewardsFlow::removeFromInvoice($id)['ok'], 'remove');
$b = row($id);
assert_eq(5000, (int)$b['amount_sats']);
assert_neq($a['bolt11'], $b['bolt11']);
assert_fee_only_requests(requests_since($before), 5000, 'remove');
assert_eq(2, count(parked($id)));
assert_eq(1000 * Rewards::MICRO, (int)Rewards::getAccountById($jack['id'])['balance_micro']);

// ---- fee payee down: the apply is rolled back, nothing changes ----
mock_lnurl_set_mode($host['dir'], 'fail');
$before = mock_lnurl_requests($host['dir']);
$r = RewardsFlow::applyToInvoice($id, 'Jack');
assert_false($r['ok']);
assert_eq(RewardsFlow::ERR_RETRY, $r['error'], 'payer asked to try again');
$c = row($id);
assert_eq($b['bolt11'], $c['bolt11'], 'bolt11 kept');
assert_eq(5000, (int)$c['amount_sats']);
assert_null($c['rewards_spend_state'], 'apply undone');
assert_null($c['rewards_account_id']);
assert_eq(1000 * Rewards::MICRO, (int)Rewards::getAccountById($jack['id'])['balance_micro'], 'reservation released');
assert_eq(0, (int)Rewards::getAccountById($jack['id'])['reserved_micro']);
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS c FROM rewards_ledger WHERE invoice_id = ?", [$id])['c'], 'ledger clean again');
$reqs = requests_since($before);
assert_false(stripos(implode("\n", $reqs), 'merchant') !== false, 'merchant never tried as a fallback');
assert_eq(2, count(parked($id)), 'nothing parked on the failed refresh');
mock_lnurl_set_mode($host['dir'], 'ok');

// ---- apply again, then the reduced fee bolt11 gets paid ----
assert_true(RewardsFlow::applyToInvoice($id, 'jack')['ok'], 're-apply');
$d = row($id);
assert_eq(4000, (int)$d['amount_sats']);
mock_lnurl_settle($host['dir'], (string)$d['lnurl_verify_url']);
Invoice::pollSingleLnAddress($id);
$s = row($id);
assert_eq('Settled', $s['status'], 'settled via markLnAddressPaid');
assert_eq('lnaddress', $s['settled_rail']);
assert_eq('committed', $s['rewards_spend_state'], 'hook ran on the lnaddress path');
assert_eq(40 * Rewards::MICRO, (int)$s['rewards_earned_micro'], '1% of the NET 4000 sats');
$j = Rewards::getAccountById($jack['id']);
assert_eq(40 * Rewards::MICRO, (int)$j['balance_micro'], 'earn credited');
assert_eq(1000 * Rewards::MICRO, (int)$j['spent_micro']);
$melt = Database::fetchOne("SELECT * FROM melts WHERE invoice_id = ? AND via = 'redirect'", [$id]);
assert_not_null($melt, 'redirect credit recorded');
assert_eq(4000, (int)$melt['amount_sats'], 'fee credited for what was actually paid');
assert_true(Invoice::formatForApi($s)['feeRedirect']['settledToFee']);
$payload = json_decode((string)Database::fetchOne(
    "SELECT payload FROM webhook_deliveries WHERE invoice_id = ? AND event_type = 'InvoiceSettled'", [$id]
)['payload'], true);
assert_eq('1000', $payload['invoice']['rewards']['appliedAmount'], 'payload carries the block');
assert_eq('40', $payload['invoice']['rewards']['earnedAmount']);
assert_true($payload['invoice']['rewards']['earnedClaimed']);
assert_eq(4000, (int)$payload['invoice']['amountSats']);

echo "test_rewards_fee_redirect: ok\n";
