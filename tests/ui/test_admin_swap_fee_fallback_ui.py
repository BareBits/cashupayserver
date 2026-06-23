"""Admin UI: submarine-swap fee-too-high → mint fallback thresholds.

Drives the admin SPA to verify the new fee-fallback fields round-trip:
  * site-wide (Settings view): #swaps-fee-max-pct / #swaps-fee-max-sats save
    into the config table via action=save_swap_settings.
  * store-wide (Stores view): #store-swaps-fee-pct / #store-swaps-fee-sats save
    into the stores columns via action=save_store_swaps.

Run with: pytest tests/ui/test_admin_swap_fee_fallback_ui.py -v -s
"""
from __future__ import annotations

import json
import sqlite3

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _config_value(db_path: str, key: str):
    with sqlite3.connect(db_path) as db:
        row = db.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    if row is None:
        return None
    try:
        return json.loads(row[0])
    except (ValueError, TypeError):
        return row[0]


def test_site_fee_fallback_round_trip(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)

    # Settings view holds the site-wide submarine-swap card; opening it triggers
    # loadSwapSettings(), which renders the fee-fallback inputs.
    page.locator('.nav-item[data-view="settings"]').click()
    page.wait_for_selector("#swaps-fee-max-pct", state="visible")

    page.fill("#swaps-fee-max-pct", "7.5")
    page.fill("#swaps-fee-max-sats", "1500")
    page.click("#btn-save-swaps")
    # Give the POST + toast time to land.
    page.wait_for_timeout(2000)

    assert _config_value(configured.handle.db_path, "swaps_fee_fallback_max_pct") == 7.5
    assert _config_value(configured.handle.db_path, "swaps_fee_fallback_max_sats") == 1500

    # Clearing a field removes the site override so it inherits the config-file
    # value (the config key is deleted rather than set to null).
    page.fill("#swaps-fee-max-pct", "")
    page.click("#btn-save-swaps")
    page.wait_for_timeout(2000)
    assert _config_value(configured.handle.db_path, "swaps_fee_fallback_max_pct") is None


def test_store_fee_fallback_round_trip(configured: ConfiguredPayserver, page) -> None:
    _login(page, configured)

    page.locator('.nav-item[data-view="stores"]').click()
    page.wait_for_selector("#store-swaps-fee-pct", state="visible")

    page.fill("#store-swaps-fee-pct", "3.5")
    page.fill("#store-swaps-fee-sats", "900")
    page.click("#btn-save-store-swaps")
    page.wait_for_timeout(2000)

    with sqlite3.connect(configured.handle.db_path) as db:
        row = db.execute(
            "SELECT swaps_fee_fallback_max_pct, swaps_fee_fallback_max_sats "
            "FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()
    assert row is not None
    assert float(row[0]) == 3.5
    assert int(row[1]) == 900

    # Blanking both fields clears the per-store override (back to inherit).
    page.fill("#store-swaps-fee-pct", "")
    page.fill("#store-swaps-fee-sats", "")
    page.click("#btn-save-store-swaps")
    page.wait_for_timeout(2000)

    with sqlite3.connect(configured.handle.db_path) as db:
        row = db.execute(
            "SELECT swaps_fee_fallback_max_pct, swaps_fee_fallback_max_sats "
            "FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()
    assert row[0] is None and row[1] is None
