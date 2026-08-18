"""Browser e2e coverage for the admin UI redesign:
  - Export Token button removed.
  - Settings + store-settings cards are collapsible (default open) and toggle.
  - On-chain "Advanced" subsection is collapsed by default.
  - Auto-cashout column selector renders (store: 2 cols — the "-1"/inherit
    column is gone with the site-wide layer) and selecting a column updates
    the underlying mode control.
  - Store-settings card order (Lightning payments between Store Info and
    Cashu automatic cashout; Submarine Swaps under On-chain; API Keys near
    the bottom).
  - Site settings no longer carries the removed site-wide cards (#aw-site,
    swaps, self-serve, on-chain default) but keeps Email Notifications.
  - Stats dashboard charts render (Chart.js loads — regression guard for the
    relative-asset-path bug that left charts blank).
  - The toast container is hidden when empty (no stray floating box).
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


@pytest.fixture
def admin_page(configured: ConfiguredPayserver, browser):
    """A logged-in admin browser page (cookie established via context.request)."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.request.post(
        f"{configured.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    page = ctx.new_page()
    yield page, configured.handle.url
    ctx.close()


def _goto(page, base, view):
    page.goto(f"{base}/admin/{view}", wait_until="networkidle")
    page.wait_for_timeout(1200)


def test_export_button_removed(admin_page):
    page, base = admin_page
    _goto(page, base, "dashboard")
    assert page.evaluate("!!document.getElementById('btn-export')") is False
    assert page.evaluate("!!document.getElementById('modal-export')") is False


def test_store_settings_collapsible_and_order(admin_page):
    page, base = admin_page
    _goto(page, base, "stores")

    titles = page.eval_on_selector_all(
        "#store-settings-content > .card .card-title",
        "els => els.map(e => e.textContent.trim())",
    )
    # API Keys moved to the bottom (just above Danger Zone).
    assert titles.index("API Keys") > titles.index("Hosting Fee")
    assert titles[-1] == "Danger Zone"
    # Lightning payments (the destination-chain card) sits between Store Info
    # and the renamed Cashu automatic cashout card.
    assert titles.index("Lightning payments") == titles.index("Store Info") + 1
    assert titles.index("Cashu automatic cashout") == titles.index("Lightning payments") + 1
    assert "Auto-Cashout" not in titles
    # Submarine Swaps directly beneath On-chain Bitcoin payments.
    assert titles.index("Submarine Swaps (LN→on-chain)") == titles.index("On-chain Bitcoin payments") + 1

    # Every section is collapsible and open by default.
    assert page.eval_on_selector_all("#store-settings-content > .card.collapsible", "e => e.length") >= 8
    card = page.query_selector("#store-settings-content > .card.collapsible")
    assert "collapsed" not in (card.get_attribute("class") or "")
    page.query_selector("#store-settings-content > .card.collapsible > .card-header").click()
    assert "collapsed" in (card.get_attribute("class") or "")


def test_onchain_advanced_collapsed_by_default(admin_page):
    page, base = admin_page
    _goto(page, base, "stores")
    adv = page.query_selector("#onchain-advanced")
    assert adv is not None
    assert "collapsed" in (adv.get_attribute("class") or "")
    # Advanced fields live inside it.
    for fid in ["onchain-network", "onchain-address-type", "onchain-min-confs",
                "onchain-confirm-timeout", "onchain-provider-url"]:
        assert page.eval_on_selector(f"#{fid}", "el => !!el.closest('#onchain-advanced')")


def test_auto_cashout_columns_and_selection(admin_page):
    page, base = admin_page
    _goto(page, base, "stores")

    cols = page.eval_on_selector_all(
        "#aw-store .aw-col", "els => els.map(e => e.getAttribute('data-aw-mode'))"
    )
    assert cols == ["0", "1"]  # lightning / on-chain — no "use global" column

    # Selecting Lightning updates the hidden mode control.
    page.query_selector('#aw-store .aw-col[data-aw-mode="0"]').click()
    assert page.eval_on_selector("#auto-melt-mode-override", "el => el.value") == "0"
    assert page.eval_on_selector('#aw-store .aw-col[data-aw-mode="0"]',
                                 "el => el.classList.contains('selected')")


def test_site_settings_email_card_and_site_cards_removed(admin_page):
    page, base = admin_page
    _goto(page, base, "settings")
    # Cards now live inside a .settings-scroll wrapper (pinned-footer redesign),
    # so match by descendant rather than direct child. Email Notifications stays
    # (deliberately unchanged); assert it is present rather than first.
    titles = page.eval_on_selector_all(
        "#view-settings .card .card-title", "els => els.map(e => e.textContent.trim())"
    )
    assert "Email Notifications" in titles, titles
    # The site-wide swaps / self-serve / on-chain-default cards (and the
    # #aw-site auto-cashout default selector) were removed with the
    # store-only-settings refactor: none of their ids may exist anywhere.
    removed_ids = [
        "aw-site", "card-swaps", "card-selfserve", "card-onchain-site",
        "btn-save-swaps", "btn-save-selfserve", "btn-save-onchain-site",
        "swaps-enabled", "swaps-strict", "swaps-provider-checkboxes",
        "swaps-auto-select", "swaps-auto-threshold", "swaps-min-sats",
        "swaps-fee-max-pct", "swaps-fee-max-sats",
        "selfserve-enabled", "selfserve-max-sats", "onchain-site-enabled",
        "auto-melt-use-swap-default",
    ]
    present = page.evaluate(
        "ids => ids.filter(id => !!document.getElementById(id))", removed_ids
    )
    assert present == [], f"removed site-settings ids still present: {present}"


def test_stats_charts_render(admin_page):
    page, base = admin_page
    _goto(page, base, "stats")
    page.wait_for_timeout(1500)
    # Regression guard: Chart.js must load on the sub-path (was 404 → undefined).
    assert page.evaluate("typeof Chart") == "function"
    # And a chart instance is actually constructed (statsState is a top-level
    # const, not a window property, so reference it by bare name).
    assert page.evaluate(
        "typeof statsState !== 'undefined' && !!(statsState.charts && statsState.charts.financial)"
    )


def test_toast_hidden_when_empty(admin_page):
    page, base = admin_page
    _goto(page, base, "dashboard")
    vis = page.eval_on_selector("#toast", "el => getComputedStyle(el).visibility")
    assert vis == "hidden"
