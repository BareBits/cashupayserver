"""On-chain Bitcoin test helpers.

Derives a watch-only xpub from the existing bitcoind regtest wallet so tests
can register it with cashupayserver, then drives bitcoind directly to fund
the derived addresses.
"""
from __future__ import annotations

import sqlite3
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

from .bitcoind import BitcoindHandle


def _base58_decode(s: str) -> bytes:
    alphabet = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"
    num = 0
    for ch in s:
        num = num * 58 + alphabet.index(ch)
    body = num.to_bytes((num.bit_length() + 7) // 8, "big") if num else b""
    leading_ones = 0
    for ch in s:
        if ch != "1":
            break
        leading_ones += 1
    return b"\x00" * leading_ones + body


def _base58_encode(b: bytes) -> str:
    import hashlib
    alphabet = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"
    num = int.from_bytes(b, "big") if b else 0
    out = ""
    while num > 0:
        num, rem = divmod(num, 58)
        out = alphabet[rem] + out
    pad = 0
    for byte in b:
        if byte != 0:
            break
        pad += 1
    return "1" * pad + out


def _base58check(payload: bytes) -> str:
    import hashlib
    checksum = hashlib.sha256(hashlib.sha256(payload).digest()).digest()[:4]
    return _base58_encode(payload + checksum)


def _xpub_to_tpub(xpub: str) -> str:
    """Swap version bytes from xpub (0488B21E) to tpub (043587CF) and re-checksum.
    The underlying key material is identical; only the prefix differs."""
    decoded = _base58_decode(xpub)
    if len(decoded) < 4 or decoded[:4].hex().lower() != "0488b21e":
        raise ValueError(f"expected mainnet xpub, got version {decoded[:4].hex()}")
    body = decoded[:-4]  # strip checksum
    new_body = bytes.fromhex("043587CF") + body[4:]
    return _base58check(new_body)


# Standard BIP32 mainnet xpub from a public test vector. Re-encoded with tpub
# version bytes so bitcoind regtest accepts it and our PHP derives identical
# addresses (the key material is the same regardless of prefix).
_MAINNET_VECTOR_XPUB = (
    "xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1ic"
    "qYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz"
)
TEST_TPUB = _xpub_to_tpub(_MAINNET_VECTOR_XPUB)


@dataclass
class OnchainContext:
    """Per-test on-chain wiring: a tpub registered on the payserver store,
    a watch-only side wallet on bitcoind, and helpers to drive payments."""
    tpub: str
    bitcoind: BitcoindHandle
    watch_wallet_url: str
    watch_wallet_name: str

    def fund_address(self, address: str, amount_sat: int) -> str:
        """Send `amount_sat` to `address`, return the txid (mempool, unconfirmed)."""
        btc = f"{amount_sat / 100_000_000:.8f}"
        return self.bitcoind.send_to_address(address, float(btc))

    def confirm(self, blocks: int = 1) -> None:
        self.bitcoind.mine(blocks)

    def bitcoind_rpc_url(self) -> str:
        return self.bitcoind.rpc_url


def _create_watch_wallet(bitcoind: BitcoindHandle, name: str) -> str:
    """Bitcoin Core 28 refuses to import watch-only descriptors into wallets
    that hold private keys. Provision a fresh, dedicated watch-only descriptor
    wallet so each test starts with a clean view (no payments leaked from
    previous tests to the same derivation indexes), and return the per-wallet
    RPC URL.

    createwallet args: name, disable_private_keys, blank, passphrase,
    avoid_reuse, descriptors, load_on_startup
    """
    bitcoind.rpc("createwallet", name, True, False, "", False, True, False)
    base = bitcoind.rpc_url.rstrip("/")
    return f"{base}/wallet/{name}"


def make_onchain_context(bitcoind: BitcoindHandle, wallet_name: str) -> OnchainContext:
    """Build an OnchainContext for use in tests.

    Provisions a fresh watch-only wallet on bitcoind for cashupayserver to use
    as its BlockchainProvider, then wires the well-known TEST_TPUB to it.
    Test fixtures should pass a unique wallet_name per test so address
    derivation states don't leak across tests (all tests start with
    onchain_next_index=0, which would otherwise cause cross-test collisions).
    """
    watch_url = _create_watch_wallet(bitcoind, wallet_name)
    return OnchainContext(
        tpub=TEST_TPUB, bitcoind=bitcoind, watch_wallet_url=watch_url, watch_wallet_name=wallet_name,
    )


def configure_store_for_onchain(
    db_path: Path,
    store_id: str,
    *,
    xpub: str,
    network: str = "regtest",
    address_type: str = "P2WPKH",
    min_confs: int = 1,
    confirm_timeout_sec: int = 86400,
    provider_url: str | None = None,
    start_index: int = 0,
) -> None:
    """Direct DB write: sets a store's on-chain config without going through
    the wizard/admin. Useful for tests that focus on the polling and
    settlement logic rather than the UI.

    `start_index` lets each test pick a unique derivation offset so the
    addresses don't collide with sibling tests (which all share TEST_TPUB).
    """
    import hashlib, time as _time
    conn = sqlite3.connect(db_path, isolation_level=None)
    try:
        conn.execute(
            """
            UPDATE stores
               SET onchain_xpub = ?,
                   onchain_network = ?,
                   onchain_address_type = ?,
                   onchain_min_confs = ?,
                   onchain_confirm_timeout_sec = ?,
                   onchain_provider = 'bitcoind-rpc',
                   onchain_provider_url = ?,
                   onchain_next_index = ?
             WHERE id = ?
            """,
            (xpub, network, address_type, min_confs, confirm_timeout_sec,
             provider_url, start_index, store_id),
        )
        # The runtime allocator keys off onchain_xpub_state, not the column on
        # stores. Seed the per-xpub counter so each test gets fresh, non-
        # colliding addresses regardless of which other test ran first.
        xpub_hash = hashlib.sha256(xpub.encode()).hexdigest()
        now = int(_time.time())
        conn.execute(
            "INSERT INTO onchain_xpub_state (xpub_hash, next_index, updated_at) "
            "VALUES (?, ?, ?) ON CONFLICT(xpub_hash) DO UPDATE SET next_index = excluded.next_index, "
            "updated_at = excluded.updated_at",
            (xpub_hash, start_index, now),
        )
    finally:
        conn.close()


def configure_store_for_static_onchain(
    db_path: Path,
    store_id: str,
    *,
    static_address: str,
    network: str = "regtest",
    tweak_range: int = 1000,
    min_confs: int = 0,
    confirm_timeout_sec: int = 86400,
    provider_url: str | None = None,
) -> None:
    """Direct DB write: put a store into static-address mode.

    The xpub field is cleared because the runtime enforces "one OR the other"
    on save_onchain; tests that drive the DB directly should match that
    invariant so subsequent UI calls don't get into surprising states.
    """
    conn = sqlite3.connect(db_path, isolation_level=None)
    try:
        conn.execute(
            """
            UPDATE stores
               SET onchain_address_mode = 'static',
                   onchain_static_address = ?,
                   onchain_static_tweak_range = ?,
                   onchain_xpub = NULL,
                   onchain_network = ?,
                   onchain_min_confs = ?,
                   onchain_confirm_timeout_sec = ?,
                   onchain_provider = 'bitcoind-rpc',
                   onchain_provider_url = ?
             WHERE id = ?
            """,
            (static_address, tweak_range, network, min_confs, confirm_timeout_sec,
             provider_url, store_id),
        )
    finally:
        conn.close()


def derive_address_in_bitcoind(bitcoind: BitcoindHandle, xpub: str, address_type: str, index: int) -> str:
    """Reference derivation via `bitcoin-cli deriveaddresses` for parity checks."""
    # Build a watch-only descriptor matching cashupayserver's m/0/{index}.
    inner = "wpkh" if address_type == "P2WPKH" else "sh(wpkh"
    closing = ")" if address_type == "P2WPKH" else "))"
    raw = f"{inner}({xpub}/0/{index}{closing}"
    info = bitcoind.rpc("getdescriptorinfo", raw)
    return bitcoind.rpc("deriveaddresses", info["descriptor"])[0]
