"""Browser e2e for the manual-update banner + Auto-update card status.

Covers the user-facing surface of the manual-update feature:
  - The "update available" banner appears (with the version) when the cached
    availability verdict says a newer build exists — on EVERY admin view, not
    just the dashboard (auto-update is off by default, so the nudge must not
    be missable) — and stays hidden when the install is current.
  - The Auto-update card's opt-in toggle persists the DB-backed
    auto_update_enabled key both ways, and the notifications card's
    "update available" email checkbox defaults on.
  - The Auto-update settings card reflects the availability verdict and exposes
    an "Update now" button.
  - In the dev/test stack the updater is intentionally disabled (the
    .updater_disabled sentinel), so the manual-update API refuses with a clear
    "disabled" reason rather than overlaying the dev checkout.

The actual download/overlay/health-verify path is covered hermetically by the
PHP tests (test_updater_force_apply.php, test_updater_check_for_update.php);
applying a real update against a live server is out of scope here (and unsafe in
the dev stack, which is exactly what the disabled-environment guard enforces).
"""
from __future__ import annotations

import json
import time

import pytest
import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.payserver import PayserverHandle


def _set_config(handle: PayserverHandle, key: str, value) -> None:
    """Seed a config row the way Config::set would (non-strings JSON-encoded)."""
    now = int(time.time())
    with handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value), now, now),
        )


@pytest.fixture
def admin_page(configured: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, configured
    ctx.close()


def test_banner_shows_when_update_available(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "latest_sha": "f" * 40,
        "current_version": "0.0-old",
        "blocked": False,
        "checked_at": int(time.time()),
    })

    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
    page.wait_for_timeout(1500)

    banner = page.locator("#update-available-banner")
    assert banner.is_visible(), "banner should be visible when an update is available"
    text = banner.inner_text()
    assert "update is available" in text.lower()
    assert "9.9.9-test" in text, "banner should name the available version"
    # The security/urgency wording the user asked for.
    assert "security" in text.lower()
    assert page.locator("#btn-update-now-banner").is_visible()


def test_banner_shows_on_every_admin_view(admin_page):
    """The banner lives above the view containers, so switching to settings,
    invoices, etc. must keep it on screen — an operator who never opens the
    dashboard still sees the nudge."""
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "latest_sha": "f" * 40,
        "current_version": "0.0-old",
        "blocked": False,
        "checked_at": int(time.time()),
    })

    for view in ("settings", "invoices", "stores"):
        page.goto(f"{configured.handle.url}/admin/{view}", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#update-available-banner")
        assert banner.is_visible(), f"banner should be visible on the {view} view"
        assert "9.9.9-test" in banner.inner_text(), (
            f"banner on the {view} view should name the available version"
        )


def test_auto_update_toggle_persists_both_ways(admin_page):
    """The Auto-update card's opt-in toggle writes the DB-backed
    auto_update_enabled key that Updater::isAutoUpdateEnabled consults. It
    starts off (the default), saves on on change, and saves off again."""
    page, configured = admin_page

    def gate_state():
        return page.evaluate(
            """async (base) => {
                const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
                const d = await r.json();
                return { enabled: d.auto_update_enabled, source: d.auto_update_source };
            }""",
            configured.handle.url,
        )

    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1500)

    toggle = page.locator("#auto-update-enabled")
    slider = page.locator("#auto-update-enabled + .toggle-slider")
    assert not toggle.is_checked(), "automatic updates must default to off"
    assert gate_state() == {"enabled": False, "source": None}

    # The <input> is CSS-hidden (opacity 0); the slider is the click target.
    slider.click()
    page.wait_for_timeout(800)
    assert gate_state() == {"enabled": True, "source": "db"}, (
        "flipping the toggle on must persist the DB opt-in"
    )

    # Survives a reload (state comes from update_status, not the DOM).
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1500)
    toggle = page.locator("#auto-update-enabled")
    assert toggle.is_checked(), "the saved opt-in must be reflected after reload"

    page.locator("#auto-update-enabled + .toggle-slider").click()
    page.wait_for_timeout(800)
    assert gate_state() == {"enabled": False, "source": None}, (
        "flipping the toggle off must close the gate again"
    )


