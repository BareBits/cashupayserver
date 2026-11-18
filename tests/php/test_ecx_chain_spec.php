<?php
/**
 * Chain descriptors (includes/onchain/chain.php): the ECX rail's labels, URI
 * scheme, unit spelling and explorer defaults, and that the Bitcoin
 * descriptor reproduces the legacy column names / public explorer chain
 * exactly (the engine reads everything through these maps).
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/onchain/provider.php';

$btc = Chain::btc();
$ecx = Chain::ecx();

// ---- identity -------------------------------------------------------------
assert_eq('btc', $btc->id);
assert_eq('ecx', $ecx->id);
assert_eq('ECX', $ecx->ticker, 'ticker is ECX (not EXC / XEC)');
assert_eq('szat', $ecx->unit);
assert_eq('szats', $ecx->unitPlural);
assert_eq('ecash', $ecx->uriScheme, 'QR / open-in-wallet use the ecash: scheme');
assert_eq('ecx', $ecx->rail);
assert_eq('ECX-OnChain', $ecx->apiMethod);
assert_eq('ecx_payments', $ecx->paymentsTable);
assert_eq('onchain_payments', $btc->paymentsTable);
assert_eq('mainnet', Chain::ECX_NETWORK);
assert_eq(2, count(Chain::all()));
assert_eq($ecx, Chain::get('ecx'));
assert_eq($ecx, Chain::forRail('ecx'));
assert_eq($btc, Chain::forRail('onchain'));
assert_null(Chain::forRail('lnaddress'), 'lightning rails have no chain');
$threw = false;
try { Chain::get('xec'); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, 'unknown chain id is rejected');

// ---- legacy Bitcoin column names are reproduced verbatim --------------------
assert_eq('onchain_address', $btc->inv['address']);
assert_eq('onchain_amount_sat', $btc->inv['amount']);
assert_eq('onchain_first_seen_at', $btc->inv['first_seen']);
assert_eq('onchain_amount_floor_sat', $btc->inv['floor']);
assert_eq('exchange_rate', $btc->inv['rate']);
assert_eq('onchain_xpub', $btc->store['xpub']);
assert_eq('onchain_confirm_timeout_sec', $btc->store['timeout']);
// ECX mirrors every key with its own column.
foreach ($btc->inv as $k => $col) {
    assert_true(isset($ecx->inv[$k]), "ecx inv map has {$k}");
    assert_neq($col, $ecx->inv[$k], "ecx inv column for {$k} differs from bitcoin's");
}
foreach ($btc->store as $k => $col) {
    assert_true(isset($ecx->store[$k]), "ecx store map has {$k}");
}
assert_eq('ecx_amount_szat', $ecx->inv['amount']);
assert_eq('ecx_exchange_rate', $ecx->inv['rate']);

// ---- network: ECX rows carry no network column -> mainnet ------------------
assert_eq('mainnet', $ecx->network(['ecx_xpub' => 'x']));
assert_eq('mainnet', $ecx->network(['ecx_network' => '']));
assert_eq('regtest', $btc->network(['onchain_network' => 'regtest']));
assert_eq('mainnet', $btc->network([]));

// ---- amounts + URIs -------------------------------------------------------
assert_eq('0.5', $ecx->formatCoin(50000000));
assert_eq('1', $ecx->formatCoin(100000000));
assert_eq('0.00000001', $ecx->formatCoin(1));
assert_eq('0', $ecx->formatCoin(0));
assert_eq('0', $ecx->formatCoin(-5), 'negative clamps to 0');
assert_eq('ecash:bc1qaddr?amount=0.5', $ecx->paymentUri('bc1qaddr', 50000000));
assert_eq('bitcoin:bc1qaddr?amount=0.00012345', $btc->paymentUri('bc1qaddr', 12345));
assert_eq('1 szat', $ecx->formatUnits(1));
assert_eq('12,345 szats', $ecx->formatUnits(12345));
assert_eq('2 sats', $btc->formatUnits(2));

// ---- explorers --------------------------------------------------------------
assert_eq(['https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'], $ecx->esploraDefaults('mainnet'));
assert_null($ecx->esploraDefaults('testnet'), 'ECX has one network');
assert_eq(['https://mempool.space/api', 'https://blockstream.info/api'], $btc->esploraDefaults('mainnet'),
    'bitcoin public chain unchanged');
assert_null($btc->esploraDefaults('regtest'));
assert_eq('https://explorer.ecash.com/tx/ab12', $ecx->txUrl('ab12'));
assert_eq('https://explorer.ecash.com/address/bc1q', $ecx->addressUrl('bc1q'));
assert_eq('https://mempool.space/testnet/tx/ab12', $btc->txUrl('ab12', 'testnet'));

// EsploraProvider reads the defaults through the chain; the legacy
// one-argument calls still mean Bitcoin.
assert_eq('https://mempool.space/api', EsploraProvider::defaultUrlForNetwork('mainnet'));
assert_eq('https://explorer.ecash.com/api', EsploraProvider::defaultUrlForNetwork('mainnet', $ecx));
assert_eq(['https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'],
    EsploraProvider::defaultUrlsForNetwork('mainnet', $ecx));
assert_eq(['https://mempool.space/signet/api'], EsploraProvider::defaultUrlsForNetwork('signet'));

// chainForStore with the ECX descriptor reads the ecx_* columns and ignores
// the Bitcoin ones (and vice versa).
$store = [
    'onchain_provider_url' => 'https://btc.example/api', 'onchain_provider_fallback' => 0,
    'ecx_provider_url' => 'https://ecx.example/api', 'ecx_provider_fallback' => 1,
];
assert_eq(['https://btc.example/api'], EsploraProvider::chainForStore($store));
assert_eq(['https://ecx.example/api', 'https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'],
    EsploraProvider::chainForStore($store, null, $ecx), 'ecx custom URL + opted-in public fallback');
assert_eq(['https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'],
    EsploraProvider::chainForStore(['ecx_xpub' => 'x'], null, $ecx), 'no custom URL -> public ECX chain');
// A bitcoind-rpc Bitcoin store still gets an Esplora chain for ECX.
$prov = OnchainProviderFactory::forStore(['onchain_provider' => 'bitcoind-rpc', 'onchain_provider_url' => 'http://u:p@127.0.0.1:1/'], $ecx);
assert_true($prov instanceof EsploraProvider, 'ECX is Esplora-only even when bitcoin uses bitcoind');

// The ECX override env is separate from the Bitcoin one and gated on the
// private-endpoints opt-in like it.
putenv('CASHUPAY_ECX_ESPLORA_DEFAULT_URLS=http://127.0.0.1:9/ecx');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
Config::set('allow_private_endpoints', null);
assert_eq(['http://127.0.0.1:9/ecx'], EsploraProvider::defaultUrlsForNetwork('mainnet', $ecx), 'ecx override honoured');
assert_eq(['https://mempool.space/api', 'https://blockstream.info/api'], EsploraProvider::defaultUrlsForNetwork('mainnet'),
    'the ECX override does not touch the bitcoin chain');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=0');
assert_eq(['https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'],
    EsploraProvider::defaultUrlsForNetwork('mainnet', $ecx), 'override ignored without the private-endpoints opt-in');
putenv('CASHUPAY_ECX_ESPLORA_DEFAULT_URLS');
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS');

echo "ok\n";
