"""The plugin's "install BareBits alongside WordPress" onboarding path.

End to end, the way a merchant on shared hosting would experience it:

    choose "install" → the plugin downloads the latest release from the
    (fixture) GitHub API, verifies it against SHA256SUMS, unpacks it to
    ABSPATH/barebits with its data dir OUTSIDE the docroot → the merchant
    walks the real BareBits setup wizard (managed install: the admin account
    is pre-seeded so there is NO password screen, and no cron screen either)
    → the plugin collects credentials through the one-time provisioning
    handshake → WooCommerce is wired (gateway plugin + webhook over the
    Greenfield API + branding + ELEX discount) → the WP-cron pinger drives
    the install's cron.php, SSO tokens sign the operator into the embedded
    admin, and the managed-install UI shaping is live (single store, no
    Products/Customers, payer email capture off, shop-facing redirects).

Needs the mint fixtures (the wizard's mints screen talks to a live mint).
"""
from __future__ import annotations

import json
import re

import pytest
import requests

from wordpress.conftest import (
    onboarding_page,
    post_onboarding,
    wp_login,
    wp_option,
)
from fixtures.setup_helpers import SetupWizard, wizard_heading
from fixtures.wordpress import WordPressHandle, install_woocommerce, stage_elex_discount_plugin

pytestmark = pytest.mark.wordpress


def _walk_barebits_wizard(wp: WordPressHandle, mint_url: str, backup_mint_url: str) -> None:
    """Drive the alongside install's real standalone wizard. The install was
    provisioned with its data dir outside the docroot (security screen
    skipped), a pre-seeded admin account (password screen skipped — the
    merchant never types a BareBits password), and CASHUPAY_MANAGED_INSTALL
    (cron screen skipped)."""
    wiz = SetupWizard(wp.url, setup_path="/barebits/setup.php")
    body = wiz.accept_terms()
    heading = wizard_heading(body)
    # Managed install: terms lands straight on the store screen — no
    # security screen (data dir outside the web root) and no password screen
    # (the admin account was pre-seeded from CASHUPAY_ADMIN_PASSWORD_HASH).
    assert "password" not in heading.lower(), (
        f"password screen rendered despite the pre-seeded admin: {heading!r}"
    )
    assert "store" in heading.lower(), f"expected the store screen, got {heading!r}"
    body = wiz.post(step="store", store_name="Alongside Store", default_currency="sat")
    body = wiz.post(step="onchain", onchain_action="skip")
    body = wiz.post(step="lightning", lightning_action="skip")
    body = wiz.post(step="swaps", swaps_enabled="0")
    body = wiz.post(
        step="mints",
        mints_enabled="1",
        mint_url=mint_url,
        backup_mint_url=backup_mint_url,
        mint_unit="sat",
    )
    # Managed install: the wizard must land on completion, never the crontab
    # screen — WP-cron owns the heartbeat.
    heading = wizard_heading(body)
    assert "cron" not in heading.lower(), (
        f"cron screen rendered despite CASHUPAY_MANAGED_INSTALL: {heading!r}"
    )


