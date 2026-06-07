"""End-to-end tests for the auto-withdrawal / submarine-swap guard-rails added
alongside the admin auto-withdrawal column-selector redesign.

Covered behavior (all enforced server-side in admin.php):
  - Forcing submarine swaps on for a store with no on-chain xpub/address is
    refused (save_store_swaps).
  - Selecting on-chain auto-withdraw (FORCE_SWAP) with no on-chain
    xpub/address is refused (save_auto_melt).
  - Selecting on-chain auto-withdraw on a store that DOES have on-chain
    configured succeeds AND auto-forces the store's submarine-swap override on
    + enables the site-wide swap master switch (without forcing other stores).
  - Forcing swaps on for a store that has on-chain configured succeeds.
"""
from __future__ import annotations

import sqlite3

import requests

from conftest import ConfiguredPayserver


# Tri-state override values mirrored from SwapsConfig / SwapAutoMelt.
FORCE_ON = 1
FORCE_SWAP = 1


def _post(configured: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    return configured.admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": configured.admin.csrf_token},
        timeout=15,
    )


def _set_static_onchain(db_path: str, store_id: str) -> None:
    """Give the store a (non-empty) static on-chain address directly in the DB.
    The guards only check that an xpub / static address is present, not its
    validity, so this is enough to flip storeHasOnchain() true without bitcoind."""
    conn = sqlite3.connect(db_path)
    try:
        conn.execute(
            "UPDATE stores SET onchain_address_mode = 'static', "
            "onchain_static_address = ?, onchain_xpub = NULL WHERE id = ?",
            ("bcrt1qexampleaddrxxxxxxxxxxxxxxxxxxxxxxxx0", store_id),
        )
        conn.commit()
    finally:
        conn.close()


def _store_swap_override(db_path: str, store_id: str) -> int | None:
    conn = sqlite3.connect(db_path)
    try:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT swaps_enabled FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
        return None if row is None else row["swaps_enabled"]
    finally:
        conn.close()


# ---------- refusal guards (no on-chain configured) ----------


def test_force_store_swap_without_onchain_is_rejected(configured: ConfiguredPayserver) -> None:
    r = _post(configured, "save_store_swaps", store_id=configured.store_id, override=str(FORCE_ON))
    assert r.status_code == 400, r.text
    assert "on-chain" in r.json()["error"].lower()


def test_onchain_automelt_without_onchain_is_rejected(configured: ConfiguredPayserver) -> None:
    r = _post(
        configured, "save_auto_melt",
        store_id=configured.store_id,
        address="",
        enabled="1",
        threshold="2000",
        mode_override=str(FORCE_SWAP),
    )
    assert r.status_code == 400, r.text
    assert "on-chain" in r.json()["error"].lower()


# ---------- positive: on-chain auto-withdraw forces swaps on ----------


def test_onchain_automelt_forces_store_swap_and_enables_site(configured: ConfiguredPayserver) -> None:
    _set_static_onchain(configured.handle.db_path, configured.store_id)

    # Site-wide swaps start disabled on a fresh install.
    pre = _post(configured, "get_swap_settings").json()
    assert pre["enabled"] is False, pre

    r = _post(
        configured, "save_auto_melt",
        store_id=configured.store_id,
        address="",
        enabled="1",
        threshold="2000",
        mode_override=str(FORCE_SWAP),
    )
    assert r.ok, (r.status_code, r.text)
    assert r.json()["success"] is True, r.text

    # Store's submarine-swap override is now forced-on...
    assert _store_swap_override(configured.handle.db_path, configured.store_id) == FORCE_ON
    # ...and the site-wide master switch was enabled (without forcing others).
    post = _post(configured, "get_swap_settings").json()
    assert post["enabled"] is True, post


def test_force_store_swap_with_onchain_succeeds(configured: ConfiguredPayserver) -> None:
    _set_static_onchain(configured.handle.db_path, configured.store_id)
    r = _post(configured, "save_store_swaps", store_id=configured.store_id, override=str(FORCE_ON))
    assert r.ok, r.text
    assert r.json()["success"] is True
    assert _store_swap_override(configured.handle.db_path, configured.store_id) == FORCE_ON
