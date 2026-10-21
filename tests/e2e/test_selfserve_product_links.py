"""E2e for self-serve product links (/pay/{storeId}?products=prod_x:2,prod_y).

Covers the customer-facing product mode of the public self-serve page:
  - a link with products renders a fixed order (titles, quantities, subtotal)
  - malformed product parameters and links whose products are all gone 404
    (same no-leak policy as unknown stores)
  - a partially-stale link drops the missing item with a notice
  - submitting creates a real invoice whose invoice_items snapshot the
    products, plus the optional tip (percent preset via the browser UI,
    custom amount via a direct POST)
  - the per-store maximum applies to the full total, tip included

Parsing + tip validation units live in tests/php/test_selfserve_product_links.php.
Products are seeded directly on the products table (the admin product UI has
its own coverage in tests/ui/test_admin_products_cart_ui.py); prices stay in
sats so no exchange-rate provider is needed.
"""
from __future__ import annotations

import secrets
import time

import requests

from conftest import ConfiguredPayserver


def _enable_store_selfserve(shared_configured: ConfiguredPayserver, enabled: bool = True) -> None:
    with shared_configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            (1 if enabled else 0, shared_configured.store_id),
        )


def _set_store_max(shared_configured: ConfiguredPayserver, max_sats) -> None:
    with shared_configured.handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_max_sats = ? WHERE id = ?",
            (max_sats, shared_configured.store_id),
        )


def _make_product(
    shared_configured: ConfiguredPayserver, title: str, price: str, enabled: int = 1
) -> str:
    pid = "prod_" + secrets.token_hex(12)
    now = int(time.time())
    with shared_configured.handle.db() as db:
        db.execute(
            "INSERT INTO products (id, store_id, title, price, currency, image_type, image_value,"
            " purchase_count, enabled, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, 'sat', 'none', NULL, 0, ?, ?, ?)",
            (pid, shared_configured.store_id, title, price, enabled, now, now),
        )
    return pid


def _pay_url(shared_configured: ConfiguredPayserver, products: str) -> str:
    return (
        f"{shared_configured.handle.url}/pay/{shared_configured.store_id}"
        f"?products={products}"
    )


