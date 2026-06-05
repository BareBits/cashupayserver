"""LNURL-pay mock server for auto-melt and LNURL-direct-receive tests.

Responds to GET /.well-known/lnurlp/{user} with valid LNURL-pay metadata
pointing at /callback?user=<name>, which on hit asks the supplied LND
node to issue a BOLT11 and returns it.

LUD-21 support: when ``lud21=True`` (default), the /callback response
includes a ``verify`` URL pointing at /verify/<payment_hash_hex>. That
endpoint forwards to ``lnd.lookup_invoice`` and exposes settled/preimage
to the cashupayserver poller without the poller needing direct LN node
access — this is how Invoice::pollPendingLnAddress detects payment.

Setting CASHU_LNURL_URL_TEMPLATE in cashupayserver's environment to
"http://127.0.0.1:<port>/.well-known/lnurlp/{user}" routes
LightningAddress::resolve() through this mock.
"""
from __future__ import annotations

import base64
import json
import threading
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

from . import ports
from .lnd import LndHandle


@dataclass
class LnurlpServer:
    port: int
    server: HTTPServer
    thread: threading.Thread
    # Set of payment_hash_hex values we've issued from /callback. The /verify
    # endpoint refuses to look up payment hashes we never minted, which keeps
    # tests from accidentally querying random hashes.
    issued_hashes: set[str] = field(default_factory=set)

    @property
    def base_url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    @property
    def url_template(self) -> str:
        """Value to set as CASHU_LNURL_URL_TEMPLATE."""
        return f"{self.base_url}/.well-known/lnurlp/{{user}}"


def _r_hash_to_hex(r_hash_b64: str) -> str:
    """LND's add_invoice returns r_hash as standard base64; LND's
    lookup_invoice expects url-safe base64. Hex is the common intermediate
    we expose to LUD-21 callers.
    """
    raw = base64.b64decode(r_hash_b64 + "=" * ((4 - len(r_hash_b64) % 4) % 4))
    return raw.hex()


def start_lnurlp_server(receiver: LndHandle, *, lud21: bool = True) -> LnurlpServer:
    """Spawn a mock LNURL-pay host backed by ``receiver`` (LND).

    Args:
        receiver: The LND node that issues invoices on /callback hits.
        lud21: When True (default), the /callback response includes a
            LUD-21 verify URL pointing at /verify/<payment_hash>. Set to
            False to exercise the cashupayserver fallback path that
            silently routes through the mint when no verify URL is offered.
    """
    port = ports.allocate(1)[0]
    issued_hashes: set[str] = set()

    class Handler(BaseHTTPRequestHandler):
        def _send_json(self, status: int, body: dict) -> None:
            payload = json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def do_GET(self):  # noqa: N802
            parsed = urlparse(self.path)
            path = parsed.path

            if path.startswith("/.well-known/lnurlp/"):
                user = path[len("/.well-known/lnurlp/"):]
                callback = f"http://127.0.0.1:{port}/callback?user={user}"
                self._send_json(200, {
                    "callback": callback,
                    "minSendable": 1000,           # 1 sat
                    "maxSendable": 100_000_000_000, # 0.1 BTC
                    "metadata": json.dumps([["text/plain", f"pay {user}"]]),
                    "commentAllowed": 0,
                    "tag": "payRequest",
                })
                return

            if path == "/callback":
                qs = parse_qs(parsed.query)
                amount_msat_list = qs.get("amount", [])
                if not amount_msat_list:
                    self._send_json(400, {"status": "ERROR", "reason": "missing amount"})
                    return
                amount_sat = int(amount_msat_list[0]) // 1000
                user = (qs.get("user") or ["unknown"])[0]
                try:
                    invoice = receiver.add_invoice(amount_sat, memo=f"lnurlp:{user}")
                except Exception as e:
                    self._send_json(500, {"status": "ERROR", "reason": str(e)})
                    return
                resp = {"pr": invoice["payment_request"], "routes": []}
                if lud21:
                    payment_hash_hex = _r_hash_to_hex(invoice["r_hash"])
                    issued_hashes.add(payment_hash_hex)
                    resp["verify"] = f"http://127.0.0.1:{port}/verify/{payment_hash_hex}"
                self._send_json(200, resp)
                return

            if path.startswith("/verify/"):
                # LUD-21 verify URL: forward to LND v1 invoice lookup (hex
                # path). The fixture's higher-level lookup_invoice helper
                # uses v2's payment_hash query param whose URL-safe base64
                # encoding LND rejects in some versions; v1 takes hex in
                # the path which has no encoding ambiguity.
                payment_hash_hex = path[len("/verify/"):]
                if payment_hash_hex not in issued_hashes:
                    self._send_json(404, {"status": "ERROR", "reason": "unknown payment hash"})
                    return
                try:
                    lookup = receiver._request(
                        "GET", f"/v1/invoice/{payment_hash_hex}"
                    )
                except Exception as e:
                    self._send_json(500, {"status": "ERROR", "reason": str(e)})
                    return
                # LND's invoice "settled" field tracks settlement; r_preimage
                # is base64. Convert to hex so the verify URL preimage matches
                # what would be observed if the cashupayserver had the LN node.
                settled = bool(lookup.get("settled"))
                preimage_b64 = lookup.get("r_preimage") or ""
                preimage_hex = (
                    base64.b64decode(
                        preimage_b64 + "=" * ((4 - len(preimage_b64) % 4) % 4)
                    ).hex()
                    if settled and preimage_b64
                    else ""
                )
                self._send_json(200, {
                    "status": "OK",
                    "settled": settled,
                    "preimage": preimage_hex,
                    "pr": lookup.get("payment_request", ""),
                })
                return

            self._send_json(404, {"status": "ERROR", "reason": "not found"})

        def log_message(self, *args, **kwargs):
            pass

    server = HTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True, name=f"lnurlp-{port}")
    thread.start()
    return LnurlpServer(port=port, server=server, thread=thread, issued_hashes=issued_hashes)


def stop_lnurlp_server(s: LnurlpServer) -> None:
    s.server.shutdown()
    s.server.server_close()
    s.thread.join(timeout=5)
