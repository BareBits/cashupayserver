"""cashu.me self-hosted dev server.

Clones cashubtc/cashu.me into tests/bin/ (one-time), runs `npm install` in
the same cached directory (one-time, slow), and serves the dev build on a
free port. Each iterate.py run launches a fresh dev server, but the cached
clone + node_modules are reused so subsequent runs start in ~3s.

Wired into the dev iterate.py script. Not part of the test suite proper.
"""
from __future__ import annotations

import os
import shutil
import signal
import subprocess
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from . import binaries, ports


TESTS_DIR = Path(__file__).resolve().parent.parent
CASHUME_CACHE = TESTS_DIR / "bin" / "cashu.me"
CASHUME_REPO = "https://github.com/cashubtc/cashu.me.git"
# Pin to a known-working commit so dev iteration stays reproducible. Bump as
# needed; verify the URL deeplink format (`?mint=...&token=...`) is still
# honored by the WalletPage.vue created() hook.
CASHUME_REF = "main"


@dataclass
class CashuMeHandle:
    process: subprocess.Popen[bytes]
    port: int
    repo_dir: Path
    node_bin_dir: Path

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def deeplink(self, mint_url: str, token: str | None = None) -> str:
        """Build a URL that auto-opens the add-mint dialog and (optionally) the
        receive-token dialog. The WalletPage's created() hook reads ?mint=
        and ?token= on first load."""
        from urllib.parse import urlencode
        params = {"mint": mint_url}
        if token:
            params["token"] = token
        return f"{self.base_url}/?{urlencode(params)}"

    def wait_ready(self, timeout_s: float = 180.0) -> None:
        """Quasar's dev server takes a beat on first build. Poll /index.html
        (which is reliably served — `/` returns 404 to non-browser clients
        because Vite's SPA fallback gates on the Accept header)."""
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(self.base_url + "/index.html", timeout=2) as resp:
                    if resp.status == 200:
                        return
            except (urllib.error.URLError, ConnectionError, urllib.error.HTTPError) as e:
                last = e
            time.sleep(0.5)
        raise TimeoutError(f"cashu.me dev server not ready after {timeout_s}s ({last})")


def _ensure_repo() -> Path:
    """Clone cashu.me if missing; idempotent. Returns the repo path."""
    if (CASHUME_CACHE / "package.json").is_file():
        _patch_quasar_config(CASHUME_CACHE)
        return CASHUME_CACHE
    CASHUME_CACHE.parent.mkdir(parents=True, exist_ok=True)
    print(f"[cashume] cloning cashu.me into {CASHUME_CACHE} ...")
    subprocess.run(
        ["git", "clone", "--depth", "1", "--branch", CASHUME_REF, CASHUME_REPO, str(CASHUME_CACHE)],
        check=True,
    )
    _patch_quasar_config(CASHUME_CACHE)
    return CASHUME_CACHE


def _patch_quasar_config(repo: Path) -> None:
    """cashu.me ships with a few devServer defaults we want to override:

    - `https: true` — we're talking to local HTTP mints/cashupayserver; the
      HTTPS dev server would force self-signed cert handling and break
      mixed-content fetch() calls.
    - `open: true` — Quasar dev server otherwise auto-launches the URL in
      the system default browser. iterate.py opens cashu.me in a
      Playwright-controlled Chromium window so all wallets share one
      window; the spurious default-browser tab is duplicate and confusing.

    Idempotent: only rewrites the file if a needle is still present."""
    config = repo / "quasar.config.js"
    if not config.is_file():
        return
    text = config.read_text()
    changed = False
    for needle, replacement, label in (
        ("https: true,", "https: false,", "devServer.https = false"),
        ("open: true,", "open: false,", "devServer.open = false"),
    ):
        if needle in text and replacement not in text:
            text = text.replace(needle, replacement)
            print(f"[cashume] patched quasar.config.js: {label}")
            changed = True
    if changed:
        config.write_text(text)


def _ensure_npm_install(repo: Path, node_bin_dir: Path) -> None:
    """Run `npm install` once if node_modules is missing. Subsequent runs
    skip this — it takes ~1 min on a cold cache."""
    if (repo / "node_modules" / ".package-lock.json").is_file():
        return
    print("[cashume] running `npm install` (one-time, ~1 min) ...")
    env = os.environ.copy()
    env["PATH"] = f"{node_bin_dir}:" + env.get("PATH", "")
    subprocess.run(
        [str(node_bin_dir / "npm"), "install", "--no-audit", "--no-fund", "--prefer-offline"],
        cwd=str(repo),
        env=env,
        check=True,
    )


def start_cashume() -> CashuMeHandle:
    """Spin up the cashu.me dev server on a free port. Repo + node_modules
    are cached across runs; the dev server itself is per-run."""
    node_exes = binaries.ensure(binaries.NODEJS)
    node_bin_dir = node_exes["node"].parent  # tests/bin/nodejs-X/bin

    repo = _ensure_repo()
    _ensure_npm_install(repo, node_bin_dir)

    port = ports.allocate(1)[0]

    env = os.environ.copy()
    env["PATH"] = f"{node_bin_dir}:" + env.get("PATH", "")
    # quasar dev reads PORT for the dev server.
    env["PORT"] = str(port)
    env["HOST"] = "127.0.0.1"

    log = (repo / "iterate-dev-server.log").open("ab")
    proc = subprocess.Popen(
        [str(node_bin_dir / "npm"), "run", "dev", "--", "--hostname", "127.0.0.1", "--port", str(port)],
        cwd=str(repo),
        env=env,
        stdout=log,
        stderr=subprocess.STDOUT,
        # Put the dev server in its own process group so we can clean it up
        # along with any node child processes (vite/quasar fork helpers).
        preexec_fn=os.setsid,
    )

    handle = CashuMeHandle(
        process=proc,
        port=port,
        repo_dir=repo,
        node_bin_dir=node_bin_dir,
    )
    try:
        handle.wait_ready()
    except Exception:
        stop_cashume(handle)
        raise
    return handle


def stop_cashume(handle: CashuMeHandle) -> None:
    if handle.process.poll() is not None:
        return
    try:
        # Kill the whole process group (npm fork + vite fork + child processes).
        os.killpg(os.getpgid(handle.process.pid), signal.SIGTERM)
    except (ProcessLookupError, PermissionError):
        handle.process.send_signal(signal.SIGTERM)
    try:
        handle.process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(os.getpgid(handle.process.pid), signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            handle.process.kill()
        handle.process.wait()
