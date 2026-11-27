"""WordPress fixture for testing the BareBits (barebits) plugin.

Uses wp-cli + the static PHP binary to stand up a fresh SQLite-backed WP
install per test, with the GPL plugin copied from wordpress/ so any local
change is exercised. Serving is backend-selectable (CASHUPAY_TEST_BACKEND,
see tests/fixtures/webserver.py): php -S through a tiny front-controller
wrapper (default), or real Apache / nginx containers. The `emulate_rewrites`
host-shape flag maps per backend — under php -S it toggles the wrapper's
.htaccess emulation snippet, under Apache it toggles AllowOverride (the real
unpacked .htaccess does the work), under nginx it picks a site config with or
without the /barebits rules and PATH_INFO support.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import sqlite3
import subprocess
import time
import urllib.request
import uuid
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import requests

from . import binaries, ports, webserver

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
TESTS_DIR = REPO_ROOT / "tests"
BIN_DIR = TESTS_DIR / "bin"

# The WordPress core the tier runs on. CASHUPAY_WP_CORE_VERSION overrides the
# pin for one run — any version wordpress.org serves a tarball for, betas and
# release candidates included ("7.2-RC3") — so an upcoming release can be
# validated against the whole tier before the pin (and the readme's "Tested
# up to") move. The cache dir is versioned, so overrides never clobber the
# pinned core.
WP_VERSION = os.environ.get("CASHUPAY_WP_CORE_VERSION", "").strip() or "7.1"
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
# deterministic end-to-end checkout. WooCommerce 11.3.1 (Requires at least:
# 7.0) — bumped from 9.6.2 alongside the WordPress 7.2 validation
# (2026-11-26); a WooCommerce bump is its own shake-out (checkout flows,
# HPOS goldens) and stays a deliberate, separate decision from core bumps.
# What this one shook out: WooCommerce >= 10.1 rounds ORDER totals to 6
# decimals on this 8-decimal BTC shop (fixtures/wc_checkout.py,
# WC_ORDER_TOTAL_DECIMALS); the discount tests assert WooCommerce's figure.
# The BTCPay plugin verifies the exact same `sha256=` HMAC BareBits emits, so
# no protocol shim is needed.
WOOCOMMERCE_VERSION = "11.3.1"
WOOCOMMERCE_URL = f"https://downloads.wordpress.org/plugin/woocommerce.{WOOCOMMERCE_VERSION}.zip"
WOOCOMMERCE_SHA256 = "1502600c738840f7f9ebfa2f11e1aaff087221a5fc5a2006c51be2d874ae2a09"
WOOCOMMERCE_CACHE = BIN_DIR / f"woocommerce-{WOOCOMMERCE_VERSION}"

BTCPAY_WC_VERSION = "2.8.0"
BTCPAY_WC_URL = (
    f"https://downloads.wordpress.org/plugin/btcpay-greenfield-for-woocommerce.{BTCPAY_WC_VERSION}.zip"
)
BTCPAY_WC_SHA256 = "b06a4da4835d984ddd870c3bfafb6fc4c524fe0ef988f22cd1575a8a7b77d236"
BTCPAY_WC_CACHE = BIN_DIR / f"btcpay-greenfield-{BTCPAY_WC_VERSION}"

# WordPress.org's Plugin Check (PCP) — the checks the plugin directory runs
# on submission. Pinned per version (the un-versioned "latest" zip churns).
PLUGIN_CHECK_VERSION = "2.1.0"
PLUGIN_CHECK_URL = f"https://downloads.wordpress.org/plugin/plugin-check.{PLUGIN_CHECK_VERSION}.zip"
PLUGIN_CHECK_SHA256 = "6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4"
PLUGIN_CHECK_CACHE = BIN_DIR / f"plugin-check-{PLUGIN_CHECK_VERSION}"

@dataclass
class WordPressHandle:
    server: webserver.ManagedServer
    port: int
    wp_root: Path
    data_dir: Path
    workdir: Path
    php_exe: Path
    wp_cli_phar: Path
    # Set when the install came from (or built) the WooCommerce golden
    # template: the pre-created virtual product's id. None on plain installs.
    woo_product_id: int | None = None
    # Set by start_wordpress(debug_log=True): the WP_DEBUG_LOG file and the
    # deprecation probe's JSON-lines file (see plugin_debug_findings).
    debug_log_path: Path | None = None
    debug_probe_path: Path | None = None

    @property
    def process(self) -> subprocess.Popen[bytes] | None:
        """Back-compat accessor: the php -S Popen, None on container backends."""
        return self.server.popen

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def barebits_dir(self) -> Path:
        """Where the plugin's install-alongside flow puts the BareBits server
        (ABSPATH/barebits, i.e. inside the served docroot)."""
        return self.wp_root / "barebits"

    @property
    def barebits_url(self) -> str:
        return f"{self.url}/barebits"

    @property
    def barebits_gateway_url(self) -> str:
        """The btcpay_gf_url the WooCommerce wiring writes for the alongside
        install: api.php's query-transport BASE, so every URL the BTCPay
        gateway builds by concatenation lands directly on api.php — one
        loopback deep on every host (see barebits_gateway_base_url)."""
        return f"{self.barebits_url}/api.php?cashupay_path="

    @property
    def barebits_data_dir(self) -> Path:
        """The data dir the installer prefers: outside the docroot, a sibling
        of ABSPATH, namespaced per site so co-hosted WordPress installs can
        never share one wallet database (dirname(wp_root)/barebits-data-<12
        hex of sha256(ABSPATH)>; ABSPATH carries WP's trailing slash)."""
        digest = hashlib.sha256(f"{self.wp_root}/".encode()).hexdigest()[:12]
        return self.workdir / f"barebits-data-{digest}"

    @property
    def db_path(self) -> Path:
        """The BareBits SQLite DB of an alongside install (preferred outside-
        docroot location first, then the in-install fallback)."""
        outside = self.barebits_data_dir / "cashupay.sqlite"
        if outside.exists():
            return outside
        return self.barebits_dir / "data" / "cashupay.sqlite"

    def plugin_debug_findings(self) -> list[str]:
        """Every PHP error and WordPress deprecation / doing-it-wrong this
        install recorded that is attributable to the barebits plugin — the
        list a WordPress-compatibility test asserts empty. Requires
        start_wordpress(debug_log=True); see classify_debug_findings for the
        attribution rules."""
        if self.debug_log_path is None or self.debug_probe_path is None:
            raise RuntimeError("plugin_debug_findings needs start_wordpress(debug_log=True)")
        log_lines = (
            self.debug_log_path.read_text(errors="replace").splitlines()
            if self.debug_log_path.is_file() else []
        )
        probe_entries: list[dict] = []
        if self.debug_probe_path.is_file():
            for raw in self.debug_probe_path.read_text(errors="replace").splitlines():
                raw = raw.strip()
                if raw:
                    probe_entries.append(json.loads(raw))
        return classify_debug_findings(
            log_lines, probe_entries, plugin_dir=self.wp_root / "wp-content" / "plugins" / "barebits"
        )

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
        # Generous ceiling (plugin installs legitimately take a while) that
        # still fails loudly instead of eating the whole CI job cap when a WP
        # boot deadlocks — seen when a wiped btcpay_gf_version made the BTCPay
        # plugin's boot migrations self-request this site recursively.
        #
        # Retry on SQLite's "database is locked": the sqlite-database-
        # integration drop-in has no busy-timeout, so a wp-cli write that
        # races the live webserver's workers (WooCommerce/Action Scheduler
        # loopbacks, the cron pinger) dies with SQLSTATE 5. Retrying the whole
        # command is safe for the idempotent option writes/reads the tests run.
        for attempt in range(5):
            result = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=300)
            if result.returncode == 0 or "database is locked" not in (result.stdout + result.stderr):
                break
            time.sleep(0.3 * (attempt + 1))
        if check and result.returncode != 0:
            raise RuntimeError(
                f"wp-cli failed ({result.returncode}) for {args}\n"
                f"stdout: {result.stdout}\n"
                f"stderr: {result.stderr}"
            )
        return result

    def set_option(self, name: str, value: str) -> None:
        """Write an option and confirm it landed, retrying through SQLite
        lock contention. Unlike `wp eval "update_option(...)"` — whose write
        can be silently lost under a lock while eval still exits 0 — this
        reads the value back, so a lost write surfaces as a loud failure here
        instead of a mysterious one in the code under test."""
        for attempt in range(5):
            self.wp_cli("option", "update", name, value)
            got = self.wp_cli("option", "get", name, check=False).stdout.strip()
            if got == value:
                return
            time.sleep(0.3 * (attempt + 1))
        raise RuntimeError(f"option {name!r} did not persist as {value!r} (got {got!r})")

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


