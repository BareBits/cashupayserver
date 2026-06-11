"""Electrum desktop wallet — on-chain + Lightning customer wallet.

Drives the AppImage via its CLI subcommands during setup (start a daemon,
create a wallet, fund it, optionally open a Lightning channel), then hands
back a handle the iterate.py script can use to launch the GUI at the end.

The wallet's data dir is per-invocation under workdir/electrum/, so each
script run gets a fresh mnemonic. Connects to a Fulcrum instance which in
turn talks to our bitcoind regtest node.
"""
from __future__ import annotations

import json
import os
import re
import shlex
import signal
import subprocess
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from . import binaries
from .bitcoind import BitcoindHandle
from .fulcrum import FulcrumHandle


# Wallet filename baked into the GUI title bar so the user can tell the
# iterate.py-launched Electrum apart from their personal Electrum at a glance.
ITERATE_WALLET_NAME = "CASHU-iterate-test"


@dataclass
class ElectrumHandle:
    appimage: Path
    datadir: Path
    wallet_path: Path
    rpc_user: str
    rpc_password: str
    rpc_port: int
    lightning_listen_port: int
    daemon_process: subprocess.Popen[bytes] | None = None
    gui_process: subprocess.Popen[bytes] | None = None
    deposit_address: str | None = None
    lightning_node_id: str | None = None
    open_channels: list[str] = field(default_factory=list)

    def cli(self, *args: str, expect_json: bool = False, timeout: float = 30.0) -> str | Any:
        """Invoke an Electrum CLI command against this wallet's data dir.
        Returns stdout as text by default; pass expect_json=True to parse JSON."""
        cmd = [
            str(self.appimage),
            "--regtest",
            "--dir", str(self.datadir),
            "--wallet", str(self.wallet_path),
            *args,
        ]
        env = os.environ.copy()
        # AppImages extract themselves on each invocation; pin the extract path
        # so we don't churn /tmp inodes and so we don't trip Linux's tmpfs perms.
        env.setdefault("APPDIR", str(self.datadir / "appimage-extract"))
        env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
        proc = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=timeout)
        if proc.returncode != 0:
            raise RuntimeError(
                f"electrum CLI failed: {shlex.join(args)}\n"
                f"stdout: {proc.stdout}\n"
                f"stderr: {proc.stderr}"
            )
        out = proc.stdout.strip()
        if expect_json:
            try:
                return json.loads(out)
            except Exception:
                raise RuntimeError(f"electrum CLI returned non-JSON for {args}: {out!r}")
        return out


