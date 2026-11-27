"""Driving a real WooCommerce checkout against the BTCPay Greenfield gateway.

A guest buyer goes through the WooCommerce Store API (the headless form of
the blocks checkout) and pays with the BTCPay gateway, which creates the
invoice on the BareBits server the plugin wired it to. Shared by the WordPress
checkout tier (tests/wordpress/test_wp_woocommerce_checkout.py and friends)
and the upgrade tier, which must prove that an order still gets paid before,
during and after a plugin/server upgrade.
"""
from __future__ import annotations

import time
from decimal import ROUND_HALF_UP, Decimal

import requests

from .wordpress import WordPressHandle

GATEWAY_ID = "btcpaygf_default"

# WooCommerce rounds ORDER totals to this many decimals regardless of the
# shop's price decimals: since WooCommerce 10.1 ("Restore support for float
# based quantities", #58693) NumberUtil::round() first normalises every value
# to the fixed WC_ROUNDING_PRECISION constant (6), so WC_Abstract_Order::
# calculate_totals() — which the blocks checkout / Store API run on every
# order it builds — rounds an 8-decimal BTC shop's total to 100-sat steps,
# half up. Line items and fee items keep their exact amounts; the cart totals
# the shopper sees at checkout are exact too (integer-precision cart math).
# BareBits deliberately invoices whatever total WooCommerce hands the gateway
# (operator decision 2026-11-26), so the tests assert against this rounding
# rather than steering WooCommerce's math.
WC_ORDER_TOTAL_DECIMALS = 6


def woocommerce_order_total(sats: int) -> Decimal:
    """The order total WooCommerce stores for an exact `sats` cart total on
    an 8-decimal BTC shop — see WC_ORDER_TOTAL_DECIMALS. 1462 -> 0.000015,
    2925 -> 0.000029, 1500 -> 0.000015."""
    exact = Decimal(sats).scaleb(-8)
    return exact.quantize(Decimal(1).scaleb(-WC_ORDER_TOTAL_DECIMALS), rounding=ROUND_HALF_UP)


def store_api_url(wp: WordPressHandle, path: str) -> str:
    """A WooCommerce Store API endpoint in the ?rest_route= query form — the
    one URL shape every host serves. The pretty /wp-json route needs working
    rewrites: on the apache hostile shape (AllowOverride None, no .htaccess)
    it plain 404s, which is exactly how WordPress behaves on such hosts —
    they run plain permalinks and WordPress itself emits ?rest_route= URLs.
    The buyer browser journey still exercises whatever URL shape the checkout
    page's own JS uses on a friendly host."""
    return f"{wp.url}/?rest_route=/wc/store/v1{path}"


def flush_rewrites(wp: WordPressHandle) -> None:
    """WooCommerce's Store API needs pretty permalinks active."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


BILLING_ADDRESS = {
    "first_name": "Sat", "last_name": "Oshi",
    "address_1": "1 Genesis Block", "city": "Cypherpunk",
    "state": "CA", "postcode": "94016", "country": "US",
    "email": "buyer@example.test", "phone": "5551234567",
}


class StoreApiSession:
    """A guest Store API session: one requests.Session plus the Nonce +
    Cart-Token header dance that carries the cart across the stateless
    requests. The priming GET issues both; every later response may rotate
    them."""

    def __init__(self, wp: WordPressHandle):
        self.wp = wp
        self.s = requests.Session()
        r = self.s.get(store_api_url(wp, "/cart"), timeout=30)
        r.raise_for_status()
        self.nonce = r.headers.get("Nonce") or r.headers.get("X-WC-Store-API-Nonce")
        self.cart_token = r.headers.get("Cart-Token")

    def _headers(self) -> dict:
        h = {}
        if self.nonce:
            h["Nonce"] = self.nonce
        if self.cart_token:
            h["Cart-Token"] = self.cart_token
        return h

    def post(self, path: str, payload: dict, timeout: int = 30) -> requests.Response:
        r = self.s.post(
            store_api_url(self.wp, path), json=payload, headers=self._headers(),
            timeout=timeout,
        )
        self.nonce = r.headers.get("Nonce", self.nonce)
        self.cart_token = r.headers.get("Cart-Token", self.cart_token)
        return r

    def add_item(self, product_id: int, quantity: int = 1) -> None:
        r = self.post("/cart/add-item", {"id": product_id, "quantity": quantity})
        assert r.status_code in (200, 201), f"add-item failed: {r.status_code} {r.text}"

    def select_payment_method(self, method: str) -> dict:
        """What the blocks checkout's discount script does on a payment-method
        change: POST the selection through the cart/extensions endpoint, which
        runs the plugin's update callback and returns the recalculated cart."""
        r = self.post(
            "/cart/extensions",
            {"namespace": "barebits-discount", "data": {"payment_method": method}},
        )
        assert r.status_code == 200, f"cart/extensions failed: {r.status_code} {r.text}"
        return r.json()

    def checkout(self, payment_method: str) -> requests.Response:
        return self.post(
            "/checkout",
            {"billing_address": dict(BILLING_ADDRESS), "payment_method": payment_method},
            timeout=60,
        )


def checkout_response(wp: WordPressHandle, product_id: int) -> requests.Response:
    """The raw Store API checkout exchange behind place_order, for tests that
    expect the payment step to fail."""
    session = StoreApiSession(wp)
    session.add_item(product_id)
    return session.checkout(GATEWAY_ID)


def place_order(wp: WordPressHandle, product_id: int) -> dict:
    """Drive a real guest checkout through the WooCommerce Store API, selecting
    the BTCPay gateway. Returns the checkout response JSON (order_id, status,
    payment_result)."""
    r = checkout_response(wp, product_id)
    assert r.status_code in (200, 201), f"checkout failed: {r.status_code} {r.text}"
    return r.json()


def order_field(wp: WordPressHandle, order_id: int, method: str) -> str:
    """Read a single field off a WooCommerce order via wp-cli."""
    snippet = f"$o = wc_get_order({order_id}); echo $o ? (string)$o->{method} : 'NO_ORDER';"
    return wp.wp_cli("eval", snippet).stdout.strip().splitlines()[-1].strip()


def await_settled(server_url: str, store_id: str, api_token: str, invoice_id: str) -> None:
    """Poll the invoice on the payserver until it is Settled (the GET drives
    Invoice::pollSingleQuote, which is what flips the state)."""
    url = f"{server_url}/api/v1/stores/{store_id}/invoices/{invoice_id}"
    auth = {"Authorization": f"token {api_token}"}
    deadline = time.monotonic() + 30
    last = None
    while time.monotonic() < deadline:
        r = requests.get(url, headers=auth, timeout=10)
        r.raise_for_status()
        last = r.json()
        if last.get("status") == "Settled":
            return
        time.sleep(0.5)
    raise AssertionError(
        f"BareBits invoice {invoice_id} never settled; last status "
        f"{(last or {}).get('status')!r}"
    )
