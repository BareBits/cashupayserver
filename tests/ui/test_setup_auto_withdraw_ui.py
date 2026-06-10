"""Setup wizard step 9: choose where auto-withdrawals go.

Three branches matter:
  - Lightning address path → auto_melt_address persisted, on-chain step optional
  - On-chain path → submarine-swap auto-melt, on-chain step required (no skip)
  - Skip path → warning shown, auto-melt disabled, on-chain step optional

Also asserts the existing add_store flow still funnels through this step.

Run with: pytest tests/ui/test_setup_auto_withdraw_ui.py -v -s
"""
from __future__ import annotations

import sqlite3

import pytest

from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle

pytestmark = pytest.mark.ui

LIGHTNING_ADDRESS = "awesomemerchant@strike.me"


def _walk_to_auto_withdraw_step(page, payserver, mint, store_name="AW Store") -> None:
    """Drive a fresh setup wizard to step 9 (auto-withdraw).

    Under the new step order, auto-withdraw comes immediately after
    create-store, so the walk is just security ack → password → store name.
    """
    page.set_default_timeout(15000)
    page.goto(f"{payserver.url}/setup")
    page.check("#security_acknowledged")
    page.click("button[type=submit]")
    page.fill("#password", "wizard-test-pw")
    page.fill("#confirm_password", "wizard-test-pw")
    page.click("button[type=submit]")
    page.fill("#store_name", store_name)
    page.click("button[type=submit]")
    page.wait_for_selector("#auto-withdraw-form")


def _store_row(payserver: PayserverHandle) -> sqlite3.Row:
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores LIMIT 1").fetchone()
    finally:
        conn.close()


def _ln_addresses(payserver: PayserverHandle) -> list[str]:
    """Ordered Lightning-address fallback chain for the (single) store.

    Replaces the old stores.auto_melt_address column lookup — addresses now
    live in the store_ln_addresses table, ordered by priority.
    """
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        rows = conn.execute(
            "SELECT address FROM store_ln_addresses ORDER BY position ASC"
        ).fetchall()
        return [r[0] for r in rows]
    finally:
        conn.close()


def test_auto_withdraw_lightning_persists_address(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_auto_withdraw_step(page, payserver, mint, "LN Store")

    # Lightning is the pre-selected radio. Fill the address + submit.
    page.fill("#lightning_address", LIGHTNING_ADDRESS)
    page.locator("#auto-withdraw-form button[type=submit]").click()

    # Lands on step 8 (on-chain). Skip — Lightning mode leaves it optional.
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    row = _store_row(payserver)
    assert row["auto_melt_enabled"] == 1, "auto-melt should be enabled"
    assert _ln_addresses(payserver) == [LIGHTNING_ADDRESS]
    # use_swap=0 means Lightning-address mode (vs 1=submarine-swap).
    assert row["auto_melt_use_swap"] == 0


def test_auto_withdraw_onchain_makes_onchain_step_required(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_auto_withdraw_step(page, payserver, mint, "Onchain Store")

    # Pick the on-chain radio + submit.
    page.locator('input[name="auto_withdraw_mode"][value="onchain"]').check()
    page.locator("#auto-withdraw-form button[type=submit]").click()

    # Step 8 (on-chain). Skip button must be gone — we can't satisfy the
    # submarine-swap path without an xpub.
    page.wait_for_selector("#onchain-form")
    skip_buttons = page.locator("button:has-text('Skip for now')").count()
    assert skip_buttons == 0, "Skip should be hidden when on-chain auto-withdraw was picked"

    # Static-address mode should also be hidden (submarine swap needs an xpub).
    options = page.evaluate(
        "() => Array.from(document.querySelectorAll('#onchain_address_mode option')).map(o => o.value)"
    )
    assert options == ["xpub"], f"expected only xpub option, got {options}"

    # Persisted state at this point: enabled with swap mode, no LN address.
    row = _store_row(payserver)
    assert row["auto_melt_enabled"] == 1
    assert row["auto_melt_use_swap"] == 1
    assert _ln_addresses(payserver) == []


def test_auto_withdraw_skip_disables_auto_melt(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    _walk_to_auto_withdraw_step(page, payserver, mint, "Skip Store")

    # The skip button is in a sibling form below the warning. Click the
    # one whose form has auto_withdraw_action=skip to disambiguate from
    # the on-chain step's identically-labeled button.
    page.locator(
        'form:has(input[name="auto_withdraw_action"][value="skip"]) '
        'button:has-text("Skip for now")'
    ).click()

    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")

    row = _store_row(payserver)
    assert row["auto_melt_enabled"] == 0, "skip should leave auto-melt disabled"


def test_auto_withdraw_step_visible_in_add_store_mode(
    payserver: PayserverHandle, mint: MintHandle, page
) -> None:
    """add_store mode starts at step 4 and must funnel through step 9 too —
    not just the fresh-setup flow."""
    # First, walk the full wizard once so the install is set up and we can
    # use add_store mode against it.
    _walk_to_auto_withdraw_step(page, payserver, mint, "Initial")
    page.locator(
        'form:has(input[name="auto_withdraw_action"][value="skip"]) '
        'button:has-text("Skip for now")'
    ).click()
    page.wait_for_selector("#onchain-form")
    page.click("button:has-text('Skip for now')")
    # Complete the rest of the wizard (mint URL → unit → seed) so we end up
    # at the admin UI for the add_store path below.
    page.wait_for_selector("#mint_url")
    page.fill("#mint_url", mint.url)
    page.click("button[type=submit]")
    page.wait_for_selector("#mint_unit")
    page.select_option("#mint_unit", "sat")
    page.click("#continue-btn")
    page.click("button:has-text('Generate New Seed Phrase')")
    page.wait_for_selector("#seed_confirmed")
    page.check("#seed_confirmed")
    page.click("button[type=submit]")

    # Now log in and navigate to setup.php?mode=add_store.
    page.goto(f"{payserver.url}/admin")
    page.fill("#password-input", "wizard-test-pw")
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    page.goto(f"{payserver.url}/setup?mode=add_store")
    page.wait_for_selector("h1:has-text('Add New Store')")
    page.fill("#store_name", "Second Store")
    page.click("button[type=submit]")

    # Under the new step order, create-store funnels straight to step 9
    # (auto-withdraw) before any mint/seed configuration.
    page.wait_for_selector("#auto-withdraw-form", state="visible")
