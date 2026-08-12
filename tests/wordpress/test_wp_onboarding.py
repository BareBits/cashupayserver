"""Onboarding wizard under WordPress mode.

WordPress mode is the same setup.php, but two things differ enough to break it
independently of the standalone path:

  * There is no BareBits credential. Auth::isLoggedIn/isAdmin delegate to
    current_user_can('manage_options') and Auth::currentUser() returns null by
    design, so the wizard skips the password screen entirely.
  * The completion screen renders WordPress-only branches — ABSPATH includes,
    is_plugin_active(), admin_url() — that never execute standalone.

Both are exercised here by driving the real wizard inside a real WordPress
install. The rest of the choice matrix is mode-independent and covered in
tests/e2e/test_setup_onboarding_matrix.py.
"""
from __future__ import annotations

import re

import pytest
import requests

from fixtures.setup_helpers import MAINNET_XPUB, SetupWizard, wizard_error, wizard_heading
from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress

SETUP_PATH = "/cashupay-setup/"


def _flush_rewrites(wp: WordPressHandle) -> None:
    """The wizard is reachable via a custom rewrite rule, which WordPress only
    honours once permalinks are non-default."""
    wp.wp_cli("rewrite", "structure", "/%postname%/", "--hard")
    wp.wp_cli("rewrite", "flush", "--hard")


def _wp_login(wp: WordPressHandle) -> requests.Session:
    """Authenticate as the WordPress admin and return the cookie jar.

    Everything the plugin exposes is gated on manage_options, so without this
    the wizard just renders wp_die('Access denied').
    """
    s = requests.Session()
    # WP refuses to set auth cookies unless it can see its own test cookie.
    s.cookies.set("wordpress_test_cookie", "WP+Cookie+check", domain="127.0.0.1")
    r = s.post(
        f"{wp.url}/wp-login.php",
        data={
            "log": WP_ADMIN_USER,
            "pwd": WP_ADMIN_PASSWORD,
            "wp-submit": "Log In",
            "redirect_to": f"{wp.url}/wp-admin/",
            "testcookie": "1",
        },
        timeout=30,
        # Deliberately not following: the fixture's php -S router cannot serve
        # /wp-admin/ (a directory), so chasing the post-login redirect bounces
        # between the front controller and the login page until requests gives
        # up. The auth cookies are already on the 302, which is all we need.
        allow_redirects=False,
    )
    assert r.status_code in (302, 303), f"wp-login returned {r.status_code}: {r.text[:200]}"
    assert any(c.startswith("wordpress_logged_in") for c in s.cookies.keys()), (
        f"no WordPress auth cookie; jar={list(s.cookies.keys())}"
    )
    return s


