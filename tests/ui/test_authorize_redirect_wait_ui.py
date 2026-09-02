"""The authorize page's guarded redirects back to the requesting shop.

After approving (or denying) a pairing request, /api-keys/authorize sends
the merchant back to the requester — for the WordPress plugin, a site that
can be sitting behind WordPress's "Briefly unavailable for scheduled
maintenance" 503 at that exact moment (wp-cron auto-updates). Blindly
submitting into that swallowed the freshly minted API key and stranded the
merchant on the maintenance screen. Both redirect pages now probe the
target's base URL first and only navigate once it stops answering 503,
showing a waiting note meanwhile (the same guard the setup wizard's
return handoff and the plugin's onboarding forms carry).

The fixture callback server answers the probe WITH CORS headers so the
browser can read its status cross-origin — mirroring the same-origin
production case (an alongside install shares the shop's origin). A target
whose probe is unreadable (a remote shop with no CORS headers) falls
through to the immediate navigation this page always did; that degradation
path is the .catch branch and is not exercised here.
"""
from __future__ import annotations

import re
import threading
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


class _CallbackServer(ThreadingHTTPServer):
    status = 503
    posts: list[dict]
    gets: list[str]


def _start_callback_server() -> _CallbackServer:
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 - http.server API
            srv = self.server
            self.send_response(srv.status)
            # Lets the authorize page's cross-origin probe read the status.
            self.send_header("Access-Control-Allow-Origin", "*")
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            self.wfile.write(b"<html><body>landed</body></html>")
            srv.gets.append(self.path)

        def do_POST(self) -> None:  # noqa: N802
            length = int(self.headers.get("Content-Length", "0"))
            fields = urllib.parse.parse_qs(self.rfile.read(length).decode())
            self.server.posts.append(fields)
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            self.wfile.write(b"<html><body>landed</body></html>")

        def log_message(self, *args) -> None:  # noqa: A003
            pass

    server = _CallbackServer(("127.0.0.1", 0), Handler)
    server.status = 503
    server.posts = []
    server.gets = []
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def _open_approval_screen(page, configured: ConfiguredPayserver, redirect: str) -> None:
    """Sign in on the authorize page and land on the approval screen."""
    authorize_url = (
        f"{configured.handle.url}/api-keys/authorize.php"
        f"?applicationName=Guard%20Test&strict=true"
        f"&permissions=btcpay.store.cancreateinvoice"
        f"&redirect={urllib.parse.quote(redirect, safe='')}"
    )
    page.set_default_timeout(15_000)
    page.goto(authorize_url)
    page.fill("#username", "admin")
    page.fill("#password", configured.admin_password)
    page.click("button:has-text('Sign In')")
    page.wait_for_selector("#store_id")
    page.select_option("#store_id", configured.store_id)


def test_approval_waits_out_target_503_then_delivers_key(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    srv = _start_callback_server()
    try:
        redirect = f"http://127.0.0.1:{srv.server_address[1]}/callback?state=guardtest"
        _open_approval_screen(page, configured, redirect)
        page.click("button:has-text('Authorize')")

        # The redirect page holds the auto-submit: waiting note shown, no
        # key POSTed anywhere while the target answers 503.
        page.wait_for_selector("#redirect-waiting", state="visible")
        assert srv.posts == [], "the key must not be POSTed into a 503"

        # Target comes back: the held POST fires on the next probe with no
        # click, delivering the minted key to the full redirect URL.
        srv.status = 200
        page.wait_for_selector("body:has-text('landed')", timeout=15_000)
        assert len(srv.posts) == 1, srv.posts
        fields = srv.posts[0]
        assert re.fullmatch(r"[0-9a-f]{64}", fields["apiKey"][0])
        assert fields["storeId"] == [configured.store_id]
    finally:
        srv.shutdown()


def test_denial_waits_out_target_503_then_returns(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    srv = _start_callback_server()
    try:
        redirect = f"http://127.0.0.1:{srv.server_address[1]}/callback?state=guardtest"
        _open_approval_screen(page, configured, redirect)
        page.click("button:has-text('Deny')")

        # The deny page (a GET navigation, not a POST) holds the same way.
        page.wait_for_selector("#redirect-waiting", state="visible")
        assert not any("error=access_denied" in path for path in srv.gets)

        srv.status = 200
        page.wait_for_selector("body:has-text('landed')", timeout=15_000)
        assert any(
            "error=access_denied" in path and "state=guardtest" in path
            for path in srv.gets
        ), srv.gets
        assert srv.posts == []
    finally:
        srv.shutdown()
