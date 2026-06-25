"""Minimal in-rig Nostr relay (NIP-01 subset) for CLINK testing.

CLINK's kind-21001 events are ephemeral: the relay only has to forward messages
between a payer (cashupayserver's ``ClinkClient``) and the Electrum CLINK plugin
while both are connected. So this is deliberately tiny — a forward-only relay
with a small recent-event buffer to cover connect/subscribe races — built on the
``aiohttp`` that already ships in the test venv (no new dependency).

It is loopback-only, performs no auth and does not verify signatures: it exists
solely inside the throwaway rig. Two ways to use it:

* As a subprocess (the iterate.py rig + e2e): ``start_clink_relay(workdir)`` /
  ``stop_clink_relay(handle)``.
* Directly: ``python -m fixtures.clink_relay --port <port>``.

Ported from the reference clink regtest rig (rig/relay.py); the recent buffer is
what lets cashupayserver's *cron* receipt poll (``fetchReceipt``, a re-subscribe
that happens after the payment) observe a kind-21001 receipt it didn't see live.
"""
from __future__ import annotations

import argparse
import json
import os
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
from collections import deque
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Deque, Dict, List

# How many recent events to retain for REQ backfill across connect races AND so
# a later cron re-subscribe can still see a kind-21001 receipt.
RECENT_BUFFER = 512


# ---------------------------------------------------------------------------
# Relay implementation (runs in the spawned subprocess)
# ---------------------------------------------------------------------------
def event_matches(filt: Dict[str, Any], event: Dict[str, Any]) -> bool:
    """NIP-01 filter match for the subset of fields CLINK uses."""
    if "ids" in filt and event.get("id") not in filt["ids"]:
        return False
    if "authors" in filt and event.get("pubkey") not in filt["authors"]:
        return False
    if "kinds" in filt and event.get("kind") not in filt["kinds"]:
        return False
    if "since" in filt and event.get("created_at", 0) < filt["since"]:
        return False
    if "until" in filt and event.get("created_at", 0) > filt["until"]:
        return False
    for key, wanted in filt.items():
        # Tag filters: "#p", "#e", ... match against tags [[name, value], ...].
        if len(key) == 2 and key[0] == "#":
            tag_name = key[1]
            present = [t[1] for t in event.get("tags", []) if len(t) >= 2 and t[0] == tag_name]
            if not any(v in present for v in wanted):
                return False
    return True


def _build_app():
    from aiohttp import WSMsgType, web

    class Relay:
        def __init__(self) -> None:
            # ws -> {sub_id: [filter, ...]}
            self.subscriptions: Dict[Any, Dict[str, List[dict]]] = {}
            self.recent: Deque[Dict[str, Any]] = deque(maxlen=RECENT_BUFFER)

        async def handle_ws(self, request):
            ws = web.WebSocketResponse(heartbeat=30)
            await ws.prepare(request)
            self.subscriptions[ws] = {}
            try:
                async for msg in ws:
                    if msg.type != WSMsgType.TEXT:
                        continue
                    try:
                        data = json.loads(msg.data)
                    except json.JSONDecodeError:
                        continue
                    if not isinstance(data, list) or not data:
                        continue
                    try:
                        await self._dispatch(ws, data)
                    except Exception as exc:  # never let one bad message drop the conn
                        print(f"[clink-relay] dispatch error: {exc!r}", flush=True)
            finally:
                self.subscriptions.pop(ws, None)
            return ws

        async def _dispatch(self, ws, data: list) -> None:
            verb = data[0]
            if verb == "EVENT" and len(data) >= 2:
                await self._on_event(ws, data[1])
            elif verb == "REQ" and len(data) >= 3:
                await self._on_req(ws, data[1], data[2:])
            elif verb == "CLOSE" and len(data) >= 2:
                self.subscriptions.get(ws, {}).pop(data[1], None)

        async def _on_event(self, sender, event: Dict[str, Any]) -> None:
            self.recent.append(event)
            # NIP-20 command result: clients (incl. electrum_aionostr and
            # swentel/nostr-php) block on this OK before considering the publish
            # complete.
            await self._safe_send(sender, ["OK", event.get("id", ""), True, ""])
            # Broadcast to every live subscription that matches.
            for ws, subs in list(self.subscriptions.items()):
                for sub_id, filters in subs.items():
                    if any(event_matches(f, event) for f in filters):
                        await self._safe_send(ws, ["EVENT", sub_id, event])
                        break

        async def _on_req(self, ws, sub_id: str, raw_filters: list) -> None:
            # Normalise filters: NIP-01 spreads them as separate array elements,
            # but some clients nest them in a single list. Accept both.
            filters: List[dict] = []
            for f in raw_filters:
                if isinstance(f, dict):
                    filters.append(f)
                elif isinstance(f, list):
                    filters.extend(x for x in f if isinstance(x, dict))
            self.subscriptions.setdefault(ws, {})[sub_id] = filters
            # Backfill from the recent buffer (covers subscribe-after-publish).
            sent_ids = set()
            for filt in filters:
                limit = filt.get("limit")
                if limit == 0:
                    continue
                matched = [e for e in self.recent if event_matches(filt, e)]
                if limit:
                    matched = matched[-limit:]
                for event in matched:
                    if event.get("id") not in sent_ids:
                        sent_ids.add(event.get("id"))
                        await self._safe_send(ws, ["EVENT", sub_id, event])
            await self._safe_send(ws, ["EOSE", sub_id])

        @staticmethod
        async def _safe_send(ws, payload: list) -> None:
            try:
                await ws.send_str(json.dumps(payload))
            except Exception:
                pass

    relay = Relay()
    app = web.Application()
    app.router.add_get("/", relay.handle_ws)
    # A tiny health endpoint so callers can wait for readiness over HTTP.
    async def _health(_request):
        return web.Response(text="ok")
    app.router.add_get("/healthz", _health)
    return app


