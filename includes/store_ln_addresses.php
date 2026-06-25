<?php
/**
 * CashuPayServer - Store Lightning Address list
 *
 * A store can have multiple Lightning addresses tried in an ordered priority
 * (fallback) chain. They are used for two things:
 *
 *   1. Receiving — Invoice::create walks the list and presents the first
 *      working LNURL-pay invoice (LUD-21 direct-receive), falling through to
 *      the next address when a host is down or can't produce an invoice.
 *   2. Withdrawing — auto-melt (LightningAddress::checkAutoMelt) and the
 *      override-settle-and-forward handler melt to the first address that
 *      accepts the payment.
 *
 * Storage is the store_ln_addresses table, ordered by `position` ASC. This
 * replaced the single stores.auto_melt_address column.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/clink/noffer.php';

class StoreLnAddresses {
    /** Destination types stored in store_ln_addresses.type. */
    public const TYPE_LNADDRESS = 'lnaddress';
    public const TYPE_NOFFER = 'noffer';

    /**
     * Lightning-address shape check (LUD-16: local-part@host.tld). Kept in
     * sync with the regex used by Invoice::create and LnUrlReceive so the
     * admin/setup validators and the runtime resolvers agree on what's valid.
     */
    public static function isValid(string $address): bool {
        return (bool)preg_match(
            '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/',
            trim($address)
        );
    }

    /**
     * Validate a destination value for a given type: LUD-16 for 'lnaddress',
     * a structurally valid noffer for 'noffer'. The single gate both the admin
     * save handler and replaceForStore() use.
     */
    public static function isValidEntry(string $type, string $value): bool {
        $value = trim($value);
        if ($type === self::TYPE_NOFFER) {
            return ClinkNoffer::isValid($value);
        }
        return self::isValid($value);
    }

    /**
     * Build an ordered, validated destination chain from two separate operator
     * lists kept apart in the admin UI: Lightning addresses first, then CLINK
     * noffers as fallback. This is both the order the UI presents (a dedicated
     * noffer section below the lightning-address section) and the order
     * Invoice::create walks at runtime.
     *
     * Each value is trimmed and blanks are dropped; each is validated against
     * its declared type (so a noffer pasted into the address list, or an
     * address pasted into the noffer list, is rejected with a clear message);
     * duplicates across both lists (case-insensitive) throw. Returns
     * [['type'=>string,'value'=>string], ...] in priority order — the shape the
     * save handler's probe loop and replaceForStore() consume.
     */
    public static function chainFromLists(array $lnAddresses, array $noffers): array {
        $typed = [];
        foreach ($lnAddresses as $v) {
            $typed[] = [self::TYPE_LNADDRESS, (string)$v];
        }
        foreach ($noffers as $v) {
            $typed[] = [self::TYPE_NOFFER, (string)$v];
        }
        $out = [];
        $seen = [];
        foreach ($typed as [$type, $raw]) {
            $val = trim($raw);
            if ($val === '') {
                continue;
            }
            if (!self::isValidEntry($type, $val)) {
                $label = $type === self::TYPE_NOFFER ? 'noffer' : 'Lightning address';
                throw new InvalidArgumentException("Invalid {$label} format: {$val}");
            }
            $key = strtolower($val);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate destination: {$val}");
            }
            $seen[$key] = true;
            $out[] = ['type' => $type, 'value' => $val];
        }
        return $out;
    }

    /**
     * Full ordered list for a store: [['id'=>int,'address'=>string,
     * 'type'=>string,'supports_verify'=>?int], ...] sorted by priority
     * (position ASC).
     */
    public static function listForStore(string $storeId): array {
        $rows = Database::fetchAll(
            "SELECT id, address, type, supports_verify
               FROM store_ln_addresses
              WHERE store_id = ?
              ORDER BY position ASC, id ASC",
            [$storeId]
        );
        return array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'address' => (string)$r['address'],
                'type' => (string)($r['type'] ?? self::TYPE_LNADDRESS),
                'supports_verify' => $r['supports_verify'] === null ? null : (int)$r['supports_verify'],
            ];
        }, $rows);
    }

    /**
     * Ordered typed destinations for the resolvers that walk the chain and act
     * per type: [['type'=>string,'value'=>string,'supports_verify'=>?int], ...]
     * in priority order.
     */
    public static function destinationsForStore(string $storeId): array {
        return array_map(static function (array $r): array {
            return [
                'type' => $r['type'],
                'value' => $r['address'],
                'supports_verify' => $r['supports_verify'],
            ];
        }, self::listForStore($storeId));
    }

    /**
     * Ordered plain destination strings for a store (priority first), across
     * both types. Used for capability checks (is there any payout target?) and
     * for representative display in failure notifications.
     */
    public static function addressesForStore(string $storeId): array {
        return array_map(
            static fn(array $r): string => $r['address'],
            self::listForStore($storeId)
        );
    }

    /**
     * The highest-priority (position 0) address, or null when the store has
     * none. Used where a single representative address is needed (e.g. failure
     * notifications).
     */
    public static function primaryForStore(string $storeId): ?string {
        $list = self::addressesForStore($storeId);
        return $list[0] ?? null;
    }

    /**
     * Replace a store's entire ordered list in one transaction. Entries are
     * applied in array order as positions 0..n-1. Each entry is either a plain
     * address string or ['address'=>string, 'supports_verify'=>?int].
     *
     * Blank entries are skipped; duplicates (case-insensitive on the address)
     * are rejected so the fallback chain never contains the same host twice.
     * Invalid addresses throw — callers validate first for nicer messages, but
     * this is the last line of defence.
     */
    public static function replaceForStore(string $storeId, array $entries): void {
        $normalized = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $address = trim((string)($entry['address'] ?? $entry['value'] ?? ''));
                $type = (string)($entry['type'] ?? self::TYPE_LNADDRESS);
                $verify = $entry['supports_verify'] ?? null;
            } else {
                $address = trim((string)$entry);
                $type = self::TYPE_LNADDRESS;
                $verify = null;
            }
            if ($address === '') {
                continue;
            }
            if ($type !== self::TYPE_LNADDRESS && $type !== self::TYPE_NOFFER) {
                throw new InvalidArgumentException("Invalid destination type: {$type}");
            }
            if (!self::isValidEntry($type, $address)) {
                $label = $type === self::TYPE_NOFFER ? 'noffer' : 'Lightning address';
                throw new InvalidArgumentException("Invalid {$label}: {$address}");
            }
            // Dedup case-insensitively across the whole chain so the same host
            // never appears twice. noffer strings are all-lowercase bech32, so
            // case-folding is a no-op for them and exact for addresses.
            $key = strtolower($address);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate destination: {$address}");
            }
            $seen[$key] = true;
            // supports_verify only has meaning for LNURL/LUD-21; force NULL for
            // noffers so the column isn't misread by the receive resolver.
            $normalized[] = [
                'address' => $address,
                'type' => $type,
                'supports_verify' => ($type === self::TYPE_LNADDRESS && $verify !== null)
                    ? (int)$verify : null,
            ];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM store_ln_addresses WHERE store_id = ?");
            $del->execute([$storeId]);
            $ins = $pdo->prepare(
                "INSERT INTO store_ln_addresses (store_id, position, address, type, supports_verify)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($normalized as $i => $row) {
                $ins->execute([$storeId, $i, $row['address'], $row['type'], $row['supports_verify']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
