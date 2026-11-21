"""A signed-in session created under PHP's default session directory survives
the switch to <data dir>/sessions.

Releases before the login timeout (v1.10 and earlier) left sessions wherever
the host's session.save_path pointed. The first request served by a newer
build moves the cookie's session file into data/sessions
(Auth::adoptLegacySessionFile), so the operator who just clicked "Update now"
is still signed in and the old dashboard's status polling completes. The
upgrade tier (tests/upgrade/, local only) exercises this against real old
releases; this test keeps the mechanism covered in CI by staging the same
situation on a current build: sign in, move the session file to the server's
default directory, and expect the next admin call to succeed and the file to
be back.
"""
from __future__ import annotations

import shutil
import tempfile
from pathlib import Path

import pytest

from conftest import ConfiguredPayserver
from fixtures import webserver
from fixtures.api_client import AdminClient


def test_session_in_php_default_directory_is_adopted(configured: ConfiguredPayserver) -> None:
    if webserver.current_backend() != "phps":
        pytest.skip("the legacy directory is the server process's own temp dir; only reachable with php -S")
    cfg = configured

    admin = AdminClient(cfg.handle.url)
    admin.login(cfg.admin_password)
    sid = admin.s.cookies.get("cashupay_session")
    assert sid, "login set no session cookie"

    own = Path(cfg.handle.data_dir) / "sessions" / f"sess_{sid}"
    assert own.is_file(), f"fresh session not under data/sessions: {own}"

    # Where php -S keeps sessions when nothing redirects session.save_path:
    # sys_get_temp_dir(), which honours the same TMPDIR the test process sees.
    legacy = Path(tempfile.gettempdir()) / f"sess_{sid}"
    shutil.move(str(own), str(legacy))
    assert not own.exists() and legacy.is_file()

    try:
        r = admin.s.get(f"{cfg.handle.url}/admin.php", params={"api": "get_login_timeout"}, timeout=15)
        assert r.status_code == 200, f"session was not carried over: {r.status_code} {r.text[:200]}"
        assert "seconds" in r.json()
        assert own.is_file(), "session file was not moved into data/sessions"
        assert not legacy.exists(), "legacy copy left behind"

        # Plain hit on the next request, nothing left to adopt.
        r = admin.s.get(f"{cfg.handle.url}/admin.php", params={"api": "get_login_timeout"}, timeout=15)
        assert r.status_code == 200, r.text[:200]
    finally:
        if legacy.exists():
            legacy.unlink()
