"""End-to-end tests for LNURL direct-receive routing.

Exercises the full stack — cashupayserver, the LUD-21 mock LNURL host
(backed by lnd_payer), the customer-side LND (lnd_mint) — to verify:

1. **Happy path**: when auto_melt_enabled=1 and the configured LN address
   advertises a LUD-21 verify URL, a new invoice routes to the lnaddress
   rail. The customer pays the LNURL-issued BOLT11 (which lands at the LN
   address host, not at our mint). Cron polls the verify URL, sees
   settled=true with a preimage, and marks the invoice Settled with
   settled_rail='lnaddress'.

2. **Fee redirect**: when a fee is owed >= the next invoice amount (seeded
   by inserting a settled-revenue invoice directly), the fee-redirect path
   supersedes the old mint-override gate — the whole invoice is pointed at
   the dev fee LNURL (resolved through the mock host). The invoice rides the
   lnaddress rail tagged with fee_redirect_note; paying it settles the
   invoice and records a melts credit (via='redirect') instead of moving
   funds to the merchant. The pure-PHP unit tests cover the decision truth
   table + accounting; this confirms the path is wired end-to-end.

3. **LUD-21 fallback**: when the LN address host doesn't advertise a
   verify URL, save_auto_melt records stores.lnurl_supports_verify=0 and
   the runtime probe falls back transparently. New invoices land on the
   mint rail with no override reason (the gate didn't fire — we just
   couldn't use LNURL at all).
"""
from __future__ import annotations

import base64
import sqlite3
import time
import uuid
from pathlib import Path
from typing import Iterator

import pytest

from conftest import (
    ConfiguredPayserver,
    DEFAULT_ADMIN_PASSWORD,
    SESSION_TMP,
    _configure,
)
from fixtures.api_client import AdminClient
from fixtures.lnd import LndHandle
from fixtures.lnurlp_server import (
    LnurlpServer,
    start_lnurlp_server,
    stop_lnurlp_server,
)
from fixtures.nutshell import MintHandle
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver

LNURL_ADDRESS = "merchant@example.test"
INVOICE_AMOUNT_SAT = 5_000


# ---------------------------------------------------------------------------
# Helpers shared across tests
# ---------------------------------------------------------------------------


def _enable_auto_melt(
    configured: ConfiguredPayserver,
    address: str = LNURL_ADDRESS,
    threshold_sat: int = 100,
    enabled: str = "1",
) -> dict:
    """Hit the admin save_auto_melt endpoint, mimicking the dashboard UI.
    Returns the response (includes an ordered `addresses` list, each carrying
    the per-address lud21Support from the LUD-21 probe the handler runs
    synchronously). Posts the legacy single `address` field, which the handler
    still accepts and stores as a one-entry fallback chain."""
    return configured.admin._post_action(
        "save_auto_melt",
        store_id=configured.store_id,
        address=address,
        enabled=enabled,
        threshold=str(threshold_sat),
    )


def _poll_invoice_until(
    configured: ConfiguredPayserver, invoice_id: str, status: str, timeout_s: float = 30
) -> dict:
    """Poll the invoice via Greenfield until status matches or the deadline
    expires. Returns the last observed payload to make failure diagnostics
    easier — the test that called this should assert on the returned status."""
    deadline = time.monotonic() + timeout_s
    last: dict | None = None
    while time.monotonic() < deadline:
        last = configured.greenfield.get_invoice(configured.store_id, invoice_id)
        if last.get("status") == status:
            return last
        time.sleep(0.5)
    raise AssertionError(
        f"invoice {invoice_id} did not reach {status} within {timeout_s}s; "
        f"last={last}"
    )


def _read_invoice_row(payserver: PayserverHandle, invoice_id: str) -> dict:
    """Return the raw invoices row including columns the JSON API doesn't
    expose (payment_rail, settled_rail, lnurl_verify_url, lnurl_preimage,
    lnurl_override_reason). Necessary for testing routing-decision side-
    effects that aren't visible in the API surface."""
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT * FROM invoices WHERE id = ?", (invoice_id,)
        ).fetchone()
        if row is None:
            raise AssertionError(f"no invoices row for id={invoice_id}")
        return dict(row)


