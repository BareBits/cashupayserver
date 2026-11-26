"""Admin UI: the cash back rewards half (includes/rewards.php).

* Rewards view (admin-only nav item "Rewards"): the account table for the
  pinned store, the per-store status line, the debounced username search, the
  store filter incl. "All stores" (adds a Store column), the inline "Enable
  rewards" form for a store with rewards off, and the header store selector
  staying hidden.
* Store settings -> "Rewards" card: enable + percentage save round-trip
  through action=save_rewards_settings into stores.rewards_*, a validation
  error surfaced inline, disabling keeps the percentage.
* Exchange settings: changing the default currency while rewards balances
  exist opens the convert/keep modal (409 + needsRewardsCurrencyDecision);
  Cancel saves nothing, "Keep" saves the default currency and leaves the
  balances in the old rewards currency.

Accounts are seeded straight into rewards_accounts (sqlite) — the payer-side
flow that creates them is covered by the payment page tests.

Run with: pytest tests/ui/test_admin_rewards_ui.py -v
"""
from __future__ import annotations

import sqlite3
import time
import uuid

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import (
    dashboard_seq,
    expand_settings_cards,
    wait_for_dashboard_settled,
    wait_for_store_settings_loaded,
)

pytestmark = pytest.mark.ui

MICRO = 1_000_000


def _login(page, configured: ConfiguredPayserver) -> None:
    # Pin the SPA to this test's store BEFORE the first load (shared server,
    # per-test store).
    page.add_init_script(f"localStorage.setItem('selectedStoreId', {configured.store_id!r})")
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.wait_for_selector("#nav-rewards:not(.hidden)")


def _seed_account(configured: ConfiguredPayserver, username: str, balance_sats: int,
                  earned_sats: int | None = None, spent_sats: int = 0, activity_offset: int = 0) -> str:
    acct_id = "rwa_" + uuid.uuid4().hex[:12]
    now = int(time.time()) - activity_offset
    with configured.handle.db() as db:
        db.execute(
            "INSERT INTO rewards_accounts (id, store_id, username, username_key, balance_micro, reserved_micro,"
            " earned_micro, spent_micro, created_at, updated_at, last_activity_at)"
            " VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)",
            (acct_id, configured.store_id, username, username.lower(), balance_sats * MICRO,
             (earned_sats if earned_sats is not None else balance_sats) * MICRO, spent_sats * MICRO, now, now, now),
        )
    return acct_id


def _set_store_rewards(configured: ConfiguredPayserver, enabled: int, percent: float | None, currency: str | None) -> None:
    with configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET rewards_enabled = ?, rewards_percent = ?, rewards_currency = ? WHERE id = ?",
            (enabled, percent, currency, configured.store_id),
        )


def _store_row(configured: ConfiguredPayserver) -> tuple:
    with sqlite3.connect(configured.handle.db_path) as db:
        return db.execute(
            "SELECT rewards_enabled, rewards_percent, rewards_currency, default_currency FROM stores WHERE id = ?",
            (configured.store_id,),
        ).fetchone()


def _wait_store_row(configured: ConfiguredPayserver, predicate, timeout: float = 15.0) -> tuple:
    deadline = time.monotonic() + timeout
    row = _store_row(configured)
    while not predicate(row) and time.monotonic() < deadline:
        time.sleep(0.2)
        row = _store_row(configured)
    assert predicate(row), f"stores row {row!r} did not reach the expected state"
    return row


def _open_rewards_view(page) -> None:
    page.click('.nav-item[data-view="rewards"]')
    page.wait_for_selector("#view-rewards.active")
    # The list has loaded once the spinner is gone (table or empty state).
    page.wait_for_selector("#rewards-table, #rewards-empty")


def _row_usernames(page) -> list[str]:
    return page.evaluate(
        "() => [...document.querySelectorAll('#all-rewards tr.rewards-row')].map(r => r.dataset.username)"
    )


