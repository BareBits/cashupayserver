"""WordPress-side steps of the upgrade tier.

The WordPress plugin is thin GPL glue over a BareBits server it either
installed alongside WordPress (install mode) or was pointed at (URL mode).
An upgrade therefore has two halves that merchants apply independently and
in either order — the SERVER's own auto-updater, and WordPress replacing the
PLUGIN's files — and every combination of old/new plugin with old/new server
must keep taking WooCommerce orders. The helpers here drive the plugin side
the way WordPress itself does it:

  install_plugin_zip()          `wp plugin install <zip> --activate`
  replace_plugin_from_zip()     Plugins → Add New → Upload → "Replace current
                                with uploaded" (Plugin_Upgrader::install with
                                overwrite_package; wp-cli's --force)
  update_plugin_via_wordpress() a wordpress.org-style update: WordPress's own
                                update offer names the new package and
                                Plugin_Upgrader::bulk_upgrade applies it — the
                                code path the wp.org directory's auto-updates
                                run — with the offer injected by a test-only
                                mu-plugin, since the directory itself cannot
                                be pointed at a candidate build
  run_alongside_install()       the install-mode onboarding of whatever plugin
                                release is installed
  connect_by_url()              the URL-mode onboarding, pairing included
  pay_wc_order()                a real guest WooCommerce order paid over
                                regtest Lightning, the signed webhook flipping
                                it to paid

Everything asserts on behaviour (options, files, order states), never on the
copy of a particular plugin release's pages — the plugin under test may be
any release in the window.
"""
from __future__ import annotations

import json
import re
import sqlite3
import zipfile
from pathlib import Path

import requests

from fixtures import releases
from fixtures.lnd import LndHandle
from fixtures.payserver import PayserverHandle
from fixtures.upgrade_flow import SeededInstall, pay_ln_invoice
from fixtures.wc_checkout import GATEWAY_ID, order_field, place_order
from fixtures.wordpress import WordPressHandle
from wordpress.conftest import drive_pairing, onboarding_page, post_onboarding, wp_option

PLUGIN_SLUG = "barebits"
PLUGIN_FILE = "barebits/barebits.php"
WPORG_SLUG = "barebits-lightning-payments-via-bitcoin"
UPDATE_OFFER_MU_PLUGIN = "barebits-test-update-offer.php"
# Environment for the BareBits server the plugin installs alongside WordPress
# (it runs inside the WP site's PHP): the same dev-fee / telemetry
# neutralisation fixtures/installed_payserver.py applies to a standalone
# install, so a lived-in store's accrued dev-fee debt can never redirect the
# next Lightning invoice to the (mainnet) fee LNURL.
ALONGSIDE_SERVER_ENV = {
    "CASHUPAY_DEV_FEE_BRANCH_ENDPOINT": "disabled",
    "CASHUPAY_USAGE_STATS_ENDPOINT": "disabled",
    "CASHUPAY_DEV_FEE_LNURL": "fees@fee-lnurl.invalid",
}


# --------------------------------------------------------------------------
# candidate plugin zips


def stamp_plugin_zip(src: Path, dest: Path) -> str:
    """Copy a plugin zip with its `Version:` header raised to <version>.9999
    (readme Stable tag alongside) and return the stamped version.

    The working tree's plugin header equals the newest published release's
    (the version is bumped at release time), and WordPress never offers — nor
    does wp-cli apply — an "update" to the version already installed. The
    stamp stands in for the release bump, the same way the candidate server
    zip gets a COMMIT_SHA that identifies the working tree; the shipped
    artifact is left untouched."""
    version = releases.plugin_version_in_zip(src)
    stamped = f"{version}.9999"
    dest.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(src) as zin, zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as zout:
        for info in zin.infolist():
            data = zin.read(info.filename)
            if info.filename == PLUGIN_FILE:
                text, n = re.subn(
                    r"^(\s*\*\s*Version:\s*)\S+", lambda m: m.group(1) + stamped,
                    data.decode("utf-8"), count=1, flags=re.M,
                )
                assert n == 1, f"no Version header in {src}"
                data = text.encode("utf-8")
            elif info.filename == "barebits/readme.txt":
                data = re.sub(
                    r"^Stable tag: .*$", f"Stable tag: {stamped}",
                    data.decode("utf-8"), count=1, flags=re.M,
                ).encode("utf-8")
            zout.writestr(info, data)
    return stamped


