"""End-to-end: CLINK noffer *direct-receive* round trip against a real plugin.

Exercises the whole noffer rail with live parts — an in-rig Nostr relay, an
Electrum wallet running the real ``electrum_clink`` plugin as the noffer service
(the receiver), a second Electrum wallet as the paying customer, and the
cashupayserver acting as the payer that fetches the merchant's bolt11:

    add_offer (merchant) -> store configured with the noffer
      -> cashupayserver creates an invoice (ClinkClient.requestInvoice over the
         relay -> the plugin answers with a real lnbcrt bolt11)
      -> the customer Electrum pays that bolt11 straight to the merchant
      -> the plugin emits a kind-21001 receipt -> the invoice is Settled.

The plugin is the receiver, so it needs inbound liquidity: the customer wallet
opens a *balanced* (push_amount) direct channel to it, which gives the merchant
inbound and the customer outbound over a single hop (no LND routing).

Settlement is asserted two ways: the payment-page receipt endpoint (the reliable
path — we relay the merchant-signed receipt the browser would forward) AND the
best-effort cron poll. Both settle the same invoice via the merchant's signature.

This is the live counterpart to the config-only test_clink_noffer.py and the
mock-relay PHP suite (tests/php/test_clink_roundtrip.php).
"""
from __future__ import annotations

import asyncio
import json
import sqlite3
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.bitcoind import BitcoindHandle
from fixtures.clink_relay import ClinkRelayHandle, start_clink_relay, stop_clink_relay
from fixtures.fulcrum import FulcrumHandle, start_fulcrum, stop_fulcrum
from fixtures import electrum as E

INVOICE_AMOUNT_SAT = 1_000
CHANNEL_CAPACITY_SAT = 5_000_000
CLINK_KIND = 21001


# ---------------------------------------------------------------------------
# Live CLINK stack: relay + merchant(plugin) + customer + a balanced channel.
# ---------------------------------------------------------------------------
@dataclass
class ClinkStack:
    relay: ClinkRelayHandle
    merchant: E.ElectrumHandle  # runs the plugin = the noffer service (receiver)
    customer: E.ElectrumHandle  # cashu_clink_sender = pays the bolt11
    noffer: str                 # a fresh spontaneous offer from the merchant


