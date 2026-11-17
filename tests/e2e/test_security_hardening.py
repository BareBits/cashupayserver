"""Security hardening from the 2026-11 codebase review (HTTP-level pins).

Each test here guards one fix:

  - the first-run wizard belongs to the session that set the admin password
    (anyone else gets a sign-in page, and can resume with the password);
  - the completed-install wizard tail screens are admin-only, not merely
    logged-in;
  - admin sessions die when the account's password is reset elsewhere;
  - the e-mailed password-reset link is built from the admin's own URL, never
    from the (forgeable) Host header of the anonymous reset request;
  - the anonymous payment page is rate-limited per client address.
"""
from __future__ import annotations

import json
import re
import uuid
from typing import Any, Iterator

import pytest
import requests

from conftest import (
    SESSION_TMP,
    ConfiguredPayserver,
    DEFAULT_ADMIN_PASSWORD,
    _configure,
    _maybe_remove_workdir,
    _test_went_green,
)
from fixtures.api_client import AdminClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard, wizard_heading
from fixtures.smtp_sink import SmtpSink, start_smtp_sink, stop_smtp_sink


def _config(handle: PayserverHandle, key: str) -> Any:
    with handle.db() as conn:
        row = conn.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    if row is None:
        return None
    try:
        return json.loads(row["value"])
    except (TypeError, ValueError):
        return row["value"]


def _admin_email(handle: PayserverHandle) -> str | None:
    with handle.db() as conn:
        row = conn.execute("SELECT email FROM users WHERE username = 'admin'").fetchone()
    return row["email"] if row else None


# ---------------------------------------------------------------------------
# Wizard ownership
# ---------------------------------------------------------------------------


def _owner_through_password(handle: PayserverHandle) -> SetupWizard:
    w = SetupWizard(handle.url)
    w.accept_terms()
    w.decline_analytics()
    w.choose_install_type("other")
    w.post(step="security", security_acknowledged="1")
    body = w.post(
        step="password",
        password=SetupWizard.DEFAULT_PASSWORD,
        confirm_password=SetupWizard.DEFAULT_PASSWORD,
    )
    assert wizard_heading(body) == "E-mail notifications", wizard_heading(body)
    return w


