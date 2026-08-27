"""Local stand-in for the GitHub releases API.

The WordPress plugin's "install BareBits alongside WordPress" flow downloads
the latest stable release from api.github.com. Tests point the plugin at this
fixture instead (CASHUPAY_RELEASE_API_BASE, threaded through the WP fixture's
router wrapper) so the install path runs against the standalone zip built
from the working tree — hermetic, and exercising the exact artifact CI
publishes when $CASHUPAY_STANDALONE_ZIP is set.

Serves:
    GET /releases/latest      GitHub-shaped JSON with the zip + SHA256SUMS assets
    GET /assets/<name>        the asset bytes
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import threading
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from . import binaries, ports

REPO_ROOT = Path(__file__).resolve().parent.parent.parent

RELEASE_TAG = "v0.0-test"
ZIP_ASSET_NAME = f"cashupayserver-{RELEASE_TAG}.zip"


def ensure_standalone_zip() -> Path:
    """The standalone server zip — the exact artifact the release workflow
    publishes. CI passes it via $CASHUPAY_STANDALONE_ZIP (built by the
    build-artifacts job); a local run builds it once via
    scripts/build-standalone.sh (needs composer + npm the first time)."""
    env_zip = os.environ.get("CASHUPAY_STANDALONE_ZIP")
    if env_zip:
        p = Path(env_zip)
        if p.is_file():
            return p
        raise RuntimeError(
            f"CASHUPAY_STANDALONE_ZIP={env_zip!r} but that file does not exist"
        )

    php_exe = binaries.ensure(binaries.PHP)["php"]
    script = REPO_ROOT / "scripts" / "build-standalone.sh"
    env = os.environ.copy()
    env["PHP_BIN"] = str(php_exe)
    print(f"[release] building standalone zip via {script.name} ...")
    subprocess.run(["bash", str(script)], cwd=str(REPO_ROOT), env=env, check=True)
    zip_path = REPO_ROOT / "build" / "cashupayserver.zip"
    if not zip_path.is_file():
        raise RuntimeError("build-standalone.sh did not produce build/cashupayserver.zip")
    return zip_path


@dataclass
class ReleaseServer:
    server: ThreadingHTTPServer
    thread: threading.Thread
    port: int

    @property
    def api_base(self) -> str:
        """Value for CASHUPAY_RELEASE_API_BASE."""
        return f"http://127.0.0.1:{self.port}"


def start_release_server(
    zip_path: Path, *, with_sums: bool = True, tamper: bool = False
) -> ReleaseServer:
    """Serve `zip_path` as the latest release. with_sums=False omits the
    SHA256SUMS asset, modeling an older release without checksums. tamper=True
    computes SHA256SUMS from the pristine zip but serves a corrupted one — the
    shape of a modified-in-transit (or compromised-mirror) download."""
    zip_bytes = zip_path.read_bytes()
    sums_body = (
        f"{hashlib.sha256(zip_bytes).hexdigest()}  {ZIP_ASSET_NAME}\n".encode()
    )
    if tamper:
        zip_bytes = zip_bytes[:-1] + bytes([zip_bytes[-1] ^ 0xFF])
    port = ports.allocate(1)[0]
    base = f"http://127.0.0.1:{port}"

    assets: dict[str, tuple[bytes, str]] = {
        ZIP_ASSET_NAME: (zip_bytes, "application/zip"),
    }
    release_assets = [
        {"name": ZIP_ASSET_NAME, "browser_download_url": f"{base}/assets/{ZIP_ASSET_NAME}"},
        # Realistic clutter the plugin must skip over.
        {"name": "BUILD_INFO", "browser_download_url": f"{base}/assets/BUILD_INFO"},
        {
            "name": f"cashupayserver-windows-{RELEASE_TAG}.zip",
            "browser_download_url": f"{base}/assets/cashupayserver-windows-{RELEASE_TAG}.zip",
        },
        {
            "name": f"wordpress_plugin-{RELEASE_TAG}.zip",
            "browser_download_url": f"{base}/assets/wordpress_plugin-{RELEASE_TAG}.zip",
        },
    ]
    assets["BUILD_INFO"] = (b"COMMIT_SHA=test\n", "text/plain")
    if with_sums:
        assets["SHA256SUMS"] = (sums_body, "text/plain")
        release_assets.append(
            {"name": "SHA256SUMS", "browser_download_url": f"{base}/assets/SHA256SUMS"}
        )

    latest = json.dumps({"tag_name": RELEASE_TAG, "assets": release_assets}).encode()

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 (http.server API)
            path = self.path.split("?", 1)[0]
            if path == "/releases/latest":
                self._respond(latest, "application/json")
                return
            if path.startswith("/assets/"):
                name = path[len("/assets/"):]
                if name in assets:
                    body, ctype = assets[name]
                    self._respond(body, ctype)
                    return
            self.send_response(404)
            self.end_headers()

        def _respond(self, body: bytes, ctype: str) -> None:
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args) -> None:  # keep pytest output clean
            pass

    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return ReleaseServer(server=server, thread=thread, port=port)


def stop_release_server(rs: ReleaseServer) -> None:
    rs.server.shutdown()
    rs.server.server_close()
