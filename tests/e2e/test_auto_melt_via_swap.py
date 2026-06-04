"""End-to-end test for auto-withdraw via submarine swap.

Verifies the full pipeline:
  1. Cashu wallet on the payserver is funded with proofs (via a mint-rail
     invoice paid from Boltz's lnd-1 → our host LND backing the mint).
  2. Store is flipped into sweep mode (auto_melt_enabled + auto_melt_use_swap=1).
  3. Cron tick triggers SwapAutoMelt::checkAndExecute:
       - quote fetched from Boltz
       - threshold (5,000 sat floor + 1% cap) is satisfied
       - reverse swap is created
       - cashu wallet melts against the swap's BOLT11
       - mint-LND routes through lnd-1 to cln-2 (Boltz BOLT11 receiver)
  4. Boltz locks up on-chain → SwapPoller (sweep ctx) sees mempool →
     SwapClaimer builds + broadcasts the script-path claim tx.
  5. The claim tx pays an address derived from Electrum's vpub, so the
     same wallet that supplies the merchant xpub sees the swept UTXO.

Topology:

      ┌────────────────── Boltz regtest (Docker) ──────────────────┐
      │  bitcoind ── lnd-1 / lnd-2 / lnd-3 / cln-1 / cln-2 ── boltz │
      │     ▲           ▲                                ▲         │
      └─────┼───────────┼────────────────────────────────┼─────────┘
            │           │                                │
        RPC :28443  P2P :9737                       API :9001
            │           │                                │
   ┌────────┼───────────┼────────────────────────────────┼─────────┐
   │ Host:  │           │                                │         │
   │ Fulcrum──▶ Electrum (vpub + sweep destination)      │         │
   │   │                                                 │         │
   │ lnd_mint ───── channel ─────▶ lnd-1 (Boltz)         │         │
   │   ▲   ▲                                             │         │
   │   │   └── cashu mint (Nutshell, REST backend)       │         │
   │   │                                                 │         │
   │ cashupayserver ────── /v2/swap/reverse ─────────────┘         │
   │  (sweep-mode store)                                           │
   └───────────────────────────────────────────────────────────────┘

Prereqs (will skip if missing):
  - sudo + docker + docker compose v2 (Boltz regtest stack)
  - tests/bin/{php-8.3.31, fulcrum-2.1.1, electrum-4.7.2, lnd-0.18.5-beta}
    bundled binaries
  - Nutshell venv (auto-installed by start_mint on first run)

This test is slow (~5-8 min) due to the Boltz bring-up + channel-open
delays. Marked `slow` to match the existing swap e2e.
"""
from __future__ import annotations

import time
import uuid
from pathlib import Path

import pytest

from fixtures.boltz_regtest import boltz_regtest, BoltzRegtestHandle  # noqa: F401
from fixtures.lnd import start_lnd, stop_lnd
from fixtures.nutshell import start_mint, stop_mint
from fixtures import swap_stack
from fixtures.swap_stack import (
    BoltzBitcoindShim,
    drive_sweep_to_terminal,
    enable_sweep_mode_for_store,
    fetch_sweep_row,
    fund_cashu_wallet_via_lnd1,
    fund_electrum,
    open_lnd_to_boltz_lnd1_channel,
    setup_payserver_for_sweep,
    start_electrum,
    start_fulcrum,
    stop_electrum,
    stop_fulcrum,
    stop_sweep_payserver,
    trigger_cron_compat,
)


def _scantxout_confirmed_sats(boltz: BoltzRegtestHandle, address: str) -> int:
    """Confirmed sat balance at the given address per Boltz's bitcoind."""
    res = boltz.bitcoind_rpc("scantxoutset", "start", [f"addr({address})"])
    return int(round(float((res or {}).get("total_amount", 0)) * 100_000_000))


