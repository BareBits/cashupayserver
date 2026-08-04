"""WordPress fixture for testing the CashuPay plugin.

Uses wp-cli + the static PHP binary to stand up a fresh SQLite-backed WP
install per test, with the plugin tree symlinked from the repo so any
local change is exercised. Same `php -S` mechanic as the standalone
payserver fixture — front controller through a tiny wrapper that
defines CASHUPAY_DATA_DIR from the env.
"""
from __future__ import annotations

import os
import shutil
import signal
import sqlite3
import subprocess
import time
import urllib.request
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import requests

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
TESTS_DIR = REPO_ROOT / "tests"
BIN_DIR = TESTS_DIR / "bin"

WP_VERSION = "6.6.2"
WP_TARBALL_URL = f"https://wordpress.org/wordpress-{WP_VERSION}.tar.gz"
WP_TARBALL_SHA256 = ""  # populated lazily via wp.org checksums; see _wp_core_path
WP_TARBALL_CACHE = BIN_DIR / f"wordpress-{WP_VERSION}"

# Use the WP.org plugin distribution — the GitHub release with the same
# version number ships the *new* wp-pdo-mysql-on-sqlite plugin which doesn't
# include the db.copy drop-in WordPress core actually needs.
SQLITE_DB_URL = "https://downloads.wordpress.org/plugin/sqlite-database-integration.2.2.23.zip"
SQLITE_DB_SHA256 = "44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e"
SQLITE_DB_CACHE = BIN_DIR / "sqlite-database-integration-2.2.23"

WP_ADMIN_USER = "admin"
WP_ADMIN_PASSWORD = "wp-admin-test-pw"
WP_ADMIN_EMAIL = "admin@example.test"
WP_SITE_TITLE = "CashuPay Test"

# WooCommerce + the real BTCPay Greenfield gateway plugin, pinned for a
# deterministic end-to-end checkout. WooCommerce 9.6.2 is the newest release
# that still lists "Requires at least: 6.6" (11.x needs WP 6.9, newer than the
# 6.6.2 core this fixture installs). The BTCPay plugin verifies the exact same
# `sha256=` HMAC BareBits emits, so no protocol shim is needed.
WOOCOMMERCE_VERSION = "9.6.2"
WOOCOMMERCE_URL = f"https://downloads.wordpress.org/plugin/woocommerce.{WOOCOMMERCE_VERSION}.zip"
WOOCOMMERCE_SHA256 = "d801efe9ffc3fdcf1495dc9662a08c9373ff1eee44b1838de649cf758ccbcd13"
WOOCOMMERCE_CACHE = BIN_DIR / f"woocommerce-{WOOCOMMERCE_VERSION}"

BTCPAY_WC_VERSION = "2.8.0"
BTCPAY_WC_URL = (
    f"https://downloads.wordpress.org/plugin/btcpay-greenfield-for-woocommerce.{BTCPAY_WC_VERSION}.zip"
)
BTCPAY_WC_SHA256 = "b06a4da4835d984ddd870c3bfafb6fc4c524fe0ef988f22cd1575a8a7b77d236"
BTCPAY_WC_CACHE = BIN_DIR / f"btcpay-greenfield-{BTCPAY_WC_VERSION}"


