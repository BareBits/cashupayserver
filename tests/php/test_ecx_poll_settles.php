<?php
/**
 * The on-chain engine under Chain::ecx(): a scripted provider feeds ECX
 * observations, the invoice walks New -> Processing -> Settled with
 * settled_rail 'ecx', rows land in ecx_payments (never onchain_payments), the
 * pending-poll picker is per chain, the API payload carries ECX-OnChain, and
 * the Bitcoin poller of the same invoice is untouched by any of it.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/payments.php';
require_once dirname(__DIR__, 2) . '/app/includes/ecx/ecx.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

make_store('s1');
Database::update('stores', [
    'ecx_xpub' => 'zpubplaceholder', 'ecx_min_confs' => 1, 'ecx_confirm_timeout_sec' => 86400,
    'onchain_xpub' => 'xpubplaceholder', 'onchain_min_confs' => 0,
], 'id = ?', ['s1']);
$now = time();
$T = str_repeat('1', 64);
$U = str_repeat('2', 64);

$fake = new class implements BlockchainProvider {
    public array $obs = [];
    public int $tip = 984800;
    public array $asked = [];
    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        $this->asked[] = $address;
        return $this->obs[$address] ?? [];
    }
    public function currentTipHeight(): int { return $this->tip; }
};
OnchainProviderFactory::$testProvider = $fake;

// Dual-rail invoice: a Bitcoin address AND an eCash address.
Database::insert('invoices', [
    'id' => 'dual', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'onchain',
    'amount' => '10', 'currency' => 'USD', 'amount_sats' => 10000,
    'onchain_address' => 'bc1qbtcaddr', 'onchain_amount_sat' => 10000, 'onchain_created_tip_height' => 900000,
    'ecx_address' => 'bc1qecxaddr', 'ecx_amount_szat' => 25000000, 'ecx_created_tip_height' => 984790,
    'ecx_exchange_rate' => 40.0,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);

// ---- API payload before payment ------------------------------------------------
$api = Invoice::formatForApi(Invoice::getById('dual'));
$pm = $api['checkout']['paymentMethods'];
assert_true(isset($pm['BTC-OnChain']), 'bitcoin method present');
assert_true(isset($pm['ECX-OnChain']), 'eCash method present');
assert_eq('bc1qecxaddr', $pm['ECX-OnChain']['destination']);
assert_eq('ecash:bc1qecxaddr?amount=0.25', $pm['ECX-OnChain']['paymentLink'], 'ecash: URI with the ECX amount');
assert_eq('25000000', $pm['ECX-OnChain']['amount'], 'szats');
assert_eq(40.0, (float)$pm['ECX-OnChain']['rate'], 'ECX rate in the invoice currency');
assert_eq('bitcoin:bc1qbtcaddr?amount=0.0001', $pm['BTC-OnChain']['paymentLink'], 'bitcoin leg unchanged');
assert_eq(25000000, $api['ecx']['amountSzat']);
assert_false($api['ecx']['settled']);
assert_false($api['ecx']['settledToFee']);

// ---- pollPending picks per chain -------------------------------------------------
Database::insert('invoices', [
    'id' => 'ecxonly', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'ecx',
    'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1000,
    'ecx_address' => 'bc1qonlyecx', 'ecx_amount_szat' => 2500000,
    'created_at' => $now, 'expiration_time' => $now + 3600,
]);
$fake->asked = [];
$btcRun = OnchainPayments::pollPending(60, 20);
assert_eq(['dual'], array_keys($btcRun), 'bitcoin poller: only the invoice with a bitcoin address');
assert_eq(['bc1qbtcaddr'], $fake->asked, 'bitcoin poller asked for the bitcoin address only');
$fake->asked = [];
$ecxRun = OnchainPayments::pollPending(60, 20, Chain::ecx());
$keys = array_keys($ecxRun); sort($keys);
assert_eq(['dual', 'ecxonly'], $keys, 'ecx poller: both invoices with an eCash address');
$asked = $fake->asked; sort($asked);
assert_eq(['bc1qecxaddr', 'bc1qonlyecx'], $asked, 'ecx poller asked for the eCash addresses');
$row = Database::fetchOne("SELECT onchain_last_polled_at, ecx_last_polled_at FROM invoices WHERE id = 'ecxonly'");
assert_null($row['onchain_last_polled_at'], 'bitcoin stamp untouched on an eCash-only invoice');
assert_not_null($row['ecx_last_polled_at'], 'ecx stamp set');
// Throttled: an immediate second ECX pass skips both.
assert_eq([], OnchainPayments::pollPending(60, 20, Chain::ecx()), 'ecx poll throttled by ecx_last_polled_at');

// ---- an eCash payment: mempool -> Processing, confirmed -> Settled -----------------
// Historical-UTXO filter: a tx mined before the invoice existed is ignored.
$fake->obs['bc1qecxaddr'] = [new OnchainTxObservation($U, 0, 25000000, 20, 984780)];
$r = OnchainPayments::pollInvoice('dual', Chain::ecx());
assert_eq('New', $r['status'], 'pre-invoice tx (height < created tip) does not count');
// Fresh mempool payment of the full amount, min_confs 1 -> Processing.
$fake->obs['bc1qecxaddr'] = [new OnchainTxObservation($T, 1, 25000000, 0, null)];
$r = OnchainPayments::pollInvoice('dual', Chain::ecx());
assert_eq('Processing', $r['status']);
assert_eq('InvoiceReceivedPayment', $r['transition']);
$inv = Invoice::getById('dual');
assert_not_null($inv['ecx_first_seen_at'], 'ecx_first_seen_at stamped');
assert_null($inv['onchain_first_seen_at'], 'bitcoin first-seen untouched');
assert_eq(1, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM ecx_payments WHERE invoice_id = 'dual'")['n'], 'row in ecx_payments');
assert_eq(0, (int)Database::fetchOne("SELECT COUNT(*) AS n FROM onchain_payments")['n'], 'nothing in onchain_payments');
assert_true(EcxRail::isPending($inv), 'payment page: eCash confirmation pending');
assert_eq('https://explorer.ecash.com/tx/' . $T, EcxRail::txUrl($inv), 'explorer link on the ECX explorer');
// Confirmed -> Settled over the eCash rail.
$fake->obs['bc1qecxaddr'] = [new OnchainTxObservation($T, 1, 25000000, 1, 984800)];
$r = OnchainPayments::pollInvoice('dual', Chain::ecx());
assert_eq('Settled', $r['status']);
assert_eq('InvoiceSettled', $r['transition']);
$inv = Invoice::getById('dual');
assert_eq('ecx', $inv['settled_rail'], 'settled_rail = ecx');
assert_not_null($inv['paid_at']);
$api = Invoice::formatForApi($inv);
assert_eq('ecx', $api['paymentRail']);
assert_eq('bc1qecxaddr', $api['destination']);
assert_eq('ecx', $api['destinationChain']);
assert_eq($T, $api['txid']);
assert_eq('ecx', $api['txidChain']);
assert_true($api['ecx']['settled']);
// The sats ledger does not count it; the ECX ledger does.
assert_eq(0, DevFee::computeOwed('s1')['revenue'], 'ECX settlement is not sats revenue');
assert_eq(25000000, EcxFees::computeOwed('s1')['revenue_szats']);
// A later Bitcoin poll of the (now settled) invoice changes nothing.
$fake->obs['bc1qbtcaddr'] = [new OnchainTxObservation(str_repeat('3', 64), 0, 10000, 0, null)];
$r = OnchainPayments::pollInvoice('dual');
assert_eq('Settled', $r['status']);
assert_eq('ecx', Invoice::getById('dual')['settled_rail'], 'settle-once: the eCash rail keeps the settlement');

// ---- underpayment stays Processing; confirmation window -> Invalid ------------------
Database::insert('invoices', [
    'id' => 'under', 'store_id' => 's1', 'status' => 'New', 'payment_rail' => 'ecx',
    'amount' => '1', 'currency' => 'USD', 'amount_sats' => 1000,
    'ecx_address' => 'bc1qunder', 'ecx_amount_szat' => 2500000, 'ecx_first_seen_at' => $now - 90000,
    'created_at' => $now - 100000, 'expiration_time' => $now - 96400,
]);
$fake->obs['bc1qunder'] = [new OnchainTxObservation(str_repeat('4', 64), 0, 2000000, 3, 984798)];
$r = OnchainPayments::pollInvoice('under', Chain::ecx());
assert_eq('Invalid', $r['status'], 'short payment past the ecx confirmation window -> Invalid');

// ---- the eCash first-method rule on the payment page --------------------------------
assert_eq('ecx', Invoice::firstPaymentMethod([], false, false, false, true), 'eCash-only invoice opens on eCash');
assert_eq('onchain', Invoice::firstPaymentMethod([], true, false, false, true), 'bitcoin stays the default');

OnchainProviderFactory::$testProvider = null;
echo "ok\n";
