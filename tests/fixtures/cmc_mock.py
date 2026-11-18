"""Mock of CoinMarketCap's public quote endpoint for the eCash (ECX) rail.

Serves exactly what EcxRates reads:

    GET /data-api/v3/cryptocurrency/quote/latest?id=42032&convertId=2781
        -> {"data":[{"id":42032,"symbol":"ECX","quotes":[{"name":"2781","price":<usd>}]}]}

`price` is settable; `mode` switches the whole mock to "down" (HTTP 500),
"empty" (a well-formed payload without a price), "garbage" (HTTP 200 whose
price field is not a decimal: an exponent string that PHP would cast to INF)
or "hostile" (HTTP 200 with a body full of terminal escape sequences and
forged log lines that is not JSON at all) so tests can prove that the eCash
option is withheld when no usable price can be obtained. Every request is
appended to ``hits``. Point a payserver at it with CASHUPAY_CMC_API_BASE
(honoured only while private endpoints are allowed, which the rigs are).
"""
from __future__ import annotations

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

from . import ports

ECX_CMC_ID = 42032
USD_CONVERT_ID = 2781


class MockCmc:
    def __init__(self, *, price: float = 40.0) -> None:
        self.price = price
        self.mode = "ok"
        self.hits: list[str] = []
        self._lock = threading.Lock()
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def _send(self, status: int, body: bytes, ctype: str = "application/json") -> None:
                self.send_response(status)
                self.send_header("Content-Type", ctype)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_GET(self):  # noqa: N802
                with outer._lock:
                    outer.hits.append(f"GET {self.path}")
                if outer.mode == "down":
                    self._send(500, b"cmc-down", "text/plain")
                    return
                if outer.mode == "hostile":
                    self._send(200, b"\x1b[31mPWNED\x1b[0m\r\n[cron] forged log line\n<script>alert(1)</script>")
                    return
                u = urlparse(self.path)
                if u.path != "/data-api/v3/cryptocurrency/quote/latest":
                    self._send(404, b"{}")
                    return
                q = parse_qs(u.query)
                cid = int((q.get("id") or ["0"])[0])
                conv = (q.get("convertId") or [""])[0]
                quotes = []
                if outer.mode != "empty" and cid == ECX_CMC_ID and conv == str(USD_CONVERT_ID):
                    price = "1e400" if outer.mode == "garbage" else outer.price
                    quotes = [{"name": str(USD_CONVERT_ID), "price": price, "volume24h": 1.0}]
                body = {"data": [{"id": cid, "name": "eCash", "symbol": "ECX", "slug": "ecashdotcom", "quotes": quotes}],
                        "status": {"error_code": "0"}}
                self._send(200, json.dumps(body).encode())

            def log_message(self, *args, **kwargs):
                pass

        self.port = ports.allocate(1)[0]
        self.server = ThreadingHTTPServer(("127.0.0.1", self.port), Handler)
        self.server.daemon_threads = True
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True, name=f"mock-cmc-{self.port}")
        self.thread.start()

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def hit_count(self) -> int:
        with self._lock:
            return len(self.hits)

    def stop(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
