<?php
/**
 * Pure-PHP secp256k1 operations for the submarine-swap claim path.
 *
 * Provides just enough curve arithmetic to compute BIP340 Schnorr signatures
 * and BIP341 Taproot output keys. Implemented with GMP so it works on shared
 * hosting without ext-secp256k1 or FFI. All operations on the curve are in
 * affine coordinates; this is slow compared to libsecp256k1 but is invoked
 * only by the cron-driven claim path (a few times per swap), so latency is
 * not a concern.
 *
 * Curve parameters: y^2 = x^3 + 7 over GF(p), generator G of order n.
 */

final class Secp256k1 {
    // Field prime p = 2^256 - 2^32 - 977
    public const P_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    // Group order n
    public const N_HEX = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    // Generator x
    public const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    // Generator y
    public const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';
    // Curve constant b (a = 0)
    public const B_HEX = '0000000000000000000000000000000000000000000000000000000000000007';

    private static ?GMP $p = null;
    private static ?GMP $n = null;
    private static ?GMP $gx = null;
    private static ?GMP $gy = null;
    private static ?GMP $b = null;
    private static ?GMP $three = null;
    private static ?GMP $two = null;

    private static function init(): void {
        if (self::$p !== null) return;
        self::$p = gmp_init(self::P_HEX, 16);
        self::$n = gmp_init(self::N_HEX, 16);
        self::$gx = gmp_init(self::GX_HEX, 16);
        self::$gy = gmp_init(self::GY_HEX, 16);
        self::$b = gmp_init(self::B_HEX, 16);
        self::$three = gmp_init(3);
        self::$two = gmp_init(2);
    }

    public static function p(): GMP { self::init(); return self::$p; }
    public static function n(): GMP { self::init(); return self::$n; }
    public static function gx(): GMP { self::init(); return self::$gx; }
    public static function gy(): GMP { self::init(); return self::$gy; }
    public static function b(): GMP { self::init(); return self::$b; }
    public static function gPoint(): array { self::init(); return [self::$gx, self::$gy]; }

    /**
     * Modular inverse via Fermat's little theorem (p is prime).
     */
    public static function modInv(GMP $a, GMP $m): GMP {
        $r = gmp_invert($a, $m);
        if ($r === false) {
            throw new RuntimeException('Modular inverse does not exist');
        }
        return $r;
    }