def test_install_mode_end_to_end(wordpress_install_mode, mint, backup_mint) -> None:
    wp = wordpress_install_mode
    s = wp_login(wp)

    # Step 1: the chooser offers both paths.
    body = onboarding_page(s, wp)
    assert "already run a BareBits server" in body
    assert "Install BareBits alongside WordPress" in body

    body = post_onboarding(s, wp, "cashupay_choose_mode", {"cashupay_mode": "install"})
    assert "Download and install BareBits" in body, body[:2000]

    # Step 2: the installer downloads from the fixture release API, verifies
    # the checksum, and unpacks next to WordPress.
    body = post_onboarding(s, wp, "cashupay_run_install")
    assert "BareBits is installed at" in body, body[:2000]
    assert "not checksum-verified" not in body, "SHA256SUMS was published; the install must verify it"

    assert (wp.barebits_dir / "BUILD_INFO").is_file()
    assert (wp.barebits_dir / "setup.php").is_file()
    assert (wp.barebits_dir / "provision.php").is_file()
    user_config = (wp.barebits_dir / "user_config.php").read_text()
    assert str(wp.barebits_data_dir) in user_config
    assert "CASHUPAY_MANAGED_INSTALL" in user_config
    assert "CASHUPAY_SHOP_URL" in user_config
    assert "CASHUPAY_RETRY_URL_TEMPLATE" in user_config
    assert "CASHUPAY_ADMIN_PASSWORD_HASH" in user_config
    assert "CASHUPAY_SSO_KEY_HASH" in user_config
    assert "CASHUPAY_PROVISION_TOKEN_HASH" in user_config
    assert f"{wp.url}/barebits" in user_config  # pinned base URL
    # The plugin holds the plaintexts the hashes were derived from.
    admin_password = wp_option(wp, "cashupay_admin_password")
    sso_key = wp_option(wp, "cashupay_sso_key")
    assert len(admin_password) >= 20 and len(sso_key) == 64
    # The data dir landed OUTSIDE the served docroot (sibling of ABSPATH).
    assert wp.barebits_data_dir.is_dir()
    assert wp_option(wp, "cashupay_server_url") == wp.barebits_url
    assert wp_option(wp, "cashupay_mode") == "install"

    # The install is served: pre-setup, the API answers with its 503 guard
    # (setup incomplete) rather than a WordPress 404 — proof the request
    # reached BareBits, not WP.
    info = requests.get(f"{wp.barebits_url}/api/v1/server/info", timeout=30)
    assert info.status_code == 503, info.text[:300]

    # Step 3: collecting credentials before the wizard is done must say so.
    body = post_onboarding(s, wp, "cashupay_collect_provision")
    assert "not finished yet" in body, body[:2000]

    _walk_barebits_wizard(wp, mint.url, backup_mint.url)

    # Now set up, the server identifies itself.
    info = requests.get(f"{wp.barebits_url}/api/v1/server/info", timeout=30)
    assert info.status_code == 200 and info.json().get("isCashuPayServer") is True

    body = post_onboarding(s, wp, "cashupay_collect_provision")
    assert "Connected!" in body, body[:2000]
    store_id = wp_option(wp, "cashupay_store_id")
    assert store_id
    assert wp_option(wp, "cashupay_cron_key")
    assert wp_option(wp, "cashupay_provision_token") == "", "one-time token must be deleted after use"

    # The handshake is single-use on the server side too.
    with wp.db() as db:
        consumed = db.execute(
            "SELECT value FROM config WHERE key = 'provision_consumed_at'"
        ).fetchone()
        assert consumed is not None
        internal_key = db.execute(
            "SELECT internal_api_key FROM stores WHERE id = ?", (store_id,)
        ).fetchone()
    replay = requests.post(f"{wp.barebits_url}/provision.php", timeout=30)
    assert replay.status_code in (403, 410)

    # Step 4: WooCommerce wiring with a 3% discount.
    install_woocommerce(wp)
    stage_elex_discount_plugin(wp)
    body = post_onboarding(s, wp, "cashupay_finish", {"cashupay_discount_percent": "3"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]

    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_url
    assert wp_option(wp, "btcpay_gf_store_id") == store_id
    assert wp_option(wp, "btcpay_gf_api_key") == internal_key["internal_api_key"]

    # Webhook registered over the Greenfield API, secret shared with the
    # gateway plugin's option.
    webhook_opt = json.loads(
        wp.wp_cli("option", "get", "btcpay_gf_webhook", "--format=json").stdout.strip()
    )
    assert webhook_opt["url"] == f"{wp.url}/?wc-api=btcpaygf_default"
    with wp.db() as db:
        row = db.execute(
            "SELECT secret, enabled FROM webhooks WHERE id = ?", (webhook_opt["id"],)
        ).fetchone()
    assert row is not None and row["enabled"] == 1
    assert row["secret"] == webhook_opt["secret"]

    # Gateway enabled at checkout, discount advertised, ELEX rule written.
    gateway = json.loads(
        wp.wp_cli(
            "option", "get", "woocommerce_btcpaygf_default_settings", "--format=json"
        ).stdout.strip()
    )
    assert gateway["enabled"] == "yes"
    assert "3% discount" in gateway["title"]
    elex = json.loads(
        wp.wp_cli(
            "option", "get", "elex_discount_per_payment_method_options", "--format=json"
        ).stdout.strip()
    )
    assert any(
        rule.get("id") == "btcpaygf_default" and rule.get("value") == "3"
        for rule in elex
    ), elex

    # Once wired, clicking "BareBits" in wp-admin embeds the admin behind a
    # one-time SSO sign-in URL — the old windowed experience, no password.
    body = onboarding_page(s, wp)
    assert 'id="cashupay-admin-frame"' in body, body[-1500:]
    assert "sso.php?token=" in body
    # The Connection page carries the status table and the password reveal.
    conn = s.get(
        f"{wp.url}/wp-admin/admin.php", params={"page": "cashupay-connection"}, timeout=60
    ).text
    assert "WooCommerce is connected" in conn
    assert "Installed alongside WordPress" in conn
    nonce = re.search(r'id="cashupay-reveal-password"\s+data-nonce="([^"]+)"', conn).group(1)
    reveal = s.post(
        f"{wp.url}/wp-admin/admin-ajax.php",
        data={"action": "cashupay_reveal_password", "nonce": nonce},
        timeout=30,
    ).json()
    assert reveal.get("success") is True and reveal.get("data") == admin_password

    # SSO end to end: mint a token with the plugin's key, consume it, and
    # land in the admin as a signed-in operator with the managed-install UI
    # shaping live (single store, shop-owned sections hidden).
    minted = requests.post(
        f"{wp.barebits_url}/sso.php",
        headers={"X-SSO-KEY": sso_key},
        timeout=30,
    ).json()
    assert minted.get("status") == "ready" and re.fullmatch(r"[0-9a-f]{64}", minted["token"])
    bs = requests.Session()
    landed = bs.get(
        f"{wp.barebits_url}/sso.php", params={"token": minted["token"]},
        timeout=30, allow_redirects=False,
    )
    assert landed.status_code in (301, 302), landed.text[:300]
    admin_page = bs.get(f"{wp.barebits_url}/admin.php", timeout=60).text
    assert "managed-hidden" in admin_page, "managed-install UI shaping missing from the admin"
    # A consumed token can never be replayed.
    replayed = requests.get(
        f"{wp.barebits_url}/sso.php", params={"token": minted["token"]},
        timeout=30, allow_redirects=False,
    )
    assert replayed.status_code == 403
    # The pre-seeded credentials also work for a direct (non-SSO) login —
    # the fallback if the plugin is ever removed, and the password BareBits
    # asks for before revealing a wallet recovery phrase.
    direct = requests.Session().post(
        f"{wp.barebits_url}/admin.php",
        data={"action": "login", "username": "admin", "password": admin_password},
        timeout=30,
    )
    assert direct.status_code == 200 and direct.json().get("success") is True, direct.text[:300]

    # Step 5: the WP-cron pinger reaches the install's cron.php with the
    # provisioned key — the install sees a real external cron run.
    result = wp.wp_cli("cron", "event", "run", "cashupay_cron_tick")
    assert result.returncode == 0
    with wp.db() as db:
        stamped = db.execute(
            "SELECT value FROM config WHERE key = 'last_external_cron_at'"
        ).fetchone()
    assert stamped is not None, "cron pinger never reached the install's cron.php"

    # Invoice creation works end to end through the paired credentials (the
    # exact call the WooCommerce gateway makes at checkout).
    invoice = requests.post(
        f"{wp.barebits_url}/api/v1/stores/{store_id}/invoices",
        json={"amount": "21", "currency": "SATS"},
        headers={"Authorization": f"token {internal_key['internal_api_key']}"},
        timeout=60,
    )
    assert invoice.status_code == 200, invoice.text[:300]
    invoice_id = invoice.json().get("id")
    assert invoice_id

    # Managed installs default payer email capture OFF: the payment page
    # renders no email/newsletter form and the endpoint refuses POSTs.
    pay_page = requests.get(
        f"{wp.barebits_url}/payment.php", params={"id": invoice_id}, timeout=30
    ).text
    assert 'id="receipt-form"' not in pay_page
    refused = requests.post(
        f"{wp.barebits_url}/payment.php",
        params={"id": invoice_id},
        data={"action": "send_receipt", "email": "x@example.test"},
        timeout=30,
    )
    assert refused.status_code == 404

    # E-commerce invoices carry the shop-side retry link (rendered in the
    # expired screen's markup) built from the provisioned template…
    order_invoice = requests.post(
        f"{wp.barebits_url}/api/v1/stores/{store_id}/invoices",
        json={"amount": "21", "currency": "SATS", "metadata": {"orderId": "4242"}},
        headers={"Authorization": f"token {internal_key['internal_api_key']}"},
        timeout=60,
    ).json()
    order_page = requests.get(
        f"{wp.barebits_url}/payment.php", params={"id": order_invoice["id"]}, timeout=30
    ).text
    assert "?cashupay-retry=" in order_page, "retry link missing for an e-commerce invoice"
    # …and the plugin's endpoint answers it (no matching order here, so it
    # falls back to the shop's front page rather than a dead end).
    bounced = requests.get(
        f"{wp.url}/?cashupay-retry={order_invoice['id']}",
        timeout=30, allow_redirects=False,
    )
    assert bounced.status_code in (301, 302), bounced.text[:300]
    assert bounced.headers["Location"].rstrip("/") == wp.url.rstrip("/")


def test_install_refuses_checksum_mismatch(standalone_zip) -> None:
    """A zip that no longer matches the release's SHA256SUMS (modified in
    transit, compromised mirror) must be rejected, leaving no install
    behind."""
    import uuid

    from wordpress.conftest import SESSION_TMP
    from fixtures.release_server import start_release_server, stop_release_server
    from fixtures.wordpress import start_wordpress, stop_wordpress

    rs = start_release_server(standalone_zip, tamper=True)
    wp = None
    try:
        wp = start_wordpress(
            SESSION_TMP / f"wp-tamper-{uuid.uuid4().hex[:8]}",
            release_api_base=rs.api_base,
        )
        from wordpress.conftest import _allow_nonstandard_ports

        _allow_nonstandard_ports(wp)
        s = wp_login(wp)
        post_onboarding(s, wp, "cashupay_choose_mode", {"cashupay_mode": "install"})
        body = post_onboarding(s, wp, "cashupay_run_install")
        assert "Checksum mismatch" in body, body[:2000]
        assert not wp.barebits_dir.exists(), "a failed-verification zip must never be unpacked"
        assert wp_option(wp, "cashupay_server_url") == ""
    finally:
        if wp is not None:
            stop_wordpress(wp)
        stop_release_server(rs)
