<?php
/**
 * BoltzLikeProvider response-shape hardening. The swap provider is a third
 * party: every field we persist, splice into a URL, hand to the claimer or
 * show the payer must have the shape its role implies, not merely be present.
 *
 *   createReverseSwap: id token, BOLT11-shaped invoice, address-shaped
 *                      lockupAddress, 33-byte refund key, positive integer
 *                      timeout height, integer invoiceAmount.
 *   getSwapStatus:     status token, hex lockup tx (malformed -> throw),
 *                      32-byte hex preimage (malformed -> dropped).
 *   broadcastTx:       64-hex txid or throw.
 *   HTTP errors:       body excerpts in exception text are sanitized.
 *
 * Network-free: a subclass scripts httpGet()/httpPostJson(). The pair-info
 * parser (fees/limits) is deliberately NOT changed by this hardening.
 */
declare(strict_types=1);
require __DIR__ . '/../php/harness.php';
require_once __DIR__ . '/../../app/includes/swap/boltz_like_provider.php';

final class ScriptedBoltz extends BoltzLikeProvider {
    public array $nextPost = [];
    public array $nextGet = [];
    public function getName(): string { return 'scripted'; }
    public function baseUrl(string $network): ?string { return 'http://scripted.invalid'; }
    protected function httpGet(string $network, string $path): array { return $this->nextGet; }
    protected function httpPostJson(string $network, string $path, array $body): array { return $this->nextPost; }
}

function expect_throw(callable $fn, string $msg): string {
    try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); }
    fail($msg . ' (did not throw)');
    return '';
}

$leaf = '20' . str_repeat('ab', 32) . 'ac';
$good = [
    'id' => 'AbC123xy',
    'invoice' => 'lnbc250000n1' . str_repeat('q', 40),
    'lockupAddress' => 'bc1pexamplelockupaddressexamplelockupaddr0',
    'refundPublicKey' => '02' . str_repeat('cd', 32),
    'timeoutBlockHeight' => 850000,
    'invoiceAmount' => 25000,
    'swapTree' => ['claimLeaf' => ['output' => $leaf], 'refundLeaf' => ['output' => $leaf]],
];
$p = new ScriptedBoltz();
$claimPk = '03' . str_repeat('ef', 32);
$preHash = str_repeat('12', 32);

// ---- happy path ------------------------------------------------------------------
$p->nextPost = $good;
$r = $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash);
assert_eq('AbC123xy', $r->swapId, 'id kept');
assert_eq($good['invoice'], $r->invoice, 'invoice kept');
assert_eq(25000, $r->invoiceAmountSats, 'invoiceAmount kept');
assert_eq(850000, $r->timeoutBlockHeight, 'timeout kept');
assert_eq('02' . str_repeat('cd', 32), $r->refundPublicKeyHex, 'refund key kept');
// invoiceAmount absent -> decoded from the BOLT11 (250000n = 25000 sat).
$p->nextPost = array_diff_key($good, ['invoiceAmount' => 1]);
assert_eq(25000, $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash)->invoiceAmountSats, 'amount decoded from bolt11 when absent');
// Uppercase refund key is normalised, uppercase bolt11 accepted.
$p->nextPost = $good + [];
$p->nextPost['refundPublicKey'] = strtoupper($good['refundPublicKey']);
$p->nextPost['invoice'] = strtoupper($good['invoice']);
$r = $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash);
assert_eq($good['refundPublicKey'], $r->refundPublicKeyHex, 'refund key lowercased');
assert_eq(strtoupper($good['invoice']), $r->invoice, 'uppercase bolt11 accepted verbatim');
// Surrounding whitespace is trimmed off the stored invoice (a trailing
// newline would otherwise end up inside the QR / lightning: link).
$p->nextPost = $good;
$p->nextPost['invoice'] = " " . $good['invoice'] . "\n";
assert_eq($good['invoice'], $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash)->invoice, 'invoice whitespace trimmed');

