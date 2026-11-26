"""The cash back rewards ORDER NOTE, against a real WooCommerce + BTCPay gateway.

The BareBits server's rewards program never changes a WooCommerce order
total: the gateway creates the invoice for the full amount, the payer may
apply an earlier balance on the BareBits payment page (lowering what is owed,
or covering it outright), and the server credits cash back on what was
actually paid. The plugin's half is to tell the merchant: when the signed
InvoiceSettled webhook makes the gateway run payment_complete(), the plugin
fetches the invoice over the Greenfield API once and records the `rewards`
story as an order note (wordpress/btcpay-integration.php,
barebits_note_rewards_on_payment_complete).

This module pins that wiring:

    a real guest order through the Store API, paying with the BTCPay gateway
      -> the invoice is settled on the payserver (Greenfield mark-status —
         the rails themselves are covered elsewhere)
      -> the invoice's rewards columns are seeded straight into the server DB
         (the server-side apply / earn flow has its own PHP and e2e tests;
         what matters here is that whatever the server reports under
         `rewards`, the order ends up with the right note, exactly once)
      -> the payserver's cron drains the webhook into WooCommerce
      -> the order flips to paid AND carries the rewards note.

Three orders: rewards applied + earned (and the once-only flag, plus the
silent no-op when the server is unreachable), fully covered by rewards, and
a plain order with no rewards story (no note, flag says so).

The pure wording is pinned by tests/php/test_wp_rewards_note.php; the
assertions here are deliberately tolerant about which currency the server
spells the applied amount in (invoice currency vs rewards currency — both
read correctly) and exact about the account, the earn and the once-only
behaviour.
"""
from __future__ import annotations

import json
import re
import time

import pytest

from wordpress.test_wp_woocommerce_checkout import (  # noqa: F401 — fixture
    _flush_rewrites,
    _order_field,
    _place_order,
    _wire,
    configured_multiworker,
)

pytestmark = pytest.mark.wordpress

# A phone-number style username, the shape the payment page suggests.
REWARDS_USERNAME = "5551234567"
# The fixture product is 0.00001500 BTC (1500 sats); the fixture store's
# default — and therefore rewards — currency is sat.
INVOICE_AMOUNT_BTC = "0.00001500"
MICRO = 1_000_000


def _order_notes(wp, order_id: int) -> list[str]:
    snippet = (
        "echo json_encode(array_values(array_map("
        "fn($n) => (string) $n->content, "
        f"wc_get_order_notes(['order_id' => {order_id}]))));"
    )
    out = wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1]
    return json.loads(out)


def _rewards_notes(wp, order_id: int) -> list[str]:
    return [n for n in _order_notes(wp, order_id) if "BareBits rewards" in n]


def _place_and_settle(wp, configured, product_id: int) -> tuple[int, str]:
    """Guest checkout with the BTCPay gateway, then settle the invoice on the
    payserver through the Greenfield status endpoint. Returns (order_id,
    invoice_id). The webhook outbox is NOT drained yet."""
    checkout = _place_order(wp, product_id)
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", checkout
    invoice_id = _order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", (
        f"gateway did not store a BareBits invoice id: {checkout}"
    )
    marked = configured.greenfield.mark_invoice_status(configured.store_id, invoice_id, "Settled")
    assert marked.get("status") == "Settled", marked
    return order_id, invoice_id


def _drain_until_paid(configured, wp, order_id: int) -> str:
    """Drive the payserver cron (the async webhook outbox) until WooCommerce
    reports the order paid — i.e. the gateway ran payment_complete() on the
    signed InvoiceSettled, which is when the plugin's hook fires."""
    deadline = time.monotonic() + 45
    status = None
    while time.monotonic() < deadline:
        r = configured.handle.trigger_cron()
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = _order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            return status
        time.sleep(1)
    raise AssertionError(f"order {order_id} never reached a paid state (last status {status!r})")


def _seed_account(db, store_id: str, account_id: str) -> None:
    now = int(time.time())
    db.execute(
        "INSERT INTO rewards_accounts (id, store_id, username, username_key, balance_micro, "
        "reserved_micro, earned_micro, spent_micro, created_at, updated_at, last_activity_at) "
        "VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?)",
        (account_id, store_id, REWARDS_USERNAME, REWARDS_USERNAME.lower(), now, now, now),
    )


def _enable_rewards_in_db(db, store_id: str) -> None:
    db.execute(
        "UPDATE stores SET rewards_enabled = 1, rewards_percent = 1.0, rewards_currency = 'sat' "
        "WHERE id = ?",
        (store_id,),
    )


def _server_reports_rewards(configured, invoice_id: str) -> dict:
    body = configured.greenfield.get_invoice(configured.store_id, invoice_id)
    assert isinstance(body.get("rewards"), dict), (
        "the seeded invoice must come back with a `rewards` block from the "
        f"server (Invoice::formatForApi / RewardsFlow::apiBlock): {body}"
    )
    return body["rewards"]


