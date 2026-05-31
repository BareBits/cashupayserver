"""Upgrade path: pre-multi-user installs only call Database::initialize()
once (during setup), so the lazy migration in Database::getInstance() must
reconstruct the users table the first time the process connects to an old
DB.

This regression test drops the users table, copies the admin's password
hash into the legacy config slot, and verifies that the next request both
creates the table and migrates the legacy admin credential.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def test_legacy_install_is_migrated_on_first_connection(
    configured: ConfiguredPayserver,
) -> None:
    # Stop the running payserver process so its in-memory PDO singleton
    # doesn't shadow the migration. Re-using the existing fixture is
    # easier than spawning a parallel one.
    from fixtures.payserver import stop_payserver, start_payserver

    stop_payserver(configured.handle)

    # Simulate a pre-multi-user DB: capture the admin's hash, drop the
    # users table, restore the legacy config slot the migration looks for.
    with configured.handle.db() as db:
        row = db.execute("SELECT password_hash FROM users WHERE username='admin'").fetchone()
        legacy_hash = row["password_hash"]
        db.execute("DROP TABLE users")
        import time
        db.execute(
            "INSERT OR REPLACE INTO config (key, value, created_at, updated_at) "
            "VALUES ('admin_password_hash', ?, ?, ?)",
            (legacy_hash, int(time.time()), int(time.time())),
        )

    # Restart the payserver — fresh PHP process, fresh PDO singleton.
    new_handle = start_payserver(configured.handle.workdir)
    try:
        # First HTTP request triggers Database::getInstance(), which should
        # create the users table and runMigrations() should populate the
        # admin row from the legacy config slot.
        r = requests.post(
            f"{new_handle.url}/admin",
            data={"action": "login", "username": "admin", "password": DEFAULT_ADMIN_PASSWORD},
            timeout=15,
        )
        assert r.status_code == 200, r.text
        assert r.json()["success"] is True
        assert r.json()["user"]["role"] == "admin"
    finally:
        stop_payserver(new_handle)
