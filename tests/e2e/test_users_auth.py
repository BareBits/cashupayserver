"""Multi-user auth: migration, login, role gating.

Covers the foundational pieces of the security-enhancements branch:
- legacy single-admin install migrates into the users table as 'admin'
- login with the migrated 'admin' username works with the configured password
- login with a bad username or password fails
- login is case-insensitive on username
- login response carries username + role
- session cookie is rotated on successful login
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def test_admin_user_exists_after_setup(configured: ConfiguredPayserver) -> None:
    """The setup wizard's password step seeds an 'admin' user via Auth::setAdminPassword."""
    fresh = requests.Session()
    r = fresh.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["success"] is True
    assert body["user"]["username"] == "admin"
    assert body["user"]["role"] == "admin"


def test_login_with_wrong_password_fails(configured: ConfiguredPayserver) -> None:
    fresh = requests.Session()
    r = fresh.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": "definitely-wrong"},
        timeout=15,
    )
    assert r.status_code == 401
    assert "Invalid" in r.json()["error"]


def test_login_with_unknown_username_fails(configured: ConfiguredPayserver) -> None:
    fresh = requests.Session()
    r = fresh.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "ghost", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 401


def test_login_with_empty_username_fails(configured: ConfiguredPayserver) -> None:
    """A blank username must never auth — protects against any future bug where
    the username branch becomes a wildcard."""
    fresh = requests.Session()
    r = fresh.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 401


def test_username_lookup_is_case_insensitive(configured: ConfiguredPayserver) -> None:
    """COLLATE NOCASE on users.username means 'ADMIN' and 'admin' resolve to the same row."""
    fresh = requests.Session()
    r = fresh.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "ADMIN", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    assert r.json()["success"] is True


def test_session_cookie_rotates_on_login(configured: ConfiguredPayserver) -> None:
    """session_regenerate_id(true) should mint a new session id on successful login."""
    s = requests.Session()
    # Establish a session by hitting GET first so a SID is set anonymously.
    s.get(f"{configured.handle.url}/admin", timeout=15)
    before = s.cookies.get("cashupay_session")
    assert before, "GET /admin should set a session cookie"

    r = s.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    after = s.cookies.get("cashupay_session")
    assert after and after != before, "session id must rotate on login"


def test_authenticated_requests_use_user_id_session(configured: ConfiguredPayserver) -> None:
    """After login, a GET ?api=dashboard call should succeed — proves the new
    session shape ($_SESSION['user_id']) is being honored by Auth::isLoggedIn()."""
    s = requests.Session()
    s.post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    r = s.get(f"{configured.handle.url}/admin?api=dashboard", timeout=15)
    assert r.status_code == 200
    assert "stores" in r.json()
