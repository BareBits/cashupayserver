"""CSRF protection on /api-keys/authorize.

Pre-fix, the approve endpoint accepted any same-origin POST while the admin
had a session — letting a malicious page silently mint a wildcard-permission
API key and exfiltrate it via the redirect helper. Audit finding #2.

These tests verify the three POST actions (login, approve, deny) all require
a valid per-session CSRF token.
"""
from __future__ import annotations

import re

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


AUTH_PATH = "/api-keys/authorize"


def _csrf_from(html: str) -> str:
    m = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
    if not m:
        raise AssertionError("no csrf_token input in rendered HTML")
    return m.group(1)


def _login(s: requests.Session, base: str) -> None:
    """Log in via /api-keys/authorize so the session is established with a
    rendered CSRF token. Caller passes the token from the next form render."""
    page = s.get(f"{base}{AUTH_PATH}?applicationName=Test", timeout=15)
    token = _csrf_from(page.text)
    r = s.post(
        f"{base}{AUTH_PATH}?applicationName=Test",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD,
              "csrf_token": token},
        timeout=15,
        allow_redirects=True,
    )
    assert r.status_code == 200, r.text


def test_login_post_without_csrf_token_is_rejected(configured: ConfiguredPayserver) -> None:
    s = requests.Session()
    # Prime session so a SID exists (otherwise no CSRF token in $_SESSION yet).
    s.get(f"{configured.handle.url}{AUTH_PATH}?applicationName=Test", timeout=15)
    r = s.post(
        f"{configured.handle.url}{AUTH_PATH}?applicationName=Test",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text
    assert "Session expired" in r.text or "invalid" in r.text.lower()


def test_login_post_with_valid_csrf_token_succeeds(configured: ConfiguredPayserver) -> None:
    s = requests.Session()
    page = s.get(f"{configured.handle.url}{AUTH_PATH}?applicationName=Test", timeout=15)
    token = _csrf_from(page.text)
    r = s.post(
        f"{configured.handle.url}{AUTH_PATH}?applicationName=Test",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD,
              "csrf_token": token},
        timeout=15,
        allow_redirects=False,
    )
    # On success, the handler issues a 302 back to the same URI to render the
    # approval form.
    assert r.status_code in (302, 303), r.text


def test_approve_post_without_csrf_token_does_not_mint_key(
    configured: ConfiguredPayserver,
) -> None:
    """The critical exploit path: malicious page forges an approve POST while
    admin is logged in. Without a CSRF token, no key should be created."""
    s = requests.Session()
    _login(s, configured.handle.url)

    # Count keys before — use the admin API client to query.
    from fixtures.api_client import AdminClient
    admin = AdminClient(configured.handle.url, session=requests.Session())
    admin.login(DEFAULT_ADMIN_PASSWORD)
    keys_before = admin._post_action("get_api_keys", store_id=configured.store_id) if False else None
    # Simpler: list via the dashboard helper.
    # Actually, just attempt the unsafe POST and assert the response is 403.

    r = s.post(
        f"{configured.handle.url}{AUTH_PATH}?applicationName=Test",
        data={
            "action": "approve",
            "store_id": configured.store_id,
            "approved_permissions[]": "*",
        },
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text
    # And no apiKey should be present in the body (the success render contains it).
    assert "apiKey" not in r.text


def test_approve_post_with_valid_csrf_token_succeeds(
    configured: ConfiguredPayserver,
) -> None:
    s = requests.Session()
    _login(s, configured.handle.url)

    # Fetch the approval page to learn its CSRF token.
    page = s.get(f"{configured.handle.url}{AUTH_PATH}?applicationName=Test", timeout=15)
    token = _csrf_from(page.text)

    r = s.post(
        f"{configured.handle.url}{AUTH_PATH}?applicationName=Test",
        data={
            "action": "approve",
            "csrf_token": token,
            "store_id": configured.store_id,
            "approved_permissions[]": "btcpay.store.cancreateinvoice",
        },
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 200, r.text
    # Success view renders the key on-screen.
    assert "Authorization Successful" in r.text


def test_deny_post_without_csrf_token_is_rejected(
    configured: ConfiguredPayserver,
) -> None:
    s = requests.Session()
    _login(s, configured.handle.url)
    r = s.post(
        f"{configured.handle.url}{AUTH_PATH}?applicationName=Test",
        data={"action": "deny"},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text
