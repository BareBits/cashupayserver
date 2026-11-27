"""WordPress-core compatibility: the plugin's whole surface under WP_DEBUG.

A new WordPress release deprecates APIs before it removes them, and it says
so only when WP_DEBUG is on — which it is not on the fixture's installs, nor
on most shops. So a plugin can keep passing every functional test on a new
core while quietly accruing the warnings that become fatals a release or two
later. This module closes that gap: it runs the plugin's full surface on an
install with WP_DEBUG + WP_DEBUG_LOG on and the fixture's deprecation probe
installed (fixtures/wordpress.py, start_wordpress(debug_log=True)), then
asserts that nothing in either log is attributable to the barebits plugin.
Third-party noise — WooCommerce, the BTCPay gateway, the SQLite drop-in,
wp-cli — is dropped by the classifier (classify_debug_findings), and the
probe records the call stack of every WordPress-level report so a deprecated
call made by the plugin is pinned on the plugin even though WordPress logs it
from wp-includes/functions.php.

The surface covered, end to end on the real stack:

  activation (deactivate + reactivate through wp-cli), the wp-admin pages the
  merchant sees (dashboard, plugins list, the BareBits page in every
  onboarding state), URL-mode onboarding with a real pairing against the
  payserver and the WooCommerce wiring that follows, the storefront product
  page, the block checkout and the classic checkout renders, a guest Store
  API checkout through the gateway (the discount fee hooks run there), the
  settled-invoice webhook delivered by the payserver's cron (the plugin's
  payment_complete hook), WP-Cron, and the uninstall routine.

The core this runs on is the fixture pin (WP_VERSION); to validate an
upcoming release before the pin moves, point the tier at it:

    CASHUPAY_WP_CORE_VERSION=7.2-RC3 tests/.venv/bin/pytest wordpress/test_wp_core_compat.py

The probe itself is proven live by test_debug_probe_attributes_plugin_reports.
"""
from __future__ import annotations

import json
import time
import uuid

import pytest
import requests

from conftest import SESSION_TMP, _maybe_remove_workdir, _test_went_green
from fixtures.wc_checkout import order_field, place_order
from fixtures.wordpress import (
    WordPressHandle,
    start_wordpress,
    stop_wordpress,
    use_classic_checkout,
)
from wordpress.conftest import drive_pairing, onboarding_page, post_onboarding, wp_login, wp_option

pytestmark = pytest.mark.wordpress


@pytest.fixture
def wordpress_debug(request) -> WordPressHandle:
    """WordPress + plugin + WooCommerce stack with WP_DEBUG on and the
    deprecation probe installed. A clone of the WooCommerce golden like the
    `wordpress` + `woocommerce` fixtures; only the per-clone wp-config.php
    and the mu-plugin differ."""
    workdir = SESSION_TMP / f"wp-debug-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(workdir, with_woocommerce=True, debug_log=True)
    yield handle
    stop_wordpress(handle)
    _maybe_remove_workdir(workdir, _test_went_green(request))


def _get(s: requests.Session, url: str, **params) -> str:
    r = s.get(url, params=params or None, timeout=60)
    assert r.status_code == 200, f"GET {r.url} -> {r.status_code}: {r.text[:300]}"
    return r.text


def _drain_until_paid(configured, wp: WordPressHandle, order_id: int) -> str:
    """Drive the payserver cron (the async webhook outbox) until WooCommerce
    reports the order paid, i.e. the gateway's webhook handler ran
    payment_complete() and the plugin's hooks on it fired."""
    deadline = time.monotonic() + 45
    status = None
    while time.monotonic() < deadline:
        r = configured.handle.trigger_cron()
        assert r.status_code == 200, f"cron drain refused: {r.status_code} {r.text[:200]}"
        status = order_field(wp, order_id, "get_status()")
        if status in ("processing", "completed"):
            return status
        time.sleep(1)
    raise AssertionError(f"order {order_id} never reached a paid state (last status {status!r})")


