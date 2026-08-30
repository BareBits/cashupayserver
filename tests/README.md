# CashuPayServer test suite

End-to-end tests for cashupayserver. Spins up bitcoind (regtest), two LND nodes,
a nutshell Cashu mint, the PHP app, and a webhook sink — all on ephemeral ports,
all cleaned up between runs.

## Requirements

- Linux x86_64
- Python 3.11+
- ~600 MB free disk (bitcoind + lnd + static PHP + Playwright Chromium)
- Internet access on first run (binary download)

PHP is fetched as a single self-contained static binary from
[static-php-cli](https://github.com/crazywhalecc/static-php-cli) — no host PHP
install is required.

## Quick start

```bash
cd tests
./scripts/run-tests.sh
```

This will:
1. Initialize the `cashu-wallet-php` git submodule if missing.
2. Create `tests/.venv/` and install Python deps from `requirements.txt`.
3. Download + verify bitcoind and LND into `tests/bin/` if not already cached.
4. Install Playwright's Chromium browser into `tests/bin/playwright-browsers/`.
5. Run `pytest` — once per serving backend (php -S, Apache, nginx; see below).

## Serving backends

The app under test is served by one of three backends, selected with
`CASHUPAY_TEST_BACKEND` (see `fixtures/webserver.py`):

| backend  | what serves the app | needs |
|----------|---------------------|-------|
| `phps`   | `php -S` + router wrapper (the default for a bare `pytest`) | nothing |
| `apache` | `php:8.3-apache` (mod_php) container, real `.htaccess` semantics | `sudo -n docker` |
| `nginx`  | nginx + php-fpm container, the canonical `docker/nginx-site.conf` | `sudo -n docker` |

- `./scripts/run-tests.sh` with **no** `--backend` flag runs the full suite on
  **all three** sequentially (the local full-coverage run, ~3h). Pass
  `--backend=phps|apache|nginx` for a single pass — CI pins `--backend=phps`
  and covers the webservers with the fast `webserver-smoke` workflow instead.
- Containers run with `--network=host`, as your uid, with the repo bind-mounted
  at its own path, so URLs, data dirs, and sqlite access behave exactly as
  under `php -S`. Test images build once (content-hash tagged) on first use.
- Version drift caveat: the host-side pinned PHP is the static-cli 8.3.31
  build (`fixtures/binaries.py`); `php:8.3-apache` / `php:8.3-fpm` float
  within 8.3.x and are different builds — which is exactly the production
  reality the container backends exist to exercise.
- Backend-conditional test logic lives in `fixtures/backend.py`
  (`is_phps()`, `default_url_mode()`, ...). Under Apache/nginx the app's
  opportunistic background cron really runs, so prefer outcome-polling
  (`PayserverHandle.drive_cron_until`) over "nothing happened yet" assertions.

## Shared servers vs. fresh installs

Two families of payserver fixtures exist (`conftest.py`):

- **`shared_configured`** (plus `shared_configured_with_lnurlp` /
  `shared_configured_with_strike`, and `shared_server` for read-only tests):
  ONE payserver boots per test module and each test gets its own store —
  created through the add_store wizard, so it has its own wallet seed (no
  mint collisions) — plus its own API key. Same `ConfiguredPayserver`
  interface as `configured`; the per-test store's unique name is
  `.store_name`. This is the default for new tests: it skips a server boot
  and a 9-step wizard walk per test.
- **`configured` / `payserver`**: a fresh install per test. Required when a
  test mutates server-global state: admin password or users, global config
  rows (SMTP, updater, cron_key, update channel), failed logins (global
  lockout counter), the global API rate limiter, process restarts, unscoped
  sqlite writes, or the setup wizard itself.

Playwright note: the admin SPA auto-selects `stores[0]` unless
`localStorage.selectedStoreId` is set, so UI tests on a shared server must
pin their own store (see `add_init_script(...selectedStoreId...)` uses).

## Workdir cleanup

A test that passes deletes its workdir in teardown; failures keep theirs for
postmortem, and a fully green session removes the shared stack dir too. Set
`CASHUPAY_KEEP_WORKDIRS=1` to keep everything. At session start, orphaned
fixture processes from previously killed runs (anything with `tests/.tmp` in
its cmdline) are swept — set `CASHUPAY_NO_STALE_SWEEP=1` when deliberately
running two suites concurrently from this checkout.

## CI sharding

`pytest --split-group=M/N` runs the Mth of N deterministic file-level shards
(`fixtures/partition.py`; whole files stay together because of the
module-scoped shared servers). CI runs 3 shards in parallel; the shard count
lives in `.github/workflows/tests.yml` (matrix AND --split-group must match).

## Layout

```
tests/
  conftest.py          # top-level fixtures + plugin glue
  pytest.ini           # pytest config + marker registry
  requirements.txt     # pinned Python deps
  fixtures/            # subprocess managers (bitcoind, lnd, mint, payserver, …)
  e2e/                 # API-level end-to-end tests
  ui/                  # Playwright-driven browser tests
  wordpress/           # WordPress plugin tests (wp-cli + sqlite drop-in)
  scripts/             # run-tests.sh, download-binaries.sh
  bin/                 # cached binaries (gitignored)
  .tmp/                # per-test isolated dirs (gitignored)
```

## Pinned versions

See `fixtures/binaries.py` for the canonical manifest. At time of writing:

| Component | Version |
|-----------|---------|
| Bitcoin Core | 28.0 |
| LND | 0.18.5-beta |
| PHP (static) | 8.3.31 |
| Nutshell | 0.16.5 |
| Playwright Chromium | bundled with playwright 1.49.1 |

## Selecting a subset

```bash
# Just the API-level e2e tests
pytest e2e/

# Skip slow tests (LN ops, failover)
pytest -m "not slow"

# Run a single test verbosely
pytest e2e/test_invoice_payment.py -v
```

## Bring-your-own binaries

If `bitcoind` / `lnd` are already on `PATH` and version-compatible, the binary
manager uses them and skips download. Override via env:

```bash
CASHUPAY_TEST_BITCOIND=/usr/local/bin/bitcoind \
CASHUPAY_TEST_LND=/usr/local/bin/lnd \
pytest e2e/
```
