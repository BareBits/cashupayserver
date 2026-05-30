"""Fulcrum — an Electrum-protocol server in front of bitcoind regtest.

Electrum desktop clients can't speak directly to a Bitcoin Core node; they
need an Electrum/Stratum-protocol server (ElectrumX, electrs, or Fulcrum)
that translates between the wallet protocol and bitcoind's RPC.

Fulcrum ships prebuilt Linux binaries (electrs only ships source) so it's
the easiest fit for the dev iteration tool's binary-manager-driven
download flow. Tiny regtest chains sync in <1s.
"""
from __future__ import annotations

import signal
import subprocess
import time
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from . import binaries, ports
from .bitcoind import BitcoindHandle, RPC_PASSWORD, RPC_USER


@dataclass
class FulcrumHandle:
    process: subprocess.Popen[bytes]
    datadir: Path
    tcp_port: int
    fulcrum_exe: Path

    @property
    def server_url(self) -> str:
        """The host:port:t string Electrum uses to point at this server.
        `t` = plain TCP (no TLS, fine for localhost regtest)."""
        return f"127.0.0.1:{self.tcp_port}:t"

    def wait_ready(self, timeout_s: float = 30.0) -> None:
        """Poll-connect to the TCP port until Fulcrum accepts."""
        import socket
        deadline = time.monotonic() + timeout_s
        last: Exception | None = None
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(("127.0.0.1", self.tcp_port), timeout=1.0):
                    return
            except (ConnectionRefusedError, OSError) as e:
                last = e
                time.sleep(0.2)
        raise TimeoutError(f"Fulcrum not listening on :{self.tcp_port} after {timeout_s}s ({last})")


def start_fulcrum(workdir: Path, bitcoind: BitcoindHandle) -> FulcrumHandle:
    fulcrum_exe = binaries.ensure(binaries.FULCRUM)["Fulcrum"]

    datadir = workdir / "fulcrum"
    datadir.mkdir(parents=True, exist_ok=True)

    tcp_port = ports.allocate(1)[0]

    config = datadir / "fulcrum.conf"
    config.write_text(
        "\n".join(
            [
                f"datadir = {datadir}",
                f"bitcoind = 127.0.0.1:{bitcoind.rpc_port}",
                f"rpcuser = {RPC_USER}",
                f"rpcpassword = {RPC_PASSWORD}",
                f"tcp = 127.0.0.1:{tcp_port}",
                # Quiet by default; ssl off (no TLS for localhost regtest).
                "debug = false",
                "polltime = 1",
                "",
            ]
        )
    )

    log = (datadir / "fulcrum.log").open("ab")
    proc = subprocess.Popen(
        [str(fulcrum_exe), str(config)],
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = FulcrumHandle(
        process=proc,
        datadir=datadir,
        tcp_port=tcp_port,
        fulcrum_exe=fulcrum_exe,
    )
    try:
        handle.wait_ready()
    except Exception:
        stop_fulcrum(handle)
        raise
    return handle


def stop_fulcrum(handle: FulcrumHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=15)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()
