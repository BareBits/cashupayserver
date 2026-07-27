"""Admin recovery-phrase reveal.

The onboarding wizard generates a store's Cashu wallet seed silently and shows
it once on the completion screen. This endpoint is the only way to get it back,
so it is also the most sensitive read in the panel: it returns spendable key
material and must not be reachable with a hijacked session alone.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD


def _raw_reveal(configured: ConfiguredPayserver, store_id: str, password: str) -> requests.Response:
    """Call the action directly so non-200 responses can be inspected —
    AdminClient._post_action raises on >=400."""
    admin = configured.admin
    return admin.s.post(
        f"{configured.handle.url}/admin",
        data={"action": "reveal_store_seed", "store_id": store_id, "password": password},
        headers={"X-CSRF-Token": admin.csrf_token},
        timeout=15,
    )


def test_reveal_returns_the_seed_with_the_right_password(configured: ConfiguredPayserver) -> None:
    r = _raw_reveal(configured, configured.store_id, configured.admin_password)
    assert r.status_code == 200, r.text
    seed = r.json()["seedPhrase"]
    assert len(seed.split()) == 12, f"expected a 12-word phrase, got {seed!r}"

    # It must be the phrase the wallet actually uses, not a freshly minted one.
    with configured.handle.db() as db:
        row = db.execute(
            "SELECT seed_phrase FROM stores WHERE id = ?", (configured.store_id,)
        ).fetchone()
    assert seed == row[0]


def test_reveal_rejects_a_wrong_password(configured: ConfiguredPayserver) -> None:
    """A hijacked session is not enough — the current password gates the read."""
    r = _raw_reveal(configured, configured.store_id, "not-the-password")
    assert r.status_code == 401, r.text
    assert "seedPhrase" not in r.text, "the seed must not leak on a failed check"


def test_reveal_requires_authentication(configured: ConfiguredPayserver) -> None:
    anon = requests.Session()
    r = anon.post(
        f"{configured.handle.url}/admin",
        data={
            "action": "reveal_store_seed",
            "store_id": configured.store_id,
            "password": DEFAULT_ADMIN_PASSWORD,
        },
        timeout=15,
    )
    assert r.status_code in (401, 403), r.text
    assert "seedPhrase" not in r.text


def test_reveal_reports_stores_that_have_no_wallet(configured: ConfiguredPayserver) -> None:
    """A store set up with mints declined has no seed at all; the endpoint says
    so with an empty value rather than inventing one."""
    with configured.handle.db() as db:
        db.execute("UPDATE stores SET seed_phrase = NULL WHERE id = ?", (configured.store_id,))

    r = _raw_reveal(configured, configured.store_id, configured.admin_password)
    assert r.status_code == 200, r.text
    assert r.json()["seedPhrase"] == ""


def test_reveal_rejects_an_unknown_store(configured: ConfiguredPayserver) -> None:
    r = _raw_reveal(configured, "store_does_not_exist", configured.admin_password)
    assert r.status_code == 400, r.text
    assert "seedPhrase" not in r.text
