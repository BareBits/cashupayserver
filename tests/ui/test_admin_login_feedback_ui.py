"""Lock-screen sign-in feedback and the white mint-balance buttons.

A wrong password used to fire the global toast, which sits at z-index 300
under the opaque lock screen (z-index 1000): the attempt looked like it did
nothing. The message now renders inline inside the form, so these tests
assert not just that the text is present but that it is the element actually
painted at that spot (elementFromPoint), which is what the toast failed."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui

WHITE = "rgb(255, 255, 255)"
BLACK = "rgb(0, 0, 0)"
HOVER_GREY = "rgb(240, 240, 240)"


def _open_lock_screen(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.wait_for_selector("#password-submit", state="visible")


def _error_text(page) -> str:
    return page.locator("#login-error").inner_text().strip()


def _error_is_painted_on_top(page) -> bool:
    """True when the element under the centre of #login-error is the error
    itself, i.e. nothing (such as the lock screen) covers it."""
    return page.evaluate(
        """() => {
            const el = document.getElementById('login-error');
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) return false;
            const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return hit === el || el.contains(hit);
        }"""
    )


def _focused_id(page) -> str:
    return page.evaluate("() => document.activeElement && document.activeElement.id")


def test_wrong_password_then_lockout_shows_inline_errors(configured: ConfiguredPayserver, page) -> None:
    """Per-test server: the lockout counters are global per client address."""
    _open_lock_screen(page, configured)
    assert page.locator("#login-error").is_hidden()  # :empty collapses the slot

    page.fill("#username-input", "admin")
    page.fill("#password-input", "definitely-wrong")
    page.click("#password-submit")
    page.wait_for_function("() => document.getElementById('login-error').textContent.trim() !== ''")

    assert _error_text(page) == "Invalid username or password"
    assert page.locator("#login-error").is_visible()
    assert _error_is_painted_on_top(page)
    # Lock screen stays up, password is wiped for a retry, username kept.
    assert page.locator("#lock-screen").is_visible()
    assert page.locator("#app").is_hidden()
    assert page.input_value("#password-input") == ""
    assert page.input_value("#username-input") == "admin"
    assert _focused_id(page) == "password-input"

    # Typing again clears the stale message.
    page.type("#password-input", "x")
    assert _error_text(page) == ""
    page.fill("#password-input", "")

    # Keep guessing (via Enter this time) until the lockout trips; the server's
    # countdown text must reach the form instead of the generic message.
    locked = False
    for i in range(8):
        page.fill("#password-input", f"wrong-{i}")
        page.press("#password-input", "Enter")
        page.wait_for_function("() => document.getElementById('login-error').textContent.trim() !== ''")
        text = _error_text(page)
        if text.startswith("Too many failed attempts"):
            locked = True
            assert "seconds" in text, text
            break
        assert text == "Invalid username or password", text
    assert locked, "expected the lockout countdown to show within 8 attempts"
    assert _error_is_painted_on_top(page)
    assert page.locator("#lock-screen").is_visible()


def test_empty_fields_are_rejected_without_a_request(shared_configured: ConfiguredPayserver, page) -> None:
    """Client-side guards: an empty username or password never reaches the
    server (so it can't spend a lockout attempt), and a good password
    afterwards signs in with the message gone."""
    _open_lock_screen(page, shared_configured)
    login_posts: list = []
    page.on(
        "request",
        lambda req: login_posts.append(req) if req.method == "POST" and "action=login" in (req.post_data or "") else None,
    )

    page.fill("#username-input", "admin")
    page.fill("#password-input", "")
    page.click("#password-submit")
    assert _error_text(page) == "Password is required"
    assert _focused_id(page) == "password-input"

    page.fill("#username-input", "")
    page.fill("#password-input", "anything")
    page.click("#password-submit")
    assert _error_text(page) == "Username is required"
    assert _focused_id(page) == "username-input"
    assert login_posts == []

    page.fill("#username-input", "admin")
    page.fill("#password-input", shared_configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    assert len(login_posts) == 1
    assert _error_text(page) == ""


def test_mint_balance_buttons_are_white_with_black_text(shared_configured: ConfiguredPayserver, page) -> None:
    _open_lock_screen(page, shared_configured)
    page.fill("#password-input", shared_configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.wait_for_selector("#btn-withdraw", state="visible")

    styles = page.eval_on_selector_all(
        ".balance-card .balance-btn",
        """els => els.map(e => {
            const cs = getComputedStyle(e);
            return { id: e.id, bg: cs.backgroundColor, color: cs.color, border: cs.borderStyle };
        })""",
    )
    ids = sorted(s["id"] for s in styles)
    assert ids == ["btn-request", "btn-request-simple", "btn-withdraw"], ids
    for s in styles:
        assert s["bg"] == WHITE, s
        assert s["color"] == BLACK, s
        assert s["border"] == "none", s

    # The icon strokes follow currentColor, so they go black with the label.
    assert page.evaluate(
        "() => getComputedStyle(document.querySelector('#btn-withdraw svg')).color"
    ) == BLACK

    page.hover("#btn-withdraw")
    page.wait_for_function(
        "() => getComputedStyle(document.getElementById('btn-withdraw')).backgroundColor === 'rgb(240, 240, 240)'"
    )
    assert page.evaluate(
        "() => getComputedStyle(document.getElementById('btn-request')).backgroundColor"
    ) == WHITE