def _ensure_cached_plugin(
    cache_dir: Path, url: str, sha256: str, slug: str, main_file_name: str | None = None
) -> Path:
    """Download + extract a wp.org plugin zip once. Returns the extracted plugin
    directory (cache_dir/<slug>), whose basename matches the plugin slug so
    `wp plugin activate <slug>` works. Idempotent across runs and tests.
    main_file_name overrides the {slug}.php convention for plugins whose main
    file is named differently (plugin-check ships plugin.php)."""
    extracted = cache_dir / slug
    main_file = extracted / (main_file_name or f"{slug}.php")
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


def install_plugin_check(wp: "WordPressHandle") -> None:
    """Copy the pinned Plugin Check plugin into a WP install and activate it,
    making `wp plugin check <slug>` available on that install's wp-cli."""
    extracted = _ensure_cached_plugin(
        PLUGIN_CHECK_CACHE, PLUGIN_CHECK_URL, PLUGIN_CHECK_SHA256,
        "plugin-check", main_file_name="plugin.php",
    )
    dest = wp.wp_root / "wp-content" / "plugins" / "plugin-check"
    if not dest.exists():
        shutil.copytree(extracted, dest)
    wp.wp_cli("plugin", "activate", "plugin-check")


# ---------- plugin tree assembly ----------


def _assemble_plugin(plugin_dir: Path) -> None:
    """Copy the GPL plugin (wordpress/ in the repo) into wp-content/plugins/
    barebits. Plain copies rather than symlinks: WordPress's activation-hook
    bookkeeping keys off realpath(__FILE__), so a symlinked plugin dir breaks
    register_activation_hook. The plugin is a handful of small files, so a
    per-test copy is cheap; it mirrors scripts/build-wordpress-plugin.sh
    exactly (no server code, no vendor/)."""
    src_root = REPO_ROOT / "wordpress"
    plugin_dir.mkdir(parents=True, exist_ok=True)
    for src in list(src_root.glob("*.php")) + [src_root / "readme.txt"]:
        dst = plugin_dir / src.name
        if not dst.exists():
            shutil.copy(src, dst)
    assets_src = src_root / "assets"
    assets_dst = plugin_dir / "assets"
    if assets_src.is_dir() and not assets_dst.exists():
        shutil.copytree(assets_src, assets_dst)


# ---------- PHP errors rendered into a page ----------

_PHP_ERROR_IN_PAGE = re.compile(
    r"(?:<b>)?(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:</b>)?:\s+.{0,400}?"
    r" in (?:<b>)?\S+?(?:</b>)? on line (?:<b>)?\d+",
    re.S,
)


def php_error_in_page(body: str) -> str | None:
    """The first PHP error PHP itself rendered into an HTML response ("Warning:
    ... in /path/file.php on line N", with or without html_errors markup), or
    None. Use this instead of a bare `"Warning:" in body`: plugins print
    the word in their own UI strings (the BTCPay gateway's order-states
    warning rides every admin page as a localized script variable)."""
    m = _PHP_ERROR_IN_PAGE.search(body)
    return m.group(0) if m else None


# ---------- WordPress-compatibility debug probe ----------

