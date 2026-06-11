"""health.php — the bootstrap probe the isolated updater uses to verify an
applied update before keeping it.

A healthy install must return 200 {"ok": true} when given the cron key, and
must refuse unauthenticated callers. The crash/rollback path (probe returns
5xx -> update.php rolls back) is covered hermetically by the PHP unit tests
(test_update_php_helpers.php); here we just confirm the live endpoint boots the
full app and is key-gated.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.payserver import PayserverHandle


def _cron_key(handle: PayserverHandle) -> str:
    with handle.db() as db:
        row = db.execute(
            "SELECT value FROM config WHERE key = 'cron_key'"
        ).fetchone()
    assert row is not None, "cron_key not seeded"
    # Stored as a raw string (Config::set leaves strings unencoded).
    return str(row[0]).strip('"')


def test_health_requires_cron_key(configured: ConfiguredPayserver) -> None:
    r = requests.get(f"{configured.handle.url}/health.php", timeout=10)
    assert r.status_code == 403, r.text
    assert r.json()["ok"] is False


def test_health_rejects_wrong_key(configured: ConfiguredPayserver) -> None:
    r = requests.get(
        f"{configured.handle.url}/health.php",
        params={"key": "nope"},
        timeout=10,
    )
    assert r.status_code == 403, r.text


def test_health_ok_when_app_boots(configured: ConfiguredPayserver) -> None:
    """With the real cron key, the full bootstrap loads and the DB is
    reachable, so the probe reports healthy."""
    key = _cron_key(configured.handle)
    r = requests.get(
        f"{configured.handle.url}/health.php",
        params={"key": key},
        timeout=15,
    )
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["ok"] is True, body
    # It also echoes the running build's version/sha for visibility.
    assert "version" in body