@pytest.fixture
def clink_stack(
    session_workdir: Path, bitcoind: BitcoindHandle, installed_binaries: dict
) -> Iterator[ClinkStack]:
    workdir = SESSION_TMP / f"clink-stack-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)

    bitcoind.ensure_wallet()
    if bitcoind.block_count() < 101:
        bitcoind.mine(101 - bitcoind.block_count())

    fulcrum = relay = merchant = customer = None
    try:
        fulcrum = start_fulcrum(workdir, bitcoind)
        relay = start_clink_relay(workdir)

        # The merchant wallet runs the CLINK plugin pointed at the in-rig relay.
        merchant = E.start_electrum(
            workdir, fulcrum,
            instance="electrum-clink-merchant", wallet_name="clink_merchant",
            with_clink=True, clink_relay_url=relay.ws_url,
        )
        # The customer wallet (cashu_clink_sender) settles the bolt11.
        customer = E.start_electrum(
            workdir, fulcrum,
            instance="electrum-clink-sender",
            wallet_name=E.CLINK_SENDER_WALLET_NAME,
        )

        # Fund the customer on-chain, then open a balanced direct channel to the
        # merchant so the merchant has inbound liquidity to receive payments.
        E.fund_electrum_from_bitcoind(customer, bitcoind, 10_000_000, confirmations=3)
        E.electrum_wait_synced(customer, bitcoind)
        E.electrum_wait_synced(merchant, bitcoind)
        E.open_electrum_channel_to_peer(
            customer, E.electrum_node_uri(merchant), bitcoind,
            capacity_sat=CHANNEL_CAPACITY_SAT,
        )

        # Wait until the plugin reports inbound liquidity (channel active on the
        # merchant side), then mint a fresh spontaneous offer.
        deadline = time.monotonic() + 120
        available = 0
        while time.monotonic() < deadline:
            try:
                available = int(E.electrum_clink(merchant, "clink_status").get("available_sat", 0) or 0)
            except Exception:
                available = 0
            if available > INVOICE_AMOUNT_SAT:
                break
            bitcoind.mine(1)
            time.sleep(2)
        assert available > INVOICE_AMOUNT_SAT, (
            f"merchant never gained enough inbound liquidity (available_sat={available})"
        )
        noffer = E.electrum_clink(merchant, "add_offer", "--label", "e2e")["noffer"]
        assert noffer.startswith("noffer1"), noffer

        yield ClinkStack(relay=relay, merchant=merchant, customer=customer, noffer=noffer)
    finally:
        for h, stop in ((customer, E.stop_electrum), (merchant, E.stop_electrum),
                        (relay, stop_clink_relay), (fulcrum, stop_fulcrum)):
            if h is not None:
                try:
                    stop(h)
                except Exception:
                    pass


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def _save_noffer_destination(admin: AdminClient, store_id: str, noffer: str,
                             enabled: str = "1") -> dict:
    """Configure a single noffer destination, mirroring the dashboard's
    addresses[] chain (see test_clink_noffer.py). ``enabled`` controls the
    auto-cashout (threshold-melt) toggle — direct-receive at invoice creation
    must work independent of it, so tests pass enabled="0" to assert that."""
    data = [
        ("action", "save_auto_melt"),
        ("store_id", store_id),
        ("enabled", enabled),
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
    return body


def _invoice_row(payserver, invoice_id: str) -> dict:
    db = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert row is not None, f"no invoices row for {invoice_id}"
        return dict(row)


# Last error from _relay_query, surfaced in diagnostics so a silent empty result
# is distinguishable from a query that actually failed.
_last_relay_query_error: str | None = None


def _relay_query(relay: ClinkRelayHandle, filt: dict) -> list[dict]:
    """One REQ against the relay, returning every matching event.

    Robust against WebSocket control frames: the relay runs with heartbeat
    pings, so a PING/PONG can arrive interleaved with the backfill. The naive
    "break on the first non-TEXT frame" lost the race under CI load — a
    heartbeat frame arrived before EVENT/EOSE and the query returned empty even
    though the events were sitting in the relay buffer. Skip control frames and
    only stop on EOSE or a real close/error."""
    global _last_relay_query_error

    async def once() -> list[dict]:
        import aiohttp
        out: list[dict] = []
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=30)) as s:
            async with s.ws_connect(relay.ws_url) as ws:
                await ws.send_str(json.dumps(["REQ", "q", filt]))
                while True:
                    msg = await asyncio.wait_for(ws.receive(), timeout=10)
                    if msg.type == aiohttp.WSMsgType.TEXT:
                        data = json.loads(msg.data)
                        if data[0] == "EVENT" and data[1] == "q":
                            out.append(data[2])
                        elif data[0] == "EOSE":
                            break
                    elif msg.type in (aiohttp.WSMsgType.PING, aiohttp.WSMsgType.PONG):
                        continue  # heartbeat — keep reading for the backfill
                    else:  # CLOSE / CLOSING / CLOSED / ERROR
                        break
        return out

    # Run in a dedicated thread with its own event loop. Under the full CI suite
    # the pytest main thread already has a running event loop (left by an earlier
    # async fixture — grpc.aio / Playwright), so asyncio.run() here raised
    # "cannot be called from a running event loop" and every query silently
    # returned empty. A fresh loop in its own thread is immune to that.
    box: dict = {}

    def runner() -> None:
        loop = asyncio.new_event_loop()
        try:
            asyncio.set_event_loop(loop)
            box["value"] = loop.run_until_complete(once())
        except Exception as e:  # noqa: BLE001 — surfaced via diagnostics
            box["error"] = f"{type(e).__name__}: {e}"
        finally:
            try:
                loop.close()
            finally:
                asyncio.set_event_loop(None)

    t = threading.Thread(target=runner, daemon=True)
    t.start()
    t.join(timeout=45)
    if t.is_alive():
        _last_relay_query_error = "relay query thread timed out"
        return []
    if "error" in box:
        _last_relay_query_error = box["error"]
        return []
    _last_relay_query_error = None
    return box.get("value", [])


