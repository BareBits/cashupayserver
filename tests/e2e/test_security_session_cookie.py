"""Session cookie hardening: HttpOnly, SameSite, Secure flags + regen on logout.

The Set-Cookie header carries the flags the browser then enforces. Audit
findings #13 and #15 — we set them in Auth::initSession() before
session_start() and rotate on logout.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def _session_set_cookie(headers) -> str:
    """Return the Set-Cookie value for cashupay_session, raising if missing."""
    cookies = headers.get_list("Set-Cookie") if hasattr(headers, "get_list") else []
    if not cookies:
        # requests doesn't expose get_list; fall back to splitting Set-Cookie
        raw = headers.get("Set-Cookie", "")
        cookies = [c.strip() for c in raw.split(",") if "cashupay_session" in c]
    for c in cookies:
        if c.startswith("cashupay_session="):
            return c
    raise AssertionError(f"no cashupay_session in Set-Cookie: {dict(headers)}")


def test_session_cookie_is_httponly_and_samesite_lax(configured: ConfiguredPayserver) -> None:
    """Anonymous GET should already set the hardened cookie."""
    s = requests.Session()
    r = s.get(f"{configured.handle.url}/admin", timeout=15)
    set_cookie = _session_set_cookie(r.headers)
    assert "HttpOnly" in set_cookie, set_cookie
    assert "SameSite=Lax" in set_cookie, set_cookie
    # Local stack is plain HTTP, so Secure should NOT be present.
    assert "Secure" not in set_cookie, set_cookie


def test_session_cookie_is_cleared_on_logout(configured: ConfiguredPayserver) -> None:
    s = requests.Session()
    # Log in to start a real session.
    s.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    sid_before = s.cookies.get("cashupay_session")
    assert sid_before

    # Fetch CSRF token for the logout POST.
    page = s.get(f"{configured.handle.url}/admin", timeout=15)
    import re
    m = re.search(r'name="csrf-token"\s+content="([^"]+)"', page.text)
    assert m, "expected csrf-token meta on admin page"
    csrf = m.group(1)

    r = s.post(
        f"{configured.handle.url}/admin",
        data={"action": "logout"},
        headers={"X-CSRF-Token": csrf},
        timeout=15,
    )
    assert r.status_code == 200, r.text

    # After logout, the same client should no longer be authenticated.
    follow = s.get(f"{configured.handle.url}/admin?api=dashboard", timeout=15)
    assert follow.status_code == 401