def test_update_email_checkbox_defaults_on(admin_page):
    """The notifications card's per-type 'update available' checkbox defaults
    ON (unlike the other per-type toggles) and persists an explicit off."""
    page, configured = admin_page
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1500)

    box = page.locator("#notifications-update-available")
    assert box.is_checked(), "the update email checkbox must default to on"

    # CSS-hidden checkbox; the slider is the click target.
    page.locator("#notifications-update-available + .toggle-slider").click()
    page.click("#btn-save-notifications")
    page.wait_for_timeout(800)

    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1500)
    assert not page.locator("#notifications-update-available").is_checked(), (
        "an explicit off must persist"
    )

    # Leave the store the way we found it.
    page.locator("#notifications-update-available + .toggle-slider").click()
    page.click("#btn-save-notifications")
    page.wait_for_timeout(800)


def test_banner_hidden_when_current(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": False,
        "channel": "main",
        "checked_at": int(time.time()),
    })

    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")
    page.wait_for_timeout(1500)

    assert not page.locator("#update-available-banner").is_visible(), \
        "banner should stay hidden when the install is current"


def test_manual_update_refused_in_dev_stack(admin_page):
    """The dev/test stack sets .updater_disabled; the manual-update endpoint
    must refuse with reason 'disabled' instead of overlaying the checkout."""
    page, configured = admin_page
    page.goto(f"{configured.handle.url}/admin/dashboard", wait_until="networkidle")

    # update_status should report the manual-blocked reason.
    status = page.evaluate(
        """async (base) => {
            const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
            return await r.json();
        }""",
        configured.handle.url,
    )
    assert status.get("manual_blocked") == "disabled", status

    # Starting a manual update is refused, not silently applied. Send the CSRF
    # token from the page's meta tag, same as the in-app button flow does.
    result = page.evaluate(
        """async (base) => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const r = await fetch(base + '/admin', {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                },
                body: 'action=start_manual_update',
            });
            return await r.json();
        }""",
        configured.handle.url,
    )
    assert result.get("success") is False, result
    assert result.get("error") == "disabled", result


def test_update_now_button_present_in_settings_card(admin_page):
    page, configured = admin_page
    _set_config(configured.handle, "updater_available", {
        "available": True,
        "channel": "main",
        "latest_version": "9.9.9-test",
        "checked_at": int(time.time()),
    })
    page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1500)

    assert page.evaluate("!!document.getElementById('btn-update-now')")
    avail = page.locator("#auto-update-availability")
    assert "9.9.9-test" in avail.inner_text()
    # Button is disabled in the dev stack (manual_blocked == 'disabled').
    assert page.evaluate("document.getElementById('btn-update-now').disabled") is True


def _channel(page, base):
    return page.evaluate(
        """async (base) => {
            const r = await fetch(base + '/admin?api=update_status', { credentials: 'same-origin' });
            return (await r.json()).channel;
        }""",
        base,
    )


def test_save_channel_button_persists(admin_page):
    """Regression: the Save-channel button posts `action` in the body via
    postWithCsrf. The earlier query-string form returned 'Unknown action', so
    the channel never changed. Drive the real button and confirm it sticks."""
    page, configured = admin_page
    base = configured.handle.url
    page.goto(f"{base}/admin/settings", wait_until="networkidle")
    page.wait_for_timeout(1200)

    assert _channel(page, base) == "main"
    page.select_option("#auto-update-channel", "testing")
    page.click("#btn-save-update-channel")
    page.wait_for_timeout(800)
    assert _channel(page, base) == "testing", "Save channel button should persist the choice"

    # Reset so the stack is left on the stable channel.
    page.select_option("#auto-update-channel", "main")
    page.click("#btn-save-update-channel")
    page.wait_for_timeout(800)
    assert _channel(page, base) == "main"
