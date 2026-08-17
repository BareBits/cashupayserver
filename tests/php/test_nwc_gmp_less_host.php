<?php
/**
 * NWC environment gate on a GMP-less host — the NIP-47 sibling of
 * test_clink_gmp_less_host. NwcClient signs kind-23194 events and derives
 * NIP-04/NIP-44 keys through swentel/nostr-php, which hard-requires ext-gmp;
 * on a host without it the gate must fire up front with an actionable message
 * (the onboarding wizard, admin settings, and save handler all consume it),
 * and makeInvoice must throw a catchable NwcException before touching the
 * network rather than the raw "gmp_init undefined" Error.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';

// Structurally valid connection string; never dialled (the gate must throw
// before any socket is opened — the relay port is a dead loopback one).
const TEST_NWC_URI = 'nostr+walletconnect://'
    . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    . '?relay=ws://127.0.0.1:1/&secret='
    . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/** Run $code in a child PHP with gmp_* disabled; return [exitCode, output]. */
function run_without_gmp(string $code): array {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d disable_functions=' . escapeshellarg(GMP_FUNCS)
        . ' -d error_reporting=E_ALL -d display_errors=1'
        . ' -r ' . escapeshellarg($code) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

$root = dirname(__DIR__, 2);
$prelude = 'require_once ' . var_export($root . '/includes/nwc/client.php', true) . ';';

// 1. The gate fires without GMP and the message is actionable.
[$rc, $out] = run_without_gmp(
    $prelude . 'echo (string)NwcClient::environmentError();'
);
assert_eq(0, $rc, "environmentError probe exited cleanly: $out");
assert_true(stripos($out, 'GMP') !== false, "gate message names GMP: $out");
assert_true(stripos($out, 'php-gmp') !== false, "gate message tells the operator what to ask for: $out");

// 2. makeInvoice throws a catchable NwcException carrying that message.
[$rc, $out] = run_without_gmp(
    $prelude . '
    try {
        NwcClient::makeInvoice(' . var_export(TEST_NWC_URI, true) . ', 1, null, null, 1);
        echo "NO_THROW";
    } catch (NwcException $e) {
        echo "NWC:" . $e->getMessage();
    } catch (Throwable $e) {
        echo get_class($e) . ":" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "makeInvoice path exited cleanly: $out");
assert_true(
    str_starts_with($out, 'NWC:') && stripos($out, 'GMP') !== false,
    "makeInvoice threw actionable NwcException: $out"
);

// 3. With GMP present (this parent process), the gate stays open.
require_once $root . '/includes/nwc/client.php';
assert_null(NwcClient::environmentError(), 'parent process has GMP');

echo "test_nwc_gmp_less_host: OK\n";
