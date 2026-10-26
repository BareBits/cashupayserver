"""End-to-end: per-install BIP32 branch for dev-fee on-chain addresses.

Every install derives dev-fee addresses from the same BareBits zpub, so
without coordination two installs produce the same address at the same index
and can't tell whose customer paid. includes/fee_branch.php fixes that by
asking getbarebits.com/xpub.php for a sequential branch once; addresses then
derive at <zpub>/<branch>/0/<index>. These tests run the REAL xpub.php on a
local php -S (fixtures/xpub_endpoint.py) with a regtest dev-fee tpub and
drive the whole loop on bitcoind regtest:

  - cron acquires the branch; a fee-redirected invoice lands on
    <tpub>/<branch>/0/0 (parity with bitcoin-cli deriveaddresses), is paid,
    settles, and credits the dev fee;
  - endpoint DOWN when the install boots: the dev fee takes NO on-chain rail
    (the invoice's on-chain address is the merchant's own), cron keeps
    retrying, redirects start once the endpoint is back;
  - endpoint returning 500 / garbage: an error per cron tick, retried every
    tick, no branch stored;
  - no cron at all: invoice creation obtains the branch inline (and the 60s
    throttle keeps a dead endpoint from delaying every checkout — inside the
    window the invoice simply isn't fee-routed on-chain);
  - two installs against one endpoint get branches 1 and 2, distinct
    addresses, and a payment to one never settles the other (the bug this
    exists to prevent);
  - the branch shows up in the admin Fees card, the stats API and the
    diagnostics export.

All payservers here are booted per test (function scope): the branch is
install-global config, which the shared-server fixtures forbid mutating.
"""
from __future__ import annotations

import json
import sqlite3
import time
import uuid

import pytest

from conftest import (
    SESSION_TMP,
    ConfiguredPayserver,
    _configure,
    _maybe_remove_workdir,
    _test_went_green,
)
from fixtures.bitcoind import BitcoindHandle
from fixtures.nutshell import MintHandle
from fixtures.onchain import (
    DEV_FEE_TEST_TPUB,
    OnchainContext,
    configure_store_for_onchain,
    derive_address_in_bitcoind,
    make_onchain_context,
)
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.xpub_endpoint import XpubEndpoint, fee_branch_env

INVOICE_SATS = 20_000
# 1% dev fee on this revenue = 100_000 sats owed: covers several INVOICE_SATS
# redirects in a row (each settled redirect credits the fee and lowers owed).
SEED_REVENUE_SATS = 10_000_000


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------