// ---- each field's malformed shapes ------------------------------------------------
$cases = [
    ['id', "swap\x1b[31m", 'swap id'],
    ['id', 'has space', 'swap id'],
    ['id', str_repeat('a', 65), 'swap id'],
    ['id', ['x'], 'swap id'],
    ['invoice', 'lnbc1mockinvoice', 'BOLT11'],
    ['invoice', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', 'BOLT11'],
    ['invoice', ['lnbc1qqqqqq'], 'BOLT11'],
    ['lockupAddress', 'bitcoin:bc1qexample', 'address'],
    ['lockupAddress', 'short', 'address'],
    ['lockupAddress', "bc1q\x00" . str_repeat('q', 30), 'address'],
    ['refundPublicKey', '02' . str_repeat('cd', 31), '33-byte'],
    ['refundPublicKey', 'zz' . str_repeat('cd', 32), '33-byte'],
    ['timeoutBlockHeight', 0, 'timeoutBlockHeight'],
    ['timeoutBlockHeight', -5, 'timeoutBlockHeight'],
    ['timeoutBlockHeight', '850000.5', 'timeoutBlockHeight'],
    ['timeoutBlockHeight', [850000], 'timeoutBlockHeight'],
    ['invoiceAmount', '1e4', 'invoiceAmount'],
    ['invoiceAmount', -1, 'invoiceAmount'],
    ['invoiceAmount', [25000], 'invoiceAmount'],
];
foreach ($cases as [$field, $value, $needle]) {
    $p->nextPost = $good;
    $p->nextPost[$field] = $value;
    $msg = expect_throw(fn() => $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash), "{$field}=" . json_encode($value) . ' refused');
    assert_true(str_contains($msg, $needle), "{$field}: message names the field ({$msg})");
    assert_false(str_contains($msg, "\x1b") || str_contains($msg, "\n"), "{$field}: no raw bytes in message");
}
// Missing required fields still throw with the existing message.
$p->nextPost = array_diff_key($good, ['lockupAddress' => 1]);
assert_true(str_contains(expect_throw(fn() => $p->createReverseSwap('mainnet', 24000, $claimPk, $preHash), 'missing field'), 'missing lockupAddress'), 'missing-field message intact');

// ---- getSwapStatus ------------------------------------------------------------------
$txHex = '0200000001' . str_repeat('ab', 40);
$p->nextGet = ['status' => 'transaction.mempool', 'transaction' => ['hex' => $txHex]];
$st = $p->getSwapStatus('mainnet', 'AbC123xy');
assert_eq('transaction.mempool', $st->status, 'status kept');
assert_eq($txHex, $st->lockupTxHex, 'lockup hex kept');
assert_null($st->preimage, 'no preimage');
$p->nextGet = ['status' => 'invoice.settled', 'preimage' => strtoupper(str_repeat('ab', 32))];
$st = $p->getSwapStatus('mainnet', 'AbC123xy');
assert_eq(str_repeat('ab', 32), $st->preimage, 'preimage normalised to lowercase');
$p->nextGet = ['status' => 'invoice.settled', 'preimage' => 'abcd'];
assert_null($p->getSwapStatus('mainnet', 'x')->preimage, 'short preimage dropped');
$p->nextGet = ['status' => 'invoice.settled', 'preimage' => ['ab']];
assert_null($p->getSwapStatus('mainnet', 'x')->preimage, 'array preimage dropped');
$p->nextGet = ['status' => "weird\x1b[31m status", 'transaction' => []];
assert_eq('', $p->getSwapStatus('mainnet', 'x')->status, 'unprintable status collapses to unknown');
$p->nextGet = ['status' => ['transaction.mempool']];
assert_eq('', $p->getSwapStatus('mainnet', 'x')->status, 'array status collapses to unknown');
$p->nextGet = ['status' => 'transaction.mempool', 'transaction' => ['hex' => 'not hex']];
expect_throw(fn() => $p->getSwapStatus('mainnet', 'x'), 'malformed lockup hex throws');
$p->nextGet = ['status' => 'transaction.mempool', 'transaction' => ['hex' => 'abc']];
expect_throw(fn() => $p->getSwapStatus('mainnet', 'x'), 'odd-length lockup hex throws');
$p->nextGet = ['status' => 'transaction.mempool', 'transaction' => ['hex' => [$txHex]]];
expect_throw(fn() => $p->getSwapStatus('mainnet', 'x'), 'array lockup hex throws');
$p->nextGet = ['status' => 'swap.created', 'transaction' => null];
assert_null($p->getSwapStatus('mainnet', 'x')->lockupTxHex, 'null transaction is simply absent');

// ---- broadcastTx ----------------------------------------------------------------------
$p->nextPost = ['id' => strtoupper(str_repeat('ab', 32))];
assert_eq(str_repeat('ab', 32), $p->broadcastTx('mainnet', $txHex), 'txid lowercased');
foreach ([['id' => 'abc'], ['id' => str_repeat('zz', 32)], ['id' => [str_repeat('ab', 32)]], ['txid' => str_repeat('ab', 32)], []] as $resp) {
    $p->nextPost = $resp;
    expect_throw(fn() => $p->broadcastTx('mainnet', $txHex), 'non-txid broadcast response ' . json_encode($resp) . ' throws');
}

// ---- HTTP-layer excerpt sanitizing (real httpGet/httpPostJson, dead host) --------------
final class DeadHostBoltz extends BoltzLikeProvider {
    public function getName(): string { return 'dead'; }
    public function baseUrl(string $network): ?string { return 'http://127.0.0.1:1'; }
    public function get(string $path): array { return $this->httpGet('mainnet', $path); }
}
putenv('CASHUPAY_ALLOW_PRIVATE_ENDPOINTS=1');
$msg = expect_throw(fn() => (new DeadHostBoltz())->get('/v2/swap/reverse'), 'dead host throws');
assert_true(str_contains($msg, 'GET /v2/swap/reverse failed'), 'transport failure message');

echo "test_boltz_response_shape: all assertions passed\n";
