"""Tip jars end to end: the per-jar public page, the invoice it creates, the
payment page banner, and the admin Tip Jars view.

Public side (HTTP + browser):
  - /pay/{store}/{jar} renders the jar's emoji, name and message; a disabled
    jar, an unknown jar, or a jar of another store 404s like a disabled store.
  - The bare /pay/{store} link redirects to the store's oldest ENABLED jar
    (what the upgrade migration created), so links shared before the rename
    keep working; it 404s when no jar is enabled.
  - An invoice created through a jar carries invoices.tip_jar_id plus the
    metadata snapshot, the payment page shows the jar banner, and the banner
    survives deleting the jar (snapshot fallback).

Admin side:
  - ?api=tip_jars lists the store's jars with settled totals (unpaid and
    other stores' invoices excluded); the write actions are admin-only.
  - Turning the store switch on with no jars creates the default jar.
  - The Tip Jars view renders one tile per jar with its total, the tile
    opens the detail modal (link + QR + totals), "View invoices" lands on the
    Invoices view filtered to the jar, and create / edit / disable / delete
    round-trip through the modals (delete behind an inline confirmation).
  - A non-admin user sees the view and the tiles but none of the controls.
"""
from __future__ import annotations

import json
import secrets
import time

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import AdminClient
from fixtures.tip_jars import delete_tip_jars, enable_tip_jars, ensure_tip_jar


def _url(cfg: ConfiguredPayserver, path: str) -> str:
    return f"{cfg.handle.url}{path}"


def _seed_settled_invoice(cfg: ConfiguredPayserver, jar_id: str | None, sats: int, status: str = "Settled") -> str:
    inv_id = "inv_tj_" + secrets.token_hex(6)
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO invoices (id, store_id, status, amount, currency, amount_sats, metadata,"
            " created_at, expiration_time, tip_jar_id) VALUES (?, ?, ?, ?, 'SAT', ?, NULL, ?, ?, ?)",
            (inv_id, cfg.store_id, status, str(sats), sats, now, now + 3600, jar_id),
        )
    return inv_id


def _invoice_id_from_location(loc: str) -> str:
    return loc.split("id=")[-1].split("&")[0] if "id=" in loc else loc.rstrip("/").split("/")[-1]


def _login_page(cfg: ConfiguredPayserver, browser, username: str = "admin", password: str = DEFAULT_ADMIN_PASSWORD):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    # Shared multi-store server: pin the dashboard to this test's store.
    ctx.add_init_script(f"localStorage.setItem('selectedStoreId', {cfg.store_id!r})")
    ctx.request.post(
        _url(cfg, "/admin"),
        form={"action": "login", "username": username, "password": password},
    )
    return ctx, ctx.new_page()


def _goto_tipjars(cfg: ConfiguredPayserver, page) -> None:
    page.goto(_url(cfg, "/admin/tipjars"), wait_until="networkidle")
    # loadTipJarsView renders the grid (tiles or the empty state) once the
    # ?api=tip_jars call lands.
    page.wait_for_function(
        "() => document.querySelector('#tipjars-grid .jar-tile, #tipjars-grid .empty-state') !== null",
        timeout=20000,
    )


# ---------------------------------------------------------------------------
# Public page
# ---------------------------------------------------------------------------


