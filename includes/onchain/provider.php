<?php
/**
 * CashuPayServer - Blockchain provider abstraction for on-chain payment polling.
 *
 * Implementations return a normalized view of a watch address's transactions
 * regardless of the underlying data source (public Esplora-style HTTP API,
 * a self-hosted Bitcoin Core node, etc.).
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../safe_http.php';

/**
 * A single transaction output observed paying a given watch address.
 */
final class OnchainTxObservation {
    public function __construct(
        public readonly string $txid,
        public readonly int $vout,
        public readonly int $amountSat,
        public readonly int $confirmations,
        public readonly ?int $blockHeight,
    ) {}
}

interface BlockchainProvider {
    /**
     * Return every output across all transactions that pay $address. Includes
     * mempool transactions (confirmations = 0).
     *
     * @param ?int $sinceHeight Optional optimization hint: the caller only
     *   cares about txs confirmed at or after this block height (older ones
     *   are filtered out anyway). Implementations may stop paging once they
     *   pass below it. Null = no hint (enumerate everything available).
     * @return OnchainTxObservation[]
     */
    public function addressTransactions(string $address, ?int $sinceHeight = null): array;

    /**
     * Return the current best-known block height. Used as a sanity check and
     * for derived calculations.
     */
    public function currentTipHeight(): int;
}

/**
 * Esplora HTTP backend (mempool.space / blockstream.info compatible).
 *
 * Public endpoints used:
 *   GET /address/{addr}/txs     -> list of txs paying $addr (mempool + confirmed)
 *   GET /blocks/tip/height      -> current chain tip
 *
 * The instance is configured with the base URL of the API (e.g.
 * https://mempool.space/api). Per-network defaults can be obtained via
 * EsploraProvider::defaultUrlForNetwork().
 */
final class EsploraProvider implements BlockchainProvider {
    private const DEFAULTS = [
        'mainnet' => 'https://mempool.space/api',
        'testnet' => 'https://mempool.space/testnet/api',
        'signet'  => 'https://mempool.space/signet/api',
        // No public regtest API exists; users must self-host or use BitcoindRpcProvider.
        'regtest' => null,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSec = 10,
    ) {}

    public static function defaultUrlForNetwork(string $network): ?string {
        return self::DEFAULTS[$network] ?? null;
    }

    // Esplora returns confirmed transactions 25 per page. A page with fewer
    // than this many confirmed entries is the last page of chain history.
    private const ESPLORA_CHAIN_PAGE = 25;
    // Hard ceiling on pages walked (25 confirmed each) so a hot, heavily
    // re-used address can't make a single poll page forever.
    private const ESPLORA_MAX_PAGES = 40;

    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        $tip = $this->currentTipHeight();
        $out = [];

