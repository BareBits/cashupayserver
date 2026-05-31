#!/usr/bin/env python3
"""Dev iteration script — spins up the whole stack against fresh data, walks
the install wizard, configures on-chain Bitcoin payments, and pays 2 invoices
each via Lightning, on-chain, and Cashu. Then halts so you can poke at the
running stack; clean shutdown on Enter.

Usage (from repo root):
    .venv/bin/python tests/scripts/iterate.py
or:
    tests/scripts/iterate.py    # if marked executable; uses tests/.venv automatically

Each run is meant to be ephemeral — the script itself is tracked, but the
workdirs it creates under tests/.tmp/ are not.
"""
from __future__ import annotations

import os
import shutil
import signal
import subprocess
import sys
import time
import uuid
from pathlib import Path

# ------------------------------------------------------------------------------
# Path setup — make tests/ imports work without a package install.
# ------------------------------------------------------------------------------

SCRIPT_DIR = Path(__file__).resolve().parent
TESTS_DIR = SCRIPT_DIR.parent
REPO_ROOT = TESTS_DIR.parent
sys.path.insert(0, str(TESTS_DIR))

# Bootstrap the test venv if the user accidentally ran this with the system
# python. Comparing the raw (unresolved) paths is intentional: venv/bin/python
# is usually a symlink to the system python, but they activate different
# sys.path / site-packages. We must re-exec via the venv path itself so the
# venv's site-packages takes precedence.
_VENV_PY = TESTS_DIR / ".venv" / "bin" / "python"
if _VENV_PY.exists() and str(_VENV_PY) != sys.executable:
    os.execv(str(_VENV_PY), [str(_VENV_PY), *sys.argv])


from fixtures import binaries  # noqa: E402
from fixtures.api_client import AdminClient, GreenfieldClient  # noqa: E402
from fixtures.bitcoind import BitcoindHandle, start_bitcoind, stop_bitcoind  # noqa: E402
from fixtures.cashume import CashuMeHandle, start_cashume, stop_cashume  # noqa: E402
from fixtures.electrum import (  # noqa: E402
    ElectrumHandle,
    fund_electrum_from_bitcoind,
    launch_electrum_gui,
    open_electrum_channel_to_lnd,
    start_electrum,
    stop_electrum,
)
from fixtures.fulcrum import FulcrumHandle, start_fulcrum, stop_fulcrum  # noqa: E402
from fixtures.lnd import (  # noqa: E402
    LndHandle,
    open_dual_channels,
    open_extra_channel,
    start_lnd,
    stop_lnd,
)
from fixtures.nutshell import MintHandle, NUTSHELL_VENV, start_mint, stop_mint  # noqa: E402
from fixtures.onchain import (  # noqa: E402
    OnchainContext,
    configure_store_for_onchain,
    make_onchain_context,
)
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver  # noqa: E402
from fixtures.setup_helpers import run_setup_wizard  # noqa: E402

# ------------------------------------------------------------------------------
# Config
# ------------------------------------------------------------------------------

ADMIN_PASSWORD = "password"
STORE_ONECONF = "oneconf"
STORE_ZEROCONF = "zeroconf"
LIGHTNING_AMOUNT_SAT = 1500
ONCHAIN_AMOUNT_SAT = 25000
CASHU_AMOUNT_SAT = 800
CASHU_FUNDING_SAT = 5000  # how much to mint into the auto-pay Cashu wallet
# Customer-side wallet funding (the wallets handed off for manual play)
ELECTRUM_FUNDING_SAT = 10_000_000   # 0.1 BTC on-chain
ELECTRUM_CHANNEL_SAT = 200_000      # Lightning channel capacity to lnd_mint
CASHUME_FUNDING_SAT = 100_000_000   # 1 BTC pre-loaded into the cashu.me wallet
AUTOMINE_INTERVAL_SEC = 30          # regtest blocks tick this often so 1-conf invoices settle

ITERATE_ROOT = TESTS_DIR / ".tmp"
PLAYWRIGHT_BROWSERS = TESTS_DIR / "bin" / "playwright-browsers"


# ------------------------------------------------------------------------------
# Stale process cleanup
# ------------------------------------------------------------------------------