def _relay_events(relay: ClinkRelayHandle, request_event_id: str, since: int) -> list[dict]:
    """All kind-21001 events referencing our request. NOTE: the merchant's
    bolt11 *reply* and its later *receipt* are both kind-21001 with the same
    ``e`` tag and differ only in their (encrypted) body, so the caller must
    inspect/try every event rather than assume the first is the receipt."""
    return _relay_query(relay, {
        "kinds": [CLINK_KIND], "#e": [request_event_id], "since": max(0, since - 1),
    })


def _relay_all_events(relay: ClinkRelayHandle) -> list[dict]:
    """Diagnostic: every kind-21001 event in the relay buffer, ignoring the #e
    filter and `since`. Distinguishes 'nothing was published to the relay' from
    'published, but our request-id / since filter excluded it'."""
    return _relay_query(relay, {"kinds": [CLINK_KIND], "since": 0})


def _relay_diagnostics(relay: ClinkRelayHandle) -> str:
    """A compact dump of what the relay actually holds + its log tail, so a CI
    failure that does not reproduce locally is still debuggable from the job
    output alone."""
    def summ(e: dict) -> dict:
        etags = [t[1][:12] for t in e.get("tags", []) if len(t) >= 2 and t[0] == "e"]
        return {"id": str(e.get("id", ""))[:12], "kind": e.get("kind"),
                "pubkey": str(e.get("pubkey", ""))[:12], "#e": etags}
    all_ev = _relay_all_events(relay)
    lines = [f"relay held {len(all_ev)} kind-{CLINK_KIND} event(s): {[summ(e) for e in all_ev]}"]
    if _last_relay_query_error:
        lines.append(f"last relay query error: {_last_relay_query_error}")
    if relay.log_path and relay.log_path.exists():
        tail = relay.log_path.read_text(errors="replace").splitlines()[-40:]
        lines.append("relay.log tail:\n  " + "\n  ".join(tail))
    return "\n".join(lines)


def _wait_for_receipt_buffered(relay: ClinkRelayHandle, request_event_id: str,
                               since: int, timeout: float = 60.0) -> list[dict]:
    """Wait until the relay buffer holds both the bolt11 reply *and* the receipt
    (two kind-21001 events for our request) and return them."""
    deadline = time.monotonic() + timeout
    events: list[dict] = []
    while time.monotonic() < deadline:
        events = _relay_events(relay, request_event_id, since)
        if len(events) >= 2:
            return events
        time.sleep(1)
    raise AssertionError(
        f"relay never buffered the receipt for request {request_event_id[:12]} "
        f"within {timeout}s (saw {len(events)} matching event(s)).\n"
        + _relay_diagnostics(relay)
    )


def _poll_invoice_status(configured: ConfiguredPayserver, invoice_id: str,
                         status: str, timeout: float = 30.0) -> dict:
    deadline = time.monotonic() + timeout
    last: dict | None = None
    while time.monotonic() < deadline:
        last = configured.greenfield.get_invoice(configured.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} not {status} within {timeout}s; last={last}")


