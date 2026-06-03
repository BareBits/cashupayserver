"""Shared bring-up helpers for submarine-swap testing/dev.

Used by both the pytest e2e (`tests/e2e/test_submarine_swap_via_electrum.py`)
and the interactive dev driver (`tests/scripts/swap_dev.py`) so the two
can't drift.

Topology (single bitcoind, Electrum acts as customer-LN AND merchant-xpub):

      ┌─────────────────────────────────────┐
      │ Boltz regtest stack (Docker)        │
      │  bitcoind  <─ LND-1/2/3  <─ Boltz   │
      │     ▲           ▲                   │
      │     │           │                   │
      └─────┼───────────┼───────────────────┘
            │ RPC :28443│ P2P :29735
            │           │
   ┌────────┼───────────┼──────────────────┐
   │ Host:  │           │                  │
   │  Fulcrum (Electrum proto) ── Electrum │
   │     ▲                       (vpub +   │
   │     │                       LN funds) │
   │     │                                 │
   │  cashupayserver (php -S)              │
   │     └─── /v2/swap/reverse → Boltz API │
   └───────────────────────────────────────┘
"""
from __future__ import annotations

import hashlib
import json
import os
import secrets
import socket
import subprocess
import sys
import time
import uuid
from base64 import b64encode
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import urllib.request
import urllib.error

from .boltz_regtest import BoltzRegtestHandle

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
PHP = REPO_ROOT / "tests" / "bin" / "php-8.3.31" / "php"
FULCRUM = REPO_ROOT / "tests" / "bin" / "fulcrum-2.1.1" / "Fulcrum"
ELECTRUM = REPO_ROOT / "tests" / "bin" / "electrum-4.7.2" / "electrum.AppImage"


def free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


# ---------- Fulcrum ----------

@dataclass
class FulcrumProc:
    process: subprocess.Popen
    port: int
    datadir: Path


def start_fulcrum(workdir: Path, boltz: BoltzRegtestHandle) -> FulcrumProc:
    datadir = workdir / "fulcrum"
    datadir.mkdir(parents=True, exist_ok=True)
    cookie = boltz.bitcoind_cookie()
    user, _, password = cookie.partition(":")
    tcp_port = free_port()
    conf = datadir / "fulcrum.conf"
    conf.write_text("\n".join([
        f"datadir = {datadir}",
        f"bitcoind = {boltz.bitcoind_rpc_host}:{boltz.bitcoind_rpc_port}",
        f"rpcuser = {user}",
        f"rpcpassword = {password}",
        f"tcp = 127.0.0.1:{tcp_port}",
        "debug = false",
        "polltime = 1",
        "",
    ]))
    log = (datadir / "fulcrum.log").open("ab")
    proc = subprocess.Popen([str(FULCRUM), str(conf)], stdout=log, stderr=subprocess.STDOUT)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", tcp_port), timeout=1.0):
                return FulcrumProc(process=proc, port=tcp_port, datadir=datadir)
        except OSError:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"Fulcrum did not come up on :{tcp_port}")


def stop_fulcrum(fulcrum: FulcrumProc) -> None:
    if fulcrum.process.poll() is None:
        fulcrum.process.terminate()
        try:
            fulcrum.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            fulcrum.process.kill()


# ---------- Electrum ----------

@dataclass
class ElectrumProc:
    process: subprocess.Popen | None
    datadir: Path
    wallet_path: Path
    rpc_port: int
    rpc_user: str
    rpc_password: str
    lightning_listen_port: int
    vpub: str = ""
    gui_process: subprocess.Popen | None = None


def electrum_cli(datadir: Path, *args: str, timeout: float = 30.0) -> str:
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    res = subprocess.run(
        [str(ELECTRUM), "--regtest", "--dir", str(datadir), *args],
        capture_output=True, text=True, env=env, timeout=timeout,
    )
    if res.returncode != 0:
        raise RuntimeError(f"electrum {args}: {res.stderr.strip()}\n{res.stdout.strip()}")
    return res.stdout.strip()


def electrum_cli_wallet(datadir: Path, wallet: Path, *args: str, timeout: float = 30.0) -> str:
    """CLI in the context of a specific wallet (for wallet-scoped commands like lnpay, list_channels)."""
    return electrum_cli(datadir, *args, "-w", str(wallet), timeout=timeout)


def electrum_rpc(rpc_port: int, user: str, password: str, method: str, params: list | dict | None = None) -> dict:
    body = json.dumps({"id": 0, "method": method, "params": params or []}).encode()
    auth = b64encode(f"{user}:{password}".encode()).decode()
    req = urllib.request.Request(
        f"http://127.0.0.1:{rpc_port}/",
        data=body,
        headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=20) as resp:
        return json.loads(resp.read().decode())


