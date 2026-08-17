"""End-to-end: auto-cashout (threshold-melt) DRAIN to an NWC connection.

The lnaddress drain is covered by test_auto_melt.py and the noffer drain by
test_auto_melt_noffer.py; this is the NIP-47 counterpart with real
settlement. The fake NWC wallet is backed by the session's ``lnd_payer``
node, which the store's mint (backed by ``lnd_mint``) can pay over the
session's dual channels:

    fund the store's mint balance (mint-rail invoice paid by lnd_payer)
      -> configure the store's auto-cashout destination = the NWC connection
      -> cron LightningAddress::checkAutoMelt()
           -> meltToDestination(nwc): NwcClient.makeInvoice over the relay
              -> the wallet answers with a real lnbcrt bolt11 from lnd_payer
           -> meltToBolt11: the mint melts ecash to pay that bolt11
      -> the mint balance drains to ~zero and the wallet's node receives it.

Draining the balance is itself the proof of settlement: the cashu mint only
returns paid=true and marks the proofs spent once the Lightning payment
actually settles.
"""
from __future__ import annotations

import time
from typing import Iterator

import pytest

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.lnd import LndHandle
from fixtures.nwc_wallet import NwcStack, start_nwc_stack, stop_nwc_stack

SETTLE_AMOUNT_SAT = 5_000


@pytest.fixture
def nwc_drain_stack(lnd_payer: LndHandle, channels: None) -> Iterator[NwcStack]:
    """Relay + fake wallet minting from lnd_payer — the node the mint's
    lnd_mint can reach one hop over the session's dual channels."""
    workdir = SESSION_TMP / f"nwc-drain-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_payer, info_methods=["make_invoice", "lookup_invoice", "get_info"]
    )
    yield stack
    stop_nwc_stack(stack)


def _settle_mint_invoice(configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> None:
    """Fund the store's mint balance. Runs BEFORE any chain destination is
    configured, so the invoice rides the mint rail."""
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(amount_sat), currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if configured.greenfield.get_invoice(configured.store_id, invoice["id"])["status"] == "Settled":
            return
        time.sleep(0.3)
    raise AssertionError("source invoice did not settle")


def _save_nwc_destination(admin: AdminClient, store_id: str, uri: str) -> dict:
    data = [
        ("action", "save_auto_melt"),
        ("store_id", store_id),
        ("enabled", "1"),
        ("threshold", "100"),
        ("mode_override", "0"),
        ("nwc[]", uri),
    ]
    r = admin.s.post(
        admin._admin_url, data=data,
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body
    assert (body.get("addresses") or [])[0]["type"] == "nwc", body
    return body


def _store_balance(configured: ConfiguredPayserver) -> int:
    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    return int(r.json()["balance"])


def test_auto_melt_drains_balance_to_nwc(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    nwc_drain_stack: NwcStack,
) -> None:
    # 1. Fund the store's mint balance (mint rail — no chain destination yet).
    _settle_mint_invoice(configured, lnd_payer, SETTLE_AMOUNT_SAT)
    assert _store_balance(configured) >= SETTLE_AMOUNT_SAT

    # 2. Point auto-cashout at the NWC connection (probe mints a 1-sat test
    #    invoice through the wallet; never paid).
    _save_nwc_destination(
        configured.admin, configured.store_id, nwc_drain_stack.wallet.connection_uri()
    )

    # 3. Trigger cron: checkAutoMelt() asks the wallet for a bolt11 over the
    #    relay and the mint melts ecash to pay it.
    r = configured.handle.trigger_cron()
    assert r.status_code == 200, r.text
    body_text = r.text.strip()
    try:
        cron_body = r.json()
    except Exception:
        import json as _json

        idx = body_text.find("{")
        cron_body = _json.loads(body_text[idx:]) if idx >= 0 else {}
    auto_melt_result = cron_body.get("tasks", {}).get("auto_melt")
    assert auto_melt_result and auto_melt_result != "skipped", (
        f"auto_melt task didn't run; body={body_text[:600]!r}"
    )

    # 4. The mint balance drained — proof the NWC melt actually settled.
    remaining = _store_balance(configured)
    assert remaining < 500, f"store balance not drained via NWC: {remaining}"
