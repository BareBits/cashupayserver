"""Admin UI: the per-store maximum payment size for the Cashu path.

Lives in the Cashu section of the store's Lightning payments card (Stores
view) — #cashu-max-invoice-sats (blank = unlimited) plus the
#cashu-max-respect-always toggle ("Respect size even if other lightning
options fail", default ON) — and saves through action=save_cashu_limit into
stores.cashu_max_invoice_sats / stores.cashu_max_respect_always
(includes/cashu_limit.php). The onboarding wizard never shows it.

Run with: pytest tests/ui/test_admin_cashu_limit_ui.py -v -s
"""
from __future__ import annotations

import sqlite3
import time
from pathlib import Path

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import (
    dashboard_seq,
    expand_settings_cards,
    wait_for_dashboard_settled,
    wait_for_store_settings_loaded,
)

pytestmark = pytest.mark.ui

REPO = Path(__file__).resolve().parents[2]


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _reveal_cashu_controls(page, configured: ConfiguredPayserver) -> None:
    """Open the Stores view and expose the Cashu maximum controls.

    Two async loads re-render the per-store cards and apply the per-section
    default collapse states (the Cashu subsection starts collapsed unless
    cashout is configured): the dashboard payload and loadStoreSettings().
    Gate on both before expanding, then verify the input actually became
    visible — a late re-render (e.g. the post-reload view restore) can still
    collapse things again, so expand once more if it did.
    """
    page.locator('.nav-item[data-view="stores"]').click()
    for attempt in range(3):
        expand_settings_cards(page)
        wait_for_store_settings_loaded(page, configured.store_id)
        wait_for_dashboard_settled(page)
        page.evaluate(
            "() => { document.querySelectorAll('.view.active .card.collapsible')"
            ".forEach(c => c.classList.remove('collapsed'));"
            " const s = document.getElementById('ln-section-cashu');"
            " if (s) s.classList.remove('collapsed'); }"
        )
        try:
            page.wait_for_selector("#cashu-max-invoice-sats", state="visible", timeout=5000)
            page.wait_for_selector("#btn-save-cashu-limit", state="visible", timeout=5000)
            return
        except Exception:
            if attempt == 2:
                raise


def _open_cashu_section(page, configured: ConfiguredPayserver) -> None:
    # Pin the SPA to this test's store BEFORE the first load (see the swaps
    # card test for why: a later store switch re-renders mid-edit).
    page.add_init_script(
        f"localStorage.setItem('selectedStoreId', {configured.store_id!r})"
    )
    _login(page, configured)
    _reveal_cashu_controls(page, configured)


