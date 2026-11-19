"""Tip jar seeding helpers for e2e tests.

The public pay page (/pay/{store}/{jar}) needs the store's master switch on
(stores.selfserve_enabled — the column keeps its pre-rename name) AND an
enabled jar. Tests that only care about the payer side seed both straight on
the database, mirroring what TipJar::create / SelfServe::setStoreOverride
write, so the admin UI stays out of the picture.
"""
from __future__ import annotations

import secrets
import time

from fixtures.payserver import PayserverHandle


def enable_tip_jars(handle: PayserverHandle, store_id: str, enabled: bool = True) -> None:
    with handle.db() as db:
        db.execute(
            "UPDATE stores SET selfserve_enabled = ? WHERE id = ?",
            (1 if enabled else 0, store_id),
        )


def ensure_tip_jar(
    handle: PayserverHandle,
    store_id: str,
    name: str = "Tip Jar",
    emoji: str | None = None,
    message: str = "",
    enabled: bool = True,
) -> str:
    """Return the id of the store's jar called `name`, creating it if needed."""
    with handle.db() as db:
        row = db.execute(
            "SELECT id FROM tip_jars WHERE store_id = ? AND name = ? COLLATE NOCASE",
            (store_id, name),
        ).fetchone()
        if row is not None:
            db.execute(
                "UPDATE tip_jars SET emoji = ?, message = ?, enabled = ?, updated_at = ? WHERE id = ?",
                (emoji, message, 1 if enabled else 0, int(time.time()), row["id"]),
            )
            return row["id"]
        jar_id = "jar_" + secrets.token_hex(12)
        now = int(time.time())
        db.execute(
            "INSERT INTO tip_jars (id, store_id, name, emoji, message, enabled, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (jar_id, store_id, name, emoji, message, 1 if enabled else 0, now, now),
        )
        return jar_id


def delete_tip_jars(handle: PayserverHandle, store_id: str) -> None:
    with handle.db() as db:
        db.execute("UPDATE invoices SET tip_jar_id = NULL WHERE store_id = ?", (store_id,))
        db.execute("DELETE FROM tip_jars WHERE store_id = ?", (store_id,))
