"""Greenfield API auth: token required, rate limit, multi-store access."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver
from fixtures.api_client import GreenfieldClient


def test_authenticated_endpoint_requires_token(shared_configured: ConfiguredPayserver) -> None:
    r = requests.get(f"{shared_configured.handle.url}/api/v1/stores", timeout=5)
    assert r.status_code == 401
    assert r.json()["code"] == "unauthenticated"


def test_invalid_token_is_rejected(shared_configured: ConfiguredPayserver) -> None:
    r = requests.get(
        f"{shared_configured.handle.url}/api/v1/stores",
        headers={"Authorization": "token wrong-token-xyz"},
        timeout=5,
    )
    assert r.status_code == 401


def test_api_key_can_access_its_store_invoices(shared_configured: ConfiguredPayserver) -> None:
    invoices = shared_configured.greenfield.list_invoices(shared_configured.store_id)
    assert isinstance(invoices, list)


def test_deleting_an_api_key_revokes_it(shared_configured: ConfiguredPayserver) -> None:
    # Create a throwaway key, exercise it, then delete and re-test.
    extra = shared_configured.admin.create_api_key(shared_configured.store_id, label="throwaway")
    extra_token = extra["key"]
    extra_client = GreenfieldClient(shared_configured.handle.url, extra_token)
    extra_client.list_stores()  # works

    shared_configured.admin.delete_api_key(extra["id"])
    r = requests.get(
        f"{shared_configured.handle.url}/api/v1/stores",
        headers={"Authorization": f"token {extra_token}"},
        timeout=5,
    )
    assert r.status_code == 401, r.text


def test_rate_limit_returns_429(configured: ConfiguredPayserver) -> None:
    """100 req/min/IP. Burst past it on a cheap endpoint and expect 429.

    Stays on the function-scoped `configured` fixture: burning the global
    per-IP rate limiter would poison every other test on a shared server."""
    s = requests.Session()
    s.headers["Authorization"] = f"token {configured.api_token}"
    url = f"{configured.handle.url}/api/v1/server/info"

    got_429 = False
    for _ in range(110):
        r = s.get(url, timeout=5)
        if r.status_code == 429:
            got_429 = True
            assert r.json().get("code") == "rate-limited"
            break
    assert got_429, "expected to hit rate limit within 110 requests"


# ---------- permission scoping ----------


def test_admin_created_key_is_store_scoped_with_explicit_permissions(
    shared_configured: ConfiguredPayserver,
) -> None:
    """A key from the admin UI carries the explicit store permission set, not
    the server-wide wildcard, and the API reports only its own store."""
    with shared_configured.handle.db() as conn:
        rows = conn.execute(
            "SELECT permissions FROM api_keys WHERE store_id = ? AND label LIKE 'e2e%'",
            (shared_configured.store_id,),
        ).fetchall()
    assert rows, "the fixture key exists"
    import json

    for row in rows:
        perms = json.loads(row["permissions"])
        assert "*" not in perms, perms
        assert "btcpay.store.cancreateinvoice" in perms
        assert "btcpay.store.webhooks.canmodifywebhooks" in perms
    stores = shared_configured.greenfield.list_stores()
    assert [s["id"] for s in stores] == [shared_configured.store_id], "only its own store is listed"


def test_api_enforces_granted_permissions(shared_configured: ConfiguredPayserver) -> None:
    """A key paired for a narrow permission set is held to it: a view-only
    key reads invoices but can neither create one nor mark one settled nor
    touch webhooks; a modify-invoices key implies create + view (BTCPay's
    hierarchy) but still cannot manage webhooks."""
    import hashlib
    import json
    import time
    import uuid

    def mint(perms: list[str]) -> GreenfieldClient:
        raw = uuid.uuid4().hex + uuid.uuid4().hex
        with shared_configured.handle.db() as conn:
            conn.execute(
                "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
                "VALUES (?, ?, ?, ?, ?, ?)",
                (f"key_{uuid.uuid4().hex[:12]}", hashlib.sha256(raw.encode()).hexdigest(),
                 shared_configured.store_id, "scoped", json.dumps(perms), int(time.time())),
            )
        return GreenfieldClient(shared_configured.handle.url, raw)

    sid = shared_configured.store_id
    inv = shared_configured.greenfield.create_invoice(sid, amount="21", currency="sat")

    viewer = mint(["btcpay.store.canviewinvoices"])
    assert viewer.get_invoice(sid, inv["id"])["id"] == inv["id"]
    import pytest

    with pytest.raises(RuntimeError, match="403"):
        viewer.create_invoice(sid, amount="21", currency="sat")
    with pytest.raises(RuntimeError, match="403"):
        viewer.mark_invoice_status(sid, inv["id"], "Settled")
    with pytest.raises(RuntimeError, match="403"):
        viewer.list_webhooks(sid)
    assert shared_configured.greenfield.get_invoice(sid, inv["id"])["status"] != "Settled"

    modifier = mint(["btcpay.store.canmodifyinvoices"])
    created = modifier.create_invoice(sid, amount="21", currency="sat")  # implied by canmodifyinvoices
    assert modifier.get_invoice(sid, created["id"])["id"] == created["id"]  # implied too
    with pytest.raises(RuntimeError, match="403"):
        modifier.list_webhooks(sid)


def test_pairing_refuses_wildcard_and_unknown_permissions(shared_configured: ConfiguredPayserver) -> None:
    base = shared_configured.handle.url
    for perm in ("*", "btcpay.server.canmodifyserversettings"):
        r = requests.get(
            f"{base}/api-keys/authorize.php",
            params=[("applicationName", "Evil Shop"), ("permissions", perm)],
            timeout=10,
        )
        assert r.status_code == 400, (perm, r.status_code, r.text[:200])
        assert "Unsupported permission" in r.text
    ok = requests.get(
        f"{base}/api-keys/authorize.php",
        params=[("applicationName", "Shop"), ("permissions", "btcpay.store.cancreateinvoice")],
        timeout=10,
    )
    assert ok.status_code == 200, ok.text[:200]
