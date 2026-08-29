"""End-to-end tests for the auto-cashout / submarine-swap guard-rails.

Covered behavior (all enforced server-side in admin.php; swaps are store-only
— the old site-wide switch and its get/save_swap_settings actions are gone):
  - Forcing submarine swaps on for a store with no on-chain xpub/address is
    refused (save_store_swaps).
  - Selecting on-chain auto-cashout (mode '1') with no on-chain xpub/address
    is refused (save_auto_melt).
  - The legacy tri-state '-1' ("inherit") is rejected outright by both
    save_auto_melt and save_store_swaps — the mode must be '0' or '1'.
  - Selecting on-chain auto-cashout on a store that DOES have on-chain
    configured succeeds AND force-enables the STORE swaps flag (there is no
    site key to flip, and the destination lists now live in
    save_lightning_payments, so the response carries no addresses array).
  - Forcing swaps on for a store that has on-chain configured succeeds.
"""
from __future__ import annotations

import sqlite3

import requests

from conftest import ConfiguredPayserver


# Store flag values mirrored from SwapsConfig / SwapAutoMelt.
FORCE_ON = 1
FORCE_SWAP = 1


def _post(shared_configured: ConfiguredPayserver, action: str, **fields) -> requests.Response:
    return shared_configured.admin.s.post(
        f"{shared_configured.handle.url}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": shared_configured.admin.csrf_token},
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


def _store_swap_flag(db_path: str, store_id: str) -> int | None:
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


def test_force_store_swap_without_onchain_is_rejected(shared_configured: ConfiguredPayserver) -> None:
    r = _post(
        shared_configured, "save_store_swaps",
        store_id=shared_configured.store_id,
        enabled="1",
        provider_order="boltz",
    )
    assert r.status_code == 400, r.text
    assert "on-chain" in r.json()["error"].lower()


def test_onchain_automelt_without_onchain_is_rejected(shared_configured: ConfiguredPayserver) -> None:
    r = _post(
        shared_configured, "save_auto_melt",
        store_id=shared_configured.store_id,
        enabled="1",
        threshold="2000",
        mode_override=str(FORCE_SWAP),
    )
    assert r.status_code == 400, r.text
    assert "on-chain" in r.json()["error"].lower()


# ---------- legacy tri-state values are dead ----------


def test_automelt_rejects_legacy_inherit_mode(shared_configured: ConfiguredPayserver) -> None:
    # '-1' meant "inherit the site default"; the site default is gone, so the
    # value is now a 400 rather than being silently normalized on write.
    r = _post(
        shared_configured, "save_auto_melt",
        store_id=shared_configured.store_id,
        enabled="1",
        threshold="2000",
        mode_override="-1",
    )
    assert r.status_code == 400, r.text
    assert "mode" in r.json()["error"].lower()


def test_store_swaps_rejects_legacy_inherit_value(shared_configured: ConfiguredPayserver) -> None:
    r = _post(
        shared_configured, "save_store_swaps",
        store_id=shared_configured.store_id,
        enabled="-1",
        provider_order="boltz",
    )
    assert r.status_code == 400, r.text
    assert "enabled" in r.json()["error"].lower()


# ---------- positive: on-chain auto-cashout forces the store swaps flag ----------


def test_onchain_automelt_forces_store_swap_flag(shared_configured: ConfiguredPayserver) -> None:
    _set_static_onchain(shared_configured.handle.db_path, shared_configured.store_id)

    # The store flag starts non-forced (legacy -1 default on a fresh store).
    assert _store_swap_flag(shared_configured.handle.db_path, shared_configured.store_id) != FORCE_ON

    # There is no site-wide switch any more: the old site-settings actions
    # fall through to the unknown-action handler.
    for dead_action in ("get_swap_settings", "save_swap_settings"):
        gone = _post(shared_configured, dead_action)
        assert gone.status_code == 400, (dead_action, gone.status_code, gone.text[:200])
        assert gone.json().get("error") == "Unknown action", (dead_action, gone.text[:200])

    r = _post(
        shared_configured, "save_auto_melt",
        store_id=shared_configured.store_id,
        enabled="1",
        threshold="2000",
        mode_override=str(FORCE_SWAP),
    )
    assert r.ok, (r.status_code, r.text)
    body = r.json()
    assert body["success"] is True, r.text
    # Destination lists moved to save_lightning_payments; save_auto_melt no
    # longer echoes an addresses array.
    assert "addresses" not in body, body

    # The store's swaps flag is now forced-on — the only flag there is.
    assert _store_swap_flag(shared_configured.handle.db_path, shared_configured.store_id) == FORCE_ON


def test_force_store_swap_with_onchain_succeeds(shared_configured: ConfiguredPayserver) -> None:
    _set_static_onchain(shared_configured.handle.db_path, shared_configured.store_id)
    r = _post(
        shared_configured, "save_store_swaps",
        store_id=shared_configured.store_id,
        enabled="1",
        provider_order="boltz",
    )
    assert r.ok, r.text
    assert r.json()["success"] is True
    assert _store_swap_flag(shared_configured.handle.db_path, shared_configured.store_id) == FORCE_ON


def test_enabling_store_swaps_requires_a_provider(shared_configured: ConfiguredPayserver) -> None:
    # enabled=1 with an empty provider_order can never produce a working swap
    # rail, so the save is refused rather than persisting a dead setting.
    _set_static_onchain(shared_configured.handle.db_path, shared_configured.store_id)
    r = _post(
        shared_configured, "save_store_swaps",
        store_id=shared_configured.store_id,
        enabled="1",
        provider_order="",
    )
    assert r.status_code == 400, r.text
    assert "provider" in r.json()["error"].lower()
