<?php
/**
 * CashuPayServer - Chain descriptor for the pay-to-address rails.
 *
 * The on-chain engine (OnchainPayments, EsploraProvider, the admin card, the
 * payment page) is written once and parameterised by a Chain: which
 * invoices/stores columns hold the rail's state, which table records the
 * observed transactions, how amounts and payment URIs are spelled, and which
 * public explorers are the defaults.
 *
 * Two chains exist:
 *
 *   btc  Bitcoin. The historical rail; its column names are the legacy
 *        onchain_* names so nothing about existing installs changes.
 *   ecx  eCash (ECX, ecash.com) - the 2026 Drivechain hard fork of Bitcoin
 *        at block 973,728. Addresses, xpubs and the bech32 HRP are
 *        byte-identical to Bitcoin's (coin type 0'), so address derivation
 *        is shared verbatim with the Bitcoin rail; only the chain being
 *        watched, the explorer, the unit names ("szats") and the payment URI
 *        scheme differ. It is NOT eCash XEC (e.cash), which is an unrelated
 *        Bitcoin Cash fork with CashAddr addresses.
 *
 * Because an address is the same string on both chains, the per-xpub
 * derivation counter (onchain_xpub_state) is deliberately shared between the
 * rails: any one address is ever shown for exactly one invoice on exactly one
 * chain, so a transaction replayed from one chain onto the other (unsplit
 * post-fork coins) can only ever land on the invoice that address belongs to.
 */

final class Chain {
    public const BTC = 'btc';
    public const ECX = 'ecx';

    /** ECX ships as a single mainnet; the store column is fixed to this. */
    public const ECX_NETWORK = 'mainnet';

    /** @var array<string, Chain> */
    private static array $instances = [];

    private function __construct(
        /** 'btc' | 'ecx' */
        public readonly string $id,
        /** Ticker shown next to coin amounts. */
        public readonly string $ticker,
        /** Smallest unit, singular / plural, as shown to payers and operators. */
        public readonly string $unit,
        public readonly string $unitPlural,
        /** Human label for the rail ("On-chain", "eCash"). */
        public readonly string $label,
        /** BIP21-style URI scheme the QR / open-in-wallet link uses. */
        public readonly string $uriScheme,
        /** invoices.settled_rail / payment_rail value and the logical rail name. */
        public readonly string $rail,
        /** Greenfield payment-method key. */
        public readonly string $apiMethod,
        /** Table holding observed (txid, vout) outputs for this chain. */
        public readonly string $paymentsTable,
        /** Prefix for the observation row ids. */
        public readonly string $paymentIdPrefix,
        /** InvoiceAttempts rail name used by the engine's attempt-log rows. */
        public readonly string $attemptRail,
        /** AdminLog category. */
        public readonly string $logCategory,
        /** invoices-table column map. */
        public readonly array $inv,
        /** stores-table column map. */
        public readonly array $store,
        /** Public web explorer base URLs (for human tx links), per network. */
        private readonly array $txUrlBases,
        /** Built-in Esplora API host chain, per network; null = none. */
        private readonly array $esploraDefaults,
        /** Environment variable that overrides $esploraDefaults in test rigs. */
        public readonly string $esploraOverrideEnv,
    ) {}

    public static function btc(): self {
        return self::$instances[self::BTC] ??= new self(
            id: self::BTC,
            ticker: 'BTC',
            unit: 'sat',
            unitPlural: 'sats',
            label: 'On-chain',
            uriScheme: 'bitcoin',
            rail: 'onchain',
            apiMethod: 'BTC-OnChain',
            paymentsTable: 'onchain_payments',
            paymentIdPrefix: 'och',
            attemptRail: 'onchain',
            logCategory: 'onchain',
            inv: [
                'address'       => 'onchain_address',
                'index'         => 'onchain_address_index',
                'amount'        => 'onchain_amount_sat',
                'tweak'         => 'onchain_amount_tweak_sats',
                'first_seen'    => 'onchain_first_seen_at',
                'created_tip'   => 'onchain_created_tip_height',
                'needs_manual'  => 'onchain_needs_manual_confirmation',
                'manual'        => 'onchain_manual_candidates',
                'last_polled'   => 'onchain_last_polled_at',
                'floor'         => 'onchain_amount_floor_sat',
                // Earlier expected totals of a static shared address that are
                // still attributable (JSON int list; cash back rewards moved
                // the amount). See OnchainPayments::reallocateStaticAmount.
                'alternates'    => 'onchain_amount_alternates',
                // Exchange rate the amount was quoted at (coin price in the
                // invoice currency); the Bitcoin rail shares the invoice's.
                'rate'          => 'exchange_rate',
            ],
            store: [
                'xpub'              => 'onchain_xpub',
                'mode'              => 'onchain_address_mode',
                'static'            => 'onchain_static_address',
                'tweak_range'       => 'onchain_static_tweak_range',
                'type'              => 'onchain_address_type',
                'network'           => 'onchain_network',
                'min_confs'         => 'onchain_min_confs',
                'timeout'           => 'onchain_confirm_timeout_sec',
                'provider'          => 'onchain_provider',
                'provider_url'      => 'onchain_provider_url',
                'provider_fallback' => 'onchain_provider_fallback',
                'next_index'        => 'onchain_next_index',
            ],
            txUrlBases: [
                'mainnet' => 'https://mempool.space',
                'testnet' => 'https://mempool.space/testnet',
                'signet'  => 'https://mempool.space/signet',
                'regtest' => 'https://mempool.space',
            ],
            esploraDefaults: [
                'mainnet' => ['https://mempool.space/api', 'https://blockstream.info/api'],
                'testnet' => ['https://mempool.space/testnet/api', 'https://blockstream.info/testnet/api'],
                // blockstream.info has no public signet instance.
                'signet'  => ['https://mempool.space/signet/api'],
                // No public regtest API exists; users must self-host or use BitcoindRpcProvider.
                'regtest' => null,
            ],
            esploraOverrideEnv: 'CASHUPAY_ESPLORA_DEFAULT_URLS',
        );
    }

