<?php
/**
 * Tip jars — named public payment links for a store.
 *
 * A tip jar is the unit the public "pay what you want" page (pay.php, reached
 * at /pay/{storeId}/{jarId}) is organised around: the merchant creates any
 * number of jars per store, each with a unique name, an optional emoji and a
 * message shown to the payer ("Thank you for your donation"). Invoices created
 * through a jar carry its id (invoices.tip_jar_id) so the admin Tip Jars view
 * can show how much each one has received.
 *
 * The store-level master switch and per-payment cap still live in
 * includes/selfserve.php (stores.selfserve_enabled / selfserve_max_sats — the
 * column names predate the "tip jar" name and are kept for compatibility).
 * A jar is reachable when the master switch is on for its store AND the jar
 * itself is enabled. The legacy single link (/pay/{storeId}, no jar) redirects
 * to the store's oldest enabled jar — the one the upgrade migration created
 * for stores that already had the feature on — so links shared before the
 * rename keep working.
 *
 * Management (create/update/delete) is admin-only; listing is available to
 * any logged-in user. Auth is enforced by the callers in admin.php, not here.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

class TipJar {
    public const MAX_NAME_LEN = 60;
    public const MAX_MESSAGE_LEN = 200;
    /** Shown when a jar has no emoji of its own. */
    public const DEFAULT_EMOJI = "\u{1FAD9}"; // 🫙
    /** Name of the jar the upgrade migration / master-switch save creates. */
    public const DEFAULT_NAME = 'Tip Jar';

    // ---------------- Validation ----------------

    /** Trim + length-check a jar name. Throws on empty / too long. */
    public static function normalizeName($raw): string {
        $name = trim((string)$raw);
        // Collapse control characters (a name is a single line).
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ($name === '') {
            throw new InvalidArgumentException('Name is required');
        }
        if (mb_strlen($name) > self::MAX_NAME_LEN) {
            throw new InvalidArgumentException('Name is too long (max ' . self::MAX_NAME_LEN . ' characters)');
        }
        return $name;
    }

    /** Normalize the payer-facing message. Empty is allowed (returns ''). */
    public static function normalizeMessage($raw): string {
        $msg = (string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', (string)$raw);
        $msg = trim($msg);
        if (mb_strlen($msg) > self::MAX_MESSAGE_LEN) {
            throw new InvalidArgumentException('Message is too long (max ' . self::MAX_MESSAGE_LEN . ' characters)');
        }
        return $msg;
    }

    /**
     * Normalize an emoji. Empty → null (the default emoji is shown). Mirrors
     * Product::normalizeImage's emoji branch: multi-codepoint sequences (ZWJ,
     * skin tones) are fine, but the value is length-capped so the column
     * can't hold arbitrary text, and control characters are rejected.
     */
    public static function normalizeEmoji($raw): ?string {
        $emoji = trim((string)$raw);
        if ($emoji === '') {
            return null;
        }
        if (strlen($emoji) > 32) {
            throw new InvalidArgumentException('Emoji is too long');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $emoji)) {
            throw new InvalidArgumentException('Invalid emoji');
        }
        return $emoji;
    }

    /** Is $name already used by another jar of this store (case-insensitive)? */
    private static function nameTaken(string $storeId, string $name, ?string $exceptId = null): bool {
        $sql = 'SELECT id FROM tip_jars WHERE store_id = ? AND name = ? COLLATE NOCASE';
        $params = [$storeId, $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return Database::fetchOne($sql . ' LIMIT 1', $params) !== null;
    }

    // ---------------- CRUD ----------------

    public static function create(string $storeId, array $d): array {
        if (Config::getStore($storeId) === null) {
            throw new InvalidArgumentException('Unknown store');
        }
        $name = self::normalizeName($d['name'] ?? '');
        if (self::nameTaken($storeId, $name)) {
            throw new InvalidArgumentException('A tip jar with that name already exists');
        }
        $emoji = self::normalizeEmoji($d['emoji'] ?? '');
        $message = self::normalizeMessage($d['message'] ?? '');

        $id = 'jar_' . bin2hex(random_bytes(12));
        $now = time();
        Database::insert('tip_jars', [
            'id' => $id,
            'store_id' => $storeId,
            'name' => $name,
            'emoji' => $emoji,
            'message' => $message,
            'enabled' => array_key_exists('enabled', $d) ? ($d['enabled'] ? 1 : 0) : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::get($id, $storeId);
    }

    public static function update(string $id, string $storeId, array $d): array {
        $existing = self::get($id, $storeId);
        if ($existing === null) {
            throw new InvalidArgumentException('Tip jar not found');
        }
        $updates = ['updated_at' => time()];
        if (array_key_exists('name', $d)) {
            $name = self::normalizeName($d['name']);
            if (self::nameTaken($storeId, $name, $id)) {
                throw new InvalidArgumentException('A tip jar with that name already exists');
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('emoji', $d)) {
            $updates['emoji'] = self::normalizeEmoji($d['emoji']);
        }
        if (array_key_exists('message', $d)) {
            $updates['message'] = self::normalizeMessage($d['message']);
        }
        if (array_key_exists('enabled', $d)) {
            $updates['enabled'] = $d['enabled'] ? 1 : 0;
        }
        Database::update('tip_jars', $updates, 'id = ? AND store_id = ?', [$id, $storeId]);
        return self::get($id, $storeId);
    }

    /**
     * Delete a jar. Its invoices survive: the reference is nulled here (the
     * column carries no FK — see the migration note in database.php) and the
     * jar's name/emoji/message live on in each invoice's metadata snapshot, so
     * the invoice detail still says where a payment came from.
     */
    public static function delete(string $id, string $storeId): void {
        $existing = self::get($id, $storeId);
        if ($existing === null) {
            return;
        }
        Database::query('UPDATE invoices SET tip_jar_id = NULL WHERE tip_jar_id = ? AND store_id = ?', [$id, $storeId]);
        Database::delete('tip_jars', 'id = ? AND store_id = ?', [$id, $storeId]);
    }

    public static function get(string $id, string $storeId): ?array {
        return Database::fetchOne(
            'SELECT * FROM tip_jars WHERE id = ? AND store_id = ?',
            [$id, $storeId]
        );
    }

    /**
     * Creation order: created_at is whole seconds, so two jars made in the
     * same second tie — rowid (insertion order; VACUUM renumbers but keeps
     * the relative order) breaks the tie deterministically instead of the
     * random hex id.
     */
    private const ORDER = 'created_at ASC, rowid ASC';

    /** Oldest first — the migration's default jar, then jars in creation order. */
    public static function listByStore(string $storeId, bool $onlyEnabled = false): array {
        $sql = 'SELECT * FROM tip_jars WHERE store_id = ?';
        if ($onlyEnabled) {
            $sql .= ' AND enabled = 1';
        }
        return Database::fetchAll($sql . ' ORDER BY ' . self::ORDER, [$storeId]);
    }

    public static function countForStore(string $storeId): int {
        $r = Database::fetchOne('SELECT COUNT(*) AS c FROM tip_jars WHERE store_id = ?', [$storeId]);
        return (int)($r['c'] ?? 0);
    }

    /**
     * The jar the legacy /pay/{storeId} link lands on: the store's oldest
     * enabled jar, or null when there is none (the caller 404s).
     */
    public static function legacyDefault(string $storeId): ?array {
        return Database::fetchOne(
            'SELECT * FROM tip_jars WHERE store_id = ? AND enabled = 1 ORDER BY ' . self::ORDER . ' LIMIT 1',
            [$storeId]
        );
    }

    /**
     * Make sure a store that has tip jars turned on owns at least one jar, so
     * the master switch always produces a working link (the pre-rename
     * behaviour). Returns the jar created, or null when one already existed.
     * Used by the upgrade migration and the store-settings save.
     */
    public static function ensureDefault(string $storeId): ?array {
        if (self::countForStore($storeId) > 0) {
            return null;
        }
        return self::create($storeId, ['name' => self::DEFAULT_NAME, 'message' => '']);
    }

    // ---------------- Totals ----------------

    /**
     * Settled totals per jar for a store: [jarId => ['sats' => int, 'count' => int]].
     * Only paid (Settled) invoices count; a jar with no payments is absent.
     */
    public static function receivedByStore(string $storeId): array {
        $out = [];
        foreach (Database::fetchAll(
            "SELECT tip_jar_id, COALESCE(SUM(amount_sats), 0) AS sats, COUNT(*) AS n
               FROM invoices
              WHERE store_id = ? AND tip_jar_id IS NOT NULL AND status = 'Settled'
              GROUP BY tip_jar_id",
            [$storeId]
        ) as $r) {
            $out[(string)$r['tip_jar_id']] = ['sats' => (int)$r['sats'], 'count' => (int)$r['n']];
        }
        return $out;
    }

    // ---------------- Invoice metadata snapshot ----------------

    /**
     * The metadata keys an invoice created through a jar carries. The live
     * row is preferred wherever it still exists (edits to the message show on
     * open invoices); this snapshot is the fallback once a jar is deleted.
     */
    public static function metadataSnapshot(array $jar): array {
        return [
            'tipJarId' => $jar['id'],
            'tipJarName' => $jar['name'],
            'tipJarEmoji' => $jar['emoji'] ?? null,
            'tipJarMessage' => $jar['message'] ?? '',
        ];
    }

    /**
     * Resolve the jar to display for an invoice: the live row when the jar
     * still exists, else the metadata snapshot, else null. Returns
     * ['id', 'name', 'emoji' (resolved, never empty), 'message', 'live' => bool].
     */
    public static function forInvoice(array $invoice): ?array {
        $jarId = $invoice['tip_jar_id'] ?? null;
        $meta = null;
        if (!empty($invoice['metadata'])) {
            $decoded = json_decode((string)$invoice['metadata'], true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        if (($jarId === null || $jarId === '') && $meta !== null && !empty($meta['tipJarId'])) {
            $jarId = (string)$meta['tipJarId'];
        }
        if ($jarId === null || $jarId === '') {
            return null;
        }
        $live = Database::fetchOne('SELECT * FROM tip_jars WHERE id = ? AND store_id = ?', [$jarId, $invoice['store_id']]);
        if ($live) {
            return [
                'id' => $live['id'],
                'name' => $live['name'],
                'emoji' => ($live['emoji'] ?? '') !== '' && $live['emoji'] !== null ? $live['emoji'] : self::DEFAULT_EMOJI,
                'message' => (string)($live['message'] ?? ''),
                'live' => true,
            ];
        }
        if ($meta !== null && isset($meta['tipJarName'])) {
            $emoji = (string)($meta['tipJarEmoji'] ?? '');
            return [
                'id' => $jarId,
                'name' => (string)$meta['tipJarName'],
                'emoji' => $emoji !== '' ? $emoji : self::DEFAULT_EMOJI,
                'message' => (string)($meta['tipJarMessage'] ?? ''),
                'live' => false,
            ];
        }
        return null;
    }

    // ---------------- API shaping ----------------

    public static function formatForApi(array $row, ?array $received = null): array {
        require_once __DIR__ . '/urls.php';
        $emoji = $row['emoji'] ?? null;
        return [
            'id' => $row['id'],
            'storeId' => $row['store_id'],
            'name' => $row['name'],
            // emoji = what to draw (never empty); emojiRaw = what was saved
            // (null when the default is in use), for the edit form.
            'emoji' => ($emoji !== null && $emoji !== '') ? $emoji : self::DEFAULT_EMOJI,
            'emojiRaw' => ($emoji !== null && $emoji !== '') ? $emoji : null,
            'message' => (string)($row['message'] ?? ''),
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'createdAt' => (int)($row['created_at'] ?? 0),
            'url' => Urls::tipJar((string)$row['store_id'], (string)$row['id']),
            'receivedSats' => (int)($received['sats'] ?? 0),
            'receivedCount' => (int)($received['count'] ?? 0),
        ];
    }
}