    /**
     * Point addition in affine coordinates. Returns null for the point at
     * infinity. Either operand may be null (infinity).
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function pointAdd(?array $p, ?array $q): ?array {
        if ($p === null) return $q;
        if ($q === null) return $p;
        self::init();
        $prime = self::$p;
        [$x1, $y1] = $p;
        [$x2, $y2] = $q;
        if (gmp_cmp($x1, $x2) === 0) {
            if (gmp_cmp(gmp_mod(gmp_add($y1, $y2), $prime), 0) === 0) {
                return null; // P + (-P) = O
            }
            return self::pointDouble($p);
        }
        $num = gmp_sub($y2, $y1);
        $den = gmp_sub($x2, $x1);
        $slope = gmp_mod(gmp_mul($num, self::modInv(gmp_mod($den, $prime), $prime)), $prime);
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_mul($slope, $slope), $x1), $x2), $prime);
        $y3 = gmp_mod(gmp_sub(gmp_mul($slope, gmp_sub($x1, $x3)), $y1), $prime);
        return [$x3, $y3];
    }

    /**
     * Point doubling: returns 2P.
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function pointDouble(?array $p): ?array {
        if ($p === null) return null;
        self::init();
        $prime = self::$p;
        [$x, $y] = $p;
        if (gmp_cmp(gmp_mod($y, $prime), 0) === 0) {
            return null;
        }
        $num = gmp_mul(self::$three, gmp_mul($x, $x));
        $den = gmp_mul(self::$two, $y);
        $slope = gmp_mod(gmp_mul($num, self::modInv(gmp_mod($den, $prime), $prime)), $prime);
        $x3 = gmp_mod(gmp_sub(gmp_mul($slope, $slope), gmp_mul(self::$two, $x)), $prime);
        $y3 = gmp_mod(gmp_sub(gmp_mul($slope, gmp_sub($x, $x3)), $y), $prime);
        return [$x3, $y3];
    }

    /**
     * Scalar multiplication via double-and-add. Constant-time is not a goal
     * here (no private-key operations leak to remote observers); this is the
     * simplest correct algorithm.
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function scalarMult(GMP $k, ?array $p): ?array {
        self::init();
        $k = gmp_mod($k, self::$n);
        if (gmp_cmp($k, 0) === 0 || $p === null) {
            return null;
        }
        $result = null;
        $addend = $p;
        $kbits = gmp_strval($k, 2);
        for ($i = strlen($kbits) - 1; $i >= 0; $i--) {
            if ($kbits[$i] === '1') {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointDouble($addend);
        }
        return $result;
    }

    /**
     * Compute k·G.
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function generatorMult(GMP $k): ?array {
        return self::scalarMult($k, self::gPoint());
    }

    /**
     * BIP340 lift_x: find the unique point with given x and even y.
     * Returns null if x does not correspond to a point on the curve.
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function liftX(GMP $x): ?array {
        self::init();
        $prime = self::$p;
        if (gmp_cmp($x, 0) < 0 || gmp_cmp($x, $prime) >= 0) {
            return null;
        }
        $ySq = gmp_mod(gmp_add(gmp_powm($x, self::$three, $prime), self::$b), $prime);
        // y = ySq^((p+1)/4) mod p
        $exp = gmp_div_q(gmp_add($prime, gmp_init(1)), gmp_init(4));
        $y = gmp_powm($ySq, $exp, $prime);
        if (gmp_cmp(gmp_mod(gmp_mul($y, $y), $prime), $ySq) !== 0) {
            return null;
        }
        // Pick the even y per BIP340.
        if (gmp_intval(gmp_mod($y, self::$two)) === 1) {
            $y = gmp_sub($prime, $y);
        }
        return [$x, $y];
    }

    /**
     * Encode a 256-bit scalar/coord as exactly 32 big-endian bytes.
     */
    public static function gmpTo32Bytes(GMP $n): string {
        $hex = gmp_strval($n, 16);
        if (strlen($hex) > 64) {
            throw new RuntimeException('Value exceeds 32 bytes');
        }
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);
        return hex2bin($hex);
    }

    public static function bytesToGmp(string $bytes): GMP {
        return gmp_init(bin2hex($bytes), 16);
    }

    /**
     * Serialize a point as 33-byte compressed sec1 (02|03 || x).
     */
    public static function pointToCompressed(array $p): string {
        [$x, $y] = $p;
        $parity = gmp_intval(gmp_mod($y, gmp_init(2))) === 0 ? "\x02" : "\x03";
        return $parity . self::gmpTo32Bytes($x);
    }

    /**
     * Parse 33-byte compressed sec1 to a point. Returns null on invalid input.
     *
     * @return array{0:GMP,1:GMP}|null
     */
    public static function compressedToPoint(string $bytes): ?array {
        if (strlen($bytes) !== 33) return null;
        $prefix = ord($bytes[0]);
        if ($prefix !== 0x02 && $prefix !== 0x03) return null;
        $x = self::bytesToGmp(substr($bytes, 1));
        $point = self::liftX($x);
        if ($point === null) return null;
        // liftX returns even-y; flip if prefix asks for odd
        if ($prefix === 0x03) {
            self::init();
            $point[1] = gmp_sub(self::$p, $point[1]);
        }
        return $point;
    }

    /**
     * y-parity (0 = even, 1 = odd) of a point.
     */
    public static function pointParity(array $p): int {
        return gmp_intval(gmp_mod($p[1], gmp_init(2)));
    }

    /**
     * Verify a scalar is in the range [1, n-1].
     */
    public static function isValidScalar(GMP $s): bool {
        self::init();
        return gmp_cmp($s, 0) > 0 && gmp_cmp($s, self::$n) < 0;
    }

    /**
     * BIP340 tagged hash: SHA256(SHA256(tag) || SHA256(tag) || msg).
     */
    public static function taggedHash(string $tag, string $msg): string {
        $th = hash('sha256', $tag, true);
        return hash('sha256', $th . $th . $msg, true);
    }
}
