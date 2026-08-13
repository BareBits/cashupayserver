"""Greenfield create-invoice amount validation: negatives, garbage, and
non-scalar amounts must be rejected with a clean validation-error 400 —
never coerced into a zero/negative invoice or a raw PHP fatal (the fiat
path used to throw an uncaught bcmath ValueError on non-numeric input)."""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver


def _create(configured: ConfiguredPayserver, body: dict) -> requests.Response:
    return requests.post(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/invoices",
        headers={"Authorization": f"token {configured.api_token}"},
        json=body,
        timeout=10,
    )


def test_valid_sat_amount_still_creates(configured: ConfiguredPayserver) -> None:
    r = _create(configured, {"amount": "21", "currency": "sat"})
    assert r.status_code == 200, r.text
    assert r.json()["amount"] == "21"


def test_negative_amount_rejected(configured: ConfiguredPayserver) -> None:
    for amount in (-5, "-5"):
        r = _create(configured, {"amount": amount, "currency": "sat"})
        assert r.status_code == 400, r.text
        assert r.json()["code"] == "validation-error"


def test_non_numeric_amount_rejected(configured: ConfiguredPayserver) -> None:
    # "abc" with a fiat currency used to reach bcdiv() and die with an
    # uncaught ValueError; with sat it used to coerce to a 0-sat invoice.
    for currency in ("sat", "USD"):
        r = _create(configured, {"amount": "abc", "currency": currency})
        assert r.status_code == 400, r.text
        assert r.json()["code"] == "validation-error"


def test_zero_and_fractional_sats_rejected(configured: ConfiguredPayserver) -> None:
    for amount in (0, "0", "5.7"):
        r = _create(configured, {"amount": amount, "currency": "sat"})
        assert r.status_code == 400, r.text
        assert r.json()["code"] == "validation-error"


def test_non_scalar_amount_rejected(configured: ConfiguredPayserver) -> None:
    for amount in (True, [], {"v": 5}):
        r = _create(configured, {"amount": amount, "currency": "sat"})
        assert r.status_code == 400, r.text
        assert r.json()["code"] == "validation-error"


def test_non_string_currency_rejected(configured: ConfiguredPayserver) -> None:
    r = _create(configured, {"amount": "21", "currency": ["sat"]})
    assert r.status_code == 400, r.text
    assert r.json()["code"] == "validation-error"