def test_wizard_skips_the_password_screen_and_completes(wp_ready) -> None:
    """The whole point of WordPress mode: no BareBits password is created,
    because the WordPress admin already is the admin. The security ack must
    therefore land straight on the store screen, and the flow must still
    complete through the WordPress-only completion screen."""
    wp, session = wp_ready
    w = SetupWizard(wp.url, setup_path=SETUP_PATH, session=session)

    body = w.get("security")
    assert wizard_heading(body) == "Quick safety check", wizard_heading(body)
    # 10 screens, not the standalone 10: WordPress drops the password screen
    # but gains the Bitcoin-discount screen, and zero-conf has not joined yet
    # because no on-chain rail exists.
    assert "of 10" in body, "WordPress with no on-chain rail should be 10 screens"

    body = w.post(step="security", security_acknowledged="1")
    assert wizard_heading(body) == "Let's name your store", (
        f"WordPress mode must skip the password screen, landed on {wizard_heading(body)!r}"
    )

    body = w.post(step="store", store_name="WP Wizard Store")
    assert wizard_error(body) is None, wizard_error(body)
    body = w.post(
        step="onchain", onchain_action="save",
        onchain_address_mode="xpub", onchain_xpub=MAINNET_XPUB,
    )
    assert wizard_heading(body) == "Zero-conf payments", wizard_heading(body)
    body = w.post(step="zeroconf", zero_conf="1")
    body = w.post(step="lightning", lightning_action="save",
                  lightning_address="wpmerchant@strike.me")
    body = w.post(step="swaps", swaps_enabled="1")
    assert wizard_error(body) is None, wizard_error(body)
    body = w.post(step="mints", mints_enabled="0")
    assert wizard_error(body) is None, wizard_error(body)
    # WordPress-only screen: the Bitcoin checkout discount. It renders after
    # setup_complete flips (the mints screen set it), which is exactly the
    # path that breaks if the step is missing from POST_COMPLETION.
    assert wizard_heading(body) == "Offer a discount to customers paying in Bitcoin?", (
        f"WordPress mode must show the discount screen after mints, landed on {wizard_heading(body)!r}"
    )
    assert "Elex Discount Per Payment Method" in body, (
        "the discount screen must name the plugin it would install"
    )

    # An out-of-range answer re-renders the screen with an error...
    body = w.post(step="discount", btc_discount_percent="101")
    assert wizard_error(body) is not None, "101% must be rejected"
    # ...and a valid whole number skips the cron screen entirely: WP-cron
    # drives the full cron.php via loopback (wordpress/cron-integration.php),
    # and the wizard's live self-test passes against this fixture, so the
    # manual-crontab instructions are advisory noise here. The completion
    # screen is where the WordPress-only branches live.
    body = w.post(step="discount", btc_discount_percent="2")
    assert wizard_error(body) is None, wizard_error(body)
    assert wizard_heading(body).startswith("You"), (
        f"working WP-cron loopback must skip the cron screen, landed on {wizard_heading(body)!r}"
    )
    assert "no crontab entry needed" in body, (
        "the completion screen should say WordPress handles background jobs"
    )
    assert "WooCommerce" in body, "the WordPress completion screen should offer WooCommerce wiring"
    assert "Back to WordPress Dashboard" in body
    # In WordPress mode the manual e-commerce integration sections are dropped —
    # BareBits wires WooCommerce up itself, so there is nothing for the merchant
    # to copy or pair by hand.
    assert "Your Server URL" not in body, "the server-URL section must be hidden in WordPress mode"
    assert "Connect Your E-commerce" not in body, "the manual pairing section must be hidden in WordPress mode"

    with wp.db() as db:
        assert db.execute(
            "SELECT value FROM config WHERE key = 'setup_complete'"
        ).fetchone()[0] == "true"
        # The discount answer is saved site-wide; the ELEX plugin install is
        # deferred to the completion screen and (correctly) did not run here —
        # this bare WordPress has no WooCommerce for it to act on.
        assert db.execute(
            "SELECT value FROM config WHERE key = 'wp_btc_discount_percent'"
        ).fetchone()[0] == "2"
        store = db.execute("SELECT * FROM stores WHERE name = 'WP Wizard Store'").fetchone()
        assert store is not None
        assert store["onchain_xpub"] == MAINNET_XPUB
        assert store["onchain_min_confs"] == 0
        assert store["swaps_enabled"] == 1
        assert store["strict_no_mint_fallback"] == 1, "declining mints pins strict mode here too"
        assert store["auto_melt_enabled"] == 1
        # No BareBits admin row is ever created in WordPress mode.
        assert db.execute("SELECT COUNT(*) FROM users").fetchone()[0] == 0

    # Loopback-hostile hosts must still get the manual cron instructions:
    # without a cron key the self-test cannot pass, and the tail-post guard
    # lets a completed install re-enter the post-completion screens. The cron
    # screen re-seeds the key it displays, so the mechanism heals itself.
    with wp.db() as db:
        db.execute("DELETE FROM config WHERE key = 'cron_key'")
    body = w.post(step="discount", btc_discount_percent="2")
    assert wizard_heading(body) == "Enable cron", (
        f"a failing self-test must keep the manual cron screen, landed on {wizard_heading(body)!r}"
    )
    with wp.db() as db:
        assert db.execute(
            "SELECT value FROM config WHERE key = 'cron_key'"
        ).fetchone() is not None, "the cron screen re-seeds a missing key"
    body = w.post(step="cron")
    assert wizard_heading(body).startswith("You"), wizard_heading(body)


def test_wizard_refuses_an_unauthenticated_visitor(wp_ready) -> None:
    """The wizard writes store configuration, so it must be admin-gated by
    WordPress rather than open to anyone who finds the URL."""
    wp, _ = wp_ready
    anon = requests.Session()
    r = anon.get(f"{wp.url}{SETUP_PATH}", timeout=30)
    assert "Quick safety check" not in r.text, "the wizard must not render for a logged-out visitor"
    assert "Access denied" in r.text or r.status_code in (401, 403), (
        f"expected an access refusal, got {r.status_code}: {r.text[:200]}"
    )


