<?php
/**
 * The harness's padded Nostr key helper (see nostr_test_private_key in
 * harness.php). nostr-php returns unpadded hex, so without this helper one
 * generated key in 256 was 63 characters and failed the suite's 64-hex NWC
 * checks at random.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/vendor/autoload.php';

use swentel\nostr\Key\Key;

// Deterministic: short input is left-padded, full-length input untouched.
assert_eq(str_repeat('0', 61) . 'abc', nostr_pad_hex('abc'), 'short hex is left-padded to 64');
$full = str_repeat('f', 64);
assert_eq($full, nostr_pad_hex($full), 'a 64-char key is returned unchanged');
assert_eq(str_repeat('0', 62) . 'ab', nostr_pad_hex('AB'), 'hex is lower-cased');

// Every generated key is exactly 64 hex and still derives a valid pubkey.
$key = new Key();
for ($i = 0; $i < 32; $i++) {
    $sk = nostr_test_private_key();
    assert_true(preg_match('/^[0-9a-f]{64}$/', $sk) === 1, "key $i is 64 lowercase hex: $sk");
    assert_eq(64, strlen($key->getPublicKey($sk)), "key $i derives a 64-hex x-only pubkey");
}

echo "OK\n";
