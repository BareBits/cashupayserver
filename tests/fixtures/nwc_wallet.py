"""In-rig fake NWC wallet service (NIP-47) backed by a real regtest LND.

Plays the wallet-service side of Nostr Wallet Connect against
cashupayserver's NwcClient: connects to the in-rig relay
(fixtures/clink_relay.py — a generic NIP-01 forwarder), publishes its
kind-13194 info event, and answers kind-23194 requests:

  make_invoice   -> LND add_invoice (a real payable lnbcrt… bolt11)
  lookup_invoice -> LND invoice lookup (state settled once the customer's
                    node actually paid), so settlement flows exactly like a
                    production wallet: pay the invoice, the poller's
                    lookup_invoice sees settled + preimage
  get_info       -> configurable per-connection methods list (or RESTRICTED
                    when `info_methods` is None, like a locked-down
                    connection)

Speaks the NIP-04 baseline (its info event advertises no `encryption` tag),
which is the compatibility floor the PHP client must handle; the nip44
negotiation path is covered by the PHP unit suite's mock wallet.

Runs an asyncio loop in a daemon thread, mirroring how lnurlp_server runs
its HTTP thread. Control knobs on the handle:

  info_methods      list[str] | None — get_info's per-connection methods
  fail_make_invoice bool — answer make_invoice with an INTERNAL error
  silent            bool — swallow requests (client-timeout tests)
"""
from __future__ import annotations

import asyncio
import base64
import json
import threading
import time
from dataclasses import dataclass, field
from typing import Any, Optional

from . import nostr_crypto as nc
from .lnd import LndHandle


def _b64_to_hex(b64: str) -> str:
    return base64.b64decode(b64 + "=" * ((4 - len(b64) % 4) % 4)).hex()


@dataclass
class NwcWalletHandle:
    relay_url: str
    wallet_sk: str
    wallet_pk: str
    receiver: LndHandle
    info_methods: Optional[list[str]] = None
    fail_make_invoice: bool = False
    silent: bool = False
    _thread: Optional[threading.Thread] = None
    _loop: Optional[asyncio.AbstractEventLoop] = None
    _ready: threading.Event = field(default_factory=threading.Event)
    _stop: threading.Event = field(default_factory=threading.Event)
    # payment_hash hex -> r_hash hex we issued (guards lookups, mirrors
    # lnurlp_server.issued_hashes)
    issued: dict[str, str] = field(default_factory=dict)

    def connection_uri(self) -> str:
        """A fresh connection string for this wallet (new client secret)."""
        secret = nc.generate_privkey()
        return f"nostr+walletconnect://{self.wallet_pk}?relay={self.relay_url}&secret={secret}"

    def stop(self) -> None:
        self._stop.set()
        if self._loop is not None:
            self._loop.call_soon_threadsafe(lambda: None)  # wake the loop
        if self._thread is not None:
            self._thread.join(timeout=10)


@dataclass
class NwcStack:
    """In-rig relay + fake wallet, the pair NWC tests need together."""
    relay: Any  # ClinkRelayHandle (avoid the import cycle at type time)
    wallet: NwcWalletHandle


def start_nwc_stack(workdir, receiver: LndHandle, *,
                    info_methods: Optional[list[str]] = None) -> NwcStack:
    """Start the generic in-rig relay plus a fake NWC wallet on it."""
    from .clink_relay import start_clink_relay
    relay = start_clink_relay(workdir)
    try:
        wallet = start_nwc_wallet(relay.ws_url, receiver, info_methods=info_methods)
    except Exception:
        from .clink_relay import stop_clink_relay
        stop_clink_relay(relay)
        raise
    return NwcStack(relay=relay, wallet=wallet)


def stop_nwc_stack(stack: NwcStack) -> None:
    from .clink_relay import stop_clink_relay
    try:
        stack.wallet.stop()
    finally:
        stop_clink_relay(stack.relay)


def start_nwc_wallet(relay_url: str, receiver: LndHandle, *,
                     info_methods: Optional[list[str]] = None) -> NwcWalletHandle:
    """Connect a fake NWC wallet to `relay_url`, minting from `receiver`."""
    wallet_sk = nc.generate_privkey()
    handle = NwcWalletHandle(
        relay_url=relay_url,
        wallet_sk=wallet_sk,
        wallet_pk=nc.pubkey_xonly(wallet_sk),
        receiver=receiver,
        info_methods=info_methods,
    )

    def _run() -> None:
        loop = asyncio.new_event_loop()
        handle._loop = loop
        asyncio.set_event_loop(loop)
        try:
            loop.run_until_complete(_serve(handle))
        finally:
            loop.close()

    handle._thread = threading.Thread(target=_run, name="nwc-wallet", daemon=True)
    handle._thread.start()
    if not handle._ready.wait(timeout=20):
        handle.stop()
        raise TimeoutError("fake NWC wallet did not connect to the relay")
    return handle