def kill_stale_processes() -> None:
    """Kill any leftover bitcoind / lnd / cashu-mint / php / chromium processes
    from prior iterate.py runs. Matches on the tests/ paths to avoid touching
    unrelated processes on the host."""
    needles = [
        str(TESTS_DIR / "bin"),
        str(TESTS_DIR / ".tmp"),
        str(NUTSHELL_VENV / "bin" / "mint"),
        str(PLAYWRIGHT_BROWSERS),
    ]
    print("[iterate] killing stale processes from prior runs ...")
    for needle in needles:
        try:
            subprocess.run(["pkill", "-9", "-f", needle], check=False)
        except FileNotFoundError:
            pass
    time.sleep(0.5)


# ------------------------------------------------------------------------------
# Cashu wallet (the customer side) via the nutshell CLI
# ------------------------------------------------------------------------------

class CashuWallet:
    """Thin wrapper around the nutshell `cashu` CLI used by the dev script
    to mint and spend tokens against the same mint cashupayserver uses.

    Operates in an isolated CASHU_DIR so multiple runs don't collide."""

    def __init__(self, mint_url: str, data_dir: Path) -> None:
        self.mint_url = mint_url
        self.data_dir = data_dir
        self.data_dir.mkdir(parents=True, exist_ok=True)
        self.cli = NUTSHELL_VENV / "bin" / "cashu"
        if not self.cli.is_file():
            raise RuntimeError(f"nutshell CLI missing at {self.cli}")

    def _env(self) -> dict[str, str]:
        env = os.environ.copy()
        env["CASHU_DIR"] = str(self.data_dir)
        env["MINT_URL"] = self.mint_url
        env["DEBUG"] = "false"
        # Disable the CLI's interactive nostr nag.
        env["NOSTR_PRIVATE_KEY"] = ""
        return env

    def _run(self, *args: str, timeout: float = 60.0) -> subprocess.CompletedProcess[str]:
        cmd = [str(self.cli), "--host", self.mint_url, *args]
        result = subprocess.run(
            cmd, env=self._env(), capture_output=True, text=True, timeout=timeout,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"cashu CLI failed: {' '.join(args)}\n"
                f"stdout: {result.stdout}\nstderr: {result.stderr}"
            )
        return result

    def request_mint_quote(self, amount_sat: int) -> tuple[str, str]:
        """Returns (bolt11, quote_id) for an inbound mint. nutshell's CLI
        prints `Invoice: <bolt11>` on one line and recommends a follow-up
        command of the form `cashu invoice <amount> --id <quote_id>`."""
        import re
        out = self._run("invoice", str(amount_sat), "--no-check").stdout
        bolt11_match = re.search(r"Invoice:\s+(lnbc\w+)", out)
        id_match = re.search(r"--id\s+([A-Za-z0-9_\-]+)", out)
        if not bolt11_match:
            raise RuntimeError(f"could not parse bolt11 from cashu invoice output:\n{out}")
        if not id_match:
            raise RuntimeError(f"could not parse quote id from cashu invoice output:\n{out}")
        return bolt11_match.group(1), id_match.group(1)

    def claim_mint(self, quote_id: str, amount_sat: int) -> None:
        """After the bolt11 from request_mint_quote has been paid externally,
        finalize the mint to receive the proofs."""
        self._run("invoice", str(amount_sat), "--id", quote_id, timeout=30)

    def balance_sat(self) -> int:
        out = self._run("balance").stdout
        for line in out.splitlines():
            if "balance:" in line.lower():
                # e.g. "Balance: 5000 sat"
                parts = line.split(":")[-1].strip().split()
                if parts and parts[0].isdigit():
                    return int(parts[0])
        return 0

    def pay_bolt11(self, bolt11: str) -> None:
        """Melt tokens to pay an external bolt11. Used to settle a
        cashupayserver invoice's bolt11 via the Cashu/melt flow."""
        # Newer nutshell uses `cashu pay`; older versions used `cashu melt`.
        # Try `pay` first, fall back to `melt`.
        try:
            self._run("pay", bolt11, "--yes", timeout=60)
        except RuntimeError:
            self._run("melt", bolt11, "--yes", timeout=60)

    def send_token(self, amount_sat: int) -> str:
        """Extract `amount_sat` worth of proofs into a Cashu token string the
        receiver can import (e.g. paste into cashu.me). Returns the token."""
        import re
        out = self._run("send", str(amount_sat), "--yes", "--offline", timeout=30).stdout
        # nutshell prints the token on its own line, beginning with cashuA... or cashuB...
        m = re.search(r"(cashu[AB][A-Za-z0-9+/=_\-]+)", out)
        if not m:
            raise RuntimeError(f"could not parse Cashu token from `cashu send` output:\n{out}")
        return m.group(1)


