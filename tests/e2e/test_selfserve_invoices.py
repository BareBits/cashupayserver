"""Browser + HTTP e2e for self-serve invoices (the public /pay/{storeId} page).

Covers the customer-facing surface:
  - When self-serve is disabled (the default) the page 404s, so store IDs and
    the feature itself aren't discoverable.
  - When enabled site-wide, the form renders and a customer can create + be
    redirected to a real, payable invoice.
  - Untrusted input is enforced: an amount above the per-store maximum is
    rejected with a clear error instead of creating an oversized invoice.

The enable toggle + max are seeded directly in the DB (the same shape Config /
SelfServe write) to keep the test focused on the public page rather than the
admin UI; the resolution + validation logic itself is unit-tested in
tests/php/test_selfserve_resolution.php and test_selfserve_validation.php.
"""
from __future__ import annotations

import json
import time

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def _enable_site_selfserve(configured: ConfiguredPayserver, enabled: bool = True) -> None:
    now = int(time.time())
    with configured.handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            ("selfserve_enabled", json.dumps(enabled), now, now),
        )


def _set_store_max(configured: ConfiguredPayserver, max_sats) -> None:
    with configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_max_sats = ? WHERE id = ?",
            (max_sats, configured.store_id),
        )


def _set_store_currency(configured: ConfiguredPayserver, currency: str) -> None:
    with configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET default_currency = ? WHERE id = ?",
            (currency, configured.store_id),
        )


def test_pay_page_404_when_disabled(configured: ConfiguredPayserver) -> None:
    # Disabled by default → generic 404 (no leak of the store or the feature).
    r = requests.get(
        f"{configured.handle.url}/pay/{configured.store_id}", timeout=15, allow_redirects=False
    )
    assert r.status_code == 404, r.text


def test_pay_page_404_unknown_store(configured: ConfiguredPayserver) -> None:
    _enable_site_selfserve(configured)
    r = requests.get(
        f"{configured.handle.url}/pay/store_does_not_exist", timeout=15, allow_redirects=False
    )
    assert r.status_code == 404, r.text


def test_pay_page_renders_when_enabled(configured: ConfiguredPayserver) -> None:
    _enable_site_selfserve(configured)
    r = requests.get(f"{configured.handle.url}/pay/{configured.store_id}", timeout=15)
    assert r.status_code == 200, r.text
    assert "Continue to payment" in r.text
    # Sat-only store: the max hint is shown.
    assert "Maximum" in r.text


def test_pay_page_defaults_to_store_currency(
    configured: ConfiguredPayserver, browser
) -> None:
    # A fiat store offers [sat, USD]; the selector must pre-select the store's
    # default display currency (USD), not sat.
    _enable_site_selfserve(configured)
    _set_store_currency(configured, "USD")
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(
            f"{configured.handle.url}/pay/{configured.store_id}", wait_until="networkidle"
        )
        # The currency <select> is only rendered for fiat stores.
        assert page.locator("#currency").count() == 1, "fiat store should show a currency selector"
        assert page.eval_on_selector("#currency", "el => el.value") == "USD"
    finally:
        ctx.close()
        _set_store_currency(configured, "sat")


def test_over_max_amount_rejected(configured: ConfiguredPayserver) -> None:
    _enable_site_selfserve(configured)
    _set_store_max(configured, 1000)
    # Post an amount above the per-store cap; expect the form back with an error
    # and NO redirect to a created invoice.
    r = requests.post(
        f"{configured.handle.url}/pay/{configured.store_id}",
        data={"amount": "5000", "currency": "sat", "notes": ""},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 200, r.text
    assert "exceeds the maximum" in r.text.lower() or "maximum" in r.text.lower()
    _set_store_max(configured, None)


def test_create_and_redirect_to_payment(configured: ConfiguredPayserver, browser) -> None:
    _enable_site_selfserve(configured)
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(
            f"{configured.handle.url}/pay/{configured.store_id}", wait_until="networkidle"
        )
        page.fill("#amount", "1500")
        page.fill("#notes", "e2e self-serve test")
        page.click("button[type=submit]")
        # Should land on the regular payment display page for the new invoice.
        page.wait_for_url("**/payment.php?id=*", timeout=15000)
        assert "payment.php?id=" in page.url
    finally:
        ctx.close()

    # The invoice exists, belongs to this store, is unpaid, and carries the note.
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT store_id, status, amount, currency, metadata FROM invoices "
            "ORDER BY created_at DESC LIMIT 1"
        ).fetchone()
    assert row is not None, "an invoice should have been created"
    assert row["store_id"] == configured.store_id
    assert row["status"] == "New"
    assert str(row["amount"]) == "1500"
    assert (row["currency"] or "").upper() == "SAT"
    assert "e2e self-serve test" in (row["metadata"] or "")