def _read_store_row(payserver: PayserverHandle, store_id: str) -> dict:
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT * FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
        if row is None:
            raise AssertionError(f"no stores row for id={store_id}")
        return dict(row)


def _primary_lud21(save_result: dict):
    """LUD-21 support for the highest-priority address in a save_auto_melt
    response. The response now returns an ordered `addresses` list (each with
    a per-address lud21Support) instead of a single lnurl_supports_verify."""
    addresses = save_result.get("addresses") or []
    if not addresses:
        return None
    return addresses[0].get("lud21Support")


def _primary_ln_address_support(payserver: PayserverHandle, store_id: str):
    """Cached LUD-21 support for the store's primary (position 0) Lightning
    address — replaces the old stores.lnurl_supports_verify column."""
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        row = conn.execute(
            "SELECT supports_verify FROM store_ln_addresses "
            "WHERE store_id = ? ORDER BY position ASC LIMIT 1",
            (store_id,),
        ).fetchone()
        return None if row is None else row[0]


def _seed_fee_revenue(payserver: PayserverHandle, store_id: str, sats: int) -> None:
    """Insert a synthetic Settled invoice so DevFee::computeOwed sees revenue
    without us having to actually pay an invoice. Also zeroes
    fee_tracking_start_at so the synthetic row falls inside the accounting
    window (computeOwed filters created_at >= start_at).

    Used by the override-gate test to push feesDue above the FORCE threshold
    in a single deterministic step.
    """
    db_path = payserver.data_dir / "cashupay.sqlite"
    now = int(time.time())
    with sqlite3.connect(str(db_path)) as conn:
        conn.execute(
            "INSERT OR REPLACE INTO config (key, value, created_at, updated_at) "
            "VALUES ('fee_tracking_start_at', '0', ?, ?)",
            (now, now),
        )
        conn.execute(
            "INSERT INTO invoices "
            "(id, store_id, status, amount, currency, amount_sats, "
            " created_at, expiration_time, payment_rail, settled_rail, paid_at) "
            "VALUES (?, ?, 'Settled', ?, 'sat', ?, ?, ?, 'lnaddress', 'lnaddress', ?)",
            (
                f"seed_{uuid.uuid4().hex[:8]}",
                store_id,
                str(sats),
                sats,
                now - 60,
                now + 3600,
                now - 60,
            ),
        )
        conn.commit()


def _trigger_cron(payserver: PayserverHandle) -> dict:
    """Hit cron.php and return the parsed JSON body. Tolerates the prefix
    noise some cron tasks emit (e.g. a Donation::send warning), same as the
    auto-melt test does."""
    r = payserver.trigger_cron()
    assert r.status_code == 200, r.text
    body = r.text.strip()
    try:
        return r.json()
    except Exception:
        import json as _json

        idx = body.find("{")
        return _json.loads(body[idx:]) if idx >= 0 else {}


def _poll_payment_page(payserver: PayserverHandle, invoice_id: str) -> dict:
    """Hit payment.php?id=<id>&json=1 — the customer-browser status-poll
    pattern. Dispatches to Invoice::pollSingleLnAddress for lnaddress-rail
    invoices, bypassing the cron's 30s rate-limit. Returns {status, ...}."""
    import requests

    r = requests.get(
        f"{payserver.url}/payment.php",
        params={"id": invoice_id, "json": "1"},
        timeout=15,
    )
    r.raise_for_status()
    return r.json()


# ---------------------------------------------------------------------------
# Happy path: LNURL direct receive with LUD-21 verify URL
# ---------------------------------------------------------------------------


