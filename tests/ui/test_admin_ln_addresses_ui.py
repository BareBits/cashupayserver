"""Admin UI: ordered Lightning-address fallback chain.

Drives the per-store Auto-Cashout card: add several Lightning addresses,
reorder them with the up/down arrows, save, and verify the store_ln_addresses
table reflects the on-screen priority order. Also checks that the save button
enforces client-side validation (duplicate + malformed address).

Saving now runs the server-side LUD-21 gate, so the persistence tests use the
`configured_with_lnurlp` stack — its CASHU_LNURL_URL_TEMPLATE routes every
address to the mock LNURL host, which advertises a verify URL. The last test
runs against the plain `configured` stack (no LNURL host reachable) and
asserts the gate blocks the save with a visible error.

Run with: pytest tests/ui/test_admin_ln_addresses_ui.py -v -s
"""
from __future__ import annotations

import sqlite3

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui

ADDR_A = "alice@wallet.test"
ADDR_B = "bob@wallet.test"
ADDR_C = "carol@wallet.test"


def _ln_addresses(handle, store_id: str) -> list[str]:
    with sqlite3.connect(handle.db_path) as db:
        rows = db.execute(
            "SELECT address FROM store_ln_addresses WHERE store_id = ? "
            "ORDER BY position ASC",
            (store_id,),
        ).fetchall()
    return [r[0] for r in rows]


def _open_auto_cashout(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.locator('.nav-item[data-view="stores"]').click()
    page.wait_for_selector("#aw-store", state="visible")
    # Select Lightning-address mode so the address group is shown. The list div
    # (#auto-melt-address-list) starts empty and thus has zero size, which
    # Playwright treats as hidden — so wait on the always-present Add button.
    page.locator('#aw-store .aw-col[data-aw-mode="0"]').click()
    page.wait_for_selector("#btn-add-ln-address", state="visible")


def _fill_rows(page, addresses: list[str]) -> None:
    """Add a row per address (via the + button) and fill each input."""
    for _ in addresses:
        page.click("#btn-add-ln-address")
    inputs = page.locator("#auto-melt-address-list input.ln-address-input")
    for i, addr in enumerate(addresses):
        inputs.nth(i).fill(addr)


def test_add_reorder_and_save_chain(configured_with_lnurlp: ConfiguredPayserver, page) -> None:
    configured = configured_with_lnurlp
    _open_auto_cashout(page, configured)

    # Add three addresses in order A, B, C.
    _fill_rows(page, [ADDR_A, ADDR_B, ADDR_C])

    # Enable auto-melt + a threshold so the save validation passes.
    # Enable auto-melt. The toggle is a CSS-hidden checkbox positioned off the
    # viewport, so set it directly rather than clicking; its value is only read
    # on save (no change handler).
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", "100")

    # Move the third row (C) up one → order becomes A, C, B.
    rows = page.locator("#auto-melt-address-list .ln-address-row")
    rows.nth(2).locator('button[title="Move up"]').click()

    page.click("#btn-save-auto-melt")
    # Wait for the save to land + dashboard reload.
    page.wait_for_timeout(2500)

    assert _ln_addresses(configured.handle, configured.store_id) == [
        ADDR_A,
        ADDR_C,
        ADDR_B,
    ], "saved chain should match on-screen priority order after reorder"


def test_remove_row(configured_with_lnurlp: ConfiguredPayserver, page) -> None:
    configured = configured_with_lnurlp
    _open_auto_cashout(page, configured)
    _fill_rows(page, [ADDR_A, ADDR_B])
    # Enable auto-melt. The toggle is a CSS-hidden checkbox positioned off the
    # viewport, so set it directly rather than clicking; its value is only read
    # on save (no change handler).
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", "100")

    # Remove the first row (A) → only B remains.
    rows = page.locator("#auto-melt-address-list .ln-address-row")
    rows.nth(0).locator('button[title="Remove"]').click()

    page.click("#btn-save-auto-melt")
    page.wait_for_timeout(2500)

    assert _ln_addresses(configured.handle, configured.store_id) == [ADDR_B]


def test_duplicate_rejected_client_side(configured: ConfiguredPayserver, page) -> None:
    _open_auto_cashout(page, configured)
    _fill_rows(page, [ADDR_A, ADDR_A])
    # Enable auto-melt. The toggle is a CSS-hidden checkbox positioned off the
    # viewport, so set it directly rather than clicking; its value is only read
    # on save (no change handler).
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", "100")

    page.click("#btn-save-auto-melt")
    page.wait_for_timeout(1000)

    # The inline error surfaces and nothing is persisted.
    err = page.locator("#aw-store-error")
    assert err.is_visible(), "expected inline duplicate-address error"
    assert "Duplicate" in err.text_content()
    assert _ln_addresses(configured.handle, configured.store_id) == []


def test_unverifiable_address_blocked_server_side(
    configured: ConfiguredPayserver, page
) -> None:
    """On the plain stack no LNURL host is reachable, so the server-side
    LUD-21 gate rejects the save: the error toast shows and nothing lands in
    store_ln_addresses."""
    _open_auto_cashout(page, configured)
    _fill_rows(page, [ADDR_A])
    # Enable auto-melt. The toggle is a CSS-hidden checkbox positioned off the
    # viewport, so set it directly rather than clicking; its value is only read
    # on save (no change handler).
    page.evaluate("() => { document.getElementById('auto-melt-enabled').checked = true; }")
    page.fill("#auto-melt-threshold", "100")

    page.click("#btn-save-auto-melt")
    # The probe times out server-side before the 400 comes back — allow for it.
    page.wait_for_selector("#toast.show.error", timeout=20000)
    assert "didn't respond" in page.locator("#toast").text_content()
    assert _ln_addresses(configured.handle, configured.store_id) == []