# The plugin's wp.org text domain (wordpress/barebits.php header; pinned by
# test_wp_plugin_check.py). WordPress names the domain, not the file, when it
# reports a translation loaded too early (_load_textdomain_just_in_time).
PLUGIN_TEXT_DOMAIN = "barebits-lightning-payments-via-bitcoin"

_DEBUG_PROBE_PHP = r"""<?php
/**
 * Test-only probe (tests/fixtures/wordpress.py, start_wordpress(debug_log=True)).
 *
 * WP_DEBUG_LOG records a WordPress-level deprecation with the file that
 * called trigger_error() — wp-includes/functions.php, never the plugin that
 * made the deprecated call. This mu-plugin listens to the actions WordPress
 * fires right before that and writes each report with the functions and
 * files on its call stack, so the Python side can attribute it to the
 * plugin under test (WordPressHandle.plugin_debug_findings).
 */
$barebits_probe_log = '%(probe_path)s';
$barebits_probe_record = function (string $kind, array $fields) use ($barebits_probe_log): void {
    $frames = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
        $function = $frame['function'] ?? '';
        if (!empty($frame['class'])) {
            $function = $frame['class'] . '::' . $function;
        }
        $frames[] = [
            'file' => $frame['file'] ?? '',
            'line' => $frame['line'] ?? 0,
            'function' => $function,
        ];
    }
    $fields['kind'] = $kind;
    $fields['trace'] = $frames;
    $fields['request'] = PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REQUEST_URI'] ?? '');
    file_put_contents($barebits_probe_log, json_encode($fields) . "\n", FILE_APPEND | LOCK_EX);
};
add_action('deprecated_function_run', function ($function, $replacement, $version) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_function', ['function' => (string) $function, 'replacement' => (string) $replacement, 'version' => (string) $version]);
}, 10, 3);
add_action('deprecated_constructor_run', function ($class, $version, $parent_class) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_constructor', ['class' => (string) $class, 'version' => (string) $version, 'parent_class' => (string) $parent_class]);
}, 10, 3);
add_action('deprecated_class_run', function ($class, $replacement, $version) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_class', ['class' => (string) $class, 'replacement' => (string) $replacement, 'version' => (string) $version]);
}, 10, 3);
add_action('deprecated_file_included', function ($file, $replacement, $version, $message) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_file', ['file' => (string) $file, 'replacement' => (string) $replacement, 'version' => (string) $version, 'message' => (string) $message]);
}, 10, 4);
add_action('deprecated_argument_run', function ($function, $message, $version) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_argument', ['function' => (string) $function, 'message' => (string) $message, 'version' => (string) $version]);
}, 10, 3);
add_action('deprecated_hook_run', function ($hook, $replacement, $version, $message) use ($barebits_probe_record) {
    $barebits_probe_record('deprecated_hook', ['hook' => (string) $hook, 'replacement' => (string) $replacement, 'version' => (string) $version, 'message' => (string) $message]);
}, 10, 4);
add_action('doing_it_wrong_run', function ($function, $message, $version) use ($barebits_probe_record) {
    $barebits_probe_record('doing_it_wrong', ['function' => (string) $function, 'message' => (string) $message, 'version' => (string) $version]);
}, 10, 3);
"""


def _install_debug_probe(wp_root: Path, probe_path: Path) -> None:
    """Drop the deprecation probe mu-plugin into the install (clones overwrite
    the golden's wp-content, so this is per handle, like wp-config.php)."""
    mu = wp_root / "wp-content" / "mu-plugins"
    mu.mkdir(parents=True, exist_ok=True)
    (mu / "barebits-test-debug-probe.php").write_text(
        _DEBUG_PROBE_PHP % {"probe_path": str(probe_path).replace("\\", "\\\\").replace("'", "\\'")}
    )


_PHP_LOG_LINE = re.compile(
    r"PHP (?P<level>Deprecated|Warning|Notice|Fatal error|Parse error|Recoverable fatal error):"
    r"\s+(?P<message>.*?) in (?P<file>\S+?)(?::\d+)? on line (?P<line>\d+)\s*$"
)


def _is_plugin_path(path: str, plugin_dir: Path) -> bool:
    path = path.replace("\\", "/")
    return path.startswith(f"{plugin_dir.as_posix()}/") or "/wp-content/plugins/barebits/" in path


def _is_core_path(path: str) -> bool:
    """A frame WordPress itself (or the test infrastructure) owns: anything
    outside wp-content — core files, the fixture's router wrapper, wp-cli's
    phar, eval()'d code. Only wp-content frames can be culprits."""
    return "/wp-content/" not in path.replace("\\", "/")


def _plugin_source_mentions(plugin_dir: Path, needle: str) -> bool:
    if not needle:
        return False
    for php in plugin_dir.glob("*.php"):
        try:
            if needle in php.read_text(errors="replace"):
                return True
        except OSError:
            continue
    return False


def _probe_entry_is_ours(entry: dict, plugin_dir: Path) -> bool:
    """Attribute one probe record to the plugin under test.

    The deprecated call's DIRECT caller is the frame whose `function` is the
    deprecated function/class (PHP backtrace frames carry the call site's
    file with the callee's name), so a deprecated WooCommerce helper called
    from WooCommerce's own internals is not pinned on the plugin that merely
    called WooCommerce. Deprecated hooks are fired by whoever runs the hook,
    so those are ours when the plugin's source names the hook. A translation
    loaded too early is reported by domain, so the plugin's text domain in
    the message counts. Anything else falls back to the first wp-content
    frame on the stack."""
    kind = entry.get("kind", "")
    trace = entry.get("trace") or []
    message = str(entry.get("message", ""))

    if kind == "deprecated_file" and _is_plugin_path(str(entry.get("file", "")), plugin_dir):
        return True
    if kind == "deprecated_hook":
        return _plugin_source_mentions(plugin_dir, str(entry.get("hook", "")))
    if PLUGIN_TEXT_DOMAIN in message:
        return True

    target = str(entry.get("function") or entry.get("class") or "").lower()
    if target:
        for frame in trace:
            fn = str(frame.get("function", "")).lower()
            if fn == target or (
                kind in ("deprecated_constructor", "deprecated_class")
                and fn == f"{target}::__construct"
            ):
                return _is_plugin_path(str(frame.get("file", "")), plugin_dir)
    for frame in trace:
        file = str(frame.get("file", ""))
        if file and not _is_core_path(file) and "mu-plugins/barebits-test-" not in file:
            return _is_plugin_path(file, plugin_dir)
    return False


