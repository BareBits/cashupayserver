"""Admin password recovery — both mechanisms, over HTTP.

Mechanism 1 (emailed reset link): the token redemption itself needs working
SMTP and is covered by the PHP unit test; here we exercise the HTTP surface —
the request endpoint is generic (no account enumeration), a known recovery
email mints a token row, bad tokens are rejected, and the reset landing page
renders.

Mechanism 2 (file-based reset): full end-to-end — drop the trigger file in the
data dir, complete the reset, and confirm the password changed and the file was
deleted. The old password must keep working until the reset completes, then stop.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


# ---------------------------------------------------------------------------
# Mechanism 2: file-based reset
# ---------------------------------------------------------------------------


def test_file_reset_requires_the_trigger_file(configured: ConfiguredPayserver) -> None:
    """Without the trigger file the endpoint refuses (409) and nothing changes."""
    handle = configured.handle
    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "file_reset_set_password", "new_password": "newpass-12345"},
        timeout=15,
    )
    assert r.status_code == 409, r.text

    # Admin password is unchanged — original still logs in.
    fresh = requests.Session()
    r = fresh.post(
        f"{handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 200 and r.json()["success"] is True


def test_file_reset_end_to_end(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    flag = handle.data_dir / "reset-admin-password"
    assert not flag.exists()

    # Operator drops the (empty) trigger file.
    flag.write_text("")
    assert flag.exists()

    # Old password still valid before the reset is completed.
    pre = requests.Session()
    r = pre.post(
        f"{handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 200, "old password must work until the reset completes"

    # Complete the reset.
    new_pw = "file-reset-pw-98765"
    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "file_reset_set_password", "new_password": new_pw},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    assert r.json()["success"] is True

    # Trigger file deleted automatically.
    assert not flag.exists(), "trigger file must be deleted after a successful reset"

    # New password works; the original no longer does.
    fresh = requests.Session()
    r = fresh.post(
        f"{handle.url}/admin",
        data={"action": "login", "username": "admin", "password": new_pw},
        timeout=15,
    )
    assert r.status_code == 200 and r.json()["success"] is True, r.text

    r = fresh.post(
        f"{handle.url}/admin",
        data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
        timeout=15,
    )
    assert r.status_code == 401, "the original password must stop working"


def test_file_reset_rejects_weak_password_and_keeps_file(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    flag = handle.data_dir / "reset-admin-password"
    flag.write_text("")

    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "file_reset_set_password", "new_password": "short"},
        timeout=15,
    )
    assert r.status_code == 400, r.text
    # The operator can retry — the file is preserved on a rejected weak password.
    assert flag.exists()


# ---------------------------------------------------------------------------
# Mechanism 1: emailed reset link (HTTP surface)
# ---------------------------------------------------------------------------


def test_request_reset_is_generic_for_unknown_email(configured: ConfiguredPayserver) -> None:
    """Unknown address still returns success — no account enumeration."""
    handle = configured.handle
    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "request_password_reset", "email": "nobody@example.com"},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    assert r.json()["success"] is True


def test_request_reset_mints_token_for_known_email(configured: ConfiguredPayserver) -> None:
    """A matching admin recovery email mints a single-use token row (the email
    send itself fails silently without SMTP, but the token is persisted)."""
    handle = configured.handle
    configured.admin._post_action("set_recovery_email", email="ops@example.com")

    # The email landed on the admin row.
    with handle.db() as conn:
        row = conn.execute("SELECT email FROM users WHERE username = 'admin'").fetchone()
    assert row["email"] == "ops@example.com"

    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "request_password_reset", "email": "OPS@example.com"},  # case-insensitive
        timeout=15,
    )
    assert r.status_code == 200, r.text

    with handle.db() as conn:
        n = conn.execute(
            "SELECT COUNT(*) AS n FROM password_reset_tokens WHERE used_at IS NULL"
        ).fetchone()["n"]
    assert n >= 1, "a reset token should have been minted for the known email"


def test_reset_with_bad_token_is_rejected(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    s = requests.Session()
    r = s.post(
        f"{handle.url}/admin",
        data={"action": "reset_with_token", "token": "deadbeef", "new_password": "whatever-12345"},
        timeout=15,
    )
    assert r.status_code == 400, r.text


def test_reset_landing_page_reports_invalid_token(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    r = requests.get(f"{handle.url}/admin?action=reset&token=deadbeef", timeout=15)
    assert r.status_code == 200
    assert "invalid or has expired" in r.text.lower()


# ---------------------------------------------------------------------------
# Lock-screen UI: the "Forgot password?" modal must open ABOVE the lock screen
# ---------------------------------------------------------------------------


def test_forgot_password_modal_opens_above_lock_screen(
    configured: ConfiguredPayserver, browser
) -> None:
    """Regression: the lock screen sits at z-index 1000 with an opaque
    background, so the forgot-password modal (a .modal-overlay, base z-index
    200) opened *behind* it — visible:false to the eye, "clicking does nothing".
    Assert the modal is genuinely on top via elementFromPoint, not merely that
    its .visible class toggled."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    try:
        # Not logged in → the lock screen renders with the Forgot link.
        page.goto(f"{configured.handle.url}/admin", wait_until="networkidle")
        page.wait_for_selector("#forgot-password-link", state="visible")
        page.click("#forgot-password-link")
        page.wait_for_timeout(500)

        modal = page.locator("#modal-forgot-password")
        assert modal.is_visible(), "modal did not become visible"

        # The decisive check: the element at the modal's centre must belong to
        # the modal — i.e. it isn't occluded by the full-screen lock overlay.
        on_top = page.evaluate(
            """() => {
                const m = document.getElementById('modal-forgot-password');
                const box = m.querySelector('.modal').getBoundingClientRect();
                const el = document.elementFromPoint(
                    box.left + box.width / 2,
                    box.top + box.height / 2,
                );
                return !!(el && m.contains(el));
            }"""
        )
        assert on_top, "forgot-password modal is occluded by the lock screen (z-index regression)"

        # And the email field inside it is actually usable.
        assert page.locator("#fp-email").is_visible()
    finally:
        ctx.close()