# ---------------------------------------------------------------------------
# The e2e
# ---------------------------------------------------------------------------
def test_clink_noffer_receive_round_trip(
    configured: ConfiguredPayserver, clink_stack: ClinkStack
) -> None:
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle

    # 1. Configure the store to direct-receive to the merchant's noffer.
    save = _save_noffer_destination(admin, store_id, clink_stack.noffer)
    assert (save.get("addresses") or [])[0]["type"] == "noffer", save

    # 2. Create an invoice — cashupayserver dials the noffer over the relay and
    #    the plugin answers with a real regtest bolt11 ("create invoice from
    #    noffer").
    invoice = configured.greenfield.create_invoice(
        store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {}).get("paymentMethods", {})
        .get("BTC-LightningNetwork", {}).get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), f"expected regtest bolt11, got {bolt11}"

    row = _invoice_row(payserver, invoice_id)
    assert row["payment_rail"] == "noffer", row
    assert row["noffer_request_event_id"], row

    # 3. The customer pays the bolt11 straight to the merchant ("make payment").
    E.electrum_lnpay(clink_stack.customer, bolt11, timeout=120)

    # 4. Settle via the payment-page path: capture the merchant-signed events off
    #    the relay and forward each one exactly as the browser would, until the
    #    server accepts the genuine {res:ok} receipt (the reply and the receipt
    #    are indistinguishable from the outside — only the server can decrypt).
    events = _wait_for_receipt_buffered(
        clink_stack.relay, row["noffer_request_event_id"],
        int(row["noffer_created_at"] or 0),
    )
    settled_by_receipt = False
    for ev in events:
        r = requests.post(
            f"{payserver.url}/payment.php",
            params={"id": invoice_id},
            data={"action": "noffer_receipt", "event": json.dumps(ev)},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        if r.json().get("settled") is True:
            settled_by_receipt = True
            break
    assert settled_by_receipt, "no forwarded event was accepted as a paid receipt"

    # 5. The invoice is Settled on the noffer rail ("verify invoice is settled").
    settled = _poll_invoice_status(configured, invoice_id, "Settled")
    assert settled["status"] == "Settled"
    final_row = _invoice_row(payserver, invoice_id)
    assert final_row["settled_rail"] == "noffer", final_row


def test_clink_noffer_direct_receive_without_auto_cashout(
    configured: ConfiguredPayserver, clink_stack: ClinkStack
) -> None:
    """Direct-receive over a noffer must work at invoice creation even when the
    auto-cashout (threshold-melt) toggle is OFF. Configuring a destination is
    enough — the toggle only governs the cron melt of accumulated mint balance.
    Regression guard for the fix that decoupled the two."""
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle

    # Save the noffer destination with auto-cashout explicitly disabled.
    save = _save_noffer_destination(admin, store_id, clink_stack.noffer, enabled="0")
    assert (save.get("addresses") or [])[0]["type"] == "noffer", save

    # Sanity-check the toggle is actually off in storage, so a green test can't
    # be explained by auto-cashout being on.
    with sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        store = conn.execute(
            "SELECT auto_melt_enabled FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    assert store["auto_melt_enabled"] == 0, dict(store)

    # The invoice still dials the noffer and rides the noffer rail.
    invoice = configured.greenfield.create_invoice(
        store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    row = _invoice_row(payserver, invoice["id"])
    assert row["payment_rail"] == "noffer", row
    assert row["noffer_request_event_id"], row


def test_clink_noffer_receipt_via_cron(
    configured: ConfiguredPayserver, clink_stack: ClinkStack
) -> None:
    """The best-effort cron receipt poll settles a noffer invoice too: after the
    customer pays, ``cron.php`` re-subscribes (fetchReceipt) and the relay's
    recent buffer hands it the merchant receipt."""
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle

    _save_noffer_destination(admin, store_id, clink_stack.noffer)
    invoice = configured.greenfield.create_invoice(
        store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]
    bolt11 = (
        invoice.get("checkout", {}).get("paymentMethods", {})
        .get("BTC-LightningNetwork", {}).get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), bolt11

    E.electrum_lnpay(clink_stack.customer, bolt11, timeout=120)

    # Make sure the receipt is in the relay's buffer before the (rate-limited,
    # first-poll-immediate) cron re-subscribe runs, so the poll isn't a no-op.
    row = _invoice_row(payserver, invoice_id)
    _wait_for_receipt_buffered(
        clink_stack.relay, row["noffer_request_event_id"],
        int(row["noffer_created_at"] or 0),
    )
    cron = payserver.trigger_cron()
    assert cron.status_code == 200, cron.text

    settled = _poll_invoice_status(configured, invoice_id, "Settled", timeout=45)
    assert settled["status"] == "Settled"
