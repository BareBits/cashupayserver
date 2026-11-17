"""WordPress URL mode with the wordpress.org plugin: a shop pointed at an
existing BareBits server, upgraded on both halves.

The wordpress.org variant ships no installer (the directory forbids plugins
that fetch executable code), so its merchants run a server of their own and
connect by URL; the plugin itself is updated by WordPress from the directory.
Per source release (and upgrade order):
  1. that release is installed standalone from its published zip (its own
     updater live, pointed at the candidate catalog), wizard walked, store
     seeded — the merchant's existing server
  2. a WooCommerce shop installs that release's PUBLISHED wordpress.org plugin
     zip, connects by URL, pairs through the server's authorize flow and
     wires WooCommerce; an order is paid — old plugin + old server
  3. the halves are upgraded in the parametrised order: the server by its
     OWN updater ("Update now"), the plugin by a wordpress.org-style update
     (WordPress's update offer names the candidate's wporg zip from the
     catalog; Plugin_Upgrader::bulk_upgrade applies it, the auto-update code
     path). An order is paid after each half — both skews taken live
  4. on the final build an on-chain invoice settles and the merchant's world
     is intact; roll the server back, and the old server under the NEW plugin
     still takes an order
"""
from __future__ import annotations

import pytest

from fixtures import releases
from fixtures.installed_payserver import read_build_info
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
    assert_plugin_serving,
    connect_by_url,
    connection_options,
    finish_wiring,
    install_plugin_zip,
    installed_plugin_version,
    pay_wc_order,
    plugin_dir,
    update_plugin_via_wordpress,
)
from wordpress.conftest import wp_login

pytestmark = pytest.mark.wordpress


def test_wporg_plugin_url_mode_upgrades_both_halves(
    source_release: Release,
    upgrade_order: str,
    install_release,
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

    # --- 1. the merchant's existing server: the old release, standalone -----
    handle, install = install_release(source_release, candidate_catalog, label=f"wporg-{tag}")
    old_info = read_build_info(install)
    assert old_info.get("VERSION") == source_release.version, old_info
    if old_info.get("COMMIT_SHA") == candidate_info["COMMIT_SHA"]:
        pytest.skip(f"{tag} is this exact build ({old_info['COMMIT_SHA'][:12]}); nothing to upgrade")
    seeded = seed_install(handle, install, tag, mint=mint, backup_mint=backup_mint, onchain=onchain)

    # --- 2. the old wordpress.org plugin connects by URL ----------------------
    wp = wordpress_shop(label=f"wporg-{tag}")
    install_plugin_zip(wp, releases.ensure_plugin_zip(source_release, wporg=True))
    assert installed_plugin_version(wp) == source_release.version, installed_plugin_version(wp)
    assert not (plugin_dir(wp) / "installer.php").exists(), "the wordpress.org variant ships no installer"
    s = wp_login(wp)
    connect_by_url(wp, s, seeded)
    before = finish_wiring(wp, s, expect_gateway_url=seeded.url)
    assert before["btcpay_gf_store_id"] == seeded.store_id
    flush_rewrites(wp)
    product_id = wp.woo_product_id
    assert product_id is not None, "the WooCommerce shop template carries the fixture product"

    oc_before, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_before)
    pay_wc_order(wp, seeded, lnd_payer, product_id, label=f"{tag}: old plugin + old server")
    assert_plugin_serving(wp, s, label=f"{tag}: old plugin + old server")

    # --- 3. the two halves, in order -----------------------------------------
    package_url = candidate_catalog.catalog_asset_url(
        candidate_info["TAG"], f"barebits_wordpress_plugin_wporg-{candidate_info['TAG']}.zip"
    )

    def upgrade_server(label: str) -> None:
        upgrade_server_via_dashboard(seeded, candidate_info)
        assert connection_options(wp) == before, f"[{label}] plugin options changed by a server upgrade"
        pay_wc_order(wp, seeded, lnd_payer, product_id, label=label)
        assert_plugin_serving(wp, s, label=label)

    def upgrade_plugin(label: str) -> None:
        update_plugin_via_wordpress(
            wp, package_url=package_url, new_version=candidate_plugin_zips["version"]
        )
        assert not (plugin_dir(wp) / "installer.php").exists(), (
            "a wordpress.org update must land the wordpress.org variant (no installer)"
        )
        assert connection_options(wp) == before, f"[{label}] plugin options changed by a plugin upgrade"
        pay_wc_order(wp, seeded, lnd_payer, product_id, label=label)
        assert_plugin_serving(wp, s, label=label)

    if upgrade_order == "server_first":
        upgrade_server(f"{tag}: OLD plugin + NEW server")
        upgrade_plugin(f"{tag}: new plugin + new server")
    else:
        upgrade_plugin(f"{tag}: NEW plugin + OLD server")
        upgrade_server(f"{tag}: new plugin + new server")

    # --- 4. the final pair on both rails, then the backup --------------------
    oc_after, _ = create_onchain_invoice(seeded)
    pay_onchain_invoice(seeded, onchain, oc_after)
    rollback_server(seeded, old_info)
    pay_wc_order(wp, seeded, lnd_payer, product_id, label=f"{tag}: new plugin + rolled-back server")
    assert_plugin_serving(wp, s, label=f"{tag}: new plugin + rolled-back server")
