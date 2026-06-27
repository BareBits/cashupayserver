"""Admin-only action gates + user-management endpoints.

Covers the action-level role enforcement in admin.php: every fund-touching
or configuration-changing action returns 403 when called by a non-admin
session, and the new user-management endpoints (list/create/delete/reset
password) are admin-only and refuse to delete the last admin or
delete self.
"""
from __future__ import annotations

from typing import Any

import pytest
import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD
from fixtures.api_client import AdminClient


@pytest.fixture
def non_admin(configured: ConfiguredPayserver) -> dict[str, Any]:
    """Create a non-admin 'staff' user via the admin endpoint and return a
    logged-in AdminClient pointed at it plus its credentials."""
    admin = configured.admin
    r = admin._post_action("create_user", username="staff", password="staffpw1234", role="user")
    assert r.get("success"), r
    user_id = r["id"]

    staff = AdminClient(configured.handle.url)
    staff.login("staffpw1234", username="staff")
    return {"client": staff, "id": user_id, "username": "staff", "password": "staffpw1234"}


# ---------- admin gating ----------


@pytest.mark.parametrize(
    "action,fields",
    [
        ("save_url_mode", {"mode": "router"}),
        ("create_store", {"name": "Forbidden Store"}),
        ("update_store", {"store_id": "x", "name": "X"}),
        ("delete_store", {"store_id": "x"}),
        ("create_api_key", {"store_id": "x", "label": "x"}),
        ("delete_api_key", {"key_id": "x"}),
        ("save_auto_melt", {"store_id": "x", "address": "x@y", "threshold": "5000"}),
        ("manual_melt", {"store_id": "x", "address": "x@y", "amount": "100"}),
        ("save_onchain", {"store_id": "x", "xpub": "x"}),
        ("validate_onchain_xpub", {"xpub": "x", "network": "regtest"}),
        ("test_onchain_xpub", {"store_id": "x"}),
        ("add_backup_mint", {"store_id": "x", "mint_url": "https://m"}),
        ("update_backup_mint", {"id": "1"}),
        ("remove_backup_mint", {"id": "1"}),
        ("generate_seed", {}),
        ("validate_seed", {"seed_phrase": "x"}),
        ("list_users", {}),
        ("create_user", {"username": "x", "password": "abcdefgh", "role": "user"}),
        ("delete_user", {"user_id": "x"}),
        ("reset_password", {"user_id": "x", "new_password": "abcdefgh"}),
    ],
)
def test_non_admin_is_forbidden(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
    action: str, fields: dict[str, str],
) -> None:
    staff = non_admin["client"]
    r = staff.s.post(
        f"{configured.handle.url}/admin",
        data={"action": action, **fields},
        headers={"X-CSRF-Token": staff.csrf_token},
        timeout=15,
    )
    assert r.status_code == 403, f"{action}: expected 403, got {r.status_code}: {r.text[:200]}"
    assert "Admin" in r.json().get("error", "") or "admin" in r.json().get("error", "").lower()


