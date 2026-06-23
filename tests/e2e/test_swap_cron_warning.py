"""Browser e2e for the Submarine Swaps stale-cron warning on the site-settings
page.

Reverse swaps are claimed only from cron, so the invoice-generation gate
suppresses swaps when external cron is stale. The settings card surfaces this
with a bold warning so the operator knows swaps are currently disabled and
where to fix it. This test drives the two states directly via the config table:

  - cron stale (last run beyond the threshold) → warning visible
  - cron fresh (just ran)                      → warning hidden
"""
from __future__ import annotations

import json
import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD

# Mirror of Background::SWAP_CRON_STALE_THRESHOLD_SECS.
THRESHOLD_SECS = 3600


def _set_config(db_path: str, key: str, value) -> None:
    """Upsert a JSON-encoded config value, matching Config::set's storage."""
    conn = sqlite3.connect(db_path)
    try:
        now = int(time.time())
        conn.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?,?,?,?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value), now, now),
        )
        conn.commit()
    finally:
        conn.close()


def _del_config(db_path: str, key: str) -> None:
    conn = sqlite3.connect(db_path)
    try:
        conn.execute("DELETE FROM config WHERE key = ?", (key,))
        conn.commit()
    finally:
        conn.close()


@pytest.fixture
def admin_page(configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page plus the configured payserver."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, configured
    ctx.close()


def _open_settings_and_load(page, base):
    page.goto(f"{base}/admin/settings", wait_until="networkidle")
    # loadSwapSettings() runs on view entry; call it again so the assertion does
    # not race the initial fetch, then give the fetch time to land.
    page.evaluate("() => (typeof loadSwapSettings === 'function') && loadSwapSettings()")
    page.wait_for_timeout(1200)


def test_stale_cron_shows_warning(admin_page):
    page, configured = admin_page
    db = configured.handle.db_path
    _set_config(db, "last_external_cron_at", int(time.time()) - (THRESHOLD_SECS + 600))
    _del_config(db, "last_external_cron_swaps_at")

    _open_settings_and_load(page, configured.handle.url)

    visible = page.evaluate(
        "() => { const e = document.getElementById('swaps-cron-stale-warning');"
        " return !!e && e.style.display !== 'none'; }"
    )
    assert visible, "stale cron should reveal the swaps stale-cron warning banner"
    text = page.evaluate(
        "() => document.getElementById('swaps-cron-stale-warning').innerText"
    )
    assert "disabled" in text.lower()
    # Points the operator at the cron-setup section.
    assert page.evaluate(
        "() => !!document.querySelector('#swaps-cron-stale-warning a[href=\"#card-cron-url\"]')"
    )


def test_fresh_cron_hides_warning(admin_page):
    page, configured = admin_page
    db = configured.handle.db_path
    _set_config(db, "last_external_cron_at", int(time.time()))

    _open_settings_and_load(page, configured.handle.url)

    hidden = page.evaluate(
        "() => { const e = document.getElementById('swaps-cron-stale-warning');"
        " return !!e && e.style.display === 'none'; }"
    )
    assert hidden, "fresh cron should keep the swaps stale-cron warning hidden"
