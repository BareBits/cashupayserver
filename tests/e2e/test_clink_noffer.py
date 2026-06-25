"""CLINK noffer admin configuration e2e.

Verifies that an operator can add a CLINK noffer to a store's auto-cashout
destination chain "the same way they add LNURLs" — pasted into the same
addresses list — and that it is auto-detected, validated, and persisted with
type='noffer' alongside Lightning addresses in priority order.

This covers the admin/config surface without needing a live Nostr relay or
merchant service (those paths are exercised by the PHP round-trip suite against
a mock relay). A malformed noffer must be rejected.
"""
from __future__ import annotations

import sqlite3

from conftest import ConfiguredPayserver
from fixtures.api_client import AdminClient

# Reference noffer from @shocknet/clink-sdk (decodes to a valid pubkey/relay/
# offer). Used only for config persistence — never dialled in this test.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)


def _post_auto_melt(admin: AdminClient, store_id: str, addresses: list[str]) -> "object":
    """Post save_auto_melt with an ordered addresses[] chain (mixed types),
    mirroring the dashboard. Returns the raw requests.Response."""
    data = [
        ("action", "save_auto_melt"),
        ("store_id", store_id),
        ("enabled", "1"),
        ("threshold", "100"),
        ("mode_override", "0"),
    ]
    for a in addresses:
        data.append(("addresses[]", a))
    return admin.s.post(
        admin._admin_url,
        data=data,
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=30,
    )


def _chain_rows(payserver, store_id: str) -> list[sqlite3.Row]:
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        return list(conn.execute(
            "SELECT address, type, position, supports_verify "
            "FROM store_ln_addresses WHERE store_id = ? ORDER BY position ASC",
            (store_id,),
        ))


def test_noffer_added_to_chain_and_persisted(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    store_id = configured.store_id

    # noffer first, Lightning address as fallback — mixed, ordered chain.
    r = _post_auto_melt(admin, store_id, [REFERENCE_NOFFER, "fallback@example.test"])
    assert r.status_code == 200, r.text
    body = r.json()
    assert body.get("success"), body

    # Response classifies each destination by type; noffer carries no LUD-21.
    results = body.get("addresses") or []
    assert len(results) == 2, results
    assert results[0]["type"] == "noffer", results
    assert results[0]["lud21Support"] is None, results
    assert results[1]["type"] == "lnaddress", results

    # Persisted with type + order intact.
    rows = _chain_rows(configured.handle, store_id)
    assert [row["type"] for row in rows] == ["noffer", "lnaddress"], [dict(r) for r in rows]
    assert rows[0]["address"] == REFERENCE_NOFFER
    assert rows[0]["supports_verify"] is None
    assert rows[1]["address"] == "fallback@example.test"


def test_invalid_noffer_rejected(configured: ConfiguredPayserver) -> None:
    admin = configured.admin
    store_id = configured.store_id

    # A noffer-shaped but undecodable string fails validation. (Auto-detection
    # only classifies a value as a noffer when it fully decodes, so a broken one
    # is rejected as an invalid destination rather than silently accepted.)
    r = _post_auto_melt(admin, store_id, [REFERENCE_NOFFER[:-4] + "zzzz"])
    assert r.status_code == 400, r.text
    assert r.json().get("error"), r.text