def _boot(request, ep: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle) -> ConfiguredPayserver:
    """Fresh payserver pointed at the local endpoint, wizard-configured, torn
    down with the test. Built here (not as a fixture) so a test controls the
    endpoint's state BEFORE the install's first cron tick."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env=fee_branch_env(ep))

    def _teardown() -> None:
        stop_payserver(handle)
        _maybe_remove_workdir(workdir, _test_went_green(request))

    request.addfinalizer(_teardown)
    return _configure(handle, mint, backup_mint)


def _cron(c: ConfiguredPayserver) -> dict:
    """Run a cron tick of OURS (retry past the overlap lock) and return it."""
    deadline = time.monotonic() + 30
    while True:
        r = c.handle.trigger_cron()
        assert r.status_code == 200, r.text
        body = r.json()
        if "tasks" in body:
            return body
        assert "skipped" in body, body
        assert time.monotonic() < deadline, f"cron lock never freed: {body}"
        time.sleep(0.5)


def _fee_branch_task(c: ConfiguredPayserver) -> str:
    body = _cron(c)
    assert "fee_branch" in body["tasks"], body
    return body["tasks"]["fee_branch"]


def _config(handle: PayserverHandle, key: str):
    with sqlite3.connect(str(handle.db_path)) as conn:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except (TypeError, ValueError):
        return row[0]


def _set_config(handle: PayserverHandle, key: str, value) -> None:
    now = int(time.time())
    with sqlite3.connect(str(handle.db_path)) as conn:
        conn.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value), now, now),
        )


def _seed_fee_revenue(handle: PayserverHandle, store_id: str, sats: int) -> None:
    """A synthetic Settled invoice so DevFee::computeOwed sees revenue, and a
    zeroed fee_tracking_start_at so it falls inside the accounting window."""
    now = int(time.time())
    with sqlite3.connect(str(handle.db_path)) as conn:
        conn.execute(
            "INSERT OR REPLACE INTO config (key, value, created_at, updated_at) "
            "VALUES ('fee_tracking_start_at', '0', ?, ?)",
            (now, now),
        )
        conn.execute(
            "INSERT INTO invoices (id, store_id, status, amount, currency, amount_sats, "
            " created_at, expiration_time, payment_rail, settled_rail, paid_at) "
            "VALUES (?, ?, 'Settled', ?, 'sat', ?, ?, ?, 'lnaddress', 'lnaddress', ?)",
            (f"seed_{uuid.uuid4().hex[:8]}", store_id, str(sats), sats, now - 60, now + 3600, now - 60),
        )


def _wire_onchain(c: ConfiguredPayserver, onchain: OnchainContext) -> None:
    configure_store_for_onchain(
        c.handle.db_path,
        c.store_id,
        xpub=onchain.tpub,
        network="regtest",
        address_type="P2WPKH",
        min_confs=0,
        confirm_timeout_sec=86400,
        provider_url=onchain.watch_wallet_url,
        start_index=0,
    )


def _invoice_row(handle: PayserverHandle, invoice_id: str) -> dict:
    with sqlite3.connect(str(handle.db_path)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row is not None, f"no invoices row for {invoice_id}"
    return dict(row)


def _create_fee_invoice(c: ConfiguredPayserver) -> tuple[dict, dict]:
    """Create an invoice whose on-chain rail is redirected to the dev fee."""
    inv = c.greenfield.create_invoice(c.store_id, amount=str(INVOICE_SATS), currency="sat")
    row = _invoice_row(c.handle, inv["id"])
    assert row["fee_redirect_note"] == "DEV_FEE", f"expected a dev-fee redirect; row={row}"
    assert row["fee_redirect_rails"] == "onchain", (
        f"only the on-chain rail should be fee-routed (the dev LNURL is unresolvable); got {row['fee_redirect_rails']!r}"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    assert addr == row["onchain_address"] == row["fee_redirect_destination"]
    return inv, row


def _create_merchant_invoice(c: ConfiguredPayserver) -> tuple[dict, dict]:
    """Create an invoice and assert its on-chain rail stayed with the merchant
    (no fee redirect on-chain) even though the dev fee is owed."""
    inv = c.greenfield.create_invoice(c.store_id, amount=str(INVOICE_SATS), currency="sat")
    row = _invoice_row(c.handle, inv["id"])
    assert row["fee_redirect_rails"] is None and row["fee_redirect_note"] is None, (
        f"no branch -> the dev fee must not claim any rail; row={row}"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    assert addr == row["onchain_address"]
    return inv, row


def _fee_address(bitcoind: BitcoindHandle, *, branch: int | None, index: int) -> str:
    return derive_address_in_bitcoind(bitcoind, DEV_FEE_TEST_TPUB, "P2WPKH", index, branch=branch)


def _poll_until(c: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30) -> dict:
    deadline = time.monotonic() + timeout_s
    last = None
    while time.monotonic() < deadline:
        c.handle.trigger_cron()
        last = c.greenfield.get_invoice(c.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status}; last={last}")


def _pay_and_settle(c: ConfiguredPayserver, onchain: OnchainContext, invoice_id: str, address: str) -> None:
    """Fund the address, wait for Settled, then bury the payment two blocks
    deep. Every test's install gets branch 1 from its own fresh endpoint
    store, so tests derive the SAME fee addresses (same key, branch, index);
    mining makes the payment historical for the next test's invoices
    (onchain_created_tip_height filter) instead of a live mempool sighting."""
    onchain.fund_address(address, INVOICE_SATS)
    assert _poll_until(c, invoice_id, "Settled")["status"] == "Settled"
    onchain.confirm(2)


def _stats(c: ConfiguredPayserver) -> dict:
    r = c.admin.s.get(f"{c.handle.url}/admin?api=stats_summary&store_id={c.store_id}&range=all", timeout=15)
    r.raise_for_status()
    return r.json()


def _diagnostics(c: ConfiguredPayserver) -> dict:
    r = c.admin.s.get(f"{c.handle.url}/admin?api=export_diagnostic_report&range=all&anonymize=1", timeout=30)
    r.raise_for_status()
    return r.json()


# ---------------------------------------------------------------------------
# tests
# ---------------------------------------------------------------------------


def test_cron_acquires_branch_and_redirect_pays_into_it(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle,
    onchain: OnchainContext, bitcoind: BitcoindHandle,
) -> None:
    c = _boot(request, xpub_endpoint, mint, backup_mint)

    # The install's cron obtains branch 1 (fresh endpoint store), stores it,
    # and later ticks are no-ops against the endpoint.
    task = _fee_branch_task(c)
    assert task in ("acquired (branch 1)", "ok (branch 1)"), task
    assert _config(c.handle, "dev_fee_onchain_branch") == 1
    assert _config(c.handle, "dev_fee_branch_acquired_at") > 0
    assert _config(c.handle, "dev_fee_branch_last_error") is None
    assert _fee_branch_task(c) == "ok (branch 1)"
    assert xpub_endpoint.highest() == 1, "exactly one allocation for one install"

    # Surfaces: stats API, admin Fees card data, diagnostics export.
    fb = _stats(c)["dev_fee_branch"]
    assert fb["branch"] == 1 and fb["disabled"] is False and fb["last_error"] is None, fb
    assert fb["endpoint"] == xpub_endpoint.url
    diag = _diagnostics(c)
    assert diag["system"]["config"]["dev_fee_onchain_branch"] == 1
    assert "dev_fee_branch_acquired_at" in diag["system"]["config"]

    # A fee-redirected invoice derives on the branch: <tpub>/1/0/0, byte-for-
    # byte what bitcoin-cli derives for that path, and NOT the shared-chain
    # address every branchless install would have produced.
    _wire_onchain(c, onchain)
    _seed_fee_revenue(c.handle, c.store_id, SEED_REVENUE_SATS)
    inv, row = _create_fee_invoice(c)
    assert row["onchain_address"] == _fee_address(bitcoind, branch=1, index=0)
    assert row["onchain_address"] != _fee_address(bitcoind, branch=None, index=0)
    assert row["onchain_address_index"] == 0

    # Second redirect: next index under the same branch.
    inv2, row2 = _create_fee_invoice(c)
    assert row2["onchain_address"] == _fee_address(bitcoind, branch=1, index=1)
    assert row2["onchain_address_index"] == 1

    # Customer pays the branch address -> invoice settles -> dev fee credited
    # via redirect (funds went to BareBits, not the merchant).
    _pay_and_settle(c, onchain, inv["id"], row["onchain_address"])
    with sqlite3.connect(str(c.handle.db_path)) as conn:
        conn.row_factory = sqlite3.Row
        melt = conn.execute(
            "SELECT note, amount_sats, via FROM melts WHERE invoice_id = ?", (inv["id"],)
        ).fetchone()
    assert melt is not None and melt["note"] == "DEV_FEE" and melt["via"] == "redirect", dict(melt or {})
    assert melt["amount_sats"] == INVOICE_SATS
    # The unpaid sibling is untouched by that payment (distinct address).
    assert c.greenfield.get_invoice(c.store_id, inv2["id"])["status"] in ("New", "Processing")


def test_endpoint_down_at_install_falls_back_then_recovers(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle,
    onchain: OnchainContext, bitcoind: BitcoindHandle,
) -> None:
    xpub_endpoint.stop()
    c = _boot(request, xpub_endpoint, mint, backup_mint)

    # Every cron tick tries and fails; nothing is stored; the error is visible.
    task = _fee_branch_task(c)
    assert task.startswith("error: ") and "unreachable" in task, task
    assert _config(c.handle, "dev_fee_onchain_branch") is None
    first_attempt = _config(c.handle, "dev_fee_branch_last_attempt_at")
    assert first_attempt > 0
    fb = _stats(c)["dev_fee_branch"]
    assert fb["branch"] is None and fb["last_error"] and "unreachable" in fb["last_error"], fb
    time.sleep(1.1)
    task2 = _fee_branch_task(c)
    assert task2.startswith("error: "), task2
    assert _config(c.handle, "dev_fee_branch_last_attempt_at") > first_attempt, "cron retried on the next tick"

    # Meanwhile the dev fee takes no on-chain rail: the invoice's address is
    # the MERCHANT's (<merchant tpub>/0/0), nothing is derived on the shared
    # fee chain, and no fee-chain index is consumed.
    _wire_onchain(c, onchain)
    _seed_fee_revenue(c.handle, c.store_id, SEED_REVENUE_SATS)
    inv_merchant, row_merchant = _create_merchant_invoice(c)
    assert row_merchant["onchain_address"] == derive_address_in_bitcoind(bitcoind, onchain.tpub, "P2WPKH", 0)
    assert row_merchant["onchain_address"] != _fee_address(bitcoind, branch=None, index=0)

    # Endpoint back: the next cron tick acquires the branch; from then on new
    # redirects derive under it, starting at index 0 of the branch.
    xpub_endpoint.start()
    task3 = _fee_branch_task(c)
    assert task3 == "acquired (branch 1)", task3
    assert _config(c.handle, "dev_fee_onchain_branch") == 1
    assert _config(c.handle, "dev_fee_branch_last_error") is None
    inv_branch, row_branch = _create_fee_invoice(c)
    assert row_branch["onchain_address"] == _fee_address(bitcoind, branch=1, index=0)
    assert row_branch["onchain_address_index"] == 0

    # The merchant invoice created before the switch settles normally to the
    # merchant (no fee credit), and the branch invoice settles to the fee.
    _pay_and_settle(c, onchain, inv_merchant["id"], row_merchant["onchain_address"])
    with sqlite3.connect(str(c.handle.db_path)) as conn:
        assert conn.execute("SELECT COUNT(*) FROM melts WHERE invoice_id = ?", (inv_merchant["id"],)).fetchone()[0] == 0
    assert c.greenfield.get_invoice(c.store_id, inv_branch["id"])["status"] in ("New", "Processing")
    _pay_and_settle(c, onchain, inv_branch["id"], row_branch["onchain_address"])
    with sqlite3.connect(str(c.handle.db_path)) as conn:
        assert conn.execute("SELECT note FROM melts WHERE invoice_id = ?", (inv_branch["id"],)).fetchone()[0] == "DEV_FEE"


def test_endpoint_errors_are_reported_and_retried_each_tick(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle,
) -> None:
    xpub_endpoint.set_mode("http500")
    c = _boot(request, xpub_endpoint, mint, backup_mint)

    task = _fee_branch_task(c)
    assert task == "error: endpoint returned HTTP 500", task
    t1 = _config(c.handle, "dev_fee_branch_last_attempt_at")
    assert _config(c.handle, "dev_fee_onchain_branch") is None

    xpub_endpoint.set_mode("garbage")
    time.sleep(1.1)
    task = _fee_branch_task(c)
    assert task == "error: endpoint returned an unparseable body", task
    assert _config(c.handle, "dev_fee_branch_last_attempt_at") > t1

    xpub_endpoint.set_mode("badbranch")
    assert _fee_branch_task(c) == "error: endpoint returned an invalid branch: 0"
    xpub_endpoint.set_mode("ratelimit")
    task = _fee_branch_task(c)
    assert task.startswith("error: endpoint rate-limited"), task
    assert _config(c.handle, "dev_fee_onchain_branch") is None

    # Healthy again: nothing was handed out during the failures, and the very
    # next tick acquires branch 1 with the error cleared.
    xpub_endpoint.set_mode("normal")
    assert xpub_endpoint.highest() == 0, "no branch was ever handed out"
    assert _fee_branch_task(c) == "acquired (branch 1)"
    assert _config(c.handle, "dev_fee_branch_last_error") is None
    assert _stats(c)["dev_fee_branch"]["branch"] == 1


def test_no_cron_inline_acquisition_and_throttle(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle,
    onchain: OnchainContext, bitcoind: BitcoindHandle,
) -> None:
    """An install whose cron never runs still gets its branch: invoice
    creation tries the endpoint inline when a redirect needs it. The inline
    path is throttled (one attempt per 60s) so a dead endpoint can't tax
    every checkout — within the throttle window the invoice is simply not
    fee-routed on-chain."""
    xpub_endpoint.stop()
    c = _boot(request, xpub_endpoint, mint, backup_mint)
    # The boot's own cron tick already failed once (endpoint down) and stamped
    # last_attempt_at; from here on NO cron runs — only invoice creation.
    _wire_onchain(c, onchain)
    _seed_fee_revenue(c.handle, c.store_id, SEED_REVENUE_SATS)
    attempt_before = _config(c.handle, "dev_fee_branch_last_attempt_at")
    assert attempt_before, "boot cron should have attempted (and failed)"

    xpub_endpoint.start()
    # Inside the throttle window: no inline attempt, no on-chain redirect.
    _, row = _create_merchant_invoice(c)
    assert row["onchain_address"] == derive_address_in_bitcoind(bitcoind, onchain.tpub, "P2WPKH", 0)
    assert _config(c.handle, "dev_fee_branch_last_attempt_at") == attempt_before, "throttled: no new attempt"
    assert xpub_endpoint.highest() == 0

    # Age the last attempt past the throttle: the next redirect acquires the
    # branch inline and derives under it immediately.
    _set_config(c.handle, "dev_fee_branch_last_attempt_at", int(time.time()) - 120)
    _, row2 = _create_fee_invoice(c)
    assert _config(c.handle, "dev_fee_onchain_branch") == 1, "inline acquisition stored the branch"
    assert xpub_endpoint.highest() == 1
    assert row2["onchain_address"] == _fee_address(bitcoind, branch=1, index=0)

    # Dead endpoint again + aged throttle: one failed inline attempt is
    # bounded and the branch already stored is simply used.
    xpub_endpoint.stop()
    _, row3 = _create_fee_invoice(c)
    assert row3["onchain_address"] == _fee_address(bitcoind, branch=1, index=1)


def test_two_installs_get_distinct_branches_and_payments_do_not_cross(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle,
    onchain: OnchainContext, bitcoind: BitcoindHandle,
) -> None:
    """The bug this feature exists for: two installs at index 0 of the shared
    chain would share an address and both see one customer's payment. With
    branches they derive different addresses and only the paid install
    settles."""
    a = _boot(request, xpub_endpoint, mint, backup_mint)
    b = _boot(request, xpub_endpoint, mint, backup_mint)
    ta, tb = _fee_branch_task(a), _fee_branch_task(b)
    assert ta in ("acquired (branch 1)", "ok (branch 1)"), ta
    assert tb in ("acquired (branch 2)", "ok (branch 2)"), tb
    assert xpub_endpoint.highest() == 2
    assert _config(a.handle, "dev_fee_onchain_branch") == 1
    assert _config(b.handle, "dev_fee_onchain_branch") == 2

    onchain_b = make_onchain_context(bitcoind, f"cashupay-watch-{uuid.uuid4().hex[:8]}")
    _wire_onchain(a, onchain)
    _wire_onchain(b, onchain_b)
    _seed_fee_revenue(a.handle, a.store_id, SEED_REVENUE_SATS)
    _seed_fee_revenue(b.handle, b.store_id, SEED_REVENUE_SATS)
    inv_a, row_a = _create_fee_invoice(a)
    inv_b, row_b = _create_fee_invoice(b)
    assert row_a["onchain_address_index"] == row_b["onchain_address_index"] == 0, "both at index 0 of their branch"
    assert row_a["onchain_address"] == _fee_address(bitcoind, branch=1, index=0)
    assert row_b["onchain_address"] == _fee_address(bitcoind, branch=2, index=0)
    assert row_a["onchain_address"] != row_b["onchain_address"]

    # Install A's customer pays. A settles; B — same index, different branch —
    # must not.
    onchain.fund_address(row_a["onchain_address"], INVOICE_SATS)
    assert _poll_until(a, inv_a["id"], "Settled")["status"] == "Settled"
    onchain.confirm(2)
    for _ in range(3):
        b.handle.trigger_cron()
        time.sleep(0.3)
    assert b.greenfield.get_invoice(b.store_id, inv_b["id"])["status"] in ("New", "Processing"), (
        "install B must not see install A's payment"
    )

    # The sweep-side view: the Core import list for 'highest = 2' covers
    # exactly the chains the two installs derived on (plain + branches 1, 2).
    import subprocess
    from fixtures import binaries
    php = binaries.ensure(binaries.PHP)["php"]
    out = subprocess.run(
        [str(php), "scripts/fee-branch-descriptors.php", "--fetch", "--network", "regtest", "--range", "5"],
        cwd=str(SESSION_TMP.parent.parent),
        env={"PATH": "/usr/bin:/bin", "CASHUPAY_DEV_FEE_BRANCH_ENDPOINT": xpub_endpoint.url,
             "CASHUPAY_DEV_FEE_ONCHAIN_XPUB": DEV_FEE_TEST_TPUB, "CASHUPAY_ALLOW_PRIVATE_ENDPOINTS": "1"},
        capture_output=True, text=True, timeout=60,
    )
    assert out.returncode == 0, out.stderr
    descs = [d["desc"] for d in json.loads(out.stdout)]
    assert len(descs) == 3, descs
    # bitcoind accepts every descriptor (checksum included) and derives the
    # very addresses the installs used (descs[0] is the plain chain kept for
    # installs that predate branches).
    assert bitcoind.rpc("deriveaddresses", descs[1], [0, 0])[0] == row_a["onchain_address"]
    assert bitcoind.rpc("deriveaddresses", descs[2], [0, 0])[0] == row_b["onchain_address"]
    assert bitcoind.rpc("deriveaddresses", descs[0], [0, 0])[0] == _fee_address(bitcoind, branch=None, index=0)


def test_admin_fees_card_shows_branch(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle, browser,
) -> None:
    c = _boot(request, xpub_endpoint, mint, backup_mint)
    assert _fee_branch_task(c) in ("acquired (branch 1)", "ok (branch 1)")

    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    try:
        ctx.request.post(
            f"{c.handle.url}/admin",
            form={"action": "login", "username": "admin", "password": c.admin_password},
        )
        page = ctx.new_page()
        page.goto(f"{c.handle.url}/admin/stats", wait_until="networkidle")
        page.wait_for_function(
            "document.getElementById('stats-fee-branch') && document.getElementById('stats-fee-branch').textContent !== '—'",
            timeout=15000,
        )
        assert page.text_content("#stats-fee-branch").strip() == "#1"
        assert "Dev fee address branch" in page.text_content("#stats-fee-branch-row")
    finally:
        ctx.close()


def test_pending_state_shown_until_acquired(
    request, xpub_endpoint: XpubEndpoint, mint: MintHandle, backup_mint: MintHandle, browser,
) -> None:
    xpub_endpoint.set_mode("http500")
    c = _boot(request, xpub_endpoint, mint, backup_mint)
    assert _fee_branch_task(c).startswith("error: ")

    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    try:
        ctx.request.post(
            f"{c.handle.url}/admin",
            form={"action": "login", "username": "admin", "password": c.admin_password},
        )
        page = ctx.new_page()
        page.goto(f"{c.handle.url}/admin/stats", wait_until="networkidle")
        page.wait_for_function(
            "document.getElementById('stats-fee-branch') && document.getElementById('stats-fee-branch').textContent !== '—'",
            timeout=15000,
        )
        assert page.text_content("#stats-fee-branch").strip() == "pending (retrying)"
        assert page.get_attribute("#stats-fee-branch", "data-state") == "pending"
        assert "HTTP 500" in (page.get_attribute("#stats-fee-branch-row", "title") or "")
    finally:
        ctx.close()
