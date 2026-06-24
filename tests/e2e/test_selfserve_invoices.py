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