def _wait_rows(page, expected: list[str]) -> None:
    page.wait_for_function(
        "(want) => JSON.stringify([...document.querySelectorAll('#all-rewards tr.rewards-row')]"
        ".map(r => r.dataset.username)) === JSON.stringify(want)",
        arg=expected,
    )


# --------------------------------------------------------------------------- view


def test_rewards_view_lists_accounts_search_and_store_filter(shared_configured: ConfiguredPayserver, page) -> None:
    cfg = shared_configured
    _set_store_rewards(cfg, 1, 1.5, "sat")
    _seed_account(cfg, "Alice", balance_sats=1500, earned_sats=2000, spent_sats=500)
    _seed_account(cfg, "Bob", balance_sats=250, activity_offset=3600)

    _login(page, cfg)
    _open_rewards_view(page)

    assert page.text_content("#header-text").strip() == "Rewards"
    # The view has its own store filter; the header's selector is hidden.
    assert page.evaluate("() => getComputedStyle(document.getElementById('header-store-selector')).display") == "none"
    assert page.input_value("#rewards-store-filter") == cfg.store_id, "first entry follows the pinned store"

    # Newest activity first; balance + lifetime figures per row.
    _wait_rows(page, ["Alice", "Bob"])
    summary = page.text_content("#rewards-store-summary")
    assert "Rewards on" in summary and "1.5% cash back" in summary and "kept in sats" in summary, summary
    alice = page.locator("#all-rewards tr.rewards-row[data-username='Alice']")
    cells = alice.locator("td").all_inner_texts()
    assert cells[0] == "Alice", cells
    assert "1,500 sats" in cells[1], cells
    assert cells[2] == "2,000 sats", cells  # earned (sats)
    assert cells[3] == "500 sats", cells    # spent
    assert cells[4].strip() not in ("", "—"), cells  # last activity rendered
    # textContent: the header cells are CSS-uppercased.
    headers = page.evaluate("() => [...document.querySelectorAll('#rewards-table thead th')].map(th => th.textContent.trim())")
    assert headers == ["Username", "Balance", "Earned", "Spent", "Last activity"], headers
    # The figure headers sit over the figures (both right-aligned) and the
    # three figure columns size to their content rather than spreading out.
    aligns = page.evaluate(
        "() => [...document.querySelectorAll('#rewards-table thead th')].map(th => getComputedStyle(th).textAlign)"
    )
    assert aligns == ["left", "right", "right", "right", "left"], aligns
    widths = page.evaluate(
        "() => [...document.querySelector('#rewards-table thead tr').children].map(th => th.getBoundingClientRect().width)"
    )
    assert widths[0] > max(widths[1:4]) * 2, widths
    # No inline enable form while rewards are on.
    assert page.evaluate("() => document.getElementById('rewards-enable-inline').classList.contains('hidden')")

    # Username search is a case-insensitive substring filter (debounced).
    page.fill("#rewards-search", "ALI")
    _wait_rows(page, ["Alice"])
    page.fill("#rewards-search", "zzz")
    page.wait_for_selector("#rewards-empty")
    assert "No rewards accounts match this search" in page.text_content("#rewards-empty")
    page.fill("#rewards-search", "")
    _wait_rows(page, ["Alice", "Bob"])

    # "All stores" adds the Store column and keeps this store's rows.
    page.select_option("#rewards-store-filter", "__all__")
    page.wait_for_function(
        "() => [...document.querySelectorAll('#rewards-table thead th')].some(th => th.textContent.trim() === 'Store')"
    )
    page.wait_for_function(
        "(n) => [...document.querySelectorAll('#all-rewards tr.rewards-row')].map(r => r.dataset.username)"
        ".filter(u => u === 'Alice' || u === 'Bob').length === n",
        arg=2,
    )
    store_cells = page.evaluate(
        "() => [...document.querySelectorAll('#all-rewards tr.rewards-row')]"
        ".filter(r => r.dataset.username === 'Alice').map(r => r.children[1].textContent.trim())"
    )
    assert store_cells == [cfg.store_name], store_cells
    summary = page.text_content("#rewards-store-summary")
    assert "store" in summary and "rewards on" in summary, summary

    # The export button targets the CSV endpoint with the current filters.
    page.fill("#rewards-search", "ali")
    _wait_rows(page, ["Alice"])
    href = page.evaluate(
        """() => {
            // The same URL the Export CSV click handler navigates to.
            const params = new URLSearchParams({ api: 'export_rewards_csv', store_id: getRewardsStoreFilter() });
            const q = getRewardsSearch();
            if (q) params.set('q', q);
            return adminUrl + '?' + params.toString();
        }"""
    )
    assert "api=export_rewards_csv" in href and "store_id=__all__" in href and "q=ali" in href, href
    # And the endpoint itself answers CSV for the logged-in admin.
    csv = page.evaluate(
        "(u) => fetch(u, { credentials: 'same-origin' }).then(r => r.headers.get('content-type') + '|' + r.status)", href
    )
    assert csv.startswith("text/csv") and csv.endswith("|200"), csv


