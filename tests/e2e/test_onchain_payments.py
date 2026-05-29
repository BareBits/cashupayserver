"""End-to-end on-chain Bitcoin payment tests against bitcoind regtest.

Uses the existing bitcoind regtest fixture as both the Bitcoin network and
the BlockchainProvider backend for cashupayserver (BitcoindRpcProvider). A
fixed regtest tpub is registered against the test store; the test sends to
the address cashupayserver derives, then polls and checks state transitions.
"""
from __future__ import annotations

import time

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.bitcoind import BitcoindHandle
from fixtures.onchain import (
    OnchainContext,
    configure_store_for_onchain,
    derive_address_in_bitcoind,
)


INVOICE_AMOUNT_SAT = 50_000


_NEXT_INDEX_OFFSET = [10]  # mutable counter; module-level so it persists across tests


def _wire_onchain(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
    *,
    min_confs: int = 1,
) -> int:
    """Configure the store's on-chain settings + provider URL to point at
    bitcoind's dedicated watch-only wallet endpoint. Each test gets a unique
    starting derivation index so addresses don't collide across the suite
    (all tests share TEST_TPUB).
    Returns the start_index used."""
    start_index = _NEXT_INDEX_OFFSET[0]
    _NEXT_INDEX_OFFSET[0] += 32  # large enough that a single test can't run past the next slot
    configure_store_for_onchain(
        configured.handle.db_path,
        configured.store_id,
        xpub=onchain.tpub,
        network="regtest",
        address_type="P2WPKH",
        min_confs=min_confs,
        confirm_timeout_sec=86400,
        provider_url=onchain.watch_wallet_url,
        start_index=start_index,
    )
    return start_index


def _poll_until(configured: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30) -> dict:
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        # Trigger cron to drive the on-chain poller.
        configured.handle.trigger_cron()
        last = configured.greenfield.get_invoice(configured.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(f"invoice {invoice_id} never reached {status}; last={last}")


# ---------- xpub validation + parity ----------


def test_xpub_validation_admin_endpoint(configured: ConfiguredPayserver, onchain: OnchainContext) -> None:
    """Admin's validate_onchain_xpub action returns valid + a 3-address preview."""
    r = configured.admin.s.post(
        f"{configured.handle.url}/admin",
        data={
            "action": "validate_onchain_xpub",
            "xpub": onchain.tpub,
            "network": "regtest",
            "address_type": "P2WPKH",
        },
        headers={"X-CSRF-Token": configured.admin.csrf_token},
        timeout=15,
    )
    r.raise_for_status()
    body = r.json()
    assert body["valid"] is True, body
    assert len(body["preview"]) == 3
    for addr in body["preview"]:
        assert addr.startswith("bcrt1"), addr


def test_xpub_rejects_mainnet_on_regtest_store(configured: ConfiguredPayserver) -> None:
    mainnet_xpub = (
        "xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic"
        "qYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz"
    )
    r = configured.admin.s.post(
        f"{configured.handle.url}/admin",
        data={
            "action": "validate_onchain_xpub",
            "xpub": mainnet_xpub,
            "network": "regtest",
            "address_type": "P2WPKH",
        },
        headers={"X-CSRF-Token": configured.admin.csrf_token},
        timeout=15,
    )
    r.raise_for_status()
    body = r.json()
    assert body["valid"] is False
    assert "mainnet" in (body["error"] or "").lower()


def test_address_derivation_parity_with_bitcoin_cli(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """cashupayserver's derived addresses must match what bitcoin-cli
    deriveaddresses produces for the same descriptor."""
    start_index = _wire_onchain(configured, onchain)
    addrs_from_cashupay = []
    for _ in range(3):
        inv = configured.greenfield.create_invoice(
            configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
        )
        addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
        addrs_from_cashupay.append(addr)
        # Cancel so they don't all poll
        configured.greenfield.mark_invoice_status(configured.store_id, inv["id"], "Invalid")

    addrs_from_bitcoind = [
        derive_address_in_bitcoind(onchain.bitcoind, onchain.tpub, "P2WPKH", start_index + i)
        for i in range(3)
    ]
    assert addrs_from_cashupay == addrs_from_bitcoind, (
        f"derivation mismatch\n  cashupay: {addrs_from_cashupay}\n  bitcoind: {addrs_from_bitcoind}"
    )


# ---------- payment flows ----------


def test_zero_conf_payment_settles(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """min_confs=0: mempool sighting alone settles the invoice."""
    _wire_onchain(configured, onchain, min_confs=0)
    inv = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    txid = onchain.fund_address(addr, INVOICE_AMOUNT_SAT)
    assert len(txid) == 64

    settled = _poll_until(configured, inv["id"], "Settled", timeout_s=20)
    assert settled["status"] == "Settled"


def test_confirmed_payment_settles_after_block(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """min_confs=1: needs the funding tx to be mined."""
    _wire_onchain(configured, onchain, min_confs=1)
    inv = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    onchain.fund_address(addr, INVOICE_AMOUNT_SAT)

    # Before mining, polling should leave it Processing (not Settled).
    configured.handle.trigger_cron()
    pending = configured.greenfield.get_invoice(configured.store_id, inv["id"])
    assert pending["status"] in ("New", "Processing"), pending

    onchain.confirm(1)
    settled = _poll_until(configured, inv["id"], "Settled", timeout_s=20)
    assert settled["status"] == "Settled"


def test_split_payment_totals_to_settle(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """Two transactions whose combined value meets the invoice amount → Settled."""
    _wire_onchain(configured, onchain, min_confs=0)
    inv = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    onchain.fund_address(addr, INVOICE_AMOUNT_SAT // 2)
    onchain.fund_address(addr, INVOICE_AMOUNT_SAT // 2 + 1)  # slight overpay

    settled = _poll_until(configured, inv["id"], "Settled", timeout_s=20)
    assert settled["status"] == "Settled"


def test_underpayment_stays_processing(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """A payment below the invoice total should leave the invoice in Processing
    state — not Settled, not Expired/Invalid yet either (TTCW hasn't elapsed)."""
    _wire_onchain(configured, onchain, min_confs=0)
    inv = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
    onchain.fund_address(addr, INVOICE_AMOUNT_SAT // 4)

    # Poll a few times; should converge to Processing, not Settled.
    for _ in range(5):
        configured.handle.trigger_cron()
        time.sleep(0.3)
    got = configured.greenfield.get_invoice(configured.store_id, inv["id"])
    assert got["status"] == "Processing", got


def test_address_uniqueness_across_invoices(
    configured: ConfiguredPayserver,
    onchain: OnchainContext,
) -> None:
    """Three invoices must produce three distinct addresses (counter increments)."""
    _wire_onchain(configured, onchain)
    seen = set()
    for _ in range(3):
        inv = configured.greenfield.create_invoice(
            configured.store_id, amount="1000", currency="sat"
        )
        addr = inv["checkout"]["paymentMethods"]["BTC-OnChain"]["destination"]
        assert addr not in seen, f"duplicate address {addr}"
        seen.add(addr)
