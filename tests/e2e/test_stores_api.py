"""Greenfield store CRUD: create / list / get / delete."""
from __future__ import annotations

import uuid

import pytest

from conftest import ConfiguredPayserver


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


def test_create_and_delete_store_via_api(shared_configured: ConfiguredPayserver) -> None:
    gc = shared_configured.greenfield
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
        shared_configured.greenfield._post("/api/v1/stores", {})
