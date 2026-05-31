"""Playwright test for the user's own-password change flow."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.api_client import AdminClient

pytestmark = pytest.mark.ui


def _login(page, base_url: str, username: str, password: str) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{base_url}/admin")
    page.fill("#username-input", username)
    page.fill("#password-input", password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def test_user_changes_own_password(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)

    page.click('[data-view="settings"]')
    page.wait_for_selector("#view-settings.active")
    page.click("#btn-change-own-password")
    page.wait_for_selector("#modal-change-password.visible")
    page.fill("#cp-current", configured.admin_password)
    page.fill("#cp-new", "newAdminPw99")
    page.fill("#cp-confirm", "newAdminPw99")
    page.click("#btn-confirm-change-password")
    page.wait_for_selector("#modal-change-password:not(.visible)")

    # Verify via fresh API client: new password works, old does not.
    fresh = AdminClient(configured.handle.url)
    fresh.login("newAdminPw99", username="admin")
    assert fresh.csrf_token

    import requests
    r = requests.Session().post(
        f"{configured.handle.url}/admin",
        data={"action": "login", "username": "admin", "password": configured.admin_password},
        timeout=15,
    )
    assert r.status_code == 401


def test_change_password_rejects_mismatched_confirm(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    page.click('[data-view="settings"]')
    page.click("#btn-change-own-password")
    page.wait_for_selector("#modal-change-password.visible")
    page.fill("#cp-current", configured.admin_password)
    page.fill("#cp-new", "newpassword99")
    page.fill("#cp-confirm", "different99")
    page.click("#btn-confirm-change-password")
    # Modal stays open; a toast should appear.
    page.wait_for_selector("#toast.show", timeout=3000)
    assert "match" in page.locator("#toast").text_content().lower()