# ------------------------------------------------------------------------------
# Payment helpers
# ------------------------------------------------------------------------------

def create_lightning_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(LIGHTNING_AMOUNT_SAT), currency="sat",
                             metadata={"label": "lightning-iterate"})


def create_onchain_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(ONCHAIN_AMOUNT_SAT), currency="sat",
                             metadata={"label": "onchain-iterate"})


def create_cashu_invoice(gc: GreenfieldClient, store_id: str) -> dict:
    return gc.create_invoice(store_id, amount=str(CASHU_AMOUNT_SAT), currency="sat",
                             metadata={"label": "cashu-iterate"})


def bolt11_of(inv: dict) -> str:
    return inv["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]


def onchain_address_of(inv: dict) -> str:
    return inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]


def wait_for_status(gc: GreenfieldClient, store_id: str, invoice_id: str, expected: str,
                    timeout_s: float = 60.0, label: str = "") -> dict:
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        last = gc.get_invoice(store_id, invoice_id)
        if last.get("status") == expected:
            return last
        time.sleep(0.5)
    raise AssertionError(f"{label or invoice_id} never reached {expected}; last={last}")


def _create_second_store(admin: AdminClient, db_path: Path, name: str, model_store_id: str) -> str:
    """Create a second store via the admin create_store action, then copy the
    wizard-created store's mint config (mint_url, mint_unit, seed_phrase) over
    via direct DB write so both stores can accept Lightning/Cashu invoices.
    Returns the new store's id."""
    import sqlite3
    result = admin._post_action("create_store", name=name)
    new_id = result["id"]
    conn = sqlite3.connect(db_path, isolation_level=None)
    try:
        row = conn.execute(
            "SELECT mint_url, mint_unit, seed_phrase, default_currency FROM stores WHERE id = ?",
            (model_store_id,),
        ).fetchone()
        if not row:
            raise RuntimeError(f"model store {model_store_id} not found")
        conn.execute(
            "UPDATE stores SET mint_url = ?, mint_unit = ?, seed_phrase = ?, default_currency = ? "
            "WHERE id = ?",
            (*row, new_id),
        )
    finally:
        conn.close()
    return new_id


# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------