def test_rewards_view_enables_rewards_inline(shared_configured: ConfiguredPayserver, page) -> None:
    cfg = shared_configured  # fresh per-test store: rewards off, no accounts
    _login(page, cfg)
    _open_rewards_view(page)
    assert page.input_value("#rewards-store-filter") == cfg.store_id
    empty = page.text_content("#rewards-empty")
    assert "No rewards accounts yet" in empty and "Enable rewards above" in empty, empty
    summary = page.text_content("#rewards-store-summary")
    assert "Rewards are off for this store" in summary, summary
    # The enable form is right there: it names the currency a first enable
    # pins (the store's default currency) and validates like the settings card.
    page.wait_for_selector("#rewards-enable-inline:not(.hidden)")
    assert "Balances will be kept in sats" in page.text_content("#rewards-inline-help")
    page.fill("#rewards-inline-percent", "")
    page.click("#btn-rewards-inline-enable")
    page.wait_for_selector("#rewards-inline-error:not(.hidden)")
    assert "percentage" in page.text_content("#rewards-inline-error")
    page.fill("#rewards-inline-percent", "75")
    page.click("#btn-rewards-inline-enable")
    page.wait_for_function("() => /50/.test(document.getElementById('rewards-inline-error').textContent)")
    assert _store_row(cfg)[0] == 0, "an invalid percentage must not enable rewards"

    page.fill("#rewards-inline-percent", "2.5")
    page.press("#rewards-inline-percent", "Enter")
    row = _wait_store_row(cfg, lambda r: r[0] == 1)
    assert row == (1, 2.5, "sat", "sat"), row
    # The view reflects the switch: summary on, form gone, empty state no
    # longer asks for enabling.
    page.wait_for_function("() => /Rewards on/.test(document.getElementById('rewards-store-summary').textContent)")
    summary = page.text_content("#rewards-store-summary")
    assert "2.5% cash back" in summary and "kept in sats" in summary, summary
    assert page.evaluate("() => document.getElementById('rewards-enable-inline').classList.contains('hidden')")
    page.wait_for_function("() => /claims their first reward/.test((document.getElementById('rewards-empty') || {}).textContent || '')")

    # "All stores" never shows the form.
    page.select_option("#rewards-store-filter", "__all__")
    page.wait_for_function("() => /store/.test(document.getElementById('rewards-store-summary').textContent)")
    assert page.evaluate("() => document.getElementById('rewards-enable-inline').classList.contains('hidden')")


# --------------------------------------------------------------------------- store settings card