def _format_probe_entry(entry: dict) -> str:
    kind = entry.get("kind", "?")
    subject = entry.get("function") or entry.get("class") or entry.get("hook") or entry.get("file") or ""
    bits = [f"{kind}: {subject}"]
    for key in ("version", "replacement", "message"):
        if entry.get(key):
            bits.append(f"{key}={entry[key]!s}")
    culprit = next(
        (f"{f.get('file')}:{f.get('line')}" for f in (entry.get("trace") or [])
         if f.get("file") and not _is_core_path(str(f["file"]))
         and "mu-plugins/barebits-test-" not in str(f["file"])),
        "",
    )
    if culprit:
        bits.append(f"at {culprit}")
    if entry.get("request"):
        bits.append(f"during {entry['request']}")
    return " | ".join(bits)


def classify_debug_findings(
    log_lines: list[str], probe_entries: list[dict], *, plugin_dir: Path
) -> list[str]:
    """Reduce a WP_DEBUG_LOG and the probe's records to the findings that are
    the barebits plugin's: PHP-engine errors raised in (or naming) a plugin
    file, and WordPress deprecation / doing-it-wrong reports whose direct
    caller is the plugin (see _probe_entry_is_ours). Third-party noise
    (WooCommerce, the gateway, the SQLite drop-in, wp-cli) is dropped."""
    findings: list[str] = []
    for line in log_lines:
        m = _PHP_LOG_LINE.search(line)
        if not m:
            continue
        if _is_plugin_path(m.group("file"), plugin_dir) or _is_plugin_path(m.group("message"), plugin_dir):
            findings.append(line.strip())
    for entry in probe_entries:
        if _probe_entry_is_ours(entry, plugin_dir):
            findings.append(_format_probe_entry(entry))
    return findings


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
    return _ensure_plugin_zip("CASHUPAY_WP_PLUGIN_ZIP", "barebits_wordpress_plugin.zip")


def ensure_wp_plugin_wporg_zip() -> Path:
    """The wordpress.org variant of the plugin zip (no installer.php — the
    directory's guidelines forbid fetching executable code). Same contract as
    ensure_wp_plugin_zip: CI passes the prebuilt artifact via
    CASHUPAY_WP_PLUGIN_WPORG_ZIP, local runs build it (the build script
    produces both variants in one run)."""
    return _ensure_plugin_zip("CASHUPAY_WP_PLUGIN_WPORG_ZIP", "barebits_wordpress_plugin_wporg.zip")


def _ensure_plugin_zip(env_var: str, zip_name: str) -> Path:
    env_zip = os.environ.get(env_var)
    if env_zip:
        p = Path(env_zip)
        if p.is_file():
            return p
        raise RuntimeError(
            f"{env_var}={env_zip!r} but that file does not exist"
        )

    zip_path = REPO_ROOT / "build" / zip_name
    php_exe = binaries.ensure(binaries.PHP)["php"]
    script = REPO_ROOT / "scripts" / "build-wordpress-plugin.sh"
    env = os.environ.copy()
    env["PHP_BIN"] = str(php_exe)
    print(f"[wp] building plugin zips via {script.name} ...")
    subprocess.run(["bash", str(script)], cwd=str(REPO_ROOT), env=env, check=True)
    if not zip_path.is_file():
        raise RuntimeError(f"build-wordpress-plugin.sh did not produce build/{zip_name}")
    return zip_path


# ---------- fixture ----------

ROUTER_WRAPPER_TEMPLATE = """<?php
// WordPress front controller — fall through to wp's index.php on misses.
// (The release-server constants the plugin's installer reads live in
// wp-config.php — the same place a real operator puts them — so they apply
// identically on every backend, not just under this php -S wrapper.)
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
{barebits_rewrites}require {wp_index!r};
"""

# The BareBits server the plugin installed alongside WordPress lives at
# /barebits inside this docroot. Apache would apply ITS .htaccess there; a
# router script gets no .htaccess, so this block emulates the rewrites the
# stack depends on: the Greenfield API (the WooCommerce gateway calls
# {btcpay_gf_url}/api/v1/... on the bare base URL), the extensionless pairing
# endpoint, and PATH_INFO-style routing (Apache's AcceptPathInfo — the
# BareBits admin canonicalizes to /barebits/admin.php/<view>). Everything else
# under /barebits is real files (served above) or the app's own router.php
# URLs. Omitted entirely for the "hostile host" fixture (emulate_rewrites=
# False): an nginx-style server that executes *.php files but applies no
# rewrite rules at all, on which the setup wizard must still complete.
BAREBITS_REWRITES_SNIPPET = """\
if (preg_match('#^(/barebits/[^/]+\\.php)(/.*)$#', $uri, $m)
        && is_file($docRoot . $m[1])) {
    $_SERVER['PATH_INFO'] = $m[2];
    $_SERVER['SCRIPT_NAME'] = $m[1];
    require $docRoot . $m[1];
    return true;
}
if (preg_match('#^/barebits(/api/v1/.*)$#', $uri, $m)
        && is_file($docRoot . '/barebits/api.php')) {
    $_SERVER['PATH_INFO'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/barebits/api.php';
    require $docRoot . '/barebits/api.php';
    return true;
}
if ($uri === '/barebits/api-keys/authorize'
        && is_file($docRoot . '/barebits/api-keys/authorize.php')) {
    $_SERVER['SCRIPT_NAME'] = '/barebits/api-keys/authorize.php';
    require $docRoot . '/barebits/api-keys/authorize.php';
    return true;
}
"""