def test_lnurl_direct_receive_happy_path(
    configured_with_lnurlp: ConfiguredPayserver,
    lnd_mint: LndHandle,
    lnurlp_server: LnurlpServer,
) -> None:
    """A LUD-21-enabled LN address receives the customer payment directly;
    the cashupayserver detects settlement via the verify URL."""
    configured = configured_with_lnurlp

    # 1. Save the auto-melt LN address. The save_auto_melt handler probes the
    #    mock LNURL host (which advertises a verify URL via lud21=True), so
    #    lnurl_supports_verify on the response should be 1.
    save_result = _enable_auto_melt(configured)
    assert save_result.get("success") is True, save_result
    assert _primary_lud21(save_result) == 1, (
        f"expected save handler to probe LUD-21 support; got {save_result}"
    )

    # 2. Create the invoice. With auto_melt_enabled=1 + LUD-21 host healthy,
    #    routing should pick the lnaddress rail before reaching mint/swap.
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]

    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), (
        f"expected regtest BOLT11, got {bolt11}"
    )

    # Inspect the raw row — the API doesn't expose payment_rail/verify URL.
    row = _read_invoice_row(configured.handle, invoice_id)
    assert row["payment_rail"] == "lnaddress", (
        f"expected lnaddress rail, got payment_rail={row['payment_rail']!r}"
    )
    assert row["lnurl_verify_url"], "verify URL should be populated"
    assert lnurlp_server.base_url in row["lnurl_verify_url"], (
        f"verify URL should point at the mock host: {row['lnurl_verify_url']}"
    )
    assert row["lnurl_override_reason"] is None, "no override should fire when fees=0"
    # The LN address the bolt11 was fetched from is persisted at creation so
    # the admin invoices view can show where the payment was sent.
    assert row["ln_destination"] == LNURL_ADDRESS, (
        f"ln_destination should record the LN address; got {row['ln_destination']!r}"
    )

    # 3. The customer (lnd_mint, which shares dual channels with lnd_payer)
    #    pays the LNURL-issued BOLT11. lnd_payer is the LN address host —
    #    funds land THERE, never touching the cashupayserver mint.
    pay_result = lnd_mint.pay_invoice_sync(bolt11, timeout=30)
    assert not pay_result.get("payment_error"), f"payment failed: {pay_result}"
    assert pay_result.get("payment_preimage"), f"missing preimage: {pay_result}"

    # 4. Drive single-invoice polling via payment.php — the same path the
    #    customer's browser uses. This dispatches to pollSingleLnAddress
    #    (no rate-limit gate), so each tick actually hits the verify URL
    #    rather than skipping per the cron's 30s minInterval.
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        status_body = _poll_payment_page(configured.handle, invoice_id)
        if status_body.get("status") == "Settled":
            break
        time.sleep(0.5)
    else:
        raise AssertionError(
            f"invoice {invoice_id} did not settle via lnaddress within 30s"
        )

    # 5. Final DB state: settled_rail='lnaddress' + preimage saved.
    final = _read_invoice_row(configured.handle, invoice_id)
    assert final["status"] == "Settled"
    assert final["settled_rail"] == "lnaddress", (
        f"expected settled_rail=lnaddress, got {final['settled_rail']!r}"
    )
    assert final["lnurl_preimage"], (
        "lnurl_preimage should be populated from verify URL response"
    )
    # Preimage is recorded as hex; sanity-check shape (32 bytes → 64 hex chars).
    assert len(final["lnurl_preimage"]) == 64, (
        f"expected 64-char hex preimage, got {final['lnurl_preimage']!r}"
    )

    # 6. The admin invoices view (Greenfield formatForApi) surfaces the LN
    #    address as the destination and the bolt11 as a copy-only lightning
    #    "txid" — neither is a block-chain entity, so both carry the lightning
    #    flag that tells the UI not to render a mempool link.
    api = configured.greenfield.get_invoice(configured.store_id, invoice_id)
    assert api.get("destination") == LNURL_ADDRESS, api
    assert api.get("destinationIsLightning") is True, api
    assert api.get("txid") == bolt11, api
    assert api.get("txidIsLightning") is True, api