def test_paid_orders_carry_the_rewards_note_once(woocommerce, configured_multiworker) -> None:
    configured = configured_multiworker
    wp, info = woocommerce
    _flush_rewrites(wp)
    _wire(wp, configured)
    store_id = configured.store_id

    # ------------------------------------------------------------------
    # Order 1: 500 sats of rewards applied against the 1500-sat invoice,
    # 1% cash back (10 sats) on the 1000 sats actually paid, auto-credited
    # to the same account.
    # ------------------------------------------------------------------
    order_1, invoice_1 = _place_and_settle(wp, configured, info["product_id"])
    with configured.handle.db() as db:
        _enable_rewards_in_db(db, store_id)
        _seed_account(db, store_id, "rwa_wptest_partial")
        db.execute(
            "UPDATE invoices SET rewards_account_id = ?, rewards_discount_amount = ?, "
            "rewards_applied_micro = ?, rewards_spend_state = 'committed', "
            "rewards_earned_micro = ?, rewards_earn_currency = 'sat', rewards_claimed_at = ? "
            "WHERE id = ?",
            ("rwa_wptest_partial", "0.00000500", 500 * MICRO, 10 * MICRO, int(time.time()), invoice_1),
        )
    rewards_1 = _server_reports_rewards(configured, invoice_1)
    assert rewards_1.get("appliedUsername") == REWARDS_USERNAME, rewards_1
    assert rewards_1.get("fullCoverage") is False, rewards_1

    _drain_until_paid(configured, wp, order_1)

    notes = _rewards_notes(wp, order_1)
    assert len(notes) == 1, f"expected exactly one rewards note, got {notes!r}; all: {_order_notes(wp, order_1)!r}"
    note = notes[0]
    assert re.fullmatch(
        r"Customer applied (0\.000005(00)? BTC|500 sats) of BareBits rewards "
        rf"\(account {REWARDS_USERNAME}\); the remaining (0\.00001000 BTC|amount) was paid in Bitcoin\. "
        rf"Customer earned 10 sats in BareBits rewards \(claimed by {REWARDS_USERNAME}\)\.",
        note,
    ), note
    assert _order_field(wp, order_1, "get_meta('barebits_rewards_note_added')") == "noted"

    # Once only: a second drain and an explicit re-run of the hook add nothing.
    configured.handle.trigger_cron()
    wp.wp_cli("eval", f"barebits_note_rewards_on_payment_complete({order_1});")
    assert len(_rewards_notes(wp, order_1)) == 1, _order_notes(wp, order_1)

    # ------------------------------------------------------------------
    # Order 2: covered entirely by rewards — settled on the 'rewards' rail
    # with nothing paid in Bitcoin, hence nothing earned (net basis).
    # ------------------------------------------------------------------
    order_2, invoice_2 = _place_and_settle(wp, configured, info["product_id"])
    with configured.handle.db() as db:
        db.execute(
            "UPDATE invoices SET rewards_account_id = ?, rewards_discount_amount = ?, "
            "rewards_applied_micro = ?, rewards_spend_state = 'committed', "
            "rewards_earned_micro = 0, rewards_earn_currency = 'sat', "
            "settled_rail = 'rewards', amount_sats = 0 "
            "WHERE id = ?",
            ("rwa_wptest_partial", INVOICE_AMOUNT_BTC, 1500 * MICRO, invoice_2),
        )
    rewards_2 = _server_reports_rewards(configured, invoice_2)
    assert rewards_2.get("fullCoverage") is True, rewards_2

    _drain_until_paid(configured, wp, order_2)
    notes = _rewards_notes(wp, order_2)
    assert notes == [f"Paid entirely with BareBits rewards (account {REWARDS_USERNAME})."], (
        notes, _order_notes(wp, order_2)
    )
    assert _order_field(wp, order_2, "get_meta('barebits_rewards_note_added')") == "noted"

    # ------------------------------------------------------------------
    # Order 3: nothing rewards-related happened. No note; the flag records
    # that the invoice was looked at so it is never fetched again. Rewards
    # are switched off on the store first: the earn is computed at
    # settlement, so a store that still had them on would legitimately earn
    # 1% on this order too.
    # ------------------------------------------------------------------
    with configured.handle.db() as db:
        db.execute("UPDATE stores SET rewards_enabled = 0 WHERE id = ?", (store_id,))
        db.commit()
    order_3, invoice_3 = _place_and_settle(wp, configured, info["product_id"])
    body_3 = configured.greenfield.get_invoice(store_id, invoice_3)
    assert "rewards" not in body_3 or body_3["rewards"] is None, body_3
    _drain_until_paid(configured, wp, order_3)
    assert _rewards_notes(wp, order_3) == [], _order_notes(wp, order_3)
    assert _order_field(wp, order_3, "get_meta('barebits_rewards_note_added')") == "none"

    # ------------------------------------------------------------------
    # Unreachable server: the hook must stay silent — no exception, no
    # note, and no flag (so nothing pretends the invoice was inspected).
    # Re-run the hook on order 3 with its flag cleared and the server URL
    # pointed at a dead port.
    # ------------------------------------------------------------------
    live_url = configured.handle.url
    wp.wp_cli(
        "eval",
        f"$o = wc_get_order({order_3}); $o->delete_meta_data('barebits_rewards_note_added'); $o->save();",
    )
    wp.wp_cli("option", "update", "barebits_server_url", "http://127.0.0.1:1/nowhere", check=False)
    try:
        res = wp.wp_cli(
            "eval",
            f"barebits_note_rewards_on_payment_complete({order_3}); echo 'SURVIVED';",
            check=False,
        )
        assert "SURVIVED" in res.stdout, (res.stdout, res.stderr)
    finally:
        wp.wp_cli("option", "update", "barebits_server_url", live_url, check=False)
    assert _rewards_notes(wp, order_3) == []
    # An absent flag echoes nothing, which the generic field helper cannot
    # parse; read it JSON-encoded so "" is a real line.
    flag_out = wp.wp_cli(
        "eval",
        f"echo json_encode((string) wc_get_order({order_3})->get_meta('barebits_rewards_note_added'));",
    ).stdout.strip().splitlines()[-1]
    assert json.loads(flag_out) == "", flag_out