def _csrf_token(session: requests.Session, admin_url: str) -> str:
    """Scrape the CSRF token the admin page embeds. Auth still uses a PHP
    session for this under WordPress, carried on the same cookie jar."""
    r = session.get(admin_url, timeout=30)
    m = re.search(r'name="csrf-token"\s+content="([^"]+)"', r.text)
    assert m, f"no csrf-token meta on the admin page: {r.text[:300]}"
    return m.group(1)


def _reveal(session, admin_url: str, store_id: str, password: str) -> requests.Response:
    return session.post(
        admin_url,
        data={"action": "reveal_store_seed", "store_id": store_id, "password": password},
        headers={"X-CSRF-Token": _csrf_token(session, admin_url)},
        timeout=30,
    )


def test_seed_reveal_accepts_the_wordpress_password(wp_ready) -> None:
    """Auth::currentUser() is null in WordPress mode, so a reveal endpoint that
    re-authenticated against the BareBits users table could never succeed here
    — it would 401 forever, leaving WP operators with no way to recover a seed
    the wizard generated silently."""
    wp, session = wp_ready
    seed = (
        "abandon abandon abandon abandon abandon abandon "
        "abandon abandon abandon abandon abandon about"
    )
    store_id = _seed_store_with_wallet(wp, seed)

    # WordPress routes the panel at /cashupay-admin (see
    # wordpress/rewrite-rules.php); /cashupay is the API prefix.
    admin_url = f"{wp.url}/cashupay-admin"

    ok = _reveal(session, admin_url, store_id, WP_ADMIN_PASSWORD)
    assert "seedPhrase" in ok.json(), f"{ok.status_code}: {ok.text[:500]}"
    assert ok.json()["seedPhrase"] == seed

    wrong = _reveal(session, admin_url, store_id, "not-the-wordpress-password")
    assert wrong.status_code == 401, f"{wrong.status_code}: {wrong.text[:300]}"
    assert wrong.json().get("error") == "Password is incorrect", wrong.text[:300]
    assert seed not in wrong.text

    anon = requests.Session()
    denied = anon.post(
        admin_url,
        data={
            "action": "reveal_store_seed",
            "store_id": store_id,
            "password": WP_ADMIN_PASSWORD,
        },
        timeout=30,
    )
    assert seed not in denied.text, "an unauthenticated caller must never see the seed"


def _seed_store_with_wallet(wp: WordPressHandle, seed: str) -> str:
    """Create a configured store directly, so the reveal test does not depend
    on a live mint."""
    snippet = f"""
require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';
require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';
Database::initialize();
$storeId = Database::generateId('store');
Database::insert('stores', [
    'id' => $storeId,
    'name' => 'WP Seeded Store',
    'mint_url' => 'https://mint.example',
    'mint_unit' => 'sat',
    'seed_phrase' => {seed!r},
    'created_at' => Database::timestamp(),
]);
Config::set('setup_complete', true);
echo $storeId;
"""
    result = wp.wp_cli("eval", snippet)
    store_id = [ln for ln in result.stdout.strip().splitlines() if ln.startswith("store_")][-1]
    return store_id.strip()


@pytest.fixture
def wp_ready(wordpress: WordPressHandle):
    """A WordPress install with permalinks flushed and an authenticated admin
    session — the two preconditions every test here shares."""
    _flush_rewrites(wordpress)
    return wordpress, _wp_login(wordpress)


def test_error_statuses_are_not_flattened_to_200(wp_ready) -> None:
    """WordPress queues its own "HTTP/1.1 200 OK" status line before handing
    the request to the plugin, and http_response_code() does not replace it —
    so every admin error used to reach the browser as a 200. The admin panel
    branches on `response.ok`, which meant rejected CSRF tokens and failed
    validation were being rendered as successes. cashupay_status() re-emits via
    status_header() so the real code survives.
    """
    wp, session = wp_ready
    _seed_store_with_wallet(wp, "abandon " * 11 + "about")
    admin_url = f"{wp.url}/cashupay-admin"

    # No CSRF token at all -> 403, not 200.
    no_csrf = session.post(
        admin_url,
        data={"action": "reveal_store_seed", "store_id": "whatever", "password": "x"},
        timeout=30,
    )
    assert no_csrf.status_code == 403, f"{no_csrf.status_code}: {no_csrf.text[:300]}"
    assert no_csrf.json()["error"] == "Invalid CSRF token"

    # Unauthenticated -> 401, not 200.
    anon = requests.Session()
    unauth = anon.post(admin_url, data={"action": "get_dashboard_data"}, timeout=30)
    assert unauth.status_code in (401, 403), f"{unauth.status_code}: {unauth.text[:300]}"