def _reveal_store_cards(page, configured: ConfiguredPayserver, selector: str) -> None:
    """Open the Stores view and expose `selector` inside a collapsible card.

    Two async loads re-render the per-store cards and re-apply the default
    collapsed state (the dashboard payload and loadStoreSettings()); gate on
    both, expand, and re-expand if a late re-render collapsed things again.
    """
    page.locator('.nav-item[data-view="stores"]').click()
    for attempt in range(3):
        expand_settings_cards(page)
        wait_for_store_settings_loaded(page, configured.store_id)
        wait_for_dashboard_settled(page)
        page.evaluate(
            "() => document.querySelectorAll('.view.active .card.collapsible')"
            ".forEach(c => c.classList.remove('collapsed'))"
        )
        try:
            page.wait_for_selector(selector, state="visible", timeout=5000)
            return
        except Exception:
            if attempt == 2:
                raise


def _set_rewards_toggle(page, checked: bool) -> None:
    # The toggle's <input> is CSS-hidden, so set it directly.
    page.evaluate("(c) => { document.getElementById('rewards-enabled').checked = c; }", checked)


def _save_rewards_card(page) -> int:
    seq = dashboard_seq(page)
    page.click("#btn-save-rewards")
    return seq


def test_store_settings_rewards_card_save_validation_and_disable(shared_configured: ConfiguredPayserver, page) -> None:
    cfg = shared_configured
    _login(page, cfg)
    _reveal_store_cards(page, cfg, "#btn-save-rewards")

    # Fresh store: off, no percent (the migration default is 0.0), currency
    # not pinned yet.
    row = _store_row(cfg)
    assert row[0] == 0 and not row[1] and row[2] is None, row
    assert not page.is_checked("#rewards-enabled")
    assert page.input_value("#rewards-percent") == ""
    assert page.get_attribute("#rewards-percent", "inputmode") == "decimal"
    assert page.locator("#rewards-experimental-warning").count() == 0, "the experimental warning is gone"
    assert "Balances will be kept in sats" in page.text_content("#rewards-currency-line")
    assert "No rewards accounts yet" in page.text_content("#rewards-totals-line")
    assert page.locator("#rewards-error").is_hidden()

    # Enabling without a percentage is refused client-side.
    _set_rewards_toggle(page, True)
    page.fill("#rewards-percent", "")
    page.click("#btn-save-rewards")
    page.wait_for_selector("#rewards-error:not(.hidden)")
    assert "percentage" in page.text_content("#rewards-error").lower()
    assert _store_row(cfg)[0] == 0, "nothing saved on the client-side refusal"

    # A server-side refusal (parsePercent) lands in the same inline error.
    page.fill("#rewards-percent", "1.23456")
    page.click("#btn-save-rewards")
    page.wait_for_function(
        "() => document.getElementById('rewards-error').textContent.includes('decimal')"
    )
    assert _store_row(cfg)[0] == 0, "nothing saved on the server-side refusal"

    # Valid save: enabled, percent, currency pinned to the store default (sat).
    page.fill("#rewards-percent", "2.5")
    seq = _save_rewards_card(page)
    _wait_store_row(cfg, lambda r: r[0] == 1 and r[1] == 2.5 and r[2] == "sat")
    wait_for_dashboard_settled(page, after_seq=seq)
    page.wait_for_function("() => document.getElementById('rewards-percent').value === '2.5'")
    assert page.is_checked("#rewards-enabled")
    assert page.locator("#rewards-error").is_hidden()
    assert "Balances are kept in sats" in page.text_content("#rewards-currency-line")

    # Reload: the dashboard payload repopulates the card.
    page.reload()
    page.wait_for_selector("#app", state="visible")
    _reveal_store_cards(page, cfg, "#btn-save-rewards")
    page.wait_for_function("() => document.getElementById('rewards-percent').value === '2.5'")
    assert page.is_checked("#rewards-enabled")

    # Disable: the percentage is remembered for the next enable.
    _set_rewards_toggle(page, False)
    seq = _save_rewards_card(page)
    _wait_store_row(cfg, lambda r: r[0] == 0 and r[1] == 2.5 and r[2] == "sat")
    wait_for_dashboard_settled(page, after_seq=seq)
    page.wait_for_function("() => !document.getElementById('rewards-enabled').checked")
    assert page.input_value("#rewards-percent") == "2.5"

    # The card links into the Rewards view.
    page.click("#link-rewards-view")
    page.wait_for_selector("#view-rewards.active")
    assert page.text_content("#header-text").strip() == "Rewards"


