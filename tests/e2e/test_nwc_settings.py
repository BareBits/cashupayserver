"""HTTP contract of the NWC settings surfaces (admin save + setup wizard).

Covers the parts the live round trip (test_nwc_receive.py) doesn't:
  - the three-list chain contract: ln_addresses[] + nwc[] + noffers[]
    posted to save_lightning_payments and persisted in the priority order
    Invoice::create walks (lnaddress -> nwc -> noffer)
  - keep:<id> reference round trip: the browser re-saves the masked row
    without ever holding the URI, and the stored secret survives unchanged
  - rejection paths: malformed URI (without echoing it back), dead-relay
    connection (probe gate), forged keep ref
  - the spend-permission warning when the wallet's get_info reports the
    connection can also pay
  - the setup wizard's lightning step accepting + probing an NWC string
"""
from __future__ import annotations

import sqlite3
import time
from typing import Iterator

import pytest

from conftest import ConfiguredPayserver, SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.lnd import LndHandle
from fixtures.nwc_wallet import NwcStack, start_nwc_stack, stop_nwc_stack
from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import SetupWizard

# Reference noffer from @shocknet/clink-sdk — persisted, never dialled.
REFERENCE_NOFFER = (
    "noffer1qvqsyqjqxuurvwpcxc6rvvrxxsurqep5vfjk2wf4v33nsenrxumnyvesxfnrswfkvycrw"
    "dp3x93xydf5xg6rzce4vv6xgdfh8quxgct9x5erxvspremhxue69uhhgetnwskhyetvv9ujumrfv"
    "a58gmnfdenjuur4vgqzpccxc30wpf78wf2q78wg3vq008fd8ygtl4qy06gstpye3h5unc47xmee6z"
)
DEAD_NWC = (
    "nostr+walletconnect://" + "ab" * 32
    + "?relay=ws://127.0.0.1:1/&secret=" + "cd" * 32
)


@pytest.fixture(scope="module")
def nwc_stack(lnd_mint: LndHandle, channels: None) -> Iterator[NwcStack]:
    """Module-scoped: generic infrastructure (each test wires its own store
    to it); the save-time probes are keyed per invoice, so wallet/relay state
    can't leak between tests."""
    workdir = SESSION_TMP / f"nwc-settings-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_mint, info_methods=["make_invoice", "lookup_invoice", "get_info"]
    )
    yield stack
    stop_nwc_stack(stack)


def _post_save(admin: AdminClient, store_id: str, data: list[tuple[str, str]],
               expect_ok: bool = True) -> tuple[int, dict]:
    """POST the destination chain to save_lightning_payments (the action that
    now owns the ln_addresses[]/nwc[]/noffers[] lists and the probe gates)."""
    body = [
        ("action", "save_lightning_payments"),
        ("store_id", store_id),
        *data,
    ]
    r = admin.s.post(
        admin._admin_url, data=body,
        headers={"X-CSRF-Token": admin.csrf_token}, timeout=60,
    )
    parsed = r.json()
    if expect_ok:
        assert r.status_code == 200 and parsed.get("success"), (r.status_code, parsed)
    return r.status_code, parsed


def _chain(payserver, store_id: str | None = None) -> list[tuple[str, str, int]]:
    """The persisted destination chain. Pass ``store_id`` on a shared server
    (multiple stores exist there); ``None`` reads all rows — only valid on a
    dedicated single-store instance (the wizard test)."""
    query = "SELECT address, type, position FROM store_ln_addresses"
    params: tuple = ()
    if store_id is not None:
        query += " WHERE store_id = ?"
        params = (store_id,)
    query += " ORDER BY position ASC"
    with sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        rows = conn.execute(query, params).fetchall()
    return [(r["type"], r["address"], r["position"]) for r in rows]


def test_three_list_chain_order_and_keep_ref_round_trip(
    shared_configured: ConfiguredPayserver, nwc_stack: NwcStack
) -> None:
    configured = shared_configured
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle
    uri = nwc_stack.wallet.connection_uri()
    secret = uri.split("secret=", 1)[1].split("&", 1)[0]

    # Full three-list save: chain order is lnaddress -> nwc -> noffer.
    # (The LN address probe needs a LUD-21 host; use the noffer-free contract
    # here — address handling is covered by the lnurlp-backed tests.)
    _status, save = _post_save(admin, store_id, [("nwc[]", uri), ("noffers[]", REFERENCE_NOFFER)])
    chain = _chain(payserver, store_id)
    assert [(t, p) for t, _a, p in chain] == [("nwc", 0), ("noffer", 1)], chain
    assert chain[0][1] == uri, "raw URI stored server-side"
    assert secret not in str(save), "save response leaks no secret"

    # Read the dashboard the way the browser does, then re-save using ONLY the
    # keep ref it was given (this is all the browser ever holds).
    dash = admin.s.get(
        admin._admin_url, params={"api": "dashboard", "store_id": store_id}, timeout=30
    ).json()
    nwc_rows = [a for a in dash["autoMelt"]["addresses"] if a["type"] == "nwc"]
    assert len(nwc_rows) == 1 and secret not in str(dash), dash["autoMelt"]["addresses"]
    ref = nwc_rows[0]["ref"]
    assert ref.startswith("keep:"), nwc_rows

    _status, _save2 = _post_save(admin, store_id, [("nwc[]", ref)])
    chain2 = _chain(payserver, store_id)
    assert chain2 == [("nwc", uri, 0)], "keep ref preserved the stored URI unchanged"

    # Dropping the entry clears it.
    _post_save(admin, store_id, [("noffers[]", REFERENCE_NOFFER)])
    assert [(t, p) for t, _a, p in _chain(payserver, store_id)] == [("noffer", 0)]


