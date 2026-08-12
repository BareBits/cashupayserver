"""Bitcoin-discount auto-configuration against a real WooCommerce install.

The onboarding wizard's discount screen only saves a percentage; the actual
work — activate the ELEX Discount Per Payment Method plugin and create the
discount rule for the BTCPay gateway — happens in
cashupay_ensure_elex_discount on the completion screen. This exercises that
function against real WordPress + WooCommerce + the real pinned ELEX plugin.

The plugin is *staged* (present in wp-content/plugins, not active) rather than
freshly downloaded, so the test covers the activate+configure path without a
live wordpress.org install mid-test; the fresh-download path shares all code
from activation onward and its install plumbing is the same
plugins_api/Plugin_Upgrader sequence already shipping in
cashupay_install_btcpay_plugin.

Deliberately NOT covered here: the checkout-cart total actually shrinking by
the discount. That is the ELEX plugin's own behaviour (a negative fee added in
woocommerce_cart_calculate_fees from its session's chosen payment method), and
driving it would test third-party code through a headless Store API session it
was not built for. Our seam ends at the option row the plugin documents and
reads.
"""
from __future__ import annotations

import json

import pytest

from fixtures.setup_helpers import SetupWizard, wizard_error, wizard_heading
from fixtures.wordpress import WordPressHandle, stage_elex_discount_plugin

# Shared preconditions for driving the wizard over HTTP in WordPress mode;
# defined next to the tests that established them.
from wordpress.test_wp_onboarding import _flush_rewrites, _wp_login

pytestmark = pytest.mark.wordpress

GATEWAY_ID = "btcpaygf_default"
ELEX_OPTION = "elex_discount_per_payment_method_options"
ELEX_PLUGIN = "elex-discount-per-payment-method"


def _ensure(wp: WordPressHandle, percent: int) -> dict:
    snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/elex-discount.php';
echo json_encode(cashupay_ensure_elex_discount({percent}));
"""
    result = wp.wp_cli("eval", snippet)
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
    stage_elex_discount_plugin(wp)

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

    # Re-render with a different remembered value: the existing rule wins —
    # completion-screen refreshes must never rewrite a live rule.
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


def test_completion_screen_applies_the_wizard_discount(woocommerce) -> None:
    """The whole journey the merchant actually takes: answer the wizard's
    discount screen, reach the completion screen, and leave with WooCommerce
    wired AND the discount rule live — plus the success copy that tells them
    so. This is the seam the lifecycle test above cannot see: the completion
    screen's glue that reads the saved percentage, gates on the gateway wiring
    being ready, and runs cashupay_ensure_elex_discount during render."""
    wp, _info = woocommerce
    stage_elex_discount_plugin(wp)
    _flush_rewrites(wp)
    session = _wp_login(wp)

    w = SetupWizard(wp.url, setup_path="/cashupay-setup/", session=session)
    w.post(step="terms", terms_legal="1", terms_warranty="1", terms_fee="1")
    w.post(step="security", security_acknowledged="1")
    w.post(step="store", store_name="Discount Store")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    body = w.post(step="mints", mints_enabled="0")
    assert wizard_heading(body) == "Offer a discount to customers paying in Bitcoin?", (
        wizard_heading(body)
    )
    # With the WP-cron loopback working (CI's WordPress fixture passes the
    # self-test), the wizard skips the manual-cron screen, so the discount
    # POST's own response is the completion screen. That render runs the
    # WooCommerce wiring and, once that reports ready, the discount
    # follow-through.
    body = w.post(step="discount", btc_discount_percent="3")
    assert wizard_error(body) is None, wizard_error(body)
    assert "WooCommerce is wired up" in body, body[:500]
    assert "3% Bitcoin discount is live at checkout" in body, (
        "the completion screen must confirm the discount"
    )

    assert ELEX_PLUGIN in _active_plugins(wp), (
        "the completion screen should have activated the staged ELEX plugin"
    )
    rules = _rules(wp)
    assert rules and rules[0]["id"] == GATEWAY_ID and rules[0]["value"] == "3", rules

    # A refresh of the completion screen must not disturb the rule. (With
    # everything wired the session was cleared, so the refreshed page renders
    # the generic completion screen — no re-run, no duplicate. The tail-post
    # guard admits this post-completion POST even though the cron screen was
    # skipped on the way through.)
    body = w.post(step="cron")
    assert wizard_error(body) is None, wizard_error(body)
    assert len(_rules(wp)) == 1, "a completion-screen refresh must not duplicate the rule"
    assert _rules(wp)[0]["value"] == "3", "nor change it"