def start_electrum(workdir: Path, fulcrum_port: int) -> ElectrumProc:
    datadir = workdir / "electrum"
    datadir.mkdir(parents=True, exist_ok=True)
    rpc_user = "electrum"
    rpc_password = secrets.token_hex(16)
    rpc_port = free_port()
    lightning_listen_port = free_port()

    for k, v in [
        ("rpcuser", rpc_user),
        ("rpcpassword", rpc_password),
        ("rpcport", str(rpc_port)),
        ("server", f"127.0.0.1:{fulcrum_port}:t"),
        ("oneserver", "true"),
        ("auto_connect", "false"),
        ("use_gossip", "true"),
        ("lightning_listen", f"127.0.0.1:{lightning_listen_port}"),
        ("decimal_point", "0"),
        ("dont_show_testnet_warning", "true"),
        ("check_updates", "false"),
    ]:
        electrum_cli(datadir, "--offline", "setconfig", k, v)

    electrum_cli(datadir, "--offline", "create")

    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    log = (datadir / "daemon.log").open("ab")
    proc = subprocess.Popen(
        [str(ELECTRUM), "--regtest", "--dir", str(datadir), "daemon", "-v"],
        env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            electrum_rpc(rpc_port, rpc_user, rpc_password, "version")
            break
        except Exception:
            time.sleep(0.4)
    else:
        proc.kill()
        raise TimeoutError("Electrum daemon did not start")

    wallet_path = datadir / "regtest" / "wallets" / "default_wallet"
    electrum_rpc(rpc_port, rpc_user, rpc_password, "load_wallet", {"wallet_path": str(wallet_path)})

    # vpub from CLI (RPC variant of getmpk is wallet-scope-sensitive).
    vpub = electrum_cli_wallet(datadir, wallet_path, "getmpk").strip()

    return ElectrumProc(
        process=proc,
        datadir=datadir,
        wallet_path=wallet_path,
        rpc_port=rpc_port,
        rpc_user=rpc_user,
        rpc_password=rpc_password,
        lightning_listen_port=lightning_listen_port,
        vpub=vpub,
    )


def stop_electrum(electrum: ElectrumProc) -> None:
    # Stop GUI first if running, then daemon.
    if electrum.gui_process and electrum.gui_process.poll() is None:
        electrum.gui_process.terminate()
        try:
            electrum.gui_process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            electrum.gui_process.kill()
    if electrum.process and electrum.process.poll() is None:
        try:
            electrum_cli(electrum.datadir, "stop", timeout=10)
        except Exception:
            pass
        try:
            electrum.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            electrum.process.kill()


def fund_electrum(electrum: ElectrumProc, boltz: BoltzRegtestHandle, sats: int) -> str:
    """Fund the Electrum wallet on-chain. Returns the funding address."""
    address = electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "getunusedaddress").strip()
    if not address:
        raise RuntimeError("Electrum did not return a funding address")
    boltz.send_to_address(address, sats)
    boltz.mine_blocks(6)
    # Wait for Electrum to see the balance
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            bal = json.loads(electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "getbalance"))
            confirmed_sats = int(float(bal.get("confirmed", "0")) * 100_000_000)
            if confirmed_sats >= sats // 2:
                return address
        except Exception:
            pass
        time.sleep(1)
    raise TimeoutError(f"Electrum did not see funded balance at {address}")


def open_channel_to_boltz_receiver(electrum: ElectrumProc, boltz: BoltzRegtestHandle,
                                    capacity_sat: int = 300_000) -> str:
    """Open a Lightning channel from Electrum directly to Boltz's BOLT11
    destination (cln-2). Direct channel = 1-hop payment, no graph routing
    needed — regtest LN gossip is fragile and we can sidestep it entirely
    by being adjacent to the receiver.

    Returns the channel point (txid:vout).
    """
    pubkey = boltz.cln2_pubkey
    if not pubkey:
        raise RuntimeError("boltz handle has no cln2_pubkey")
    node_uri = f"{pubkey}@{boltz.cln2_p2p_host}:{boltz.cln2_p2p_port}"
    capacity_btc = f"{capacity_sat / 100_000_000:.8f}"
    # `open_channel` blocks until the funding tx is broadcast.
    raw = electrum_cli_wallet(electrum.datadir, electrum.wallet_path,
                              "open_channel", node_uri, capacity_btc, timeout=120)
    import re
    m = re.search(r"([0-9a-f]{64}:\d+)", raw)
    if not m:
        raise RuntimeError(f"open_channel did not yield a channel point: {raw}")
    channel_point = m.group(1)

    # Mine for funding confirmations + LN graph propagation.
    boltz.mine_blocks(6)

    # Wait until the channel is in an active/open state.
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline:
        try:
            channels = json.loads(electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "list_channels"))
        except Exception:
            channels = []
        for c in channels:
            state = (c.get("state") or "").upper()
            if state in ("OPEN", "FUNDED", "OPENING_DONE"):
                return channel_point
        time.sleep(2)
    raise TimeoutError(f"Electrum channel to {pubkey[:16]}… did not open within 90s")