def start_wordpress(
    workdir: Path,
    *,
    install_barebits: bool = True,
    release_api_base: str | None = None,
    release_channel: str | None = None,
    emulate_rewrites: bool = True,
    php_workers: int | None = None,
    with_woocommerce: bool = False,
    server_release_api_base: str | None = None,
    updater_enabled: bool = False,
    extra_env: dict[str, str] | None = None,
    debug_log: bool = False,
    _from_template: bool = True,
) -> WordPressHandle:
    """Stand up a fresh SQLite-backed WordPress install.

    install_barebits=True (default) copies the GPL plugin from wordpress/ and
    activates it — what every WP test wants. install_barebits=False brings
    WordPress up with NO barebits plugin, so a test can install the real built
    zip itself (`wp plugin install <zip>`) and exercise the shipped artifact.

    release_api_base points the plugin's "install BareBits alongside" flow at
    a local fixture release server instead of api.github.com; release_channel
    pins the plugin's release channel the way a wp-config.php constant would
    ('testing' makes it read the fixture's /releases listing).

    emulate_rewrites=False serves /barebits WITHOUT the Apache-.htaccess
    rewrite emulation — a "hostile host" (think nginx with a plain WordPress
    config) that executes real *.php files but routes everything else into
    WordPress. The setup wizard must survive such hosts.

    php_workers pins PHP_CLI_SERVER_WORKERS for this instance (default 6, see
    below) — a small value emulates hosts with tight per-site PHP pools
    (Local WP), where nested same-site loopback requests starve into
    timeouts.

    with_woocommerce=True yields an install that already carries WooCommerce +
    the BTCPay gateway + the configured BTC store and one virtual product
    (`install_woocommerce`'s end state); the product id is on
    `handle.woo_product_id`. Combined with install_barebits=False it is a
    shop with NO barebits plugin yet — for tests that install a specific
    plugin zip (a past release's) themselves.

    server_release_api_base / updater_enabled / extra_env shape the BareBits
    server the plugin installs alongside (it runs inside this site's PHP):
    the releases URL ITS auto-updater reads (CASHUPAY_RELEASE_API_BASE —
    distinct from release_api_base, which is what the PLUGIN downloads the
    server from), whether that updater may run at all (every other fixture
    leaves the kill switch to the environment; the upgrade tier needs it
    live), and further CASHUPAY_* environment for it.

    debug_log=True runs the site with WP_DEBUG on, PHP errors logged to
    <workdir>/wp-debug.log (never displayed), plus a test-only mu-plugin that
    records every WordPress-level deprecation and _doing_it_wrong() with the
    files on its call stack to <workdir>/wp-debug-probe.jsonl. Together they
    feed `handle.plugin_debug_findings()`, the WordPress-compatibility check
    (a new core's deprecations surface there before they become fatals).
    Off by default: WP_DEBUG changes nothing functional, but the probe is
    only read by the compatibility tests.

    Golden templates: the first install of each DB-affecting shape (bare /
    plugin / plugin+woocommerce / woocommerce-only) per pytest session is
    kept as a golden tree,
    and later installs of that shape are file-copies of it — skipping
    `wp core install` and plugin activations entirely. Safe because every
    URL-bearing value WordPress persists is overridden at runtime by the
    WP_HOME/WP_SITEURL constants in the per-clone wp-config.php (regenerated
    with the clone's own port), and release_api_base/release_channel/
    emulate_rewrites/php_workers are all serve-time parameters, not DB state.
    Set CASHUPAY_WP_NO_TEMPLATE=1 to force the old fresh-install-per-test
    behaviour.
    """
    php_exe = binaries.ensure(binaries.PHP)["php"]
    wp_cli_phar = binaries.ensure_file(binaries.WP_CLI)

    workdir.mkdir(parents=True, exist_ok=True)
    wp_root = workdir / "wp"
    # The dir the plugin's installer prefers for the BareBits data dir is a
    # sibling of the docroot, namespaced per site (dirname(ABSPATH)/
    # barebits-data-<12 hex of sha256(ABSPATH)>) — that's inside this
    # per-test workdir, so it needs no pre-creation and stays isolated.
    data_dir = workdir / (
        "barebits-data-" + hashlib.sha256(f"{wp_root}/".encode()).hexdigest()[:12]
    )

    use_template = (
        _from_template
        and not os.environ.get("CASHUPAY_WP_NO_TEMPLATE")
        and not wp_root.exists()
    )
    woo_product_id: int | None = None
    if use_template:
        golden_wp, meta = _ensure_golden_install(
            install_barebits=install_barebits, with_woocommerce=with_woocommerce
        )
        # cp -a: preserves the symlinked wp.org plugins and is much faster
        # than shutil.copytree on WP core's thousands of small files. The
        # suite is Linux-only (tests/README.md).
        subprocess.run(
            ["cp", "-a", str(golden_wp), str(wp_root)], check=True, timeout=120
        )
        woo_product_id = meta.get("product_id")

    # 1. Copy WP core into wp_root (fresh per test; isolated).
    if not use_template:
        core = _ensure_wp_core()
        if not wp_root.exists():
            shutil.copytree(core, wp_root)

    # 1b. The standard WordPress .htaccess block, which every real Apache WP
    #     install has. wp-cli's `rewrite --hard` cannot write it (CLI SAPI ⇒
    #     got_mod_rewrite() false), and without it pretty-permalink routes
    #     (/wp-json/... — the WooCommerce Store API) 404 on the apache
    #     backend. Inert under php -S (the wrapper never reads .htaccess) and
    #     under the hostile AllowOverride None shape; /barebits/* keeps its
    #     own .htaccess, which takes per-directory precedence.
    wp_htaccess = wp_root / ".htaccess"
    if not wp_htaccess.exists():
        wp_htaccess.write_text(
            "# BEGIN WordPress\n"
            "<IfModule mod_rewrite.c>\n"
            "RewriteEngine On\n"
            "RewriteBase /\n"
            "RewriteRule ^index\\.php$ - [L]\n"
            "RewriteCond %{REQUEST_FILENAME} !-f\n"
            "RewriteCond %{REQUEST_FILENAME} !-d\n"
            "RewriteRule . /index.php [L]\n"
            "</IfModule>\n"
            "# END WordPress\n"
        )

    # 2. SQLite drop-in.
    sqlite_plugin = _ensure_sqlite_plugin()
    target_plugin_dir = wp_root / "wp-content" / "plugins" / "sqlite-database-integration"
    if not target_plugin_dir.exists():
        shutil.copytree(sqlite_plugin, target_plugin_dir)
    drop_in = wp_root / "wp-content" / "db.php"
    if not drop_in.exists():
        shutil.copy(sqlite_plugin / "db.copy", drop_in)

    # 3. Barebits plugin (symlinks for live source). Skipped when the caller
    #    will install the built zip itself.
    if install_barebits:
        _assemble_plugin(wp_root / "wp-content" / "plugins" / "barebits")

    # 4. wp-config.php with SQLite config + WP_HOME (+ the release-server
    #    constants the plugin's installer reads, when the caller provides them).
    #    A template clone force-overwrites the golden's config: the clone's own
    #    port and release constants live here, and the WP_HOME/WP_SITEURL
    #    constants are what make the golden's DB-stored URLs irrelevant.
    port = ports.allocate(1)[0]
    config = wp_root / "wp-config.php"
    debug_log_path = workdir / "wp-debug.log" if debug_log else None
    debug_probe_path = workdir / "wp-debug-probe.jsonl" if debug_log else None
    if use_template or not config.exists():
        config.write_text(
            _wp_config_php(
                port=port,
                release_api_base=release_api_base,
                release_channel=release_channel,
                debug_log_path=debug_log_path,
            )
        )
    if debug_probe_path is not None:
        _install_debug_probe(wp_root, debug_probe_path)

    # 5. Router wrapper.
    router_wrapper = workdir / "wp-router.php"
    router_wrapper.write_text(
        ROUTER_WRAPPER_TEMPLATE.format(
            wp_root=str(wp_root),
            wp_index=str(wp_root / "index.php"),
            barebits_rewrites=BAREBITS_REWRITES_SNIPPET if emulate_rewrites else "",
        )
    )

    # 6. wp core install (uses the static PHP via wp-cli). A template clone
    #    carries the golden's installed DB, so both install and activation
    #    are already done.
    if not use_template:
        install_env = os.environ.copy()
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

        # 7. Activate barebits plugin (unless the caller installs the zip
        #    itself).
        if install_barebits:
            subprocess.run(
                [
                    str(php_exe), str(wp_cli_phar),
                    f"--path={wp_root}",
                    "--allow-root",
                    "plugin", "activate", "barebits",
                ],
                env=install_env,
                check=True,
                capture_output=True,
                text=True,
            )

    # 8. Serve the tree on the active backend.
    env = os.environ.copy()
    # SafeHttp blocks loopback/RFC1918 destinations by default; the webhook
    # sink and the alongside BareBits install both live on 127.0.0.1, so the
    # served stack opts in.
    env.setdefault("CASHUPAY_ALLOW_PRIVATE_ENDPOINTS", "1")
    if server_release_api_base:
        env["CASHUPAY_RELEASE_API_BASE"] = server_release_api_base
    elif release_api_base:
        env["CASHUPAY_RELEASE_API_BASE"] = release_api_base
    if release_channel:
        env["CASHUPAY_RELEASE_CHANNEL"] = release_channel
    if updater_enabled:
        # The alongside install's auto-updater must RUN: override an outer
        # shell's kill switch explicitly ("0" means not disabled).
        env["CASHUPAY_UPDATER_DISABLED"] = "0"
    if extra_env:
        env.update(extra_env)
    # A multi-worker pool on every backend. A single-threaded server deadlocks
    # on any same-server loopback request, and this stack makes several: the
    # BTCPay gateway calls the BareBits Greenfield API (same host) during
    # checkout, BareBits' webhook cron POSTs back to WooCommerce's wc-api
    # endpoint during the cron request, and the plugin's WP-cron pinger GETs
    # the alongside install's cron.php. Maps to PHP_CLI_SERVER_WORKERS under
    # php -S, prefork MaxRequestWorkers under Apache, pm.max_children under
    # FPM; a small value emulates hosts with tight per-site pools (Local WP).
    workers = php_workers if php_workers is not None else 6

    server = webserver.start_php_site(
        port=port,
        docroot=wp_root,
        workdir=workdir,
        env=env,
        role="wordpress",
        log_path=workdir / "wp-server.log",
        workers=workers,
        phps_router=router_wrapper,
        phps_cwd=wp_root,
        phps_binary=str(php_exe),
        # Host-shape mapping: friendly Apache honours the real .htaccess the
        # install-alongside flow unpacks at wp_root/barebits/.htaccess; the
        # hostile host is AllowOverride None.
        apache_allow_override=emulate_rewrites,
        nginx_site_conf=_wp_nginx_site(
            port=port, wp_root=wp_root, friendly=emulate_rewrites
        ),
    )

    handle = WordPressHandle(
        server=server,
        port=port,
        wp_root=wp_root,
        data_dir=data_dir,
        workdir=workdir,
        php_exe=php_exe,
        wp_cli_phar=wp_cli_phar,
        woo_product_id=woo_product_id,
        debug_log_path=debug_log_path,
        debug_probe_path=debug_probe_path,
    )
    try:
        handle.wait_ready()
        if with_woocommerce and woo_product_id is None:
            # Non-template path (golden build itself, or templates disabled):
            # install WooCommerce for real on the now-running site.
            info = install_woocommerce(handle)
            handle.woo_product_id = info["product_id"]
    except Exception:
        stop_wordpress(handle)
        raise
    return handle