def test_product_link_renders_fixed_order(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    p1 = _make_product(shared_configured, "Link Espresso", "700")
    p2 = _make_product(shared_configured, "Link Croissant", "300")
    r = requests.get(_pay_url(shared_configured, f"{p1}:2,{p2}"), timeout=15)
    assert r.status_code == 200, r.text
    assert "Link Espresso" in r.text
    assert "Link Croissant" in r.text
    assert "Review your order" in r.text
    # Subtotal: 2×700 + 300 = 1,700 sats.
    assert "1,700" in r.text
    # Fixed order: no amount input, tip presets offered instead.
    assert 'name="amount"' not in r.text
    assert "No tip" in r.text and "15%" in r.text


def test_product_link_404_cases(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    disabled = _make_product(shared_configured, "Link Retired", "100", enabled=0)
    ok = _make_product(shared_configured, "Link Alive", "100")

    # Malformed parameter -> generic 404.
    r = requests.get(_pay_url(shared_configured, "not-a-product"), timeout=15)
    assert r.status_code == 404, r.text
    # Zero quantity is malformed too.
    r = requests.get(_pay_url(shared_configured, f"{ok}:0"), timeout=15)
    assert r.status_code == 404, r.text
    # Every product in the link unavailable -> 404.
    r = requests.get(_pay_url(shared_configured, disabled), timeout=15)
    assert r.status_code == 404, r.text
    # Self-serve off -> 404 even with a valid product link.
    _enable_store_selfserve(shared_configured, enabled=False)
    try:
        r = requests.get(_pay_url(shared_configured, ok), timeout=15)
        assert r.status_code == 404, r.text
    finally:
        _enable_store_selfserve(shared_configured)


def test_product_link_drops_missing_with_notice(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    alive = _make_product(shared_configured, "Link Keeper", "400")
    gone = _make_product(shared_configured, "Link Dropper", "100", enabled=0)
    r = requests.get(_pay_url(shared_configured, f"{alive},{gone}:3"), timeout=15)
    assert r.status_code == 200, r.text
    assert "Link Keeper" in r.text
    assert "Link Dropper" not in r.text
    assert "no longer available" in r.text


def test_create_with_percent_tip_via_browser(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    _enable_store_selfserve(shared_configured)
    pid = _make_product(shared_configured, "Link Tipped", "1000")
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(_pay_url(shared_configured, f"{pid}:2"), wait_until="networkidle")
        # Pick the 15% preset: the client-side preview updates the total.
        page.click('button[data-tip-mode="percent"][data-tip-percent="15"]')
        page.wait_for_function(
            "() => document.getElementById('ss-total').textContent.includes('2,300')"
        )
        assert "incl." in page.locator("#ss-total").inner_text()
        page.fill("#notes", "e2e product link tip")
        page.click("button[type=submit]")
        page.wait_for_url("**/payment.php?id=*", timeout=15000)
        invoice_id = page.url.split("id=")[1].split("&")[0]
    finally:
        ctx.close()

    with shared_configured.handle.db() as db:
        inv = db.execute(
            "SELECT store_id, status, amount, currency, metadata FROM invoices WHERE id = ?",
            (invoice_id,),
        ).fetchone()
        items = db.execute(
            "SELECT product_id, title, quantity, amount_sats FROM invoice_items "
            "WHERE invoice_id = ? ORDER BY created_at ASC, id ASC",
            (invoice_id,),
        ).fetchall()
    assert inv is not None
    assert inv["store_id"] == shared_configured.store_id
    # 2×1000 product + 15% tip (300) = 2300 sats.
    assert str(inv["amount"]) == "2300"
    assert (inv["currency"] or "").upper() == "SAT"
    assert "e2e product link tip" in (inv["metadata"] or "")
    assert len(items) == 2, items
    assert items[0]["product_id"] == pid
    assert items[0]["quantity"] == 2
    assert items[0]["amount_sats"] == 2000
    assert items[1]["title"] == "Tip (15%)"
    assert items[1]["product_id"] is None
    assert items[1]["amount_sats"] == 300


def test_create_with_custom_tip_via_post(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    pid = _make_product(shared_configured, "Link Custom Tip", "800")
    r = requests.post(
        _pay_url(shared_configured, pid),
        data={"tip_mode": "custom", "tip_percent": "", "tip_amount": "150", "notes": ""},
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code in (301, 302, 303), r.text
    location = r.headers.get("Location", "")
    assert "payment" in location
    invoice_id = location.split("id=")[1].split("&")[0]
    with shared_configured.handle.db() as db:
        inv = db.execute(
            "SELECT amount FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
        items = db.execute(
            "SELECT title, amount_sats FROM invoice_items WHERE invoice_id = ? "
            "ORDER BY created_at ASC, id ASC",
            (invoice_id,),
        ).fetchall()
    assert inv is not None and str(inv["amount"]) == "950"
    assert [i["title"] for i in items] == ["Link Custom Tip", "Tip"]
    assert items[1]["amount_sats"] == 150


def test_max_applies_to_total_including_tip(shared_configured: ConfiguredPayserver) -> None:
    _enable_store_selfserve(shared_configured)
    pid = _make_product(shared_configured, "Link Near Max", "900")
    _set_store_max(shared_configured, 1000)
    try:
        # Product alone fits; product + tip busts the cap -> form error, no redirect.
        r = requests.post(
            _pay_url(shared_configured, pid),
            data={"tip_mode": "custom", "tip_percent": "", "tip_amount": "200", "notes": ""},
            timeout=15,
            allow_redirects=False,
        )
        assert r.status_code == 200, r.text
        assert "maximum" in r.text.lower()
        # Without the tip it goes through.
        r = requests.post(
            _pay_url(shared_configured, pid),
            data={"tip_mode": "", "tip_percent": "", "tip_amount": "", "notes": ""},
            timeout=15,
            allow_redirects=False,
        )
        assert r.status_code in (301, 302, 303), r.text
    finally:
        _set_store_max(shared_configured, None)