# --------------------------------------------------------------------------- currency switch modal


def test_default_currency_switch_prompts_for_rewards_decision(shared_configured: ConfiguredPayserver, page) -> None:
    cfg = shared_configured
    _set_store_rewards(cfg, 1, 1.0, "sat")
    _seed_account(cfg, "Dora", balance_sats=4000)

    _login(page, cfg)
    _reveal_store_cards(page, cfg, "#btn-save-exchange-settings")
    assert _store_row(cfg)[3] == "sat", _store_row(cfg)

    page.select_option("#default-currency", "USD")
    page.click("#btn-save-exchange-settings")
    page.wait_for_selector("#modal-rewards-currency.visible")
    text = page.text_content("#rewards-currency-text")
    assert "Rewards balances are kept in sats. Switch them to USD?" in text, text
    assert "1 account holds a balance" in text, text
    assert page.text_content("#btn-rewards-currency-keep").strip() == "Keep balances in sats"
    assert page.text_content("#btn-rewards-currency-convert").strip() == "Convert at today's rate"

    # Cancel: nothing saved, the modal closes.
    page.click("#modal-rewards-currency button:has-text('Cancel')")
    page.wait_for_selector("#modal-rewards-currency", state="hidden")
    time.sleep(0.5)
    assert _store_row(cfg)[3] == "sat", "cancel must not save the default currency"
    assert _store_row(cfg)[2] == "sat"

    # Keep: the default currency changes, the balances stay in sats.
    page.click("#btn-save-exchange-settings")
    page.wait_for_selector("#modal-rewards-currency.visible")
    page.click("#btn-rewards-currency-keep")
    page.wait_for_selector("#modal-rewards-currency", state="hidden")
    row = _wait_store_row(cfg, lambda r: r[3] == "USD")
    assert row[2] == "sat", f"keep leaves the rewards currency alone: {row!r}"
    with sqlite3.connect(cfg.handle.db_path) as db:
        bal = db.execute(
            "SELECT balance_micro FROM rewards_accounts WHERE store_id = ? AND username_key = 'dora'", (cfg.store_id,)
        ).fetchone()[0]
    assert bal == 4000 * MICRO, "keep leaves balances untouched"

    # The rewards card now explains the split currencies.
    wait_for_dashboard_settled(page)
    page.wait_for_function(
        "() => document.getElementById('rewards-currency-line').textContent.includes('default currency is USD')"
    )
    assert "Balances are kept in sats" in page.text_content("#rewards-currency-line")

    # Switching back to the rewards currency itself needs no decision.
    page.select_option("#default-currency", "sat")
    page.click("#btn-save-exchange-settings")
    _wait_store_row(cfg, lambda r: r[3] == "sat")
    assert not page.locator("#modal-rewards-currency").evaluate("el => el.classList.contains('visible')")


# --------------------------------------------------------------------------- invoice detail + list rendering


