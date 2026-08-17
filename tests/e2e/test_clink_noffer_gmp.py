"""CLINK noffer settings on a host whose PHP lacks the GMP extension.

The noffer payer path Schnorr-signs Nostr events through swentel/nostr-php,
which hard-requires ext-gmp — the same extension the on-chain stack needs, and
one shared WordPress hosts frequently ship without. Before the gate, a noffer
saved on such a host looked fine in settings but every checkout's CLINK
request threw, so the customer silently saw an on-chain-only invoice.

These tests pin the environment gate on both settings surfaces (the mirror of
test_setup_onchain_gmp.py's coverage for the on-chain screens):

  - onboarding wizard, lightning screen: the noffer field renders disabled
    with the php-gmp warning; a direct POST with a new noffer is rejected with
    that message; a Lightning address alone still saves and advances.
  - wizard save must not silently DELETE a stored noffer just because the
    disabled field didn't submit it.
  - admin save_auto_melt: a new noffer is rejected with the actionable
    message; noffers already stored pass through (kept or removed) so the
    rest of the card stays editable.

The GMP-less host is simulated by starting the payserver with the gmp_*
functions disabled (calling a disabled function raises the same Error an
absent extension does).
"""
from __future__ import annotations

import re
import sqlite3
import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.api_client import AdminClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import (
    REFERENCE_NOFFER,
    SetupWizard,
    wizard_error,
    wizard_heading,
)

GMP_FUNCS = (
    "gmp_init,gmp_add,gmp_mul,gmp_cmp,gmp_mod,gmp_div_q,gmp_intval,gmp_strval,"
    "gmp_sub,gmp_pow,gmp_powm,gmp_invert,gmp_import,gmp_export,gmp_and,gmp_or,"
    "gmp_setbit,gmp_testbit,gmp_neg"
)


@pytest.fixture()
def gmpless_payserver(lnurlp_server) -> Iterator[PayserverHandle]:
    # The lnurlp mock is routed in so the plain lnaddresses these tests save
    # alongside noffers pass the save-time LUD-21 gate.
    workdir = SESSION_TMP / f"payserver-noffer-gmpless-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_php_args=["-d", f"disable_functions={GMP_FUNCS}"],
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server.url_template},
    )
    yield handle
    stop_payserver(handle)


def _noffer_input_chunk(body: str) -> str:
    """The <input ...> tag of the wizard's noffer field."""
    m = re.search(r'<input[^>]*name="noffer"[^>]*>', body)
    assert m, "lightning screen should render the noffer input"
    return m.group(0)


def _to_lightning_screen(w: SetupWizard, store: str) -> str:
    w.through_store(store)
    return w.post(step="onchain", onchain_action="skip")


def _chain_rows(handle: PayserverHandle) -> list[sqlite3.Row]:
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        conn.row_factory = sqlite3.Row
        return list(conn.execute(
            "SELECT store_id, address, type, position FROM store_ln_addresses "
            "ORDER BY position ASC"
        ))


def _seed_noffer(handle: PayserverHandle, noffer: str) -> str:
    """Insert a noffer directly, as if saved before the host lost GMP.
    Returns the store id it was attached to."""
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        store_id = conn.execute("SELECT id FROM stores").fetchone()[0]
        conn.execute(
            "INSERT INTO store_ln_addresses (store_id, position, address, type, supports_verify) "
            "VALUES (?, ?, ?, 'noffer', NULL)",
            (store_id, 0, noffer),
        )
        conn.commit()
    return store_id


# ------------------------------------------------------------ onboarding wizard


def test_wizard_noffer_field_disabled_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: the lightning screen carries the php-gmp warning and the
    noffer field is disabled; the LNURL field stays usable."""
    body = _to_lightning_screen(SetupWizard(gmpless_payserver.url), "Noffer GMP-less Store")
    assert wizard_heading(body) == "Lightning payments"
    assert "php-gmp" in body
    assert "disabled" in _noffer_input_chunk(body)
    ln_chunk = re.search(r'<input[^>]*name="lightning_address"[^>]*>', body)
    assert ln_chunk and "disabled" not in ln_chunk.group(0)


def test_wizard_rejects_new_noffer_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: a direct POST past the disabled field is refused with
    the actionable message and nothing is persisted."""
    w = SetupWizard(gmpless_payserver.url)
    _to_lightning_screen(w, "Noffer POST Store")
    body = w.post(step="lightning", lightning_action="save", noffer=REFERENCE_NOFFER)
    err = wizard_error(body)
    assert err is not None and "GMP" in err, f"error was: {err!r}"
    assert wizard_heading(body) == "Lightning payments"
    assert _chain_rows(gmpless_payserver) == []


