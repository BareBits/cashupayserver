<?php
/**
 * CashuPayServer — CLINK noffer client (NIP-69 over Nostr)
 *
 * Implements the *payer* side of CLINK: given a merchant's `noffer` string we
 * ask their Nostr service for a BOLT11 invoice, and later confirm the customer
 * paid by reading the merchant's kind-21001 payment receipt.
 *
 * Two distinct jobs, both pure-PHP (no exec, no ext-sodium) on top of
 * swentel/nostr-php (NIP-44 + Schnorr signing + a pure-PHP websocket client):
 *
 *   requestInvoice()  — used at invoice creation (receive) and at cashout
 *                       (withdraw). Generates a throwaway identity, sends an
 *                       encrypted kind-21001 request to the offer's relay, and
 *                       returns the BOLT11 the merchant service mints back.
 *
 *   fetchReceipt()    — used by cron (best-effort) and verifyReceiptEvent() by
 *                       the payment page (primary). Confirms settlement via the
 *                       merchant's kind-21001 `{res:'ok'}` receipt, validated by
 *                       the merchant's signature.
 *
 * Why ephemeral keys: CLINK requests carry no long-lived identity, and the
 * receipt is NIP-44-encrypted to the key that made the request. We persist the
 * throwaway secret on the invoice so both the page and cron can decrypt later.
 *
 * Receipt-retention caveat: kind 21001 is in Nostr's ephemeral range
 * (20000–29999); relays are "not expected to store" these (NIP-01). The payment
 * page holds a live subscription and is the reliable path; cron re-subscribing
 * later is best-effort and only works on relays that retain ephemeral events.
 */

declare(strict_types=1);

require_once __DIR__ . '/noffer.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use swentel\nostr\Key\Key;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Encryption\Nip44;

/** Structured CLINK failure carrying a NIP-69 error code where known. */
class ClinkException extends \RuntimeException
{
    /** NIP-69 codes: 1 invalid offer, 2 temp fail, 3 expired, 4 unsupported, 5 invalid amount. 0 = local/transport. */
    public int $clinkCode;

    public function __construct(string $message, int $clinkCode = 0)
    {
        parent::__construct($message);
        $this->clinkCode = $clinkCode;
    }
}

class ClinkClient
{
    public const KIND = 21001;

    // Total wall-clock budget for a request→reply round trip. Relays + the
    // merchant's invoice generation are the slow parts; keep it snappy enough
    // not to wedge invoice creation but tolerant of a sluggish relay. Override
    // in user_config.php.
    public const DEFAULT_TIMEOUT_SEC = 10;

    /**
     * Ask a noffer's service for a BOLT11 invoice.
     *
     * @param string   $noffer     the noffer1… code
     * @param int|null $amountSats amount to request (required for spontaneous
     *                             offers; merchants pin it for fixed/variable)
     * @param string|null $description optional payer note (<=100 chars per spec)
     * @param int|null $timeoutSec  override DEFAULT_TIMEOUT_SEC
     *
     * @return array{
     *   bolt11:string, relay:string, receiver_pubkey:string, offer:string,
     *   ephemeral_sk:string, ephemeral_pubkey:string, request_event_id:string,
     *   created_at:int
     * }
     * @throws ClinkException on decode/transport/protocol error.
     */
    public static function requestInvoice(
        string $noffer,
        ?int $amountSats = null,
        ?string $description = null,
        ?int $timeoutSec = null
    ): array {
        $decoded = ClinkNoffer::decode($noffer); // throws on malformed
        $timeout = $timeoutSec ?? self::timeoutSec();

        if ($description !== null && mb_strlen($description) > 100) {
            $description = mb_substr($description, 0, 100);
        }

        $key = new Key();
        $skHex = $key->generatePrivateKey();
        $pkHex = $key->getPublicKey($skHex);
        $receiverPubkey = $decoded['pubkey'];

        // Encrypted request payload. amount is conditional: the spec carries it
        // for spontaneous offers and ignores it for fixed/variable, so we only
        // include it when we have one.
        $payload = ['offer' => $decoded['offer']];
        if ($amountSats !== null && $amountSats > 0) {
            // The reference SDK sends amount in sats under `amount_sats`; some
            // services also read `amount`. Send both for compatibility.
            $payload['amount_sats'] = $amountSats;
            $payload['amount'] = $amountSats;
        }
        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        $convKey = Nip44::getConversationKey($skHex, $receiverPubkey);
        $content = Nip44::encrypt(json_encode($payload), $convKey);

        $event = new Event();
        $event->setKind(self::KIND);
        $event->setTags([['p', $receiverPubkey], ['clink_version', '1']]);
        $event->setContent($content);
        (new Sign())->signEvent($event, $skHex);
        $signed = $event->toArray();
        $requestId = (string)$signed['id'];
        $createdAt = (int)$signed['created_at'];

        // Subscribe-before-publish on one socket: the reply is an ephemeral
        // event the service emits *after* seeing our request, so the
        // subscription must already be live or we'd miss it.
        $reply = self::roundTrip($decoded['relay'], $signed, $pkHex, $requestId, $createdAt, $timeout, $convKey);
        if ($reply === null) {
            throw new ClinkException('No response from noffer service within timeout', 2);
        }

        if (isset($reply['error']) || isset($reply['code'])) {
            $code = isset($reply['code']) ? (int)$reply['code'] : 0;
            $msg = isset($reply['error']) ? (string)$reply['error'] : 'CLINK service error';
            if ($code === 5 && isset($reply['range']) && is_array($reply['range'])) {
                $min = $reply['range']['min'] ?? '?';
                $max = $reply['range']['max'] ?? '?';
                $msg .= " (acceptable range: {$min}–{$max} sats)";
            }
            throw new ClinkException($msg, $code);
        }
        if (!isset($reply['bolt11']) || !is_string($reply['bolt11']) || $reply['bolt11'] === '') {
            throw new ClinkException('CLINK service returned no bolt11', 2);
        }

        return [
            'bolt11' => (string)$reply['bolt11'],
            'relay' => $decoded['relay'],
            'receiver_pubkey' => $receiverPubkey,
            'offer' => $decoded['offer'],
            'ephemeral_sk' => $skHex,
            'ephemeral_pubkey' => $pkHex,
            'request_event_id' => $requestId,
            'created_at' => $createdAt,
        ];
    }