    public static function ecx(): self {
        return self::$instances[self::ECX] ??= new self(
            id: self::ECX,
            ticker: 'ECX',
            unit: 'szat',
            unitPlural: 'szats',
            label: 'eCash',
            // The official eCash.com mobile wallet registers `ecash:` (and
            // treats `bitcoin:` strictly as Bitcoin, so a bitcoin: QR would
            // never offer an ECX wallet). Electrum-ECX uses `ecx:`; the bare
            // address stays copyable for it.
            uriScheme: 'ecash',
            rail: 'ecx',
            apiMethod: 'ECX-OnChain',
            paymentsTable: 'ecx_payments',
            paymentIdPrefix: 'ecxp',
            attemptRail: 'ecx',
            logCategory: 'ecx',
            inv: [
                'address'       => 'ecx_address',
                'index'         => 'ecx_address_index',
                'amount'        => 'ecx_amount_szat',
                'tweak'         => 'ecx_amount_tweak_szats',
                'first_seen'    => 'ecx_first_seen_at',
                'created_tip'   => 'ecx_created_tip_height',
                'needs_manual'  => 'ecx_needs_manual_confirmation',
                'manual'        => 'ecx_manual_candidates',
                'last_polled'   => 'ecx_last_polled_at',
                'floor'         => 'ecx_amount_floor_szat',
                'alternates'    => 'ecx_amount_alternates',
                'rate'          => 'ecx_exchange_rate',
            ],
            store: [
                'xpub'              => 'ecx_xpub',
                'mode'              => 'ecx_address_mode',
                'static'            => 'ecx_static_address',
                'tweak_range'       => 'ecx_static_tweak_range',
                'type'              => 'ecx_address_type',
                // ECX has one network; the engine reads this key and the
                // row carries no such column, so the default ('mainnet') is
                // what every read resolves to.
                'network'           => 'ecx_network',
                'min_confs'         => 'ecx_min_confs',
                'timeout'           => 'ecx_confirm_timeout_sec',
                'provider'          => 'ecx_provider',
                'provider_url'      => 'ecx_provider_url',
                'provider_fallback' => 'ecx_provider_fallback',
                'next_index'        => 'ecx_next_index',
            ],
            txUrlBases: [
                'mainnet' => 'https://explorer.ecash.com',
            ],
            esploraDefaults: [
                // explorer.ecash.com serves a full Esplora API under /api;
                // esplora.ecash.ninja is an independently operated second
                // host (no /api prefix) for the same failover rule the
                // Bitcoin rail applies between mempool.space and
                // blockstream.info.
                'mainnet' => ['https://explorer.ecash.com/api', 'https://esplora.ecash.ninja'],
            ],
            esploraOverrideEnv: 'CASHUPAY_ECX_ESPLORA_DEFAULT_URLS',
        );
    }

    /** @return Chain[] */
    public static function all(): array {
        return [self::btc(), self::ecx()];
    }

    public static function get(string $id): self {
        return match ($id) {
            self::BTC => self::btc(),
            self::ECX => self::ecx(),
            default => throw new InvalidArgumentException("Unknown chain: {$id}"),
        };
    }

    /** Chain by its settled_rail / payment_rail value; null for Lightning rails. */
    public static function forRail(?string $rail): ?self {
        foreach (self::all() as $c) {
            if ($c->rail === $rail) {
                return $c;
            }
        }
        return null;
    }

    public function isBtc(): bool {
        return $this->id === self::BTC;
    }

    /** The store's network for this chain ('mainnet' when the chain has one network). */
    public function network(array $store): string {
        $n = (string)($store[$this->store['network']] ?? '');
        return $n !== '' ? $n : 'mainnet';
    }

    /** Esplora API host chain for a network (primary first); null when the network has none. */
    public function esploraDefaults(string $network): ?array {
        return $this->esploraDefaults[$network] ?? null;
    }

    /** Public explorer link for a transaction on this chain. */
    public function txUrl(string $txid, string $network = 'mainnet'): string {
        $base = $this->txUrlBases[$network] ?? reset($this->txUrlBases);
        return $base . '/tx/' . rawurlencode($txid);
    }

    /** Public explorer link for an address on this chain. */
    public function addressUrl(string $address, string $network = 'mainnet'): string {
        $base = $this->txUrlBases[$network] ?? reset($this->txUrlBases);
        return $base . '/address/' . rawurlencode($address);
    }

    /**
     * Whole-coin spelling of a base-unit amount for BIP21 `amount=` and for
     * display: 8 decimals, trailing zeros trimmed, '0' for zero.
     */
    public function formatCoin(int $base): string {
        $coin = bcdiv((string)max(0, $base), '100000000', 8);
        $coin = rtrim(rtrim($coin, '0'), '.');
        return $coin === '' ? '0' : $coin;
    }

    /** BIP21-style payment URI for an address + base-unit amount. */
    public function paymentUri(string $address, int $amountBase): string {
        return $this->uriScheme . ':' . $address . '?amount=' . $this->formatCoin($amountBase);
    }

    /** "12,345 szats" / "1 sat" style label. */
    public function formatUnits(int $base): string {
        return number_format($base) . ' ' . ($base === 1 ? $this->unit : $this->unitPlural);
    }
}
