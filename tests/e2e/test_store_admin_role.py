"""Store admin role + per-user store grants, end to end.

Two stores on the shared server (this test's own store plus a second one
created through the add_store wizard), one store admin holding only the
first, one user holding only the first, driven both over the admin
endpoints and through the admin SPA in Chromium:

- API: the store admin changes settings of its store (tip jar, Cashu
  maximum, trusted-mints opt-out) and is refused on the other store and on
  every site-wide setting; its dashboard / stores list / invoices never
  mention the other store; a user can create an invoice (Request Payment
  path: the store's internal key) on its store only.
- API: the store admin creates a user on its store, cannot grant the other
  store, cannot create an admin; the admin edits grants + role; a deleted
  store disappears from the grants.
- UI (store admin): nav shows Stats/Products/Customers/Rewards/Log, the
  store selector lists only the held store and has no "+ Create Store"
  entry, Settings shows the Users card with store checkboxes but none of
  the site-wide cards, the Cashu section carries the trusted-mints switch
  and flipping it persists.
- UI (user): no Users card, no manager nav items, recovery e-mail row shown.
- Password reset by e-mail works for a user-role account (token minted).
"""
from __future__ import annotations

import secrets
import time

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import AdminClient, GreenfieldClient
from fixtures.browser import expand_settings_cards, wait_for_dashboard_settled
from fixtures.setup_helpers import run_add_store_wizard


def _url(cfg: ConfiguredPayserver, path: str) -> str:
    return f"{cfg.handle.url.rstrip('/')}{path}"


