<?php
/**
 * Security tests for CLINK payment-receipt verification. The payment page
 * forwards a raw signed event off its live subscription; the server must only
 * settle on a genuine merchant-signed kind-21001 {res:'ok'} receipt for the
 * right request. All trust is the merchant's Schnorr signature — the browser
 * relays but must not be able to forge a "paid".
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/includes/clink/client.php';

use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip44;

$key = new Key();
$mSk = $key->generatePrivateKey();           // merchant (receiver) identity
$mPk = $key->getPublicKey($mSk);
$eSk = $key->generatePrivateKey();           // payer ephemeral identity (ours)
$ePk = $key->getPublicKey($eSk);
$reqId = str_repeat('aa', 32);

$ctx = [
    'relay' => 'wss://unused.test',
    'receiver_pubkey' => $mPk,
    'ephemeral_sk' => $eSk,
    'ephemeral_pubkey' => $ePk,
    'request_event_id' => $reqId,
    'created_at' => 1700000000,
];

/** Build a kind-21001 event from $signerSk addressed to $ePk, body NIP-44 encrypted. */
function mkEvent(string $signerSk, string $ePk, string $reqId, array $body): array {
    $ck = Nip44::getConversationKey($signerSk, $ePk);
    $e = new Event();
    $e->setKind(21001);
    $e->setTags([['p', $ePk], ['e', $reqId]]);
    $e->setContent(Nip44::encrypt(json_encode($body), $ck));
    (new Sign())->signEvent($e, $signerSk);
    return $e->toArray();
}

// ---------- happy path ----------
$ok = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
assert_true(ClinkClient::verifyReceiptEvent($ok, $ctx)['paid'], 'valid merchant receipt → paid');

// ---------- res != ok ----------
$notok = mkEvent($mSk, $ePk, $reqId, ['res' => 'GFY', 'error' => 'nope']);
assert_false(ClinkClient::verifyReceiptEvent($notok, $ctx)['paid'], 'res!=ok → not paid');

// ---------- impostor author (different key signs a {res:ok}) ----------
$xSk = $key->generatePrivateKey();
$impostor = mkEvent($xSk, $ePk, $reqId, ['res' => 'ok']);
assert_false(ClinkClient::verifyReceiptEvent($impostor, $ctx)['paid'], 'wrong author → not paid');

// ---------- wrong request id (e-tag mismatch) ----------
$wrongRef = mkEvent($mSk, $ePk, str_repeat('bb', 32), ['res' => 'ok']);
assert_false(ClinkClient::verifyReceiptEvent($wrongRef, $ctx)['paid'], 'wrong e-tag → not paid');

// ---------- tampered signature ----------
$tampered = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
$tampered['sig'][10] = $tampered['sig'][10] === 'a' ? 'b' : 'a';
assert_false(ClinkClient::verifyReceiptEvent($tampered, $ctx)['paid'], 'tampered sig → not paid');

// ---------- wrong kind ----------
$wrongKind = mkEvent($mSk, $ePk, $reqId, ['res' => 'ok']);
$wrongKind['kind'] = 1;
assert_false(ClinkClient::verifyReceiptEvent($wrongKind, $ctx)['paid'], 'wrong kind → not paid');

echo "test_clink_receipt_verify: ok\n";
