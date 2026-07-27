"""Onboarding wizard: server-side step machine and validation.

Browser coverage lives in tests/ui/test_setup_wizard_ui.py, and whole-flow
choice combinations in test_setup_onboarding_matrix.py. This file pins the
step machine itself — sequencing, validation messages, and the store-identity
rules — over plain HTTP, so failures point at the handler rather than at a
selector or at a combination.

Only a payserver is needed: every path here skips or declines the mint screen,
so no LND/nutshell stack has to come up.
"""
from __future__ import annotations

import sqlite3

from fixtures.payserver import PayserverHandle
from fixtures.setup_helpers import (
    MAINNET_ADDRESS,
    MAINNET_XPUB,
    SetupWizard,
    TESTNET_TPUB,
    wizard_error as _error,
    wizard_heading as _heading,
)

ADMIN_PW = SetupWizard.DEFAULT_PASSWORD


def _stores(handle: PayserverHandle) -> list[sqlite3.Row]:
    conn = sqlite3.connect(handle.data_dir / "cashupay.sqlite")
    conn.row_factory = sqlite3.Row
    try:
        return conn.execute("SELECT * FROM stores").fetchall()
    finally:
        conn.close()


def Wizard(handle: PayserverHandle) -> SetupWizard:
    """Adapter kept so the cases below read as before."""
    return SetupWizard(handle.url)


def test_full_flow_persists_every_answer(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    body = w.through_store("Persisted Store")
    assert _heading(body) == "On-chain Bitcoin payments"

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="xpub",
        onchain_xpub=MAINNET_XPUB,
    )
    # Saving an on-chain destination pulls zero-conf into the sequence.
    assert _heading(body) == "Zero-conf payments"
    assert "of 11" in body

    body = w.post(step="zeroconf", zero_conf="1")
    assert _heading(body) == "Lightning payments"

    body = w.post(step="lightning", lightning_action="save", lightning_address="merchant@strike.me")
    assert _heading(body) == "Submarine swaps"

    body = w.post(step="swaps", swaps_enabled="1")
    assert _heading(body) == "Cashu mints"

    # Decline mints — the mint screen is where setup_complete flips.
    body = w.post(step="mints", mints_enabled="0")
    assert _heading(body) == "Enable cron"
    assert "X-CRON-KEY" in body, "the cron screen should render a ready-made crontab line"

    body = w.post(step="cron")
    assert _heading(body) == "You&#039;re all set!" or "all set" in _heading(body)
    # A stray PHP close tag once leaked onto this screen as literal text.
    assert "?>" not in body, "the completion screen must not render a bare ?> as text"

    row = _stores(payserver)[0]
    assert row["name"] == "Persisted Store"
    assert row["onchain_xpub"] == MAINNET_XPUB
    assert row["onchain_network"] == "mainnet"
    assert row["onchain_address_type"] == "P2WPKH"
    assert row["onchain_min_confs"] == 0
    assert row["swaps_enabled"] == 1
    assert row["strict_no_mint_fallback"] == 1, "declining mints pins strict mode"
    assert row["auto_melt_enabled"] == 1, "a Lightning destination enables threshold cashout"
    assert row["auto_melt_use_swap"] == 0, "and it sweeps over Lightning, not via a swap"
    assert not row["mint_url"] and not row["seed_phrase"]


def test_skipping_onchain_removes_the_zeroconf_screen(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    body = w.through_store("Skip Onchain")
    assert "of 10" in body, "without an on-chain rail the wizard is 10 screens, not 11"

    body = w.post(step="onchain", onchain_action="skip")
    assert _heading(body) == "Lightning payments", "zero-conf must be skipped with nothing to time"

    # Reaching it directly still renders (it is a known slug) but nothing
    # downstream depends on that; the sequence simply never routes there.
    assert _stores(payserver)[0]["onchain_min_confs"] == 1, "the default is left untouched"


def test_swaps_require_an_xpub_not_a_static_address(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Static Store")
    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="static",
        onchain_static_address=MAINNET_ADDRESS,
    )
    assert _heading(body) == "Zero-conf payments", "a static address is still an on-chain rail"
    row = _stores(payserver)[0]
    assert row["onchain_address_mode"] == "static"
    assert row["onchain_network"] == "mainnet", "the network is inferred from the address encoding"

    w.post(step="zeroconf", zero_conf="0")
    w.post(step="lightning", lightning_action="skip")

    body = w.post(step="swaps", swaps_enabled="1")
    assert "need an xpub" in (_error(body) or ""), _error(body)
    assert _stores(payserver)[0]["swaps_enabled"] == -1, "the rejected answer must not be saved"

    body = w.post(step="swaps", swaps_enabled="0")
    assert _heading(body) == "Cashu mints"
    assert _stores(payserver)[0]["swaps_enabled"] == 0


def test_testnet_family_xpub_takes_the_declared_subnetwork(payserver: PayserverHandle) -> None:
    """A tpub/upub/vpub could be testnet, signet or regtest — the version
    bytes are shared. The wizard reveals a picker and honours it, because
    guessing wrong generates addresses on the wrong chain."""
    w = Wizard(payserver)
    w.through_store("Regtest Store")
    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="xpub",
        onchain_xpub=TESTNET_TPUB,
        onchain_testnet_network="regtest",
    )
    assert _error(body) is None, _error(body)
    row = _stores(payserver)[0]
    assert row["onchain_network"] == "regtest"
    # A BIP44 prefix says nothing about the address type, so the wizard
    # defaults to native SegWit and lets the address preview settle it.
    assert row["onchain_address_type"] == "P2WPKH"


