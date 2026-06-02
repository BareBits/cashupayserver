#!/usr/bin/env python3
"""Unified dev driver for interactive submarine-swap testing.

Spins up:
  - Fulcrum (Electrum-protocol server) pointed at Boltz's bitcoind
  - Electrum daemon, with a fresh regtest wallet, connected to Fulcrum
  - cashupayserver via php -S, configured for swaps with the
    Electrum-derived vpub as the merchant xpub
  - Boltz regtest stack is assumed to be ALREADY RUNNING externally
    (start with `cd /tmp/boltz-regtest && sudo bash start.sh`)

Halts at the end of bring-up; press Enter to clean up.

Network topology: a single bitcoind (Boltz's container, RPC exposed on
host :28443) is the authoritative chain. Electrum and Fulcrum see the
same UTXOs Boltz's backend operates on. When a swap claim is broadcast
by cashupayserver via the Boltz API, it lands in this chain — and
Electrum picks it up at the merchant xpub address.
"""
from __future__ import annotations

import json
import os
import secrets
import signal
import socket
import subprocess
import sys
import time
import uuid
from base64 import b64encode
from pathlib import Path

import urllib.request
import urllib.error

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
PHP = REPO_ROOT / "tests" / "bin" / "php-8.3.31" / "php"
FULCRUM = REPO_ROOT / "tests" / "bin" / "fulcrum-2.1.1" / "Fulcrum"
ELECTRUM = REPO_ROOT / "tests" / "bin" / "electrum-4.7.2" / "electrum.AppImage"

BOLTZ_BITCOIND_RPC_PORT = 28443
BOLTZ_API_URL = "http://localhost:29001"


def echo(msg: str) -> None:
    print(f"[swap-dev] {msg}", flush=True)


def free_port() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def boltz_bitcoind_cookie() -> str:
    """Fetch the current cookie auth string from Boltz's bitcoind container."""
    res = subprocess.run(
        ["sudo", "-n", "docker", "exec", "boltz-bitcoind",
         "cat", "/app/bitcoin/regtest/.cookie"],
        capture_output=True, text=True, check=True,
    )
    return res.stdout.strip()


def start_fulcrum(workdir: Path, cookie: str, tcp_port: int) -> subprocess.Popen:
    datadir = workdir / "fulcrum"
    datadir.mkdir(parents=True, exist_ok=True)
    # cookie format: __cookie__:<value>
    user, _, password = cookie.partition(":")
    conf = datadir / "fulcrum.conf"
    conf.write_text(
        "\n".join([
            f"datadir = {datadir}",
            f"bitcoind = 127.0.0.1:{BOLTZ_BITCOIND_RPC_PORT}",
            f"rpcuser = {user}",
            f"rpcpassword = {password}",
            f"tcp = 127.0.0.1:{tcp_port}",
            "debug = false",
            "polltime = 1",
            "",
        ])
    )
    log = (datadir / "fulcrum.log").open("ab")
    proc = subprocess.Popen([str(FULCRUM), str(conf)], stdout=log, stderr=subprocess.STDOUT)
    # Wait for it to accept connections
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", tcp_port), timeout=1.0):
                return proc
        except OSError:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"Fulcrum did not come up on :{tcp_port}")


def electrum_cli(appimage: Path, datadir: Path, *args: str) -> str:
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    res = subprocess.run(
        [str(appimage), "--regtest", "--dir", str(datadir), *args],
        capture_output=True, text=True, env=env,
    )
    if res.returncode != 0:
        raise RuntimeError(f"electrum {args} failed: {res.stderr}\n{res.stdout}")
    return res.stdout.strip()


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