async def _serve(handle: NwcWalletHandle) -> None:
    import aiohttp

    async with aiohttp.ClientSession() as session:
        async with session.ws_connect(handle.relay_url, heartbeat=30) as ws:
            # Replaceable info event: no `encryption` tag -> nip04 baseline.
            info = nc.sign_event(
                handle.wallet_sk, 13194, [],
                "make_invoice lookup_invoice get_info",
            )
            await ws.send_str(json.dumps(["EVENT", info]))
            await ws.send_str(json.dumps([
                "REQ", "wallet-sub",
                {"kinds": [23194], "#p": [handle.wallet_pk]},
            ]))
            handle._ready.set()

            while not handle._stop.is_set():
                try:
                    msg = await asyncio.wait_for(ws.receive(), timeout=1.0)
                except asyncio.TimeoutError:
                    continue
                if msg.type != aiohttp.WSMsgType.TEXT:
                    if msg.type in (aiohttp.WSMsgType.CLOSED, aiohttp.WSMsgType.ERROR):
                        break
                    continue
                try:
                    data = json.loads(msg.data)
                except json.JSONDecodeError:
                    continue
                if not isinstance(data, list) or len(data) < 3:
                    continue
                if data[0] != "EVENT" or data[1] != "wallet-sub":
                    continue
                event = data[2]
                if event.get("kind") != 23194:
                    continue
                if handle.silent:
                    continue
                try:
                    reply = _handle_request(handle, event)
                except Exception as exc:  # noqa: BLE001 — keep the wallet alive
                    print(f"[nwc-wallet] request failed: {exc!r}", flush=True)
                    continue
                await ws.send_str(json.dumps(["EVENT", reply]))


def _handle_request(handle: NwcWalletHandle, event: dict[str, Any]) -> dict[str, Any]:
    client_pk = event["pubkey"]
    plain = nc.nip04_decrypt(event["content"], handle.wallet_sk, client_pk)
    request = json.loads(plain)
    method = request.get("method", "")
    params = request.get("params") or {}
    print(f"[nwc-wallet] {method} from {client_pk[:12]}", flush=True)

    body = _response_body(handle, method, params)
    return nc.sign_event(
        handle.wallet_sk, 23195,
        [["p", client_pk], ["e", event["id"]]],
        nc.nip04_encrypt(json.dumps(body), handle.wallet_sk, client_pk),
    )


def _response_body(handle: NwcWalletHandle, method: str, params: dict) -> dict[str, Any]:
    def err(code: str, message: str) -> dict[str, Any]:
        return {"result_type": method, "error": {"code": code, "message": message}, "result": None}

    if method == "make_invoice":
        if handle.fail_make_invoice:
            return err("INTERNAL", "wallet scripted to fail")
        msats = int(params.get("amount", 0))
        if msats <= 0 or msats % 1000 != 0:
            # LND mints whole-sat invoices; NwcClient always sends sats*1000.
            return err("OTHER", f"unsupported msat amount {msats}")
        created = handle.receiver.add_invoice(msats // 1000, memo=str(params.get("description", ""))[:600])
        payment_hash = _b64_to_hex(created["r_hash"])
        handle.issued[payment_hash] = payment_hash
        return {
            "result_type": "make_invoice",
            "error": None,
            "result": {
                "type": "incoming",
                "state": "pending",
                "invoice": created["payment_request"],
                "payment_hash": payment_hash,
                "amount": msats,
                "created_at": int(time.time()),
                "expires_at": int(time.time()) + int(params.get("expiry", 3600)),
            },
        }

    if method == "lookup_invoice":
        payment_hash = str(params.get("payment_hash", "")).lower()
        if payment_hash not in handle.issued:
            return err("NOT_FOUND", "unknown payment hash")
        # v1 hex-in-path lookup: the fixture's v2 helper uses a URL-safe-base64
        # query param that some LND versions reject (see lnurlp_server.py's
        # /verify handler, which hit the same wall).
        looked = handle.receiver._request("GET", f"/v1/invoice/{payment_hash}")
        settled = looked.get("state") == "SETTLED" or looked.get("settled") is True
        result: dict[str, Any] = {
            "type": "incoming",
            "state": "settled" if settled else "pending",
            "invoice": looked.get("payment_request", ""),
            "payment_hash": payment_hash,
            "amount": int(looked.get("value_msat", 0) or int(looked.get("value", 0)) * 1000),
            "created_at": int(looked.get("creation_date", 0)),
        }
        if settled:
            result["preimage"] = _b64_to_hex(looked["r_preimage"])
            result["settled_at"] = int(looked.get("settle_date", 0)) or int(time.time())
        return {"result_type": "lookup_invoice", "error": None, "result": result}

    if method == "get_info":
        if handle.info_methods is None:
            return err("RESTRICTED", "get_info not permitted on this connection")
        return {
            "result_type": "get_info",
            "error": None,
            "result": {
                "alias": "rig-nwc-wallet",
                "network": "regtest",
                "methods": list(handle.info_methods),
            },
        }

    return err("NOT_IMPLEMENTED", f"unknown method {method}")
