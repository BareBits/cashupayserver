"""Regression guard: the CLINK noffer receipt watcher draws its Nostr
subscription id from the browser CSPRNG, not Math.random().

The sub id is generated inside an inline IIFE in payment.php's
startNofferReceiptWatch(); that function is emitted into every payment page's
<script> block (it just early-returns when the invoice has no noffer rail), so
the served HTML is a faithful place to assert the hardened generator shipped.

Why a static guard rather than a behavioural e2e: exercising the actual sub id
requires a live Nostr relay + a noffer invoice round trip, which this suite
does not stand up. The generator's functional correctness (valid 12-hex id via
crypto.getRandomValues) is covered separately; here we only lock in that the
weak-RNG form cannot creep back.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver

INVOICE_AMOUNT_SAT = "1500"


def _payment_html(session: requests.Session, base_url: str, invoice_id: str) -> str:
    r = session.get(f"{base_url}/payment.php", params={"id": invoice_id}, timeout=15)
    r.raise_for_status()
    return r.text


def test_payment_subid_uses_csprng(configured: ConfiguredPayserver) -> None:
    base_url = configured.handle.url

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=INVOICE_AMOUNT_SAT, currency="sat"
    )
    html = _payment_html(configured.admin.s, base_url, invoice["id"])

    # The watcher function (and therefore the sub-id generator) must be present.
    assert "startNofferReceiptWatch" in html, "noffer watcher JS missing from payment page"

    # Hardened generator: the CSPRNG path must be present.
    assert "getRandomValues" in html, (
        "payment page sub-id should use crypto.getRandomValues"
    )

    # The old weak-RNG sub id must be gone. We match the specific historical
    # form so the legitimate Math.random fallback (only reached when no CSPRNG
    # exists) does not trip the guard.
    assert "Math.random().toString(36).slice(2, 10)" not in html, (
        "weak Math.random sub-id reintroduced on the payment page"
    )