def test_wizard_lightning_address_still_saves_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: the gate must not take the LNURL path down with it."""
    w = SetupWizard(gmpless_payserver.url)
    _to_lightning_screen(w, "LNURL Still Works Store")
    body = w.post(
        step="lightning", lightning_action="save", lightning_address="shop@example.test"
    )
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body) == "Submarine swaps"
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [("shop@example.test", "lnaddress")]


def test_wizard_save_preserves_stored_noffer_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: the disabled noffer field doesn't submit, so a save must
    keep a previously stored noffer rather than silently deleting it — it
    starts working again the moment the host gains GMP."""
    w = SetupWizard(gmpless_payserver.url)
    body = _to_lightning_screen(w, "Preserve Noffer Store")
    _seed_noffer(gmpless_payserver, REFERENCE_NOFFER)

    # Re-render: the stored noffer shows in the (disabled) field.
    body = w.get("lightning")
    assert REFERENCE_NOFFER in body

    body = w.post(
        step="lightning", lightning_action="save", lightning_address="keep@example.test"
    )
    assert wizard_error(body) is None, wizard_error(body)
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [
        ("keep@example.test", "lnaddress"),
        (REFERENCE_NOFFER, "noffer"),
    ]


# ------------------------------------------------------------- admin settings


def _walk_wizard_mintless(w: SetupWizard, store: str) -> None:
    """Complete the wizard with no on-chain, no lightning and no mint — the
    minimal path that unlocks the admin dashboard on a GMP-less host."""
    _to_lightning_screen(w, store)
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    w.post(step="mints", mints_enabled="0")


def _admin_for(handle: PayserverHandle, store: str) -> tuple[AdminClient, str]:
    w = SetupWizard(handle.url)
    _walk_wizard_mintless(w, store)
    admin = AdminClient(handle.url)
    admin.login(SetupWizard.DEFAULT_PASSWORD)
    stores = admin.list_stores()
    assert stores, "wizard walk should have created a store"
    return admin, stores[0]["id"]


def _save_auto_melt(admin: AdminClient, store_id: str, *, ln=(), noffers=()):
    data = [
        ("action", "save_auto_melt"),
        ("store_id", store_id),
        ("enabled", "1"),
        ("threshold", "100"),
        ("mode_override", "0"),
    ]
    for a in ln:
        data.append(("ln_addresses[]", a))
    for n in noffers:
        data.append(("noffers[]", n))
    return admin.s.post(
        admin._admin_url,
        data=data,
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=30,
    )


def test_admin_rejects_new_noffer_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: save_auto_melt refuses to ADD a noffer, names php-gmp,
    and persists nothing."""
    admin, store_id = _admin_for(gmpless_payserver, "Admin Gate Store")
    r = _save_auto_melt(admin, store_id, noffers=[REFERENCE_NOFFER])
    body = r.json()
    assert body.get("success") is not True, body
    assert "GMP" in (body.get("error") or ""), body
    assert _chain_rows(gmpless_payserver) == []

    # The settings page itself renders the section gated.
    page = admin.s.get(admin._admin_url, timeout=15).text
    assert 'id="auto-melt-noffer-group"' in page
    assert "data-env-error" in page
    assert "noffers are unavailable on this server" in page


def test_admin_keeps_and_removes_stored_noffers_without_gmp(
    gmpless_payserver: PayserverHandle,
) -> None:
    """GMP-less host: noffers stored before the host lost GMP can be saved
    back unchanged (the card stays editable) and can be removed — only NEW
    ones are refused."""
    admin, store_id = _admin_for(gmpless_payserver, "Admin Keep Store")
    _seed_noffer(gmpless_payserver, REFERENCE_NOFFER)

    # Re-saving the same chain (what the greyed-out UI submits) is allowed.
    r = _save_auto_melt(admin, store_id, ln=["keep@example.test"], noffers=[REFERENCE_NOFFER])
    assert r.json().get("success") is True, r.text
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [
        ("keep@example.test", "lnaddress"),
        (REFERENCE_NOFFER, "noffer"),
    ]

    # Removing it is allowed too.
    r = _save_auto_melt(admin, store_id, ln=["keep@example.test"])
    assert r.json().get("success") is True, r.text
    rows = _chain_rows(gmpless_payserver)
    assert [(r["address"], r["type"]) for r in rows] == [("keep@example.test", "lnaddress")]


# ------------------------------------------------------- healthy-host contrast


def test_wizard_noffer_field_enabled_with_gmp(payserver: PayserverHandle) -> None:
    """Healthy host: no warning, field enabled, and the noffer saves — pins
    that the gate doesn't fire when the environment is fine."""
    w = SetupWizard(payserver.url)
    body = _to_lightning_screen(w, "Healthy Noffer Store")
    assert "php-gmp" not in body
    assert "disabled" not in _noffer_input_chunk(body)

    body = w.post(step="lightning", lightning_action="save", noffer=REFERENCE_NOFFER)
    assert wizard_error(body) is None, wizard_error(body)
    rows = _chain_rows(payserver)
    assert [(r["address"], r["type"]) for r in rows] == [(REFERENCE_NOFFER, "noffer")]
