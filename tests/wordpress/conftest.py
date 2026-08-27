"""WordPress-suite fixtures and helpers.

The plugin under test is thin GPL glue: onboarding either connects an
existing BareBits server by URL or downloads a standalone release and
installs it alongside WordPress. The fixtures here provide:

  - wp_plugin_zip / wordpress_bare  — the built plugin zip on a bare WP
  - standalone_zip / release_server — a local GitHub-releases stand-in
                                      serving the standalone server zip
  - wordpress_install_mode          — WP whose plugin is pointed at that
                                      fixture release server
  - wp_login / onboarding helpers   — drive the wp-admin onboarding flow
                                      over HTTP the way a merchant would
"""
from __future__ import annotations

import re
import uuid
from pathlib import Path
from typing import Iterator

import pytest
import requests

from fixtures.release_server import (
    ReleaseServer,
    ensure_standalone_zip,
    start_release_server,
    stop_release_server,
)
from fixtures.wordpress import (
    WP_ADMIN_PASSWORD,
    WP_ADMIN_USER,
    WordPressHandle,
    ensure_wp_plugin_zip,
    start_wordpress,
    stop_wordpress,
)

TESTS_DIR = Path(__file__).resolve().parent.parent
SESSION_TMP = TESTS_DIR / ".tmp"


@pytest.fixture(scope="session")
def wp_plugin_zip() -> Path:
    """The built WordPress plugin zip (CI artifact, or built locally). Session
    scoped so the build happens at most once."""
    return ensure_wp_plugin_zip()


@pytest.fixture(scope="session")
def standalone_zip() -> Path:
    """The standalone server zip the install-mode flow downloads (CI artifact
    via $CASHUPAY_STANDALONE_ZIP, or built locally once per session)."""
    return ensure_standalone_zip()


@pytest.fixture
def release_server(standalone_zip: Path) -> Iterator[ReleaseServer]:
    """GitHub-releases stand-in serving the standalone zip + SHA256SUMS."""
    rs = start_release_server(standalone_zip)
    yield rs
    stop_release_server(rs)


@pytest.fixture
def wordpress_bare() -> Iterator[WordPressHandle]:
    """Fresh WordPress install with NO cashupay plugin, so a test can install
    the built zip itself. Function-scoped — each test gets its own WP root."""
    workdir = SESSION_TMP / f"wp-bare-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(workdir, install_cashupay=False)
    yield handle
    stop_wordpress(handle)


@pytest.fixture
def wordpress_install_mode(release_server: ReleaseServer) -> Iterator[WordPressHandle]:
    """WordPress + plugin, with the plugin's release downloader pointed at the
    fixture release server — the install-alongside path's test double for
    api.github.com."""
    workdir = SESSION_TMP / f"wp-inst-{uuid.uuid4().hex[:8]}"
    handle = start_wordpress(workdir, release_api_base=release_server.api_base)
    _allow_nonstandard_ports(handle)
    yield handle
    stop_wordpress(handle)


def _allow_nonstandard_ports(wp: WordPressHandle) -> None:
    """Test-only mu-plugin: download_url() (wp_safe_remote_get) rejects URLs
    on non-{80,443,8080} ports unless they match the site's own port. The
    fixture release server lives on a random port, so unsafe-URL rejection is
    disabled for this throwaway install. Production hits https://api.github.com
    and never needs this."""
    mu = wp.wp_root / "wp-content" / "mu-plugins"
    mu.mkdir(parents=True, exist_ok=True)
    # http_request_args (not http_request_reject_unsafe_urls — that filter only
    # sets the DEFAULT, and wp_safe_remote_get overrides it with an explicit
    # true) is the hook that can actually turn validation off per request.
    (mu / "cashupay-test.php").write_text(
        "<?php add_filter('http_request_args', function ($args) {\n"
        "    $args['reject_unsafe_urls'] = false;\n"
        "    return $args;\n"
        "});\n"
    )


# ---------- driving the onboarding flow over HTTP ----------


def wp_login(wp: WordPressHandle) -> requests.Session:
    """Authenticate as the WordPress admin and return the cookie jar.

    Everything the plugin exposes is gated on manage_options, so without this
    the onboarding page just renders the login screen / a 403.
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


def onboarding_page(s: requests.Session, wp: WordPressHandle) -> str:
    """GET the BareBits wp-admin page (onboarding flow or status panel)."""
    r = s.get(f"{wp.url}/wp-admin/admin.php", params={"page": "cashupay"}, timeout=60)
    assert r.status_code == 200, f"onboarding page -> {r.status_code}: {r.text[:300]}"
    return r.text


def page_nonces(body: str) -> dict[str, str]:
    """Map each admin-post form's action to its _wpnonce, scraped from the
    rendered page. Every onboarding form carries a hidden action input and a
    wp_nonce_field."""
    nonces: dict[str, str] = {}
    for form in re.findall(r"<form\b.*?</form>", body, re.S):
        action = re.search(r'name="action" value="([^"]+)"', form)
        nonce = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        if action and nonce:
            nonces[action.group(1)] = nonce.group(1)
    return nonces


def post_onboarding(
    s: requests.Session,
    wp: WordPressHandle,
    action: str,
    data: dict[str, str] | None = None,
    *,
    timeout: int = 300,
) -> str:
    """Submit one onboarding form the way the browser would: scrape the live
    nonce off the page, POST to admin-post.php, follow the redirect back, and
    return the re-rendered page (which carries the flash notice)."""
    body = onboarding_page(s, wp)
    nonces = page_nonces(body)
    assert action in nonces, (
        f"no form for {action!r} on the page (have {sorted(nonces)}); page tail: {body[-1500:]}"
    )
    r = s.post(
        f"{wp.url}/wp-admin/admin-post.php",
        data={"action": action, "_wpnonce": nonces[action], **(data or {})},
        timeout=timeout,
    )
    assert r.status_code == 200, f"{action} -> {r.status_code}: {r.text[:300]}"
    return r.text


def wp_option(wp: WordPressHandle, name: str) -> str:
    """An option's value via wp-cli ('' when unset)."""
    result = wp.wp_cli("option", "get", name, check=False)
    return result.stdout.strip() if result.returncode == 0 else ""