@dataclass
class WordPressHandle:
    process: subprocess.Popen[bytes]
    port: int
    wp_root: Path
    data_dir: Path
    workdir: Path
    php_exe: Path
    wp_cli_phar: Path

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def cashupay_admin_url(self) -> str:
        return f"{self.url}/wp-content/plugins/cashupay/admin.php"

    @property
    def db_path(self) -> Path:
        return self.data_dir / "cashupay.sqlite"

    @contextmanager
    def db(self) -> Iterator[sqlite3.Connection]:
        conn = sqlite3.connect(self.db_path, isolation_level=None)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()

    def wp_cli(self, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        """Run a wp-cli command against this WP install."""
        cmd = [
            str(self.php_exe),
            str(self.wp_cli_phar),
            f"--path={self.wp_root}",
            "--allow-root",
            *args,
        ]
        env = os.environ.copy()
        env["CASHUPAY_DATA_DIR"] = str(self.data_dir)
        result = subprocess.run(cmd, env=env, capture_output=True, text=True)
        if check and result.returncode != 0:
            raise RuntimeError(
                f"wp-cli failed ({result.returncode}) for {args}\n"
                f"stdout: {result.stdout}\n"
                f"stderr: {result.stderr}"
            )
        return result

    def wait_ready(self, timeout_s: float = 30.0) -> None:
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                requests.get(self.url, timeout=2)
                return
            except requests.RequestException as e:
                last = e
            time.sleep(0.2)
        raise TimeoutError(f"WordPress not ready after {timeout_s}s ({last})")


# ---------- one-time downloads ----------

def _download_to(url: str, dest: Path, *, expected_sha256: str | None = None) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    if dest.exists() and expected_sha256 and _sha256(dest) == expected_sha256:
        return
    import tempfile
    print(f"[wp] downloading {dest.name} ...")
    req = urllib.request.Request(url, headers={"User-Agent": "cashupayserver-tests/1.0"})
    with tempfile.NamedTemporaryFile(dir=dest.parent, delete=False, suffix=".partial") as tmp:
        with urllib.request.urlopen(req, timeout=120) as resp:
            shutil.copyfileobj(resp, tmp)
        tmp_path = Path(tmp.name)
    if expected_sha256:
        actual = _sha256(tmp_path)
        if actual != expected_sha256:
            tmp_path.unlink(missing_ok=True)
            raise RuntimeError(f"sha256 mismatch for {dest.name}: expected {expected_sha256} got {actual}")
    tmp_path.replace(dest)


def _sha256(path: Path) -> str:
    import hashlib
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _ensure_wp_core() -> Path:
    """Download + extract WordPress core if not already cached. Returns the
    directory containing the core (with wp-includes/, wp-admin/, index.php)."""
    if (WP_TARBALL_CACHE / "wordpress" / "wp-includes" / "version.php").is_file():
        return WP_TARBALL_CACHE / "wordpress"
    WP_TARBALL_CACHE.mkdir(parents=True, exist_ok=True)
    tarball = WP_TARBALL_CACHE / f"wordpress-{WP_VERSION}.tar.gz"
    _download_to(WP_TARBALL_URL, tarball)  # checksum-less; wp.org doesn't publish a stable manifest at a static URL
    import tarfile
    with tarfile.open(tarball, "r:gz") as tf:
        tf.extractall(WP_TARBALL_CACHE, filter="data")
    return WP_TARBALL_CACHE / "wordpress"


def _ensure_sqlite_plugin() -> Path:
    """Download + extract sqlite-database-integration. Returns the plugin dir."""
    extracted = SQLITE_DB_CACHE / "sqlite-database-integration"
    if (extracted / "db.copy").is_file():
        return extracted
    SQLITE_DB_CACHE.mkdir(parents=True, exist_ok=True)
    # Wipe any stale extraction (e.g. from the GitHub tarball layout).
    if SQLITE_DB_CACHE.exists():
        for child in SQLITE_DB_CACHE.iterdir():
            if child.is_dir():
                shutil.rmtree(child)
    archive = SQLITE_DB_CACHE / "plugin.zip"
    _download_to(SQLITE_DB_URL, archive, expected_sha256=SQLITE_DB_SHA256)
    import zipfile
    with zipfile.ZipFile(archive) as zf:
        zf.extractall(SQLITE_DB_CACHE)
    if not (extracted / "db.copy").is_file():
        raise RuntimeError(f"sqlite-database-integration extracted but db.copy missing at {extracted}")
    return extracted


def _ensure_cached_plugin(cache_dir: Path, url: str, sha256: str, slug: str) -> Path:
    """Download + extract a wp.org plugin zip once. Returns the extracted plugin
    directory (cache_dir/<slug>), whose basename matches the plugin slug so
    `wp plugin activate <slug>` works. Idempotent across runs and tests."""
    extracted = cache_dir / slug
    main_file = extracted / f"{slug}.php"
    if main_file.is_file():
        return extracted
    cache_dir.mkdir(parents=True, exist_ok=True)
    archive = cache_dir / "plugin.zip"
    _download_to(url, archive, expected_sha256=sha256)
    import zipfile
    with zipfile.ZipFile(archive) as zf:
        zf.extractall(cache_dir)
    if not main_file.is_file():
        raise RuntimeError(f"{slug} extracted but {main_file.name} missing at {extracted}")
    return extracted


# ---------- plugin tree assembly ----------

# Mirrors what scripts/build-wordpress-plugin.sh produces but via symlinks
# so source edits are reflected without rebuilding.
# Top-level repo entries to symlink as subdirs/files of the plugin.
_PLUGIN_SUBPATHS = (
    "includes",
    "admin.php",
    "setup.php",
    "api.php",
    "payment.php",
    "receive.php",
    "cron.php",
    "router.php",
    "api-keys",
    "assets",
    "images",
    "favicon.ico",
    "manifest.json",
)


def _assemble_plugin(plugin_dir: Path) -> None:
    """Mirror docker/Dockerfile.wordpress's plugin layout: all wordpress/*.php
    files live flat at the plugin root, with shared backend folders symlinked
    next to them.

    PHP files at the plugin root are *copies* (not symlinks) so __DIR__ resolves
    to the plugin directory at runtime; everything else is symlinked so source
    edits show up live."""
    plugin_dir.mkdir(parents=True, exist_ok=True)

    # 1. Copy every wordpress/*.php to the plugin root so __DIR__ resolution
    #    points at the plugin directory and `require __DIR__ . '/bootstrap.php'`
    #    in cashupay.php finds bootstrap.php beside it.
    for src in (REPO_ROOT / "wordpress").glob("*.php"):
        dst = plugin_dir / src.name
        if not dst.exists():
            shutil.copy(src, dst)

    # 2. Symlink the shared backend directories / single-file assets.
    for rel in _PLUGIN_SUBPATHS:
        src = REPO_ROOT / rel
        if not src.exists():
            continue
        dst = plugin_dir / rel
        if dst.is_symlink() or dst.exists():
            continue
        os.symlink(src, dst)

    # 3. cashu-wallet-php: only the two runtime files (matches Dockerfile).
    cashu_dir = plugin_dir / "cashu-wallet-php"
    cashu_dir.mkdir(exist_ok=True)
    for fname in ("CashuWallet.php", "bip39-english.txt"):
        src = REPO_ROOT / "cashu-wallet-php" / fname
        dst = cashu_dir / fname
        if src.exists() and not dst.exists():
            os.symlink(src, dst)


# ---------- built plugin zip (the shipped artifact) ----------

def ensure_wp_plugin_zip() -> Path:
    """Return the built WordPress plugin zip — the exact artifact the release
    workflow publishes.

    If CASHUPAY_WP_PLUGIN_ZIP points at an existing file, use it verbatim (CI
    passes the artifact built by the `build-artifacts` job, so the E2E job needs
    no Node/composer). Otherwise build it locally via
    scripts/build-wordpress-plugin.sh, using the pinned static PHP for composer.
    A local build additionally needs Node/npm for the mint-discovery bundle.
    """
    env_zip = os.environ.get("CASHUPAY_WP_PLUGIN_ZIP")
    if env_zip:
        p = Path(env_zip)
        if p.is_file():
            return p
        raise RuntimeError(
            f"CASHUPAY_WP_PLUGIN_ZIP={env_zip!r} but that file does not exist"
        )

    php_exe = binaries.ensure(binaries.PHP)["php"]
    script = REPO_ROOT / "scripts" / "build-wordpress-plugin.sh"
    env = os.environ.copy()
    env["PHP_BIN"] = str(php_exe)
    print(f"[wp] building plugin zip via {script.name} ...")
    subprocess.run(["bash", str(script)], cwd=str(REPO_ROOT), env=env, check=True)
    zip_path = REPO_ROOT / "build" / "wordpress_plugin.zip"
    if not zip_path.is_file():
        raise RuntimeError("build-wordpress-plugin.sh did not produce build/wordpress_plugin.zip")
    return zip_path


# ---------- fixture ----------

ROUTER_WRAPPER_TEMPLATE = """<?php
$dataDir = getenv('CASHUPAY_DATA_DIR');
if ($dataDir !== false && $dataDir !== '' && !defined('CASHUPAY_DATA_DIR')) {{
    define('CASHUPAY_DATA_DIR', $dataDir);
}}
// WordPress front controller — fall through to wp's index.php on misses.
//
// Paths resolve against the WordPress docroot, NOT __DIR__: this router lives
// in the workdir alongside the install, so __DIR__ would never match a real WP
// file and every request — including /wp-login.php — would fall through to
// index.php. That silently made browser-style authentication impossible.
$docRoot = {wp_root!r};
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $docRoot . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file) && substr($uri, -4) !== '.php') {{
    return false;  // let PHP serve static assets
}}
if (substr($uri, -4) === '.php' && file_exists($file)) {{
    require $file;
    return true;
}}
require {wp_index!r};
"""


def start_wordpress(workdir: Path, *, install_cashupay: bool = True) -> WordPressHandle:
    """Stand up a fresh SQLite-backed WordPress install.

    install_cashupay=True (default) assembles the cashupay plugin from live
    source via symlinks and activates it — what every existing WP test wants.
    install_cashupay=False brings WordPress up with NO cashupay plugin, so a
    test can install the real built zip itself (`wp plugin install <zip>`) and
    exercise the shipped artifact rather than the symlink tree.
    """
    php_exe = binaries.ensure(binaries.PHP)["php"]
    wp_cli_phar = binaries.ensure_file(binaries.WP_CLI)

    workdir.mkdir(parents=True, exist_ok=True)
    wp_root = workdir / "wp"
    data_dir = workdir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)

    # 1. Copy WP core into wp_root (fresh per test; isolated).
    core = _ensure_wp_core()
    if not wp_root.exists():
        shutil.copytree(core, wp_root)

    # 2. SQLite drop-in.
    sqlite_plugin = _ensure_sqlite_plugin()
    target_plugin_dir = wp_root / "wp-content" / "plugins" / "sqlite-database-integration"
    if not target_plugin_dir.exists():
        shutil.copytree(sqlite_plugin, target_plugin_dir)
    drop_in = wp_root / "wp-content" / "db.php"
    if not drop_in.exists():
        shutil.copy(sqlite_plugin / "db.copy", drop_in)

    # 3. Cashupay plugin (symlinks for live source). Skipped when the caller
    #    will install the built zip itself.
    if install_cashupay:
        _assemble_plugin(wp_root / "wp-content" / "plugins" / "cashupay")

    # 4. wp-config.php with SQLite config + WP_HOME.
    port = ports.allocate(1)[0]
    config = wp_root / "wp-config.php"
    if not config.exists():
        config.write_text(_wp_config_php(port=port, data_dir=data_dir))

    # 5. Router wrapper.
    router_wrapper = workdir / "wp-router.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(
            wp_root=str(wp_root),
            wp_index=str(wp_root / "index.php"),
        )
    )

    # 6. wp core install (uses the static PHP via wp-cli).
    install_env = os.environ.copy()
    install_env["CASHUPAY_DATA_DIR"] = str(data_dir)
    subprocess.run(
        [
            str(php_exe), str(wp_cli_phar),
            f"--path={wp_root}",
            "--allow-root",
            "core", "install",
            f"--url=http://127.0.0.1:{port}",
            f"--title={WP_SITE_TITLE}",
            f"--admin_user={WP_ADMIN_USER}",
            f"--admin_password={WP_ADMIN_PASSWORD}",
            f"--admin_email={WP_ADMIN_EMAIL}",
            "--skip-email",
        ],
        env=install_env,
        check=True,
        capture_output=True,
        text=True,
    )

    # 7. Activate cashupay plugin (unless the caller installs the zip itself).
    if install_cashupay:
        subprocess.run(
            [
                str(php_exe), str(wp_cli_phar),
                f"--path={wp_root}",
                "--allow-root",
                "plugin", "activate", "cashupay",
            ],
            env=install_env,
            check=True,
            capture_output=True,
            text=True,
        )

    # 8. Spin php -S.
    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    # SafeHttp blocks loopback/RFC1918 destinations by default; the
    # webhook sink in this test runs on 127.0.0.1, so let the WP-served
    # cashupay plugin opt in.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    # Fork multiple worker processes for the built-in server. A single-threaded
    # `php -S` deadlocks on any same-server loopback request, and a real
    # WooCommerce checkout makes two: the BTCPay gateway calls the cashupay
    # Greenfield API to create the invoice *during* the checkout request, and
    # the cashupay webhook cron POSTs back to WooCommerce's wc-api endpoint
    # *during* the cron request. Production (Apache/php-fpm) is multi-process;
    # this mirrors that. Confirmed honored by the pinned static PHP 8.3 build.
    env.setdefault("PHP_CLI_SERVER_WORKERS", "6")
    log = (workdir / "wp-server.log").open("ab")
    proc = subprocess.Popen(
        [
            str(php_exe),
            "-S", f"127.0.0.1:{port}",
            "-t", str(wp_root),
            str(router_wrapper),
        ],
        env=env,
        cwd=str(wp_root),
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = WordPressHandle(
        process=proc,
        port=port,
        wp_root=wp_root,
        data_dir=data_dir,
        workdir=workdir,
        php_exe=php_exe,
        wp_cli_phar=wp_cli_phar,
    )
    try:
        handle.wait_ready()
    except Exception:
        stop_wordpress(handle)
        raise
    return handle


def stop_wordpress(handle: WordPressHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()


# ---------- WooCommerce + BTCPay gateway on top of a running WP ----------

# The shop currency the checkout test uses is BTC. BareBits treats BTC as a
# fixed 1e8 sats with no exchange-rate lookup (see includes/invoice.php), so a
# BTC-priced order settles deterministically with no network price feed. Eight
# decimals lets us price a product in exact sats (0.00001500 BTC = 1500 sats).
WC_PRODUCT_PRICE_BTC = "0.00001500"


def install_woocommerce(handle: WordPressHandle) -> dict:
    """Install + activate WooCommerce and the real BTCPay Greenfield gateway on
    an already-running WordPress handle, configure a headless-checkout-friendly
    store (BTC currency, guest checkout, no taxes/shipping), and create one
    virtual product.

    Returns {"product_id": int} for the test to add to the cart. The BTCPay
    gateway is installed but left *unconfigured* — wiring it to BareBits is the
    behaviour under test (cashupay_configure_btcpay_plugin), so the test does
    that itself.
    """
    woo_src = _ensure_cached_plugin(
        WOOCOMMERCE_CACHE, WOOCOMMERCE_URL, WOOCOMMERCE_SHA256, "woocommerce"
    )
    btcpay_src = _ensure_cached_plugin(
        BTCPAY_WC_CACHE, BTCPAY_WC_URL, BTCPAY_WC_SHA256, "btcpay-greenfield-for-woocommerce"
    )

    plugins_dir = handle.wp_root / "wp-content" / "plugins"
    for src in (woo_src, btcpay_src):
        dst = plugins_dir / src.name
        if not dst.exists():
            os.symlink(src, dst)

    # WooCommerce first, then the gateway (which declares WooCommerce a
    # dependency). Activation runs the WC installer against the SQLite DB.
    handle.wp_cli("plugin", "activate", "woocommerce")
    handle.wp_cli("plugin", "activate", "btcpay-greenfield-for-woocommerce")

    # Store config: everything that would otherwise make a headless Store API
    # checkout demand extra input or hide the storefront. wp-cli reports a
    # no-op `option update` (value already equal to the default) as a failure,
    # so these are best-effort — the ones that matter are asserted below.
    for key, value in {
        "woocommerce_currency": "BTC",
        "woocommerce_price_num_decimals": "8",
        "woocommerce_enable_guest_checkout": "yes",
        "woocommerce_enable_checkout_login_reminder": "no",
        "woocommerce_calc_taxes": "no",
        "woocommerce_default_customer_address": "base",
        # WC 9.x "Launch Your Store" ships sites in coming-soon mode, which
        # 503s the front end and the Store API. Turn it off.
        "woocommerce_coming_soon": "no",
        # Skip the onboarding wizard so the REST endpoints behave normally.
        "woocommerce_task_list_hidden": "yes",
    }.items():
        handle.wp_cli("option", "update", key, value, check=False)
    handle.wp_cli(
        "option", "update", "woocommerce_onboarding_profile",
        '{"completed":true}', "--format=json", check=False,
    )
    # Currency drives the whole settlement math (BareBits reads BTC as fixed
    # 1e8 sats); confirm it actually took rather than trusting the no-op path.
    got_currency = handle.wp_cli("option", "get", "woocommerce_currency").stdout.strip()
    assert got_currency == "BTC", f"currency not set to BTC: {got_currency!r}"

    # A virtual product needs no shipping, which keeps the Store API checkout
    # payload to just a billing address.
    created = handle.wp_cli(
        "wc", "product", "create",
        "--name=Test Widget",
        f"--regular_price={WC_PRODUCT_PRICE_BTC}",
        "--virtual=true",
        "--manage_stock=false",
        "--status=publish",
        f"--user={WP_ADMIN_USER}",
        "--porcelain",
    )
    product_id = int(created.stdout.strip().splitlines()[-1])
    return {"product_id": product_id}


def _wp_config_php(*, port: int, data_dir: Path) -> str:
    return f"""<?php
// SQLite drop-in expects these even though they're unused.
define('DB_NAME', 'wordpress');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('DB_DIR', __DIR__ . '/wp-content/database/');
define('DB_FILE', 'wordpress.sqlite');

$table_prefix = 'wp_';

// Authentication keys — random per-fixture.
define('AUTH_KEY',         '{_rand_key()}');
define('SECURE_AUTH_KEY',  '{_rand_key()}');
define('LOGGED_IN_KEY',    '{_rand_key()}');
define('NONCE_KEY',        '{_rand_key()}');
define('AUTH_SALT',        '{_rand_key()}');
define('SECURE_AUTH_SALT', '{_rand_key()}');
define('LOGGED_IN_SALT',   '{_rand_key()}');
define('NONCE_SALT',       '{_rand_key()}');

define('WP_HOME',    'http://127.0.0.1:{port}');
define('WP_SITEURL', 'http://127.0.0.1:{port}');
define('WP_DEBUG', false);

// Force CashuPay data dir to the test's isolated location. (Guard against
// the env var also setting it via the router wrapper.)
if (!defined('CASHUPAY_DATA_DIR')) {{
    define('CASHUPAY_DATA_DIR', '{data_dir}');
}}

if (!defined('ABSPATH')) {{
    define('ABSPATH', __DIR__ . '/');
}}
require_once ABSPATH . 'wp-settings.php';
"""


def _rand_key() -> str:
    import secrets
    return secrets.token_urlsafe(48).replace("'", "x")