    /**
     * Best-effort cron path: re-subscribe to the relay and look for a payment
     * receipt referencing our original request. Returns ['paid'=>bool] —
     * paid=true only when a merchant-signed receipt with {res:'ok'} is found.
     *
     * @param array{relay:string,receiver_pubkey:string,ephemeral_sk:string,
     *              ephemeral_pubkey:string,request_event_id:string,created_at:int} $ctx
     */
    public static function fetchReceipt(array $ctx, ?int $timeoutSec = null): array
    {
        $timeout = $timeoutSec ?? self::timeoutSec();
        $convKey = Nip44::getConversationKey($ctx['ephemeral_sk'], $ctx['receiver_pubkey']);
        $events = self::collectEvents(
            $ctx['relay'],
            $ctx['ephemeral_pubkey'],
            $ctx['request_event_id'],
            (int)$ctx['created_at'],
            $timeout
        );
        foreach ($events as $ev) {
            $verdict = self::interpretReceipt($ev, $ctx, $convKey);
            if ($verdict['paid']) {
                return ['paid' => true, 'event' => $ev];
            }
        }
        return ['paid' => false];
    }

    /**
     * Primary (payment-page) path: the browser forwards a raw signed event it
     * received on its live subscription; verify it really is a paid receipt for
     * this invoice. All trust comes from the merchant's signature — the browser
     * can relay but cannot forge.
     *
     * @param array $rawEvent decoded nostr event (id,pubkey,kind,tags,content,sig)
     * @param array $ctx      same shape as fetchReceipt()'s $ctx
     */
    public static function verifyReceiptEvent(array $rawEvent, array $ctx): array
    {
        $convKey = Nip44::getConversationKey($ctx['ephemeral_sk'], $ctx['receiver_pubkey']);
        return self::interpretReceipt($rawEvent, $ctx, $convKey);
    }

    // ---- internals ----

    /**
     * Validate + decrypt a candidate receipt event. Returns ['paid'=>bool].
     * A genuine receipt must: be kind 21001, be authored by the offer's
     * receiver pubkey, reference our request id via an `e` tag, carry a valid
     * Schnorr signature, and decrypt to a JSON object whose `res` is `ok`.
     */
    private static function interpretReceipt(array $ev, array $ctx, string $convKey): array
    {
        if ((int)($ev['kind'] ?? 0) !== self::KIND) {
            return ['paid' => false];
        }
        if (!hash_equals((string)$ctx['receiver_pubkey'], (string)($ev['pubkey'] ?? ''))) {
            return ['paid' => false];
        }
        if (!self::tagsReferenceEvent($ev['tags'] ?? [], (string)$ctx['request_event_id'])) {
            return ['paid' => false];
        }
        // Verify the Schnorr signature over the canonical event id.
        try {
            $check = new Event();
            $check->populate((object)[
                'id' => $ev['id'] ?? '',
                'pubkey' => $ev['pubkey'] ?? '',
                'created_at' => $ev['created_at'] ?? 0,
                'kind' => $ev['kind'] ?? 0,
                'tags' => $ev['tags'] ?? [],
                'content' => $ev['content'] ?? '',
                'sig' => $ev['sig'] ?? '',
            ]);
            if (!$check->verify()) {
                return ['paid' => false];
            }
        } catch (\Throwable $e) {
            return ['paid' => false];
        }
        // Decrypt + inspect the receipt body.
        try {
            $plain = Nip44::decrypt((string)($ev['content'] ?? ''), $convKey);
            $data = json_decode($plain, true);
        } catch (\Throwable $e) {
            return ['paid' => false];
        }
        if (is_array($data) && isset($data['res']) && $data['res'] === 'ok') {
            return ['paid' => true];
        }
        return ['paid' => false];
    }