def start_electrum(workdir: Path, fulcrum_port: int) -> tuple[subprocess.Popen, int, str, str, str]:
    """Returns (daemon_proc, rpc_port, rpc_user, rpc_pass, vpub)."""
    datadir = workdir / "electrum"
    datadir.mkdir(parents=True, exist_ok=True)
    rpc_user = "electrum"
    rpc_password = secrets.token_hex(16)
    rpc_port = free_port()

    # Configure
    for k, v in [
        ("rpcuser", rpc_user),
        ("rpcpassword", rpc_password),
        ("rpcport", str(rpc_port)),
        ("server", f"127.0.0.1:{fulcrum_port}:t"),
        ("oneserver", "true"),
        ("auto_connect", "false"),
        ("use_gossip", "true"),
        ("decimal_point", "0"),
        ("dont_show_testnet_warning", "true"),
        ("check_updates", "false"),
    ]:
        electrum_cli(ELECTRUM, datadir, "--offline", "setconfig", k, v)

    # Create wallet (fresh seed)
    electrum_cli(ELECTRUM, datadir, "--offline", "create")

    # Start daemon
    env = os.environ.copy()
    env.setdefault("APPIMAGE_EXTRACT_AND_RUN", "1")
    log = (datadir / "daemon.log").open("ab")
    proc = subprocess.Popen(
        [str(ELECTRUM), "--regtest", "--dir", str(datadir), "daemon", "-v"],
        env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    # Wait for JSON-RPC
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

    # Load the wallet
    wallet_path = datadir / "regtest" / "wallets" / "default_wallet"
    electrum_rpc(rpc_port, rpc_user, rpc_password, "load_wallet", {"wallet_path": str(wallet_path)})

    # Get master public key via CLI — RPC `getmpk` is wallet-context-sensitive
    # and AppImage's daemon API doesn't accept a wallet path the same way the
    # CLI does. CLI talks to the daemon under the hood and returns the vpub.
    vpub = electrum_cli(ELECTRUM, datadir, "getmpk", "-w", str(wallet_path)).strip()
    if not vpub:
        raise RuntimeError("getmpk returned empty")

    return proc, rpc_port, rpc_user, rpc_password, vpub


def php_eval(data_dir: Path, snippet: str) -> str:
    code = (
        f"define('CASHUPAY_DATA_DIR', {str(data_dir)!r});\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'database.php')!r};\n"
        f"require_once {str(REPO_ROOT / 'includes' / 'config.php')!r};\n"
        + snippet
    )
    res = subprocess.run([str(PHP), "-r", code], capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"php failed: {res.stderr}\n---stdout---\n{res.stdout}")
    return res.stdout.strip()


def configure_payserver(data_dir: Path, vpub: str) -> tuple[str, str]:
    """Initializes the DB, seeds setup-complete, creates one store with the
    Electrum-derived vpub, enables swaps, and creates an API token.

    Returns (store_id, api_token)."""
    php_eval(data_dir, "Database::initialize(); echo 'ok';")
    import sqlite3
    db = data_dir / "cashupay.sqlite"
    now = int(time.time())
    store_id = f"store_{uuid.uuid4().hex[:12]}"
    api_token = "dev-" + uuid.uuid4().hex[:20]
    import hashlib
    api_token_hash = hashlib.sha256(api_token.encode()).hexdigest()

    conn = sqlite3.connect(str(db))
    try:
        cur = conn.cursor()
        for k, v in [
            ("setup_complete", json.dumps(True)),
            ("swaps_enabled", json.dumps(True)),
            ("swaps_provider_order", json.dumps(["boltz"])),
            ("swaps_strict_no_mint_fallback", json.dumps(True)),
            ("swaps_boltz_regtest_url", json.dumps(BOLTZ_API_URL)),
            ("cron_key", json.dumps("dev-cron-key")),
        ]:
            cur.execute(
                "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at",
                (k, v, now, now),
            )
        cur.execute(
            "INSERT INTO stores (id, name, mint_unit, default_currency, created_at, "
            "onchain_xpub, onchain_address_type, onchain_network) VALUES "
            "(?, 'Swap Dev Store', 'sat', 'sat', ?, ?, 'P2WPKH', 'regtest')",
            (store_id, now, vpub),
        )
        cur.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, 'dev', ?, ?)",
            (
                "key_" + uuid.uuid4().hex[:12],
                api_token_hash,
                store_id,
                json.dumps(["*"]),
                now,
            ),
        )
        conn.commit()
    finally:
        conn.close()
    return store_id, api_token


