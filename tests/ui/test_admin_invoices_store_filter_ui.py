"""Admin invoices store filter.

The invoices screen has a store dropdown next to the status filter. It defaults
to the currently-selected store, offers an "All stores" option that drops the
store_id filter so every store's invoices show, and — when a concrete store is
picked — keeps the global header store selector in sync.

This drives the real loadInvoices() fetch + backend filtering with invoices that
genuinely belong to two different stores, so it covers the whole path rather
than just renderInvoicesTable() with mock data.
"""
from __future__ import annotations

import uuid

import pytest

from conftest import ConfiguredPayserver
from fixtures.api_client import GreenfieldClient
from fixtures.nutshell import MintHandle

pytestmark = pytest.mark.ui


def _login(page, configured: ConfiguredPayserver) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")


def _api_key_token(resp: dict) -> str:
    token = resp.get("key") or resp.get("apiKey") or resp.get("token")
    assert token, f"expected api key in response, got {resp}"
    return token


def test_invoices_store_filter_all_and_per_store(
    shared_configured: ConfiguredPayserver, mint: MintHandle, page
) -> None:
    c = shared_configured
    store_a = c.store_id

    # One invoice in this test's own store (A).
    c.greenfield.create_invoice(
        store_a, amount="1000", currency="sat", metadata={"itemDesc": "ALPHA-INV"}
    )

    # Create a second store (B) sharing the same mint, with its own API key and
    # invoice, so the admin DB holds invoices belonging to two different stores.
    # Unique name: the shared server already carries the base wizard store plus
    # one store per test, so a fixed "Beta Store" could collide.
    beta_name = f"Beta Store {uuid.uuid4().hex[:6]}"
    c.admin._post_action(
        "create_store", name=beta_name, mint_url=mint.url, mint_unit="sat"
    )
    beta = next(s for s in c.admin.list_stores() if s["name"] == beta_name)
    store_b = beta["id"]
    beta_token = _api_key_token(c.admin.create_api_key(store_b, label=f"e2e-{beta_name}"))
    GreenfieldClient(c.handle.url, beta_token).create_invoice(
        store_b, amount="2000", currency="sat", metadata={"itemDesc": "BETA-INV"}
    )

    _login(page, shared_configured)

    # Navigate to the invoices view and wait for its store filter to populate.
    # The shared server holds other tests' stores too, so wait for membership
    # of the values this test needs rather than an exact option count.
    page.locator('.nav-item[data-view="invoices"]').click()
    page.wait_for_selector("#invoice-store-filter")
    page.wait_for_function(
        "(ids) => { const opts = Array.from("
        "document.querySelectorAll('#invoice-store-filter option'), o => o.value);"
        " return ids.every(id => opts.includes(id)); }",
        arg=["__all__", store_a, store_b],
    )

    # Both single-store views render exactly one row, so row count can't tell a
    # stale render from a fresh one — wait on the invoice text + row count
    # together to avoid racing the async loadInvoices() refetch.
    def wait_rows(predicate_js: str) -> None:
        page.wait_for_function(
            "() => { const rows = document.querySelectorAll('#all-invoices .inv-id-row');"
            " const t = document.querySelector('#all-invoices').innerText;"
            f" return ({predicate_js}); }}"
        )

    # "All stores" shows both stores' invoices. (>= 2, not === 2: on the shared
    # server another test's store could in principle hold invoices too — the
    # two itemDesc markers are what pins this test's rows.)
    page.select_option("#invoice-store-filter", "__all__")
    wait_rows("rows.length >= 2 && t.includes('ALPHA-INV') && t.includes('BETA-INV')")

    # Narrowing to store A shows only its single invoice...
    page.select_option("#invoice-store-filter", store_a)
    wait_rows("rows.length === 1 && t.includes('ALPHA-INV') && !t.includes('BETA-INV')")
    # ...and picking a concrete store keeps the global header selector in sync.
    assert page.input_value("#store-select") == store_a

    # Switching to store B narrows to its invoice and syncs the header too.
    page.select_option("#invoice-store-filter", store_b)
    wait_rows("rows.length === 1 && t.includes('BETA-INV') && !t.includes('ALPHA-INV')")
    assert page.input_value("#store-select") == store_b