def test_plugin_surface_is_clean_under_wp_debug(wordpress_debug: WordPressHandle, configured) -> None:
    wp = wordpress_debug
    assert wp.debug_log_path is not None and wp.debug_probe_path is not None
    s = wp_login(wp)

    # Activation hooks and the plugin's bootstrap, under WP_DEBUG.
    wp.wp_cli("plugin", "deactivate", "barebits")
    wp.wp_cli("plugin", "activate", "barebits")

    # The wp-admin pages a merchant sees before onboarding: the admin nag,
    # the plugins list (header + action links), the BareBits chooser.
    _get(s, f"{wp.url}/wp-admin/index.php")
    _get(s, f"{wp.url}/wp-admin/plugins.php")
    body = onboarding_page(s, wp)
    assert "BareBits" in body

    # URL-mode onboarding end to end: a refused URL, the real server, a real
    # pairing on the payserver's authorize page, the WooCommerce wiring.
    body = post_onboarding(
        s, wp, "barebits_choose_mode", {"barebits_mode": "url", "barebits_server_url": wp.url}
    )
    assert "does not look like a BareBits server" in body, body[:2000]
    body = post_onboarding(
        s, wp, "barebits_choose_mode",
        {"barebits_mode": "url", "barebits_server_url": configured.handle.url},
    )
    assert "Pair with your BareBits server" in body, body[:2000]
    drive_pairing(
        wp, s,
        server_url=configured.handle.url,
        admin_password=configured.admin_password,
        store_id=configured.store_id,
    )
    body = post_onboarding(s, wp, "barebits_finish", {"barebits_discount_percent": "2"})
    assert "WooCommerce now takes Bitcoin" in body, body[:2000]
    assert wp_option(wp, "btcpay_gf_store_id") == configured.store_id

    # The connected status panel and WooCommerce's own settings screens the
    # plugin filters (gateway list, the gateway's settings).
    body = onboarding_page(s, wp)
    assert "WooCommerce is connected" in body
    _get(s, f"{wp.url}/wp-admin/admin.php", page="wc-settings", tab="checkout")
    _get(s, f"{wp.url}/wp-admin/admin.php", page="wc-settings", tab="checkout", section="btcpaygf_default")

    # Storefront renders: product page, block checkout, classic checkout.
    guest = requests.Session()
    product_id = wp.woo_product_id
    assert product_id
    _get(guest, f"{wp.url}/", p=product_id)
    checkout_page_id = wp.wp_cli("option", "get", "woocommerce_checkout_page_id").stdout.strip().splitlines()[-1]
    _get(guest, f"{wp.url}/", page_id=checkout_page_id)
    use_classic_checkout(wp)
    _get(guest, f"{wp.url}/", page_id=checkout_page_id)

    # A real guest checkout through the gateway (the discount fee follows the
    # selected method on the Store API path), then the settled-invoice
    # webhook the payserver's cron delivers — the plugin's payment_complete
    # hook runs inside that request.
    checkout = place_order(wp, product_id)
    order_id = checkout["order_id"]
    assert checkout.get("payment_result", {}).get("payment_status") == "success", checkout
    invoice_id = order_field(wp, order_id, "get_meta('BTCPay_id')")
    assert invoice_id and invoice_id != "NO_ORDER", checkout
    marked = configured.greenfield.mark_invoice_status(configured.store_id, invoice_id, "Settled")
    assert marked.get("status") == "Settled", marked
    _drain_until_paid(configured, wp, order_id)
    # The order's own edit screen (post.php on the posts-table storage this
    # shop is pinned to; wc-orders under HPOS) — the plugin's order note and
    # gateway meta render there.
    edit_url = wp.wp_cli(
        "eval", f"echo wc_get_order({order_id})->get_edit_order_url();"
    ).stdout.strip().splitlines()[-1]
    assert edit_url.startswith(wp.url), edit_url
    _get(s, edit_url)

    # WP-Cron and the uninstall routine (uninstall.php runs under WP_DEBUG
    # too; --skip-delete keeps the files for the probe's final read).
    wp.wp_cli("cron", "event", "run", "--due-now")
    wp.wp_cli("plugin", "deactivate", "barebits")
    wp.wp_cli("plugin", "uninstall", "barebits", "--skip-delete")

    # Something ran under the probe at all (WooCommerce and wp-cli are never
    # silent under WP_DEBUG on a fresh core), so an empty finding list means
    # "clean", not "nothing was logged".
    assert wp.debug_log_path.is_file() or wp.debug_probe_path.is_file(), (
        "neither WP_DEBUG_LOG nor the probe wrote anything — is WP_DEBUG on?"
    )
    findings = wp.plugin_debug_findings()
    assert findings == [], (
        f"{len(findings)} PHP/WordPress report(s) attributed to the barebits plugin on "
        f"WordPress {wp.wp_cli('core', 'version').stdout.strip()}:\n  " + "\n  ".join(findings)
    )


def test_debug_probe_attributes_plugin_reports(wordpress_debug: WordPressHandle) -> None:
    """Positive control for the probe pipeline: a deprecated call and a
    _doing_it_wrong() made from a file inside the plugin dir are recorded
    with their stack and attributed to the plugin; the same calls from
    wp-cli's eval'd code are recorded but not attributed."""
    wp = wordpress_debug
    control = wp.wp_root / "wp-content" / "plugins" / "barebits" / "zz-probe-control.php"
    control.write_text(
        "<?php\n"
        "_deprecated_function('barebits_probe_control_fn', '0.0', 'nothing');\n"
        "_doing_it_wrong('barebits_probe_control_wrong', 'control message', '0.0');\n"
        "trigger_error('barebits probe control notice', E_USER_NOTICE);\n"
    )
    try:
        # include, not `wp eval-file`: wp-cli eval()s a file's contents, which
        # would strip the plugin-dir path off every frame.
        wp.wp_cli("eval", f"include {str(control)!r};")
    finally:
        control.unlink()
    wp.wp_cli("eval", "_deprecated_function('barebits_probe_unattributed_fn', '0.0');")

    assert wp.debug_probe_path is not None and wp.debug_probe_path.is_file()
    recorded = [json.loads(line) for line in wp.debug_probe_path.read_text().splitlines() if line.strip()]
    kinds = {(e["kind"], e.get("function")) for e in recorded}
    assert ("deprecated_function", "barebits_probe_control_fn") in kinds, kinds
    assert ("doing_it_wrong", "barebits_probe_control_wrong") in kinds, kinds
    assert ("deprecated_function", "barebits_probe_unattributed_fn") in kinds, kinds
    assert all(e["trace"] for e in recorded), "probe records carry no stack"

    findings = wp.plugin_debug_findings()
    joined = "\n".join(findings)
    assert "barebits_probe_control_fn" in joined, joined
    assert "barebits_probe_control_wrong" in joined, joined
    # The E_USER_NOTICE went through WP_DEBUG_LOG with the control file's path.
    assert "barebits probe control notice" in joined, joined
    assert "barebits_probe_unattributed_fn" not in joined, joined
