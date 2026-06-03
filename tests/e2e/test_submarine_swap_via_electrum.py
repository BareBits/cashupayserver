"""End-to-end submarine-swap test driven by a real Electrum customer wallet.

Self-payment topology: the same Electrum wallet acts as
  - customer (pays the BOLT11 via lnpay over its LN channel to Boltz lnd-1)
  - merchant (owns the xpub where the on-chain claim lands)

The net effect: sats move from the LN balance to the on-chain balance,
minus Boltz's percentage fee + lockup miner fee + claim miner fee.

Prereqs (will skip with a loud reason if missing):
  - sudo + docker + docker compose v2 (Boltz regtest stack)
  - tests/bin/{php-8.3.31, fulcrum-2.1.1, electrum-4.7.2} bundled binaries

This test is slow (~3-5 min including Boltz bring-up + teardown) but is
included in the default pytest run by request.
"""
from __future__ import annotations

import json
import shutil
import sqlite3
import time
import uuid
from pathlib import Path
from typing import Iterator

import pytest

from fixtures.boltz_regtest import boltz_regtest, BoltzRegtestHandle  # noqa: F401 — pytest fixture import
from fixtures import swap_stack
from fixtures.swap_stack import (
    create_swap_invoice,
    drive_swap_to_terminal,
    fetch_invoice_row,
    fetch_swap_row,
    fund_electrum,
    open_channel_to_boltz_receiver,
    pay_bolt11,
    start_electrum,
    start_fulcrum,
    setup_payserver,
    stop_electrum,
    stop_fulcrum,
    stop_payserver,
    wait_for_lightning_route,
    electrum_cli_wallet,
)


def _balance_at(electrum: swap_stack.ElectrumProc, boltz: BoltzRegtestHandle, address: str) -> int:
    """Confirmed sat balance Electrum reports for the given address."""
    # listunspent isn't address-scoped on all Electrum versions; rely on
    # the Boltz bitcoind directly + Fulcrum to confirm Electrum sees it.
    txouts = boltz.bitcoind_rpc("scantxoutset", "start", [f"addr({address})"])
    confirmed_btc = float((txouts or {}).get("total_amount", 0))
    return int(round(confirmed_btc * 100_000_000))


@pytest.mark.slow
def test_submarine_swap_via_electrum_lightning(boltz_regtest: BoltzRegtestHandle, tmp_path: Path) -> None:
    """End-to-end: Electrum pays a BOLT11 from cashupayserver; swap claim
    lands at the merchant xpub address (also derived from Electrum). The
    same wallet should see net-out of its LN balance and net-in of an
    on-chain UTXO."""
    workdir = tmp_path / f"swap-test-{uuid.uuid4().hex[:6]}"
    workdir.mkdir()

    fulcrum = None
    electrum = None
    payserver = None
    try:
        # ---- Stack bring-up ----
        fulcrum = start_fulcrum(workdir, boltz_regtest)
        electrum = start_electrum(workdir, fulcrum.port)
        assert electrum.vpub and electrum.vpub.startswith(("vpub", "tpub", "xpub")), \
            f"unexpected vpub: {electrum.vpub!r}"

        # Fund Electrum on-chain (~5M sat, way more than the channel needs)
        fund_electrum(electrum, boltz_regtest, 5_000_000)

        # Open a 300k channel DIRECTLY to Boltz's BOLT11 receiver (cln-2).
        # 1-hop payment, no graph gossip needed.
        open_channel_to_boltz_receiver(electrum, boltz_regtest, capacity_sat=300_000)
        wait_for_lightning_route(electrum, boltz_regtest.cln2_pubkey, timeout=10)

        payserver = setup_payserver(workdir, electrum.vpub, boltz_regtest.api_url,
                                     strict_no_mint_fallback=True)

        # ---- Create the invoice ----
        target_sats = 100_000  # well above Boltz regtest minimum of 50,000
        invoice = create_swap_invoice(payserver, target_sats, currency="sat")
        invoice_id = invoice["id"]
        bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
        assert bolt11.startswith("lnbcrt"), f"expected lnbcrt invoice, got {bolt11[:20]}…"

        swap_row = fetch_swap_row(payserver, invoice_id)
        assert swap_row is not None, "swap_attempts row should be persisted at create-time"
        assert swap_row["status"] == "swap.created"
        assert swap_row["target_onchain_amount_sats"] == target_sats
        assert swap_row["invoice_amount_sats"] > target_sats, \
            "invoice amount should include the swap fees"
        merchant_address = swap_row["merchant_address"]

        # ---- Pay via Electrum LN ----
        # `lnpay` blocks on a hold invoice until Boltz settles. We don't
        # want to wait synchronously — kick it off and drive the cron in
        # parallel.
        import threading
        pay_result: dict = {}
        pay_error: list[Exception] = []

        def _do_pay():
            try:
                pay_result.update(pay_bolt11(electrum, bolt11, timeout=180))
            except Exception as e:
                pay_error.append(e)

        pay_thread = threading.Thread(target=_do_pay, daemon=True)
        pay_thread.start()

        # ---- Drive the swap lifecycle ----
        final = drive_swap_to_terminal(payserver, invoice_id, boltz_regtest, timeout=180)
        pay_thread.join(timeout=30)

        assert final["status"] == "invoice.settled", \
            f"expected invoice.settled, got {final['status']} (error_message={final.get('error_message')})"
        assert final["claim_txid"], "claim_txid should be populated"
        assert final["lockup_txid"], "lockup_txid should be recorded"
        if pay_error:
            raise AssertionError(f"lnpay raised: {pay_error[0]}")

        # Invoice should be Settled too
        inv_final = fetch_invoice_row(payserver, invoice_id)
        assert inv_final is not None
        assert inv_final["status"] == "Settled", f"invoice status {inv_final['status']}"
        assert inv_final["payment_rail"] == "swap"

        # ---- Verify the on-chain claim landed at the merchant address ----
        # Mine a couple more confirmations to make scantxoutset deterministic
        boltz_regtest.mine_blocks(2)
        time.sleep(1)
        confirmed_sats = _balance_at(electrum, boltz_regtest, merchant_address)
        # Expected: target − Boltz's claim miner fee (~111 sat on regtest pair).
        # The exact claim fee varies; tolerate ±300 sats.
        assert abs(confirmed_sats - target_sats) <= 300, (
            f"merchant address received {confirmed_sats} sat, expected ~{target_sats} "
            f"(claim_txid={final['claim_txid']})"
        )

    finally:
        # Always tear down in reverse order
        if payserver is not None:
            stop_payserver(payserver)
        if electrum is not None:
            stop_electrum(electrum)
        if fulcrum is not None:
            stop_fulcrum(fulcrum)
        # Boltz regtest is tear-down via fixture finalizer at session end