# ---------- golden templates (one real install per shape per session) ----------
#
# Cache key is the install's DB-affecting shape only. release_api_base /
# release_channel are wp-config constants (rewritten per clone), and
# emulate_rewrites / php_workers / server_release_api_base / updater_enabled /
# extra_env only shape serving — none of them touch the installed tree or the
# SQLite DB.
_GOLDEN_CACHE: dict[str, tuple[Path, dict]] = {}


def _golden_template_key(*, install_barebits: bool, with_woocommerce: bool) -> str:
    if with_woocommerce:
        # "woo-bare": the shop with WooCommerce + the BTCPay gateway but no
        # barebits plugin — the upgrade tier installs a past release's plugin
        # zip into it.
        return "woo" if install_barebits else "woo-bare"
    return "plugin" if install_barebits else "bare"


def _ensure_golden_install(
    *, install_barebits: bool, with_woocommerce: bool
) -> tuple[Path, dict]:
    """Build (once per session) and return (golden wp_root, meta) for the
    requested shape. The golden is produced by the exact same code path a
    templateless install takes — server booted, wizard-equivalent wp-cli
    install run, plugins activated, then stopped — so clones inherit a state
    byte-identical to what each test used to build for itself."""
    key = _golden_template_key(
        install_barebits=install_barebits, with_woocommerce=with_woocommerce
    )
    if key in _GOLDEN_CACHE:
        return _GOLDEN_CACHE[key]

    workdir = TESTS_DIR.parent / "app" / ".tmp" / f"wp-golden-{key}-{uuid.uuid4().hex[:8]}"
    print(f"[wp] building golden '{key}' WordPress template (once per session) ...")
    handle = start_wordpress(
        workdir,
        install_barebits=install_barebits,
        with_woocommerce=with_woocommerce,
        _from_template=False,
    )
    try:
        meta: dict = {}
        if handle.woo_product_id is not None:
            meta["product_id"] = handle.woo_product_id
    finally:
        stop_wordpress(handle)
    # If a WAL journal was opted into (CASHUPAY_WP_SQLITE_JOURNAL_MODE), fold
    # the -wal back into the main file so every clone starts from one
    # self-contained database. A no-op for the default rollback journal.
    _checkpoint_sqlite(handle.wp_root / "wp-content" / "database" / "wordpress.sqlite")
    # Serve-time leftovers (router wrapper, server log) stay outside wp/ and
    # are never cloned; the golden's wp-config.php is overwritten per clone.
    (workdir / "template-meta.json").write_text(json.dumps({"key": key, **meta}))
    _GOLDEN_CACHE[key] = (handle.wp_root, meta)
    return _GOLDEN_CACHE[key]


