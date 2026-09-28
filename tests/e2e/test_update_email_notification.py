"""E2e for the "update available" admin email.

With auto-update off (the default), the cron pass that refreshes the cached
availability verdict must also queue exactly one email per release to the
site-wide notification address — gated on the notifications master switch —
and never when automatic updates are enabled.

Delivery transport is deliberately not asserted here: the e2e stack has no
MTA/SMTP server (see test_smtp_settings.py). The queue row is the contract;
the send path (including the EmailSender transport selection) is covered by
tests/php/test_update_available_notification.php via the transport override.
"""
from __future__ import annotations

import json
import time

from conftest import ConfiguredPayserver
from fixtures.payserver import PayserverHandle


def _set_config(handle: PayserverHandle, key: str, value) -> None:
    """Seed a config row the way Config::set would (non-strings JSON-encoded)."""
    now = int(time.time())
    with handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, json.dumps(value), now, now),
        )


def _get_config(handle: PayserverHandle, key: str):
    with handle.db() as db:
        row = db.execute("SELECT value FROM config WHERE key = ?", (key,)).fetchone()
    if row is None:
        return None
    try:
        return json.loads(row["value"])
    except (ValueError, TypeError):
        return row["value"]


def _seed_available(handle: PayserverHandle, sha: str, version: str) -> None:
    _set_config(handle, "updater_available", {
        "available": True,
        "channel": "main",
        "current_version": "0.0-old",
        "current_sha": "0" * 40,
        "latest_version": version,
        "latest_sha": sha,
        "blocked": False,
        # Fresh stamp so the (test-disabled) checker returns this verdict
        # untouched instead of re-probing GitHub.
        "checked_at": int(time.time()),
    })


def _update_emails(handle: PayserverHandle) -> list:
    with handle.db() as db:
        return db.execute(
            "SELECT * FROM notification_queue WHERE event_type = 'UpdateAvailable' ORDER BY id ASC"
        ).fetchall()


def _run_cron(configured: ConfiguredPayserver) -> dict:
    """One full external cron pass of OURS. An opportunistic background cron
    can hold the cron lock (yielding {"skipped": ...}) — retry until a run of
    ours actually executes, so the post-run assertions are deterministic."""
    deadline = time.monotonic() + 30
    while True:
        body = configured.handle.trigger_cron_json()
        if "tasks" in body:
            return body
        assert "skipped" in body, body
        assert time.monotonic() < deadline, f"cron lock never freed: {body}"
        time.sleep(1)


def test_update_email_queued_once_per_release(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    sha_a = "a" * 40

    # Gates open: master switch on, site-wide address set, auto-update off
    # (the default — no auto_update_enabled row exists on a fresh install).
    _set_config(handle, "notifications_enabled", True)
    _set_config(handle, "notifications_to_email", "ops@example.com")
    _seed_available(handle, sha_a, "9.9.9-test")

    _run_cron(configured)

    rows = _update_emails(handle)
    assert len(rows) == 1, f"exactly one update email queued, got {len(rows)}"
    assert rows[0]["to_email"] == "ops@example.com"
    assert "9.9.9-test" in rows[0]["subject"], "subject must name the new version"
    assert "Automatic updates are disabled" in rows[0]["body"]
    assert _get_config(handle, "updater_notified_sha") == sha_a, (
        "the once-per-release marker must be stamped when the email is queued"
    )

    # A second cron pass over the same release must not queue a duplicate.
    _run_cron(configured)
    assert len(_update_emails(handle)) == 1, "same release must not re-notify"

    # A NEW release notifies again.
    sha_b = "b" * 40
    _seed_available(handle, sha_b, "9.9.10-test")
    _run_cron(configured)
    rows = _update_emails(handle)
    assert len(rows) == 2, "a new release must queue a second email"
    assert "9.9.10-test" in rows[1]["subject"]
    assert _get_config(handle, "updater_notified_sha") == sha_b

    # With automatic updates enabled the release applies on its own — no email.
    _set_config(handle, "auto_update_enabled", True)
    _seed_available(handle, "c" * 40, "9.9.11-test")
    _run_cron(configured)
    assert len(_update_emails(handle)) == 2, "auto-update on must suppress the email"
    _set_config(handle, "auto_update_enabled", False)


def test_update_email_respects_master_switch(configured: ConfiguredPayserver) -> None:
    handle = configured.handle
    sha = "d" * 40

    _set_config(handle, "notifications_enabled", False)
    _set_config(handle, "notifications_to_email", "ops@example.com")
    _seed_available(handle, sha, "9.9.12-test")

    _run_cron(configured)
    assert not any("9.9.12-test" in r["subject"] for r in _update_emails(handle)), (
        "master switch off must suppress the update email"
    )
    # The marker must not be stamped while gated: flipping the switch on
    # makes the very next cron pass deliver the owed email.
    assert _get_config(handle, "updater_notified_sha") != sha

    _set_config(handle, "notifications_enabled", True)
    _run_cron(configured)
    assert any("9.9.12-test" in r["subject"] for r in _update_emails(handle)), (
        "opening the gate must deliver the previously-owed notification"
    )
    assert _get_config(handle, "updater_notified_sha") == sha