# --------------------------------------------------------------------------
# plugin install / replace / update


def install_plugin_zip(wp: WordPressHandle, zip_path: Path) -> None:
    """`wp plugin install <zip> --activate` — the operator's install of a
    plugin zip (Plugins → Add New → Upload)."""
    res = wp.wp_cli("plugin", "install", str(zip_path), "--activate")
    assert plugin_is_active(wp), f"barebits not active after installing {zip_path.name}:\n{res.stdout}\n{res.stderr}"


def installed_plugin_version(wp: WordPressHandle) -> str:
    return wp.wp_cli("plugin", "get", PLUGIN_SLUG, "--field=version").stdout.strip().splitlines()[-1]


def plugin_is_active(wp: WordPressHandle) -> bool:
    active = wp.wp_cli("plugin", "list", "--field=name", "--status=active").stdout.split()
    return PLUGIN_SLUG in active


def plugin_dir(wp: WordPressHandle) -> Path:
    return wp.wp_root / "wp-content" / "plugins" / PLUGIN_SLUG


def replace_plugin_from_zip(wp: WordPressHandle, zip_path: Path, *, expect_version: str) -> None:
    """Plugins → Add New → Upload → "Replace current with uploaded": WordPress
    overwrites the installed plugin's files with the uploaded zip's
    (Plugin_Upgrader::install with overwrite_package, WP 5.5+). The plugin
    stays active; no activation hook runs. wp-cli's --force is that flow."""
    res = wp.wp_cli("plugin", "install", str(zip_path), "--force")
    got = installed_plugin_version(wp)
    assert got == expect_version, (
        f"plugin header is {got!r} after replacing with {zip_path.name}, expected {expect_version!r}\n"
        f"{res.stdout}\n{res.stderr}"
    )
    assert plugin_is_active(wp), "the plugin must stay active across an upload-replace"


def offer_plugin_update(wp: WordPressHandle, *, package_url: str, new_version: str) -> None:
    """Test-only mu-plugin: make WordPress's plugin update check offer
    barebits/barebits.php an update to `new_version`, downloadable at
    `package_url` — the shape api.wordpress.org puts into the update_plugins
    site transient for a directory-hosted plugin. Read-time filter
    (site_transient_update_plugins), so it applies whether or not WordPress
    considers a fresh check due."""
    mu = wp.wp_root / "wp-content" / "mu-plugins"
    mu.mkdir(parents=True, exist_ok=True)
    (mu / UPDATE_OFFER_MU_PLUGIN).write_text(
        "<?php\n"
        "// Test-only: the wordpress.org update offer for the candidate build.\n"
        "add_filter('site_transient_update_plugins', function ($transient) {\n"
        "    if (!is_object($transient)) { $transient = new stdClass(); }\n"
        "    if (!isset($transient->response) || !is_array($transient->response)) { $transient->response = []; }\n"
        f"    $transient->response[{json.dumps(PLUGIN_FILE)}] = (object) [\n"
        f"        'id' => 'w.org/plugins/{WPORG_SLUG}',\n"
        f"        'slug' => {json.dumps(WPORG_SLUG)},\n"
        f"        'plugin' => {json.dumps(PLUGIN_FILE)},\n"
        f"        'new_version' => {json.dumps(new_version)},\n"
        f"        'url' => 'https://wordpress.org/plugins/{WPORG_SLUG}/',\n"
        f"        'package' => {json.dumps(package_url)},\n"
        "    ];\n"
        "    return $transient;\n"
        "});\n"
    )


def withdraw_plugin_update_offer(wp: WordPressHandle) -> None:
    (wp.wp_root / "wp-content" / "mu-plugins" / UPDATE_OFFER_MU_PLUGIN).unlink(missing_ok=True)


def update_plugin_via_wordpress(wp: WordPressHandle, *, package_url: str, new_version: str) -> None:
    """A wordpress.org-style plugin update: WordPress sees an update offer,
    `wp plugin update barebits` runs Plugin_Upgrader::bulk_upgrade on it —
    download, unpack, delete the old plugin folder, move the new one in,
    maintenance mode around it, plugin kept active, no activation hook. The
    offer is withdrawn afterwards so WordPress stops advertising it."""
    before = installed_plugin_version(wp)
    offer_plugin_update(wp, package_url=package_url, new_version=new_version)
    try:
        res = wp.wp_cli("plugin", "update", PLUGIN_SLUG)
    finally:
        withdraw_plugin_update_offer(wp)
    got = installed_plugin_version(wp)
    assert got == new_version, (
        f"plugin header is {got!r} after the update from {before!r}, expected {new_version!r}\n"
        f"{res.stdout}\n{res.stderr}"
    )
    assert plugin_is_active(wp), "the plugin must stay active across a wordpress.org update"


