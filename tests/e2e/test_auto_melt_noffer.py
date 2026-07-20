"""End-to-end: auto-cashout (threshold-melt) DRAIN to a CLINK noffer.

The lnaddress drain is covered by test_auto_melt.py; this is its noffer
counterpart with *real settlement*. A live ``electrum_clink`` plugin acts as
the noffer service (the receiver); the store's cashu mint pays the
plugin-issued bolt11 via a direct ``lnd_mint -> merchant`` channel (one hop, no
routing — the drain analogue of the receive test's ``customer -> merchant``
channel):

    fund the store's mint balance (lnd_payer -> mint)
      -> configure the store's auto-cashout destination = the merchant noffer
      -> cron LightningAddress::checkAutoMelt()
           -> meltToDestination(noffer): ClinkClient.requestInvoice over the
              relay -> the plugin answers with a real lnbcrt bolt11
           -> meltToBolt11: the mint melts ecash to pay that bolt11
      -> the mint balance drains to ~zero and the merchant receives the payment.

Draining the balance is itself the proof of settlement: the cashu mint only
returns paid=true and marks the proofs spent once the Lightning payment to the
merchant actually settles.

Live counterpart to the config-only tests/e2e/test_clink_noffer.py and the
mock-relay PHP suite tests/php/test_clink_roundtrip.php; complements the
gating-level unit test tests/php/test_auto_melt_ln_primary.php.
"""
from __future__ import annotations

import sqlite3
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import pytest

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.bitcoind import BitcoindHandle
from fixtures.clink_relay import ClinkRelayHandle, start_clink_relay, stop_clink_relay
from fixtures.fulcrum import start_fulcrum, stop_fulcrum
from fixtures.lnd import LndHandle, fund_node
from fixtures import electrum as E

SETTLE_AMOUNT_SAT = 5_000
MERCHANT_CHANNEL_CAPACITY_SAT = 1_000_000
MERCHANT_CHANNEL_PUSH_SAT = 500_000  # merchant inbound, far above the drain amount


@dataclass
class NofferDrainStack:
    relay: ClinkRelayHandle
    merchant: E.ElectrumHandle  # runs the plugin = the noffer service (receiver)
    noffer: str                 # a fresh spontaneous offer from the merchant


def _open_lnd_to_electrum_channel(
    lnd: LndHandle, merchant: E.ElectrumHandle, bitcoind: BitcoindHandle
) -> None:
    """Open a pushed channel FROM the mint's LND node TO the merchant Electrum
    node so the merchant has inbound liquidity the mint can pay over directly."""
    uri = E.electrum_node_uri(merchant)  # <pubkey>@127.0.0.1:<listen_port>
    merchant_pubkey = uri.split("@", 1)[0]

    # Top the funder up so it can cover the funding tx, then wait until it has
    # fully synced to tip (LND requires that before it will open a channel).
    fund_node(bitcoind, lnd, 1.0)
    target_height = bitcoind.block_count()
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        info = lnd.get_info()
        if (
            info.get("synced_to_chain")
            and int(info.get("block_height", 0)) >= target_height
            and lnd.wallet_balance_sat() >= MERCHANT_CHANNEL_CAPACITY_SAT + 50_000
        ):
            break
        time.sleep(0.5)

    # Dial the merchant and wait until LND actually registers it as an online
    # peer before opening — a fresh connect to the Electrum node takes a moment
    # to complete its handshake, and open_channel 500s on an offline peer.
    host = f"127.0.0.1:{merchant.lightning_listen_port}"
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            lnd.connect_peer(merchant_pubkey, host)
        except Exception:
            pass
        if any(p.get("pub_key") == merchant_pubkey for p in lnd.list_peers()):
            break
        time.sleep(1)
    else:
        raise TimeoutError(f"mint LND never registered merchant peer {merchant_pubkey[:16]}")

    lnd.open_channel(
        merchant_pubkey,
        MERCHANT_CHANNEL_CAPACITY_SAT,
        MERCHANT_CHANNEL_PUSH_SAT,
    )
    bitcoind.mine(6)


