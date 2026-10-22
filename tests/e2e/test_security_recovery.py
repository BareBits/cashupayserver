"""MC-3 — restore before readiness, against the real nutshell mint + lnd.

A seed that was already USED at a mint must not issue new blinded secrets there
until its existing outputs were scanned (NUT-09): counters would restart at 0,
the mint would refuse (or double-sign) the already-signed outputs, and the
payment would be stuck in Processing forever.

  (a) store 1 (the wizard store) takes a payment, so the mint has signed
      outputs for its seed. The store is then lost and re-created from its
      written-down phrase (admin create_store with the pasted seed, on a wallet
      database that no longer knows the seed): the new store shows "Preparing
      payments with your mint", an invoice attempt reports the setup message
      (and creates nothing), the cron drives the scan to completion, after
      which an invoice works, pays and settles — no "outputs already signed" —
      and the balance shows the ecash the old store had received.
  (b) a brand-new store from the add_store wizard (generated seed) takes an
      invoice immediately, with no cron run in between.

`configured` (function-scoped, fresh server): (a) wipes the wallet namespace
to simulate the lost database, which is not share-safe.
"""
from __future__ import annotations

import hashlib
import time

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import GreenfieldClient
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.setup_helpers import run_add_store_wizard


def _admin_post(cfg: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    admin = cfg.admin
    return admin.s.post(
        f"{cfg.handle.url}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=120,
    )


def _dashboard(cfg: ConfiguredPayserver, store_id: str) -> dict:
    r = cfg.admin.s.get(f"{cfg.handle.url}/admin?api=dashboard&store_id={store_id}", timeout=15)
    r.raise_for_status()
    return r.json()


def _greenfield_for(cfg: ConfiguredPayserver, store_id: str) -> GreenfieldClient:
    key = cfg.admin.create_api_key(store_id, label=f"e2e-{store_id}")
    token = key.get("key") or key.get("apiKey") or key.get("token")
    assert token, key
    return GreenfieldClient(cfg.handle.url, token)


def _raw_create_invoice(gf: GreenfieldClient, store_id: str, amount: int) -> requests.Response:
    return gf.s.post(
        f"{gf.base_url}/api/v1/stores/{store_id}/invoices",
        json={"amount": str(amount), "currency": "sat"},
        timeout=60,
    )


def _pay_and_settle(gf: GreenfieldClient, store_id: str, lnd_payer: LndHandle, amount: int) -> str:
    invoice = gf.create_invoice(store_id, amount=str(amount), currency="sat")
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if gf.get_invoice(store_id, invoice["id"])["status"] == "Settled":
            return invoice["id"]
        time.sleep(0.3)
    raise AssertionError(f"invoice {invoice['id']} did not settle")


def _wallet_id(mint_url: str, unit: str = "sat") -> str:
    return hashlib.sha256(f"{mint_url.rstrip('/')}:{unit}".encode()).hexdigest()[:16]


def test_imported_used_seed_is_scanned_before_the_store_takes_payments(
    configured: ConfiguredPayserver,
    mint: MintHandle,
    lnd_payer: LndHandle,
) -> None:
    cfg = configured
    first_amount = 1200

    # Store 1 receives a payment: the mint has now signed outputs of its seed.
    _pay_and_settle(cfg.greenfield, cfg.store_id, lnd_payer, first_amount)
    with cfg.handle.db() as db:
        seed = db.execute("SELECT seed_phrase FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()[0]
    assert len(seed.split()) == 12

    # Disaster: the store and its wallet database are lost; only the phrase
    # survives. (Deleting the store is also what create_store's duplicate-seed
    # check needs.) The namespace rows are wiped so nothing but the seed is
    # left — exactly a fresh install restored from the written-down phrase.
    r = _admin_post(cfg, "delete_store", store_id=cfg.store_id)
    assert r.status_code == 200, r.text
    wid = _wallet_id(mint.url)
    with cfg.handle.db() as db:
        for table in ("cashu_proofs", "cashu_counters", "cashu_pending_operations",
                      "cashu_wallet_metadata", "cashu_restore_progress"):
            db.execute(f"DELETE FROM {table} WHERE wallet_id = ?", (wid,))
        db.execute("DELETE FROM wallet_recovery")

    # Store 2: created from the pasted phrase.
    r = _admin_post(cfg, "validate_seed", seed_phrase=seed)
    assert r.status_code == 200 and r.json().get("valid") is True, r.text
    r = _admin_post(
        cfg, "create_store",
        name="Restored shop", mint_url=mint.url, mint_unit="sat", seed_phrase=seed,
    )
    assert r.status_code == 200, r.text
    created = r.json()
    store2 = created["id"]
    assert created["isConfigured"] is True
    assert created["walletRecovery"] == "pending", created
    assert "Preparing payments" in (created.get("walletRecoveryMessage") or ""), created

    # The dashboard says so, for the store list and the balance card.
    dash = _dashboard(cfg, store2)
    assert dash["walletRecovery"]["pending"] is True and dash["walletRecovery"]["primaryPending"] is True, dash["walletRecovery"]
    assert dash["walletRecovery"]["message"] == "Preparing payments with your mint"
    me = [s for s in dash["stores"] if s["id"] == store2]
    assert me and me[0]["walletRecovering"] is True, me

    # An invoice attempt reports the setup message and creates nothing.
    gf2 = _greenfield_for(cfg, store2)
    r = _raw_create_invoice(gf2, store2, 300)
    assert r.status_code >= 400, r.text
    assert "being set up" in r.text, r.text
    assert gf2.list_invoices(store2) == [], "no half-created invoice may be left behind"
    with cfg.handle.db() as db:
        row = db.execute("SELECT status FROM wallet_recovery WHERE store_id = ?", (store2,)).fetchone()
    assert row is not None and row["status"] == "pending"

    # The cron scans the mint for the seed's outputs and readies the account.
    cfg.handle.drive_cron_until(
        lambda: _dashboard(cfg, store2)["walletRecovery"]["pending"] is False,
        timeout_s=180, interval_s=2.0, label="wallet recovery complete",
    )
    dash = _dashboard(cfg, store2)
    assert dash["walletRecovery"]["accounts"][0]["status"] == "ready", dash["walletRecovery"]
    me = [s for s in dash["stores"] if s["id"] == store2]
    assert me[0]["walletRecovering"] is False
    # The old store's ecash was found by the scan: it is store 2's balance now.
    assert dash["balance"] == first_amount, dash["balance"]
    with cfg.handle.db() as db:
        counters = db.execute(
            "SELECT counter FROM cashu_counters WHERE wallet_id = ?", (wid,)
        ).fetchall()
    assert counters and max(c["counter"] for c in counters) > 0, "counters advanced past the restored outputs"

    # Now an invoice works, is paid and settles: the mint signs fresh outputs
    # above the restored range (no "outputs already signed" refusal).
    _pay_and_settle(gf2, store2, lnd_payer, 700)
    assert _dashboard(cfg, store2)["balance"] == first_amount + 700


def test_new_store_from_the_wizard_takes_an_invoice_immediately(
    configured: ConfiguredPayserver,
    mint: MintHandle,
    backup_mint: MintHandle,
    lnd_payer: LndHandle,
) -> None:
    cfg = configured
    run_add_store_wizard(
        cfg.handle.url,
        admin_password=DEFAULT_ADMIN_PASSWORD,
        store_name="Brand new shop",
        mint_url=mint.url,
        backup_mint_url=backup_mint.url,
    )
    stores = [s for s in cfg.admin.list_stores() if s["name"] == "Brand new shop"]
    assert len(stores) == 1, stores
    store_id = stores[0]["id"]
    assert stores[0]["walletRecovering"] is False

    dash = _dashboard(cfg, store_id)
    assert dash["walletRecovery"]["pending"] is False, dash["walletRecovery"]
    assert all(a["status"] == "ready" for a in dash["walletRecovery"]["accounts"]), dash["walletRecovery"]

    # No cron run in between: the generated seed is ready at once.
    gf = _greenfield_for(cfg, store_id)
    _pay_and_settle(gf, store_id, lnd_payer, 500)
    assert _dashboard(cfg, store_id)["balance"] == 500
