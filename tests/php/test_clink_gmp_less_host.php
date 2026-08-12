<?php
/**
 * CLINK noffer environment gate on a GMP-less host.
 *
 * swentel/nostr-php hard-requires ext-gmp (Schnorr signing + NIP-44 key
 * derivation), but neither composer.json nor the generated platform check
 * enforces it — so on a shared host without GMP everything works EXCEPT the
 * noffer payer path, which threw a raw Error ("gmp_init undefined") deep in
 * the library. Invoice::create catches it, logs one line, and the checkout
 * silently loses its Lightning option.
 *
 * ClinkClient::environmentError() is the up-front gate the onboarding wizard
 * and admin settings use (the noffer sibling of
 * OnchainWallet::environmentError(), covered by test_onchain_gmp_less_host).
 * A GMP-less host is simulated by running assertions in a child PHP with the
 * gmp_* functions disabled — calling a disabled function raises the same
 * Error an absent extension does. Asserts that on such a host:
 *   - environmentError() reports the problem and names php-gmp,
 *   - requestInvoice() throws an actionable ClinkException before touching
 *     the network, rather than the bare "undefined function" Error,
 * and that with GMP present environmentError() stays null.
 */
declare(strict_types=1);
require __DIR__ . '/harness.php';

const GMP_FUNCS = 'gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,'
    . 'gmp_strval,gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,'
    . 'gmp_and,gmp_or,gmp_setbit,gmp_testbit,gmp_neg';

// Reference noffer from @shocknet/clink-sdk — structurally valid, never
// dialled here (the environment gate must fire before any socket is opened).
const REFERENCE_NOFFER = 'noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsen'
    . 'rxumnyvesxfnrswfkvycrwdp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue'
    . '69uhhgetnwskhyetvv9ujumrfva58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008f'
    . 'd8ygtl4qy06gstpye3h5unc47xmee6z';

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
$prelude = 'require_once ' . var_export($root . '/includes/clink/client.php', true) . ';';

// 1. The gate fires without GMP and the message is actionable (names php-gmp).
[$rc, $out] = run_without_gmp(
    $prelude . 'echo (string)ClinkClient::environmentError();'
);
assert_eq(0, $rc, "environmentError probe exited cleanly: $out");
assert_true(stripos($out, 'GMP') !== false, "gate message names GMP: $out");
assert_true(stripos($out, 'php-gmp') !== false, "gate message tells the operator what to ask for: $out");

// 2. requestInvoice throws a catchable ClinkException carrying that message —
//    not the raw Error nostr-php raises from gmp_* two frames deeper. This is
//    what keeps the runtime fallback (Invoice::create's catch) actionable in
//    the logs. A 1-second timeout would still be far more wall-clock than the
//    gate needs: it must throw before any relay connection is attempted.
[$rc, $out] = run_without_gmp(
    $prelude . '
    try {
        ClinkClient::requestInvoice(' . var_export(REFERENCE_NOFFER, true) . ', 1000, null, 1);
        echo "NO_THROW";
    } catch (ClinkException $e) {
        echo "CLINK:" . $e->getMessage();
    } catch (Throwable $e) {
        echo get_class($e) . ":" . $e->getMessage();
    }'
);
assert_eq(0, $rc, "requestInvoice path exited cleanly: $out");
assert_true(
    str_starts_with($out, 'CLINK:') && stripos($out, 'GMP') !== false,
    "requestInvoice threw actionable ClinkException: $out"
);

// 3. With GMP present (this parent process), the gate stays open.
require_once $root . '/includes/clink/client.php';
assert_null(ClinkClient::environmentError(), 'parent process has GMP');

echo "test_clink_gmp_less_host: OK\n";