def test_invalid_inputs_are_rejected_with_readable_messages(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Validation Store")

    body = w.post(step="onchain", onchain_action="save", onchain_address_mode="xpub", onchain_xpub="nope")
    assert "extended public key" in (_error(body) or ""), _error(body)

    body = w.post(
        step="onchain",
        onchain_action="save",
        onchain_address_mode="static",
        onchain_static_address="definitely-not-an-address",
    )
    assert "Bitcoin address" in (_error(body) or ""), _error(body)

    w.post(step="onchain", onchain_action="skip")

    body = w.post(step="lightning", lightning_action="save", lightning_address="not-an-address")
    assert "myname@strike.me" in (_error(body) or ""), _error(body)

    body = w.post(step="lightning", lightning_action="save", noffer="noffer1garbage")
    assert "noffer1" in (_error(body) or ""), _error(body)

    # Nothing partial should have been written by the rejected submissions.
    conn = sqlite3.connect(payserver.data_dir / "cashupay.sqlite")
    try:
        assert conn.execute("SELECT COUNT(*) FROM store_ln_addresses").fetchone()[0] == 0
    finally:
        conn.close()


def test_duplicate_store_name_is_rejected_but_a_rename_is_not(payserver: PayserverHandle) -> None:
    w = Wizard(payserver)
    w.through_store("Original")

    # Same name again from the store screen is a rename of the store already
    # under construction, not a clash with itself.
    body = w.post(step="store", store_name="Original")
    assert _error(body) is None, _error(body)
    assert len(_stores(payserver)) == 1

    body = w.post(step="store", store_name="Renamed")
    assert _error(body) is None, _error(body)
    stores = _stores(payserver)
    assert len(stores) == 1, "going back to the store screen must not create a second store"
    assert stores[0]["name"] == "Renamed"


def test_add_store_does_not_rename_the_store_from_first_run(payserver: PayserverHandle) -> None:
    """The store screen reuses the in-progress store id so navigating Back
    renames rather than duplicates. That reuse must not leak across wizard
    runs: the session still holds the first-run store when the operator goes
    straight to "Create Store", and reusing it there would rename their live
    store instead of adding a new one.
    """
    w = Wizard(payserver)
    w.through_store("Live Store")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    w.post(step="mints", mints_enabled="0")
    assert [r["name"] for r in _stores(payserver)] == ["Live Store"]

    # add_store is admin-gated. Logging in on the same session keeps the
    # wizard's session data (session_regenerate_id preserves $_SESSION), which
    # is exactly how the stale store id survives into add_store.
    login = w.s.post(
        f"{payserver.url}/admin",
        data={"action": "login", "username": "admin", "password": ADMIN_PW},
        timeout=15,
    )
    assert login.status_code == 200 and login.json()["success"] is True, login.text

    # Same session, straight into add_store — no cron/done screen in between.
    w.s.get(w.url, params={"mode": "add_store"}, timeout=15)
    w.post(step="store", store_name="Extra Store", mode="add_store")

    names = sorted(r["name"] for r in _stores(payserver))
    assert names == ["Extra Store", "Live Store"], f"add_store must add, not rename: {names}"


def test_unknown_step_restarts_rather_than_rendering_blank(payserver: PayserverHandle) -> None:
    """Stale bookmarks and forms saved from the pre-slug wizard (step=4, …)
    must land somewhere usable."""
    w = Wizard(payserver)
    for stale in ("4", "", "bogus"):
        body = w.get(stale)
        assert _heading(body) == "Terms of service", f"step={stale!r} should restart the wizard"


def test_terms_gate_requires_both_checkboxes(payserver: PayserverHandle) -> None:
    """The terms-of-service gate opens every first run and only advances when
    both the legal-use and the as-is/no-warranty boxes are ticked."""
    w = Wizard(payserver)

    # The wizard lands on the terms gate before anything else.
    landing = w.get("")
    assert _heading(landing) == "Terms of service", _heading(landing)
    assert "github.com/BareBits/cashupayserver/blob/main/LICENSE.md" in landing, (
        "the license must link to the LICENSE file on GitHub"
    )

    # Neither box, then each box alone: all rejected, none advances.
    for data in ({}, {"terms_legal": "1"}, {"terms_warranty": "1"}):
        body = w.post(step="terms", **data)
        assert "accept both terms" in (_error(body) or ""), _error(body)
        assert _heading(body) == "Terms of service", "a partial acceptance must not advance"

    # Both boxes ticked advances to the safety check.
    body = w.post(step="terms", terms_legal="1", terms_warranty="1")
    assert _error(body) is None, _error(body)
    assert _heading(body) == "Quick safety check", _heading(body)
