# Submarine-swap end-to-end test

`swap_e2e.py` exercises the full LN → on-chain submarine-swap path against a
**real** Boltz backend running in regtest mode. It validates everything that
the in-tree mock-provider PHP test cannot: real Schnorr signing on real
Taproot UTXOs, real BIP341 sighash, real script-path witness assembly, and
real Bitcoin script-validation by the backend's bitcoind.

## Prerequisites

1. **Docker + Docker Compose v2** installed (`sudo apt install docker.io docker-compose-v2`).
2. **Boltz regtest stack** cloned next to the project:
   ```bash
   git clone https://github.com/BoltzExchange/regtest /tmp/boltz-regtest
   ```
3. **Port override** (only needed if your host has services on the default
   Boltz regtest ports — postgres :5432, bitcoind :18443, lnd :10009, etc.).
   The bundled `tests/scripts/swap_e2e_compose.override.yml` strips host
   port bindings for everything except the Boltz API. Copy it into the
   regtest checkout as `docker-compose.override.yml`:
   ```bash
   cp tests/scripts/swap_e2e_compose.override.yml /tmp/boltz-regtest/docker-compose.override.yml
   ```
4. **Bring up the stack** from the regtest checkout:
   ```bash
   cd /tmp/boltz-regtest && sudo bash start.sh
   ```
   Wait for `boltz-backend-nginx` to become healthy (≈ 60s). Verify with
   `curl http://localhost:29001/v2/swap/reverse` — should return JSON.

## Running

From the project root:

```bash
python3 tests/scripts/swap_e2e.py
```

Exit code:
- `0` — swap completed; invoice flipped to Settled and claim_txid recorded.
- `1` — invoice creation failed (provider unreachable / amount out of range /
  setup issue).
- `2` — lifecycle did not reach a terminal positive state within the timeout.

The script will:

1. Spin up a fresh SQLite-backed payserver under `/tmp/swap-e2e-…/`.
2. Seed a store with the canonical regtest tpub, enable swaps, point them
   at `http://localhost:29001`.
3. Create a 60,000-sat invoice via the public BTCPay-compatible API.
4. Pay the BOLT11 from Boltz's LND `node-1` (the "customer wallet"). This
   call blocks until Boltz settles the held invoice — the timeout is
   expected and harmless.
5. Loop `cron.php` while mining a regtest block per tick.
6. Print the final `swap_attempts` row + invoice status.

Each run leaves its workdir on disk for postmortem. Clean up with
`rm -rf /tmp/swap-e2e-*` when done.

## What this proves

| Layer | What real Boltz validates |
|-------|---------------------------|
| `Schnorr` (BIP340) | bitcoind verifies the signature on the claim tx witness. A bad Schnorr impl → tx rejected. |
| `Taproot` (BIP341) | bitcoind reconstructs the output key from internal_key + script tree using our control block. A bad control block → tx rejected. |
| `KeyAgg` (BIP327) | The internal key we compute must match the one Boltz used when funding the lockup. If KeyAgg is wrong, the address mismatch is caught at invoice-creation time. |
| Witness layout | Our `[sig, preimage, script, control_block]` ordering must be exactly what the Boltz tapscript expects. |
| Sighash | The BIP341 sighash we sign over must match what bitcoind recomputes. |
| State machine | Provider status strings (`transaction.mempool`, `invoice.settled`, etc.) must drive cashupayserver's invoice state transitions correctly. |

What it does not cover: cooperative musig2 claim (deferred to a follow-up;
see plan), the reverse direction (on-chain → LN), and provider authentication
flows (regtest backend doesn't require API keys).
