"""Minimal pure-Python nostr crypto for in-rig fixtures (test-only).

The e2e rig needs a fake NWC wallet service (NIP-47 counterparty for
cashupayserver's NwcClient), which must Schnorr-sign nostr events and speak
NIP-04. The test venv has no secp256k1 package and the rig's only other
nostr-capable component (the Electrum CLINK plugin) is a heavyweight
AppImage — so this module implements just enough itself:

  * secp256k1 point arithmetic (naive, fine at test scale)
  * BIP-340 Schnorr signing (transcribed from the BIP-340 reference code)
  * NIP-01 event id computation + signing
  * NIP-04 encrypt/decrypt (ECDH X coordinate as AES-256-CBC key, via the
    `cryptography` package already in requirements.txt), matching
    swentel/nostr-php's Nip04 that the PHP client uses

Never use this for real funds — constant-time properties are absent by
design; it exists to make deterministic test rigs, not to hold money.
"""
from __future__ import annotations

import hashlib
import json
import os
import time
from typing import Any

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives import padding
import base64

# ---------------------------------------------------------------------------
# secp256k1 field/point arithmetic (BIP-340 reference style)
# ---------------------------------------------------------------------------
P = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F
N = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
G = (
    0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798,
    0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8,
)

Point = tuple[int, int] | None


def _add(p1: Point, p2: Point) -> Point:
    if p1 is None:
        return p2
    if p2 is None:
        return p1
    x1, y1 = p1
    x2, y2 = p2
    if x1 == x2 and (y1 + y2) % P == 0:
        return None
    if p1 == p2:
        lam = (3 * x1 * x1 * pow(2 * y1, P - 2, P)) % P
    else:
        lam = ((y2 - y1) * pow(x2 - x1, P - 2, P)) % P
    x3 = (lam * lam - x1 - x2) % P
    return (x3, (lam * (x1 - x3) - y1) % P)


def _mul(point: Point, scalar: int) -> Point:
    result: Point = None
    addend = point
    while scalar:
        if scalar & 1:
            result = _add(result, addend)
        addend = _add(addend, addend)
        scalar >>= 1
    return result


def _lift_x(x: int) -> Point:
    if x >= P:
        return None
    y_sq = (pow(x, 3, P) + 7) % P
    y = pow(y_sq, (P + 1) // 4, P)
    if pow(y, 2, P) != y_sq:
        return None
    return (x, y if y % 2 == 0 else P - y)


def _tagged_hash(tag: str, msg: bytes) -> bytes:
    tag_hash = hashlib.sha256(tag.encode()).digest()
    return hashlib.sha256(tag_hash + tag_hash + msg).digest()


def generate_privkey() -> str:
    while True:
        sk = int.from_bytes(os.urandom(32), "big")
        if 1 <= sk < N:
            return f"{sk:064x}"


def pubkey_xonly(privkey_hex: str) -> str:
    """The x-only (nostr) public key for a private key, hex."""
    point = _mul(G, int(privkey_hex, 16))
    assert point is not None
    return f"{point[0]:064x}"


def schnorr_sign(msg32: bytes, privkey_hex: str) -> str:
    """BIP-340 signature (hex) over a 32-byte message."""
    d0 = int(privkey_hex, 16)
    if not (1 <= d0 < N):
        raise ValueError("private key out of range")
    point = _mul(G, d0)
    assert point is not None
    d = d0 if point[1] % 2 == 0 else N - d0
    aux = os.urandom(32)
    t = (d ^ int.from_bytes(_tagged_hash("BIP0340/aux", aux), "big")).to_bytes(32, "big")
    k0 = (
        int.from_bytes(
            _tagged_hash("BIP0340/nonce", t + point[0].to_bytes(32, "big") + msg32), "big"
        )
        % N
    )
    if k0 == 0:
        raise RuntimeError("nonce is zero")
    r_point = _mul(G, k0)
    assert r_point is not None
    k = k0 if r_point[1] % 2 == 0 else N - k0
    e = (
        int.from_bytes(
            _tagged_hash(
                "BIP0340/challenge",
                r_point[0].to_bytes(32, "big") + point[0].to_bytes(32, "big") + msg32,
            ),
            "big",
        )
        % N
    )
    sig = r_point[0].to_bytes(32, "big") + ((k + e * d) % N).to_bytes(32, "big")
    return sig.hex()


# ---------------------------------------------------------------------------
# NIP-01 events
# ---------------------------------------------------------------------------
def event_id(pubkey: str, created_at: int, kind: int, tags: list, content: str) -> str:
    payload = json.dumps(
        [0, pubkey, created_at, kind, tags, content],
        separators=(",", ":"),
        ensure_ascii=False,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def sign_event(privkey_hex: str, kind: int, tags: list, content: str,
               created_at: int | None = None) -> dict[str, Any]:
    """Build a signed nostr event dict."""
    pubkey = pubkey_xonly(privkey_hex)
    created = created_at if created_at is not None else int(time.time())
    eid = event_id(pubkey, created, kind, tags, content)
    return {
        "id": eid,
        "pubkey": pubkey,
        "created_at": created,
        "kind": kind,
        "tags": tags,
        "content": content,
        "sig": schnorr_sign(bytes.fromhex(eid), privkey_hex),
    }


# ---------------------------------------------------------------------------
# NIP-04
# ---------------------------------------------------------------------------
def _nip04_shared_key(privkey_hex: str, pubkey_hex: str) -> bytes:
    """ECDH X coordinate — the raw AES key per NIP-04 (no hashing)."""
    point = _lift_x(int(pubkey_hex, 16))
    if point is None:
        raise ValueError("invalid public key")
    shared = _mul(point, int(privkey_hex, 16))
    assert shared is not None
    return shared[0].to_bytes(32, "big")


def nip04_encrypt(plaintext: str, privkey_hex: str, pubkey_hex: str) -> str:
    key = _nip04_shared_key(privkey_hex, pubkey_hex)
    iv = os.urandom(16)
    padder = padding.PKCS7(128).padder()
    padded = padder.update(plaintext.encode("utf-8")) + padder.finalize()
    enc = Cipher(algorithms.AES(key), modes.CBC(iv)).encryptor()
    ct = enc.update(padded) + enc.finalize()
    return base64.b64encode(ct).decode() + "?iv=" + base64.b64encode(iv).decode()


def nip04_decrypt(payload: str, privkey_hex: str, pubkey_hex: str) -> str:
    key = _nip04_shared_key(privkey_hex, pubkey_hex)
    ct_b64, iv_b64 = payload.split("?iv=", 1)
    ct = base64.b64decode(ct_b64)
    iv = base64.b64decode(iv_b64)
    dec = Cipher(algorithms.AES(key), modes.CBC(iv)).decryptor()
    padded = dec.update(ct) + dec.finalize()
    unpadder = padding.PKCS7(128).unpadder()
    return (unpadder.update(padded) + unpadder.finalize()).decode("utf-8")
