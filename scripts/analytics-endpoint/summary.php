<?php
/**
 * BareBits anonymous usage statistics — operator summary.
 * Deployed at https://getbarebits.com/analytics/summary.php
 *
 * Aggregates the one-row-per-install table that report.php maintains.
 * Operator-only: served only when the web server authenticated the caller
 * (HTTP basic auth, see .htaccess) or the configured ANALYTICS_ACCESS_TOKEN
 * was passed as ?token=. Without either it answers 403 — forgetting the
 * .htaccess never exposes the data.
 *
 *   summary.php                HTML tables
 *   summary.php?format=json    the same aggregates as JSON
 *   summary.php?format=csv     one row per install, flattened — for a
 *                              spreadsheet or a warehouse import
 *   &active_days=N             "active" = reported within N days (default 14)
 *
 * Everything rendered is escaped; the CSV neutralises formula-leading
 * cells. No shell, no includes beyond common.php.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('Referrer-Policy: no-referrer');

if (!analytics_operator_authorized()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden: put summary.php behind HTTP basic auth or set ANALYTICS_ACCESS_TOKEN and pass ?token=\n";
    exit;
}

function summary_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Spreadsheet formula injection guard for CSV cells. */
function summary_csv_cell(mixed $v): string {
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null) {
        return '';
    }
    if (is_array($v)) {
        $v = implode('|', array_map('strval', $v));
    }
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    return $s;
}

function summary_group(array $rows, string $key, ?callable $pick = null): array {
    $out = [];
    foreach ($rows as $r) {
        $v = $pick ? $pick($r) : ($r[$key] ?? null);
        $label = $v === null || $v === '' ? '(none)' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v);
        $out[$label] = ($out[$label] ?? 0) + 1;
    }
    arsort($out);
    return $out;
}

$pdo = analytics_db();
$now = time();
$activeDays = max(1, min(3650, (int)($_GET['active_days'] ?? 14)));
$activeCutoff = $now - $activeDays * 86400;
$format = (string)($_GET['format'] ?? 'html');

$rows = [];
foreach ($pdo->query('SELECT * FROM reports ORDER BY received_at DESC') as $r) {
    $decoded = json_decode((string)$r['payload'], true);
    $props = is_array($decoded) && is_array($decoded['properties'] ?? null) ? $decoded['properties'] : [];
    $rows[] = [
        'install_id'    => (string)$r['install_id'],
        'first_seen_at' => (int)$r['first_seen_at'],
        'received_at'   => (int)$r['received_at'],
        'level'         => (int)$r['level'],
        'country'       => $r['country'] === null ? null : (string)$r['country'],
        'active'        => (int)$r['received_at'] >= $activeCutoff,
        'p'             => $props,
    ];
}

