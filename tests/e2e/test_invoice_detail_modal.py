"""Admin invoice detail modal + per-invoice payment-path attempt log.

Clicking an invoice anywhere in the admin UI — an All Invoices row, a
Recent-invoices card on the dashboard, the invoice link on a Log tab row —
opens a modal with the full invoice (status, amounts, times, payment methods,
destination, note / order reference, customer), the products snapshotted at
creation (invoice_items) and the payment-path attempt log: which rails were
tried while sourcing the invoice's payment methods, in order, with each
attempt's outcome and reason.

The rig: the per-test store gets a dead Lightning address destination
(unresolvable host) ahead of its regtest mint, so creating an invoice walks
"Lightning address -> failed, Cashu mint -> ok" and the attempt log has
something to show. The cart line items are inserted directly (the same
snapshot the self-serve product-link flow writes; that flow has its own
test).

Also covers the endpoint contract (?api=invoice_detail): login required,
404 for an unknown id, raw error detail present for the admin.
"""
from __future__ import annotations

import sqlite3
import time
import uuid

import pytest
import requests

from conftest import DEFAULT_ADMIN_PASSWORD, ConfiguredPayserver

DEAD_LN_ADDRESS = "merchant@lnurl-dead.invalid"
ITEM_TITLES = ("Detail modal widget", "Detail modal gadget")


def _db(cfg: ConfiguredPayserver) -> sqlite3.Connection:
    conn = sqlite3.connect(str(cfg.handle.data_dir / "cashupay.sqlite"), timeout=30)
    conn.row_factory = sqlite3.Row
    return conn


def _add_dead_ln_address(cfg: ConfiguredPayserver) -> None:
    """Put an unreachable Lightning address first in the store's chain.
    Written directly: the admin save path probes the address and would
    (correctly) refuse to store a dead one."""
    with _db(cfg) as conn:
        conn.execute(
            "DELETE FROM store_ln_addresses WHERE store_id = ? AND address = ?",
            (cfg.store_id, DEAD_LN_ADDRESS),
        )
        conn.execute(
            "INSERT INTO store_ln_addresses (store_id, position, address, type) VALUES (?, 0, ?, 'lnaddress')",
            (cfg.store_id, DEAD_LN_ADDRESS),
        )
        conn.commit()


def _add_items(cfg: ConfiguredPayserver, invoice_id: str) -> None:
    now = int(time.time())
    with _db(cfg) as conn:
        for i, title in enumerate(ITEM_TITLES):
            conn.execute(
                "INSERT INTO invoice_items (id, invoice_id, store_id, product_id, title, unit_price,"
                " unit_currency, quantity, amount_sats, display_amount, display_currency,"
                " image_type, image_value, created_at)"
                " VALUES (?, ?, ?, NULL, ?, ?, 'sat', ?, ?, NULL, NULL, 'none', NULL, ?)",
                (f"item_{uuid.uuid4().hex[:12]}", invoice_id, cfg.store_id, title,
                 str(300 + i * 100), i + 1, (300 + i * 100) * (i + 1), now),
            )
        conn.commit()


@pytest.fixture()
def rigged_invoice(shared_configured: ConfiguredPayserver) -> dict:
    """A 1000-sat invoice created with the dead LN address ahead of the mint,
    carrying a note, an order reference and two cart line items."""
    _add_dead_ln_address(shared_configured)
    invoice = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="1000", currency="sat",
        metadata={"itemDesc": "Detail modal test invoice", "orderId": "ORDER-4242"},
    )
    _add_items(shared_configured, invoice["id"])
    return invoice


def _detail(cfg: ConfiguredPayserver, invoice_id: str) -> requests.Response:
    return cfg.admin.s.get(
        cfg.admin._admin_url, params={"api": "invoice_detail", "id": invoice_id}, timeout=30
    )


