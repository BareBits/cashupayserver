"""Idle login timeout: the Settings control, the persistent cookie, and the
relock-with-notice when a session has expired.

Sessions used to live and die by the host's PHP garbage collector (24 idle
minutes by default) and a browser-session cookie. Now the admin picks a
timeout (default 7 days) under Settings → My Account; the cookie lives that
long and slides forward on activity, and the server ends an idle session
itself. When that happens mid-use the SPA relocks with an explanation
instead of leaving every button silently dead.

The expiry is simulated by rewinding the session file's activity stamp:
sessions are stored under <data dir>/sessions precisely so the host's
sweeper cannot reach them, and the file being there is itself an assertion.
"""
from __future__ import annotations

import os
import re
import time
from pathlib import Path

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards, wait_for_dashboard_settled

pytestmark = pytest.mark.ui

SEVEN_DAYS = 7 * 86400
TIMED_OUT = "timed out"


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#username-input", "admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    # Let the sign-in's dashboard load land before navigating: a late render
    # can otherwise undo the card expansion below.
    wait_for_dashboard_settled(page)


def _open_settings(page) -> None:
    page.click('.nav-item[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#view-settings.active")
    page.wait_for_selector("#login-timeout-row:not(.hidden)")
    page.wait_for_function(
        "() => document.getElementById('login-timeout-select').dataset.loaded === '1'"
    )


def _session_cookie(page) -> dict:
    for c in page.context.cookies():
        if c["name"] == "cashupay_session":
            return c
    raise AssertionError("no cashupay_session cookie in the browser context")


def _session_file(page, configured: ConfiguredPayserver) -> Path:
    path = Path(configured.handle.data_dir) / "sessions" / f"sess_{_session_cookie(page)['value']}"
    assert path.is_file(), f"session file not under the data directory: {path}"
    return path


def _rewind_activity(page, path: Path, seconds: int = 400 * 86400) -> None:
    """Make the session look idle for `seconds` by editing its activity stamp.

    PHP writes the session back at the end of every request, so an in-flight
    admin call would overwrite the edit: let the page go quiet first, then
    check the stale stamp is still what the server will read."""
    if not os.access(path, os.W_OK):
        pytest.skip(f"session file not writable from the test process: {path}")
    page.wait_for_load_state("networkidle")
    raw = path.read_text(encoding="latin-1")
    assert "last_activity|i:" in raw, raw[:200]
    stale = str(int(time.time()) - seconds)
    raw = re.sub(r"last_activity\|i:\d+;", f"last_activity|i:{stale};", raw)
    raw = re.sub(r"login_time\|i:\d+;", f"login_time|i:{stale};", raw)
    path.write_text(raw, encoding="latin-1")
    page.wait_for_timeout(700)
    assert f"last_activity|i:{stale};" in path.read_text(encoding="latin-1"), "a late request rewrote the session"


def test_setting_defaults_to_7_days_and_saves(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)
    _open_settings(page)

    select = page.locator("#login-timeout-select")
    assert select.input_value() == str(SEVEN_DAYS)
    labels = select.locator("option").all_text_contents()
    assert labels[0] == "15 minutes" and labels[-1] == "30 days" and "7 days" in labels

    page.select_option("#login-timeout-select", "900")
    page.click("#btn-save-login-timeout")
    page.wait_for_selector(".toast:has-text('Login timeout set to 15 minutes')", state="attached")

    # Persisted server-side and read back by the admin API.
    r = configured.admin.s.get(f"{configured.handle.url}/admin.php?api=get_login_timeout", timeout=15)
    assert r.status_code == 200, r.text
    assert r.json()["seconds"] == 900

    # Survives a reload.
    page.reload()
    page.wait_for_selector("#app", state="visible")
    _open_settings(page)
    assert page.locator("#login-timeout-select").input_value() == "900"


def test_session_cookie_lives_for_the_timeout(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)
    cookie = _session_cookie(page)
    # Not a browser-session cookie any more: it carries an expiry ~7 days out.
    assert cookie["expires"] > 0
    assert abs(cookie["expires"] - (time.time() + SEVEN_DAYS)) < 600
    assert cookie["httpOnly"] is True
    _session_file(page, configured)  # stored under <data dir>/sessions


def test_idle_session_relocks_with_notice_and_signs_back_in(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)
    assert page.locator("#lock-notice").is_hidden()
    _rewind_activity(page, _session_file(page, configured))

    # The next admin call comes back 401 → relock, with the reason shown.
    page.click('.nav-item[data-view="invoices"]')
    page.wait_for_selector("#lock-screen:not(.hidden)", state="visible")
    assert page.locator("#app").is_hidden()
    notice = page.locator("#lock-notice")
    assert notice.is_visible()
    assert TIMED_OUT in notice.inner_text()
    assert page.locator("#login-error").is_hidden()
    assert page.input_value("#password-input") == ""

    # Signing back in clears the notice and restores the app.
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    assert page.locator("#lock-screen").is_hidden()
    assert page.locator("#lock-notice").inner_text().strip() == ""

    # And the fresh session works: a settings load succeeds.
    _open_settings(page)
    assert page.locator("#login-timeout-select").input_value() == str(SEVEN_DAYS)


def test_page_load_on_expired_session_shows_notice(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)
    _rewind_activity(page, _session_file(page, configured))

    page.reload()
    page.wait_for_selector("#password-submit", state="visible")
    assert page.locator("#app").is_hidden()
    assert TIMED_OUT in page.locator("#lock-notice").inner_text()

    # A plain not-signed-in visit carries no notice.
    page.context.clear_cookies()
    page.goto(f"{configured.handle.url}/admin")
    page.wait_for_selector("#password-submit", state="visible")
    assert page.locator("#lock-notice").is_hidden()


def test_shorter_timeout_applies_to_the_current_session(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)
    _open_settings(page)
    page.select_option("#login-timeout-select", "900")
    page.click("#btn-save-login-timeout")
    page.wait_for_selector(".toast:has-text('Login timeout set to 15 minutes')", state="attached")

    # 20 idle minutes was fine at 7 days; at 15 minutes it is over.
    _rewind_activity(page, _session_file(page, configured), seconds=20 * 60)
    page.click('.nav-item[data-view="invoices"]')
    page.wait_for_selector("#lock-screen:not(.hidden)", state="visible")
    assert TIMED_OUT in page.locator("#lock-notice").inner_text()
