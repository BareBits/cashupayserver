<?php
/**
 * Behavioural test for the swap cron-liveness gate inside Invoice::create.
 *
 * Reverse swaps are claimed only from cron, so when external cron is stale a
 * swap we hand out now might never be claimed before the provider's timeout —
 * an unrecoverable loss of customer funds. The gate therefore suppresses the
 * swap when cron is stale and lets checkout proceed on another rail:
 *   - fresh cron                  → swap rail (provider called)
 *   - stale cron, mint available  → mint fallback (non-strict)
 *   - stale cron, strict mode     → mint suppressed, proceed on-chain
 *   - never-run cron              → suppressed, on-chain
 *
 * Uses a self-consistent mock provider (valid lockup tree so verifySwapLockup
 * passes) that counts createReverseSwap calls, and a swap-only store (no mint)
 * so the fallback rail is on-chain — exercisable without a live cashu mint.
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/cashupay-crongate-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
define('CASHUPAY_DATA_DIR', $tmp);

require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/background.php';
require_once __DIR__ . '/../../includes/invoice.php';
require_once __DIR__ . '/../../includes/swap/factory.php';
require_once __DIR__ . '/../../includes/swap/config.php';
require_once __DIR__ . '/../../includes/swap/provider.php';
require_once __DIR__ . '/../../includes/crypto/secp256k1.php';
require_once __DIR__ . '/../../includes/crypto/taproot.php';
require_once __DIR__ . '/../../includes/crypto/tx_builder.php';

$failures = 0;
$total = 0;
function tassert(bool $cond, string $msg): void {
    global $total, $failures; $total++;
    if ($cond) { echo "PASS {$msg}\n"; }
    else { echo "FAIL {$msg}\n"; $failures++; }
}

/** Mock provider with a valid lockup tree; counts createReverseSwap calls. */
class CronGateMockProvider implements SwapProvider {
    public static int $createdCount = 0;
    public function getName(): string { return 'crongatemock'; }
    public function isReachable(string $network): bool { return true; }
    public function getReversePairInfo(string $network): SwapPairInfo {
        return new SwapPairInfo(0.5, 200, 150, 1000, 5_000_000, 'mockhash');
    }
    public function createReverseSwap(string $network, int $onchainAmountSats, string $claimPubkeyHex, string $preimageHashHex): SwapCreateResult {
        self::$createdCount++;
        $refundPriv = hash('sha256', 'mock-refund-key', true);
        $refundPub = Secp256k1::pointToCompressed(Secp256k1::generatorMult(Secp256k1::bytesToGmp($refundPriv)));
        $claimPub = hex2bin($claimPubkeyHex);
        $preimageHash = hex2bin($preimageHashHex);

        $claimXOnly = substr($claimPub, 1);
        $hash160 = hash('ripemd160', hash('sha256', $preimageHash, true), true);
        $claimScript = "\x82\x01\x20\x88\xA9" . chr(20) . $hash160 . "\x88" . chr(32) . $claimXOnly . "\xAC";
        $refundXOnly = substr($refundPub, 1);
        $refundScript = chr(32) . $refundXOnly . "\xAD" . TxBuilder::scriptNumberPush(1_000_000) . "\xB1";

        $claimLeaf  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeaf = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $merkleRoot = Taproot::tapBranchHash($claimLeaf, $refundLeaf);
        $internalKey = Taproot::keyAggInternalKey([$refundPub, $claimPub]);
        [$outKey] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        $lockup = Taproot::encodeP2trAddress($outKey, $network === 'regtest' ? 'regtest' : 'mainnet');

        return new SwapCreateResult(
            swapId: 'mocksw-' . bin2hex(random_bytes(4)),
            invoice: 'lnbcrt' . ($onchainAmountSats + 360) . 'n1mock',
            invoiceAmountSats: $onchainAmountSats + 360,
            onchainAmountSats: $onchainAmountSats,
            lockupAddress: $lockup,
            refundPublicKeyHex: bin2hex($refundPub),
            timeoutBlockHeight: 1_000_000,
            claimLeafScript: $claimScript,
            refundLeafScript: $refundScript,
        );
    }
    public function getSwapStatus(string $network, string $swapId): ?SwapStatus { return null; }
    public function broadcastTx(string $network, string $rawTxHex): string { return 'mocktxid'; }
    public function cancelInvoice(string $network, string $swapId): void { /* no-op */ }
}

