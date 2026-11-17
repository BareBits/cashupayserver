"""Greenfield store CRUD: create / list / get / delete."""
from __future__ import annotations

import hashlib
import json
import time
import uuid

import pytest

from conftest import ConfiguredPayserver
from fixtures.api_client import GreenfieldClient


def test_list_stores_returns_setup_store(shared_configured: ConfiguredPayserver) -> None:
    stores = shared_configured.greenfield.list_stores()
    assert any(s["id"] == shared_configured.store_id for s in stores)
    setup_store = next(s for s in stores if s["id"] == shared_configured.store_id)
    assert setup_store["name"] == shared_configured.store_name
    assert "createdTime" in setup_store


def test_get_store_returns_metadata(shared_configured: ConfiguredPayserver) -> None:
    store = shared_configured.greenfield.get_store(shared_configured.store_id)
    assert store["id"] == shared_configured.store_id
    assert store["name"] == shared_configured.store_name


def test_get_nonexistent_store_returns_404(shared_configured: ConfiguredPayserver) -> None:
    with pytest.raises(RuntimeError, match="404"):
        shared_configured.greenfield.get_store("store_does_not_exist_xxx")


def test_store_scoped_key_cannot_create_or_delete_stores(shared_configured: ConfiguredPayserver) -> None:
    """Keys minted from the admin UI are bound to one store with explicit
    permissions: no POST /stores, no DELETE of their own store (which would
    cascade away invoices, webhooks, keys and the wallet seed)."""
    gc = shared_configured.greenfield
    with pytest.raises(RuntimeError, match="403"):
        gc._post("/api/v1/stores", {"name": "Not Allowed"})
    with pytest.raises(RuntimeError, match="403"):
        gc._delete(f"/api/v1/stores/{shared_configured.store_id}")
    # The store is intact.
    assert gc.get_store(shared_configured.store_id)["id"] == shared_configured.store_id


def _wildcard_client(shared_configured: ConfiguredPayserver) -> GreenfieldClient:
    """A legacy server-wide key (permissions '*'), as installs upgraded from
    older releases still hold: inserted directly, since nothing mints them."""
    raw = uuid.uuid4().hex + uuid.uuid4().hex
    with shared_configured.handle.db() as conn:
        conn.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (
                f"key_{uuid.uuid4().hex[:12]}",
                hashlib.sha256(raw.encode()).hexdigest(),
                shared_configured.store_id,
                "legacy wildcard",
                json.dumps(["*"]),
                int(time.time()),
            ),
        )
    return GreenfieldClient(shared_configured.handle.url, raw)


def test_create_and_delete_store_via_api(shared_configured: ConfiguredPayserver) -> None:
    gc = _wildcard_client(shared_configured)
    name = f"Second Store {uuid.uuid4().hex[:8]}"
    new_store = gc._post("/api/v1/stores", {"name": name})
    assert new_store["name"] == name
    assert new_store["id"].startswith("store_"), new_store

    stores = gc.list_stores()
    assert any(s["id"] == new_store["id"] for s in stores)

    gc._delete(f"/api/v1/stores/{new_store['id']}")
    stores_after = gc.list_stores()
    assert not any(s["id"] == new_store["id"] for s in stores_after)


def test_create_store_requires_name(shared_configured: ConfiguredPayserver) -> None:
    with pytest.raises(RuntimeError, match="validation-error"):
        _wildcard_client(shared_configured)._post("/api/v1/stores", {})