@pytest.mark.slow
def test_auto_melt_sweeps_cashu_balance_to_electrum_xpub(
    boltz_regtest: BoltzRegtestHandle,
    tmp_path: Path,
) -> None:
    """The store's cashu balance, once it exceeds the 5,000-sat floor + 1%
    cost gate, should be swept on-chain to an address derived from the
    Electrum vpub registered as the store's xpub."""
    workdir = tmp_path / f"sweep-test-{uuid.uuid4().hex[:6]}"
    workdir.mkdir()

    bitcoind_shim = BoltzBitcoindShim(boltz=boltz_regtest)
    # Mature coinbase so the shim can fund on-chain (LND wallet + Electrum).
    height = bitcoind_shim.block_count()
    if height < 101:
        bitcoind_shim.mine(101 - height)

    fulcrum = None
    electrum = None
    lnd_mint = None
    mint = None
    payserver = None

    try:
        # --- Electrum (host-side, against Boltz's bitcoind) ----------------
        # Supplies the vpub that will receive the sweep claim and acts as
        # the wallet the merchant would see in production.
        fulcrum = start_fulcrum(workdir, boltz_regtest)
        electrum = start_electrum(workdir, fulcrum.port)
        assert electrum.vpub and electrum.vpub.startswith(("vpub", "tpub", "xpub")), \
            f"unexpected vpub: {electrum.vpub!r}"
        fund_electrum(electrum, boltz_regtest, 500_000)

        # --- Mint-backing LND ----------------------------------------------
        # Talks to Boltz's bitcoind via cookie auth on the host-exposed
        # RPC + ZMQ ports. Channels with lnd-1 are opened below.
        cookie = boltz_regtest.bitcoind_cookie()
        user, _, password = cookie.partition(":")
        lnd_mint = start_lnd(workdir, "mint", bitcoind_shim,
                             rpc_user=user, rpc_pass=password)

        # --- LND-mint <-> Boltz lnd-1 channel ------------------------------
        # Dual-direction (5M push) so:
        #   * Boltz lnd-1 can pay the cashu mint invoice (funds the wallet)
        #   * lnd-mint can pay the sweep BOLT11 (routes via lnd-1 to cln-2)
        open_lnd_to_boltz_lnd1_channel(
            lnd_mint, boltz_regtest, bitcoind_shim,
            capacity_sat=10_000_000, push_sat=5_000_000,
        )

        # --- Cashu mint (Nutshell) -----------------------------------------
        mint = start_mint(workdir, lnd_mint)
        mint.wait_ready()

        # --- Payserver -----------------------------------------------------
        # Store starts with swaps force-OFF + auto_melt off so funding goes
        # through the cashu-mint rail. enable_sweep_mode_for_store flips the
        # flags after funding so the next cron tick triggers SwapAutoMelt.
        payserver = setup_payserver_for_sweep(
            workdir, vpub=electrum.vpub, mint_url=mint.url,
            boltz_api_url=boltz_regtest.api_url,
        )

        # --- Fund the cashu wallet -----------------------------------------
        # 200,000 sat: comfortably above the 50,000-sat Boltz regtest pair
        # minimum AND large enough that the percent-fee + miner-fee total
        # stays well under the 1% sweep cap (~0.5% fee + ~400 sat fixed ≈
        # 1,400 sat on 200k = 0.7%).
        funded_invoice = fund_cashu_wallet_via_lnd1(
            payserver, boltz_regtest, amount_sats=200_000, timeout=120,
        )
        assert funded_invoice["status"] == "Settled"
        assert funded_invoice["payment_rail"] in ("mint", None), \
            f"funding invoice should not have gone through swap: {funded_invoice}"

        # Sanity: cashu wallet now holds proofs ≥ 5,000 sat (the static
        # minimum). Cashu proofs live in cashu_proofs keyed by
        # wallet_id = first 16 hex of sha256(mint_url + ':' + mint_unit).
        import hashlib as _hashlib
        import sqlite3
        wallet_id = _hashlib.sha256(f"{mint.url}:sat".encode()).hexdigest()[:16]
        with sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite")) as conn:
            conn.row_factory = sqlite3.Row
            proof_sum = conn.execute(
                "SELECT COALESCE(SUM(amount), 0) AS s FROM cashu_proofs"
                " WHERE wallet_id = ? AND state = 'UNSPENT'",
                (wallet_id,),
            ).fetchone()
            assert proof_sum and int(proof_sum["s"]) >= 5_000, (
                f"cashu wallet should be funded ≥ 5,000 sat; got "
                f"{int(proof_sum['s']) if proof_sum else 0}"
            )

        # --- Flip the store into sweep mode --------------------------------
        enable_sweep_mode_for_store(payserver)

        # First cron tick should kick off SwapAutoMelt and create a sweep
        # row. Drive subsequent ticks until the row reaches terminal state.
        trigger_cron_compat(payserver)

        sweep_row = drive_sweep_to_terminal(
            payserver, boltz_regtest, timeout=300.0,
        )
        assert sweep_row["status"] == "invoice.settled", (
            f"sweep status = {sweep_row['status']}, "
            f"error_message={sweep_row.get('error_message')}"
        )
        assert sweep_row["claim_txid"], "claim_txid should be populated on settlement"
        assert sweep_row["lockup_txid"], "lockup_txid should be recorded after claim"
        merchant_address = sweep_row["merchant_address"]
        assert merchant_address, "sweep_attempts.merchant_address should be set"

        # --- Verify the funds landed at the Electrum-vpub-derived address --
        # Mine a couple more confs so scantxoutset returns the claim
        # output reliably.
        boltz_regtest.mine_blocks(2)
        time.sleep(1)
        confirmed = _scantxout_confirmed_sats(boltz_regtest, merchant_address)

        target = int(sweep_row["target_onchain_amount_sats"])
        # Claim miner fee shows up here; ±300 sats tolerance matches the
        # existing customer-swap e2e for the same reason.
        assert abs(confirmed - target) <= 300, (
            f"merchant address {merchant_address} received {confirmed} sat, "
            f"expected ~{target} (claim_txid={sweep_row['claim_txid']})"
        )

        # --- Verify a quote-history row was persisted ----------------------
        # The sweep gate stores every quote it observes, so an operator can
        # diagnose why a sweep did or didn't fire.
        with sqlite3.connect(str(payserver.data_dir / "cashupay.sqlite")) as conn:
            conn.row_factory = sqlite3.Row
            rows = conn.execute(
                "SELECT * FROM swap_quote_history WHERE store_id = ?",
                (payserver.store_id,),
            ).fetchall()
            assert len(rows) >= 1, (
                "expected at least one row in swap_quote_history after a sweep run"
            )
            # At least one of the observed quotes must have satisfied the
            # cap (otherwise the sweep wouldn't have run at all).
            assert any(r["met_threshold"] == 1 for r in rows), (
                "no quote in history was marked as meeting the percent cap"
            )

    finally:
        # Reverse-order teardown. Each helper is tolerant of None / already-stopped.
        if payserver is not None:
            stop_sweep_payserver(payserver)
        if mint is not None:
            stop_mint(mint)
        if lnd_mint is not None:
            stop_lnd(lnd_mint)
        if electrum is not None:
            stop_electrum(electrum)
        if fulcrum is not None:
            stop_fulcrum(fulcrum)
