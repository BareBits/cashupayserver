<?php
/**
 * Configurable payment-rail ordering (RailOrder + StoreLnAddresses):
 *
 *   1. Lenient read-side normalization: NULL/empty → default order; unknown
 *      types dropped; duplicates collapsed; missing types appended in
 *      default order; case-folded. A CSV written before the swap and the
 *      Cashu mint became orderable (four types) gets them appended last —
 *      the historical behavior.
 *   2. Strict write-side parsing: only a full permutation of the six paths
 *      is accepted (plus the exact legacy four-type shape, which gets
 *      swap,cashu appended); anything else throws.
 *   3. Persistence: setLnOrder / setOnchainOrder round-trip through the
 *      stores columns; lnOrderForStore / onchainOrderForStore read them.
 *   4. listForStore: with NO explicit order the stored per-row order is
 *      returned verbatim (historical behavior — a legacy interleaved chain
 *      stays interleaved); with an explicit order the chain is
 *      stable-sorted by type, entries of the same type keeping their
 *      stored relative priority.
 *   5. primaryForStore follows the configured order.
 *   6. The 'swap' and 'cashu' paths are order-only: destinationsForStore
 *      never yields them (so the auto-cashout melt chain can't target the
 *      mint), lnDestinationTypes drops them, cashuPrecedesSwap reads the
 *      order, and Invoice::lightningWalkSteps places them as steps.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/rail_order.php';
require_once dirname(__DIR__, 2) . '/app/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/app/includes/clink/noffer.php';
require_once dirname(__DIR__, 2) . '/app/includes/invoice.php';

$ALL = ['strike', 'lnaddress', 'nwc', 'noffer', 'swap', 'cashu'];

$store = 'store_rail_order';
make_store($store);

// ---------- 1. lenient normalization ----------
assert_eq($ALL, RailOrder::LN_DEFAULT, 'default: destinations, then swap, then the mint');
assert_eq($ALL, RailOrder::lnOrderFromRow(null), 'missing row → default order');
assert_eq($ALL, RailOrder::lnOrderFromRow(['ln_rail_order' => null]), 'NULL → default order');
assert_eq($ALL, RailOrder::lnOrderFromRow(['ln_rail_order' => '']), 'empty → default order');
assert_eq(['cashu', 'noffer', 'nwc', 'swap', 'lnaddress', 'strike'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'cashu,noffer,nwc,swap,lnaddress,strike']),
    'full permutation honored');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike', 'swap', 'cashu'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'noffer,nwc,lnaddress,strike']),
    'pre-swap/cashu four-type CSV → swap and mint appended last (historical behavior)');
assert_eq(['nwc', 'strike', 'lnaddress', 'noffer', 'swap', 'cashu'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'nwc']),
    'partial value → missing types appended in default order');
assert_eq(['noffer', 'strike', 'lnaddress', 'nwc', 'swap', 'cashu'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'noffer,bogus,noffer, ,']),
    'unknowns dropped, duplicates collapsed, blanks skipped');
assert_eq(['cashu', 'noffer', 'nwc', 'lnaddress', 'strike', 'swap'],
    RailOrder::lnOrderFromRow(['ln_rail_order' => 'CASHU,NOFFER, Nwc ,LNADDRESS,Strike']),
    'case-folded and trimmed');
assert_eq(['strike', 'local'], RailOrder::onchainOrderFromRow(null),
    'on-chain default order');
assert_eq(['local', 'strike'], RailOrder::onchainOrderFromRow(['onchain_source_order' => 'local,strike']),
    'on-chain order honored');
assert_eq(['local', 'strike'], RailOrder::onchainOrderFromRow(['onchain_source_order' => 'local']),
    'on-chain partial → missing appended');

// ---------- 2. strict write-side parsing ----------
assert_eq(['cashu', 'noffer', 'strike', 'swap', 'lnaddress', 'nwc'],
    RailOrder::parseLnOrder('cashu,noffer,strike,swap,lnaddress,nwc'), 'valid permutation parses');
assert_eq(['noffer', 'strike', 'lnaddress', 'nwc', 'swap', 'cashu'],
    RailOrder::parseLnOrder('noffer,strike,lnaddress,nwc'),
    'legacy four-type shape accepted: swap,cashu appended (older client)');
foreach ([
    'strike,lnaddress,nwc',                                // missing types
    'strike,lnaddress,nwc,noffer,swap',                    // missing cashu (not the legacy shape)
    'strike,lnaddress,nwc,noffer,cashu',                   // missing swap
    'strike,lnaddress,nwc,noffer,swap,cashu,strike',       // duplicate
    'strike,lnaddress,nwc,noffer,swap,bogus',              // unknown
    'strike,lnaddress,nwc,bogus',                          // legacy shape with an unknown
    '',                                                    // empty
    'strike,lnaddress,nwc,noffer,swap,cashu,extra',        // extra entry
] as $bad) {
    $threw = false;
    try {
        RailOrder::parseLnOrder($bad);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, "strict parse rejects '{$bad}'");
}
$threw = false;
try {
    RailOrder::parseOnchainOrder('strike');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'strict on-chain parse rejects a partial order');
assert_eq(['local', 'strike'], RailOrder::parseOnchainOrder('local,strike'), 'valid on-chain order parses');

// ---------- 3. persistence round-trip ----------
assert_null(RailOrder::storedLnOrderCsv($store), 'fresh store has no explicit order');
RailOrder::setLnOrder($store, 'cashu,noffer,nwc,lnaddress,swap,strike');
assert_eq('cashu,noffer,nwc,lnaddress,swap,strike', RailOrder::storedLnOrderCsv($store), 'CSV persisted');
assert_eq(['cashu', 'noffer', 'nwc', 'lnaddress', 'swap', 'strike'], RailOrder::lnOrderForStore($store), 'order read back');
$threw = false;
try {
    RailOrder::setLnOrder($store, 'strike,strike,nwc,noffer,swap,cashu');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, 'setLnOrder rejects a non-permutation');
assert_eq('cashu,noffer,nwc,lnaddress,swap,strike', RailOrder::storedLnOrderCsv($store),
    'rejected write leaves the stored order untouched');
RailOrder::setLnOrder($store, 'noffer,nwc,lnaddress,strike');
assert_eq('noffer,nwc,lnaddress,strike,swap,cashu', RailOrder::storedLnOrderCsv($store),
    'legacy four-type write is stored with swap,cashu appended');

RailOrder::setOnchainOrder($store, 'local,strike');
assert_eq(['local', 'strike'], RailOrder::onchainOrderForStore($store), 'on-chain order persisted');
RailOrder::setLnOrder($store, 'strike,lnaddress,nwc,noffer,swap,cashu'); // back to default for section 4

// ---------- 4. listForStore ordering ----------
$noffer = ClinkNoffer::encode([
    'pubkey' => str_repeat('ab', 32),
    'relay' => 'wss://relay.test',
    'offer' => 'shop',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$nwcUri = 'nostr+walletconnect://' . str_repeat('11', 32)
    . '?relay=ws%3A%2F%2F127.0.0.1%3A7777&secret=' . str_repeat('22', 32);
$strikeKey = 'RAILORDERKEY0' . str_repeat('A', 27);

// A deliberately interleaved chain (only reachable through direct
// replaceForStore / the legacy shape-classified API): noffer first, then two
// addresses, strike, nwc, second noffer.
$noffer2 = ClinkNoffer::encode([
    'pubkey' => str_repeat('cd', 32),
    'relay' => 'wss://relay2.test',
    'offer' => 'shop2',
    'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
]);
$interleaved = [
    ['type' => 'noffer', 'address' => $noffer],
    ['type' => 'lnaddress', 'address' => 'first@wallet.test', 'supports_verify' => 1],
    ['type' => 'strike', 'address' => $strikeKey],
    ['type' => 'lnaddress', 'address' => 'second@wallet.test', 'supports_verify' => 1],
    ['type' => 'nwc', 'address' => $nwcUri],
    ['type' => 'noffer', 'address' => $noffer2],
];

$storeLegacy = 'store_rail_legacy';
make_store($storeLegacy);
StoreLnAddresses::replaceForStore($storeLegacy, $interleaved);
// No explicit order → stored order verbatim, interleaving preserved.
assert_eq(['noffer', 'lnaddress', 'strike', 'lnaddress', 'nwc', 'noffer'],
    array_map(fn($d) => $d['type'], StoreLnAddresses::destinationsForStore($storeLegacy)),
    'NULL order keeps the stored interleaving (historical behavior)');

// Explicit DEFAULT order → grouped strike-first, within-type order kept.
RailOrder::setLnOrder($storeLegacy, 'strike,lnaddress,nwc,noffer,swap,cashu');
$d = StoreLnAddresses::destinationsForStore($storeLegacy);
assert_eq(['strike', 'lnaddress', 'lnaddress', 'nwc', 'noffer', 'noffer'],
    array_map(fn($x) => $x['type'], $d), 'explicit default order groups by type');
assert_eq('first@wallet.test', $d[1]['value'], 'within-type order preserved (first address)');
assert_eq('second@wallet.test', $d[2]['value'], 'within-type order preserved (second address)');
assert_eq($noffer, $d[4]['value'], 'within-type order preserved (first noffer)');
assert_eq($noffer2, $d[5]['value'], 'within-type order preserved (second noffer)');

// Reversed order → noffers lead, strike last.
RailOrder::setLnOrder($storeLegacy, 'noffer,nwc,lnaddress,strike,swap,cashu');
$d = StoreLnAddresses::destinationsForStore($storeLegacy);
assert_eq(['noffer', 'noffer', 'nwc', 'lnaddress', 'lnaddress', 'strike'],
    array_map(fn($x) => $x['type'], $d), 'reversed order re-sorts the chain');
assert_eq($noffer, $d[0]['value'], 'noffer relative order kept after re-sort');

// ---------- 5. primaryForStore follows the configured order ----------
assert_eq($noffer, StoreLnAddresses::primaryForStore($storeLegacy),
    'primary is the first destination of the configured order');
RailOrder::setLnOrder($storeLegacy, 'lnaddress,strike,nwc,noffer,swap,cashu');
assert_eq('first@wallet.test', StoreLnAddresses::primaryForStore($storeLegacy),
    'primary follows an order change');

// ---------- 6. swap / cashu are order-only paths ----------
// Cashu first, swap second: the destination chain (what the auto-cashout
// melt walks) still holds destinations only — the mint can never be a melt
// target, and the destination types keep their configured relative order.
RailOrder::setLnOrder($storeLegacy, 'cashu,swap,noffer,nwc,lnaddress,strike');
$d = StoreLnAddresses::destinationsForStore($storeLegacy);
assert_eq(['noffer', 'noffer', 'nwc', 'lnaddress', 'lnaddress', 'strike'],
    array_map(fn($x) => $x['type'], $d),
    'destination chain never contains swap/cashu, destination order honored');
foreach ($d as $dest) {
    assert_true(in_array($dest['type'], RailOrder::LN_DESTINATION_TYPES, true),
        'melt-chain destination type is a payout destination: ' . $dest['type']);
}
assert_eq($noffer, StoreLnAddresses::primaryForStore($storeLegacy),
    'primary is the first DESTINATION even when the mint leads the order');
assert_eq(['noffer', 'nwc', 'lnaddress', 'strike'],
    RailOrder::lnDestinationTypes(['cashu', 'swap', 'noffer', 'nwc', 'lnaddress', 'strike']),
    'lnDestinationTypes drops the order-only slots');

assert_false(RailOrder::cashuPrecedesSwap(RailOrder::LN_DEFAULT), 'default: swap before mint');
assert_true(RailOrder::cashuPrecedesSwap(['cashu', 'swap', 'noffer', 'nwc', 'lnaddress', 'strike']),
    'cashu ahead of swap detected');
assert_true(RailOrder::cashuPrecedesSwap(['strike', 'lnaddress', 'nwc', 'noffer', 'cashu', 'swap']),
    'cashu just ahead of a trailing swap detected');
assert_false(RailOrder::cashuPrecedesSwap(['swap', 'cashu', 'strike', 'lnaddress', 'nwc', 'noffer']),
    'swap ahead of cashu (both moved up) is not "cashu first"');
assert_false(RailOrder::cashuPrecedesSwap(['strike', 'lnaddress']), 'missing slots → false');

// Walk steps for Invoice::create / refreshLightning.
$dests = [
    ['type' => 'strike', 'value' => 'k1'],
    ['type' => 'lnaddress', 'value' => 'a1'],
    ['type' => 'lnaddress', 'value' => 'a2'],
    ['type' => 'nwc', 'value' => 'n1'],
];
$kinds = fn(array $steps) => array_map(
    fn($st) => $st['kind'] === 'direct' ? 'direct:' . implode('+', array_column($st['destinations'], 'value')) : $st['kind'],
    $steps
);
assert_eq(['direct:k1+a1+a2+n1', 'swap', 'mint'],
    $kinds(Invoice::lightningWalkSteps(RailOrder::LN_DEFAULT, $dests, false)),
    'NULL (legacy) order: whole chain verbatim, then swap, then mint — the historical walk');
assert_eq(['direct:k1', 'direct:a1+a2', 'direct:n1', 'swap', 'mint'],
    $kinds(Invoice::lightningWalkSteps(RailOrder::LN_DEFAULT, $dests, true)),
    'explicit default order: per-type groups, swap, mint');
assert_eq(['mint', 'direct:k1', 'swap', 'direct:a1+a2', 'direct:n1'],
    $kinds(Invoice::lightningWalkSteps(['cashu', 'strike', 'swap', 'lnaddress', 'noffer', 'nwc'], $dests, true)),
    'mint and swap sit at their configured slots; empty types (noffer) emit no step');
assert_eq(['mint', 'swap'],
    $kinds(Invoice::lightningWalkSteps(['cashu', 'strike', 'swap', 'lnaddress', 'noffer', 'nwc'], [], true)),
    'no destinations: only the order-only steps');
assert_eq(['swap', 'mint'],
    $kinds(Invoice::lightningWalkSteps(RailOrder::LN_DEFAULT, [], false)),
    'legacy order without destinations: swap, mint');

echo "test_rail_order: ok\n";
