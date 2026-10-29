"""Local stand-in for getbarebits.com/analytics/report.php — the BareBits
anonymous-usage-statistics receiver.

Runs the REAL scripts/analytics-endpoint/*.php under the pinned `php -S` from
a scratch directory (its SQLite store lands there), behind
tests/fixtures/analytics_endpoint_router.php so tests can inject failures
(set_mode: http500 / garbage / notok / reject / ratelimit / hang) and can
stop/start the server on the same port to simulate the endpoint being down.

Point a payserver at it with `usage_stats_env(endpoint)`: that sets
CASHUPAY_USAGE_STATS_ENDPOINT (the explicit override that re-enables the
client on a test rig — fixtures otherwise never contact the live endpoint,
see UsageStats::isDisabled).
"""
from __future__ import annotations

import json
import shutil
import socket
import sqlite3
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path

import requests

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
ENDPOINT_FILES = ("common.php", "report.php", "summary.php", "import-geo.php")


@dataclass
class AnalyticsEndpoint:
    port: int
    dir: Path
    proc: subprocess.Popen | None = None

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def url(self) -> str:
        """Value for CASHUPAY_USAGE_STATS_ENDPOINT."""
        return f"{self.base_url}/report.php"

    @property
    def db_path(self) -> Path:
        return self.dir / "usage-reports.sqlite"

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
        raise RuntimeError("analytics endpoint fixture did not come up")

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
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=0.1):
                    time.sleep(0.1)
            except OSError:
                return

    def set_mode(self, mode: str) -> None:
        (self.dir / "mode").write_text(mode)

    def set_constant(self, name: str, php_literal: str) -> None:
        """Edit a constant in the deployed common.php copy (e.g. the access token)."""
        p = self.dir / "common.php"
        src = p.read_text()
        import re

        new, n = re.subn(rf"^const {re.escape(name)}\s*=\s*.*?;", f"const {name} = {php_literal};", src, count=1, flags=re.M)
        if n != 1:
            raise RuntimeError(f"constant {name} not found in common.php")
        p.write_text(new)

    def alive(self) -> bool:
        r = requests.get(self.url, timeout=10)
        return r.status_code == 200 and r.json() == {"ok": True}

    def db(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        return conn

    def reports(self) -> list[dict]:
        """Every stored report (one per install id) with the payload decoded."""
        if not self.db_path.exists():
            return []
        with self.db() as conn:
            rows = conn.execute("SELECT * FROM reports ORDER BY received_at").fetchall()
        out = []
        for r in rows:
            d = dict(r)
            d["payload"] = json.loads(d["payload"])
            out.append(d)
        return out

    def wait_for_reports(self, n: int, timeout_s: float = 20.0) -> list[dict]:
        deadline = time.monotonic() + timeout_s
        while True:
            reps = self.reports()
            if len(reps) >= n:
                return reps
            if time.monotonic() >= deadline:
                raise TimeoutError(f"expected {n} report(s) on the analytics endpoint, have {len(reps)}")
            time.sleep(0.3)


def start_analytics_endpoint(workdir: Path) -> AnalyticsEndpoint:
    workdir.mkdir(parents=True, exist_ok=True)
    for f in ENDPOINT_FILES:
        shutil.copy(REPO_ROOT / "scripts" / "analytics-endpoint" / f, workdir / f)
    shutil.copy(REPO_ROOT / "tests" / "fixtures" / "analytics_endpoint_router.php", workdir / "router.php")
    ep = AnalyticsEndpoint(port=ports.allocate(1)[0], dir=workdir)
    ep.start()
    return ep


def stop_analytics_endpoint(ep: AnalyticsEndpoint) -> None:
    ep.stop()
    shutil.rmtree(ep.dir, ignore_errors=True)


def usage_stats_env(ep: AnalyticsEndpoint) -> dict[str, str]:
    """Environment that makes a payserver report to the local endpoint."""
    return {"CASHUPAY_USAGE_STATS_ENDPOINT": ep.url}
