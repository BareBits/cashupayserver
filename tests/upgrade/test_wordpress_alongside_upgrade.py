"""WordPress install mode: a shop whose BareBits server was installed
alongside WordPress by the plugin, upgraded on both halves.

Per source release (and upgrade order):
  1. a WooCommerce shop installs that release's PUBLISHED plugin zip; the
     plugin's install-alongside onboarding downloads that release's server
     from its own channel and unpacks it at <site>/barebits (data dir outside
     the docroot, pre-seeded admin, WP-cron pinger) — the old plugin installs
     the old server, exactly as a merchant of the day got it
  2. the old server's wizard is walked, the store gets the regtest xpub,
     products and an API key; the plugin collects credentials and wires
     WooCommerce; a guest order is placed and paid over Lightning, and an
     on-chain invoice settles — old plugin + old server take money
  3. the two halves are upgraded to the build under test, in the
     parametrised order: the server by its OWN updater ("Update now" in the
     embedded admin, health-verified), the plugin by WordPress's
     upload-and-replace (the way the GitHub-distributed full plugin is
     updated). After EACH half an order is paid — so old plugin + new server
     and new plugin + old server are both lived through — and the plugin's
     wp-admin page still renders and its cron pinger still reaches cron.php
  4. on the final build: an on-chain invoice settles too, the merchant's
     login/store/products/API key/invoices are intact
  5. the server's backup works: roll back, the old server is live again under
     the NEW plugin, still boots on the migrated database, and still takes an
     order
"""
from __future__ import annotations

import pytest

from fixtures import releases
from fixtures.installed_payserver import point_updater_at, read_build_info
from fixtures.lnd import LndHandle
from fixtures.nutshell import MintHandle
from fixtures.onchain import OnchainContext
from fixtures.release_server import ReleaseServer
from fixtures.releases import Release
from fixtures.upgrade_flow import (
    create_onchain_invoice,
    pay_onchain_invoice,
    rollback_server,
    seed_install,
    upgrade_server_via_dashboard,
)
from fixtures.wc_checkout import flush_rewrites
from upgrade.conftest import ShopFactory
from upgrade.wp_flow import (
    alongside_handle,
    assert_plugin_serving,
    collect_provision,
    connection_options,
    finish_wiring,
    install_plugin_zip,
    installed_plugin_version,
    pay_wc_order,
    plugin_dir,
    replace_plugin_from_zip,
    run_alongside_install,
)
from wordpress.conftest import wp_login

pytestmark = pytest.mark.wordpress


def test_alongside_install_upgrades_both_halves(
    source_release: Release,
    upgrade_order: str,
    source_plugin_catalog,
    wordpress_shop: ShopFactory,
    candidate_catalog: ReleaseServer,
    candidate_info: dict[str, str],
    candidate_plugin_zips: dict[str, object],
    mint: MintHandle,
    backup_mint: MintHandle,
    onchain: OnchainContext,
    lnd_payer: LndHandle,
) -> None:
    tag = source_release.tag
    source_rs = source_plugin_catalog(source_release)
    wp = wordpress_shop(
        plugin_release_api_base=source_rs.api_base,
        server_release_api_base=candidate_catalog.releases_base,
        label=f"alongside-{tag}",
    )

    # --- 1. the old plugin installs the old server ---------------------------
    install_plugin_zip(wp, releases.ensure_plugin_zip(source_release))
    assert installed_plugin_version(wp) == source_release.version, installed_plugin_version(wp)
    assert (plugin_dir(wp) / "installer.php").is_file(), "the full plugin variant ships the installer"
    s = wp_login(wp)
    secrets = run_alongside_install(wp, s)
    install = wp.barebits_dir
    old_info = read_build_info(install)
    assert old_info.get("VERSION") == source_release.version, old_info
    if old_info.get("COMMIT_SHA") == candidate_info["COMMIT_SHA"]:
        pytest.skip(f"{tag} is this exact build ({old_info['COMMIT_SHA'][:12]}); nothing to upgrade")
    # The server the plugin unpacked must read updates from the candidate
    # catalog: releases that honour CASHUPAY_RELEASE_API_BASE already do (the
    # site's environment carries it); older ones get their test seam pointed.
    point_updater_at(install, candidate_catalog.releases_base)

    # --- 2. lived-in, wired, paid on the old pair ----------------------------
    handle = alongside_handle(wp)
    seeded = seed_install(
        handle, install, tag,
        mint=mint, backup_mint=backup_mint, onchain=onchain,
        password=secrets["admin_password"], admin_entry="/admin.php",
    )
    assert collect_provision(wp, s) == seeded.store_id
    before = finish_wiring(wp, s, expect_gateway_url=wp.barebits_gateway_url)
    assert before["btcpay_gf_store_id"] == seeded.store_id
    flush_rewrites(wp)
    product_id = wp.woo_product_id
    assert product_id is not None, "the WooCommerce shop template carries the fixture product"

    oc_before, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_before)
    pay_wc_order(wp, seeded, lnd_payer, product_id, label=f"{tag}: old plugin + old server")
    assert_plugin_serving(wp, s, label=f"{tag}: old plugin + old server")

    # --- 3. the two halves, in order -----------------------------------------
    def upgrade_server(label: str) -> None:
        upgrade_server_via_dashboard(seeded, candidate_info)
        assert connection_options(wp) == before, f"[{label}] plugin options changed by a server upgrade"
        pay_wc_order(wp, seeded, lnd_payer, product_id, label=label)
        assert_plugin_serving(wp, s, label=label)

    def upgrade_plugin(label: str) -> None:
        replace_plugin_from_zip(
            wp, candidate_plugin_zips["full"], expect_version=candidate_plugin_zips["version"]
        )
        assert (plugin_dir(wp) / "installer.php").is_file(), "the full variant keeps its installer"
        assert connection_options(wp) == before, f"[{label}] plugin options changed by a plugin upgrade"
        pay_wc_order(wp, seeded, lnd_payer, product_id, label=label)
        assert_plugin_serving(wp, s, label=label)

    if upgrade_order == "server_first":
        upgrade_server(f"{tag}: OLD plugin + NEW server")
        upgrade_plugin(f"{tag}: new plugin + new server")
    else:
        upgrade_plugin(f"{tag}: NEW plugin + OLD server")
        upgrade_server(f"{tag}: new plugin + new server")

    # --- 4. the final pair on both rails ------------------------------------
    oc_after, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_after)
    cron = seeded.handle.trigger_cron_json()
    assert cron, "cron pass returned no summary after the upgrade"

    # --- 5. the server's backup works under the new plugin -------------------
    rollback_server(seeded, old_info)
    pay_wc_order(wp, seeded, lnd_payer, product_id, label=f"{tag}: new plugin + rolled-back server")
    assert_plugin_serving(wp, s, label=f"{tag}: new plugin + rolled-back server")