# --------------------------------------------------------------------------
# onboarding, whichever plugin release is installed


def run_alongside_install(wp: WordPressHandle, s: requests.Session) -> dict[str, str]:
    """Install mode: choose "install alongside", let the installed plugin
    download its own-channel release from the fixture release server and
    unpack it at <site>/barebits. Returns the plaintext secrets the plugin
    holds for the install (admin password, SSO key)."""
    body = onboarding_page(s, wp)
    assert "Fatal error" not in body, body[:2000]
    body = post_onboarding(s, wp, "barebits_choose_mode", {"barebits_mode": "install"})
    assert wp_option(wp, "barebits_mode") == "install", body[:3000]
    body = post_onboarding(s, wp, "barebits_run_install")
    assert (wp.barebits_dir / "BUILD_INFO").is_file() and (wp.barebits_dir / "user_config.php").is_file(), (
        f"the plugin did not unpack a BareBits install at {wp.barebits_dir}; page: {body[:3000]}"
    )
    assert wp.barebits_data_dir.is_dir(), "the data dir must land outside the docroot"
    assert wp_option(wp, "barebits_server_url") == wp.barebits_url
    admin_password = wp_option(wp, "barebits_admin_password")
    sso_key = wp_option(wp, "barebits_sso_key")
    assert len(admin_password) >= 20 and len(sso_key) == 64, "plugin did not record the install's credentials"
    return {"admin_password": admin_password, "sso_key": sso_key}


def alongside_handle(wp: WordPressHandle) -> PayserverHandle:
    """The alongside install as a PayserverHandle: same site process, URL
    under /barebits, data dir outside the docroot."""
    handle = PayserverHandle(
        server=wp.server, port=wp.port, data_dir=wp.barebits_data_dir, workdir=wp.workdir,
        base_path="/barebits",
    )
    handle.wait_ready()
    return handle


def collect_provision(wp: WordPressHandle, s: requests.Session) -> str:
    """After the wizard: the plugin collects the install's credentials through
    the one-time handshake. Returns the store id it recorded."""
    body = post_onboarding(s, wp, "barebits_collect_provision")
    store_id = wp_option(wp, "barebits_store_id")
    assert store_id, f"plugin recorded no store id after collecting credentials; page: {body[-3000:]}"
    assert wp_option(wp, "barebits_cron_key"), "install mode must record the cron key for the WP-cron pinger"
    assert wp_option(wp, "barebits_provision_token") == "", "one-time token must be deleted after use"
    return store_id


def connect_by_url(wp: WordPressHandle, s: requests.Session, seeded: SeededInstall) -> None:
    """URL mode: point the plugin at an existing server and pair."""
    body = onboarding_page(s, wp)
    assert "Fatal error" not in body, body[:2000]
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": seeded.url},
    )
    assert wp_option(wp, "barebits_server_url") == seeded.url, body[:3000]
    drive_pairing(wp, s, server_url=seeded.url, admin_password=seeded.password, store_id=seeded.store_id)
    assert wp_option(wp, "barebits_store_id") == seeded.store_id


def finish_wiring(wp: WordPressHandle, s: requests.Session, *, expect_gateway_url: str, percent: str = "0") -> dict:
    """The onboarding's last step: wire WooCommerce (gateway options, webhook
    on the server, gateway enabled). Returns the plugin's connection options
    worth comparing across upgrades."""
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": percent})
    assert wp_option(wp, "btcpay_gf_url") == expect_gateway_url, (
        f"gateway URL is {wp_option(wp, 'btcpay_gf_url')!r}, expected {expect_gateway_url!r}; page: {body[:3000]}"
    )
    gateway = json.loads(
        wp.wp_cli("option", "get", f"woocommerce_{GATEWAY_ID}_settings", "--format=json").stdout.strip()
    )
    assert gateway.get("enabled") == "yes", f"the BTCPay gateway must be enabled at checkout: {gateway}"
    return connection_options(wp)