Database::initialize();
SwapsConfig::setSiteEnabled(true);
SwapsConfig::setProviderOrder(['crongatemock']);
SwapsConfig::setAutoSelectCheapest(false);
SwapProviderFactory::setRegistry(['crongatemock' => new CronGateMockProvider()]);

// Swap-only store (no mint): the non-swap fallback rail is on-chain.
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'Cron Gate Store',
    'mint_url' => null,
    'mint_unit' => 'sat',
    'created_at' => time(),
    'onchain_xpub' => 'tpubD6NzVbkrYhZ4WaWSyoBvQwbpLkojyoTZPRsgXELWz3Popb3qkjcJyJUGLnL4qHHoQvao8ESaAstxYSnhyswJ76uZPStJRJCTKvosUCJZL5B',
    'onchain_address_type' => 'P2WPKH',
    'onchain_network' => 'regtest',
]);

$threshold = Background::SWAP_CRON_STALE_THRESHOLD_SECS;
$mk = fn() => Invoice::create($storeId, ['amount' => 50000, 'currency' => 'sat']);

// --- Fresh cron → swap rail, provider called -------------------------------
Config::set('last_external_cron_at', time());
CronGateMockProvider::$createdCount = 0;
$inv = $mk();
tassert($inv['payment_rail'] === 'swap', 'fresh cron → swap rail');
tassert(CronGateMockProvider::$createdCount === 1, 'fresh cron → provider createReverseSwap called');

// --- Stale cron → swap suppressed, falls back to on-chain ------------------
Config::set('last_external_cron_at', time() - ($threshold + 60));
CronGateMockProvider::$createdCount = 0;
$inv = $mk();
tassert($inv['payment_rail'] === 'onchain', 'stale cron → on-chain fallback rail');
tassert(CronGateMockProvider::$createdCount === 0, 'stale cron → provider NOT called');
tassert(!empty($inv['onchain_address']), 'stale cron → on-chain address allocated (checkout proceeds)');

// --- Never-run cron → suppressed, on-chain ---------------------------------
Config::delete('last_external_cron_at');
Config::delete('last_external_cron_swaps_at');
CronGateMockProvider::$createdCount = 0;
$inv = $mk();
tassert($inv['payment_rail'] === 'onchain', 'never-run cron → on-chain fallback rail');
tassert(CronGateMockProvider::$createdCount === 0, 'never-run cron → provider NOT called');

// --- Strict mode + stale cron → no throw, proceeds on-chain ----------------
// (Strict mode normally errors rather than mint-fallback; for a stale-cron
//  suppression we still want checkout to proceed via a non-swap rail.)
SwapsConfig::setStrictNoMintFallback(true);
Config::set('last_external_cron_at', time() - ($threshold + 60));
CronGateMockProvider::$createdCount = 0;
$threw = false;
try {
    $inv = $mk();
} catch (\Throwable $e) {
    $threw = true;
}
tassert(!$threw, 'strict mode + stale cron → does not throw');
tassert(isset($inv) && $inv['payment_rail'] === 'onchain', 'strict mode + stale cron → on-chain rail');
tassert(CronGateMockProvider::$createdCount === 0, 'strict mode + stale cron → provider NOT called');

// --- Swap fast-lane stamp alone keeps swaps alive --------------------------
SwapsConfig::setStrictNoMintFallback(false);
Config::delete('last_external_cron_at');
Config::set('last_external_cron_swaps_at', time());
CronGateMockProvider::$createdCount = 0;
$inv = $mk();
tassert($inv['payment_rail'] === 'swap', 'fresh swap fast-lane stamp → swap rail');
tassert(CronGateMockProvider::$createdCount === 1, 'fresh swap fast-lane stamp → provider called');

echo "\n{$total} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