def _raw(client: AdminClient, cfg: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    return client.s.post(
        _url(cfg, "/admin"),
        data={"action": action, **fields},
        headers={"X-CSRF-Token": client.csrf_token},
        timeout=30,
    )


def _get(client: AdminClient, cfg: ConfiguredPayserver, api: str, **params) -> requests.Response:
    return client.s.get(_url(cfg, "/admin"), params={"api": api, **params}, timeout=30)


def _second_store(cfg: ConfiguredPayserver, mint, backup_mint) -> str:
    name = "other-" + secrets.token_hex(4)
    run_add_store_wizard(
        cfg.handle.url,
        admin_password=cfg.admin_password,
        store_name=name,
        mint_url=mint.url,
        backup_mint_url=backup_mint.url,
    )
    return next(s["id"] for s in cfg.admin.list_stores() if s["name"] == name)


def _login_client(cfg: ConfiguredPayserver, username: str, password: str) -> AdminClient:
    c = AdminClient(cfg.handle.url)
    c.login(password, username=username)
    return c


def _create(cfg: ConfiguredPayserver, username: str, password: str, role: str, store_ids: list[str], **extra) -> str:
    r = cfg.admin._post_action("create_user", username=username, password=password, role=role,
                               store_ids=",".join(store_ids), **extra)
    assert r.get("success"), r
    return r["id"]


def _login_page(cfg: ConfiguredPayserver, browser, username: str, password: str, store_id: str | None = None):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    ctx.add_init_script(f"localStorage.setItem('selectedStoreId', {(store_id or cfg.store_id)!r})")
    ctx.request.post(_url(cfg, "/admin"), form={"action": "login", "username": username, "password": password})
    page = ctx.new_page()
    page.set_default_timeout(20000)
    return ctx, page


# ---------------------------------------------------------------------------
# API level
# ---------------------------------------------------------------------------


def test_store_admin_api_scope(shared_configured: ConfiguredPayserver, mint, backup_mint) -> None:
    cfg = shared_configured
    other = _second_store(cfg, mint, backup_mint)
    tag = secrets.token_hex(3)
    sa_id = _create(cfg, f"sa_{tag}", "sa-pw-123456", "store_admin", [cfg.store_id], email=f"sa_{tag}@example.com")
    user_id = _create(cfg, f"u_{tag}", "user-pw-123456", "user", [cfg.store_id])
    try:
        sa = _login_client(cfg, f"sa_{tag}", "sa-pw-123456")
        user = _login_client(cfg, f"u_{tag}", "user-pw-123456")

        # Visibility
        stores = _get(sa, cfg, "dashboard").json()["stores"]
        assert [s["id"] for s in stores] == [cfg.store_id]
        assert _get(sa, cfg, "dashboard", store_id=other).status_code == 404
        assert [s["id"] for s in _get(sa, cfg, "stores").json()] == [cfg.store_id]
        assert _get(sa, cfg, "invoices", store_id=other).status_code == 403
        assert _get(sa, cfg, "invoices").status_code == 200
        assert _get(sa, cfg, "api_keys", store_id=cfg.store_id).status_code == 200
        assert _get(sa, cfg, "api_keys", store_id=other).status_code == 403
        assert _get(sa, cfg, "stats_summary", store_id="__all__", range="all").status_code == 200
        assert _get(sa, cfg, "stats_summary", store_id=other, range="all").status_code == 403
        for api in ("cron_url", "update_status", "get_trusted_mints_settings", "export_diagnostic_report"):
            assert _get(sa, cfg, api).status_code == 403, api

        # Store-scoped writes: own store yes, other store no.
        r = _raw(sa, cfg, "create_tip_jar", store_id=cfg.store_id, name=f"Jar {tag}", emoji="🙏", message="ty")
        assert r.status_code == 200, r.text
        jar_id = r.json()["jar"]["id"]
        assert _raw(sa, cfg, "create_tip_jar", store_id=other, name="nope").status_code == 403
        assert _raw(sa, cfg, "save_cashu_limit", store_id=cfg.store_id, max_sats="7777", respect_always="1").status_code == 200
        assert _raw(sa, cfg, "save_cashu_limit", store_id=other, max_sats="1", respect_always="1").status_code == 403
        assert _raw(sa, cfg, "save_store_trusted_mints", store_id=cfg.store_id, enabled="0").status_code == 200
        with cfg.handle.db() as db:
            row = db.execute("SELECT trusted_mints_enabled, cashu_max_invoice_sats FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()
            assert row["trusted_mints_enabled"] == 0 and row["cashu_max_invoice_sats"] == 7777
            assert db.execute("SELECT trusted_mints_enabled FROM stores WHERE id = ?", (other,)).fetchone()[0] == 1
        assert _raw(sa, cfg, "save_store_trusted_mints", store_id=cfg.store_id, enabled="1").status_code == 200
        assert _get(sa, cfg, "dashboard", store_id=cfg.store_id).json()["trustedMints"]["enabled"] is True

        # Site-wide writes stay admin-only.
        for action, fields in [
            ("create_store", {"name": "nope"}),
            ("delete_store", {"store_id": cfg.store_id}),
            ("save_url_mode", {"mode": "router"}),
            ("save_notifications_settings", {"enabled": "0"}),
            ("save_login_timeout", {"seconds": "3600"}),
            ("save_trusted_mints_settings", {"url": ""}),
            ("save_developer_settings", {}),
        ]:
            assert _raw(sa, cfg, action, **fields).status_code == 403, action
        assert cfg.admin._post_action("list_users")  # admin still fine

        # Store admin creates / edits users within its stores only.
        r = _raw(sa, cfg, "create_user", username=f"made_{tag}", password="made-pw-123456", role="user", store_ids=cfg.store_id)
        assert r.status_code == 200, r.text
        made_id = r.json()["id"]
        assert _raw(sa, cfg, "create_user", username=f"x_{tag}", password="made-pw-123456", role="user", store_ids=other).status_code == 400
        assert _raw(sa, cfg, "create_user", username=f"y_{tag}", password="made-pw-123456", role="admin").status_code == 400
        assert _raw(sa, cfg, "create_user", username=f"z_{tag}", password="made-pw-123456", role="store_admin", store_ids=cfg.store_id).status_code == 200
        listed = _raw(sa, cfg, "list_users").json()
        assert [s["id"] for s in listed["stores"]] == [cfg.store_id]
        names = {u["username"]: u for u in listed["users"]}
        assert names[f"made_{tag}"]["canManage"] is True
        assert names["admin"]["canManage"] is False
        assert _raw(sa, cfg, "update_user", user_id=made_id, store_ids=other).status_code == 400
        assert _raw(sa, cfg, "reset_password", user_id=made_id, new_password="reset-pw-123456").status_code == 200
        assert _raw(sa, cfg, "delete_user", user_id=made_id).status_code == 200
        z_id = names[f"z_{tag}"]["id"]
        assert _raw(sa, cfg, "delete_user", user_id=z_id).status_code == 200

        # User: can create an invoice on its store through the dashboard key.
        dash = _get(user, cfg, "dashboard", store_id=cfg.store_id).json()
        key = next(s["internalApiKey"] for s in dash["stores"] if s["id"] == cfg.store_id)
        gc = GreenfieldClient(cfg.handle.url, key)
        inv = gc.create_invoice(cfg.store_id, "100", "sat")
        assert inv["id"]
        assert _get(user, cfg, "invoice_detail", id=inv["id"]).status_code == 200
        assert _get(user, cfg, "dashboard", store_id=other).status_code == 404
        assert _raw(user, cfg, "update_store", store_id=cfg.store_id, name="x").status_code == 403
        assert _raw(user, cfg, "list_users").status_code == 403
        assert _raw(user, cfg, "set_recovery_email", email=f"u_{tag}@example.com").status_code == 200

        # Password reset by e-mail for a user-role account mints a token.
        anon = requests.Session()
        r = anon.post(_url(cfg, "/admin"), data={"action": "request_password_reset", "email": f"u_{tag}@example.com"}, timeout=15)
        assert r.status_code == 200 and "admin" not in r.json()["message"].lower()
        with cfg.handle.db() as db:
            n = db.execute("SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL", (user_id,)).fetchone()[0]
        assert n == 1, "a reset token was minted for the user-role account"

        # Admin edits grants + role; deleting a store drops it from the grants.
        r = cfg.admin._post_action("update_user", user_id=user_id, store_ids=f"{cfg.store_id},{other}")
        assert sorted(r["user"]["storeIds"]) == sorted([cfg.store_id, other])
        assert _get(user, cfg, "dashboard", store_id=other).status_code == 200, "new grant takes effect at once"
        r = cfg.admin._post_action("update_user", user_id=user_id, role="store_admin")
        assert r["user"]["role"] == "store_admin"
        assert _raw(user, cfg, "update_store", store_id=other, name=f"Renamed {tag}").status_code == 200
        cfg.admin._post_action("delete_store", store_id=other)
        listed = cfg.admin._post_action("list_users")["users"]
        assert next(u for u in listed if u["id"] == user_id)["storeIds"] == [cfg.store_id]
        assert _get(sa, cfg, "dashboard", store_id=cfg.store_id).status_code == 200
        cfg.admin._post_action("delete_tip_jar", store_id=cfg.store_id, tip_jar_id=jar_id)
    finally:
        for uid in (sa_id, user_id):
            try:
                cfg.admin._post_action("delete_user", user_id=uid)
            except Exception:
                pass
        cfg.admin._post_action("save_cashu_limit", store_id=cfg.store_id, max_sats="", respect_always="1")


# ---------------------------------------------------------------------------
# UI
# ---------------------------------------------------------------------------


def test_store_admin_ui(shared_configured: ConfiguredPayserver, browser, mint, backup_mint) -> None:
    cfg = shared_configured
    other = _second_store(cfg, mint, backup_mint)
    tag = secrets.token_hex(3)
    sa_id = _create(cfg, f"sa_{tag}", "sa-pw-123456", "store_admin", [cfg.store_id])
    ctx, page = _login_page(cfg, browser, f"sa_{tag}", "sa-pw-123456")
    try:
        page.goto(_url(cfg, "/admin"), wait_until="networkidle")
        wait_for_dashboard_settled(page)
        # Manager nav entries are shown, store selector is scoped, no Create Store.
        for nav in ("nav-stats", "nav-products", "nav-customers", "nav-rewards", "nav-log"):
            assert page.locator(f"#{nav}").is_visible(), nav
        options = page.locator("#store-select option").evaluate_all("els => els.map(e => e.value)")
        assert options == [cfg.store_id], options
        assert "__create__" not in options

        # Settings: Users card present with store checkboxes; site-wide cards hidden.
        page.click('.nav-item[data-view="settings"]')
        page.wait_for_selector("#view-settings.active")
        expand_settings_cards(page)
        page.wait_for_selector("#card-users:not(.hidden)")
        page.wait_for_selector("#users-list [data-testid=user-row]")
        assert "store admin" in page.locator("#my-role-badge").text_content()
        assert page.locator("#recovery-email-row").is_visible()
        assert page.locator("#login-timeout-row").is_hidden()
        for card in ("card-cron-url", "card-auto-update", "card-notifications", "card-trusted-mints", "card-mint-reliability"):
            assert page.locator(f"#{card}").is_hidden() or page.locator(f"#{card} .card-body").is_hidden(), card
        assert page.locator("#card-users").is_visible()
        rows = page.locator("#users-list [data-testid=user-row]")
        admin_row = rows.filter(has_text="admin").first
        assert admin_row.locator("button").count() == 0, "admins are read-only for a store admin"
        page.click("#btn-add-user")
        page.wait_for_selector("#modal-add-user.visible")
        boxes = page.locator("#au-stores input.user-store-box").evaluate_all("els => els.map(e => e.value)")
        assert boxes == [cfg.store_id], boxes
        roles = page.locator("#au-role option").evaluate_all("els => els.map(e => e.value)")
        assert roles == ["store_admin", "user"], roles
        page.fill("#au-username", f"uiuser_{tag}")
        page.fill("#au-password", "uiuser-pw-123456")
        page.select_option("#au-role", "user")
        page.check(f'#au-stores input.user-store-box[value="{cfg.store_id}"]')
        page.click("#btn-confirm-add-user")
        page.wait_for_function(f"() => document.querySelector('#users-list').innerText.includes('uiuser_{tag}')")
        with cfg.handle.db() as db:
            uid = db.execute("SELECT id FROM users WHERE username = ?", (f"uiuser_{tag}",)).fetchone()[0]
            grants = [r[0] for r in db.execute("SELECT store_id FROM user_store_access WHERE user_id = ?", (uid,))]
        assert grants == [cfg.store_id]

        # Edit modal: promote the new user to store admin and set an e-mail.
        row = page.locator(f'#users-list [data-testid=user-row][data-user-id="{uid}"]')
        row.locator('button[data-user-action="edit"]').click()
        page.wait_for_selector("#modal-edit-user.visible")
        assert page.locator("#eu-username").text_content() == f"uiuser_{tag}"
        assert page.locator("#eu-stores input.user-store-box").evaluate_all("els => els.map(e => e.checked)") == [True]
        page.select_option("#eu-role", "store_admin")
        page.fill("#eu-email", f"uiuser_{tag}@example.com")
        page.click("#btn-confirm-edit-user")
        page.wait_for_function(
            f"() => [...document.querySelectorAll('#users-list [data-testid=user-row]')]"
            f".some(r => r.dataset.userId === {uid!r} && r.querySelector('.user-role-badge').textContent.trim() === 'store admin')"
        )
        with cfg.handle.db() as db:
            urow = db.execute("SELECT role, email FROM users WHERE id = ?", (uid,)).fetchone()
        assert (urow["role"], urow["email"]) == ("store_admin", f"uiuser_{tag}@example.com")
        # Unticking the only store is refused and leaves the grant alone.
        row.locator('button[data-user-action="edit"]').click()
        page.wait_for_selector("#modal-edit-user.visible")
        page.uncheck(f'#eu-stores input.user-store-box[value="{cfg.store_id}"]')
        page.click("#btn-confirm-edit-user")
        page.wait_for_function("() => document.getElementById('toast').classList.contains('show') && document.getElementById('toast').textContent.includes('at least one store')")
        with cfg.handle.db() as db:
            grants = [r[0] for r in db.execute("SELECT store_id FROM user_store_access WHERE user_id = ?", (uid,))]
        assert grants == [cfg.store_id]
        page.click("#modal-edit-user .btn-secondary")

        # Stores view: Cashu section carries the trusted-mints switch; flipping persists.
        page.click('.nav-item[data-view="stores"]')
        page.wait_for_selector("#view-stores.active")
        expand_settings_cards(page)
        assert page.locator("#btn-create-store").is_hidden()
        page.evaluate("() => document.getElementById('ln-section-cashu').classList.remove('collapsed')")
        box = page.locator("#store-trusted-mints-enabled")
        page.wait_for_function("() => document.getElementById('store-trusted-mints-enabled') !== null")
        assert box.is_checked()
        seq = page.evaluate("() => dashboardLoadSeq")
        # The toggle's <input> is CSS-hidden; the label is the clickable slider.
        page.locator("label.toggle:has(#store-trusted-mints-enabled)").click()
        wait_for_dashboard_settled(page, after_seq=seq)
        with cfg.handle.db() as db:
            assert db.execute("SELECT trusted_mints_enabled FROM stores WHERE id = ?", (cfg.store_id,)).fetchone()[0] == 0
        page.reload(wait_until="networkidle")
        wait_for_dashboard_settled(page)
        page.click('.nav-item[data-view="stores"]')
        page.wait_for_selector("#view-stores.active")
        expand_settings_cards(page)
        page.evaluate("() => document.getElementById('ln-section-cashu').classList.remove('collapsed')")
        page.wait_for_function("() => document.getElementById('store-trusted-mints-enabled').checked === false")
        page.locator("label.toggle:has(#store-trusted-mints-enabled)").click()
        page.wait_for_function(
            "() => fetch(adminUrl + '?api=dashboard&store_id=' + encodeURIComponent(currentStoreId)).then(r => r.json()).then(d => d.trustedMints.enabled === true)"
        )
    finally:
        ctx.close()
        for uname in (f"uiuser_{tag}",):
            with cfg.handle.db() as db:
                row = db.execute("SELECT id FROM users WHERE username = ?", (uname,)).fetchone()
            if row:
                cfg.admin._post_action("delete_user", user_id=row[0])
        cfg.admin._post_action("delete_user", user_id=sa_id)
        cfg.admin._post_action("delete_store", store_id=other)
        cfg.admin._post_action("save_store_trusted_mints", store_id=cfg.store_id, enabled="1")


def test_user_role_ui(shared_configured: ConfiguredPayserver, browser) -> None:
    cfg = shared_configured
    tag = secrets.token_hex(3)
    uid = _create(cfg, f"u_{tag}", "user-pw-123456", "user", [cfg.store_id])
    ctx, page = _login_page(cfg, browser, f"u_{tag}", "user-pw-123456")
    try:
        page.goto(_url(cfg, "/admin"), wait_until="networkidle")
        wait_for_dashboard_settled(page)
        for nav in ("nav-stats", "nav-products", "nav-customers", "nav-rewards", "nav-log"):
            assert page.locator(f"#{nav}").is_hidden(), nav
        options = page.locator("#store-select option").evaluate_all("els => els.map(e => e.value)")
        assert "__create__" not in options
        page.click('.nav-item[data-view="settings"]')
        page.wait_for_selector("#view-settings.active")
        expand_settings_cards(page)
        assert "user" in page.locator("#my-role-badge").text_content()
        assert page.locator("#card-users").is_hidden()
        assert page.locator("#recovery-email-row").is_visible(), "users can set a recovery e-mail now"
        page.fill("#recovery-email-input", f"u_{tag}@example.com")
        page.click("#btn-save-recovery-email")
        page.wait_for_function(
            f"() => fetch(adminUrl + '?api=dashboard').then(() => true)"
        )
        deadline = time.time() + 10
        while time.time() < deadline:
            with cfg.handle.db() as db:
                email = db.execute("SELECT email FROM users WHERE id = ?", (uid,)).fetchone()[0]
            if email == f"u_{tag}@example.com":
                break
            time.sleep(0.2)
        assert email == f"u_{tag}@example.com"
    finally:
        ctx.close()
        cfg.admin._post_action("delete_user", user_id=uid)