# ---------------------------------------------------------------------------
# Fee redirect: fee owed >= invoice → whole payment routed to the fee
# ---------------------------------------------------------------------------


def _read_redirect_melt(payserver: PayserverHandle, invoice_id: str) -> dict | None:
    """Return the melts row recorded for a settled fee-redirect invoice
    (via='redirect'), or None if settlement hasn't credited it yet."""
    db_path = payserver.data_dir / "cashupay.sqlite"
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        row = conn.execute(
            "SELECT * FROM melts WHERE invoice_id = ? AND via = 'redirect'",
            (invoice_id,),
        ).fetchone()
        return None if row is None else dict(row)


def test_fee_redirect_lightning_supersedes_mint_override(
    configured_with_lnurlp: ConfiguredPayserver,
    lnd_mint: LndHandle,
    lnurlp_server: LnurlpServer,
) -> None:
    """When a fee is owed in an amount >= the next invoice, the whole invoice
    is redirected to that fee instead of routing through the mint. Here the
    largest owed fee is the dev fee; its LNURL resolves through the mock host,
    so the invoice lands on the lnaddress rail tagged fee_redirect_note=DEV_FEE.
    Paying it settles the invoice and records a via='redirect' melts credit —
    the funds went to the fee payee, not the merchant."""
    configured = configured_with_lnurlp

    save_result = _enable_auto_melt(configured)
    assert save_result.get("success") is True
    assert _primary_lud21(save_result) == 1

    # Seed 1_000_000 sats revenue → dev fee owed = 2% = 20_000 sats, well above
    # the 5_000-sat invoice, so the gate (owed >= invoice) lets the redirect fire.
    _seed_fee_revenue(configured.handle, configured.store_id, sats=1_000_000)

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    invoice_id = invoice["id"]

    # The invoice is now a dev-fee payment on the lnaddress rail.
    row = _read_invoice_row(configured.handle, invoice_id)
    assert row["payment_rail"] == "lnaddress", (
        f"fee redirect should use the lnaddress rail; got {row['payment_rail']!r}"
    )
    assert row["fee_redirect_note"] == "DEV_FEE", (
        f"expected redirect to the dev fee; got {row['fee_redirect_note']!r}"
    )
    assert row["fee_redirect_destination"], "fee destination should be recorded"
    # The fee LNURL is also persisted as the lightning destination so the
    # admin invoices view shows where the redirected payment went.
    assert row["ln_destination"] == row["fee_redirect_destination"], (
        f"fee LNURL should be the ln_destination; got {row['ln_destination']!r}"
    )
    assert row["lnurl_verify_url"], "verify URL needed to detect settlement"
    assert row["lnurl_override_reason"] is None, (
        "redirect path supersedes the override gate; no override reason expected"
    )

    # The API surfaces the badge payload for the admin invoice list.
    assert (invoice.get("feeRedirect") or {}).get("note") == "DEV_FEE", (
        f"create response should carry feeRedirect badge data; got {invoice.get('feeRedirect')!r}"
    )

    bolt11 = (
        invoice.get("checkout", {})
        .get("paymentMethods", {})
        .get("BTC-LightningNetwork", {})
        .get("destination")
    )
    assert bolt11 and bolt11.lower().startswith("lnbcrt"), (
        f"expected the fee LNURL's regtest BOLT11; got {bolt11}"
    )

    # Customer pays the fee LNURL's BOLT11 — funds land at the fee payee
    # (lnd_payer behind the mock host), never at the merchant or our mint.
    pay_result = lnd_mint.pay_invoice_sync(bolt11, timeout=30)
    assert not pay_result.get("payment_error"), f"payment failed: {pay_result}"

    # Poll until settled via the verify URL (same path the customer tab uses).
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if _poll_payment_page(configured.handle, invoice_id).get("status") == "Settled":
            break
        time.sleep(0.5)
    else:
        raise AssertionError(
            f"redirect invoice {invoice_id} did not settle within 30s"
        )

    final = _read_invoice_row(configured.handle, invoice_id)
    assert final["status"] == "Settled"
    assert final["settled_rail"] == "lnaddress"

    # Settlement recorded a fee-paid credit via redirect (not a wallet melt),
    # so DevFee::computeOwed will see the dev fee partially settled.
    melt = _read_redirect_melt(configured.handle, invoice_id)
    assert melt is not None, "expected a via='redirect' melts credit after settlement"
    assert melt["note"] == "DEV_FEE", f"credit should carry the dev fee note; got {melt['note']!r}"
    assert int(melt["amount_sats"]) == INVOICE_AMOUNT_SAT, (
        f"credit amount should equal the invoice sats; got {melt['amount_sats']}"
    )
    assert int(melt["network_fee_sats"]) == 0, "a redirect spends no wallet proofs"

    # The admin invoices view shows the fee LNURL as the destination + the
    # bolt11 as a copy-only lightning "txid", and the badge reports the
    # payment was actually redirected to the fee.
    api = configured.greenfield.get_invoice(configured.store_id, invoice_id)
    assert api.get("destination") == final["fee_redirect_destination"], api
    assert api.get("destinationIsLightning") is True, api
    assert api.get("txid") == bolt11, api
    assert api.get("txidIsLightning") is True, api
    assert (api.get("feeRedirect") or {}).get("settledToFee") is True, api


