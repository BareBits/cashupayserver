<?php
/**
 * Tests for the store-name memo cashupayserver attaches to noffer-issued
 * Lightning invoices.
 *
 * Two layers:
 *   1. Unit — Invoice::nofferMemo() composes "<store name> - <itemDesc>",
 *      caps at the CLINK spec's 100-char description limit (mb-safe), and
 *      degrades gracefully when pieces are missing.
 *   2. Integration — Invoice::create() on the noffer rail actually SENDS that
 *      memo as the NIP-69 `description` in the encrypted kind-21001 request.
 *      Driven against the mock relay (which decrypts the request with the
 *      merchant key and dumps the plaintext payload for inspection).
 *
 * The receiving wallet may honor or ignore the description per NIP-69; this
 * suite asserts only what cashupayserver controls — that a spec-compliant
 * best-effort memo is sent. The wallet-side honoring is a separate concern
 * (electrum_clink plugin) covered in that repo.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
fresh_db();
require_once dirname(__DIR__, 2) . '/includes/clink/client.php';
require_once dirname(__DIR__, 2) . '/includes/store_ln_addresses.php';
require_once dirname(__DIR__, 2) . '/includes/dev_fee.php';
require_once dirname(__DIR__, 2) . '/includes/invoice.php';

use swentel\nostr\Key\Key;

// ---------------------------------------------------------------------------
// Unit: Invoice::nofferMemo
// ---------------------------------------------------------------------------
assert_eq('Acme Coffee', Invoice::nofferMemo(['name' => 'Acme Coffee'], null),
    'store name only when no metadata');
assert_eq('Acme Coffee', Invoice::nofferMemo(['name' => 'Acme Coffee'], ['itemDesc' => '']),
    'empty itemDesc ignored');
assert_eq('Acme Coffee - 2x Latte', Invoice::nofferMemo(['name' => 'Acme Coffee'], ['itemDesc' => '2x Latte']),
    'store name + itemDesc joined');
assert_eq('2x Latte', Invoice::nofferMemo(['name' => ''], ['itemDesc' => '2x Latte']),
    'note alone when store name blank');
assert_eq('', Invoice::nofferMemo([], null),
    'empty string when nothing to say (so caller can omit the field)');
assert_eq('', Invoice::nofferMemo(['name' => '   '], ['itemDesc' => "  \t "]),
    'whitespace-only pieces trimmed away');

// Cap at 100 chars, mb-safe (multibyte names must not be cut mid-character).
$longName = str_repeat('é', 120);
$memo = Invoice::nofferMemo(['name' => $longName], null);
assert_true(mb_strlen($memo) <= 100, 'memo capped at 100 chars');
assert_eq(str_repeat('é', 100), $memo, 'multibyte truncation keeps whole characters');

// ---------------------------------------------------------------------------
// Integration: Invoice::create sends the memo as the NIP-69 description
// ---------------------------------------------------------------------------

/** Start the mock relay/merchant; returns [proc, pipes, port]. */
function start_relay(array $env): array {
    static $seq = 0;
    $base = 26000 + (getmypid() % 2000) + (($seq++) * 13);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $port = $base + $attempt;
        $full = array_merge($env, ['MOCK_CLINK_PORT' => (string)$port, 'PATH' => getenv('PATH')]);
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/mock_clink_relay.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $full
        );
        if (!is_resource($proc)) { continue; }
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) { fclose($c); return [$proc, $pipes, $port]; }
            usleep(100000);
        }
        proc_terminate($proc);
    }
    fail('mock relay failed to start on any port');
}

function stop_relay($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); }
}

/** Poll for the relay's request dump, returning the decoded payload or null. */
function wait_for_dump(string $path, float $timeoutSec = 5.0): ?array {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (is_file($path) && filesize($path) > 0) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        usleep(100000);
    }
    return null;
}

$key = new Key();
$mSk = $key->generatePrivateKey();
$mPk = $key->getPublicKey($mSk);

$dumpFile = sys_get_temp_dir() . '/clink_req_dump_' . bin2hex(random_bytes(4)) . '.json';
[$proc, $pipes, $port] = start_relay([
    'MOCK_CLINK_MERCHANT_SK' => $mSk,
    'MOCK_CLINK_BOLT11' => 'lnbc100n1mockinvoice0000000',
    'MOCK_CLINK_DUMP' => $dumpFile,
]);
try {
    $noffer = ClinkNoffer::encode([
        'pubkey' => $mPk,
        'relay' => "ws://127.0.0.1:$port",
        'offer' => 'shop',
        'price_type' => ClinkNoffer::PRICE_SPONTANEOUS,
    ]);

    // Stub mint so the store is "lightning capable"; the noffer rail is tried
    // first and wins, so the non-routable mint is never contacted.
    $store = 'store_noffer_memo';
    make_store($store, 'http://127.0.0.1:1');
    Database::update('stores', ['name' => 'Acme Coffee'], 'id = ?', [$store]);
    StoreLnAddresses::replaceForStore($store, [['type' => 'noffer', 'address' => $noffer]]);

    $inv = Invoice::create($store, [
        'amount' => 1000,
        'currency' => 'sat',
        'metadata' => ['itemDesc' => '2x Latte'],
    ]);
    assert_eq('noffer', $inv['payment_rail'], 'invoice rides the noffer rail');
    assert_eq('lnbc100n1mockinvoice0000000', $inv['bolt11'], 'mock bolt11 stored on the invoice');

    $payload = wait_for_dump($dumpFile);
    assert_not_null($payload, 'relay dumped the decrypted request payload');
    assert_eq('Acme Coffee - 2x Latte', $payload['description'] ?? null,
        'store-name memo sent as the NIP-69 description');
    assert_eq('shop', $payload['offer'] ?? null, 'offer id sent in the request');
    assert_eq(1000, $payload['amount_sats'] ?? null, 'amount_sats sent in the request');
} finally {
    stop_relay($proc);
    @unlink($dumpFile);
}

echo "test_clink_noffer_memo: OK\n";
