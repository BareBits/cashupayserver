"""Playwright tests for the server-side PIN flow.

Covers: user sets a PIN from Settings, PIN screen appears on next reload,
correct PIN unlocks, wrong PIN shakes, user clears the PIN.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD

pytestmark = pytest.mark.ui


def _login(page, base_url: str, username: str, password: str) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{base_url}/admin")
    page.fill("#username-input", username)
    page.fill("#password-input", password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _open_settings(page) -> None:
    page.click('.nav-item[data-view="settings"]')
    page.wait_for_selector("#view-settings.active")


def test_user_sets_pin_then_pin_screen_on_reload(
    configured: ConfiguredPayserver, page,
) -> None:
    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.wait_for_selector("#btn-set-pin")
    page.click("#btn-set-pin")
    page.wait_for_selector("#modal-pin-setup.visible")
    page.fill("#new-pin", "8642")
    page.fill("#confirm-pin", "8642")
    page.click("#btn-save-pin")
    page.wait_for_selector("#modal-pin-setup:not(.visible)")

    # Reload — PIN screen should now intercept before the app shows.
    page.reload()
    page.wait_for_selector("#lock-screen:not(.hidden)")
    # PIN UI is visible, password fallback subtitle is "Enter PIN to unlock".
    assert page.locator(".lock-subtitle").text_content().strip() == "Enter PIN to unlock"

    # Wrong PIN: type 0000, expect shake (error class on dots) and reset.
    for d in "0000":
        page.click(f'.pin-key[data-key="{d}"]')
    page.wait_for_selector(".pin-dot.error", timeout=3000)
    # Wait for the reset to clear.
    page.wait_for_function(
        "() => document.querySelectorAll('.pin-dot.error').length === 0",
        timeout=3000,
    )

    # Correct PIN unlocks.
    for d in "8642":
        page.click(f'.pin-key[data-key="{d}"]')
    page.wait_for_selector("#app", state="visible", timeout=5000)


def test_user_can_clear_own_pin(
    configured: ConfiguredPayserver, page,
) -> None:
    """Set a PIN, then clear it via the Settings UI. After reload the PIN
    screen should NOT appear (server says no PIN -> straight to app)."""
    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.click("#btn-set-pin")
    page.wait_for_selector("#modal-pin-setup.visible")
    page.fill("#new-pin", "1357")
    page.fill("#confirm-pin", "1357")
    page.click("#btn-save-pin")
    page.wait_for_selector("#modal-pin-setup:not(.visible)")

    # Now the "Remove my PIN" button should be revealed.
    page.wait_for_selector("#btn-clear-own-pin:not(.hidden)")
    page.once("dialog", lambda d: d.accept())
    page.click("#btn-clear-own-pin")
    # Button should re-acquire the hidden class once clear succeeds.
    # (Can't wait_for_selector(...".hidden") with default state=visible —
    # a hidden element will never match. Poll the class list instead.)
    page.wait_for_function(
        "() => document.getElementById('btn-clear-own-pin').classList.contains('hidden')",
        timeout=5000,
    )

    # Reload: no PIN screen.
    page.reload()
    page.wait_for_selector("#app", state="visible")


def test_admin_reset_users_pin_lets_them_unlock(
    configured: ConfiguredPayserver, page,
) -> None:
    """Admin uses Reset PIN UI on a user; that user can then log in and
    unlock using the admin-chosen PIN."""
    configured.admin._post_action(
        "create_user", username="dave", password="davepw1234", role="user"
    )

    _login(page, configured.handle.url, "admin", configured.admin_password)
    _open_settings(page)
    page.wait_for_selector("#users-list .list-item")
    page.evaluate(
        """() => {
            const items = document.querySelectorAll('#users-list .list-item');
            for (const it of items) {
                if (it.innerText.includes('dave')) {
                    const buttons = it.querySelectorAll('button');
                    for (const b of buttons) {
                        if (b.innerText.includes('Reset PIN')) { b.click(); return; }
                    }
                }
            }
        }"""
    )
    page.wait_for_selector("#modal-reset-user-pin.visible")
    page.fill("#rupin-new", "2580")
    page.click("#btn-confirm-reset-user-pin")
    page.wait_for_selector("#modal-reset-user-pin:not(.visible)")

    # Log out and log in as dave.
    page.once("dialog", lambda d: d.accept())
    # Use the API to log out the current admin session cleanly — Playwright
    # context already has the cookie; reset by clearing cookies on context.
    page.context.clear_cookies()

    _login(page, configured.handle.url, "dave", "davepw1234")
    # Reload to trigger PIN screen — server says dave has a PIN.
    page.reload()
    page.wait_for_selector("#lock-screen:not(.hidden)")
    for d in "2580":
        page.click(f'.pin-key[data-key="{d}"]')
    page.wait_for_selector("#app", state="visible", timeout=5000)
