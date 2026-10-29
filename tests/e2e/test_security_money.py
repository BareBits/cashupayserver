"""Money-safety port (security/upstream-audit-port): withdrawals, late
payments and mint changes against the real nutshell mint + lnd payer (plus a
guard that the removed token-export action stays gone).

Every test funds its own per-test store on the shared server by paying a
mint-rail invoice through lnd, then exercises the admin money endpoints
directly over HTTP (the dashboard JS calls exactly these actions).
Balance assertions are deltas around this test's own activity — the wallet
namespace is keyed by mint:unit, so the shared server's stores pool proofs.
"""
from __future__ import annotations

import fcntl
import hashlib
import time
from pathlib import Path

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.webhook_sink import WebhookSink


# ---------------------------------------------------------------- helpers


def _admin_post(cfg: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    """Raw admin POST so non-2xx answers can be inspected (AdminClient raises)."""
    admin = cfg.admin
    data = {"action": action, **fields}
    return admin.s.post(
        f"{cfg.handle.url}/admin",
        data=data,
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=120,
    )


def _dashboard(cfg: ConfiguredPayserver) -> dict:
    r = cfg.admin.s.get(
        f"{cfg.handle.url}/admin?api=dashboard&store_id={cfg.store_id}", timeout=15
    )
    r.raise_for_status()
    return r.json()


def _balance(cfg: ConfiguredPayserver) -> int:
    return int(_dashboard(cfg)["balance"])


def _invoice_status(cfg: ConfiguredPayserver, invoice_id: str) -> str:
    return cfg.greenfield.get_invoice(cfg.store_id, invoice_id)["status"]


def _fund_store(cfg: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> str:
    """Create + pay + wait for settle on the mint rail. Returns the invoice id."""
    invoice = cfg.greenfield.create_invoice(cfg.store_id, amount=str(amount_sat), currency="sat")
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if _invoice_status(cfg, invoice["id"]) == "Settled":
            return invoice["id"]
        time.sleep(0.3)
    raise AssertionError(f"invoice {invoice['id']} did not settle")


def _wallet_id(mint_url: str, unit: str = "sat") -> str:
    """The library's namespace derivation: sha256(mint:unit)[:16]."""
    return hashlib.sha256(f"{mint_url.rstrip('/')}:{unit}".encode()).hexdigest()[:16]


# ---------------------------------------------------------------- exports


def test_export_token_action_is_gone(shared_configured: ConfiguredPayserver) -> None:
    """The admin UI no longer generates Cashu tokens (the dashboard "Export
    token" button, its modal form and the export_token action were removed
    2026-10). The action must be unknown — not merely unlinked — so nothing
    can hand out bearer ecash through it. The ledger endpoints that service
    tokens handed out earlier (Show / Check / Take back) stay."""
    cfg = shared_configured
    r = _admin_post(cfg, "export_token", store_id=cfg.store_id, amount="100")
    assert r.status_code == 400, r.text
    assert r.json() == {"error": "Unknown action"}
    # The dashboard payload no longer advertises an exportable amount, but
    # still carries the ledger for existing tokens.
    dash = _dashboard(cfg)
    assert "exportAvailable" not in dash
    assert "recentExports" in dash and "exportedBalance" in dash


def test_withdrawal_is_refused_while_the_funds_lock_is_held(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    cfg = shared_configured
    _fund_store(cfg, lnd_payer, 1000)
    lock_path = cfg.handle.data_dir / f".funds_{cfg.store_id}.lock"
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with open(lock_path, "a+") as fp:
        fcntl.flock(fp.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            inv = lnd_payer.add_invoice(100, "lock-test")
            r = _admin_post(
                cfg, "manual_melt", store_id=cfg.store_id,
                address=inv["payment_request"], amount="100", amount_is_sats="1",
            )
            assert r.status_code == 409, r.text
            assert "in progress" in r.json()["error"]
        finally:
            fcntl.flock(fp.fileno(), fcntl.LOCK_UN)
    # Released: the withdrawal is accepted again.
    inv = lnd_payer.add_invoice(100, "lock-test-2")
    r = _admin_post(
        cfg, "manual_melt", store_id=cfg.store_id,
        address=inv["payment_request"], amount="100", amount_is_sats="1",
    )
    assert r.status_code == 200, r.text


# ---------------------------------------------------------------- late payment


def test_invoice_paid_after_it_was_invalidated_is_settled_by_cron(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    webhook_sink: WebhookSink,
) -> None:
    cfg = shared_configured
    cfg.greenfield.create_webhook(cfg.store_id, webhook_sink.endpoint("late"), secret="x")
    invoice = cfg.greenfield.create_invoice(cfg.store_id, amount="700", currency="sat")
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]

    # Merchant cancels the order; the mint quote stays payable.
    assert cfg.greenfield.mark_invoice_status(cfg.store_id, invoice["id"], "Invalid")["status"] == "Invalid"
    before = _balance(cfg)

    # Customer pays anyway.
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)

    cfg.handle.drive_cron_until(
        lambda: _invoice_status(cfg, invoice["id"]) == "Settled",
        timeout_s=60, interval_s=1.0, label="late payment recovered",
    )
    got = cfg.greenfield.get_invoice(cfg.store_id, invoice["id"])
    assert got["status"] == "Settled"
    assert _balance(cfg) - before == 700, "the late payment was minted into the balance"

    captured = webhook_sink.wait_for("/hook/late", count=1, timeout_s=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        types = [r.json().get("type") for r in webhook_sink.by_path("/hook/late")]
        if "InvoiceSettled" in types:
            break
        cfg.handle.trigger_cron()
        time.sleep(1)
    types = [r.json().get("type") for r in webhook_sink.by_path("/hook/late")]
    assert "InvoiceSettled" in types, types
    assert types.index("InvoiceInvalid") < types.index("InvoiceSettled")


def test_invoice_paid_after_expiry_is_settled_not_expired(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
) -> None:
    cfg = shared_configured
    invoice = cfg.greenfield.create_invoice(cfg.store_id, amount="600", currency="sat")
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    # Locally expired a minute ago; the mint quote is still payable.
    with cfg.handle.db() as db:
        db.execute("UPDATE invoices SET expiration_time = ? WHERE id = ?", (int(time.time()) - 60, invoice["id"]))
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    # Within the confirm window the sweep must ask the mint rather than expire
    # on the clock; the paid quote is minted and settled.
    cfg.handle.drive_cron_until(
        lambda: _invoice_status(cfg, invoice["id"]) == "Settled",
        timeout_s=60, interval_s=1.0, label="paid-at-expiry settled",
    )
    assert _invoice_status(cfg, invoice["id"]) == "Settled"


# ---------------------------------------------------------------- mint change / backups


def test_changing_the_mint_of_a_funded_store_is_refused(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    backup_mint: MintHandle,
) -> None:
    cfg = shared_configured
    _fund_store(cfg, lnd_payer, 800)
    before = _balance(cfg)
    with cfg.handle.db() as db:
        row = db.execute("SELECT mint_url, seed_phrase FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()
    old_mint, seed = row["mint_url"], row["seed_phrase"]

    # The wizard registered backup_mint as a backup, so the hard block does
    # not apply; the stranding confirmation still does.
    r = _admin_post(cfg, "update_store", store_id=cfg.store_id, mint_url=backup_mint.url)
    assert r.status_code == 409, r.text
    body = r.json()
    assert body["error"] == "mint_change_strands_funds", body
    assert body["balance"] >= before

    # A seed change is never accepted, whatever else is posted.
    r = _admin_post(
        cfg, "update_store", store_id=cfg.store_id, name="still mine",
        seed_phrase="abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about",
    )
    assert r.status_code == 200, r.text
    with cfg.handle.db() as db:
        row = db.execute("SELECT mint_url, seed_phrase, name FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()
    assert row["mint_url"] == old_mint and row["seed_phrase"] == seed
    assert row["name"] == "still mine"
    assert _balance(cfg) == before


def test_removing_a_backup_mint_that_holds_proofs_is_refused(
    shared_configured: ConfiguredPayserver,
    backup_mint: MintHandle,
) -> None:
    cfg = shared_configured
    with cfg.handle.db() as db:
        bm = db.execute(
            "SELECT id FROM store_mints WHERE store_id = ? AND mint_url = ?",
            (cfg.store_id, backup_mint.url.rstrip("/")),
        ).fetchone()
        assert bm, "wizard should have registered the backup mint"
        # A proof settled by failover sits in the backup's namespace.
        db.execute(
            "INSERT INTO cashu_proofs (wallet_id, keyset_id, amount, secret, C, state, created_at) "
            "VALUES (?, ?, ?, ?, ?, 'UNSPENT', ?)",
            (_wallet_id(backup_mint.url), "00ad268c4d1f5826", 21, f"e2e-backup-{cfg.store_id}", "02" + "ab" * 32, int(time.time())),
        )
    try:
        r = _admin_post(cfg, "remove_backup_mint", id=str(bm["id"]))
        assert r.status_code == 409, r.text
        assert r.json()["error"] == "backup_mint_not_empty"
        with cfg.handle.db() as db:
            assert db.execute("SELECT id FROM store_mints WHERE id = ?", (bm["id"],)).fetchone()
        # The backup's holdings are visible where the mint is listed.
        r = cfg.admin.s.get(f"{cfg.handle.url}/admin?api=get_backup_mints&store_id={cfg.store_id}", timeout=15)
        assert isinstance(r.json(), list), r.text
        rows = {m["id"]: m for m in r.json()}
        assert rows[bm["id"]]["balance"] >= 21
    finally:
        with cfg.handle.db() as db:
            db.execute("DELETE FROM cashu_proofs WHERE secret = ?", (f"e2e-backup-{cfg.store_id}",))
