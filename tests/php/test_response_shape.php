<?php
/**
 * ResponseShape: every value we read out of a third-party API response must
 * come back as exactly one well-defined type or be refused. These cases are
 * the hostile shapes a buggy or malicious upstream can hand json_decode():
 * arrays where scalars are expected, exponent strings, whitespace, signs,
 * control characters, mixed-case / wrong-alphabet BOLT11s, oversize values.
 *
 * Network-free and DB-free.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';
require_once dirname(__DIR__, 2) . '/app/includes/response_shape.php';

// ---- decodeJsonArray --------------------------------------------------------
assert_eq(['a' => 1], ResponseShape::decodeJsonArray('{"a":1}'), 'object decodes');
assert_eq([1, 2], ResponseShape::decodeJsonArray('[1,2]'), 'array decodes');
foreach (['', 'null', '42', '"str"', 'true', '<html>', '{"a":', "\x1b[31m{}"] as $bad) {
    assert_null(ResponseShape::decodeJsonArray($bad), 'non-object JSON refused: ' . json_encode($bad));
}
$deep = str_repeat('[', 40) . '1' . str_repeat(']', 40);
assert_null(ResponseShape::decodeJsonArray($deep), 'nesting beyond the depth cap is refused');
assert_not_null(ResponseShape::decodeJsonArray($deep, 64), 'depth cap is a parameter');

// ---- decimal ------------------------------------------------------------------
assert_eq(95000.5, ResponseShape::decimal(95000.5), 'float passes');
assert_eq(7.0, ResponseShape::decimal(7), 'int passes as float');
assert_eq(0.0, ResponseShape::decimal(0), 'zero is a decimal (range is the caller\'s job)');
assert_eq(41.78, ResponseShape::decimal('41.78'), 'plain decimal string passes');
assert_eq(123.0, ResponseShape::decimal('123'), 'digit string passes');
foreach ([
    '1e5', '1E5', '1e400', ' 12', '12 ', '+12', '-12', '0x1A', '1_000', '.5', '5.', '', 'abc',
    "12\n", "\x0012", 'NAN', 'INF', '١٢',
    -1, -0.5, INF, -INF, NAN, true, false, null, [], ['1'], [1.5],
    str_repeat('9', 30), '1.' . str_repeat('9', 30),
] as $bad) {
    assert_null(ResponseShape::decimal($bad), 'decimal refuses ' . json_encode($bad));
}

// ---- integer ------------------------------------------------------------------
assert_eq(42, ResponseShape::integer(42), 'int passes');
assert_eq(42, ResponseShape::integer(42.0), 'integral float passes');
assert_eq(42, ResponseShape::integer('42'), 'digit string passes');
assert_eq(0, ResponseShape::integer(0), 'zero passes');
assert_eq(PHP_INT_MAX, ResponseShape::integer((string)PHP_INT_MAX), 'PHP_INT_MAX string passes');
foreach ([
    42.5, -1, -1.0, '-1', '+1', ' 1', '1 ', '1e3', '0x10', '', 'abc', true, false, null, [], [1],
    '9223372036854775808', str_repeat('9', 20), INF, NAN, 9007199254740993.0,
] as $bad) {
    assert_null(ResponseShape::integer($bad), 'integer refuses ' . json_encode($bad));
}

// ---- hex ----------------------------------------------------------------------
$h32 = str_repeat('ab', 32);
assert_eq($h32, ResponseShape::hex($h32, 32), '32-byte hex passes');
assert_eq($h32, ResponseShape::hex(strtoupper($h32), 32), 'uppercase hex is lowercased');
assert_eq('00ff', ResponseShape::hex('00ff'), 'free-length hex passes');
foreach (['', 'abc', 'zz', 'ab cd', "ab\n", str_repeat('ab', 31), str_repeat('ab', 33), 171, null, ['ab'], true] as $bad) {
    assert_null(ResponseShape::hex($bad, 32), 'hex(32) refuses ' . json_encode($bad));
}
assert_null(ResponseShape::hex(str_repeat('ab', 11), null, 10), 'maxBytes is enforced');

// ---- token --------------------------------------------------------------------
assert_eq('strk-1234', ResponseShape::token('strk-1234'), 'plain token passes');
assert_eq('transaction.mempool', ResponseShape::token('transaction.mempool', 64), 'dotted status passes');
foreach (['', 'a b', "a\tb", "a\nb", "\x1b[31mred", "a\x00b", 'ü', str_repeat('a', 129), 12, null, [], true] as $bad) {
    assert_null(ResponseShape::token($bad), 'token refuses ' . json_encode($bad));
}
assert_null(ResponseShape::token('abc!', 10, '/^[a-z]+$/'), 'pattern is enforced');
assert_eq('abc', ResponseShape::token('abc', 10, '/^[a-z]+$/'), 'pattern match passes');

// ---- addressToken ---------------------------------------------------------------
assert_not_null(ResponseShape::addressToken('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'), 'bech32 address shape');
assert_not_null(ResponseShape::addressToken('1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'), 'base58 address shape');
foreach (['bitcoin:bc1q...', 'bc1q w', 'short', str_repeat('a', 91), '', "bc1q\x1b", null, 5] as $bad) {
    assert_null(ResponseShape::addressToken($bad), 'addressToken refuses ' . json_encode($bad));
}

// ---- bolt11 ---------------------------------------------------------------------
$good = [
    'lnbc1pretend',                 // amountless
    'lnbc2500u1pvjluez',            // 2500u
    'lnbc10000n1m0ckvnv0ce0000000', // mock shape used across the suite
    'LNBC10000N1M0CKVNV0CE0000000', // all-uppercase is legal bech32
    'lnbcrt1000n1qqqqqq',
    'lntbs10u1qqqqqq',
    'lntb10u1qqqqqq',
    '  lnbc1pretend  ',             // surrounding whitespace is trimmed
];
foreach ($good as $g) {
    assert_eq(trim($g), ResponseShape::bolt11($g), 'bolt11 accepts ' . $g);
}
$bad = [
    'lnbc1mockinvoice',            // 'o', 'i' are outside the bech32 alphabet
    'lnbcmockstrike',              // no separator
    'lnbc1pretend' . "\x1b[31m",   // escape sequence
    'lnbc1Pretend',                // mixed case
    'lnbc1',                       // no data part
    'lnbc1abc',                    // data part too short / 'b' invalid
    'lnxx1qqqqqq',                 // unknown network prefix
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', // an address, not an invoice
    'lightning:lnbc1pretend',      // URI, not bare invoice
    'lnbc10x1qqqqqq',              // unknown multiplier
    'lnbc1pretend lnbc1pretend',   // embedded space
    '', ' ', null, 12, ['lnbc1pretend'], true,
    'lnbc1' . str_repeat('q', 2100), // over the length cap
    'lnbc' . str_repeat('9', 21) . 'n1qqqqqq', // absurd amount digits
];
foreach ($bad as $b) {
    assert_null(ResponseShape::bolt11($b), 'bolt11 refuses ' . json_encode($b));
}
assert_not_null(ResponseShape::bolt11('lnbc1' . str_repeat('q', 2043)), 'exactly at the length cap passes');

// ---- bolt11AmountMsat --------------------------------------------------------------
assert_eq(0, ResponseShape::bolt11AmountMsat('lnbc1pretend'), 'amountless -> 0');
assert_eq(100000000000, ResponseShape::bolt11AmountMsat('lnbc11qqqqqq'), '1 BTC');
assert_eq(100000000, ResponseShape::bolt11AmountMsat('lnbc1m1qqqqqq'), '1 mBTC');
assert_eq(250000000, ResponseShape::bolt11AmountMsat('lnbc2500u1pvjluez'), '2500 uBTC = 250 000 sat');
assert_eq(1000, ResponseShape::bolt11AmountMsat('lnbc10n1qqqqqq'), '10 nBTC = 1 sat');
assert_eq(1000000, ResponseShape::bolt11AmountMsat('lnbc10000n1m0ckvnv0ce0000000'), '1000 sats mock');
assert_eq(1, ResponseShape::bolt11AmountMsat('lnbc10p1qqqqqq'), '10 pBTC = 1 msat');
assert_eq(1000000, ResponseShape::bolt11AmountMsat('LNBC10000N1M0CKVNV0CE0000000'), 'uppercase parses');
assert_eq(2100000000000000000, ResponseShape::bolt11AmountMsat('lnbc210000001qqqqqq'), '21M BTC is the ceiling');
assert_null(ResponseShape::bolt11AmountMsat('lnbc210000011qqqqqq'), 'above 21M BTC refused');
assert_null(ResponseShape::bolt11AmountMsat('lnbc1p1qqqqqq'), 'sub-msat pico amount refused');
assert_null(ResponseShape::bolt11AmountMsat('lnbc0u1qqqqqq'), 'zero amount digits refused');
assert_null(ResponseShape::bolt11AmountMsat('lnbc1mockinvoice'), 'not BOLT11-shaped -> null');
assert_null(ResponseShape::bolt11AmountMsat('garbage'), 'garbage -> null');
assert_null(ResponseShape::bolt11AmountMsat('lnbc' . str_repeat('9', 20) . 'p1qqqqqq'), '20-digit pico overflow refused');

echo "test_response_shape: all assertions passed\n";
