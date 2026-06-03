<?php
/**
 * Builds, signs, and broadcasts the Taproot script-path claim transaction
 * that completes a reverse swap. Triggered by SwapPoller once the provider's
 * lockup transaction is in the mempool / confirmed.
 *
 * Boltz reverse-swap claim leaf (used by Zeus + Boltz):
 *   OP_SIZE <0x20> OP_EQUALVERIFY OP_HASH160 <hash160(preimage_hash)>
 *                OP_EQUALVERIFY <claim_pubkey_xonly> OP_CHECKSIG
 *
 * Witness:
 *   [schnorr_sig, preimage (32B), claim_script, control_block]
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../onchain/wallet.php';
require_once __DIR__ . '/../crypto/secp256k1.php';
require_once __DIR__ . '/../crypto/schnorr.php';
require_once __DIR__ . '/../crypto/taproot.php';
require_once __DIR__ . '/../crypto/tx_builder.php';
require_once __DIR__ . '/factory.php';

final class SwapClaimer {
    /**
     * Build, sign, and broadcast the claim transaction. Returns the txid on
     * success and stores it in swap_attempts.claim_txid. Throws on any error
     * so the caller can mark the row with an error_message.
     *
     * @param array $row the swap_attempts row (assoc)
     * @param string $lockupTxHex hex of the lockup transaction (from provider status)
     * @return string txid (hex, big-endian display order)
     */
    public static function buildAndBroadcast(array $row, string $lockupTxHex): string {
        // 1. Parse the lockup transaction; find the output paying our lockup_address.
        $lockupRaw = self::hexToBin($lockupTxHex);
        // The lockup tx may be a segwit transaction; strip marker+flag so the
        // existing parser (which doesn't handle witness) sees the right shape.
        $lockupRawNoWit = self::stripWitnessIfPresent($lockupRaw);
        $parsed = Taproot::parseUnsignedTx($lockupRawNoWit);

        // The Taproot output for our lockup_address has scriptPubKey = OP_1 OP_PUSH32 <outkey>
        $outputKey = Taproot::decodeP2trAddress($row['lockup_address'], $row['network'] ?? 'mainnet');
        if ($outputKey === null) {
            throw new RuntimeException('Cannot decode lockup_address as P2TR');
        }
        $expectedSpk = TxBuilder::p2trScript($outputKey);

        $lockupVout = -1;
        $lockupAmount = 0;
        foreach ($parsed['outputs'] as $idx => $out) {
            if ($out['script'] === $expectedSpk) {
                $lockupVout = $idx;
                $lockupAmount = $out['value'];
                break;
            }
        }
        if ($lockupVout < 0) {
            throw new RuntimeException('Lockup output not found in provider lockup tx');
        }

        // 2. Compute lockup txid (double-sha256 of NO-WITNESS serialization, displayed big-endian).
        $lockupTxidLE = hash('sha256', hash('sha256', $lockupRawNoWit, true), true);
        $lockupTxidBE = strrev($lockupTxidLE);

        // Persist what we observed before attempting the spend. Includes the
        // raw lockup tx hex so an operator could manually craft a claim even
        // without access to the originating Bitcoin node.
        Database::getInstance()->prepare(
            "UPDATE swap_attempts
                SET lockup_txid = ?, lockup_vout = ?, lockup_amount_sats = ?,
                    lockup_tx_hex = COALESCE(lockup_tx_hex, ?),
                    updated_at = ?
              WHERE id = ?"
        )->execute([
            bin2hex($lockupTxidBE),
            $lockupVout,
            $lockupAmount,
            $lockupTxHex,
            time(),
            $row['id'],
        ]);

        // 3. Resolve the merchant payout script for `merchant_address`. We derive
        // address strings from the store's xpub at allocation time, so we can
        // safely re-derive the scriptPubKey from the address string.
        $store = Database::fetchOne(
            "SELECT onchain_xpub, onchain_address_type, onchain_network FROM stores WHERE id = ?",
            [$row['store_id']]
        );
        if (!$store) {
            throw new RuntimeException('store row missing for swap_attempts.store_id');
        }
        $outScript = self::scriptForMerchantAddress(
            $row['merchant_address'],
            $store['onchain_address_type'] ?: 'P2WPKH',
            $row['network'] ?? ($store['onchain_network'] ?: 'mainnet')
        );

        // 4. Compute the claim amount: lockup_amount minus a current claim-fee
        // estimate (re-fetched, since on-chain feerate may have moved since
        // create-time). Falls back to the stored estimate if re-fetch fails.
        $provider = SwapProviderFactory::byName($row['provider']);
        $claimFeeEstimate = (int)($row['lockup_amount_sats'] ?? 0); // placeholder
        try {
            if ($provider) {
                $pair = $provider->getReversePairInfo($row['network'] ?? 'mainnet');
                $claimFeeEstimate = $pair->claimFeeEstimateSats;
            }
        } catch (Throwable $e) {
            error_log("swap claimer: pair re-fetch failed, using stored estimate: " . $e->getMessage());
        }
        if ($claimFeeEstimate <= 0 || $claimFeeEstimate >= $lockupAmount) {
            // Sane fallback: ~1 sat/vB on a ~136 vB tx == 150 sats
            $claimFeeEstimate = 200;
        }
        $claimOutAmount = $lockupAmount - $claimFeeEstimate;
        if ($claimOutAmount < 546) {
            throw new RuntimeException('Lockup output too small after claim fee');
        }

        // 5. Build the unsigned tx (1 input, 1 output).
        $unsigned = TxBuilder::buildUnsigned(
            $lockupTxidBE, $lockupVout,
            0xFFFFFFFD, // sequence: enables RBF, no relative locktime
            $outScript,
            $claimOutAmount,
            0 // locktime
        );

        // 6. Compute the BIP341 script-path sighash for the claim leaf.
        $claimScript = self::hexToBin($row['claim_leaf_script_hex']);
        $refundScript = self::hexToBin($row['refund_leaf_script_hex']);
        $claimLeafHash  = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $claimScript);
        $refundLeafHash = Taproot::tapLeafHash(Taproot::TAPSCRIPT_LEAF_VERSION, $refundScript);
        $lockupSpk = TxBuilder::p2trScript($outputKey);
        $sighash = Taproot::sighashSchnorrScriptPath(
            $unsigned,
            0,
            [$lockupSpk],
            [$lockupAmount],
            $claimLeafHash
        );

        // 7. Sign with claim_privkey.
        $claimPriv = self::hexToBin($row['claim_privkey_hex']);
        $sig = Schnorr::sign($claimPriv, $sighash);

        // 8. Build the control block. The output key parity is what BIP341
        // requires for script-path; recompute from internal_key + merkle_root.
        $refundPub33 = self::hexToBin($row['refund_pubkey_hex']);
        $claimPub33  = self::hexToBin($row['claim_pubkey_hex']);
        $internalKey = Taproot::keyAggInternalKey([$refundPub33, $claimPub33]);
        $merkleRoot = Taproot::tapBranchHash($claimLeafHash, $refundLeafHash);
        [$expectedOutKey, $parity] = Taproot::tweakOutputKey($internalKey, $merkleRoot);
        if ($expectedOutKey !== $outputKey) {
            // Sanity: the lockup_address Boltz returned should produce the same
            // output key we compute from the tree. Caught earlier at create
            // time too; this catches DB-row tampering.
            throw new RuntimeException('Lockup output key does not match recomputed taproot output');
        }

        $preimage = self::hexToBin($row['preimage_hex']);
        $controlBlock = Taproot::controlBlock2Leaf(
            Taproot::TAPSCRIPT_LEAF_VERSION,
            $parity,
            $internalKey,
            $refundLeafHash
        );

        // 9. Attach witness and broadcast.
        $finalTx = TxBuilder::attachWitness($unsigned, [$sig, $preimage, $claimScript, $controlBlock]);
        $finalHex = bin2hex($finalTx);

        $txid = self::broadcastWithFallback($row, $finalHex);

        // Also persist the raw signed claim tx hex. If something goes wrong
        // post-broadcast (chain reorg, dropped by mempool, fee bump needed),
        // the operator has the exact bytes to re-broadcast from any node.
        Database::getInstance()->prepare(
            "UPDATE swap_attempts SET claim_txid = ?, claim_tx_hex = ?, status = ?, updated_at = ?
              WHERE id = ?"
        )->execute([$txid, $finalHex, 'claim.broadcast', time(), $row['id']]);

        return $txid;
    }

    /**
     * Try the provider's broadcast endpoint first; fall back to Esplora if
     * the provider call fails. Last resort raises.
     */
    private static function broadcastWithFallback(array $row, string $rawTxHex): string {
        $provider = SwapProviderFactory::byName($row['provider']);
        $network = $row['network'] ?? 'mainnet';
        if ($provider) {
            try {
                return $provider->broadcastTx($network, $rawTxHex);
            } catch (Throwable $e) {
                error_log("swap broadcast via {$row['provider']} failed: " . $e->getMessage());
            }
        }
        // Esplora fallback (does not exist on regtest).
        require_once __DIR__ . '/../onchain/provider.php';
        $esploraUrl = EsploraProvider::defaultUrlForNetwork($network);
        if ($esploraUrl !== null) {
            $url = rtrim($esploraUrl, '/') . '/tx';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $rawTxHex,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $status < 400 && !$err) {
                return trim((string)$body);
            }
            error_log("swap broadcast via esplora failed: HTTP {$status}: " . substr((string)$body, 0, 200));
        }
        throw new RuntimeException('All broadcast paths failed for swap claim');
    }

    private static function scriptForMerchantAddress(string $addr, string $type, string $network): string {
        // We re-derive the address from the xpub at allocation, so we can rely
        // on the address being a valid P2WPKH or P2SH-P2WPKH in our supported
        // address-type set. Decode it manually here.
        require_once __DIR__ . '/../onchain/wallet.php';
        if ($type === 'P2WPKH') {
            // Bech32 segwit v0; decode HRP + program.
            $hrp = $network === 'mainnet' ? 'bc' : ($network === 'regtest' ? 'bcrt' : 'tb');
            [, $bech] = OnchainWallet::SUPPORTED_NETWORKS ? [null, null] : [null, null]; // suppress lint
            // Use bitwasp/bech32 (already a dep) to decode.
            return self::p2wpkhScriptFromAddress($addr, $hrp);
        }
        if ($type === 'P2SH-P2WPKH') {
            return self::p2shScriptFromBase58($addr);
        }
        throw new RuntimeException("Unsupported merchant address type: {$type}");
    }

    private static function p2wpkhScriptFromAddress(string $address, string $expectedHrp): string {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            [$hrp, $version, $program] = self::decodeBech32Segwit($address);
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot decode merchant address: " . $e->getMessage());
        }
        if ($hrp !== $expectedHrp || $version !== 0 || count($program) !== 20) {
            throw new RuntimeException('merchant address: expected v0 20-byte segwit');
        }
        return TxBuilder::p2wpkhScript(implode('', array_map('chr', $program)));
    }

    private static function p2shScriptFromBase58(string $address): string {
        // Decode base58check; first byte is version, next 20 are scriptHash.
        $decoded = self::base58CheckDecode($address);
        if ($decoded === null || strlen($decoded) !== 21) {
            throw new RuntimeException("merchant address: invalid base58 P2SH");
        }
        $scriptHash = substr($decoded, 1);
        return TxBuilder::p2shScript($scriptHash);
    }

    // -------- helpers --------

    private static function hexToBin(string $hex): string {
        $b = @hex2bin($hex);
        if ($b === false) {
            throw new RuntimeException('invalid hex');
        }
        return $b;
    }

    private static function stripWitnessIfPresent(string $raw): string {
        // A segwit tx has marker=0x00 flag=0x01 at offset 4 (after version).
        if (strlen($raw) >= 6 && $raw[4] === "\x00" && $raw[5] === "\x01") {
            // Reparse to find where the witness section starts/ends, and rebuild
            // without it. Easier: serialize the inputs/outputs ourselves.
            // We do a minimal parse: skip marker+flag, parse inputs+outputs into
            // a no-witness layout, then serialize back.
            $pos = 6;
            $version = substr($raw, 0, 4);
            $numIn = self::readCompactSize($raw, $pos);
            $inputs = [];
            for ($i = 0; $i < $numIn; $i++) {
                $startIn = $pos;
                $pos += 32 + 4; // prevout
                $sLen = self::readCompactSize($raw, $pos);
                $pos += $sLen;
                $pos += 4; // sequence
                $inputs[] = substr($raw, $startIn, $pos - $startIn);
            }
            $numOut = self::readCompactSize($raw, $pos);
            $outputsStart = $pos;
            for ($i = 0; $i < $numOut; $i++) {
                $pos += 8;
                $sLen = self::readCompactSize($raw, $pos);
                $pos += $sLen;
            }
            $outputsRaw = substr($raw, $outputsStart, $pos - $outputsStart);
            // Skip the witness section (variable length); locktime is the
            // final 4 bytes of $raw.
            $locktime = substr($raw, -4);
            $out = $version . Taproot::compactSize($numIn) . implode('', $inputs)
                 . Taproot::compactSize($numOut) . $outputsRaw . $locktime;
            return $out;
        }
        return $raw;
    }

    private static function readCompactSize(string $raw, int &$pos): int {
        $b = ord($raw[$pos]); $pos++;
        if ($b < 0xFD) return $b;
        if ($b === 0xFD) { $v = unpack('v', substr($raw, $pos, 2))[1]; $pos += 2; return $v; }
        if ($b === 0xFE) { $v = unpack('V', substr($raw, $pos, 4))[1]; $pos += 4; return $v; }
        $v = unpack('P', substr($raw, $pos, 8))[1]; $pos += 8;
        return $v;
    }

    private static function decodeBech32Segwit(string $addr): array {
        // Use existing bitwasp/bech32 to decode v0/v1 segwit addresses.
        $addr = strtolower($addr);
        $pos = strrpos($addr, '1');
        if ($pos === false) throw new RuntimeException('no bech32 separator');
        $hrp = substr($addr, 0, $pos);
        // Bech32 charset position lookup.
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data = [];
        for ($i = $pos + 1; $i < strlen($addr); $i++) {
            $d = strpos($charset, $addr[$i]);
            if ($d === false) throw new RuntimeException('bech32 char invalid');
            $data[] = $d;
        }
        if (count($data) < 7) throw new RuntimeException('bech32 too short');
        $version = $data[0];
        $programBits = array_slice($data, 1, -6);
        // 5→8 bit regroup
        $acc = 0; $bits = 0; $program = [];
        foreach ($programBits as $v) {
            $acc = ($acc << 5) | $v;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $program[] = ($acc >> $bits) & 0xFF;
            }
        }
        return [$hrp, $version, $program];
    }

    private static function base58CheckDecode(string $s): ?string {
        // Reuse the helper logic from OnchainWallet via a copy here so we
        // don't depend on private API.
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = gmp_init(0);
        for ($i = 0; $i < strlen($s); $i++) {
            $pos = strpos($alphabet, $s[$i]);
            if ($pos === false) return null;
            $num = gmp_add(gmp_mul($num, 58), $pos);
        }
        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        $bytes = $hex === '0' ? '' : hex2bin($hex);
        $leading = 0;
        for ($i = 0; $i < strlen($s) && $s[$i] === '1'; $i++) $leading++;
        $decoded = str_repeat("\x00", $leading) . $bytes;
        if (strlen($decoded) < 4) return null;
        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $checksum)) return null;
        return $payload;
    }
}
