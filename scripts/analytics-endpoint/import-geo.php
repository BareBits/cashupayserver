<?php
/**
 * BareBits anonymous usage statistics — IP-to-country table loader.
 *
 * report.php tags each report with the country the connection came from
 * and then forgets the IP. The lookup needs a local table: this script
 * loads the free DB-IP "IP to Country Lite" CSV (CC-BY 4.0, db-ip.com) into
 * the endpoint's SQLite file (geo_v4 / geo_v6), replacing the previous
 * table atomically. Run it once at deploy and then about monthly.
 *
 * Run from the command line (preferred when the host offers SSH):
 *   php import-geo.php                      download this month's CSV and load it
 *   php import-geo.php /path/to/file.csv.gz load a CSV you downloaded yourself
 *
 * Or over HTTP on a host without SSH, protected the same way as summary.php
 * (HTTP basic auth via .htaccess, or ANALYTICS_ACCESS_TOKEN as ?token=):
 *   https://.../analytics/import-geo.php?token=...
 * The import takes a few seconds to a minute for ~700k rows. Progress is
 * printed as plain text.
 *
 * Attribution required by the CC-BY licence: "IP Geolocation by DB-IP"
 * (https://db-ip.com) — keep it on the summary page or wherever countries
 * are shown.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (!analytics_operator_authorized()) {
        http_response_code(403);
        echo "forbidden: put import-geo.php behind HTTP basic auth or set ANALYTICS_ACCESS_TOKEN and pass ?token=\n";
        exit;
    }
}
@set_time_limit(0);
@ini_set('memory_limit', '256M');

function geo_out(string $line): void {
    echo $line, "\n";
    if (PHP_SAPI !== 'cli') {
        @ob_flush();
        flush();
    }
}

function geo_download(string $url, string $dest): bool {
    $ch = curl_init($url);
    $fh = fopen($dest, 'wb');
    if ($ch === false || $fh === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT      => 'barebits-analytics-geo-import',
        CURLOPT_FAILONERROR    => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($ch);
    fclose($fh);
    return $ok && filesize($dest) > 1024;
}

/**
 * Stream the CSV (start_ip,end_ip,country) into fresh tables, then swap
 * them in under one transaction so lookups never see a half-loaded table.
 * Returns [v4 rows, v6 rows].
 */
function geo_load(PDO $pdo, string $gzPath): array {
    $fh = @gzopen($gzPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("cannot open {$gzPath}");
    }
    $pdo->exec('DROP TABLE IF EXISTS geo_v4_new');
    $pdo->exec('DROP TABLE IF EXISTS geo_v6_new');
    $pdo->exec('CREATE TABLE geo_v4_new (start INTEGER NOT NULL, end INTEGER NOT NULL, cc TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE geo_v6_new (start BLOB NOT NULL, end BLOB NOT NULL, cc TEXT NOT NULL)');
    $ins4 = $pdo->prepare('INSERT INTO geo_v4_new (start, end, cc) VALUES (?, ?, ?)');
    $ins6 = $pdo->prepare('INSERT INTO geo_v6_new (start, end, cc) VALUES (?, ?, ?)');
    $n4 = 0; $n6 = 0; $lineNo = 0;
    $pdo->exec('BEGIN');
    try {
        while (($line = gzgets($fh, 4096)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 3) {
                continue;
            }
            [$start, $end, $cc] = [trim($parts[0]), trim($parts[1]), strtoupper(trim($parts[2]))];
            if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'ZZ') {
                continue;
            }
            $ps = @inet_pton($start);
            $pe = @inet_pton($end);
            if ($ps === false || $pe === false || strlen($ps) !== strlen($pe)) {
                continue;
            }
            if (strlen($ps) === 4) {
                $ins4->execute([(int)sprintf('%u', unpack('N', $ps)[1]), (int)sprintf('%u', unpack('N', $pe)[1]), $cc]);
                $n4++;
            } else {
                $ins6->bindValue(1, $ps, PDO::PARAM_LOB);
                $ins6->bindValue(2, $pe, PDO::PARAM_LOB);
                $ins6->bindValue(3, $cc, PDO::PARAM_STR);
                $ins6->execute();
                $n6++;
            }
            if (($n4 + $n6) % 100000 === 0) {
                geo_out('  ... ' . ($n4 + $n6) . ' ranges');
            }
        }
        gzclose($fh);
        if ($n4 < 1000) {
            throw new RuntimeException("suspiciously few IPv4 ranges ({$n4}) — refusing to replace the table");
        }
        $pdo->exec('CREATE INDEX idx_geo_v4_new_start ON geo_v4_new (start)');
        $pdo->exec('CREATE INDEX idx_geo_v6_new_start ON geo_v6_new (start)');
        $pdo->exec('DROP TABLE IF EXISTS geo_v4');
        $pdo->exec('DROP TABLE IF EXISTS geo_v6');
        $pdo->exec('ALTER TABLE geo_v4_new RENAME TO geo_v4');
        $pdo->exec('ALTER TABLE geo_v6_new RENAME TO geo_v6');
        analytics_meta_set($pdo, 'geo_loaded_at', gmdate('c'));
        analytics_meta_set($pdo, 'geo_source', basename($gzPath));
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
    return [$n4, $n6];
}

try {
    $pdo = analytics_db();
    $source = $isCli ? (string)($argv[1] ?? '') : '';
    $tmp = null;
    if ($source === '') {
        $tmp = __DIR__ . '/dbip-country-lite.download.csv.gz';
        $urls = [
            'https://download.db-ip.com/free/dbip-country-lite-' . gmdate('Y-m') . '.csv.gz',
            'https://download.db-ip.com/free/dbip-country-lite-' . gmdate('Y-m', strtotime('first day of last month')) . '.csv.gz',
        ];
        $got = false;
        foreach ($urls as $url) {
            geo_out("downloading {$url}");
            if (geo_download($url, $tmp)) {
                $got = true;
                break;
            }
            geo_out('  not available');
        }
        if (!$got) {
            @unlink($tmp);
            throw new RuntimeException('could not download the DB-IP CSV; pass a local file instead');
        }
        $source = $tmp;
    } elseif (!is_file($source)) {
        throw new RuntimeException("no such file: {$source}");
    }
    geo_out("loading {$source}");
    [$n4, $n6] = geo_load($pdo, $source);
    if ($tmp !== null) {
        @unlink($tmp);
    }
    geo_out("done: {$n4} IPv4 ranges, {$n6} IPv6 ranges. IP Geolocation by DB-IP (https://db-ip.com).");
} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    geo_out('error: ' . $e->getMessage());
    exit(1);
}