    private static function tagsReferenceEvent(array $tags, string $eventId): bool
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'e' && ($tag[1] ?? null) === $eventId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Open a socket, subscribe for the reply, publish the request, and return
     * the first decrypted reply payload (bolt11 or error) — or null on timeout.
     */
    private static function roundTrip(
        string $relayUrl,
        array $signedEvent,
        string $ephemeralPubkey,
        string $requestId,
        int $createdAt,
        int $timeout,
        string $convKey
    ): ?array {
        $client = null;
        $subId = bin2hex(random_bytes(8));
        try {
            $client = new \WebSocket\Client($relayUrl);
            $client->setTimeout($timeout);
            $client->connect();

            $filter = self::replyFilter($ephemeralPubkey, $requestId, $createdAt);
            $client->text(json_encode(['REQ', $subId, $filter]));
            $client->text(json_encode(['EVENT', $signedEvent]));

            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                $msg = self::receiveText($client);
                if ($msg === null) {
                    break; // timeout / closed
                }
                $data = json_decode($msg, true);
                if (!is_array($data) || !isset($data[0])) {
                    continue;
                }
                if ($data[0] === 'EVENT' && ($data[1] ?? null) === $subId && isset($data[2])) {
                    $ev = $data[2];
                    // Only accept replies actually authored by the receiver.
                    if (!hash_equals($signedEvent['tags'][0][1] ?? '', (string)($ev['pubkey'] ?? ''))) {
                        continue;
                    }
                    try {
                        $plain = Nip44::decrypt((string)($ev['content'] ?? ''), $convKey);
                        $parsed = json_decode($plain, true);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (is_array($parsed)) {
                        // The bolt11 reply is what we're after; ignore a stray
                        // receipt that might race in (handled separately).
                        if (isset($parsed['bolt11']) || isset($parsed['error']) || isset($parsed['code'])) {
                            return $parsed;
                        }
                    }
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
                // EOSE / OK / NOTICE: keep waiting for the merchant's reply.
            }
            return null;
        } catch (\Throwable $e) {
            throw new ClinkException('CLINK relay error: ' . $e->getMessage(), 2);
        } finally {
            self::closeQuietly($client, $subId);
        }
    }

    /**
     * Collect events matching our reply filter until EOSE or timeout. Used by
     * the cron receipt poll, where we read whatever the relay still holds.
     *
     * @return array<int,array> raw event arrays
     */
    private static function collectEvents(
        string $relayUrl,
        string $ephemeralPubkey,
        string $requestId,
        int $createdAt,
        int $timeout
    ): array {
        $client = null;
        $subId = bin2hex(random_bytes(8));
        $out = [];
        try {
            $client = new \WebSocket\Client($relayUrl);
            $client->setTimeout($timeout);
            $client->connect();
            $filter = self::replyFilter($ephemeralPubkey, $requestId, $createdAt);
            $client->text(json_encode(['REQ', $subId, $filter]));

            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                $msg = self::receiveText($client);
                if ($msg === null) {
                    break;
                }
                $data = json_decode($msg, true);
                if (!is_array($data) || !isset($data[0])) {
                    continue;
                }
                if ($data[0] === 'EVENT' && ($data[1] ?? null) === $subId && isset($data[2]) && is_array($data[2])) {
                    $out[] = $data[2];
                } elseif ($data[0] === 'EOSE' && ($data[1] ?? null) === $subId) {
                    break; // we have all stored events the relay will give us
                } elseif ($data[0] === 'CLOSED' && ($data[1] ?? null) === $subId) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // best-effort: swallow transport errors, return what we have
            error_log('[clink] receipt poll relay error: ' . $e->getMessage());
        } finally {
            self::closeQuietly($client, $subId);
        }
        return $out;
    }

    /** Reply/receipt subscription filter: kind 21001 to us, referencing our request. */
    private static function replyFilter(string $ephemeralPubkey, string $requestId, int $createdAt): array
    {
        return [
            'kinds' => [self::KIND],
            '#p' => [$ephemeralPubkey],
            '#e' => [$requestId],
            'since' => max(0, $createdAt - 1),
        ];
    }

    /** Receive the next text frame's content, or null on timeout/close/control noise. */
    private static function receiveText(\WebSocket\Client $client): ?string
    {
        try {
            $msg = $client->receive();
        } catch (\Throwable $e) {
            return null; // timeout or closed
        }
        if ($msg === null) {
            return null;
        }
        if (method_exists($msg, 'getOpcode') && $msg->getOpcode() !== 'text') {
            return ''; // control frame (ping/pong); caller keeps looping
        }
        return $msg->getContent();
    }

    private static function closeQuietly(?\WebSocket\Client $client, string $subId): void
    {
        if ($client === null) {
            return;
        }
        try {
            if ($client->isConnected()) {
                $client->text(json_encode(['CLOSE', $subId]));
                $client->disconnect();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private static function timeoutSec(): int
    {
        if (defined('CLINK_NOFFER_TIMEOUT_SEC')) {
            return (int)CLINK_NOFFER_TIMEOUT_SEC;
        }
        return self::DEFAULT_TIMEOUT_SEC;
    }
}
