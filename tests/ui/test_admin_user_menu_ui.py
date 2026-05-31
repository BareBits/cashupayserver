"""Header user dropdown: click to open, shows the current username,
contains a Logout entry that ends the session, closes on outside click
and Escape.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(page, base_url: str, username: str, password: str) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{base_url}/admin")
    page.fill("#username-input", username)
    page.fill("#password-input", password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def test_user_menu_shows_username_and_logout_entry(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)

    # Closed by default.
    is_hidden = page.evaluate(
        "() => document.getElementById('user-menu-panel').classList.contains('hidden')"
    )
    assert is_hidden, "dropdown must start hidden"

    page.click("#user-btn")
    page.wait_for_function(
        "() => !document.getElementById('user-menu-panel').classList.contains('hidden')",
        timeout=3000,
    )
    assert page.locator("#user-menu-username").text_content().strip() == "admin"
    assert page.locator("#user-menu-logout").is_visible()


def test_user_menu_closes_on_outside_click(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    page.click("#user-btn")
    page.wait_for_function(
        "() => !document.getElementById('user-menu-panel').classList.contains('hidden')"
    )
    # Click somewhere outside the menu.
    page.click(".header-title")
    page.wait_for_function(
        "() => document.getElementById('user-menu-panel').classList.contains('hidden')",
        timeout=2000,
    )


def test_user_menu_logout_ends_session(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    page.click("#user-btn")
    page.wait_for_function(
        "() => !document.getElementById('user-menu-panel').classList.contains('hidden')"
    )
    page.click("#user-menu-logout")

    # logout() reloads the page; after reload the lock screen should be
    # visible (no session -> password prompt).
    page.wait_for_selector("#lock-screen:not(.hidden)", timeout=5000)
    # And the dashboard view should not be the visible app shell.
    is_app_visible = page.evaluate(
        "() => document.getElementById('app').classList.contains('visible')"
    )
    assert is_app_visible is False


def test_user_menu_shows_non_admin_username(
    configured: ConfiguredPayserver, page,
) -> None:
    configured.admin._post_action(
        "create_user", username="erin", password="erinpw1234abc", role="user"
    )
    _login(page, configured.handle.url, "erin", "erinpw1234abc")
    page.click("#user-btn")
    page.wait_for_function(
        "() => !document.getElementById('user-menu-panel').classList.contains('hidden')"
    )
    assert page.locator("#user-menu-username").text_content().strip() == "erin"
