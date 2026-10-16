"""Payment-page "Share" button (copies the invoice URL to the clipboard).

The button is rendered server-side only when the link could actually open on
another device (Urls::paymentLinkShareable):

  - hidden on a stock local install — the autodetected base URL host is
    127.0.0.1, which is loopback;
  - visible once the configured base URL is public; clicking it copies the
    canonical invoice URL (built from the configured base URL + URL mode,
    NOT location.href) and shows the dedicated share toast;
  - hidden on the Windows desktop package (CASHUPAY_DESKTOP=1) even when a
    public base URL is configured — localhost-only server, nothing to share.

The decision matrix itself (loopback spellings, *.localhost, ::1, 127/8) is
unit-tested in tests/php/test_share_link_visibility.php; these tests prove
the wiring end to end.
"""
from __future__ import annotations

import sqlite3
import time
from typing import Iterator

import pytest
import requests

from conftest import ConfiguredPayserver
from fixtures.api_client import GreenfieldClient
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver

PUBLIC_BASE_URL = "https://pay.merchant-e2e.example"


def _set_base_url(handle: PayserverHandle, value: str | None) -> None:
    """Write/remove the stored base_url the way Config::set does (raw string
    value; non-JSON strings round-trip verbatim through Config::get)."""
    with sqlite3.connect(str(handle.data_dir / "cashupay.sqlite")) as conn:
        if value is None:
            conn.execute("DELETE FROM config WHERE key = 'base_url'")
        else:
            now = int(time.time())
            conn.execute(
                "INSERT INTO config (key, value, created_at, updated_at)"
                " VALUES ('base_url', ?, ?, ?)"
                " ON CONFLICT(key) DO UPDATE SET value = excluded.value,"
                " updated_at = excluded.updated_at",
                (value, now, now),
            )
        conn.commit()


def _payment_page(base: str, gf: GreenfieldClient, store_id: str) -> tuple[str, str]:
    """Create a sat invoice and return (payment page URL, invoice id)."""
    invoice = gf.create_invoice(store_id, amount="1000", currency="sat")
    return f"{base}/payment.php?id={invoice['id']}", invoice["id"]


def test_share_button_hidden_on_loopback_install(
    shared_configured: ConfiguredPayserver,
) -> None:
    """Stock test install: served and viewed via 127.0.0.1 — no Share."""
    url, _ = _payment_page(
        shared_configured.handle.url,
        shared_configured.greenfield,
        shared_configured.store_id,
    )
    r = requests.get(url, timeout=15)
    assert r.status_code == 200, f"payment page did not render: {r.status_code}"
    assert 'data-share-url="' not in r.text, (
        "Share button rendered on a loopback install — the copied link could "
        "never open on the payer's device"
    )


def test_share_button_copies_invoice_url_and_toasts(
    shared_configured: ConfiguredPayserver, browser
) -> None:
    """With a public base URL the button appears in every method block, and a
    click puts the canonical (configured-base-URL) invoice link on the
    clipboard with the share toast."""
    handle = shared_configured.handle
    _set_base_url(handle, PUBLIC_BASE_URL)
    try:
        url, invoice_id = _payment_page(
            handle.url, shared_configured.greenfield, shared_configured.store_id
        )

        # Server-side render: the button carries the canonical URL built from
        # the configured base URL, not from the Host header we connect with.
        r = requests.get(url, timeout=15)
        assert 'data-share-url="' in r.text, "Share button missing with a public base URL"
        assert PUBLIC_BASE_URL in r.text, (
            "share URL must be built from the configured base URL"
        )

        ctx = browser.new_context()
        ctx.grant_permissions(["clipboard-read", "clipboard-write"])
        page = ctx.new_page()
        try:
            page.goto(url, wait_until="domcontentloaded")
            share = page.locator("[data-share-url]").first
            share.wait_for(state="visible", timeout=10000)
            share.click()

            toast = page.locator("#copy-toast")
            toast.wait_for(state="visible", timeout=5000)
            assert toast.inner_text() == "Share link copied to your clipboard!"

            clipboard = page.evaluate("navigator.clipboard.readText()")
            assert clipboard.startswith(PUBLIC_BASE_URL + "/"), (
                f"clipboard holds {clipboard!r}, expected the {PUBLIC_BASE_URL} invoice URL"
            )
            assert invoice_id in clipboard, (
                f"clipboard URL {clipboard!r} does not reference invoice {invoice_id}"
            )

            # The ordinary copy buttons keep their generic toast message.
            page.locator("[data-copy].btn").first.click()
            page.wait_for_function(
                "document.getElementById('copy-toast').textContent === 'Copied to clipboard!'",
                timeout=5000,
            )
        finally:
            ctx.close()
    finally:
        # The module-scoped shared server outlives this test; hand back the
        # autodetected (loopback) base URL so later tests see stock behavior.
        _set_base_url(handle, None)


@pytest.fixture()
def desktop_restarted(
    configured: ConfiguredPayserver,
) -> Iterator[tuple[PayserverHandle, ConfiguredPayserver]]:
    """The configured server restarted on its own data dir with
    CASHUPAY_DESKTOP=1 — a wizard-completed Windows-desktop-package install.
    (The desktop wizard drops screens, so configuring first and flipping the
    env on restart is the stable way to get a configured desktop server.)"""
    stop_payserver(configured.handle)
    workdir = configured.handle.data_dir.parent
    handle = start_payserver(workdir, extra_env={"CASHUPAY_DESKTOP": "1"})
    yield handle, configured
    stop_payserver(handle)


def test_share_button_hidden_on_windows_desktop(desktop_restarted) -> None:
    """Desktop package hides Share even with a public base URL configured."""
    handle, configured = desktop_restarted
    _set_base_url(handle, PUBLIC_BASE_URL)
    gf = GreenfieldClient(handle.url, configured.api_token)
    url, _ = _payment_page(handle.url, gf, configured.store_id)
    r = requests.get(url, timeout=15)
    assert r.status_code == 200, f"payment page did not render: {r.status_code}"
    assert 'data-share-url="' not in r.text, (
        "Share button rendered on the Windows desktop package — its server "
        "only listens on 127.0.0.1, there is no shareable link"
    )
