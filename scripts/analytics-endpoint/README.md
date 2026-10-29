# BareBits anonymous usage statistics endpoint (`getbarebits.com/analytics/`)

Receives the opt-in usage report a BareBits payserver sends when its operator
enables **Anonymous usage statistics** (wizard screen, or Settings card). The
client side is `includes/usage_stats.php`; the wire format is documented in
its header. This directory is the server side: plain PHP + SQLite, no
dependencies, runs on shared hosting.

| File | Role |
| --- | --- |
| `report.php` | Public receiver. Validates every field against the per-level allowlist, keeps one row per install id, tags a country, forgets the IP. |
| `summary.php` | Operator-only aggregates (HTML / `?format=json` / `?format=csv`). |
| `import-geo.php` | Loads the DB-IP country table that gives `report.php` its country lookup. |
| `common.php` | Shared settings, database, validation rules. Not web-accessible. |
| `.htaccess` | Blocks the SQLite file and `common.php`; template for basic auth on the operator pages. |

## Deploy

1. Upload the five files to the `analytics/` directory of getbarebits.com
   (merge the `.htaccess` rules into an existing file if there is one).
2. Make the directory writable by the PHP user: `report.php` creates
   `usage-reports.sqlite` (plus `-wal` / `-shm`) beside itself on first request.
3. Protect the operator pages. Either uncomment the basic-auth block in
   `.htaccess` and create the `htpasswd` file **outside** the web root, or set
   `ANALYTICS_ACCESS_TOKEN` in `common.php` to a long random string and open
   the pages with `?token=<it>`. With neither configured the pages answer 403;
   they never fall open.
4. Check: `https://getbarebits.com/analytics/report.php` returns `{"ok":true}`,
   `https://getbarebits.com/analytics/usage-reports.sqlite` returns 403 once
   the file exists, `https://getbarebits.com/analytics/common.php` returns 403.
5. Proxy check: open `report.php?whoami=1` from a machine whose public IP you
   know. If it prints a loopback or proxy address, the site sits behind a
   reverse proxy/CDN — set `ANALYTICS_TRUSTED_PROXY_IP_HEADER` in `common.php`
   (e.g. `'HTTP_CF_CONNECTING_IP'`) and re-check, otherwise every install
   shares one rate-limit bucket and every report gets the proxy's country.
6. Country lookup: run `php import-geo.php` over SSH, or open
   `import-geo.php?token=...` once, then roughly monthly (DB-IP publishes a new
   file each month). If the host already supplies a country header
   (Cloudflare `CF-IPCountry`, mod_geoip), set `ANALYTICS_COUNTRY_HEADER`
   instead and skip the import. Attribution for the free database:
   "IP Geolocation by DB-IP" (https://db-ip.com, CC-BY 4.0).

## What is stored

`reports`: one row per install id — first seen, last received, level,
country, version, channel, install kind, and the validated report JSON. A
newer report **replaces** the older one; there is no history. `recent_requests`:
client IP + time, only for the 120/hour per-IP rate limit, purged after two
hours on every request. The geo tables hold public DB-IP ranges.

An install that opts out simply stops sending; its last row stays (it ages
out of the "active" counts on `summary.php`). Delete rows by hand with
`sqlite3 usage-reports.sqlite "DELETE FROM reports WHERE install_id = '...'"` if
asked.

## Contract with the client

`report.php` accepts **exactly** the keys `includes/usage_stats.php` lists in
`UsageStats::ALLOWED_PROPERTIES` for the report's level (lower levels
included), with type and range checks (`analytics_rules()` in `common.php`).
Unknown or missing keys → `400 {"error":"unknown_field"|"missing_field","field":...}`.
When a field is added to the client, add it here first and deploy the
endpoint before the client release ships, otherwise every updated install
sees "endpoint returned HTTP 400" in its Settings card until the server
catches up. The repo tests (`tests/php/test_analytics_endpoint.php`,
`tests/php/test_usage_stats_client.php`) run this real script under `php -S`
and pin the contract from both sides.

## Future ingestion

Each stored `payload` is a self-describing JSON event
(`schema_version`, `event`, `install_id`, `sent_at`, `level`, flat snake_case
`properties`). `summary.php?format=csv` flattens the current population to
one row per install; the SQLite file can be opened directly with Datasette,
Metabase, DuckDB or `sqlite3` when a dashboard beyond `summary.php` is wanted.