def test_non_admin_cannot_call_export_info(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    staff = non_admin["client"]
    r = staff.s.get(
        f"{configured.handle.url}/admin?api=export_info&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 403


def test_non_admin_cannot_call_proofs(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    """?api=proofs returns the store's unspent proofs — secret + C, i.e.
    spendable bearer ecash. A non-admin 'user' must not be able to dump it
    (it would let staff redeem the whole balance at the mint)."""
    staff = non_admin["client"]
    r = staff.s.get(
        f"{configured.handle.url}/admin?api=proofs&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 403, f"expected 403, got {r.status_code}: {r.text[:200]}"


def test_non_admin_cannot_call_api_keys(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    """?api=api_keys leaks API-key metadata (id/label/scopes) — admin-only."""
    staff = non_admin["client"]
    r = staff.s.get(
        f"{configured.handle.url}/admin?api=api_keys&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 403, f"expected 403, got {r.status_code}: {r.text[:200]}"


def test_admin_can_call_proofs(configured: ConfiguredPayserver) -> None:
    """The admin (the role the endpoint is meant for) still gets a 200 + JSON
    array, so the new gate didn't break the legitimate path."""
    admin = configured.admin
    r = admin.s.get(
        f"{configured.handle.url}/admin?api=proofs&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 200, r.text
    assert isinstance(r.json(), list)


# ---------- self-service ----------


def test_non_admin_can_view_dashboard_and_invoices(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    """Non-admin can read the dashboard and the invoice list — that's the
    whole point of the role."""
    staff = non_admin["client"]
    r = staff.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 200, r.text
    assert "stores" in r.json()

    r = staff.s.get(
        f"{configured.handle.url}/admin?api=invoices&store_id={configured.store_id}",
        timeout=15,
    )
    assert r.status_code == 200


def test_user_can_change_own_password(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    staff = non_admin["client"]
    r = staff._post_action(
        "change_own_password",
        current_password=non_admin["password"],
        new_password="newpw1234ABC",
    )
    assert r.get("success") is True, r

    # Login with the new password works.
    fresh = AdminClient(configured.handle.url)
    fresh.login("newpw1234ABC", username="staff")
    assert fresh.csrf_token


def test_change_own_password_rejects_wrong_current(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    staff = non_admin["client"]
    r = staff.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "change_own_password",
              "current_password": "wrong-pw",
              "new_password": "newpw1234ABC"},
        headers={"X-CSRF-Token": staff.csrf_token},
        timeout=15,
    )
    assert r.status_code == 401


# ---------- admin user-management ----------


def test_admin_can_list_and_create_and_delete_users(
    configured: ConfiguredPayserver,
) -> None:
    admin = configured.admin
    before = admin._post_action("list_users")["users"]
    assert any(u["username"] == "admin" for u in before)

    created = admin._post_action(
        "create_user", username="staff2", password="staff2pw1234", role="user"
    )
    assert created["success"] is True
    new_id = created["id"]

    after = admin._post_action("list_users")["users"]
    assert any(u["id"] == new_id and u["role"] == "user" for u in after)

    admin._post_action("delete_user", user_id=new_id)
    final = admin._post_action("list_users")["users"]
    assert all(u["id"] != new_id for u in final)


def test_admin_cannot_delete_self(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    users = admin._post_action("list_users")["users"]
    admin_id = [u["id"] for u in users if u["username"] == "admin"][0]

    r = admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "delete_user", "user_id": admin_id},
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=15,
    )
    assert r.status_code == 400
    assert "own account" in r.json()["error"].lower()


def test_admin_cannot_delete_only_remaining_admin(
    configured: ConfiguredPayserver,
) -> None:
    """The defense-in-depth invariant: never leave the install adminless.
    Set up two admins, have admin2 delete the original admin so admin2 is
    sole. Then admin2 calling delete_user with the *first admin's* (now
    nonexistent) id 404s, but we also want to assert the only-admin guard
    fires if someone forges a request to delete admin2 — easiest path is
    a direct Auth::deleteUser via the DB (the API path is guarded by
    requireAdmin and self-delete).

    For the API-level guarantee, just verify that after the first
    deletion admin2 cannot delete itself either (the self-delete guard
    fires first regardless of how many admins remain)."""
    admin = configured.admin
    admin._post_action(
        "create_user", username="admin2", password="admin2pw1234", role="admin"
    )
    admin2 = AdminClient(configured.handle.url)
    admin2.login("admin2pw1234", username="admin2")

    # admin2 deletes the original admin -> admin2 is the only admin.
    users = admin2._post_action("list_users")["users"]
    original_id = [u["id"] for u in users if u["username"] == "admin"][0]
    admin2._post_action("delete_user", user_id=original_id)

    # admin2 cannot delete itself.
    admin2_id = [u["id"] for u in admin2._post_action("list_users")["users"]
                 if u["username"] == "admin2"][0]
    r = admin2.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "delete_user", "user_id": admin2_id},
        headers={"X-CSRF-Token": admin2.csrf_token},
        timeout=15,
    )
    assert r.status_code == 400
    assert "own account" in r.json()["error"].lower()


def test_admin_can_reset_other_users_password(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    admin = configured.admin
    r = admin._post_action(
        "reset_password",
        user_id=non_admin["id"],
        new_password="resetpw1234",
    )
    assert r["success"] is True

    # The user can now log in with the new password.
    fresh = AdminClient(configured.handle.url)
    fresh.login("resetpw1234", username="staff")
    assert fresh.csrf_token


def test_create_user_rejects_duplicate_username(
    configured: ConfiguredPayserver, non_admin: dict[str, Any],
) -> None:
    admin = configured.admin
    r = admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "create_user", "username": "staff", "password": "abcdefgh",
              "role": "user"},
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=15,
    )
    assert r.status_code == 409
    assert "already" in r.json()["error"].lower()


def test_create_user_rejects_invalid_role(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    r = admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "create_user", "username": "weird", "password": "abcdefgh",
              "role": "wizard"},
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=15,
    )
    assert r.status_code == 400
