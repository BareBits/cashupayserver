"""Exchange-rate cache rows are read back from storage and must be treated as
data: a row whose rate is a string, an array, zero, or absurdly large — or
whose timestamp is not an integer — must be IGNORED by pricing, never fed to
bcmath (ValueError), never used to mint a 0-sat invoice, and never surfaced
as a PHP fatal. Fiat invoice creation under a poisoned cache therefore ends
in one of exactly two clean outcomes: a priced invoice (a live provider or a
sane last-good rate stepped in) or a JSON error — and once a sane rate is
seeded again, pricing uses it exactly.

The scientific-notation edge of the sane band (rates below 1e-4, which PHP
would stringify as 1.0E-5) cannot be reached here: at those rates one cent is
hundreds of BTC, beyond the mint's quote cap. tests/php/test_rates_guard.php
covers that edge through ExchangeRates::fmt()."""
from __future__ import annotations

import json
import time

import requests

from conftest import ConfiguredPayserver

BTC_USD = 100_000.0


def _config_set(cfg: ConfiguredPayserver, key: str, payload: str) -> None:
    now = int(time.time())
    with cfg.handle.db() as db:
        db.execute(
            "INSERT INTO config (key, value, created_at, updated_at) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            (key, payload, now, now),
        )


def _seed(cfg: ConfiguredPayserver, rate, timestamp=None) -> None:
    payload = json.dumps({"rate": rate, "timestamp": int(time.time()) if timestamp is None else timestamp, "provider": "e2e"})
    for key in ("rate_usd", "rate_lastgood_usd"):
        _config_set(cfg, key, payload)


def _create_usd(cfg: ConfiguredPayserver, amount: str = "10.00") -> requests.Response:
    return requests.post(
        f"{cfg.handle.url}/api/v1/stores/{cfg.store_id}/invoices",
        headers={"Authorization": f"token {cfg.api_token}"},
        json={"amount": amount, "currency": "USD"},
        timeout=60,
    )


def _health(cfg: ConfiguredPayserver) -> None:
    """The server still answers an authenticated API read after the poisoned
    pricing attempt (no wedged worker, no fatal)."""
    assert cfg.greenfield.get_store(cfg.store_id)["id"] == cfg.store_id


def test_poisoned_rate_cache_is_ignored_not_fatal(shared_configured: ConfiguredPayserver) -> None:
    cfg = shared_configured
    poisons = [
        ("abc", None),                      # string rate
        ({"nested": 1}, None),              # object rate
        (0, None),                          # zero -> would price at 0 sats / divide by zero
        (-5, None),                         # negative
        (1e20, None),                       # absurd -> PHP would spell 1.0E+20, bcmath ValueError
        ("1e5", None),                      # exponent string
        (BTC_USD, "yesterday"),             # sane rate, garbage timestamp
    ]
    try:
        for rate, ts in poisons:
            _seed(cfg, rate, ts)
            r = _create_usd(cfg)
            assert r.headers.get("content-type", "").startswith("application/json"), (rate, r.status_code, r.text[:300])
            body = r.json()
            assert "Fatal error" not in r.text and "ValueError" not in r.text and "TypeError" not in r.text, (rate, r.text[:300])
            if r.status_code == 200:
                # A live provider (or an older sane last-good) stepped in: the
                # invoice must be priced sanely, never at 0 sats.
                sats = _invoice_sats(cfg, body["id"])
                assert sats > 0, (rate, body)
            else:
                assert r.status_code in (400, 500, 503), (rate, r.status_code, r.text[:300])
                assert body.get("error") or body.get("message"), body
            _health(cfg)
    finally:
        _seed(cfg, BTC_USD)

    # A sane cache row prices exactly: $10 at $100,000/BTC = 10,000 sats.
    r = _create_usd(cfg)
    assert r.status_code == 200, r.text
    assert _invoice_sats(cfg, r.json()["id"]) == 10_000


def _invoice_sats(cfg: ConfiguredPayserver, invoice_id: str) -> int:
    with cfg.handle.db() as db:
        row = db.execute("SELECT amount_sats FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
    assert row is not None, invoice_id
    return int(row[0])
