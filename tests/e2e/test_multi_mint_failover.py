"""Multi-mint failover: when the primary mint is unreachable, the invoice
should mint at the next backup and record which mint actually served it."""
from __future__ import annotations

from conftest import ConfiguredPayserver
from fixtures.nutshell import MintHandle


def _add_backup_mint(shared_configured: ConfiguredPayserver, mint_url: str, *, priority: int = 100) -> None:
    """admin.php POST action=add_backup_mint."""
    r = shared_configured.admin._post_action(
        "add_backup_mint",
        store_id=shared_configured.store_id,
        mint_url=mint_url,
        unit="sat",
        priority=str(priority),
    )
    assert r, f"add_backup_mint returned empty: {r}"


def _set_primary_mint_url(shared_configured: ConfiguredPayserver, mint_url: str) -> None:
    with shared_configured.handle.db() as db:
        db.execute("UPDATE stores SET mint_url = ? WHERE id = ?", (mint_url, shared_configured.store_id))


def _clear_backup_mints(shared_configured: ConfiguredPayserver) -> None:
    """Drop the store's backup mints. The onboarding wizard provisions a
    required backup mint at setup, so tests that need "no reachable mint" must
    remove it explicitly."""
    with shared_configured.handle.db() as db:
        db.execute("DELETE FROM store_mints WHERE store_id = ?", (shared_configured.store_id,))


def test_invoice_falls_over_to_backup_when_primary_is_dead(
    shared_configured: ConfiguredPayserver,
    mint: MintHandle,
    backup_mint: MintHandle,
) -> None:
    # Add the real (working) mint as a backup before nuking primary.
    _add_backup_mint(shared_configured, mint.url, priority=100)
    # Point primary at a TCP port that nothing is listening on.
    dead_url = "http://127.0.0.1:1"
    _set_primary_mint_url(shared_configured, dead_url)

    invoice = shared_configured.greenfield.create_invoice(
        shared_configured.store_id, amount="500", currency="sat"
    )
    assert invoice["status"] in ("New", "Processing")
    assert invoice.get("checkout", {}).get("paymentMethods", {}).get("BTC-LightningNetwork"), invoice

    # The invoice row should record the actual mint that served it: one of the
    # reachable backups (the wizard-provisioned backup_mint, or the mint added
    # above), never the dead primary.
    with shared_configured.handle.db() as db:
        row = db.execute(
            "SELECT mint_url FROM invoices WHERE id = ?", (invoice["id"],)
        ).fetchone()
    assert row is not None
    assert row["mint_url"] in {mint.url, backup_mint.url}, (
        f"expected a reachable backup, got {row['mint_url']}"
    )
    assert row["mint_url"] != dead_url


def test_invoice_creation_fails_when_no_mints_are_reachable(
    shared_configured: ConfiguredPayserver,
) -> None:
    """With no backups and primary dead, the API should surface the error."""
    _clear_backup_mints(shared_configured)
    _set_primary_mint_url(shared_configured, "http://127.0.0.1:1")

    import pytest
    with pytest.raises(RuntimeError, match="invoice-error"):
        shared_configured.greenfield.create_invoice(shared_configured.store_id, amount="500", currency="sat")
