<?php
/**
 * CashuPayServer - Shape validation for values read out of third-party API
 * responses (price feeds, Lightning rails, swap providers, explorers).
 *
 * Every number, hex blob, identifier or BOLT11 invoice that comes back from a
 * remote service goes through one of these helpers before it is used, cached,
 * stored or logged. Each helper accepts `mixed` (whatever json_decode handed
 * us) and returns a value of exactly one well-defined type, or null when the
 * input is not of the expected shape. Callers decide whether null means
 * "field absent" or "provider is misbehaving".
 *
 * The helpers deliberately refuse PHP's permissive coercions: an array is
 * never 1, "1e400" is never INF, " 12 " is never 12, and a string with
 * control characters never reaches a log line or a QR code.
 *
 * Regexes end in \z, not $: PCRE's $ also matches before a trailing "\n",
 * which would let "12\n" pass as a number.
 */

final class ResponseShape {
    /** Longest BOLT11 invoice we accept (route hints included, QR-sized). */
    public const BOLT11_MAX_LEN = 2048;
    /** bech32 data-part alphabet (no 1, b, i, o). */
    public const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    /** Largest Lightning amount we ever accept: 21M BTC in msat. */
    private const MAX_MSAT = '2100000000000000000';

    /**
     * json_decode() to an associative array with a nesting cap. Returns null
     * for anything that is not a JSON object/array (scalars, invalid JSON,
     * nesting deeper than $depth).
     */
    public static function decodeJsonArray(string $body, int $depth = 32): ?array {
        $data = json_decode($body, true, max(1, $depth));
        return is_array($data) ? $data : null;
    }

    /**
     * Non-negative decimal number as a finite float. Accepts JSON numbers
     * (int|float) and plain decimal strings (`123`, `0.45`); rejects booleans,
     * arrays, signs, exponents, hex, whitespace and INF/NaN.
     */
    public static function decimal(mixed $v): ?float {
        if (is_bool($v)) {
            return null;
        }
        if (is_int($v)) {
            return $v >= 0 ? (float)$v : null;
        }
        if (is_float($v)) {
            return (is_finite($v) && $v >= 0) ? $v : null;
        }
        if (is_string($v) && preg_match('/^[0-9]{1,24}(\.[0-9]{1,24})?\z/', $v) === 1) {
            $f = (float)$v;
            return is_finite($f) ? $f : null;
        }
        return null;
    }

    /**
     * Non-negative integer. Accepts ints, integral floats (JSON `12.0`) and
     * plain digit strings that fit in PHP_INT_MAX; rejects everything else.
     */
    public static function integer(mixed $v): ?int {
        if (is_bool($v)) {
            return null;
        }
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        if (is_float($v)) {
            if (!is_finite($v) || $v < 0 || $v > 9007199254740991.0 || floor($v) !== $v) {
                return null;
            }
            return (int)$v;
        }
        if (is_string($v) && preg_match('/^[0-9]{1,19}\z/', $v) === 1) {
            // 19 digits can still exceed PHP_INT_MAX (9223372036854775807).
            if (strlen($v) === 19 && strcmp($v, (string)PHP_INT_MAX) > 0) {
                return null;
            }
            return (int)$v;
        }
        return null;
    }

    /**
     * Lowercase hex string. With $bytes set the value must encode exactly
     * that many bytes; otherwise any non-empty even-length hex up to
     * $maxBytes is accepted.
     */
    public static function hex(mixed $v, ?int $bytes = null, int $maxBytes = 4_000_000): ?string {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $len = strlen($v);
        if ($len % 2 !== 0 || !ctype_xdigit($v)) {
            return null;
        }
        if ($bytes !== null) {
            return $len === $bytes * 2 ? strtolower($v) : null;
        }
        return $len <= $maxBytes * 2 ? strtolower($v) : null;
    }

    /**
     * Opaque identifier / status token: printable ASCII only (no spaces or
     * control characters), 1..$maxLen bytes, optionally matching $pattern.
     */
    public static function token(mixed $v, int $maxLen = 128, ?string $pattern = null): ?string {
        if (!is_string($v) || $v === '' || strlen($v) > $maxLen) {
            return null;
        }
        if (preg_match('/^[\x21-\x7E]+\z/', $v) !== 1) {
            return null;
        }
        if ($pattern !== null && preg_match($pattern, $v) !== 1) {
            return null;
        }
        return $v;
    }

    /**
     * Bitcoin-style address token (base58 or bech32/bech32m), as returned by
     * swap providers and custodial rails. This is a SHAPE check only; callers
     * that need network/checksum validation keep doing it themselves.
     */
    public static function addressToken(mixed $v): ?string {
        return self::token($v, 90, '/^[a-zA-Z0-9]{14,90}\z/');
    }

    /**
     * BOLT11 invoice shape: `ln` + (bc|tb|tbs|bcrt) + optional amount + `1`
     * + bech32 data part, all one case, at most BOLT11_MAX_LEN bytes.
     * Returns the trimmed invoice as given (case preserved) or null.
     */
    public static function bolt11(mixed $v): ?string {
        if (!is_string($v)) {
            return null;
        }
        $s = trim($v);
        if ($s === '' || strlen($s) > self::BOLT11_MAX_LEN) {
            return null;
        }
        if ($s !== strtolower($s) && $s !== strtoupper($s)) {
            return null; // bech32 forbids mixed case
        }
        $re = '/^ln(bc|tb|tbs|bcrt)(?:[0-9]{1,20}[munp]?)?1[' . self::BECH32_CHARSET . ']{6,}\z/';
        return preg_match($re, strtolower($s)) === 1 ? $s : null;
    }

    /**
     * Amount encoded in a BOLT11 invoice, in millisatoshi, as an int.
     * Returns 0 for an amountless invoice and null when the invoice is not
     * BOLT11-shaped, the pico amount is not a multiple of 10 (the spec
     * forbids sub-msat precision) or the amount exceeds 21M BTC.
     */
    public static function bolt11AmountMsat(string $bolt11): ?int {
        $inv = self::bolt11($bolt11);
        if ($inv === null) {
            return null;
        }
        if (preg_match('/^ln(?:bc|tb|tbs|bcrt)([0-9]{1,20})([munp]?)1/', strtolower($inv), $m) !== 1) {
            return 0; // no amount section
        }
        $digits = ltrim($m[1], '0');
        if ($digits === '') {
            return null; // "0" amounts are not valid BOLT11
        }
        switch ($m[2]) {
            case '':  $msat = bcmul($digits, '100000000000', 0); break; // BTC
            case 'm': $msat = bcmul($digits, '100000000', 0); break;    // milli
            case 'u': $msat = bcmul($digits, '100000', 0); break;       // micro
            case 'n': $msat = bcmul($digits, '100', 0); break;          // nano
            default:                                                    // pico
                if (substr($digits, -1) !== '0') {
                    return null;
                }
                $msat = substr($digits, 0, -1);
                break;
        }
        if (bccomp($msat, self::MAX_MSAT, 0) > 0) {
            return null;
        }
        return (int)$msat;
    }
}