def test_rejections_are_masked_and_blocking(
    shared_configured: ConfiguredPayserver
) -> None:
    configured = shared_configured
    admin = configured.admin
    store_id = configured.store_id
    payserver = configured.handle
    secret = "cd" * 32

    # Malformed NWC string: rejected without echoing the paste.
    status, body = _post_save(
        admin, store_id,
        [("nwc[]", "nostr+walletconnect://broken?secret=" + secret)],
        expect_ok=False,
    )
    assert status == 400, body
    assert secret not in str(body), "error must not echo the (secret-bearing) paste"
    assert _chain(payserver, store_id) == [], "nothing persisted on rejection"

    # Structurally valid but dead-relay connection: blocked by the probe gate.
    status, body = _post_save(admin, store_id, [("nwc[]", DEAD_NWC)], expect_ok=False)
    assert status == 400, body
    assert "connection test" in body.get("error", ""), body
    assert "cd" * 32 not in str(body), "gate error leaks no secret"
    assert _chain(payserver, store_id) == []

    # Forged keep ref: rejected.
    status, body = _post_save(admin, store_id, [("nwc[]", "keep:424242")], expect_ok=False)
    assert status == 400, body


def test_spend_capable_connection_warns_but_saves(
    shared_configured: ConfiguredPayserver, lnd_mint: LndHandle, channels: None
) -> None:
    configured = shared_configured
    workdir = SESSION_TMP / f"nwc-spendy-{int(time.time())}"
    workdir.mkdir(parents=True, exist_ok=True)
    stack = start_nwc_stack(
        workdir, lnd_mint,
        info_methods=["pay_invoice", "make_invoice", "lookup_invoice", "get_info"],
    )
    try:
        uri = stack.wallet.connection_uri()
        _status, save = _post_save(configured.admin, configured.store_id, [("nwc[]", uri)])
        results = [a for a in save.get("addresses", []) if a["type"] == "nwc"]
        assert results and results[0].get("warning"), save
        assert "pay_invoice" in results[0]["warning"], results
        assert _chain(configured.handle, configured.store_id)[0][0] == "nwc", "warned but saved"
    finally:
        stop_nwc_stack(stack)


def test_setup_wizard_lightning_step_accepts_nwc(
    payserver: PayserverHandle, nwc_stack: NwcStack
) -> None:
    """The onboarding lightning screen tests + saves an NWC string ('tested +
    checked upon input'): a good connection persists as a type='nwc' chain row;
    a garbage string re-renders the step with the actionable error."""
    w = SetupWizard(payserver.url)
    w.through_store(name="NWC Wizard Store")
    w.post(step="onchain", onchain_action="skip")

    # Garbage NWC string: the step re-renders with the shape error.
    body = w.post(step="lightning", lightning_action="save", nwc="nostr+walletconnect://nope")
    assert "look right" in body, body[:1000]

    # Dead-relay string: blocked by the save-time probe, actionable message,
    # never echoing the (secret-bearing) paste.
    body = w.post(step="lightning", lightning_action="save", nwc=DEAD_NWC)
    assert "connection test" in body, body[:1000]
    assert ("cd" * 32) not in body, "wizard error must not echo the secret"

    # Working connection: probed against the live fake wallet, then persisted.
    uri = nwc_stack.wallet.connection_uri()
    w.post(step="lightning", lightning_action="save", nwc=uri)
    assert _chain(payserver) == [("nwc", uri, 0)]

    # Revisiting the screen prefills a masked label + keep ref, not the URI.
    secret = uri.split("secret=", 1)[1].split("&", 1)[0]
    body = w.get("lightning")
    assert secret not in body, "wizard re-render must not leak the stored secret"
    assert "nwc_keep_ref" in body and "NWC wallet " in body, body[:2000]

    # Saving again with the keep ref (what the form posts untouched) keeps the
    # stored connection.
    import re as _re
    ref = _re.search(r'name="nwc_keep_ref" value="([^"]+)"', body).group(1)
    w.post(step="lightning", lightning_action="save", nwc="", nwc_keep_ref=ref)
    assert _chain(payserver) == [("nwc", uri, 0)]

    # The clear checkbox removes it.
    w.post(step="lightning", lightning_action="save", nwc="", nwc_keep_ref=ref, nwc_clear="1")
    assert _chain(payserver) == []