def test_admin_invoices_view_shows_selfserve_link(configured: ConfiguredPayserver, browser) -> None:
    # When self-serve is on for the store, the Invoices view surfaces a banner
    # with the public link so operators can discover + share it.
    _enable_site_selfserve(configured)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{configured.handle.url}/admin/invoices", wait_until="networkidle")
        page.wait_for_timeout(1500)
        banner = page.locator("#card-selfserve-link")
        assert banner.is_visible(), "self-serve link banner should be visible when enabled"
        link = page.locator("#invoices-selfserve-link").input_value()
        assert configured.store_id in link, f"banner link should target the store, got {link}"
    finally:
        ctx.close()


def test_store_settings_info_no_unit_and_selfserve_link(
    configured: ConfiguredPayserver, browser
) -> None:
    # The store-info block drops the always-"sat" Unit row and, when self-serve
    # is effectively on, surfaces the public link with a Copy button.
    _enable_site_selfserve(configured)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{configured.handle.url}/admin/stores", wait_until="networkidle")
        # loadDashboard auto-selects a store, populates dashboardData.selfserve
        # and runs refreshStoreSelfServeCard (which toggles the info-grid link);
        # loadStoreSettings fills the store-info block.
        page.evaluate("async () => { await loadDashboard(); await loadStoreSettings(); }")
        page.wait_for_timeout(1000)

        # The Unit row is gone entirely (element removed, not just hidden).
        assert page.locator("#store-settings-unit").count() == 0, "Unit row should be removed"

        # Self-serve is inherited-on for a payment-capable store → link visible.
        row = page.locator("#store-info-selfserve-row")
        assert row.is_visible(), "self-serve link row should show when enabled"
        link = page.locator("#store-info-selfserve-link").input_value()
        assert configured.store_id in link, f"link should target the store, got {link}"
        assert page.locator("#btn-copy-store-info-selfserve").is_visible(), "Copy button present"
    finally:
        ctx.close()


def test_store_settings_info_hides_selfserve_link_when_off(
    configured: ConfiguredPayserver, browser
) -> None:
    # With self-serve disabled site-wide (the default) and no override, the
    # info-grid link row stays hidden.
    _enable_site_selfserve(configured, enabled=False)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    try:
        page.goto(f"{configured.handle.url}/admin/stores", wait_until="networkidle")
        page.evaluate("async () => { await loadDashboard(); await loadStoreSettings(); }")
        page.wait_for_timeout(1000)
        assert not page.locator("#store-info-selfserve-row").is_visible(), (
            "self-serve link row should be hidden when the feature is off"
        )
    finally:
        ctx.close()


def _login_page(configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page in a fresh context."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    return ctx, ctx.new_page()


def test_admin_save_global_selfserve_shows_success(
    configured: ConfiguredPayserver, browser
) -> None:
    # Regression: the save handlers used to call a non-existent loadDashboard*()
    # in their success branch, so the ReferenceError was swallowed by the same
    # try/catch and the user saw "Failed to save self-serve settings" even though
    # the server returned {"success":true} and the setting persisted. Drive the
    # real button and assert the SUCCESS toast wins (not the error toast).
    ctx, page = _login_page(configured, browser)
    try:
        page.goto(f"{configured.handle.url}/admin/settings", wait_until="networkidle")
        # The global-save refresh path is gated on a selected store; loading the
        # dashboard auto-selects one, matching what an operator sees.
        page.evaluate("async () => { await loadDashboard(); }")
        assert page.evaluate("currentStoreId") is not None, "a store should be selected"

        page.click("#btn-save-selfserve")
        # Let the (previously throwing) success branch settle so we read the
        # final, stable toast rather than the momentary one.
        page.wait_for_timeout(1000)
        toast_text = page.text_content("#toast")
        toast_class = page.get_attribute("#toast", "class") or ""
        assert toast_text == "Self-serve settings saved!", toast_text
        assert "error" not in toast_class.split(), toast_class
    finally:
        ctx.close()


def test_admin_save_store_selfserve_shows_success(
    configured: ConfiguredPayserver, browser
) -> None:
    # Same regression as above for the per-store save button, whose success
    # branch unconditionally refreshes the dashboard.
    ctx, page = _login_page(configured, browser)
    try:
        page.goto(f"{configured.handle.url}/admin/stores", wait_until="networkidle")
        page.evaluate("async () => { await loadDashboard(); }")
        page.wait_for_timeout(300)
        # Force the override off so neither the client- nor server-side
        # payment-capability check is in play — we're testing the save/refresh
        # wiring, not the resolution rules (those are covered elsewhere).
        page.select_option("#store-selfserve-override", "0")

        page.click("#btn-save-store-selfserve")
        page.wait_for_timeout(1000)
        toast_text = page.text_content("#toast")
        toast_class = page.get_attribute("#toast", "class") or ""
        assert toast_text == "Store self-serve setting saved", toast_text
        assert "error" not in toast_class.split(), toast_class
    finally:
        ctx.close()