def test_unfinished_wizard_is_locked_to_the_session_that_set_the_password(
    payserver: PayserverHandle,
) -> None:
    owner = _owner_through_password(payserver)

    # A second, anonymous browser arrives while the owner is still on the
    # e-mail screen. It gets the sign-in page, not the wizard, and its POSTs
    # change nothing: the password-reset address stays unset.
    intruder = requests.Session()
    r = intruder.get(f"{payserver.url}/setup.php", timeout=15)
    assert r.status_code == 403, r.text[:300]
    assert 'id="setup-locked"' in r.text
    csrf = re.search(r'name="csrf_token"\s+value="([^"]+)"', r.text)
    assert csrf, "the locked page carries a CSRF token for its sign-in form"

    r = intruder.post(
        f"{payserver.url}/setup.php",
        data={
            "step": "email", "email_action": "save", "csrf_token": csrf.group(1),
            "smtp_host": "127.0.0.1", "smtp_port": "25", "smtp_encryption": "none",
            "smtp_from_address": "evil@example.com", "to_email": "evil@example.com", "reset_same": "1",
        },
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 403, r.text[:300]
    assert _admin_email(payserver) is None, "the intruder could not set the reset address"
    assert _config(payserver, "smtp_host") is None

    # Nor can it reach the store/rail screens or add_store.
    for step in ("store", "onchain", "mints", "updates"):
        r = intruder.post(
            f"{payserver.url}/setup.php",
            data={"step": step, "csrf_token": csrf.group(1), "store_name": "Hijacked"},
            timeout=15, allow_redirects=False,
        )
        assert r.status_code == 403, (step, r.status_code)
    r = intruder.get(f"{payserver.url}/setup.php", params={"mode": "add_store"}, timeout=15, allow_redirects=False)
    assert r.status_code == 403
    with payserver.db() as conn:
        assert conn.execute("SELECT COUNT(*) AS n FROM stores").fetchone()["n"] == 0

    # The owner continues unhindered.
    body = owner.post(step="email", email_action="skip")
    assert wizard_heading(body) == "Let's name your store", wizard_heading(body)

    # Wrong password on the resume form: still locked (and counted by the lockout).
    r = intruder.post(
        f"{payserver.url}/setup.php",
        data={"setup_action": "resume_login", "username": "admin", "password": "not-it-12345",
              "csrf_token": csrf.group(1)},
        timeout=15, allow_redirects=False,
    )
    assert r.status_code == 401
    assert "Invalid username or password" in r.text

    # The real password resumes the wizard in a NEW browser (lost session).
    r = intruder.post(
        f"{payserver.url}/setup.php",
        data={"setup_action": "resume_login", "username": "admin",
              "password": SetupWizard.DEFAULT_PASSWORD, "csrf_token": csrf.group(1)},
        timeout=15, allow_redirects=False,
    )
    assert r.status_code == 302, r.text[:300]
    r = intruder.get(f"{payserver.url}/setup.php", timeout=15)
    assert r.status_code == 200
    assert 'id="setup-locked"' not in r.text
    assert "csrf_token" in r.text


def test_wizard_stays_anonymous_until_the_admin_exists(payserver: PayserverHandle) -> None:
    """Before the password screen there is nobody to authenticate: a second
    session may still open the wizard (first come, first served on a fresh
    install — exactly as before)."""
    w = SetupWizard(payserver.url)
    w.accept_terms()
    other = requests.Session()
    r = other.get(f"{payserver.url}/setup.php", timeout=15)
    assert r.status_code == 200
    assert 'id="setup-locked"' not in r.text


# ---------------------------------------------------------------------------
# Tail screens on a completed install: admin only
# ---------------------------------------------------------------------------


def test_tail_screens_require_admin_not_just_a_login(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    r = admin._post_action("create_user", username="staff", password="staffpw1234", role="user")
    assert r.get("success"), r
    staff = AdminClient(configured.handle.url)
    staff.login("staffpw1234", username="staff")

    before = _config(configured.handle, "auto_update_enabled")
    r = staff.s.post(
        f"{configured.handle.url}/setup.php",
        data={"step": "updates", "auto_update": "1", "csrf_token": staff.csrf_token},
        timeout=15, allow_redirects=False,
    )
    assert r.status_code == 302, (r.status_code, r.text[:200])
    assert _config(configured.handle, "auto_update_enabled") == before, "a user-role account changed nothing"
    r = staff.s.post(
        f"{configured.handle.url}/setup.php",
        data={"step": "cron", "csrf_token": staff.csrf_token},
        timeout=15, allow_redirects=False,
    )
    assert r.status_code == 302
    assert "cron" not in r.text.lower() or r.text == "", "no cron key rendered for a user-role account"

    # The admin still can.
    r = admin.s.post(
        f"{configured.handle.url}/setup.php",
        data={"step": "updates", "auto_update": "0", "csrf_token": admin.csrf_token},
        timeout=15, allow_redirects=False,
    )
    assert r.status_code == 200, (r.status_code, r.text[:200])


# ---------------------------------------------------------------------------
# Session revocation
# ---------------------------------------------------------------------------


def test_password_reset_ends_other_sessions(configured: ConfiguredPayserver) -> None:
    first = configured.admin  # already logged in
    second = AdminClient(configured.handle.url)
    second.login(DEFAULT_ADMIN_PASSWORD)
    assert first.get_dashboard().get("stores") is not None
    assert second.get_dashboard().get("stores") is not None

    # The second browser changes its own password: it stays signed in, the
    # first browser's session is gone on its next request.
    r = second._post_action(
        "change_own_password", current_password=DEFAULT_ADMIN_PASSWORD, new_password="rotated-pw-12345"
    )
    assert r.get("success"), r
    assert second.get_dashboard().get("stores") is not None, "the changing session survives"
    r = first.s.get(f"{configured.handle.url}/admin?api=dashboard", timeout=15)
    assert r.status_code == 401, (r.status_code, r.text[:200])

    # A fresh login with the new password works; the old one is refused.
    third = AdminClient(configured.handle.url)
    with pytest.raises(Exception):
        third.login(DEFAULT_ADMIN_PASSWORD)
    third.login("rotated-pw-12345")
    assert third.get_dashboard().get("stores") is not None


def test_deleting_a_user_ends_its_session(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    r = admin._post_action("create_user", username="temp", password="temppw1234", role="user")
    staff = AdminClient(configured.handle.url)
    staff.login("temppw1234", username="temp")
    assert staff.s.get(f"{configured.handle.url}/admin?api=dashboard", timeout=15).status_code == 200
    admin._post_action("delete_user", user_id=r["id"])
    assert staff.s.get(f"{configured.handle.url}/admin?api=dashboard", timeout=15).status_code == 401


# ---------------------------------------------------------------------------
# Password-reset link base URL
# ---------------------------------------------------------------------------


@pytest.fixture
def smtp_sink() -> Iterator[SmtpSink]:
    sink = start_smtp_sink()
    yield sink
    stop_smtp_sink(sink)


def test_reset_link_ignores_forged_host_header(configured: ConfiguredPayserver, smtp_sink: SmtpSink) -> None:
    admin = configured.admin
    r = admin._post_action(
        "save_notifications_settings",
        enabled="1", to_email="owner@example.com",
        smtp_host=smtp_sink.host, smtp_port=str(smtp_sink.port), smtp_encryption="none",
        smtp_username=smtp_sink.username, smtp_password=smtp_sink.password,
        smtp_from_address="shop@example.com", smtp_from_name="Shop",
    )
    assert r.get("success"), r
    admin._post_action("set_recovery_email", email="ops@example.com")

    # Anonymous reset request with a forged Host header.
    anon = requests.Session()
    r = anon.post(
        f"{configured.handle.url}/admin",
        data={"action": "request_password_reset", "email": "ops@example.com"},
        headers={"Host": "evil.example"},
        timeout=20,
    )
    assert r.status_code == 200, r.text
    msgs = smtp_sink.wait_for_messages(1, timeout_s=20)
    assert msgs, "the reset mail was sent"
    body = msgs[-1].data
    assert "evil.example" not in body, body
    # The admin fixture signed in at the real URL: that is what the link uses.
    assert configured.handle.url.rstrip("/") in body, body
    assert "action=reset&token=" in body


def test_reset_mail_without_a_known_url_sends_the_code_not_a_host_link(
    configured: ConfiguredPayserver, smtp_sink: SmtpSink
) -> None:
    """With nothing recorded (no sign-in origin, no base_url) the mail
    carries the reset code and instructions instead of a Host-derived link."""
    admin = configured.admin
    r = admin._post_action(
        "save_notifications_settings",
        enabled="1", to_email="owner@example.com",
        smtp_host=smtp_sink.host, smtp_port=str(smtp_sink.port), smtp_encryption="none",
        smtp_username=smtp_sink.username, smtp_password=smtp_sink.password,
        smtp_from_address="shop@example.com", smtp_from_name="Shop",
    )
    assert r.get("success"), r
    admin._post_action("set_recovery_email", email="ops@example.com")
    with configured.handle.db() as conn:
        conn.execute("DELETE FROM config WHERE key IN ('admin_base_url', 'base_url')")

    anon = requests.Session()
    r = anon.post(
        f"{configured.handle.url}/admin",
        data={"action": "request_password_reset", "email": "ops@example.com"},
        headers={"Host": "evil.example"},
        timeout=20,
    )
    assert r.status_code == 200, r.text
    msgs = smtp_sink.wait_for_messages(1, timeout_s=20)
    assert msgs
    data = msgs[-1].data
    assert "evil.example" not in data, data
    # Only the message body matters (the headers carry PHPMailer's X-Mailer URL).
    body = data.replace("\r\n", "\n").split("\n\n", 1)[1]
    assert "?action=reset&token=" in body
    assert "http://" not in body and "https://" not in body, "no absolute link without a trusted base URL"


# ---------------------------------------------------------------------------
# Payment page rate limit
# ---------------------------------------------------------------------------


def test_payment_page_is_rate_limited_per_client(request, mint, backup_mint) -> None:
    """GET polls and POST actions on payment.php share per-address budgets
    (lowered here through the env override so the test stays quick)."""
    workdir = SESSION_TMP / f"payserver-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHUPAY_PAYMENT_RATE_LIMIT": "6", "CASHUPAY_PAYMENT_POST_RATE_LIMIT": "3"},
    )
    try:
        cfg = _configure(handle, mint, backup_mint)
        inv = cfg.greenfield.create_invoice(cfg.store_id, amount="21", currency="sat")
        s = requests.Session()
        url = f"{handle.url}/payment.php"

        statuses = []
        for _ in range(8):
            r = s.get(url, params={"id": inv["id"], "json": "1"}, timeout=15)
            statuses.append(r.status_code)
        assert statuses[:6] == [200] * 6, statuses
        assert 429 in statuses[6:], statuses
        limited = s.get(url, params={"id": inv["id"], "json": "1"}, timeout=15)
        assert limited.status_code == 429
        assert limited.json()["error"]
        assert limited.headers.get("Retry-After") == "60"
        html = s.get(url, params={"id": inv["id"]}, timeout=15)
        assert html.status_code == 429, "the HTML view shares the GET budget"

        post_statuses = []
        for _ in range(5):
            r = s.post(url, params={"id": inv["id"]}, data={"action": "noffer_receipt", "event": "{}"}, timeout=15)
            post_statuses.append(r.status_code)
        assert all(code < 429 for code in post_statuses[:3]), post_statuses  # served (receipt rejected or not)
        assert post_statuses[3:] == [429] * 2, post_statuses
    finally:
        stop_payserver(handle)
        _maybe_remove_workdir(workdir, _test_went_green(request))