def wait_for_lightning_route(electrum: ElectrumProc, boltz_target_pubkey: str | None = None,
                              timeout: float = 60.0) -> None:
    """Brief grace period for our direct channel to be considered routable.

    With a direct channel to cln-2 (Boltz's BOLT11 receiver), no gossip is
    needed for routing — the payment is 1-hop along our own channel.
    Electrum still needs the channel's own updates internalized, which is
    near-instant. A short sleep is enough.
    """
    time.sleep(3)


def pay_bolt11(electrum: ElectrumProc, bolt11: str, timeout: int = 60) -> dict:
    """Pay a BOLT11 from the Electrum wallet via `lnpay`.

    Electrum's `lnpay` returns a JSON dict with `payment_hash` and `success`.
    """
    raw = electrum_cli_wallet(electrum.datadir, electrum.wallet_path, "lnpay", bolt11,
                              timeout=timeout)
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw}


# ---------- cashupayserver ----------

@dataclass
class PayserverProc:
    process: subprocess.Popen
    port: int
    data_dir: Path
    store_id: str
    api_token: str

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"


def _php_eval(data_dir: Path, snippet: str) -> str:
    code = (
        f"define('CASHUPAY_DATA_DIR', {str(data_dir)!r});\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'database.php')!r};\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'config.php')!r};\n"
        + snippet
    )
    res = subprocess.run([str(PHP), "-r", code], capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"php failed: {res.stderr}\n{res.stdout}")
    return res.stdout.strip()


def setup_payserver(workdir: Path, vpub: str, boltz_api_url: str,
                    strict_no_mint_fallback: bool = True,
                    admin_password: str = "password") -> PayserverProc:
    """Initialize DB, seed setup-complete + swap config + one store using the
    given vpub, create an admin user (admin/<admin_password>) + a public API
    token + the store's internal-API-key (used by the admin UI). Start php -S.
    Returns a handle."""
    data_dir = workdir / "payserver-data"
    data_dir.mkdir(parents=True, exist_ok=True)
    _php_eval(data_dir, "Database::initialize(); echo 'ok';")

    import sqlite3
    db = data_dir / "cashupay.sqlite"
    now = int(time.time())
    store_id = f"store_{uuid.uuid4().hex[:12]}"
    api_token = "dev-" + uuid.uuid4().hex[:20]
    api_token_hash = hashlib.sha256(api_token.encode()).hexdigest()

    # The admin UI's invoice form reads stores.internal_api_key, which is
    # created on demand by Auth::getOrCreateInternalApiKey() the first time
    # an authed admin loads the store row. Seed it directly so the UI works
    # without requiring an admin browser load first.
    internal_api_token = "internal-" + uuid.uuid4().hex[:24]
    internal_api_hash = hashlib.sha256(internal_api_token.encode()).hexdigest()

    # Admin user password hash. The PHP layer uses password_hash(PASSWORD_BCRYPT)
    # — we generate that hash here via php -r so we don't have to ship a PHP
    # bcrypt implementation.
    admin_pw_hash = _php_eval(
        data_dir,
        f"echo password_hash({admin_password!r}, PASSWORD_BCRYPT);",
    ).strip()

    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        kvs = [
            ("setup_complete", json.dumps(True)),
            ("swaps_enabled", json.dumps(True)),
            ("swaps_provider_order", json.dumps(["boltz"])),
            ("swaps_strict_no_mint_fallback", json.dumps(bool(strict_no_mint_fallback))),
            ("swaps_boltz_regtest_url", json.dumps(boltz_api_url)),
            ("cron_key", json.dumps("dev-cron-key")),
        ]
        for k, v in kvs:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        # Admin user — username 'admin', password from arg (default 'password')
        cur.execute(
            "INSERT INTO users (id, username, password_hash, role, created_at) "
            "VALUES (?, 'admin', ?, 'admin', ?)",
            ("user_" + uuid.uuid4().hex[:12], admin_pw_hash, now),
        )
        # Store + store.internal_api_key
        cur.execute(
            "INSERT INTO stores (id, name, mint_unit, default_currency, created_at, "
            "onchain_xpub, onchain_address_type, onchain_network, internal_api_key) VALUES "
            "(?, 'Swap Dev Store', 'sat', 'sat', ?, ?, 'P2WPKH', 'regtest', ?)",
            (store_id, now, vpub, internal_api_token),
        )
        # API keys: external dev token + the internal one referenced by the UI
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'dev', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], api_token_hash, store_id,
             json.dumps(["*"]), now),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'internal', ?, ?)",
            ("key_" + uuid.uuid4().hex[:12], internal_api_hash, store_id,
             json.dumps(["*"]), now),
        )
        conn.commit()
    finally:
        conn.close()

    # php -S with a wrapper that defines CASHUPAY_DATA_DIR
    wrapper = data_dir / "router_wrapper.php"
    wrapper.write_text(
        "<?php\n"
        "$d = getenv('CASHUPAY_DATA_DIR');\n"
        "if ($d !== false && $d !== '' && !defined('CASHUPAY_DATA_DIR')) {\n"
        "    define('CASHUPAY_DATA_DIR', $d);\n"
        "}\n"
        f"return require {str(REPO_ROOT / 'router.php')!r};\n"
    )
    env = os.environ.copy()
    env["CASHUPAY_DATA_DIR"] = str(data_dir)
    port = free_port()
    log = (data_dir / "payserver.log").open("ab")
    proc = subprocess.Popen(
        [str(PHP), "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT), str(wrapper)],
        cwd=str(REPO_ROOT), env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/v1/server/info", timeout=1.0)
            return PayserverProc(process=proc, port=port, data_dir=data_dir,
                                  store_id=store_id, api_token=api_token)
        except Exception:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"cashupayserver did not come up on :{port}")


def stop_payserver(payserver: PayserverProc) -> None:
    if payserver.process.poll() is None:
        payserver.process.terminate()
        try:
            payserver.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            payserver.process.kill()


# ---------- API helpers ----------

def http_json(url: str, *, method: str = "GET", body: dict | None = None,
              headers: dict | None = None, timeout: float = 15.0) -> tuple[int, dict | str]:
    """Use curl as transport; PHP built-in server + urllib have some
    edge-case POST interactions we'd rather not debug."""
    cmd = ["curl", "-s", "-o", "/dev/stdout", "-w", "\n__HTTP_STATUS__:%{http_code}",
           "-X", method, "--max-time", str(int(timeout))]
    for k, v in {"Accept": "application/json", **(headers or {})}.items():
        cmd += ["-H", f"{k}: {v}"]
    if body is not None:
        cmd += ["-H", "Content-Type: application/json", "-d", json.dumps(body)]
    cmd += [url]
    proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 5)
    raw = proc.stdout
    status = 0
    body_str = raw
    if "\n__HTTP_STATUS__:" in raw:
        body_str, _, status_line = raw.rpartition("\n__HTTP_STATUS__:")
        status = int(status_line.strip())
    try:
        return status, json.loads(body_str)
    except (json.JSONDecodeError, ValueError):
        return status, body_str


