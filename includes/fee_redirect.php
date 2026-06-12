<?php
/**
 * CashuPayServer — Fee-Redirect Module
 *
 * Decides, at invoice-creation time, whether an invoice's payment should be
 * pointed straight at a fee destination (dev / upstream / hosting) instead of
 * the merchant, and builds the rail destinations when it should.
 *
 * Why: fees are normally accrued and later melted out of the merchant's cashu
 * wallet by the cron (see DevFee::settleStore). That path is kept. This module
 * adds an opportunistic shortcut — when a fee is already owed in an amount at
 * least as large as the incoming invoice, the whole invoice payment is routed
 * to that fee's payee. The merchant gives up this one payment but their fee
 * debt drops by the same amount, so it nets out (the existing cron path would
 * otherwise have melted the equivalent from their wallet).
 *
 * Rules (locked in with the operator):
 *   - GATE (no overpay): a fee is only a candidate when its owed amount is
 *     >= the invoice amount in sats, so the whole payment is fully absorbed by
 *     existing debt and the payee is never overpaid.
 *   - PRECEDENCE: when several fees qualify, the one with the LARGEST owed
 *     amount wins (a single payment can only go to one destination).
 *   - ALL RAILS OR NONE: the chosen fee must be able to cover EVERY rail the
 *     store would otherwise offer (a LUD-21 Lightning address for the lightning
 *     rail, a network-matched xpub for the on-chain rail). If no qualifying fee
 *     covers all of the store's rails, we do NOT redirect — the invoice is
 *     created normally with all rails intact (so we never disable a payment
 *     type or misroute a customer to the merchant on a "fee" invoice).
 *   - Upstream, dev and hosting all participate. Upstream/dev destinations are
 *     global config; hosting's are per-store. A fee with no destination for a
 *     given rail simply can't cover that rail.
 *
 * The lightning rail reuses {@see LnUrlReceive::probeAndFetchInvoice}, which
 * requires a LUD-21 `verify` URL — without it we couldn't detect settlement
 * (we don't run an LN node), exactly as the existing lnaddress receive rail.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dev_fee.php';
require_once __DIR__ . '/lnurl_receive.php';
require_once __DIR__ . '/onchain/payments.php';

class FeeRedirect {
    /**
     * Build the ordered list of redirect-fee candidates for a store, each with
     * its owed amount and configured destinations. Pure assembly from config +
     * the supplied owed math; no network calls. Exposed for unit testing.
     *
     * @param array $owed Result of DevFee::computeOwed (or an equivalent stub).
     * @return array<int, array{key:string, note:string, owed:int, lnurl:string,
     *                          xpub:string, network:string, type:string}>
     */
    public static function candidates(array $store, array $owed): array {
        $list = [
            [
                'key'     => 'upstream',
                'note'    => FEE_NOTE_UPSTREAM,
                'owed'    => (int)($owed['upstream_owed'] ?? 0),
                'lnurl'   => (string)CASHUPAY_UPSTREAM_DEV_FEE_LNURL,
                'xpub'    => (string)CASHUPAY_UPSTREAM_DEV_FEE_ONCHAIN_XPUB,
                'network' => (string)CASHUPAY_UPSTREAM_DEV_FEE_ONCHAIN_NETWORK,
                'type'    => (string)CASHUPAY_UPSTREAM_DEV_FEE_ONCHAIN_ADDRESS_TYPE,
            ],
            [
                'key'     => 'dev',
                'note'    => FEE_NOTE_DEV,
                'owed'    => (int)($owed['dev_owed'] ?? 0),
                'lnurl'   => (string)CASHUPAY_DEV_FEE_LNURL,
                'xpub'    => (string)CASHUPAY_DEV_FEE_ONCHAIN_XPUB,
                'network' => (string)CASHUPAY_DEV_FEE_ONCHAIN_NETWORK,
                'type'    => (string)CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE,
            ],
            [
                'key'     => 'hosting',
                'note'    => FEE_NOTE_HOSTING,
                'owed'    => (int)($owed['hosting_owed'] ?? 0),
                'lnurl'   => (string)($store['hosting_fee_destination'] ?? ''),
                'xpub'    => (string)($store['hosting_fee_onchain_xpub'] ?? ''),
                'network' => (string)($store['hosting_fee_onchain_network'] ?? 'mainnet'),
                'type'    => (string)($store['hosting_fee_onchain_address_type'] ?? 'P2WPKH'),
            ],
        ];

        // Largest owed first (single payment -> single destination).
        usort($list, static fn($a, $b) => $b['owed'] <=> $a['owed']);
        return $list;
    }

    /**
     * Decide whether to redirect this invoice and, if so, build the rail
     * destinations. Returns null to leave the invoice on the normal path.
     *
     * @param string   $storeId
     * @param array    $store        Full stores row (Config::getStore).
     * @param int      $invoiceSats  Invoice amount in sats.
     * @param string[] $offeredRails Subset of ['lightning','onchain'] the store
     *                               can present; the chosen fee must cover all.
     * @return array{
     *   note:string, fee_key:string, destination:string,
     *   lightning:?array{bolt11:string,verify_url:string,amount_sats:int},
     *   onchain:?array{address:string,index:int,tip_height:?int}
     * }|null
     */
    public static function decide(string $storeId, array $store, int $invoiceSats, array $offeredRails): ?array {
        if ($invoiceSats < 1 || empty($offeredRails)) {
            return null;
        }

        $owed = DevFee::computeOwed($storeId);
        $storeNetwork = (string)($store['onchain_network'] ?? 'mainnet');
        // Free trial / no revenue => every owed is 0 => nothing qualifies.
        foreach (self::candidates($store, $owed) as $cand) {
            // GATE: only redirect when the whole invoice is absorbed by debt.
            if ($cand['owed'] < $invoiceSats) {
                continue;
            }
            // COVERAGE: the chosen fee must (by config) be able to cover every
            // rail the store offers — otherwise redirecting would drop a
            // payment type. Cheap static check before any HTTP / derivation.
            if (!self::staticallyCovers($cand, $offeredRails, $storeNetwork)) {
                continue;
            }
            $built = self::buildRails($cand, $store, $invoiceSats, $offeredRails);
            if ($built === null) {
                // This (largest-owed) candidate can't cover every offered
                // rail — fall through to the next, smaller candidate.
                continue;
            }
            error_log(sprintf(
                '[fee-redirect] store=%s fee=%s owed=%d invoice_sats=%d rails=%s',
                $storeId, $cand['key'], $cand['owed'], $invoiceSats, implode('+', $offeredRails)
            ));
            return [
                'note'        => $cand['note'],
                'fee_key'     => $cand['key'],
                'destination' => $built['destination'],
                'lightning'   => $built['lightning'],
                'onchain'     => $built['onchain'],
            ];
        }
        return null;
    }

    /**
     * Pure coverage check: does this candidate have a configured destination
     * for every offered rail? Lightning needs a non-empty LNURL; on-chain
     * needs a non-empty xpub on the SAME network as the paying store (the
     * redirect invoice is polled with the store's provider). No I/O — the
     * runtime confirmation (LNURL reachable, derivation works) happens in
     * buildRails. Exposed for unit testing.
     *
     * @param string[] $offeredRails
     */
    public static function staticallyCovers(array $cand, array $offeredRails, string $storeNetwork): bool {
        if (in_array('lightning', $offeredRails, true) && ($cand['lnurl'] ?? '') === '') {
            return false;
        }
        if (in_array('onchain', $offeredRails, true)) {
            if (($cand['xpub'] ?? '') === '') {
                return false;
            }
            if (($cand['network'] ?? 'mainnet') !== $storeNetwork) {
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to build destinations for every offered rail using one fee
     * candidate. All-or-nothing: returns null if any offered rail can't be
     * covered, so the caller never produces a partially-redirected invoice.
     *
     * @return array{destination:string, lightning:?array, onchain:?array}|null
     */
    private static function buildRails(array $cand, array $store, int $invoiceSats, array $offeredRails): ?array {
        $wantLightning = in_array('lightning', $offeredRails, true);
        $wantOnchain   = in_array('onchain', $offeredRails, true);

        $lightning = null;
        $onchain = null;
        $labels = [];

        if ($wantLightning) {
            if ($cand['lnurl'] === '') {
                return null;
            }
            // Live LUD-21 probe + bolt11 fetch for the exact amount. Null on
            // host down / no verify URL / amount out of LNURL range.
            $probe = LnUrlReceive::probeAndFetchInvoice($cand['lnurl'], $invoiceSats);
            if ($probe === null) {
                return null;
            }
            $lightning = [
                'bolt11'      => $probe['bolt11'],
                'verify_url'  => $probe['verify_url'],
                'amount_sats' => $invoiceSats,
            ];
            $labels[] = $cand['lnurl'];
        }

        if ($wantOnchain) {
            if ($cand['xpub'] === '') {
                return null;
            }
            // The redirect invoice is polled with the PAYING store's on-chain
            // provider, which is bound to the store's network. A fee xpub on a
            // different network would be unwatchable, so require a match.
            $storeNetwork = (string)($store['onchain_network'] ?? 'mainnet');
            if ($cand['network'] !== $storeNetwork) {
                return null;
            }
            try {
                $alloc = OnchainPayments::allocateFeeAddress(
                    $cand['xpub'], $cand['network'], $cand['type'], $store
                );
            } catch (Throwable $e) {
                error_log('[fee-redirect] fee-address derivation failed for '
                    . ($cand['key'] ?? '?') . ': ' . $e->getMessage());
                return null;
            }
            $onchain = [
                'address'    => $alloc['address'],
                'index'      => $alloc['index'],
                'tip_height' => $alloc['tip_height'] ?? null,
            ];
            $labels[] = $alloc['address'];
        }

        if ($lightning === null && $onchain === null) {
            return null;
        }
        return [
            'destination' => implode(' / ', $labels),
            'lightning'   => $lightning,
            'onchain'     => $onchain,
        ];
    }
}