def connection_options(wp: WordPressHandle) -> dict[str, str]:
    """Everything the plugin persisted about its connection — must survive
    both halves of an upgrade byte for byte."""
    return {
        name: wp_option(wp, name)
        for name in (
            "barebits_mode", "barebits_server_url", "barebits_store_id", "barebits_api_key",
            "barebits_cron_key", "barebits_admin_password", "barebits_sso_key",
            "barebits_discount_percent",
            "btcpay_gf_url", "btcpay_gf_store_id", "btcpay_gf_api_key", "btcpay_gf_webhook",
        )
    }


def assert_plugin_serving(wp: WordPressHandle, s: requests.Session, *, label: str) -> None:
    """The plugin's wp-admin page renders (no fatal across the plugin/server
    version skew) and, in install mode, the WP-cron pinger still reaches the
    install's cron.php."""
    body = onboarding_page(s, wp)
    assert "Fatal error" not in body and "Warning:" not in body, f"[{label}] plugin page broken: {body[:2000]}"
    if wp_option(wp, "barebits_mode") == "install":
        res = wp.wp_cli("cron", "event", "run", "barebits_cron_tick", check=False)
        assert res.returncode == 0, f"[{label}] WP-cron pinger failed:\n{res.stdout}\n{res.stderr}"
        with wp.db() as db:
            stamped = db.execute(
                "SELECT value FROM config WHERE key = 'last_external_cron_at'"
            ).fetchone()
        assert stamped is not None, f"[{label}] cron pinger never reached the install's cron.php"


# --------------------------------------------------------------------------
# the money


def pay_wc_order(
    wp: WordPressHandle, seeded: SeededInstall, lnd_payer: LndHandle, product_id: int, *, label: str
) -> int:
    """A guest buys the fixture product through the WooCommerce Store API
    paying with the BTCPay gateway (the gateway creates the invoice on the
    server), the bolt11 is paid from the regtest payer, the server settles
    and its cron drains the signed InvoiceSettled webhook to WooCommerce,
    whose BTCPay plugin flips the order to a paid state. Returns the order
    id."""
    checkout = place_order(wp, product_id)
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", f"[{label}] {checkout}"
    invoice_id = order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", f"[{label}] gateway stored no BareBits invoice id: {checkout}"
    seeded.invoice_ids.append(invoice_id)
    inv = seeded.greenfield.get_invoice(seeded.store_id, invoice_id)
    assert inv.get("status") in ("New", "Processing"), f"[{label}] fresh order invoice is {inv.get('status')}"
    pay_ln_invoice(seeded, lnd_payer, invoice_id)

    def paid() -> bool:
        return order_field(wp, order_id, "get_status()") in ("processing", "completed")

    def settled_delivery() -> dict | None:
        """The InvoiceSettled outbox row for this invoice (None when the
        release's outbox schema predates these columns)."""
        try:
            with seeded.handle.db() as db:
                row = db.execute(
                    "SELECT status, status_code FROM webhook_deliveries "
                    "WHERE invoice_id = ? AND event_type = 'InvoiceSettled'",
                    (invoice_id,),
                ).fetchone()
        except sqlite3.OperationalError:
            return {"status": "n/a", "status_code": None}
        return dict(row) if row is not None else None

    def settled_delivered() -> bool:
        row = settled_delivery()
        return row is not None and (
            row["status"] == "n/a"
            or (row["status"] == "delivered" and row["status_code"] is not None and 200 <= row["status_code"] < 300)
        )

    # The BTCPay plugin moves the order to processing on the FIRST event it
    # accepts (InvoiceProcessing / InvoiceReceivedPayment), so keep draining
    # until the InvoiceSettled delivery has been accepted as well — a 2xx
    # there corroborates from the server's side that WooCommerce verified the
    # signature (the plugin wp_die()s on a bad HMAC before touching the order).
    seeded.handle.drive_cron_until(
        lambda: paid() and settled_delivered(),
        timeout_s=60, label=f"[{label}] order {order_id} paid and InvoiceSettled delivered",
    )
    row = settled_delivery()
    assert row is not None and row["status"] in ("delivered", "n/a"), (
        f"[{label}] InvoiceSettled webhook not accepted by WooCommerce: {row}"
    )
    return order_id
    assert row is not None and row["status"] == "delivered" and 200 <= row["status_code"] < 300, (
        f"[{label}] InvoiceSettled webhook not accepted by WooCommerce: {dict(row) if row else None}"
    )
    return order_id