        // Esplora paginates /address/{a}/txs: the base call returns mempool txs
        // plus only the FIRST 25 confirmed; older confirmed txs require walking
        // /txs/chain/{last_seen_txid}. Without this loop, a re-used address with
        // >25 confirmed txs would silently drop older payments and an invoice
        // paid to it would never settle. We stop once a page is short, once we
        // page below $sinceHeight (older txs are filtered out anyway), or at a
        // hard page cap.
        $enc = rawurlencode($address);
        $lastSeenTxid = null;
        for ($page = 0; $page < self::ESPLORA_MAX_PAGES; $page++) {
            $path = $lastSeenTxid === null
                ? "/address/{$enc}/txs"
                : "/address/{$enc}/txs/chain/" . rawurlencode($lastSeenTxid);
            $txs = $this->httpJson($path);
            if (empty($txs)) {
                break;
            }

            $confirmedInPage = 0;
            $minHeightInPage = null;
            foreach ($txs as $tx) {
                $confirmed = (bool)($tx['status']['confirmed'] ?? false);
                $blockHeight = $confirmed ? ($tx['status']['block_height'] ?? null) : null;
                // A hostile or MITM'd provider could report an out-of-range
                // block_height to inflate the confirmation count past minConfs /
                // REORG_SAFETY_DEPTH and force a premature settle. A genuinely
                // confirmed tx sits at an integer height in (0, tip]; anything
                // else is treated as unconfirmed (confs=0) so it can't settle.
                if ($confirmed) {
                    if (!is_int($blockHeight) || $blockHeight <= 0 || $blockHeight > $tip) {
                        error_log(sprintf(
                            'EsploraProvider::addressTransactions(%s): tx %s reported out-of-range block_height %s (tip %d); treating as unconfirmed',
                            $address, (string)($tx['txid'] ?? '?'), var_export($blockHeight, true), $tip
                        ));
                        $confirmed = false;
                        $blockHeight = null;
                    } else {
                        $confirmedInPage++;
                        if ($minHeightInPage === null || $blockHeight < $minHeightInPage) {
                            $minHeightInPage = $blockHeight;
                        }
                    }
                }
                $confs = ($confirmed && $blockHeight !== null) ? max(0, $tip - $blockHeight + 1) : 0;
                foreach ($tx['vout'] ?? [] as $voutIdx => $vout) {
                    if (($vout['scriptpubkey_address'] ?? null) !== $address) {
                        continue;
                    }
                    $out[] = new OnchainTxObservation(
                        txid: $tx['txid'],
                        vout: $voutIdx,
                        // Floor at 0: a negative value would corrupt the
                        // received-amount / underpayment math downstream.
                        amountSat: max(0, (int)($vout['value'] ?? 0)),
                        confirmations: $confs,
                        blockHeight: $blockHeight,
                    );
                }
                if (isset($tx['txid'])) {
                    $lastSeenTxid = $tx['txid'];
                }
            }

            // Fewer than a full confirmed page => no more chain history.
            if ($confirmedInPage < self::ESPLORA_CHAIN_PAGE) {
                break;
            }
            // We've paged past the caller's window; older txs are irrelevant.
            if ($sinceHeight !== null && $minHeightInPage !== null && $minHeightInPage < $sinceHeight) {
                break;
            }
            if ($page === self::ESPLORA_MAX_PAGES - 1) {
                error_log("EsploraProvider::addressTransactions({$address}): hit max page cap; older history not walked");
            }
        }
        return $out;
    }

    public function currentTipHeight(): int {
        $body = trim($this->httpRaw('/blocks/tip/height'));
        // Esplora returns the height as a bare decimal string. Reject anything
        // else: an empty body, a proxy/error HTML page, or whitespace must NOT
        // collapse to (int)0 — a 0 tip poisons the historical-UTXO filter at
        // allocation time (a stored created-tip of 0 disables the filter, so a
        // pre-existing UTXO on a re-used address could wrongly settle an
        // invoice). Throwing lets currentTipBestEffort() degrade to null
        // (skip-filter) instead of a false 0.
        if ($body === '' || !ctype_digit($body)) {
            throw new RuntimeException('Esplora /blocks/tip/height: non-numeric body: ' . substr($body, 0, 80));
        }
        $height = (int)$body;
        if ($height <= 0) {
            throw new RuntimeException("Esplora /blocks/tip/height: non-positive height {$height}");
        }
        return $height;
    }

    private function httpRaw(string $path): string {
        $url = rtrim($this->baseUrl, '/') . $path;
        // Esplora URL is admin-configured; the operator's allow_private_endpoints
        // opt-in lets them point at a local Bitcoin node.
        $result = \SafeHttp::request($url, [
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'followRedirects' => true,
            'headers' => ['Accept: application/json'],
            'userAgent' => 'CashuPayServer/1.0 (onchain)',
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException("Esplora request failed for {$path}: {$result['error']}");
        }
        if ($result['status'] >= 400) {
            throw new RuntimeException("Esplora {$path} -> HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
        return $result['body'];
    }

    private function httpJson(string $path): array {
        $raw = $this->httpRaw($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Esplora {$path}: malformed JSON response");
        }
        return $decoded;
    }
}

/**
 * Bitcoin Core RPC backend.
 *
 * Designed for tests (the existing bitcoind regtest fixture) and for users
 * who self-host a Bitcoin Core node and want full sovereignty.
 *
 * Address watching is done via importdescriptors with a `wpkh(addr(...))`
 * watch-only descriptor on first sight, then scantxoutset / getrawmempool +
 * listsinceblock to enumerate inbound payments.
 */
final class BitcoindRpcProvider implements BlockchainProvider {
    public function __construct(
        private readonly string $rpcUrl,         // http://user:pass@host:port/
        private readonly int $timeoutSec = 15,
    ) {}

    public function addressTransactions(string $address, ?int $sinceHeight = null): array {
        // $sinceHeight is unused here: listreceivedbyaddress already returns the
        // full history for the watched address in one call (no pagination).
        // Make sure the wallet is watching the address (idempotent).
        $this->ensureWatchAddress($address);

        $tip = $this->currentTipHeight();
        $out = [];

        // Confirmed outputs paying $address (scantxoutset gives us UTXOs only;
        // for full history we use listreceivedbyaddress with includeempty=false).
        $entries = $this->rpc('listreceivedbyaddress', [0, true, true, $address]);
        foreach ($entries as $entry) {
            if (($entry['address'] ?? null) !== $address) {
                continue;
            }
            $txids = $entry['txids'] ?? [];
            foreach ($txids as $txid) {
                $tx = $this->rpc('gettransaction', [$txid, true]);
                $details = $tx['details'] ?? [];
                $blockHeight = $tx['blockheight'] ?? null;
                $confs = (int)($tx['confirmations'] ?? 0);
                if ($confs < 0) {
                    // Negative confirmations mean the tx was double-spent / reorged out.
                    continue;
                }
                foreach ($details as $d) {
                    if (($d['address'] ?? null) !== $address) {
                        continue;
                    }
                    if (($d['category'] ?? null) !== 'receive') {
                        continue;
                    }
                    $out[] = new OnchainTxObservation(
                        txid: $txid,
                        vout: (int)($d['vout'] ?? 0),
                        amountSat: (int)round(((float)$d['amount']) * 100_000_000),
                        confirmations: $confs,
                        blockHeight: $confs > 0 ? $blockHeight : null,
                    );
                }
            }
        }
        return $out;
    }

    public function currentTipHeight(): int {
        return (int)$this->rpc('getblockcount', []);
    }

    private function ensureWatchAddress(string $address): void {
        try {
            // timestamp='now' avoids a full rescan: cashupayserver only cares
            // about payments made AFTER the address is allocated for an
            // invoice, and full rescans are slow on real mainnet wallets.
            $descIn = ['desc' => "addr({$address})", 'timestamp' => 'now', 'label' => 'cashupay'];
            $info = $this->rpc('getdescriptorinfo', [$descIn['desc']]);
            $descIn['desc'] = $info['descriptor'];
            $this->rpc('importdescriptors', [[$descIn]]);
        } catch (Throwable $e) {
            // Some node configs may disallow importdescriptors (no watch-only
            // wallet available); log so operators can diagnose, but don't
            // crash — the caller will still see whatever the wallet tracks.
            error_log("BitcoindRpcProvider::ensureWatchAddress({$address}): " . $e->getMessage());
        }
    }

    public function rpc(string $method, array $params): mixed {
        $body = json_encode([
            'jsonrpc' => '1.0',
            'id' => 'cashupay',
            'method' => $method,
            'params' => $params,
        ]);
        // bitcoind RPC almost always points at localhost; the URL is
        // admin-configured via the store record.
        $result = \SafeHttp::request($this->rpcUrl, [
            'method' => 'POST',
            'body' => $body,
            'timeout' => $this->timeoutSec,
            'connectTimeout' => 5,
            'headers' => ['Content-Type: application/json'],
            'allowPrivate' => \SafeHttp::privateEndpointsAllowed(),
        ]);
        if ($result['error'] !== '') {
            throw new RuntimeException("bitcoind RPC {$method} failed: {$result['error']}");
        }
        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("bitcoind RPC {$method}: malformed JSON");
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException("bitcoind RPC {$method}: " . json_encode($decoded['error']));
        }
        if ($result['status'] >= 400 && empty($decoded['result'])) {
            throw new RuntimeException("bitcoind RPC {$method}: HTTP {$result['status']}");
        }
        return $decoded['result'];
    }
}

/**
 * Build the configured provider for a store.
 */
final class OnchainProviderFactory {
    /**
     * Test seam: when set, forStore() returns this provider instead of building
     * a real network-backed one. Production code never assigns it (null), so the
     * normal path is unchanged. Tests use it to drive pollInvoice() with a
     * scripted set of observations (e.g. to exercise reorg reconciliation)
     * without contacting Esplora/bitcoind.
     */
    public static ?BlockchainProvider $testProvider = null;

    public static function forStore(array $store): BlockchainProvider {
        if (self::$testProvider !== null) {
            return self::$testProvider;
        }
        $kind = $store['onchain_provider'] ?? 'esplora';
        $network = $store['onchain_network'] ?? 'mainnet';
        $url = $store['onchain_provider_url'] ?: null;

        if ($kind === 'bitcoind' || $kind === 'bitcoind-rpc') {
            if (!$url) {
                throw new RuntimeException('bitcoind provider requires onchain_provider_url');
            }
            return new BitcoindRpcProvider($url);
        }

        // Default: Esplora HTTP.
        if (!$url) {
            $url = EsploraProvider::defaultUrlForNetwork($network);
            if (!$url) {
                throw new RuntimeException(
                    "No default Esplora URL for network '{$network}'. "
                    . "Set onchain_provider_url in the store config."
                );
            }
        }
        return new EsploraProvider($url);
    }
}