MOCK_DETAIL = {
    "invoice": {
        "id": "inv_rw_mock", "status": "Settled", "amount": "10.00", "currency": "USD",
        "paymentRail": "mint", "mintUrl": "https://mint.example.com", "createdTime": 1700000000,
        "paidTime": 1700000100, "metadata": {}, "network": "regtest",
        "rewards": {"appliedAmount": "1.25", "appliedCurrency": "USD", "appliedUsername": "Alice", "fullCoverage": False},
    },
    "storeName": "Alpha",
    "isAdmin": True,
    "tipJar": None,
    "detail": {"paymentRailChosen": "mint", "settledRail": "mint", "metadata": {}},
    "items": [],
    "attempts": [
        {"timestamp": 1700000050, "context": "rewards", "rail": "rewards", "outcome": "ok",
         "label": "rewards applied: -1.25 USD (Alice)", "reason": None, "detail": None},
    ],
    "attemptsLegacy": False,
    "rewards": {
        "enabled": True, "percent": 2.5, "rewardsCurrency": "USD", "canApply": False, "applyBlockedReason": "x",
        "applied": {"username": "Alice", "accountId": "rwa_x", "discountAmount": "1.25", "currency": "USD",
                    "micro": 1250000, "display": "1.25 USD", "state": "committed", "fullCoverage": False},
        "effectiveAmount": "8.75", "originalAmount": "10.00", "currency": "USD",
        "earned": {"micro": 218750, "currency": "USD", "display": "0.21 USD", "fiatDisplay": None, "sats": 218,
                   "claimable": False, "claimDeadline": 1700086500, "claimedTo": "Alice", "claimedAt": 1700000100},
    },
}


def test_invoice_detail_and_list_render_rewards(shared_configured: ConfiguredPayserver, page) -> None:
    """The invoice modal's "Rewards applied"/"Rewards earned" rows, the
    effective amount next to the face value, the 'rewards' labels for the
    attempt log and the method chip, and the list's applied-rewards chip —
    driven with mock payloads through the real render functions."""
    _login(page, shared_configured)

    html = page.evaluate("(d) => renderInvoiceDetail(d)", MOCK_DETAIL)
    assert "Rewards applied" in html and "−1.25 USD (Alice)" in html, html
    assert "paid entirely with rewards" not in html, html
    assert "(8.75 USD after rewards)" in html, html
    assert "Rewards earned" in html and "0.21 USD" in html and "credited to Alice" in html, html
    # Attempt log: the rewards context and rail are labelled.
    assert 'data-rail="rewards"' in html and "<strong>Rewards</strong>" in html, html

    # Full coverage, unclaimed-but-claimable earn, rewards rail chip.
    full = dict(MOCK_DETAIL)
    full["invoice"] = dict(MOCK_DETAIL["invoice"], paymentRail="rewards", amount="0.50",
                           rewards={"appliedAmount": "0.50", "appliedCurrency": "USD", "appliedUsername": "Bob", "fullCoverage": True})
    full["detail"] = {"paymentRailChosen": "mint", "settledRail": "rewards", "metadata": {}}
    full["rewards"] = dict(
        MOCK_DETAIL["rewards"],
        applied=dict(MOCK_DETAIL["rewards"]["applied"], username="Bob", discountAmount="0.50", fullCoverage=True),
        effectiveAmount="0.00", originalAmount="0.50",
        earned=dict(MOCK_DETAIL["rewards"]["earned"], claimable=True, claimedTo=None, claimedAt=None, fiatDisplay="0.30 EUR"),
    )
    html = page.evaluate("(d) => renderInvoiceDetail(d)", full)
    assert "−0.50 USD (Bob), paid entirely with rewards" in html, html
    assert "🎁 Rewards" in html, html  # method chip for the rewards rail
    assert "unclaimed, claimable until" in html and "(≈ 0.30 EUR)" in html, html

    expired = dict(full)
    expired["rewards"] = dict(full["rewards"], earned=dict(full["rewards"]["earned"], claimable=False))
    html = page.evaluate("(d) => renderInvoiceDetail(d)", expired)
    assert "unclaimed, expired" in html, html

    # Invoices list: the applied-rewards chip rides in the note column; the
    # amount column keeps the face value.
    page.evaluate("(rows) => renderInvoicesTable('all-invoices', rows)", [MOCK_DETAIL["invoice"], full["invoice"]])
    table = page.inner_html("#all-invoices")
    assert "🎁 −1.25 USD" in table and "🎁 −0.50 USD" in table, table
    assert "Paid entirely with rewards" in table, table
    assert "10.00 USD" in table, table