def _serve(host: str, port: int) -> None:
    from aiohttp import web

    print(f"[clink-relay] listening on ws://{host}:{port}", flush=True)
    web.run_app(_build_app(), host=host, port=port, print=None)


# ---------------------------------------------------------------------------
# Subprocess handle (used by iterate.py + e2e fixtures)
# ---------------------------------------------------------------------------
@dataclass
class ClinkRelayHandle:
    host: str
    port: int
    process: subprocess.Popen[bytes]

    @property
    def ws_url(self) -> str:
        return f"ws://{self.host}:{self.port}"

    @property
    def health_url(self) -> str:
        return f"http://{self.host}:{self.port}/healthz"


def start_clink_relay(workdir: Path, *, host: str = "127.0.0.1") -> ClinkRelayHandle:
    """Spawn the in-rig Nostr relay on a free port and wait until it answers."""
    from . import ports as ports_mod

    (port,) = ports_mod.allocate(1)
    log_dir = workdir / "clink-relay"
    log_dir.mkdir(parents=True, exist_ok=True)
    log = (log_dir / "relay.log").open("ab")

    # Run this very module as a script via the active interpreter (the test
    # venv when under pytest / iterate.py — both have aiohttp).
    tests_dir = str(Path(__file__).resolve().parent.parent)
    env = os.environ.copy()
    env["PYTHONPATH"] = tests_dir + os.pathsep + env.get("PYTHONPATH", "")
    proc = subprocess.Popen(
        [sys.executable, "-m", "fixtures.clink_relay", "--host", host, "--port", str(port)],
        cwd=tests_dir,
        env=env,
        stdout=log,
        stderr=subprocess.STDOUT,
    )

    handle = ClinkRelayHandle(host=host, port=port, process=proc)
    deadline = time.monotonic() + 20
    last: Exception | None = None
    while time.monotonic() < deadline:
        if proc.poll() is not None:
            raise RuntimeError(f"clink relay exited early (rc={proc.returncode}); see {log_dir/'relay.log'}")
        try:
            urllib.request.urlopen(handle.health_url, timeout=2).read()
            return handle
        except (urllib.error.URLError, ConnectionError) as e:
            last = e
            time.sleep(0.2)
    stop_clink_relay(handle)
    raise TimeoutError(f"clink relay not ready after 20s ({last})")


def stop_clink_relay(handle: ClinkRelayHandle) -> None:
    if handle.process.poll() is None:
        handle.process.send_signal(signal.SIGTERM)
        try:
            handle.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            handle.process.kill()
            handle.process.wait()


def main() -> None:
    parser = argparse.ArgumentParser(description="minimal in-rig Nostr relay")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, required=True)
    args = parser.parse_args()
    _serve(args.host, args.port)


if __name__ == "__main__":
    main()