def _checkpoint_sqlite(db: Path) -> None:
    """Best-effort `PRAGMA wal_checkpoint(TRUNCATE)` on a WAL-mode SQLite file
    nothing else has open. Leaves the mode as WAL (it is persisted in the file
    header), only empties the side files."""
    if not db.is_file():
        return
    try:
        conn = sqlite3.connect(str(db), timeout=10)
        try:
            conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        finally:
            conn.close()
    except sqlite3.Error:
        pass


def stop_wordpress(handle: WordPressHandle) -> None:
    handle.server.stop(grace_s=10.0)


# ---------- nginx site config (container backend) ----------

def _wp_nginx_site(*, port: int, wp_root: Path, friendly: bool) -> str:
    """The WordPress server block for the nginx backend.

    friendly=True models the canonical well-configured host: WP front
    controller, PATH_INFO-capable PHP location, and the /barebits rules the
    install's own .htaccess would provide under Apache. friendly=False is the
    genuinely rewrite-hostile host — a stock WP nginx config whose plain
    `\\.php$` location executes real files with no PATH_INFO and routes
    everything else into WordPress (the fixture's `emulate_rewrites=False`
    contract).
    """
    barebits_rules = """
    # What the BareBits install's .htaccess provides under Apache: the
    # extensionless pairing endpoint and the Greenfield API on the bare base.
    location = /barebits/api-keys/authorize {
        rewrite ^ /barebits/api-keys/authorize.php last;
    }
    location ^~ /barebits/api/v1/ {
        rewrite ^/barebits(/api/v1/.*)$ /barebits/api.php$1 last;
    }
""" if friendly else ""

    if friendly:
        php_location = """
    location ~ [^/]\\.php(/|$) {
        fastcgi_split_path_info ^(.+?\\.php)(/.*)$;
        set $wp_path_info $fastcgi_path_info;
        try_files $fastcgi_script_name =404;
        include fastcgi_params;
        # Debian's fastcgi_params rewrites HTTP_HOST to $host (port dropped);
        # WordPress then canonical-redirects to WP_HOME's ported URL forever.
        fastcgi_param HTTP_HOST $http_host if_not_empty;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $wp_path_info;
        fastcgi_pass unix:%(sock)s;
    }
""" % {"sock": webserver.FPM_SOCKET}
    else:
        php_location = """
    # Stock shape: no fastcgi_split_path_info, so /barebits/api.php/api/v1/...
    # never matches — only real .php files execute, everything else lands in
    # WordPress. This is what the same-origin api.php transport exists for.
    location ~ \\.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param HTTP_HOST $http_host if_not_empty;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:%(sock)s;
    }
""" % {"sock": webserver.FPM_SOCKET}

    return f"""server {{
    listen 127.0.0.1:{port};
    server_name _;
    root {wp_root};
    index index.php;
{barebits_rules}
    location / {{
        try_files $uri $uri/ /index.php?$args;
    }}
{php_location}}}
"""


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
    behaviour under test (barebits_configure_btcpay_plugin), so the test does
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

    # WooCommerce's activation leaves a redirect transient that hijacks the
    # NEXT wp-admin page render into its own onboarding wizard — which would
    # swallow the BareBits onboarding page a test loads right after this.
    handle.wp_cli("transient", "delete", "_wc_activation_redirect", check=False)

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
        # Pin order storage to the legacy posts table, and drop the
        # "newly installed" flag WC's deferred HPOS-for-new-shops job keys
        # off. Without the pin that job flips HPOS on minutes after install
        # (fresh per-test installs never lived long enough to see it; golden
        # clones boot with it overdue), and the HPOS order table's DECIMAL
        # column round-trips through SQLite as a REAL: the drop-in
        # stringifies it the way PHP prints floats ("1.5E-5" for 0.00001500)
        # and WC's wc_format_decimal strips the exponent char on hydration —
        # a 1.50000000 total off a 1500-sat order. The plugin's wiring now
        # pins storage the same way on real SQLite shops
        # (barebits_pin_order_storage_for_sqlite); the pin here keeps every
        # UNWIRED fixture usable, and test_wp_hpos_checkout.py un-pins it to
        # prove the plugin-side steering end to end.
        "woocommerce_custom_orders_table_enabled": "no",
        "woocommerce_newly_installed": "no",
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


