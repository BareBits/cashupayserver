"""WordPress "leave us a review" admin banner.

Once setup is complete, cashupay_admin_notice() swaps the "Configure BareBits"
nag for a dismissible review banner linking to the wordpress.org plugin
search. Dismissal is persisted site-wide through a wp_ajax endpoint
(nonce-gated): each dismissal hides the banner for 30 days, and after three
dismissals it never returns. This test drives the real admin dashboard +
admin-ajax.php over HTTP with an authenticated admin session; time travel and
option inspection go through wp-cli.

Not covered here: the browser-side delegated click listener that fires the
AJAX call when WP core's dismiss (X) button is pressed — that needs a real
browser against wp-admin; the endpoint it calls is covered below.
"""
from __future__ import annotations

import json
import re

import pytest
import requests

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress

REVIEW_COPY = "Enjoying having control of your money with"
REVIEW_LINK = "https://wordpress.org/plugins/search/barebits/"
CONFIGURE_COPY = "Configure BareBits"
OPTION = "cashupay_review_banner"


def _wp_login(wp: WordPressHandle) -> requests.Session:
    """Authenticate as the WordPress admin (see test_wp_onboarding for why the
    redirect is deliberately not followed)."""
    s = requests.Session()
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
        allow_redirects=False,
    )
    assert r.status_code in (302, 303), f"wp-login returned {r.status_code}"
    assert any(c.startswith("wordpress_logged_in") for c in s.cookies.keys())
    return s


def _complete_setup(wp: WordPressHandle) -> None:
    """Initialize the plugin DB and mark setup complete — the banner's
    precondition. Mirrors what finishing the real wizard leaves behind."""
    wp.wp_cli(
        "eval",
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/database.php';"
        "require_once CASHUPAY_PLUGIN_DIR . '/includes/config.php';"
        "Database::ensureExists(); Database::initialize();"
        "Config::set('setup_complete', true);",
    )


def _dashboard(wp: WordPressHandle, session: requests.Session) -> str:
    r = session.get(f"{wp.url}/wp-admin/index.php", timeout=30)
    assert r.status_code == 200, f"dashboard returned {r.status_code}"
    return r.text


def _banner_state(wp: WordPressHandle) -> dict:
    out = wp.wp_cli("option", "get", OPTION, "--format=json", check=False)
    if out.returncode != 0:  # option not set yet
        return {}
    return json.loads(out.stdout.strip().splitlines()[-1])


def _set_banner_state(wp: WordPressHandle, dismissed_at: int, count: int) -> None:
    wp.wp_cli(
        "option", "update", OPTION,
        json.dumps({"dismissed_at": dismissed_at, "count": count}),
        "--format=json",
    )


def _dismiss(wp: WordPressHandle, session: requests.Session, nonce: str) -> requests.Response:
    return session.post(
        f"{wp.url}/wp-admin/admin-ajax.php",
        data={"action": "cashupay_dismiss_review", "nonce": nonce},
        timeout=30,
    )


def test_review_banner_lifecycle(wordpress: WordPressHandle) -> None:
    session = _wp_login(wordpress)

    # Before setup: configure nag, no review banner.
    html = _dashboard(wordpress, session)
    assert CONFIGURE_COPY in html
    assert REVIEW_COPY not in html

    # After setup: review banner with the wordpress.org link, dismissible.
    _complete_setup(wordpress)
    html = _dashboard(wordpress, session)
    assert CONFIGURE_COPY not in html
    assert REVIEW_COPY in html
    assert REVIEW_LINK in html
    m = re.search(r'id="cashupay-review-notice" data-nonce="([^"]+)"', html)
    assert m, "banner should carry the dismiss nonce"
    nonce = m.group(1)

    # A bad nonce is rejected and records nothing.
    bad = _dismiss(wordpress, session, "not-a-nonce")
    assert bad.status_code == 403, f"bad nonce got {bad.status_code}: {bad.text[:100]}"
    assert _banner_state(wordpress) == {}

    # Real dismissal: success, count=1, banner gone on the next load.
    ok = _dismiss(wordpress, session, nonce)
    assert ok.status_code == 200, f"{ok.status_code}: {ok.text[:200]}"
    assert ok.json()["success"] is True
    state = _banner_state(wordpress)
    assert state["count"] == 1
    assert state["dismissed_at"] > 0
    assert REVIEW_COPY not in _dashboard(wordpress, session)

    # 29 days later: still hidden. 31 days later: it returns.
    _set_banner_state(wordpress, state["dismissed_at"] - 29 * 86400, 1)
    assert REVIEW_COPY not in _dashboard(wordpress, session)
    _set_banner_state(wordpress, state["dismissed_at"] - 31 * 86400, 1)
    html = _dashboard(wordpress, session)
    assert REVIEW_COPY in html

    # Two more dismissals reach the permanent cap.
    nonce = re.search(r'id="cashupay-review-notice" data-nonce="([^"]+)"', html).group(1)
    assert _dismiss(wordpress, session, nonce).json()["success"] is True
    _set_banner_state(wordpress, _banner_state(wordpress)["dismissed_at"] - 31 * 86400, 2)
    html = _dashboard(wordpress, session)
    nonce = re.search(r'id="cashupay-review-notice" data-nonce="([^"]+)"', html).group(1)
    assert _dismiss(wordpress, session, nonce).json()["success"] is True
    assert _banner_state(wordpress)["count"] == 3

    # Permanently hidden, even long after the last dismissal.
    _set_banner_state(wordpress, _banner_state(wordpress)["dismissed_at"] - 365 * 86400, 3)
    assert REVIEW_COPY not in _dashboard(wordpress, session)


def test_dismiss_requires_authentication(wordpress: WordPressHandle) -> None:
    """An anonymous POST to the dismiss endpoint must not change state.
    wp_ajax_* actions are only registered for logged-in users, so WordPress
    itself rejects this before the plugin code runs."""
    _complete_setup(wordpress)
    r = requests.post(
        f"{wordpress.url}/wp-admin/admin-ajax.php",
        data={"action": "cashupay_dismiss_review", "nonce": "whatever"},
        timeout=30,
    )
    assert r.status_code in (400, 403), f"anonymous dismiss got {r.status_code}"
    assert _banner_state(wordpress) == {}
