"""The plugin's "use an existing BareBits server" onboarding path.

A separate, already-configured standalone payserver plays the merchant's
existing server. The plugin validates the URL, pairs through the server's
BTCPay-compatible /api-keys/authorize redirect flow (state-token
authenticated callback), then wires WooCommerce: gateway plugin options,
webhook registered over the Greenfield API, branding, discount saved.
"""
from __future__ import annotations

import html
import json
import re
from urllib.parse import parse_qs, urlparse

import pytest
import requests

from wordpress.conftest import (
    drive_pairing,
    onboarding_page,
    post_onboarding,
    page_nonces,
    wp_login,
    wp_option,
)
from fixtures.wordpress import install_woocommerce

pytestmark = pytest.mark.wordpress


def _drive_pairing(wp, configured, s: requests.Session) -> None:
    """The browser's side of pairing (see wordpress.conftest.drive_pairing),
    against the configured standalone payserver."""
    drive_pairing(
        wp, s,
        server_url=configured.handle.url,
        admin_password=configured.admin_password,
        store_id=configured.store_id,
    )


def test_url_mode_end_to_end(wordpress, woocommerce, configured) -> None:
    wp = wordpress
    s = wp_login(wp)

    # A URL that is reachable but not a BareBits server is refused.
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": wp.url},
    )
    assert "does not look like a BareBits server" in body, body[:2000]
    assert wp_option(wp, "barebits_mode") == ""

    # The real server validates and moves us to the pairing step.
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": configured.handle.url},
    )
    assert "Pair with your BareBits server" in body, body[:2000]
    assert wp_option(wp, "barebits_server_url") == configured.handle.url

    _drive_pairing(wp, configured, s)
    assert wp_option(wp, "barebits_store_id") == configured.store_id

    # Finish: wire WooCommerce with a 2% discount.
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": "2"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]

    assert wp_option(wp, "btcpay_gf_url") == configured.handle.url
    assert wp_option(wp, "btcpay_gf_store_id") == configured.store_id
    paired_key = wp_option(wp, "btcpay_gf_api_key")
    assert re.fullmatch(r"[0-9a-f]{64}", paired_key)

    # Webhook registered on the REMOTE server over its Greenfield API.
    webhook_opt = json.loads(
        wp.wp_cli("option", "get", "btcpay_gf_webhook", "--format=json").stdout.strip()
    )
    assert webhook_opt["url"] == f"{wp.url}/?wc-api=btcpaygf_default"
    listed = requests.get(
        f"{configured.handle.url}/api/v1/stores/{configured.store_id}/webhooks",
        headers={"Authorization": f"token {configured.api_token}"},
        timeout=30,
    ).json()
    ours = [h for h in listed if h["id"] == webhook_opt["id"]]
    assert ours and ours[0]["enabled"] is True

    # `wp option get` reads through the runtime title filter (a CLI context is
    # not wp-admin), so this is the title customers see: suffixed with the
    # discount the merchant just chose.
    gateway = json.loads(
        wp.wp_cli(
            "option", "get", "woocommerce_btcpaygf_default_settings", "--format=json"
        ).stdout.strip()
    )
    assert gateway["enabled"] == "yes"
    assert "2% discount" in gateway["title"]

    # The discount option itself carries the answer; the checkout fee and the
    # title suffix are both derived from it at runtime (payment-discount.php).
    assert wp_option(wp, "barebits_discount_percent") == "2"

    # No cron pinger in URL mode: the remote server runs its own cron.
    assert wp_option(wp, "barebits_cron_key") == ""
    next_run = wp.wp_cli("cron", "event", "list", "--format=json").stdout
    assert "barebits_cron_tick" not in next_run

    # Status panel reflects the connection.
    body = onboarding_page(s, wp)
    assert "WooCommerce is connected" in body
    assert "Existing server (connected by URL)" in body


def test_pairing_denied_returns_to_onboarding(wordpress, configured) -> None:
    """A merchant clicking Deny on the approval screen lands back on the
    onboarding page with an error, and nothing is stored."""
    wp = wordpress
    s = wp_login(wp)
    post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": configured.handle.url},
    )
    nonces = page_nonces(onboarding_page(s, wp))
    r = s.post(
        f"{wp.url}/wp-admin/admin-post.php",
        data={"action": "barebits_start_pairing", "_wpnonce": nonces["barebits_start_pairing"]},
        timeout=30,
        allow_redirects=False,
    )
    callback = parse_qs(urlparse(r.headers["Location"]).query)["redirect"][0]

    # The server's deny path redirects to the callback with ?error=access_denied.
    denied = requests.get(callback + "&error=access_denied", timeout=30, allow_redirects=False)
    # GET with a valid state: the handler treats it as denied/incomplete.
    assert denied.status_code in (301, 302), denied.text[:300]
    assert wp_option(wp, "barebits_store_id") == ""

    body = onboarding_page(s, wp)
    assert "denied or came back incomplete" in body, body[:2000]