def use_classic_checkout(handle: WordPressHandle) -> None:
    """Switch the shop's checkout page from the WooCommerce checkout block
    (the 9.x default) to the classic [woocommerce_checkout] shortcode.

    WooCommerce keys its classic-vs-blocks behaviour off the checkout page's
    content, so rewriting the page is the same switch a merchant makes in the
    editor. Used by the classic-checkout discount tests — the two checkouts
    take entirely different code paths to the payment-method-driven fee."""
    page_id = handle.wp_cli(
        "option", "get", "woocommerce_checkout_page_id"
    ).stdout.strip().splitlines()[-1]
    handle.wp_cli(
        "post", "update", page_id, "--post_content=[woocommerce_checkout]"
    )


def _wp_sqlite_ast_driver_enabled() -> bool:
    """The drop-in's AST driver is on unless CASHUPAY_WP_SQLITE_AST_DRIVER is
    set to an explicit off value (0/false/no/off)."""
    raw = os.environ.get("CASHUPAY_WP_SQLITE_AST_DRIVER", "1").strip().lower()
    return raw not in ("0", "false", "no", "off")


def _wp_config_php(
    *,
    port: int,
    release_api_base: str | None = None,
    release_channel: str | None = None,
    debug_log_path: Path | None = None,
) -> str:
    release_defines = ""
    if release_api_base:
        release_defines += (
            "// Point the plugin's release downloader at the test's fixture release\n"
            "// server instead of api.github.com (see wordpress/installer.php).\n"
            f"define('BAREBITS_RELEASE_API_BASE', '{release_api_base}');\n"
        )
    if release_channel:
        release_defines += (
            "// Release channel override — the same wp-config.php constant a site\n"
            "// operator would use to opt into the testing channel.\n"
            f"define('BAREBITS_RELEASE_CHANNEL', '{release_channel}');\n"
        )
    # sqlite-database-integration drop-in driver + journal knobs.
    #
    #   CASHUPAY_WP_SQLITE_AST_DRIVER=0              fall back to the legacy translator
    #   CASHUPAY_WP_SQLITE_JOURNAL_MODE=WAL|DELETE|...   PRAGMA journal_mode (A/B only)
    #
    # The plugin's newer "AST" driver is the default here. Its legacy
    # translator wraps every statement in a deferred BEGIN, and a connection
    # that read first cannot upgrade to a write once another php -S worker
    # committed in between: SQLite fails the upgrade with "database is locked"
    # without consulting the busy timeout. WooCommerce saves the cart session
    # from a shutdown hook in exactly that shape, so under the multi-worker
    # checkout tier a buyer's cart could silently vanish between requests
    # (woocommerce_rest_cart_empty). The AST driver opens write transactions
    # with BEGIN IMMEDIATE, which queues concurrent writers on the busy
    # timeout instead. Measured 2026-10-28 on test_wp_woocommerce_checkout.py:
    # translator 3/3 clean but flaky in CI, translator+WAL 3 empty-cart
    # failures in 4 runs, AST driver 3/3 clean.
    sqlite_defines = ""
    journal_mode = os.environ.get("CASHUPAY_WP_SQLITE_JOURNAL_MODE", "")
    if journal_mode:
        sqlite_defines += (
            "// A/B knob only. With the legacy translator driver WAL made the\n"
            "// lost-write race WORSE: its deferred BEGIN cannot upgrade to a write\n"
            "// once another worker committed, and WAL widens that window to the\n"
            "// whole transaction.\n"
            f"define('SQLITE_JOURNAL_MODE', '{journal_mode}');\n"
        )
    if _wp_sqlite_ast_driver_enabled():
        sqlite_defines += (
            "// The plugin's newer driver opens write statements with BEGIN IMMEDIATE,\n"
            "// which queues concurrent writers on the busy timeout instead of failing\n"
            "// the read-to-write upgrade with \"database is locked\" (the cause of the\n"
            "// empty-cart checkout flakes on the legacy translator driver).\n"
            "define('WP_SQLITE_AST_DRIVER', true);\n"
        )
    if debug_log_path is None:
        debug_defines = "define('WP_DEBUG', false);"
    else:
        debug_defines = (
            "// Compatibility probe (start_wordpress(debug_log=True)): every PHP\n"
            "// notice/deprecation is logged, never displayed, to a file outside\n"
            "// the docroot; plugin_debug_findings() reads it back.\n"
            "define('WP_DEBUG', true);\n"
            f"define('WP_DEBUG_LOG', '{debug_log_path}');\n"
            "define('WP_DEBUG_DISPLAY', false);"
        )
    return f"""<?php
{release_defines}{sqlite_defines}
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
{debug_defines}

if (!defined('ABSPATH')) {{
    define('ABSPATH', __DIR__ . '/');
}}
require_once ABSPATH . 'wp-settings.php';
"""


def _rand_key() -> str:
    import secrets
    return secrets.token_urlsafe(48).replace("'", "x")