def create_swap_invoice(payserver: PayserverProc, amount_sats: int,
                        currency: str = "sat") -> dict:
    status, body = http_json(
        f"{payserver.url}/api/v1/stores/{payserver.store_id}/invoices",
        method="POST",
        body={"amount": amount_sats, "currency": currency},
        headers={"Authorization": f"token {payserver.api_token}"},
    )
    if status != 200 or not isinstance(body, dict) or "id" not in body:
        raise RuntimeError(f"invoice creation failed: status={status} body={body}")
    return body


def trigger_cron(payserver: PayserverProc) -> None:
    """Fire-and-forget cron tick. Errors swallowed (it may 500 transiently)."""
    try:
        with urllib.request.urlopen(f"{payserver.url}/cron.php?key=dev-cron-key", timeout=10) as r:
            r.read()
    except Exception:
        pass


def fetch_invoice_row(payserver: PayserverProc, invoice_id: str) -> dict | None:
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def fetch_swap_row(payserver: PayserverProc, invoice_id: str) -> dict | None:
    import sqlite3
    db = payserver.data_dir / "cashupay.sqlite"
    conn = sqlite3.connect(str(db))
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM swap_attempts WHERE invoice_id = ?", (invoice_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


# ---------- High-level convenience ----------

def drive_swap_to_terminal(payserver: PayserverProc, invoice_id: str,
                            boltz: BoltzRegtestHandle,
                            timeout: float = 180.0) -> dict:
    """Loop cron + mine until the swap reaches a terminal state. Returns the
    final swap_attempts row.

    Terminal: invoice.settled OR any of the failure statuses.
    """
    terminal = {"invoice.settled", "swap.expired", "transaction.refunded",
                "transaction.failed", "invoice.expired", "claim.confirmed", "error"}
    deadline = time.monotonic() + timeout
    tick = 0
    while time.monotonic() < deadline:
        trigger_cron(payserver)
        tick += 1
        if tick % 3 == 0:
            boltz.mine_blocks(1)
        row = fetch_swap_row(payserver, invoice_id)
        if row and row["status"] in terminal:
            return row
        time.sleep(2)
    raise TimeoutError(f"Swap {invoice_id} did not reach a terminal state within {timeout}s")