def main() -> int:
    kill_stale_processes()

    workdir = ITERATE_ROOT / f"iterate-{int(time.time())}-{uuid.uuid4().hex[:6]}"
    workdir.mkdir(parents=True, exist_ok=True)
    print(f"[iterate] workdir = {workdir}")

    # 0. Make sure binaries are cached.
    binaries.ensure_all()

    handles: list[tuple[str, callable, object]] = []  # (label, stopper, handle)
    try:
        # 1. Infra
        print("[iterate] starting bitcoind regtest ...")
        bitcoind = start_bitcoind(workdir)
        handles.append(("bitcoind", stop_bitcoind, bitcoind))

        print("[iterate] starting LND mint + payer ...")
        lnd_mint = start_lnd(workdir, "mint", bitcoind)
        handles.append(("lnd-mint", stop_lnd, lnd_mint))
        lnd_payer = start_lnd(workdir, "payer", bitcoind)
        handles.append(("lnd-payer", stop_lnd, lnd_payer))

        print("[iterate] opening dual channels (10M cap, 5M push each side) ...")
        open_dual_channels(bitcoind, lnd_payer, lnd_mint)

        # Auto-mine 1 block every AUTOMINE_INTERVAL_SEC seconds for the rest
        # of the session so on-chain payments to the "oneconf" store settle
        # without manual intervention. The daemon thread is registered with
        # the cleanup handler list so it stops on signal.
        import threading
        automine_stop = threading.Event()
        def _automine():
            while not automine_stop.wait(AUTOMINE_INTERVAL_SEC):
                try:
                    bitcoind.mine(1)
                except Exception as e:
                    print(f"[iterate] auto-mine tick failed: {e}")
        automine_thread = threading.Thread(target=_automine, name="iterate-automine", daemon=True)
        automine_thread.start()
        def _stop_automine(_):
            automine_stop.set()
            automine_thread.join(timeout=5)
        handles.append(("automine", _stop_automine, None))
        print(f"[iterate] auto-mine started ({AUTOMINE_INTERVAL_SEC}s interval)")

        print("[iterate] starting nutshell mint ...")
        mint = start_mint(workdir, lnd_mint)
        handles.append(("mint", stop_mint, mint))

        print("[iterate] starting cashupayserver ...")
        payserver = start_payserver(workdir / "payserver")
        handles.append(("payserver", stop_payserver, payserver))

        onchain = make_onchain_context(bitcoind, f"cashupay-watch-iter-{uuid.uuid4().hex[:6]}")

        # 2. Setup wizard — driven through the browser so the user can see the
        #    initial state and so the UI flow is exercised end-to-end.
        print("[iterate] running setup wizard via Playwright (headless) ...")
        os.environ.setdefault("PLAYWRIGHT_BROWSERS_PATH", str(PLAYWRIGHT_BROWSERS))
        from playwright.sync_api import sync_playwright  # noqa: E402

        with sync_playwright() as pw:
            browser = pw.chromium.launch(
                headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"],
            )
            ctx = browser.new_context()
            page = ctx.new_page()
            page.set_default_timeout(15000)

            page.goto(f"{payserver.url}/setup")
            page.check("#security_acknowledged")
            page.click("button[type=submit]")
            page.fill("#password", ADMIN_PASSWORD)
            page.fill("#confirm_password", ADMIN_PASSWORD)
            page.click("button[type=submit]")
            page.fill("#store_name", STORE_ONECONF)
            page.click("button[type=submit]")
            page.fill("#mint_url", mint.url)
            page.click("button[type=submit]")
            page.wait_for_selector("#mint_unit")
            page.select_option("#mint_unit", "sat")
            page.click("#continue-btn")
            page.wait_for_selector("button[type=submit]")
            page.click("button:has-text('Generate New Seed Phrase')")
            page.wait_for_selector("#seed_confirmed")
            page.check("#seed_confirmed")
            page.click("button[type=submit]")
            page.wait_for_selector("button:has-text('Skip for now')")
            page.click("button:has-text('Skip for now')")

            browser.close()

        # 3. Bring up Fulcrum + Electrum NOW (before configuring stores) so
        #    we can pull Electrum's vpub and register it as the on-chain xpub
        #    for both stores. That way addresses cashupayserver generates
        #    match what Electrum's wallet displays for receive.
        print("[iterate] starting Fulcrum (Electrum protocol server -> bitcoind) ...")
        fulcrum = start_fulcrum(workdir, bitcoind)
        handles.append(("fulcrum", stop_fulcrum, fulcrum))

        print("[iterate] starting Electrum daemon (regtest) ...")
        electrum = start_electrum(workdir, fulcrum)
        handles.append(("electrum", stop_electrum, electrum))

        electrum_vpub = electrum.cli("getmpk").strip()
        print(f"[iterate] Electrum vpub: {electrum_vpub[:16]}...{electrum_vpub[-8:]}")

        # 4. Login as admin via the AdminClient + mint an API key.
        print("[iterate] logging in as admin + creating API key ...")
        admin = AdminClient(payserver.url)
        admin.login(ADMIN_PASSWORD)
        stores = admin.list_stores()
        oneconf_store_id = next(s["id"] for s in stores if s["name"] == STORE_ONECONF)

        # 5. Create the second store via the Greenfield API + grant the same
        #    seed/mint config the wizard gave to "oneconf" so it can take
        #    Cashu invoices too.
        print(f"[iterate] creating second store '{STORE_ZEROCONF}' ...")
        # Use the admin AJAX endpoint instead of the bare Greenfield POST /stores
        # because the wizard sets mint_url + seed on the wizard store; for the
        # second store we copy those over via direct DB write.
        zeroconf_store_id = _create_second_store(
            admin, payserver.db_path, STORE_ZEROCONF, oneconf_store_id,
        )

        key = admin.create_api_key(oneconf_store_id, label="iterate-oneconf")
        token_oneconf = key["key"]
        gc_oneconf = GreenfieldClient(payserver.url, token_oneconf)
        key2 = admin.create_api_key(zeroconf_store_id, label="iterate-zeroconf")
        token_zeroconf = key2["key"]
        gc_zeroconf = GreenfieldClient(payserver.url, token_zeroconf)

        # 6. Configure on-chain on BOTH stores with Electrum's vpub. They
        #    share the same xpub; the per-xpub counter in onchain_xpub_state
        #    keeps their derivation indices in sync so they can't collide.
        print(f"[iterate] configuring on-chain on both stores (vpub={electrum_vpub[:12]}...) ...")
        configure_store_for_onchain(
            payserver.db_path, oneconf_store_id,
            xpub=electrum_vpub, network="regtest", address_type="P2WPKH",
            min_confs=1, confirm_timeout_sec=86400,
            provider_url=onchain.watch_wallet_url, start_index=0,
        )
        configure_store_for_onchain(
            payserver.db_path, zeroconf_store_id,
            xpub=electrum_vpub, network="regtest", address_type="P2WPKH",
            min_confs=0, confirm_timeout_sec=86400,
            provider_url=onchain.watch_wallet_url, start_index=0,
        )

        # 5. Prepare the customer-side Cashu wallet — mint enough tokens upfront
        #    that we can pay the two Cashu-flow invoices with melts.
        print(f"[iterate] funding customer Cashu wallet with {CASHU_FUNDING_SAT} sats ...")
        cashu_wallet = CashuWallet(mint.url, workdir / "cashu-wallet")
        bolt11_for_mint, quote_id = cashu_wallet.request_mint_quote(CASHU_FUNDING_SAT)
        pay_result = lnd_payer.pay_invoice_sync(bolt11_for_mint, timeout=30)
        if pay_result.get("payment_error"):
            raise RuntimeError(f"Cashu wallet funding payment failed: {pay_result}")
        # Give the mint a moment to mark the quote paid, then claim.
        time.sleep(1.5)
        cashu_wallet.claim_mint(quote_id, CASHU_FUNDING_SAT)
        print(f"[iterate] Cashu wallet balance: {cashu_wallet.balance_sat()} sat")

        # 7. Create + pay 6 invoices: 1 of each method per store.
        results: list[tuple[str, str, str, str]] = []  # (store, method, invoice_id, status)

        store_pairs = [
            ("oneconf", oneconf_store_id, gc_oneconf),
            ("zeroconf", zeroconf_store_id, gc_zeroconf),
        ]

        print("\n[iterate] === Lightning invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_lightning_invoice(gc, sid)
            print(f"  [{label}] {inv['id']} -> paying {LIGHTNING_AMOUNT_SAT} sat via lnd_payer")
            lnd_payer.pay_invoice_sync(bolt11_of(inv), timeout=30)
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=30, label=f"lightning {label}")
            results.append((label, "Lightning", inv["id"], settled["status"]))

        print("\n[iterate] === On-chain invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_onchain_invoice(gc, sid)
            addr = onchain_address_of(inv)
            print(f"  [{label}] {inv['id']} -> sending {ONCHAIN_AMOUNT_SAT} sat to {addr}")
            onchain.fund_address(addr, ONCHAIN_AMOUNT_SAT)
            # oneconf needs a block; zeroconf settles on mempool sighting
            onchain.confirm(1)
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=45, label=f"onchain {label}")
            results.append((label, "On-chain", inv["id"], settled["status"]))

        print("\n[iterate] === Cashu (wallet melt) invoices (one per store) ===")
        for label, sid, gc in store_pairs:
            inv = create_cashu_invoice(gc, sid)
            print(f"  [{label}] {inv['id']} -> paying {CASHU_AMOUNT_SAT} sat via Cashu wallet melt")
            cashu_wallet.pay_bolt11(bolt11_of(inv))
            settled = wait_for_status(gc, sid, inv["id"], "Settled",
                                      timeout_s=30, label=f"cashu {label}")
            results.append((label, "Cashu", inv["id"], settled["status"]))

        # 8. Customer-side wallets: fund Electrum on-chain and open its LN
        #    channel. (Electrum + Fulcrum were started earlier so we could
        #    grab the vpub before configuring the stores.)
        print("\n[iterate] === Setting up customer wallets ===")
        print(f"[iterate] funding Electrum on-chain with {ELECTRUM_FUNDING_SAT:,} sat ...")
        fund_electrum_from_bitcoind(electrum, bitcoind, ELECTRUM_FUNDING_SAT, confirmations=3)

        print(f"[iterate] opening Electrum Lightning channel to lnd_mint ({ELECTRUM_CHANNEL_SAT:,} sat) ...")
        open_electrum_channel_to_lnd(
            electrum, bitcoind, lnd_mint.pubkey, "127.0.0.1", lnd_mint.p2p_port,
            capacity_sat=ELECTRUM_CHANNEL_SAT,
        )

        # 1 BTC pre-mint needs much more outbound than the dual-channel 5M
        # push gives. Open an extra big channel from the customer LN to the
        # mint backend's LN so the mint quote payment routes through it.
        cashu_fund_channel_sat = CASHUME_FUNDING_SAT + 10_000_000  # headroom for fees + LND reserves
        print(f"[iterate] opening extra {cashu_fund_channel_sat:,} sat channel "
              f"for the {CASHUME_FUNDING_SAT:,} sat Cashu pre-fund ...")
        open_extra_channel(
            bitcoind, lnd_payer, lnd_mint,
            capacity_sat=cashu_fund_channel_sat, push_sat=0,
        )

        print(f"[iterate] minting {CASHUME_FUNDING_SAT:,} sat ({CASHUME_FUNDING_SAT/1e8:.4f} BTC) "
              f"Cashu token for cashu.me ...")
        bolt11_cashume, qid_cashume = cashu_wallet.request_mint_quote(CASHUME_FUNDING_SAT)
        pay_result = lnd_payer.pay_invoice_sync(bolt11_cashume, timeout=120)
        if pay_result.get("payment_error"):
            raise RuntimeError(f"cashu.me funding payment failed: {pay_result['payment_error']}")
        time.sleep(3)
        cashu_wallet.claim_mint(qid_cashume, CASHUME_FUNDING_SAT)
        cashume_token = cashu_wallet.send_token(CASHUME_FUNDING_SAT)

        print("[iterate] starting cashu.me dev server ...")
        cashume = start_cashume()
        handles.append(("cashu.me", stop_cashume, cashume))
        cashume_url = cashume.deeplink(mint_url=mint.url, token=cashume_token)

        print("[iterate] launching Electrum GUI ...")
        launch_electrum_gui(electrum)

        print("[iterate] launching cashu.me + admin in a single Chromium window ...")
        # Use Playwright's manual lifecycle (start/stop instead of context
        # manager) so the browser stays open for the user while we wait for
        # the cleanup signal. All URLs land as tabs of the same window so
        # the user has a single Chromium to alt-tab through.
        from playwright.sync_api import sync_playwright  # noqa: E402
        wallet_pw = sync_playwright().start()
        wallet_browser = wallet_pw.chromium.launch(
            headless=False, args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        wallet_ctx = wallet_browser.new_context()

        # Pre-seed cashu.me's localStorage to bypass the welcome wizard
        # before the page even mounts. Keys derived from
        # tests/bin/cashu.me/src/stores/welcome.ts. useLocalStorage from
        # vueuse stores values as JSON strings.
        wallet_ctx.add_init_script("""
            try {
                if (location.host.startsWith('127.0.0.1') || location.host.startsWith('localhost')) {
                    localStorage.setItem('cashu.welcome.showWelcome', 'false');
                    localStorage.setItem('cashu.welcome.termsAccepted', 'true');
                    localStorage.setItem('cashu.welcome.seedPhraseValidated', 'true');
                    localStorage.setItem('cashu.welcome.mintSetupCompleted', 'true');
                    localStorage.setItem('cashu.welcome.ecashRestoreCompleted', 'true');
                    localStorage.setItem('cashu.welcome.path', '"new"');
                }
            } catch (_) {}
        """)

        cashume_tab = wallet_ctx.new_page()
        cashume_tab.set_default_timeout(15000)
        try:
            # First navigation triggers Vite's cold-start SPA bundle compile,
            # which can comfortably exceed the 15s default on a fresh
            # node_modules/.cache. Give it 2 minutes; warm runs return in
            # seconds anyway.
            cashume_tab.goto(cashume_url, timeout=120000)
            # The ?mint=... deeplink opens AddMintDialog ("Do you trust this
            # mint?"). Its primary action button has a stable class .add-btn.
            cashume_tab.locator(".add-btn:visible").first.click(timeout=15000)
            # Give the add-mint dialog its close animation + give the
            # ReceiveTokenDialog its open animation. Without this delay we
            # race and end up clicking a stale button.
            cashume_tab.wait_for_selector(".add-btn", state="hidden", timeout=10000)

            # The receive dialog has three potential buttons: "Later"
            # (flat), "Swap" (outline), and the primary handleReceive button
            # ("Receive"/"Receive Ecash", unelevated, full-width, size lg).
            # A plain `:has-text('Receive')` would also match tooltips, so
            # we target the unique class combination of the primary button.
            cashume_tab.locator(
                ".q-dialog button.full-width.q-btn--unelevated:not(.q-btn--flat):not(.q-btn--outline):visible"
            ).first.click(timeout=20000)
        except Exception as e:
            print(f"[iterate] (note) cashu.me auto-click hit a snag — finish manually: {e}")

        admin_tab = wallet_ctx.new_page()
        admin_tab.goto(f"{payserver.url}/admin")

        def stop_wallet_browser(_):
            try: wallet_ctx.close()
            except Exception: pass
            try: wallet_browser.close()
            except Exception: pass
            try: wallet_pw.stop()
            except Exception: pass
        handles.append(("wallet-browser", stop_wallet_browser, None))

        # 9. Summary + halt
        print("\n" + "=" * 72)
        print(f"{'Store':<10} {'Method':<12} {'Invoice ID':<32} {'Status'}")
        print("-" * 72)
        for store_label, method, iid, status in results:
            print(f"{store_label:<10} {method:<12} {iid:<32} {status}")
        print("=" * 72)
        print(f"\nAdmin URL:        {payserver.url}/admin     (password: {ADMIN_PASSWORD})")
        print(f"Payserver:        {payserver.url}")
        print(f"Mint:             {mint.url}")
        print(f"oneconf store id: {oneconf_store_id}    (on-chain min_confs=1)")
        print(f"oneconf API key:  {token_oneconf}")
        print(f"zeroconf store id:{zeroconf_store_id}    (on-chain min_confs=0)")
        print(f"zeroconf API key: {token_zeroconf}")
        print(f"Workdir:          {workdir}")
        print(f"Auto-mine:        every {AUTOMINE_INTERVAL_SEC}s (so oneconf on-chain invoices settle)")
        print(f"\nElectrum:         GUI launched (regtest); vpub registered on both stores")
        print(f"                  ~{ELECTRUM_FUNDING_SAT/1e8:.4f} BTC on-chain, "
              f"~{ELECTRUM_CHANNEL_SAT:,} sat channel to lnd_mint")
        print(f"cashu.me:         {cashume_url}")
        print(f"                  opened in Chromium with {CASHUME_FUNDING_SAT} sat pre-loaded")

        # If stdin is a terminal, halt on Enter as advertised. When the script
        # is launched in the background (no controlling terminal — stdin is
        # closed / piped), input() raises EOFError immediately and we'd tear
        # everything down before the caller can use the stack. In that case,
        # block until we get a signal (SIGTERM/SIGINT) so the caller can drive
        # the lifecycle externally.
        if sys.stdin.isatty():
            print("\nPress Enter to clean up everything and exit ...")
            try:
                input()
            except (EOFError, KeyboardInterrupt):
                pass
        else:
            print("\n[iterate] running in background; send SIGTERM/SIGINT to clean up.", flush=True)
            stop_event = signal.SIGTERM  # sentinel for the handler below
            received: list[int] = []
            def _handler(signum, _frame):
                received.append(signum)
            signal.signal(signal.SIGTERM, _handler)
            signal.signal(signal.SIGINT, _handler)
            while not received:
                signal.pause()

    finally:
        print("\n[iterate] cleaning up ...")
        for label, stopper, handle in reversed(handles):
            try:
                stopper(handle)
                print(f"  stopped {label}")
            except Exception as e:
                print(f"  failed to stop {label}: {e}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
