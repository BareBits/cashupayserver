"""SSRF hardening: mint probe gating + URL policy, and the opportunistic
background self-request never carrying the internal key to a Host-derived
target.
"""
from __future__ import annotations

import json
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

import requests

from conftest import ConfiguredPayserver
from fixtures import ports
from fixtures.api_client import AdminClient


def _raw_action(client: AdminClient, base: str, action: str, **fields) -> requests.Response:
    return client.s.post(
        f"{base}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": client.csrf_token},
        timeout=30,
    )


def _set_config(handle, key: str, value) -> None:
    now = int(time.time())
    with handle.db() as db:
        db.execute("DELETE FROM config WHERE key = ?", (key,))
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?)",
            (key, json.dumps(value), now, now),
        )


# ---------- NW-3: test_mint is admin-only ----------


def test_user_role_cannot_probe_mints(configured: ConfiguredPayserver) -> None:
    r = configured.admin._post_action("create_user", username="staff", password="staffpw1234", role="user")
    assert r.get("success"), r
    staff = AdminClient(configured.handle.url)
    staff.login("staffpw1234", username="staff")

    t0 = time.monotonic()
    r = _raw_action(staff, configured.handle.url, "test_mint", mint_url="http://127.0.0.1:1")
    assert r.status_code == 403, r.text
    assert "Admin role required" in r.text
    r = _raw_action(staff, configured.handle.url, "test_mint_expiry", mint_url="http://127.0.0.1:1", unit="sat")
    assert r.status_code == 403, r.text
    assert time.monotonic() - t0 < 5, "refused without any connection attempt"


def test_admin_mint_probe_refuses_file_and_private_targets(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    admin = configured.admin
    # The fixtures run mints on loopback with the private-endpoints opt-in;
    # turn it OFF (the DB key wins over the env) for this policy check.
    _set_config(handle, "allow_private_endpoints", False)

    r = _raw_action(admin, handle.url, "test_mint", mint_url="file:///etc/passwd")
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is False
    assert "http" in body["error"].lower()
    assert "root:" not in r.text

    t0 = time.monotonic()
    r = _raw_action(admin, handle.url, "test_mint", mint_url="http://127.0.0.1:1")
    body = r.json()
    assert body["success"] is False, r.text
    assert "private or local network" in body["error"]
    assert "127.0.0.1" not in body["error"], "the refused address is not echoed"
    assert time.monotonic() - t0 < 5, "refused before connecting (no connect timeout)"

    r = _raw_action(admin, handle.url, "test_mint", mint_url="http://169.254.169.254/latest/meta-data/")
    assert r.json()["success"] is False
    assert "private or local network" in r.json()["error"]

    r = _raw_action(admin, handle.url, "test_mint", mint_url="https://user:pw@mint.example")
    assert r.json()["success"] is False
    assert "credentials" in r.json()["error"]


# ---------- NW-5 / SO-4: internal key never goes to a Host-derived target ----------


class _Capture(BaseHTTPRequestHandler):
    seen: list[dict] = []

    def _record(self) -> None:
        _Capture.seen.append({"path": self.path, "headers": {k.lower(): v for k, v in self.headers.items()}})
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"listener")

    do_GET = _record
    do_POST = _record
    do_HEAD = _record

    def log_message(self, *args) -> None:  # noqa: D401 - keep pytest output clean
        pass


def test_payment_page_with_forged_host_never_sends_the_internal_key_elsewhere(
    configured: ConfiguredPayserver,
) -> None:
    """GET /payment.php with `Host: 127.0.0.1:<listener>` used to make the server
    curl http://127.0.0.1:<listener>/cron.php?internal=1 with X-CRON-KEY. The
    self-request is now pinned to the address:port the request arrived on, so
    the listener named by the Host header never sees it, while the trigger
    itself still fires (stamped) and cron keeps working."""
    handle = configured.handle
    _Capture.seen = []
    port = ports.allocate(1)[0]
    server = HTTPServer(("127.0.0.1", port), _Capture)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        # Make sure the 30 s coalescing window is open so the trigger really fires.
        _set_config(handle, "last_background_trigger", 0)
        internal_key = None
        with handle.db() as db:
            row = db.execute("SELECT value FROM config WHERE key = 'internal_background_key'").fetchone()
            internal_key = row["value"] if row else None

        invoice = configured.greenfield.create_invoice(configured.store_id, "1000", "sat")
        r = requests.get(
            f"{handle.url}/payment.php",
            params={"id": invoice["id"]},
            headers={"Host": f"127.0.0.1:{port}"},
            timeout=30,
        )
        assert r.status_code == 200, r.text[:300]

        time.sleep(1.0)
        assert _Capture.seen == [], f"the Host-named listener received requests: {_Capture.seen}"
        if internal_key:
            for req in _Capture.seen:
                assert req["headers"].get("x-cron-key") != internal_key

        with handle.db() as db:
            row = db.execute("SELECT value FROM config WHERE key = 'last_background_trigger'").fetchone()
        assert row is not None and int(json.loads(row["value"])) > 0, "the trigger fired (stamped), it was not skipped"

        # Cron itself is unaffected.
        r = handle.trigger_cron()
        assert r.status_code == 200, r.text[:200]
    finally:
        server.shutdown()
        server.server_close()