// ---- CSV: one row per install ------------------------------------------------
if ($format === 'csv') {
    $allKeys = [];
    foreach (analytics_rules_for_level(3) as $k => $_) {
        $allKeys[] = $k;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="barebits-usage-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['install_id', 'first_seen_utc', 'received_utc', 'level', 'country', 'active'], $allKeys));
    foreach ($rows as $r) {
        $line = [
            $r['install_id'], gmdate('c', $r['first_seen_at']), gmdate('c', $r['received_at']),
            $r['level'], $r['country'], $r['active'],
        ];
        foreach ($allKeys as $k) {
            $line[] = array_key_exists($k, $r['p']) ? $r['p'][$k] : null;
        }
        fputcsv($out, array_map('summary_csv_cell', $line));
    }
    fclose($out);
    exit;
}

// ---- Aggregates ----------------------------------------------------------------
$active = array_values(array_filter($rows, static fn($r) => $r['active']));
$agg = [
    'generated_at_utc' => gmdate('c', $now),
    'active_days'      => $activeDays,
    'installs_total'   => count($rows),
    'installs_active'  => count($active),
    'by_level'         => summary_group($active, 'level'),
    'by_version'       => summary_group($active, '', static fn($r) => $r['p']['version'] ?? null),
    'by_channel'       => summary_group($active, '', static fn($r) => $r['p']['channel'] ?? null),
    'by_install_kind'  => summary_group($active, '', static fn($r) => $r['p']['install_kind'] ?? null),
    'by_php_version'   => summary_group($active, '', static fn($r) => $r['p']['php_version'] ?? null),
    'by_os_family'     => summary_group($active, '', static fn($r) => $r['p']['os_family'] ?? null),
    'by_web_server'    => summary_group($active, '', static fn($r) => $r['p']['web_server'] ?? null),
    'by_country'       => summary_group($active, 'country'),
];
$l2 = array_values(array_filter($active, static fn($r) => $r['level'] >= 2));
$railInstalls = array_fill_keys(ANALYTICS_FEATURE_RAILS, 0);
$sumStores = 0; $sumProducts = 0; $sumApiKeys = 0; $autoUpdateOn = 0; $wpConnected = 0;
foreach ($l2 as $r) {
    foreach ((array)($r['p']['rails_enabled'] ?? []) as $rail) {
        if (isset($railInstalls[$rail])) {
            $railInstalls[$rail]++;
        }
    }
    $sumStores += (int)($r['p']['store_count'] ?? 0);
    $sumProducts += (int)($r['p']['products_count'] ?? 0);
    $sumApiKeys += (int)($r['p']['api_keys_count'] ?? 0);
    $autoUpdateOn += !empty($r['p']['auto_update_enabled']) ? 1 : 0;
    $wpConnected += !empty($r['p']['wordpress_connected']) ? 1 : 0;
}
$agg['features'] = [
    'installs_reporting'    => count($l2),
    'installs_with_rail'    => $railInstalls,
    'stores_total'          => $sumStores,
    'products_total'        => $sumProducts,
    'api_keys_total'        => $sumApiKeys,
    'auto_update_enabled'   => $autoUpdateOn,
    'wordpress_connected'   => $wpConnected,
];
$l3 = array_values(array_filter($active, static fn($r) => $r['level'] >= 3));
$vol = ['installs_reporting' => count($l3), 'invoices_30d_created' => 0, 'invoices_30d_settled' => 0,
        'invoices_30d_expired' => 0, 'invoices_30d_settled_sats' => 0, 'by_rail' => []];
foreach (ANALYTICS_VOLUME_RAILS as $rail) {
    $vol['by_rail'][$rail] = ['count' => 0, 'sats' => 0];
}
foreach ($l3 as $r) {
    foreach (['invoices_30d_created', 'invoices_30d_settled', 'invoices_30d_expired', 'invoices_30d_settled_sats'] as $k) {
        $vol[$k] += (int)($r['p'][$k] ?? 0);
    }
    foreach (ANALYTICS_VOLUME_RAILS as $rail) {
        $vol['by_rail'][$rail]['count'] += (int)($r['p']["settled_30d_{$rail}_count"] ?? 0);
        $vol['by_rail'][$rail]['sats'] += (int)($r['p']["settled_30d_{$rail}_sats"] ?? 0);
    }
}
$agg['volume_30d'] = $vol;
$agg['geo_table_loaded_at'] = analytics_meta_get($pdo, 'geo_loaded_at');

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($agg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

// ---- HTML -----------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
function summary_table(string $title, array $counts): void {
    echo '<h2>', summary_h($title), '</h2><table><tr><th>Value</th><th>Installs</th></tr>';
    if ($counts === []) {
        echo '<tr><td colspan="2"><em>none</em></td></tr>';
    }
    foreach ($counts as $label => $n) {
        echo '<tr><td>', summary_h((string)$label), '</td><td>', (int)$n, '</td></tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>BareBits usage statistics</title>
<style>
body { font-family: system-ui, sans-serif; margin: 2rem; color: #222; background: #fafafa; }
table { border-collapse: collapse; margin-bottom: 1.5rem; min-width: 18rem; }
th, td { border: 1px solid #ccc; padding: 0.3rem 0.7rem; text-align: left; }
th { background: #eee; }
h2 { font-size: 1.05rem; margin: 1.5rem 0 0.4rem; }
.kpi { display: inline-block; margin: 0 1.5rem 1rem 0; }
.kpi b { display: block; font-size: 1.8rem; }
small { color: #666; }
</style>
</head>
<body>
<h1>BareBits anonymous usage statistics</h1>
<p><small>Generated <?= summary_h($agg['generated_at_utc']) ?> · "active" = reported within the last <?= (int)$activeDays ?> days
(<a href="?active_days=7<?= ANALYTICS_ACCESS_TOKEN !== '' && isset($_GET['token']) ? '&amp;token=' . summary_h((string)$_GET['token']) : '' ?>">7</a> ·
<a href="?active_days=30<?= ANALYTICS_ACCESS_TOKEN !== '' && isset($_GET['token']) ? '&amp;token=' . summary_h((string)$_GET['token']) : '' ?>">30</a> ·
<a href="?active_days=365<?= ANALYTICS_ACCESS_TOKEN !== '' && isset($_GET['token']) ? '&amp;token=' . summary_h((string)$_GET['token']) : '' ?>">365</a>)
· <a href="?format=json<?= ANALYTICS_ACCESS_TOKEN !== '' && isset($_GET['token']) ? '&amp;token=' . summary_h((string)$_GET['token']) : '' ?>">JSON</a>
· <a href="?format=csv<?= ANALYTICS_ACCESS_TOKEN !== '' && isset($_GET['token']) ? '&amp;token=' . summary_h((string)$_GET['token']) : '' ?>">CSV export</a>
· geo table: <?= $agg['geo_table_loaded_at'] ? 'loaded ' . summary_h($agg['geo_table_loaded_at']) : 'not loaded (run import-geo.php)' ?></small></p>

<div class="kpi"><b><?= (int)$agg['installs_total'] ?></b>installs ever seen</div>
<div class="kpi"><b><?= (int)$agg['installs_active'] ?></b>active installs</div>
<div class="kpi"><b><?= (int)$agg['features']['installs_reporting'] ?></b>sharing features</div>
<div class="kpi"><b><?= (int)$agg['volume_30d']['installs_reporting'] ?></b>sharing volume</div>

<?php
summary_table('Active installs by level', $agg['by_level']);
summary_table('By version', $agg['by_version']);
summary_table('By channel', $agg['by_channel']);
summary_table('By install kind', $agg['by_install_kind']);
summary_table('By country', $agg['by_country']);
summary_table('By PHP version', $agg['by_php_version']);
summary_table('By OS family', $agg['by_os_family']);
summary_table('By web server', $agg['by_web_server']);
summary_table('Installs with each rail enabled (level 2+)', $agg['features']['installs_with_rail']);
?>
<h2>Feature totals (level 2+)</h2>
<table>
<tr><th>Stores</th><td><?= (int)$agg['features']['stores_total'] ?></td></tr>
<tr><th>Products</th><td><?= (int)$agg['features']['products_total'] ?></td></tr>
<tr><th>API keys</th><td><?= (int)$agg['features']['api_keys_total'] ?></td></tr>
<tr><th>Installs with auto-update on</th><td><?= (int)$agg['features']['auto_update_enabled'] ?></td></tr>
<tr><th>Installs with WordPress connected</th><td><?= (int)$agg['features']['wordpress_connected'] ?></td></tr>
</table>
<h2>Last-30-day volume (level 3, summed over reporting installs)</h2>
<table>
<tr><th>Invoices created</th><td><?= (int)$vol['invoices_30d_created'] ?></td></tr>
<tr><th>Invoices settled</th><td><?= (int)$vol['invoices_30d_settled'] ?></td></tr>
<tr><th>Invoices expired</th><td><?= (int)$vol['invoices_30d_expired'] ?></td></tr>
<tr><th>Settled sats</th><td><?= number_format((int)$vol['invoices_30d_settled_sats']) ?></td></tr>
</table>
<table>
<tr><th>Rail</th><th>Settled invoices</th><th>Settled sats</th></tr>
<?php foreach ($vol['by_rail'] as $rail => $v): ?>
<tr><td><?= summary_h((string)$rail) ?></td><td><?= (int)$v['count'] ?></td><td><?= number_format((int)$v['sats']) ?></td></tr>
<?php endforeach; ?>
</table>
</body>
</html>
