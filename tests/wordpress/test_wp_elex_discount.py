"""Bitcoin-discount auto-configuration against a real WooCommerce install.

The onboarding flow's discount question only saves a percentage; the actual
work — activate the ELEX Discount Per Payment Method plugin and create the
discount rule for the BTCPay gateway — happens in
cashupay_ensure_elex_discount when the wiring finishes. This exercises that
function against real WordPress + WooCommerce + the real pinned ELEX plugin.

The plugin is *staged* (present in wp-content/plugins, not active) rather than
freshly downloaded, so the test covers the activate+configure path without a
live wordpress.org install mid-test; the fresh-download path shares all code
from activation onward and its install plumbing is the same
plugins_api/Plugin_Upgrader sequence already shipping in
cashupay_install_btcpay_plugin.

The ELEX wiring never talks to the BareBits server (it only touches WP
plugins and options), so the cashupay_server_url option points at a dead
address on purpose — reaching for it would be a bug this setup catches.

Deliberately NOT covered here: the checkout-cart total actually shrinking by
the discount. That is the ELEX plugin's own behaviour (a negative fee added in
woocommerce_cart_calculate_fees from its session's chosen payment method), and
driving it would test third-party code through a headless Store API session it
was not built for. Our seam ends at the option row the plugin documents and
reads. The full onboarding journey that runs this wiring (cashupay_finish) is
covered by the onboarding-mode modules.
"""
from __future__ import annotations

import json

import pytest

from fixtures.wordpress import WordPressHandle, stage_elex_discount_plugin

pytestmark = pytest.mark.wordpress

GATEWAY_ID = "btcpaygf_default"
ELEX_OPTION = "elex_discount_per_payment_method_options"
ELEX_PLUGIN = "elex-discount-per-payment-method"
DEAD_SERVER_URL = "http://127.0.0.1:1/nonexistent"


def _setup(wp: WordPressHandle) -> None:
    """The state the wiring runs in: ELEX staged-not-active, and the minimal
    plugin options a connected shop would carry."""
    stage_elex_discount_plugin(wp)
    wp.wp_cli("option", "update", "cashupay_server_url", DEAD_SERVER_URL, check=False)


def _ensure(wp: WordPressHandle, percent: int) -> dict:
    result = wp.wp_cli(
        "eval", f"echo json_encode(cashupay_ensure_elex_discount({percent}));"
    )
    return json.loads(result.stdout.strip().splitlines()[-1])


def _active_plugins(wp: WordPressHandle) -> list[str]:
    result = wp.wp_cli("plugin", "list", "--status=active", "--field=name")
    return result.stdout.split()


def _rules(wp: WordPressHandle) -> list | None:
    """The ELEX option as stored, or None when absent."""
    snippet = f"echo json_encode(get_option({ELEX_OPTION!r}, null));"
    result = wp.wp_cli("eval", snippet)
    return json.loads(result.stdout.strip().splitlines()[-1])


def test_ensure_elex_discount_lifecycle(woocommerce) -> None:
    wp, _info = woocommerce
    _setup(wp)

    # 0%: the merchant declined — nothing may be activated or written. This is
    # the spec's "completely optional" half: a 0% answer leaves no trace.
    res = _ensure(wp, 0)
    assert res["status"] == "skipped", res
    assert ELEX_PLUGIN not in _active_plugins(wp), (
        "declining the discount must not activate the ELEX plugin"
    )
    assert _rules(wp) is None, "declining the discount must not write the ELEX option"

    # 2%: activate + create the rule.
    res = _ensure(wp, 2)
    assert res == {"status": "ready", "auto_installed": False, "rule": "added"}, res
    assert ELEX_PLUGIN in _active_plugins(wp), "the staged plugin should now be active"
    rules = _rules(wp)
    assert isinstance(rules, list) and len(rules) == 1, rules
    rule = rules[0]
    assert rule["id"] == GATEWAY_ID, rule
    assert rule["discount_type"] == "percentage", rule
    assert rule["value"] == "2", rule
    assert rule["checkbox_value"] == "yes", rule
    assert rule["row_label"] == "Bitcoin discount", rule
    # No gateway settings written yet, so the rule's display name falls back to
    # the same title the branding pass would write.
    assert rule["type"] == "BareBits (Bitcoin + Lightning)", rule

    # Re-run with a different remembered value: the existing rule wins —
    # re-running the wiring must never rewrite a live rule.
    res = _ensure(wp, 5)
    assert res == {"status": "ready", "auto_installed": False, "rule": "kept_existing"}, res
    assert _rules(wp)[0]["value"] == "2", "a re-run must not change the live rule"
    assert len(_rules(wp)) == 1, "and must not duplicate it"

    # A merchant's own pre-existing rules (ours removed, theirs installed):
    # theirs for the gateway is kept verbatim, unrelated gateways untouched.
    merchant = [
        {"id": "cheque", "type": "Check payments", "discount_type": "percentage",
         "value": "5", "row_label": "Check discount", "checkbox_value": "yes"},
        {"id": GATEWAY_ID, "type": "BTCPay", "discount_type": "fixed",
         "value": "7", "row_label": "My own label", "checkbox_value": "no"},
    ]
    wp.wp_cli(
        "option", "update", ELEX_OPTION, json.dumps(merchant), "--format=json"
    )
    res = _ensure(wp, 3)
    assert res == {"status": "ready", "auto_installed": False, "rule": "kept_existing"}, res
    assert _rules(wp) == merchant, "merchant-authored rules must survive byte-for-byte"


def test_rule_display_name_uses_the_branded_gateway_title(woocommerce) -> None:
    """When the gateway settings already carry a title (the branding pass, or
    the merchant's own), the ELEX rule's read-only display name uses it."""
    wp, _info = woocommerce
    _setup(wp)

    wp.wp_cli(
        "eval",
        "update_option('woocommerce_btcpaygf_default_settings',"
        " ['title' => 'My Custom Bitcoin Gateway']);",
    )
    res = _ensure(wp, 4)
    assert res == {"status": "ready", "auto_installed": False, "rule": "added"}, res
    rules = _rules(wp)
    assert rules and rules[0]["type"] == "My Custom Bitcoin Gateway", rules
    assert rules[0]["value"] == "4", rules