def _row(configured: ConfiguredPayserver) -> tuple:
    with sqlite3.connect(configured.handle.db_path) as db:
        return db.execute(
            "SELECT cashu_max_invoice_sats, cashu_max_respect_always FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()


def _wait_row(configured: ConfiguredPayserver, expected: tuple, timeout: float = 15.0) -> None:
    deadline = time.monotonic() + timeout
    row = _row(configured)
    while row != expected and time.monotonic() < deadline:
        time.sleep(0.2)
        row = _row(configured)
    assert row == expected, f"stores row {row!r} != {expected!r}"


def _save(page, configured: ConfiguredPayserver, expected: tuple) -> None:
    """Click Save and wait for BOTH the row to land and the un-awaited
    post-save dashboard reload to finish — that reload re-populates the
    controls from the payload and would clobber the next edit mid-flight."""
    seq = dashboard_seq(page)
    page.click("#btn-save-cashu-limit")
    _wait_row(configured, expected)
    wait_for_dashboard_settled(page, after_seq=seq)
    want = "" if expected[0] is None else str(expected[0])
    page.wait_for_function(
        "(v) => document.getElementById('cashu-max-invoice-sats').value === v", arg=want
    )


def _set_respect(page, checked: bool) -> None:
    # The toggle's <input> is CSS-hidden, so set it directly.
    page.evaluate(
        "(c) => { document.getElementById('cashu-max-respect-always').checked = c; }", checked
    )


def test_defaults_render_unlimited_and_respect_on(shared_configured: ConfiguredPayserver, page) -> None:
    configured = shared_configured
    _open_cashu_section(page, configured)
    assert _row(configured) == (None, 1), "fresh store: unlimited, respect ON"
    assert page.input_value("#cashu-max-invoice-sats") == ""
    assert page.get_attribute("#cashu-max-invoice-sats", "placeholder") == "unlimited"
    assert page.is_checked("#cashu-max-respect-always")
    assert page.locator("#cashu-limit-error").is_hidden()


def test_save_roundtrip_and_clear(shared_configured: ConfiguredPayserver, page) -> None:
    configured = shared_configured
    _open_cashu_section(page, configured)
    try:
        page.fill("#cashu-max-invoice-sats", "5000")
        _set_respect(page, False)
        _save(page, configured, (5000, 0))
        assert page.locator("#cashu-limit-error").is_hidden()

        # Reload: the dashboard payload repopulates both controls.
        page.reload()
        page.wait_for_selector("#app", state="visible")
        _reveal_cashu_controls(page, configured)
        page.wait_for_function(
            "() => document.getElementById('cashu-max-invoice-sats').value === '5000'"
        )
        assert not page.is_checked("#cashu-max-respect-always")

        # Blank + respect back on = unlimited again.
        page.fill("#cashu-max-invoice-sats", "")
        _set_respect(page, True)
        _save(page, configured, (None, 1))

        # 0 also means unlimited.
        page.fill("#cashu-max-invoice-sats", "2500")
        _save(page, configured, (2500, 1))
        page.fill("#cashu-max-invoice-sats", "0")
        _save(page, configured, (None, 1))
    finally:
        with sqlite3.connect(configured.handle.db_path) as db:
            db.execute(
                "UPDATE stores SET cashu_max_invoice_sats = NULL, cashu_max_respect_always = 1 WHERE id = ?",
                (configured.store_id,),
            )


def test_invalid_values_rejected(shared_configured: ConfiguredPayserver, page) -> None:
    configured = shared_configured
    _open_cashu_section(page, configured)
    before = _row(configured)

    # Client side: a fraction never reaches the server.
    page.fill("#cashu-max-invoice-sats", "12.5")
    page.click("#btn-save-cashu-limit")
    page.wait_for_selector("#cashu-limit-error:not(.hidden)")
    assert "whole number" in page.locator("#cashu-limit-error").inner_text()
    assert _row(configured) == before, "blocked save must not persist"

    # Server side: the handler validates on its own (negative, text, bad flag).
    for body in (
        f"action=save_cashu_limit&store_id={configured.store_id}&max_sats=-5&respect_always=1",
        f"action=save_cashu_limit&store_id={configured.store_id}&max_sats=abc&respect_always=1",
        f"action=save_cashu_limit&store_id={configured.store_id}&max_sats=100&respect_always=2",
        "action=save_cashu_limit&store_id=store_does_not_exist&max_sats=100&respect_always=1",
    ):
        result = page.evaluate(
            "async (body) => { const r = await postWithCsrf(adminUrl, body);"
            " return { status: r.status, json: await r.json() }; }",
            body,
        )
        assert result["status"] == 400, result
        assert "error" in result["json"], result
    assert _row(configured) == before, "rejected saves must not persist"


def test_wizard_never_shows_the_setting() -> None:
    """Store-settings only: the onboarding wizard and its helpers must not
    render or save the Cashu maximum."""
    for rel in ("app/setup.php", "app/includes/setup_flow.php"):
        text = (REPO / rel).read_text(encoding="utf-8")
        assert "cashu_max" not in text and "cashu-max" not in text, rel
        assert "CashuLimit" not in text, rel
