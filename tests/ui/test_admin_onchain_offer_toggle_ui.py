"""Admin UI: the on-chain payment offer controls.

Covers the per-store "Offer on-chain to customers" tri-state (Bitcoin tab)
and the site-wide "Offer on-chain payments by default" toggle (Settings),
both added so a merchant can present a Lightning-only checkout while keeping
an xpub (submarine swaps still settle on-chain to it).
"""
from __future__ import annotations

import sqlite3
import time

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _store_offer(handle, store_id: str):
    with sqlite3.connect(handle.db_path) as db:
        row = db.execute(
            "SELECT onchain_offer_enabled FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    return row[0] if row else None


def _wait_offer(handle, store_id: str, expected: int, timeout_s: float = 10) -> None:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        if _store_offer(handle, store_id) == expected:
            return
        time.sleep(0.25)
    raise AssertionError(
        f"onchain_offer_enabled did not become {expected}; "
        f"last={_store_offer(handle, store_id)}"
    )


def _login_admin(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def test_per_store_onchain_offer_toggle_persists(
    configured: ConfiguredPayserver, page
) -> None:
    _login_admin(page, configured)
    page.locator('.nav-item[data-view="stores"]').click()
    page.wait_for_selector("#onchain-offer-override", state="visible")
    # Wait until the card is populated from dashboardData (site-default filled).
    page.wait_for_function(
        "() => { const el = document.getElementById('onchain-offer-site-default');"
        " return el && el.textContent.trim() && el.textContent.trim() !== '\\u2014'; }"
    )

    try:
        # Default: inherit the site default (column is -1 / NULL).
        assert _store_offer(configured.handle, configured.store_id) in (-1, None)

        # Force OFF -> onchange instant-saves -> column becomes 0, warning shows.
        page.select_option("#onchain-offer-override", "0")
        _wait_offer(configured.handle, configured.store_id, 0)
        assert page.locator("#onchain-offer-warning").is_visible(), \
            "the 'some wallets can't pay you' warning should show when off"

        # Force ON -> column becomes 1, warning hidden.
        page.select_option("#onchain-offer-override", "1")
        _wait_offer(configured.handle, configured.store_id, 1)
        assert not page.locator("#onchain-offer-warning").is_visible()
    finally:
        # Restore inherit so the shared session store doesn't leak state.
        with sqlite3.connect(configured.handle.db_path) as db:
            db.execute(
                "UPDATE stores SET onchain_offer_enabled = -1 WHERE id = ?",
                (configured.store_id,),
            )
            db.commit()


def test_site_wide_onchain_default_toggle_persists(
    configured: ConfiguredPayserver, page
) -> None:
    _login_admin(page, configured)
    page.locator('.nav-item[data-view="settings"]').click()
    # The styled toggle's <input> is CSS-hidden (only the slider shows), so wait
    # for it to be attached and interact with force.
    page.wait_for_selector("#onchain-site-enabled", state="attached")

    cb = page.locator("#onchain-site-enabled")
    try:
        # Default site setting is ON (checkbox checked after load).
        page.wait_for_function(
            "() => document.getElementById('onchain-site-enabled')?.checked === true"
        )

        # Turn the site default OFF by clicking the visible toggle label (the
        # <input> itself is CSS-hidden off-viewport). Warning should appear.
        page.locator("label.toggle:has(#onchain-site-enabled)").click()
        page.wait_for_function(
            "() => document.getElementById('onchain-site-enabled')?.checked === false"
        )
        assert page.locator("#onchain-site-warning").is_visible()
        page.click("#btn-save-onchain-site")

        # Reload + re-open Settings; the checkbox should come back unchecked.
        page.reload()
        page.wait_for_selector("#app", state="visible")
        page.locator('.nav-item[data-view="settings"]').click()
        page.wait_for_selector("#onchain-site-enabled", state="attached")
        page.wait_for_function(
            "() => document.getElementById('onchain-site-enabled')?.checked === false"
        )
    finally:
        # Restore the site default to ON so later tests keep on-chain enabled.
        # Config stores booleans JSON-encoded; a fresh PHP request re-reads the
        # config table (static cache is per-request under php -S).
        with sqlite3.connect(configured.handle.db_path) as db:
            db.execute(
                "UPDATE config SET value = 'true' WHERE key = 'onchain_payments_enabled'"
            )
            db.commit()
