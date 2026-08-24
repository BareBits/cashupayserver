"""BareBits branding of the BTCPay WooCommerce gateway settings.

cashupay_apply_btcpay_gateway_branding() runs during setup completion and must
set the checkout title, customer message, and gateway logo — but only when
those fields are empty or still carry the stock BTCPay defaults, so a
merchant's manual customization survives re-runs. The logo is sideloaded into
the media library WITHOUT intermediate sizes (the BTCPay plugin fetches the
icon at 'thumbnail' size, and a 150x150 crop would truncate the wordmark).

These tests drive the real plugin PHP via wp-cli eval; they need neither
WooCommerce nor the BTCPay plugin installed, because the branding only touches
wp_options and the media library.
"""
from __future__ import annotations

import json

import pytest

from fixtures.wordpress import WordPressHandle

pytestmark = pytest.mark.wordpress

BAREBITS_TITLE = "BareBits (Bitcoin + Lightning)"
BAREBITS_DESCRIPTION = (
    "CashApp, PayPal, and Venmo are all Bitcoin wallets. "
    "You will be redirected to BareBits to complete this payment."
)
STOCK_TITLE = "BTCPay (Bitcoin, Lightning Network, ...)"
STOCK_DESCRIPTION = "You will be redirected to BTCPay to complete your purchase."

APPLY = (
    "require_once CASHUPAY_PLUGIN_DIR . '/btcpay-integration.php';"
    "cashupay_apply_btcpay_gateway_branding();"
)
APPLY_WITH_DISCOUNT = (
    "require_once CASHUPAY_PLUGIN_DIR . '/btcpay-integration.php';"
    "cashupay_apply_btcpay_gateway_branding(5);"
)
DUMP = "echo json_encode(get_option('woocommerce_btcpaygf_default_settings'));"


def _settings(wp: WordPressHandle) -> dict:
    out = wp.wp_cli("eval", DUMP).stdout.strip().splitlines()[-1]
    data = json.loads(out)
    assert isinstance(data, dict), f"unexpected settings payload: {out!r}"
    return data


def test_branding_applied_on_fresh_settings(wordpress: WordPressHandle) -> None:
    wordpress.wp_cli("eval", APPLY)
    settings = _settings(wordpress)
    assert settings["title"] == BAREBITS_TITLE
    assert settings["description"] == BAREBITS_DESCRIPTION

    media_id = int(settings.get("icon_media_id") or 0)
    assert media_id > 0, f"icon_media_id not set: {settings}"

    # The attachment must resolve to a served PNG whose original (uncropped)
    # file is what wp_get_attachment_image_src() returns at 'thumbnail' size —
    # i.e. no intermediate sizes were generated.
    check = wordpress.wp_cli(
        "eval",
        f"$src = wp_get_attachment_image_src({media_id});"
        f"$meta = wp_get_attachment_metadata({media_id});"
        "echo json_encode(['src' => $src ? $src[0] : null, 'sizes' => $meta['sizes'] ?? null]);",
    ).stdout.strip().splitlines()[-1]
    info = json.loads(check)
    assert info["src"] and info["src"].endswith("barebits-gateway-logo.png"), info
    assert not info["sizes"], f"intermediate sizes must not exist: {info}"

    import requests
    r = requests.get(info["src"], timeout=10)
    assert r.status_code == 200 and r.content[:8] == b"\x89PNG\r\n\x1a\n"


def test_branding_replaces_stock_btcpay_defaults(wordpress: WordPressHandle) -> None:
    seed = json.dumps({"enabled": "yes", "title": STOCK_TITLE, "description": STOCK_DESCRIPTION})
    wordpress.wp_cli(
        "eval",
        f"update_option('woocommerce_btcpaygf_default_settings', json_decode({seed!r}, true));",
    )
    wordpress.wp_cli("eval", APPLY)
    settings = _settings(wordpress)
    assert settings["title"] == BAREBITS_TITLE
    assert settings["description"] == BAREBITS_DESCRIPTION
    assert settings["enabled"] == "yes", "unrelated keys survive"


def test_discount_percent_lands_in_the_checkout_title(wordpress: WordPressHandle) -> None:
    """A wizard-chosen Bitcoin discount is advertised in the gateway title so
    customers see the incentive before picking a payment method."""
    wordpress.wp_cli(
        "eval", "delete_option('woocommerce_btcpaygf_default_settings');"
    )
    wordpress.wp_cli("eval", APPLY_WITH_DISCOUNT)
    settings = _settings(wordpress)
    assert settings["title"] == f"{BAREBITS_TITLE} 5% discount"
    assert settings["description"] == BAREBITS_DESCRIPTION


def test_discount_title_is_write_once_like_the_rest_of_branding(wordpress: WordPressHandle) -> None:
    """The branding pass is deliberately write-once: a title we already wrote
    (with or without a discount) is neither re-suffixed nor updated when the
    percentage changes later."""
    wordpress.wp_cli(
        "eval", "delete_option('woocommerce_btcpaygf_default_settings');"
    )
    wordpress.wp_cli("eval", APPLY)
    wordpress.wp_cli("eval", APPLY_WITH_DISCOUNT)
    settings = _settings(wordpress)
    assert settings["title"] == BAREBITS_TITLE, (
        "an already-written title must not gain a discount suffix on re-run"
    )


def test_branding_never_clobbers_merchant_customization(wordpress: WordPressHandle) -> None:
    seed = json.dumps({
        "title": "My Custom Gateway",
        "description": "Pay me in corn.",
        "icon_media_id": "424242",
    })
    wordpress.wp_cli(
        "eval",
        f"update_option('woocommerce_btcpaygf_default_settings', json_decode({seed!r}, true));",
    )
    wordpress.wp_cli("eval", APPLY)
    wordpress.wp_cli("eval", APPLY_WITH_DISCOUNT)
    settings = _settings(wordpress)
    assert settings["title"] == "My Custom Gateway"
    assert settings["description"] == "Pay me in corn."
    assert settings["icon_media_id"] == "424242"


def test_branding_is_idempotent_reuses_attachment(wordpress: WordPressHandle) -> None:
    wordpress.wp_cli("eval", APPLY)
    first = _settings(wordpress)["icon_media_id"]
    wordpress.wp_cli("eval", APPLY)
    second = _settings(wordpress)["icon_media_id"]
    assert first == second, "re-running must reuse the sideloaded attachment"

    count = wordpress.wp_cli(
        "eval",
        "echo count(get_posts(['post_type' => 'attachment', 'numberposts' => -1,"
        " 's' => 'BareBits payment gateway logo']));",
    ).stdout.strip().splitlines()[-1]
    assert count == "1", f"expected exactly one logo attachment, got {count}"