def start_electrum(workdir: Path, fulcrum: FulcrumHandle) -> ElectrumHandle:
    """Spin up an Electrum daemon configured for regtest pointing at the
    provided Fulcrum server. Creates a fresh wallet."""
    appimage = binaries.ensure_file(binaries.ELECTRUM)

    datadir = workdir / "electrum"
    datadir.mkdir(parents=True, exist_ok=True)

    # Random RPC credentials so two iterate.py runs on the same machine don't
    # collide on Electrum's default ~/.electrum config.
    import secrets
    rpc_user = "electrum"
    rpc_password = secrets.token_hex(16)

    # ports.allocate is brittle for Electrum because the daemon reads its
    # port from config at startup; pick one, write the config, then start.
    # Two ports allocated up front so the user's other Electrum instance
    # (if any) can never collide with ours:
    #   - rpcport: daemon's JSON-RPC endpoint we use to drive setup
    #   - lightning_listen: the Lightning peer-listen port (binds even if no
    #     incoming peers are expected)
    from . import ports as ports_mod
    rpc_port, lightning_listen_port = ports_mod.allocate(2)

    # Wallet file lives under the per-run datadir; the basename shows up in
    # the GUI title bar.
    wallet_path = datadir / "regtest" / "wallets" / ITERATE_WALLET_NAME
    wallet_path.parent.mkdir(parents=True, exist_ok=True)

    handle = ElectrumHandle(
        appimage=appimage,
        datadir=datadir,
        wallet_path=wallet_path,
        rpc_user=rpc_user,
        rpc_password=rpc_password,
        rpc_port=rpc_port,
        lightning_listen_port=lightning_listen_port,
    )

    # 1. Pre-seed config (setconfig writes to the config file; --offline
    #    skips the daemon connection so this works before the daemon is up).
    handle.cli("--offline", "setconfig", "rpcuser", rpc_user)
    handle.cli("--offline", "setconfig", "rpcpassword", rpc_password)
    handle.cli("--offline", "setconfig", "rpcport", str(rpc_port))
    handle.cli("--offline", "setconfig", "server", fulcrum.server_url)
    handle.cli("--offline", "setconfig", "oneserver", "true")
    handle.cli("--offline", "setconfig", "auto_connect", "false")
    # Lightning settings. Electrum's default `use_gossip=false` puts it in
    # trampoline-only mode, which refuses to open channels to peers (like LND)
    # that don't advertise trampoline support. Flip gossip on so it runs as a
    # "full" routing node — channel opens succeed and we can route payments
    # over the single direct channel without needing trampoline.
    handle.cli("--offline", "setconfig", "use_gossip", "true")
    # Pin the LN listen port to one we know is free. Default is unset (no
    # incoming binding); explicit assignment guarantees no collision with the
    # user's other Electrum if it has LN listen enabled.
    handle.cli("--offline", "setconfig", "lightning_listen", f"127.0.0.1:{lightning_listen_port}")
    # Dark theme so the test wallet's window is visually distinct from the
    # user's regular (mainnet, light-themed) Electrum instance.
    handle.cli("--offline", "setconfig", "qt_gui_color_theme", "dark")
    # Default display unit = sat (matches our regtest/iterate amounts which
    # are always sat-denominated). decimal_point: 0=sat, 5=mBTC, 8=BTC.
    handle.cli("--offline", "setconfig", "decimal_point", "0")
    # Skip the modal "I understand this is a testnet wallet" dialog every run.
    handle.cli("--offline", "setconfig", "dont_show_testnet_warning", "true")
    # Skip the first-run "Enable update check?" dialog. main_window.py only
    # shows it when the ConfigVar isn't is_set(); writing any value (false)
    # silences it for good and ensures no background update polling either.
    handle.cli("--offline", "setconfig", "check_updates", "false")

    # 2. Create the wallet (fresh seed). `--offline` so this doesn't try to
    #    contact a server during creation.
    handle.cli("--offline", "create")

    # 3. Start the daemon.
    log = (datadir / "daemon.log").open("ab")
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    handle.daemon_process = subprocess.Popen(
        [str(appimage), "--regtest", "--dir", str(datadir), "daemon", "-v"],
        env=env,
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    # 4. Wait for the JSON-RPC endpoint to accept.
    import urllib.request, urllib.error
    from base64 import b64encode
    deadline = time.monotonic() + 30
    auth = b64encode(f"{rpc_user}:{rpc_password}".encode()).decode()
    last: Exception | None = None
    while time.monotonic() < deadline:
        try:
            req = urllib.request.Request(
                f"http://127.0.0.1:{rpc_port}/",
                data=b'{"id":0,"method":"version","params":[]}',
                headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
            )
            urllib.request.urlopen(req, timeout=2).read()
            break
        except (urllib.error.URLError, ConnectionError, urllib.error.HTTPError) as e:
            last = e
            time.sleep(0.3)
    else:
        stop_electrum(handle)
        raise TimeoutError(f"Electrum daemon not ready after 30s ({last})")

    # 5. Load the wallet into the daemon.
    handle.cli("load_wallet")

    return handle


def electrum_wait_synced(
    handle: ElectrumHandle,
    bitcoind: BitcoindHandle,
    timeout: float = 60.0,
) -> None:
    """Block until Electrum has caught up to bitcoind's current chain tip.

    Spending before the wallet has verified the blocks that confirm its own
    funding/change UTXOs is what produces Electrum's "this transaction builds
    on top of a local tx" / bitcoind's `bad-txns-inputs-missingorspent` errors:
    `payto` picks a change output the wallet still treats as an unconfirmed
    local tx, so the broadcast references inputs bitcoind doesn't have. Waiting
    for the verified height to reach the tip closes that race (and tolerates a
    slow Fulcrum sync under heavy machine load). Best-effort: returns when the
    deadline lapses so the caller's retry can still take over.
    """
    target = bitcoind.block_count()
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            info = handle.cli("getinfo", expect_json=True)
        except Exception:
            info = {}
        height = max(
            int(info.get("blockchain_height") or 0),
            int(info.get("server_height") or 0),
        )
        # `connected` flips False mid-sync; only trust a matched height.
        if height >= target:
            return
        time.sleep(0.4)


def electrum_send_onchain(
    handle: ElectrumHandle,
    bitcoind: BitcoindHandle,
    address: str,
    amount_sat: int,
    *,
    confirmations: int = 1,
    feerate_sat_per_vb: int = 5,
) -> str:
    """Drive the Electrum wallet to send `amount_sat` to `address` and mine
    `confirmations` blocks so the recipient observes it confirmed. Returns
    the broadcast txid.

    Used by the static-address iterate flow: the merchant has a single
    Bitcoin address and the customer (this Electrum wallet) pays the exact
    tweaked total for each invoice.

    Retries across a sync barrier: under load Electrum can momentarily try to
    spend an unconfirmed local parent (see electrum_wait_synced), which bitcoind
    rejects. We wait for sync, and on a transient rejection mine + re-sync and
    retry rather than aborting the whole iterate launch.
    """
    btc = f"{amount_sat / 100_000_000:.8f}"
    transient = (
        "missingorspent",
        "local transaction",
        "builds on top",
        "unconfirmed",
        "not enough funds",
        "txn-mempool-conflict",
        "bad-txns-inputs",
    )
    last_err: Exception | None = None
    for attempt in range(5):
        electrum_wait_synced(handle, bitcoind)
        try:
            # `payto` returns a complete serialized transaction (hex). It signs
            # with the wallet's keys since the wallet is unencrypted in the
            # iterate flow (no --password).
            tx_hex = handle.cli(
                "payto", address, btc,
                f"--feerate={feerate_sat_per_vb}",
            ).strip()
            # Strip any leading/trailing whitespace and quotes — older Electrum
            # versions wrap the hex in quotes.
            tx_hex = tx_hex.strip('"').strip("'")
            txid = handle.cli("broadcast", tx_hex).strip()
            txid = txid.strip('"').strip("'")
            if confirmations > 0:
                bitcoind.mine(confirmations)
            return txid
        except RuntimeError as e:
            last_err = e
            if attempt < 4 and any(k in str(e).lower() for k in transient):
                # Confirm whatever is pending + give Electrum a moment to verify
                # the new tip, then retry from a fully-synced state.
                try:
                    bitcoind.mine(1)
                except Exception:
                    pass
                time.sleep(1.0)
                continue
            raise
    assert last_err is not None
    raise last_err


def fund_electrum_from_bitcoind(
    handle: ElectrumHandle,
    bitcoind: BitcoindHandle,
    amount_sat: int,
    confirmations: int = 3,
) -> str:
    """Send `amount_sat` from bitcoind's miner wallet to the Electrum wallet's
    next unused address, then mine `confirmations` blocks. Returns the deposit
    address.
    """
    address = handle.cli("getunusedaddress").strip()
    handle.deposit_address = address
    btc = amount_sat / 100_000_000
    bitcoind.send_to_address(address, btc)
    bitcoind.mine(confirmations)

    # Wait for Electrum to see the funds.
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        out = handle.cli("getbalance", expect_json=True)
        confirmed_str = out.get("confirmed", "0")
        confirmed_btc = float(confirmed_str)
        if int(confirmed_btc * 100_000_000) >= amount_sat - 100:
            return address
        time.sleep(0.3)
    raise TimeoutError(f"Electrum didn't see deposit to {address} within 20s")


def open_electrum_channel_to_lnd(
    handle: ElectrumHandle,
    bitcoind: BitcoindHandle,
    lnd_pubkey: str,
    lnd_host: str,
    lnd_p2p_port: int,
    capacity_sat: int = 200_000,
) -> str:
    """Have Electrum open a Lightning channel to the given LND node.
    Mines blocks to confirm + activate. Returns the channel point string."""
    node_uri = f"{lnd_pubkey}@{lnd_host}:{lnd_p2p_port}"
    handle.lightning_node_id = lnd_pubkey

    # open_channel takes the channel capacity *in BTC* (not sats) — pass an
    # 8-decimal string to match Electrum's amount parsing.
    capacity_btc = f"{capacity_sat / 100_000_000:.8f}"
    raw = handle.cli("open_channel", node_uri, capacity_btc, timeout=60)
    # Extract a `txid:vout` from the output.
    m = re.search(r"([0-9a-f]{64}:\d+)", raw)
    if not m:
        raise RuntimeError(f"electrum open_channel did not return a channel point: {raw}")
    channel_point = m.group(1)
    handle.open_channels.append(channel_point)

    # Confirm the funding tx + activate.
    bitcoind.mine(6)
    # Wait for the channel to show 'OPEN' state.
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            channels = handle.cli("list_channels", expect_json=True)
        except RuntimeError:
            channels = []
        if any((c.get("state") or "").upper() in ("OPEN", "OPENING_DONE", "FUNDED") for c in channels):
            # Make sure the wallet has verified the funding block before the
            # caller spends the change output (avoids the "builds on a local
            # tx" race in the static-address payments that follow).
            electrum_wait_synced(handle, bitcoind)
            return channel_point
        time.sleep(1)
    raise TimeoutError(f"Electrum channel to {lnd_pubkey[:16]} did not open within 60s")


def launch_electrum_gui(handle: ElectrumHandle) -> None:
    """Stop the headless daemon and replace it with the GUI process. The GUI
    runs its own internal daemon; we keep a Popen handle so cleanup can kill
    it."""
    if handle.daemon_process and handle.daemon_process.poll() is None:
        handle.cli("stop", timeout=10)
        try:
            handle.daemon_process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.daemon_process.kill()
        handle.daemon_process = None

    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    handle.gui_process = subprocess.Popen(
        [
            str(handle.appimage),
            "--regtest",
            "--dir", str(handle.datadir),
            "--wallet", str(handle.wallet_path),
        ],
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def stop_electrum(handle: ElectrumHandle) -> None:
    """Kill the GUI (if running), then the daemon (if running). Idempotent."""
    if handle.gui_process and handle.gui_process.poll() is None:
        handle.gui_process.send_signal(signal.SIGTERM)
        try:
            handle.gui_process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.gui_process.kill()
    if handle.daemon_process and handle.daemon_process.poll() is None:
        try:
            handle.cli("stop", timeout=5)
            handle.daemon_process.wait(timeout=5)
        except Exception:
            handle.daemon_process.send_signal(signal.SIGTERM)
            try:
                handle.daemon_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                handle.daemon_process.kill()