# ---------------------------------------------------------------------------
# LUD-21 fallback: host without verify URL → routes via mint silently
# ---------------------------------------------------------------------------


@pytest.fixture
def lnurlp_server_no_lud21(lnd_payer: LndHandle) -> Iterator[LnurlpServer]:
    """LNURL mock that does NOT advertise a verify URL. Used to exercise the
    silent fallback path in Invoice::create — the LNURL probe should fail
    (no LUD-21 verify field) and routing should fall through to mint/swap."""
    s = start_lnurlp_server(lnd_payer, lud21=False)
    yield s
    stop_lnurlp_server(s)


@pytest.fixture
def payserver_no_lud21(lnurlp_server_no_lud21: LnurlpServer) -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-nolud21-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(
        workdir,
        extra_env={"CASHU_LNURL_URL_TEMPLATE": lnurlp_server_no_lud21.url_template},
    )
    yield handle
    stop_payserver(handle)


@pytest.fixture
def configured_no_lud21(
    payserver_no_lud21: PayserverHandle,
    mint: MintHandle,
    backup_mint: MintHandle,
) -> ConfiguredPayserver:
    """Same as `configured` but the LNURL host doesn't support LUD-21."""
    return _configure(payserver_no_lud21, mint, backup_mint)


def test_lnurl_lud21_missing_falls_back_to_mint(
    configured_no_lud21: ConfiguredPayserver,
    lnurlp_server_no_lud21: LnurlpServer,
) -> None:
    """Without LUD-21, the LNURL probe rejects and the invoice routes via
    the mint. The save_auto_melt response should reflect lnurl_supports_verify=0,
    and the stores table should match."""
    configured = configured_no_lud21

    save_result = _enable_auto_melt(configured)
    assert save_result.get("success") is True, save_result
    assert _primary_lud21(save_result) == 0, (
        "host without verify URL should report unsupported; "
        f"got {save_result}"
    )

    # The per-address cache mirrors the response.
    assert _primary_ln_address_support(configured.handle, configured.store_id) == 0

    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(INVOICE_AMOUNT_SAT), currency="sat"
    )
    row = _read_invoice_row(configured.handle, invoice["id"])
    assert row["payment_rail"] == "mint", (
        f"LUD-21 missing → expected mint fallback; got {row['payment_rail']!r}"
    )
    # No override reason — the gate didn't fire, we just couldn't use LNURL.
    assert row["lnurl_override_reason"] is None, (
        f"no override expected; got {row['lnurl_override_reason']!r}"
    )
    assert row["lnurl_verify_url"] is None