def start_payserver(data_dir: Path) -> tuple[subprocess.Popen, int]:
    port = free_port()
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
    log = (data_dir / "payserver.log").open("ab")
    proc = subprocess.Popen(
        [str(PHP), "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT), str(wrapper)],
        cwd=str(REPO_ROOT), env=env, stdout=log, stderr=subprocess.STDOUT,
    )
    # Wait for HTTP
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/api/v1/server/info", timeout=1.0)
            return proc, port
        except Exception:
            time.sleep(0.3)
    proc.kill()
    raise TimeoutError(f"cashupayserver did not come up on :{port}")


def fund_electrum_wallet(rpc_port: int, user: str, password: str, sats: int, electrum_datadir: Path | None = None) -> None:
    """Send some sats from Boltz's bitcoind to a fresh Electrum address."""
    addr = None
    if electrum_datadir is not None:
        # CLI is more reliable for AppImage daemons; doesn't need wallet routing.
        try:
            addr = electrum_cli(ELECTRUM, electrum_datadir, "getunusedaddress").strip()
        except Exception:
            pass
    if not addr:
        addr_resp = electrum_rpc(rpc_port, user, password, "getunusedaddress")
        addr = addr_resp.get("result")
    if not addr:
        echo(f"could not get electrum address")
        return
    echo(f"funding Electrum at {addr} with {sats} sats...")
    btc = sats / 100_000_000
    subprocess.run(
        ["sudo", "-n", "docker", "exec", "boltz-scripts", "bash", "-c",
         f"source /etc/profile.d/utils.sh && bitcoin-cli-sim-client -named sendtoaddress address={addr} amount={btc:.8f}"],
        capture_output=True, text=True, check=False,
    )
    # Mine a few blocks to confirm
    subprocess.run(
        ["sudo", "-n", "docker", "exec", "boltz-scripts", "bash", "-c",
         "source /etc/profile.d/utils.sh && bitcoin-cli-sim-client -generate 6"],
        capture_output=True, text=True, check=False,
    )


def main() -> int:
    workdir = Path("/tmp") / f"swap-dev-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir()
    echo(f"workdir = {workdir}")

    # 1. Boltz bitcoind cookie auth
    echo("reading Boltz bitcoind cookie ...")
    cookie = boltz_bitcoind_cookie()

    # 2. Fulcrum -> Boltz bitcoind
    fulcrum_port = free_port()
    echo(f"starting Fulcrum on :{fulcrum_port} -> 127.0.0.1:{BOLTZ_BITCOIND_RPC_PORT}")
    fulcrum_proc = start_fulcrum(workdir, cookie, fulcrum_port)

    # 3. Electrum daemon
    echo("starting Electrum daemon + creating wallet ...")
    electrum_proc, electrum_rpc_port, electrum_user, electrum_pass, vpub = start_electrum(workdir, fulcrum_port)
    echo(f"Electrum vpub = {vpub}")

    # 4. cashupayserver
    echo("initializing cashupayserver DB + seeding swap config ...")
    data_dir = workdir / "payserver-data"
    data_dir.mkdir()
    store_id, api_token = configure_payserver(data_dir, vpub)

    echo("starting cashupayserver (php -S) ...")
    payserver_proc, payserver_port = start_payserver(data_dir)
    payserver_url = f"http://127.0.0.1:{payserver_port}"

    # 5. Optional: fund the Electrum wallet so the GUI shows some balance
    try:
        echo("funding Electrum with 10,000,000 sats from Boltz bitcoind ...")
        fund_electrum_wallet(electrum_rpc_port, electrum_user, electrum_pass, 10_000_000,
                              electrum_datadir=workdir / "electrum")
    except Exception as e:
        echo(f"funding failed (non-fatal — fund via Electrum GUI or scripts container later): {e}")

    print()
    print("=" * 72)
    print("Unified stack ready")
    print("=" * 72)
    print(f"Bitcoin RPC:         127.0.0.1:{BOLTZ_BITCOIND_RPC_PORT}  (Boltz's regtest bitcoind)")
    print(f"Fulcrum:             127.0.0.1:{fulcrum_port}")
    print(f"Electrum daemon RPC: 127.0.0.1:{electrum_rpc_port}  (user={electrum_user})")
    print(f"Electrum vpub:       {vpub}")
    print(f"Boltz API:           {BOLTZ_API_URL}")
    print(f"Boltz web app:       http://localhost:8080")
    print(f"Payserver:           {payserver_url}")
    print(f"Payserver admin:     {payserver_url}/admin  (no password set; setup is bypassed)")
    print(f"Store ID:            {store_id}")
    print(f"API token:           {api_token}")
    print(f"Workdir:             {workdir}")
    print()
    print("Try a swap end-to-end:")
    print("  1. Create a 60,000+ sat invoice via the API:")
    print(f"     curl -X POST {payserver_url}/api/v1/stores/{store_id}/invoices \\")
    print(f"          -H 'Authorization: token {api_token}' \\")
    print(f"          -H 'Content-Type: application/json' \\")
    print(f"          -d '{{\"amount\":60000,\"currency\":\"sat\"}}'")
    print("  2. Copy the bolt11 from the response (checkout.paymentMethods.BTC-LightningNetwork.destination).")
    print("  3. Pay it from Boltz's customer LND:")
    print("     sudo docker exec boltz-scripts bash -c \\")
    print("       'source /etc/profile.d/utils.sh && lncli-sim 1 payinvoice -f <bolt11>'")
    print("  4. Drive cron repeatedly until invoice.status == Settled:")
    print(f"     while true; do curl -s '{payserver_url}/cron.php?key=dev-cron-key' >/dev/null; sleep 2; done")
    print("  5. Open Electrum GUI to watch the claim UTXO land on your vpub:")
    print(f"     APPIMAGE_EXTRACT_AND_RUN=1 {ELECTRUM} --regtest --dir {workdir / 'electrum'} gui")
    print()

    # Halt
    if sys.stdin.isatty():
        print("Press Enter to clean up and exit ...")
        try:
            input()
        except (EOFError, KeyboardInterrupt):
            pass
    else:
        print("(no TTY) Blocking on SIGTERM/SIGINT to keep the stack up...")
        signal.signal(signal.SIGTERM, lambda s, f: sys.exit(0))
        signal.signal(signal.SIGINT, lambda s, f: sys.exit(0))
        signal.pause()

    echo("stopping payserver, electrum, fulcrum ...")
    for name, proc in [("payserver", payserver_proc), ("electrum", electrum_proc), ("fulcrum", fulcrum_proc)]:
        try:
            proc.terminate()
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        except Exception as e:
            echo(f"{name} cleanup: {e}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