def _admin_context(cfg: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Multi-store shared server: pin the dashboard's store selector to this
    # test's store so the lists show its invoices.
    ctx.add_init_script(f"localStorage.setItem('selectedStoreId', {cfg.store_id!r})")
    ctx.request.post(
        f"{cfg.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
    )
    return ctx


def _assert_modal_shows_invoice(page, invoice_id: str) -> None:
    modal = page.locator("#modal-invoice-detail")
    modal.wait_for(state="visible", timeout=10000)
    page.wait_for_selector("#invoice-detail-id", timeout=10000)
    assert invoice_id in page.locator("#invoice-detail-id").inner_text()


def test_detail_endpoint_contract(shared_configured: ConfiguredPayserver, rigged_invoice: dict) -> None:
    invoice_id = rigged_invoice["id"]

    # Anonymous: the ?api= dispatcher requires a session.
    r = requests.get(
        shared_configured.admin._admin_url, params={"api": "invoice_detail", "id": invoice_id}, timeout=15
    )
    assert r.status_code == 401, r.text

    # Unknown id -> 404 with an error body.
    r = _detail(shared_configured, "inv_does_not_exist")
    assert r.status_code == 404, r.text
    assert r.json().get("error")

    r = _detail(shared_configured, invoice_id)
    assert r.status_code == 200, r.text
    d = r.json()
    assert d["invoice"]["id"] == invoice_id
    assert d["invoice"]["hasItems"] is True
    assert d["isAdmin"] is True
    assert d["storeName"] == shared_configured.store_name or d["storeName"]
    assert d["detail"]["metadata"]["orderId"] == "ORDER-4242"
    assert d["detail"]["bolt11"], "the mint rail should have produced a bolt11"
    assert d["detail"]["paymentRailChosen"] == "mint"
    assert [it["title"] for it in d["items"]] == list(ITEM_TITLES)
    assert d["items"][1]["quantity"] == 2

    # Attempt log: the dead LN address failed first, then the mint won. The
    # admin sees the destination label and the raw error detail; the reason
    # is the fixed payer-safe phrase.
    assert d["attemptsLegacy"] is False
    attempts = d["attempts"]
    rails = [(a["rail"], a["outcome"]) for a in attempts]
    assert rails[0] == ("lnurl", "failed"), rails
    assert ("mint", "ok") in rails, rails
    lnurl = attempts[0]
    assert lnurl["context"] == "checkout"
    assert lnurl["label"] == DEAD_LN_ADDRESS
    assert lnurl["reason"], lnurl
    assert "detail" in lnurl and lnurl["detail"], "admin payload must carry the raw error text"
    mint_ok = next(a for a in attempts if a["rail"] == "mint" and a["outcome"] == "ok")
    assert mint_ok["label"] and mint_ok["label"].startswith("http"), mint_ok
    assert mint_ok["reason"] is None

    # The failure also reached the Log tab feed with the invoice id attached
    # (checkout-context rows used to be written without it).
    with _db(shared_configured) as conn:
        row = conn.execute(
            "SELECT invoice_id, label FROM admin_event_log"
            " WHERE category = 'lnurl' AND context = 'checkout' AND invoice_id = ?",
            (invoice_id,),
        ).fetchone()
    assert row is not None, "checkout-context admin log row must reference the invoice"
    assert row["label"] == DEAD_LN_ADDRESS


def test_invoice_row_click_opens_detail_modal(
    shared_configured: ConfiguredPayserver, rigged_invoice: dict, browser
) -> None:
    invoice_id = rigged_invoice["id"]
    base = shared_configured.handle.url
    ctx = _admin_context(shared_configured, browser)
    page = ctx.new_page()
    try:
        page.goto(f"{base}/admin/invoices", wait_until="networkidle")
        row = page.locator(f"tr.inv-row[data-invoice-id='{invoice_id}']")
        row.wait_for(state="visible", timeout=15000)
        # The old inline "items" link is gone; the row flags items with a chip
        # and the whole row opens the detail.
        assert row.locator("a[onclick*='openInvoiceItems']").count() == 0
        assert "items" in row.inner_text()
        row.locator("td").nth(3).click()
        _assert_modal_shows_invoice(page, invoice_id)

        content = page.locator("#invoice-detail-content")
        text = content.inner_text()
        # Section titles are CSS-uppercased, so compare case-insensitively.
        lowered = text.lower()
        for needle in ("summary", "payment methods", "products", "payment attempts",
                       "detail modal test invoice", "order-4242", "1000 sat"):
            assert needle in lowered, f"missing {needle!r} in modal:\n{text}"

        # Products: both line items + total (300×1 + 400×2 = 1,100 sats).
        for title in ITEM_TITLES:
            assert title in text
        assert "Total: 1,100 sats" in text, text

        # Attempt log rendered in walk order with outcome badges.
        failed = content.locator(".invd-attempt[data-rail='lnurl'][data-outcome='failed']")
        assert failed.count() == 1, text
        assert DEAD_LN_ADDRESS in failed.inner_text()
        assert "Failed" in failed.inner_text()
        ok = content.locator(".invd-attempt[data-rail='mint'][data-outcome='ok']")
        assert ok.count() >= 1, text
        assert "OK" in ok.first.inner_text()
        assert "Invoice creation" in text
        assert "Reconstructed" not in text, "a logged invoice must not show the legacy note"
        # The lnurl failure's raw detail equals its sanitized reason here, so
        # the phrase must render once, not twice.
        assert failed.inner_text().count("could not be reached") == 1, failed.inner_text()

        # Clicking an explorer / copy link inside a row must not re-open the
        # modal on top of itself: close, then click the row's id tooltip.
        page.locator("#modal-invoice-detail .btn").click()
        page.locator("#modal-invoice-detail").wait_for(state="hidden", timeout=5000)
        row.locator(".inv-id-tip").click()
        page.wait_for_timeout(400)
        assert not page.locator("#modal-invoice-detail").is_visible(), (
            "clicking the id tooltip inside the row must not open the modal"
        )
    finally:
        ctx.close()


def test_dashboard_recent_invoice_card_opens_detail_modal(
    shared_configured: ConfiguredPayserver, rigged_invoice: dict, browser
) -> None:
    invoice_id = rigged_invoice["id"]
    base = shared_configured.handle.url
    ctx = _admin_context(shared_configured, browser)
    page = ctx.new_page()
    try:
        page.goto(f"{base}/admin", wait_until="networkidle")
        card = page.locator(f"#recent-invoices .list-item[data-invoice-id='{invoice_id}']")
        card.wait_for(state="visible", timeout=15000)
        card.click()
        _assert_modal_shows_invoice(page, invoice_id)
        text = page.locator("#invoice-detail-content").inner_text()
        assert "payment attempts" in text.lower()
        assert DEAD_LN_ADDRESS in text
    finally:
        ctx.close()


def test_log_tab_invoice_link_opens_detail_modal(
    shared_configured: ConfiguredPayserver, rigged_invoice: dict, browser
) -> None:
    invoice_id = rigged_invoice["id"]
    base = shared_configured.handle.url
    ctx = _admin_context(shared_configured, browser)
    page = ctx.new_page()
    try:
        page.goto(f"{base}/admin/log", wait_until="networkidle")
        link = page.locator(f"a.log-invoice-link[title='{invoice_id}']").first
        link.wait_for(state="visible", timeout=15000)
        link.click()
        _assert_modal_shows_invoice(page, invoice_id)
    finally:
        ctx.close()


def test_legacy_invoice_reconstructs_attempts(shared_configured: ConfiguredPayserver) -> None:
    """An invoice from before the attempt log (no rows) still gets a walk
    reconstructed from receive_errors + the winning rail, flagged legacy."""
    invoice = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="500", currency="sat"
    )
    with _db(shared_configured) as conn:
        conn.execute("DELETE FROM invoice_rail_attempts WHERE invoice_id = ?", (invoice["id"],))
        conn.execute(
            "UPDATE invoices SET receive_errors = ? WHERE id = ?",
            ('[{"type":"nwc","reason":"no response from the wallet (timed out)"}]', invoice["id"]),
        )
        conn.commit()
    d = _detail(shared_configured, invoice["id"]).json()
    assert d["attemptsLegacy"] is True
    outcomes = [(a["rail"], a["outcome"]) for a in d["attempts"]]
    assert outcomes[0] == ("nwc", "failed"), outcomes
    assert outcomes[-1][1] == "ok", outcomes
    assert all("detail" not in a for a in d["attempts"])
