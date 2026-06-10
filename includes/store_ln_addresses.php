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

class StoreLnAddresses {
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
     * Full ordered list for a store: [['id'=>int,'address'=>string,
     * 'supports_verify'=>?int], ...] sorted by priority (position ASC).
     */
    public static function listForStore(string $storeId): array {
        $rows = Database::fetchAll(
            "SELECT id, address, supports_verify
               FROM store_ln_addresses
              WHERE store_id = ?
              ORDER BY position ASC, id ASC",
            [$storeId]
        );
        return array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'address' => (string)$r['address'],
                'supports_verify' => $r['supports_verify'] === null ? null : (int)$r['supports_verify'],
            ];
        }, $rows);
    }

    /**
     * Ordered plain address strings for a store (priority first). Convenience
     * for the resolvers that just need to walk the chain.
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
                $address = trim((string)($entry['address'] ?? ''));
                $verify = $entry['supports_verify'] ?? null;
            } else {
                $address = trim((string)$entry);
                $verify = null;
            }
            if ($address === '') {
                continue;
            }
            if (!self::isValid($address)) {
                throw new InvalidArgumentException("Invalid Lightning address: {$address}");
            }
            $key = strtolower($address);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate Lightning address: {$address}");
            }
            $seen[$key] = true;
            $normalized[] = [
                'address' => $address,
                'supports_verify' => $verify === null ? null : (int)$verify,
            ];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM store_ln_addresses WHERE store_id = ?");
            $del->execute([$storeId]);
            $ins = $pdo->prepare(
                "INSERT INTO store_ln_addresses (store_id, position, address, supports_verify)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($normalized as $i => $row) {
                $ins->execute([$storeId, $i, $row['address'], $row['supports_verify']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
