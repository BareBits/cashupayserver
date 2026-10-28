"""Local stand-in for getbarebits.com/xpub/xpub.php — the BareBits fee-branch allocator.

Runs the REAL scripts/xpub-endpoint/xpub.php under the pinned `php -S` from a
scratch directory (its SQLite store lands there), behind
tests/fixtures/xpub_endpoint_router.php so tests can inject failures
(set_mode: http500 / garbage / badbranch / strbranch / nobranch / ratelimit /
hang) and can stop/start the server on the same port to simulate the endpoint
being down when an install boots.

Point a payserver at it with `fee_branch_env(endpoint)`: that sets
CASHUPAY_DEV_FEE_BRANCH_ENDPOINT (the explicit override that re-enables the
client on a test rig — fixtures otherwise never contact the live endpoint,
see FeeBranch::isDisabled), plus a regtest dev-fee tpub so the on-chain
fee-redirect rail is network-matched against regtest stores.
"""
from __future__ import annotations

import shutil
import socket
import sqlite3
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import requests

from . import binaries, ports
from .onchain import DEV_FEE_TEST_TPUB

REPO_ROOT = Path(__file__).resolve().parent.parent.parent


@dataclass
class XpubEndpoint:
    port: int
    dir: Path
    proc: subprocess.Popen | None = None

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def url(self) -> str:
        """Value for CASHUPAY_DEV_FEE_BRANCH_ENDPOINT."""
        return f"{self.base_url}/xpub.php"

    @property
    def db_path(self) -> Path:
        return self.dir / "xpub-branches.sqlite"

    @property
    def is_running(self) -> bool:
        return self.proc is not None and self.proc.poll() is None

    def start(self) -> None:
        if self.is_running:
            return
        php = binaries.ensure(binaries.PHP)["php"]
        log = (self.dir / "server.log").open("ab")
        self.proc = subprocess.Popen(
            [str(php), "-S", f"127.0.0.1:{self.port}", "-t", str(self.dir), str(self.dir / "router.php")],
            cwd=str(self.dir),
            env={"PATH": "/usr/bin:/bin", "PHP_CLI_SERVER_WORKERS": "4"},
            stdout=log,
            stderr=subprocess.STDOUT,
        )
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=0.2):
                    return
            except OSError:
                time.sleep(0.1)
        self.stop()
        raise RuntimeError("xpub endpoint fixture did not come up")

    def stop(self) -> None:
        if self.proc is not None:
            if self.proc.poll() is None:
                self.proc.terminate()
                try:
                    self.proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    self.proc.kill()
                    self.proc.wait(timeout=5)
            self.proc = None
        # Linger until the port is actually free so start() can rebind it.
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=0.1):
                    time.sleep(0.1)
            except OSError:
                return

    def set_mode(self, mode: str) -> None:
        (self.dir / "mode").write_text(mode)

    def highest(self) -> int:
        """What a browser sees: the highest branch allocated so far."""
        r = requests.get(self.url, timeout=10)
        r.raise_for_status()
        return int(r.text.strip())

    def db(self) -> sqlite3.Connection:
        return sqlite3.connect(str(self.db_path))


def start_xpub_endpoint(workdir: Path) -> XpubEndpoint:
    workdir.mkdir(parents=True, exist_ok=True)
    shutil.copy(REPO_ROOT / "scripts" / "xpub-endpoint" / "xpub.php", workdir / "xpub.php")
    shutil.copy(REPO_ROOT / "tests" / "fixtures" / "xpub_endpoint_router.php", workdir / "router.php")
    ep = XpubEndpoint(port=ports.allocate(1)[0], dir=workdir)
    ep.start()
    return ep


def stop_xpub_endpoint(ep: XpubEndpoint) -> None:
    ep.stop()
    shutil.rmtree(ep.dir, ignore_errors=True)


def fee_branch_env(ep: XpubEndpoint) -> dict[str, str]:
    """Environment that makes a payserver use the local endpoint and a regtest
    dev-fee xpub (so the on-chain fee-redirect rail is live on regtest
    stores). The dev-fee LNURL is pointed at a never-resolving host so the
    Lightning rail of a redirect drops to the merchant quickly instead of
    probing getbarebits.com from the test rig."""
    return {
        "CASHUPAY_DEV_FEE_BRANCH_ENDPOINT": ep.url,
        "CASHUPAY_DEV_FEE_ONCHAIN_XPUB": DEV_FEE_TEST_TPUB,
        "CASHUPAY_DEV_FEE_ONCHAIN_NETWORK": "regtest",
        "CASHUPAY_DEV_FEE_ONCHAIN_ADDRESS_TYPE": "P2WPKH",
        "CASHUPAY_DEV_FEE_LNURL": "fees@fee-lnurl.invalid",
    }