def test_jar_page_renders_emoji_name_and_message(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    jar = ensure_tip_jar(cfg.handle, cfg.store_id, "Coffee fund", emoji="☕", message="Thank you for your donation!")
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{jar}"), timeout=15)
    assert r.status_code == 200, r.text[:300]
    assert 'id="jar-emoji">☕<' in r.text
    assert 'id="jar-name">Coffee fund<' in r.text
    assert "Thank you for your donation!" in r.text
    assert "<title>Coffee fund - " in r.text
    assert "Continue to payment" in r.text


def test_jar_page_default_emoji_and_no_message_block(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    jar = ensure_tip_jar(cfg.handle, cfg.store_id, "Plain jar")
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{jar}"), timeout=15)
    assert r.status_code == 200
    assert 'id="jar-emoji">🫙<' in r.text, "default emoji when none is set"
    assert 'id="jar-message"' not in r.text, "no message block when the message is empty"


def test_jar_page_404_cases(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    off = ensure_tip_jar(cfg.handle, cfg.store_id, "Off jar", enabled=False)
    on = ensure_tip_jar(cfg.handle, cfg.store_id, "On jar")

    # Disabled jar, unknown jar: 404.
    for jar in (off, "jar_000000000000000000000000"):
        r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{jar}"), timeout=15, allow_redirects=False)
        assert r.status_code == 404, (jar, r.status_code)
    # Enabled jar works.
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{on}"), timeout=15, allow_redirects=False)
    assert r.status_code == 200
    # Master switch off: even the enabled jar 404s.
    enable_tip_jars(cfg.handle, cfg.store_id, enabled=False)
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{on}"), timeout=15, allow_redirects=False)
    assert r.status_code == 404
    # A jar id of another store 404s on this store (ids are store-scoped).
    enable_tip_jars(cfg.handle, cfg.store_id)
    with cfg.handle.db() as db:
        other = db.execute("SELECT id FROM stores WHERE id <> ? LIMIT 1", (cfg.store_id,)).fetchone()
    if other is not None:
        foreign = ensure_tip_jar(cfg.handle, other["id"], "Foreign jar " + secrets.token_hex(3))
        r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{foreign}"), timeout=15, allow_redirects=False)
        assert r.status_code == 404
        with cfg.handle.db() as db:
            db.execute("DELETE FROM tip_jars WHERE id = ?", (foreign,))


def test_legacy_store_link_redirects_to_oldest_enabled_jar(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    delete_tip_jars(cfg.handle, cfg.store_id)
    # No jar at all: the bare link 404s (nothing to redirect to).
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}"), timeout=15, allow_redirects=False)
    assert r.status_code == 404

    first = ensure_tip_jar(cfg.handle, cfg.store_id, "First")
    time.sleep(1.1)  # created_at is whole seconds; make the order unambiguous
    second = ensure_tip_jar(cfg.handle, cfg.store_id, "Second")
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}"), timeout=15, allow_redirects=False)
    assert r.status_code == 302, r.status_code
    assert r.headers["Location"].endswith(f"/pay/{cfg.store_id}/{first}"), r.headers["Location"]

    # Disabling the oldest jar moves the legacy link to the next enabled one.
    with cfg.handle.db() as db:
        db.execute("UPDATE tip_jars SET enabled = 0 WHERE id = ?", (first,))
    r = requests.get(_url(cfg, f"/pay/{cfg.store_id}"), timeout=15, allow_redirects=False)
    assert r.status_code == 302
    assert r.headers["Location"].endswith(f"/pay/{cfg.store_id}/{second}"), r.headers["Location"]

    # A POST to the bare link (pre-rename integrations) is served in place
    # against that default jar instead of being redirected.
    r = requests.post(
        _url(cfg, f"/pay/{cfg.store_id}"),
        data={"amount": "321", "currency": "sat", "notes": "legacy post"},
        timeout=30, allow_redirects=False,
    )
    assert r.status_code == 302, r.text[:300]
    inv_id = _invoice_id_from_location(r.headers["Location"])
    with cfg.handle.db() as db:
        row = db.execute("SELECT tip_jar_id FROM invoices WHERE id = ?", (inv_id,)).fetchone()
    assert row["tip_jar_id"] == second


def test_invoice_tagged_and_payment_page_shows_banner(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    jar = ensure_tip_jar(cfg.handle, cfg.store_id, "Busking", emoji="🎸", message="Thanks for listening!")
    ctx = browser.new_context(viewport={"width": 480, "height": 900})
    page = ctx.new_page()
    try:
        page.goto(_url(cfg, f"/pay/{cfg.store_id}/{jar}"), wait_until="networkidle")
        assert page.locator("#jar-message").inner_text() == "Thanks for listening!"
        page.fill("#amount", "2100")
        page.fill("#notes", "great set")
        page.click("button[type=submit]")
        page.wait_for_url("**/payment.php?id=*", timeout=15000)
        invoice_id = page.url.split("id=")[-1].split("&")[0]
        # Banner on the payment page: emoji + name + message.
        banner = page.locator("#tipjar-banner")
        assert banner.is_visible()
        assert page.locator("#tipjar-name").inner_text() == "Busking"
        assert page.locator("#tipjar-message").inner_text() == "Thanks for listening!"
        assert "🎸" in banner.inner_text()
        # The payer's own note still shows as the Note.
        assert "great set" in page.content()
    finally:
        ctx.close()

    with cfg.handle.db() as db:
        row = db.execute("SELECT tip_jar_id, metadata FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row["tip_jar_id"] == jar
    meta = json.loads(row["metadata"])
    assert meta["tipJarId"] == jar
    assert meta["tipJarName"] == "Busking"
    assert meta["tipJarEmoji"] == "🎸"
    assert meta["tipJarMessage"] == "Thanks for listening!"
    assert meta["itemDesc"] == "great set"

    # Editing the jar shows through on the open invoice (live row wins) ...
    with cfg.handle.db() as db:
        db.execute("UPDATE tip_jars SET message = 'Edited thanks' WHERE id = ?", (jar,))
    r = requests.get(_url(cfg, f"/payment.php?id={invoice_id}"), timeout=15)
    assert 'id="tipjar-message">Edited thanks<' in r.text
    # ... and deleting it falls back to the snapshot taken at creation.
    with cfg.handle.db() as db:
        db.execute("UPDATE invoices SET tip_jar_id = NULL WHERE tip_jar_id = ?", (jar,))
        db.execute("DELETE FROM tip_jars WHERE id = ?", (jar,))
    r = requests.get(_url(cfg, f"/payment.php?id={invoice_id}"), timeout=15)
    assert 'id="tipjar-name">Busking<' in r.text
    assert 'id="tipjar-message">Thanks for listening!<' in r.text


# ---------------------------------------------------------------------------
# Admin API
# ---------------------------------------------------------------------------


def test_api_lists_jars_with_settled_totals(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    delete_tip_jars(cfg.handle, cfg.store_id)
    a = ensure_tip_jar(cfg.handle, cfg.store_id, "Alpha", emoji="🅰️")
    b = ensure_tip_jar(cfg.handle, cfg.store_id, "Beta")
    _seed_settled_invoice(cfg, a, 1000)
    _seed_settled_invoice(cfg, a, 500)
    _seed_settled_invoice(cfg, a, 99999, status="New")        # unpaid: excluded
    _seed_settled_invoice(cfg, a, 777, status="Expired")      # never paid: excluded
    _seed_settled_invoice(cfg, None, 4242)                    # not from a jar

    r = cfg.admin.s.get(_url(cfg, f"/admin?api=tip_jars&store_id={cfg.store_id}"), timeout=15)
    assert r.status_code == 200, r.text[:300]
    d = r.json()
    assert d["effective"] is True and d["enabled"] is True and d["paymentCapable"] is True
    assert d["storeCurrency"] == "sat"
    assert d["defaultEmoji"] == "🫙"
    by_id = {j["id"]: j for j in d["jars"]}
    assert list(by_id) == [a, b], "oldest first"
    assert by_id[a]["receivedSats"] == 1500 and by_id[a]["receivedCount"] == 2
    assert by_id[a]["emoji"] == "🅰️" and by_id[a]["emojiRaw"] == "🅰️"
    assert by_id[b]["receivedSats"] == 0 and by_id[b]["receivedCount"] == 0
    assert by_id[b]["emoji"] == "🫙" and by_id[b]["emojiRaw"] is None
    assert by_id[a]["receivedFiat"] is None, "sat-priced store has no fiat approximation"
    assert by_id[a]["url"].endswith(f"/pay/{cfg.store_id}/{a}")

    # The invoices list can be narrowed to one jar and carries the jar chip data.
    r = cfg.admin.s.get(_url(cfg, f"/admin?api=invoices&store_id={cfg.store_id}&tip_jar_id={a}&limit=50"), timeout=15)
    assert r.status_code == 200
    rows = r.json()
    assert len(rows) == 4 and all(inv["tipJar"]["id"] == a and inv["tipJar"]["name"] == "Alpha" for inv in rows)
    assert r.headers.get("X-Total-Count") == "4"
    # A malformed filter is ignored rather than erroring.
    r = cfg.admin.s.get(_url(cfg, f"/admin?api=invoices&store_id={cfg.store_id}&tip_jar_id=nope'&limit=50"), timeout=15)
    assert r.status_code == 200 and len(r.json()) >= 5


def test_api_write_actions_round_trip_and_are_admin_only(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    delete_tip_jars(cfg.handle, cfg.store_id)
    admin = cfg.admin

    created = admin._post_action("create_tip_jar", store_id=cfg.store_id, name="API jar", emoji="🙏", message="ty")
    assert created.get("success"), created
    jar = created["jar"]
    assert jar["name"] == "API jar" and jar["emoji"] == "🙏" and jar["message"] == "ty" and jar["enabled"] is True

    # Duplicate name (case-insensitive) is a 400 with a readable error.
    r = admin.s.post(
        _url(cfg, "/admin"),
        data={"action": "create_tip_jar", "store_id": cfg.store_id, "name": "api JAR"},
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=15,
    )
    assert r.status_code == 400 and "already exists" in r.json()["error"]

    upd = admin._post_action("update_tip_jar", store_id=cfg.store_id, tip_jar_id=jar["id"], message="Thank you!", enabled="0")
    assert upd["jar"]["message"] == "Thank you!" and upd["jar"]["enabled"] is False
    assert upd["jar"]["emoji"] == "🙏", "partial update leaves the emoji alone"

    # Non-admin: can read, cannot write.
    uname = "tj_staff_" + secrets.token_hex(3)
    made = admin._post_action("create_user", username=uname, password="staffpw1234", role="user", store_ids=cfg.store_id)
    assert made.get("success"), made
    staff = AdminClient(cfg.handle.url)
    staff.login("staffpw1234", username=uname)
    r = staff.s.get(_url(cfg, f"/admin?api=tip_jars&store_id={cfg.store_id}"), timeout=15)
    assert r.status_code == 200 and r.json()["isAdmin"] is False
    assert [j["id"] for j in r.json()["jars"]] == [jar["id"]]
    for action, fields in (
        ("create_tip_jar", {"name": "x"}),
        ("update_tip_jar", {"tip_jar_id": jar["id"], "name": "x"}),
        ("delete_tip_jar", {"tip_jar_id": jar["id"]}),
    ):
        r = staff.s.post(
            _url(cfg, "/admin"),
            data={"action": action, "store_id": cfg.store_id, **fields},
            headers={"X-CSRF-Token": staff.csrf_token}, timeout=15,
        )
        assert r.status_code == 403, (action, r.status_code, r.text[:200])
    admin._post_action("delete_user", user_id=made["id"])

    gone = admin._post_action("delete_tip_jar", store_id=cfg.store_id, tip_jar_id=jar["id"])
    assert gone.get("success")
    r = admin.s.get(_url(cfg, f"/admin?api=tip_jars&store_id={cfg.store_id}"), timeout=15)
    assert r.json()["jars"] == []


def test_turning_switch_on_creates_default_jar(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    delete_tip_jars(cfg.handle, cfg.store_id)
    enable_tip_jars(cfg.handle, cfg.store_id, enabled=False)
    r = cfg.admin._post_action("save_store_selfserve", store_id=cfg.store_id, enabled="1", max_sats="")
    assert r.get("success"), r
    with cfg.handle.db() as db:
        jars = db.execute("SELECT name FROM tip_jars WHERE store_id = ?", (cfg.store_id,)).fetchall()
    assert [j["name"] for j in jars] == ["Tip Jar"]
    # Saving again (or turning off and on) does not add another one.
    cfg.admin._post_action("save_store_selfserve", store_id=cfg.store_id, enabled="0", max_sats="")
    cfg.admin._post_action("save_store_selfserve", store_id=cfg.store_id, enabled="1", max_sats="")
    with cfg.handle.db() as db:
        n = db.execute("SELECT COUNT(*) AS n FROM tip_jars WHERE store_id = ?", (cfg.store_id,)).fetchone()["n"]
    assert n == 1
    # The dashboard payload points the store card / banner at that jar.
    dash = cfg.admin.s.get(_url(cfg, f"/admin?api=dashboard&store_id={cfg.store_id}"), timeout=15).json()
    ss = dash["selfserve"]
    assert ss["jarCount"] == 1 and ss["defaultJarName"] == "Tip Jar"
    assert f"/pay/{cfg.store_id}/jar_" in ss["payUrl"]
    assert ss["storeUrl"].endswith(f"/pay/{cfg.store_id}")


# ---------------------------------------------------------------------------
# Admin UI
# ---------------------------------------------------------------------------


def test_tip_jars_view_tiles_detail_modal_and_invoice_filter(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    delete_tip_jars(cfg.handle, cfg.store_id)
    coffee = ensure_tip_jar(cfg.handle, cfg.store_id, "Coffee fund", emoji="☕", message="Fuel the team")
    time.sleep(1.1)
    merch = ensure_tip_jar(cfg.handle, cfg.store_id, "Merch table", emoji="👕", enabled=False)
    _seed_settled_invoice(cfg, coffee, 1500)
    _seed_settled_invoice(cfg, coffee, 500)
    _seed_settled_invoice(cfg, coffee, 12345, status="New")
    _seed_settled_invoice(cfg, merch, 7)

    ctx, page = _login_page(cfg, browser)
    try:
        # Sidebar entry sits right after Invoices.
        page.goto(_url(cfg, "/admin"), wait_until="networkidle")
        views = page.eval_on_selector_all(".nav .nav-item", "els => els.map(e => e.dataset.view)")
        assert views.index("tipjars") == views.index("invoices") + 1, views
        assert page.locator("#nav-tipjars").is_visible()

        _goto_tipjars(cfg, page)
        assert page.locator("#header-text").inner_text() == "Tip Jars"
        assert page.locator("#card-tipjars-off").is_hidden(), "switch is on: no off-banner"
        tiles = page.locator("#tipjars-grid .jar-tile")
        assert tiles.count() == 2
        first = tiles.nth(0)
        assert first.get_attribute("data-jar-id") == coffee
        assert first.locator(".jar-tile-emoji").inner_text() == "☕"
        assert first.locator(".jar-tile-name").inner_text() == "Coffee fund"
        assert first.locator(".jar-tile-amount").inner_text() == "2,000 sats"
        assert "2 payments" in first.locator(".jar-tile-sub").inner_text()
        second = tiles.nth(1)
        assert second.locator(".jar-tile-amount").inner_text() == "7 sats"
        assert "1 payment" in second.locator(".jar-tile-sub").inner_text()
        assert "disabled" in (second.get_attribute("class") or "")
        assert second.locator(".jar-tile-badge").inner_text().lower() == "off"

        # Tile → detail modal.
        first.click()
        page.wait_for_selector("#modal-tipjar-detail.visible")
        assert page.locator("#tipjar-detail-name").inner_text() == "Coffee fund"
        assert page.locator("#tipjar-detail-message").inner_text() == "Fuel the team"
        assert page.locator("#tipjar-detail-received").inner_text() == "2,000 sats"
        assert page.locator("#tipjar-detail-count").inner_text() == "2"
        link = page.locator("#tipjar-detail-link").input_value()
        assert link.endswith(f"/pay/{cfg.store_id}/{coffee}"), link
        assert page.locator("#tipjar-detail-open").get_attribute("href") == link
        assert page.locator("#tipjar-detail-qr svg").count() == 1, "QR code rendered"
        assert page.locator("#tipjar-detail-link-warning").is_hidden()
        assert page.locator("#tipjar-detail-admin").is_visible()
        assert page.locator("#btn-tipjar-toggle").inner_text() == "Disable"

        # "View invoices" → Invoices view filtered to the jar (chip + URL + rows).
        page.click("#btn-tipjar-view-invoices")
        page.wait_for_selector("#view-invoices.active")
        page.wait_for_function("() => document.querySelectorAll('#all-invoices tbody tr').length > 0", timeout=20000)
        assert "tip_jar=" + coffee in page.url
        chip = page.locator("#invoice-tipjar-filter")
        assert chip.is_visible()
        assert "Coffee fund" in page.locator("#invoice-tipjar-filter-label").inner_text()
        rows = page.locator("#all-invoices tbody tr")
        assert rows.count() == 3, "two settled + one new invoice through this jar"
        assert page.locator("#all-invoices .inv-chip-tipjar").count() == 3
        # Clearing the chip widens the list again and drops the URL param.
        page.click("#btn-clear-invoice-tipjar-filter")
        page.wait_for_function("() => !window.location.search.includes('tip_jar=')")
        page.wait_for_function("() => document.querySelectorAll('#all-invoices tbody tr').length >= 4", timeout=20000)
        assert chip.is_hidden()
    finally:
        ctx.close()


def test_tip_jars_view_create_edit_disable_delete(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    delete_tip_jars(cfg.handle, cfg.store_id)
    ctx, page = _login_page(cfg, browser)
    try:
        _goto_tipjars(cfg, page)
        assert page.locator("#tipjars-grid .empty-state").is_visible()

        # Create via the modal, picking an emoji from the grid.
        page.click("#btn-new-tipjar")
        page.wait_for_selector("#modal-tipjar-edit.visible")
        assert page.locator("#tipjar-edit-title").inner_text() == "New tip jar"
        page.fill("#tipjar-edit-name", "Street show")
        page.click("#btn-tipjar-emoji-pick")
        page.click("#tipjar-edit-emoji-picker [data-emoji='🎸']")
        assert page.locator("#tipjar-edit-emoji").input_value() == "🎸"
        assert page.locator("#tipjar-edit-emoji-preview").inner_text() == "🎸"
        page.fill("#tipjar-edit-message", "Thank you for your donation")
        page.click("#btn-save-tipjar")
        page.wait_for_selector("#modal-tipjar-edit", state="hidden")
        page.wait_for_selector("#tipjars-grid .jar-tile")
        tile = page.locator("#tipjars-grid .jar-tile").first
        assert tile.locator(".jar-tile-name").inner_text() == "Street show"
        assert tile.locator(".jar-tile-emoji").inner_text() == "🎸"
        with cfg.handle.db() as db:
            row = db.execute("SELECT * FROM tip_jars WHERE store_id = ?", (cfg.store_id,)).fetchone()
        assert row["name"] == "Street show" and row["emoji"] == "🎸"
        assert row["message"] == "Thank you for your donation" and row["enabled"] == 1
        jar_id = row["id"]

        # Duplicate name shows the server error in the modal.
        page.click("#btn-new-tipjar")
        page.wait_for_selector("#modal-tipjar-edit.visible")
        page.fill("#tipjar-edit-name", "STREET show")
        page.click("#btn-save-tipjar")
        page.wait_for_selector("#tipjar-edit-error", state="visible")
        assert "already exists" in page.locator("#tipjar-edit-error").inner_text()
        page.click("#modal-tipjar-edit .btn-secondary.btn-full")  # Cancel (the emoji buttons are .btn-secondary too)
        page.wait_for_selector("#modal-tipjar-edit", state="hidden")

        # Edit from the detail modal: rename + clear emoji → default emoji.
        tile.click()
        page.wait_for_selector("#modal-tipjar-detail.visible")
        page.click("#btn-tipjar-edit")
        page.wait_for_selector("#modal-tipjar-edit.visible")
        assert page.locator("#tipjar-edit-title").inner_text() == "Edit tip jar"
        assert page.locator("#tipjar-edit-name").input_value() == "Street show"
        page.fill("#tipjar-edit-name", "Street show 2")
        page.click("#btn-tipjar-clear-emoji")
        page.click("#btn-save-tipjar")
        page.wait_for_selector("#modal-tipjar-detail.visible")
        assert page.locator("#tipjar-detail-name").inner_text() == "Street show 2"
        assert page.locator("#tipjar-detail-emoji").inner_text() == "🫙"
        with cfg.handle.db() as db:
            row = db.execute("SELECT name, emoji FROM tip_jars WHERE id = ?", (jar_id,)).fetchone()
        assert row["name"] == "Street show 2" and row["emoji"] is None

        # Disable → public link 404s, warning shown, tile dimmed.
        page.click("#btn-tipjar-toggle")
        page.wait_for_function("() => document.getElementById('btn-tipjar-toggle').textContent === 'Enable'")
        assert page.locator("#tipjar-detail-link-warning").is_visible()
        r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{jar_id}"), timeout=15, allow_redirects=False)
        assert r.status_code == 404
        page.click("#btn-tipjar-toggle")
        page.wait_for_function("() => document.getElementById('btn-tipjar-toggle').textContent === 'Disable'")
        r = requests.get(_url(cfg, f"/pay/{cfg.store_id}/{jar_id}"), timeout=15, allow_redirects=False)
        assert r.status_code == 200

        # Delete needs the inline confirmation; Cancel keeps it.
        page.click("#btn-tipjar-delete")
        assert page.locator("#tipjar-delete-confirm").is_visible()
        assert "Street show 2" in page.locator("#tipjar-delete-name").inner_text()
        page.click("#btn-tipjar-delete-cancel")
        assert page.locator("#tipjar-delete-confirm").is_hidden()
        with cfg.handle.db() as db:
            assert db.execute("SELECT COUNT(*) AS n FROM tip_jars WHERE id = ?", (jar_id,)).fetchone()["n"] == 1
        page.click("#btn-tipjar-delete")
        page.click("#btn-tipjar-delete-confirm")
        page.wait_for_selector("#modal-tipjar-detail", state="hidden")
        page.wait_for_selector("#tipjars-grid .empty-state")
        with cfg.handle.db() as db:
            assert db.execute("SELECT COUNT(*) AS n FROM tip_jars WHERE id = ?", (jar_id,)).fetchone()["n"] == 0
    finally:
        ctx.close()


def test_tip_jars_view_off_banner_turns_switch_on(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id, enabled=False)
    delete_tip_jars(cfg.handle, cfg.store_id)
    ctx, page = _login_page(cfg, browser)
    try:
        # The sidebar entry is always there — a store with the switch off and
        # no jars at all still gets the icon (that is how you reach the view
        # to create the first one); only managed installs hide it.
        page.goto(_url(cfg, "/admin"), wait_until="networkidle")
        nav = page.locator("#nav-tipjars")
        assert nav.is_visible(), "Tip Jars nav must show for a store with no jars"
        assert "hidden" not in (nav.get_attribute("class") or "").split()
        _goto_tipjars(cfg, page)
        assert page.locator("#tipjars-grid .empty-state").is_visible()
        banner = page.locator("#card-tipjars-off")
        assert banner.is_visible()
        assert "turned off" in page.locator("#tipjars-off-text").inner_text()
        page.click("#btn-tipjars-turn-on")
        page.wait_for_selector("#card-tipjars-off.hidden", state="attached")
        # The switch save also created the default jar, now shown as a tile.
        page.wait_for_selector("#tipjars-grid .jar-tile")
        assert page.locator("#tipjars-grid .jar-tile .jar-tile-name").first.inner_text() == "Tip Jar"
        with cfg.handle.db() as db:
            row = db.execute("SELECT selfserve_enabled FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()
        assert row["selfserve_enabled"] == 1
    finally:
        ctx.close()


def test_non_admin_sees_tiles_but_no_controls(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    delete_tip_jars(cfg.handle, cfg.store_id)
    jar = ensure_tip_jar(cfg.handle, cfg.store_id, "Read only", emoji="👀")
    _seed_settled_invoice(cfg, jar, 300)
    uname = "tj_viewer_" + secrets.token_hex(3)
    made = cfg.admin._post_action("create_user", username=uname, password="viewerpw1234", role="user", store_ids=cfg.store_id)
    assert made.get("success"), made
    ctx, page = _login_page(cfg, browser, username=uname, password="viewerpw1234")
    try:
        _goto_tipjars(cfg, page)
        assert page.locator("#nav-tipjars").is_visible()
        assert page.locator("#btn-new-tipjar").is_hidden()
        tile = page.locator("#tipjars-grid .jar-tile").first
        assert tile.locator(".jar-tile-amount").inner_text() == "300 sats"
        tile.click()
        page.wait_for_selector("#modal-tipjar-detail.visible")
        assert page.locator("#tipjar-detail-link").input_value().endswith(f"/pay/{cfg.store_id}/{jar}")
        assert page.locator("#tipjar-detail-admin").is_hidden()
        # Wait rather than an instant is_visible(): the sheet is still sliding
        # in (0.3s transition) when the .visible class lands, and a one-shot
        # read in that window has come back false in CI.
        page.wait_for_selector("#btn-tipjar-view-invoices", state="visible", timeout=5000)
    finally:
        ctx.close()
        cfg.admin._post_action("delete_user", user_id=made["id"])


def test_invoice_detail_modal_shows_tip_jar(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    enable_tip_jars(cfg.handle, cfg.store_id)
    jar = ensure_tip_jar(cfg.handle, cfg.store_id, "Detail jar", emoji="🧁")
    inv = _seed_settled_invoice(cfg, jar, 150)
    r = cfg.admin.s.get(_url(cfg, f"/admin?api=invoice_detail&id={inv}"), timeout=15)
    assert r.status_code == 200
    d = r.json()
    assert d["tipJar"]["id"] == jar and d["tipJar"]["name"] == "Detail jar"
    assert d["tipJar"]["emoji"] == "🧁" and d["tipJar"]["live"] is True

    ctx, page = _login_page(cfg, browser)
    try:
        page.goto(_url(cfg, "/admin/invoices"), wait_until="networkidle")
        page.wait_for_function("() => document.querySelectorAll('#all-invoices tbody tr').length > 0", timeout=20000)
        page.evaluate(f"openInvoiceDetail({inv!r})")
        page.wait_for_selector("#invoice-detail-tipjar")
        assert page.locator("#invoice-detail-tipjar").inner_text() == "🧁 Detail jar"
    finally:
        ctx.close()
