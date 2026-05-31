"""Non-admin users see the dashboard's Withdraw and Export buttons, but
clicking them must show a toast and refuse to open the modal — the server
already 403s the actions, this is the UX side of that gate.
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


def _create_staff(configured: ConfiguredPayserver) -> tuple[str, str]:
    configured.admin._post_action(
        "create_user", username="staffmod", password="staffmodpw1234", role="user"
    )
    return "staffmod", "staffmodpw1234"


def test_non_admin_withdraw_button_toasts_and_does_not_open(
    configured: ConfiguredPayserver, page,
) -> None:
    username, password = _create_staff(configured)
    _login(page, configured.handle.url, username, password)

    page.click("#btn-withdraw")
    # Toast appears with the admin-only message.
    page.wait_for_selector("#toast.show", timeout=3000)
    assert "admin" in page.locator("#toast").text_content().lower()

    # Withdraw modal stays closed.
    is_visible = page.evaluate(
        "() => document.getElementById('modal-withdraw').classList.contains('visible')"
    )
    assert is_visible is False, "withdraw modal must not open for non-admin"


def test_non_admin_export_button_toasts_and_does_not_open(
    configured: ConfiguredPayserver, page,
) -> None:
    username, password = _create_staff(configured)
    _login(page, configured.handle.url, username, password)

    page.click("#btn-export")
    page.wait_for_selector("#toast.show", timeout=3000)
    assert "admin" in page.locator("#toast").text_content().lower()

    is_visible = page.evaluate(
        "() => document.getElementById('modal-export').classList.contains('visible')"
    )
    assert is_visible is False, "export modal must not open for non-admin"


def test_non_admin_request_button_still_opens(
    configured: ConfiguredPayserver, page,
) -> None:
    """Receiving payments is a non-admin operation — the Request modal must
    still open."""
    username, password = _create_staff(configured)
    _login(page, configured.handle.url, username, password)

    page.click("#btn-request")
    page.wait_for_selector("#modal-request.visible", timeout=3000)


def test_admin_withdraw_modal_still_opens(
    configured: ConfiguredPayserver, page,
) -> None:
    """Sanity: the guard is keyed on role, admins keep full access."""
    _login(page, configured.handle.url, "admin", configured.admin_password)
    page.click("#btn-withdraw")
    page.wait_for_selector("#modal-withdraw.visible", timeout=3000)