@pytest.fixture
def noffer_drain_stack(
    session_workdir: Path,
    bitcoind: BitcoindHandle,
    installed_binaries: dict,
    lnd_mint: LndHandle,
) -> Iterator[NofferDrainStack]:
    workdir = SESSION_TMP / f"noffer-drain-stack-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)

    bitcoind.ensure_wallet()
    if bitcoind.block_count() < 101:
        bitcoind.mine(101 - bitcoind.block_count())

    fulcrum = relay = merchant = None
    try:
        fulcrum = start_fulcrum(workdir, bitcoind)
        relay = start_clink_relay(workdir)

        # The merchant wallet runs the CLINK plugin pointed at the in-rig relay.
        merchant = E.start_electrum(
            workdir, fulcrum,
            instance="electrum-clink-noffer-drain", wallet_name="clink_drain_merchant",
            with_clink=True, clink_relay_url=relay.ws_url,
        )
        E.electrum_wait_synced(merchant, bitcoind)

        # Give the merchant inbound liquidity, reachable one hop from the mint.
        _open_lnd_to_electrum_channel(lnd_mint, merchant, bitcoind)

        # Wait until the plugin reports it can receive the drain amount, then
        # mint a fresh spontaneous offer.
        deadline = time.monotonic() + 120
        available = 0
        while time.monotonic() < deadline:
            try:
                available = int(E.electrum_clink(merchant, "clink_status").get("available_sat", 0) or 0)
            except Exception:
                available = 0
            if available > SETTLE_AMOUNT_SAT:
                break
            bitcoind.mine(1)
            time.sleep(2)
        assert available > SETTLE_AMOUNT_SAT, (
            f"merchant never gained enough inbound liquidity (available_sat={available})"
        )
        noffer = E.electrum_clink(merchant, "add_offer", "--label", "drain-e2e")["noffer"]
        assert noffer.startswith("noffer1"), noffer

        yield NofferDrainStack(relay=relay, merchant=merchant, noffer=noffer)
    finally:
        for h, stop in ((merchant, E.stop_electrum),
                        (relay, stop_clink_relay), (fulcrum, stop_fulcrum)):
            if h is not None:
                try:
                    stop(h)
                except Exception:
                    pass


def _settle_invoice(configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> None:
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


def _save_noffer_destination(admin: AdminClient, store_id: str, noffer: str) -> dict:
    """Configure a single noffer auto-cashout destination (enabled, low
    threshold) via the addresses[] chain, mirroring the dashboard."""
    data = [
        ("action", "save_auto_melt"),
        ("store_id", store_id),
        ("enabled", "1"),
        ("threshold", "100"),
        ("mode_override", "0"),
        ("addresses[]", noffer),
    ]
    r = admin.s.post(
        admin._admin_url, data=data,
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=30,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body
    assert (body.get("addresses") or [])[0]["type"] == "noffer", body
    return body


def _store_balance(configured: ConfiguredPayserver) -> int:
    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    return int(r.json()["balance"])


def test_auto_melt_drains_balance_to_noffer(
    configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    noffer_drain_stack: NofferDrainStack,
) -> None:
    # 1. Fund the store's mint balance.
    _settle_invoice(configured, lnd_payer, SETTLE_AMOUNT_SAT)
    assert _store_balance(configured) >= SETTLE_AMOUNT_SAT

    # 2. Point auto-cashout at the merchant's noffer.
    _save_noffer_destination(configured.admin, configured.store_id, noffer_drain_stack.noffer)

    # 3. Trigger cron: checkAutoMelt() dials the noffer over the relay, the
    #    plugin issues a real bolt11, and the mint melts ecash to pay it.
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

    # 4. The mint balance drained — proof the noffer melt actually settled.
    remaining = _store_balance(configured)
    assert remaining < 500, f"store balance not drained via noffer: {remaining}"
