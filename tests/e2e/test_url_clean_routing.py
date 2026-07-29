"""Clean-URL routing: extension-less paths must resolve through the front
controller.

"Clean URL mode" (url_mode = 'clean') has the app emit pretty, extension-less
page URLs — /admin, /setup, /pay/{store}, /payment/{id}, /health — instead of
the .php filenames. Those only work if the front controller actually dispatches
them. This test drives a live server and asserts each pretty route resolves to
its handler rather than 404'ing.

The `payserver` fixture runs `php -S <wrapper-requiring-router.php>`, i.e.
router.php in rewrite mode, so every request flows through the same front
controller the Apache `.htaccess` catch-all forwards to in a real deployment.
router.php returns a hard 404 for any path that matches no route, so
"status is not 404" is a precise signal that the extension-less route resolved.

(The Apache `.htaccess` catch-all rewrite itself is server-config, not
exercisable under php -S; router.php's dispatch — which it forwards to — is
what this test covers, alongside the Urls:: generation covered by
tests/php/test_urls_clean_mode.php.)
"""
from __future__ import annotations

import requests

from fixtures.payserver import PayserverHandle


def test_extensionless_health_route_resolves(payserver: PayserverHandle) -> None:
    """/health (no .php) must reach health.php, not 404.

    health.php is cron-key gated, so unauthenticated it answers 403 — the point
    is only that the pretty path routed to the handler. A 404 would mean the
    front controller has no extension-less /health route (the very thing the
    url-mode probe relies on to detect clean mode)."""
    r = requests.get(f"{payserver.url}/health", timeout=15, allow_redirects=False)
    assert r.status_code != 404, "extension-less /health did not route to health.php"
    assert r.status_code == 403, (
        f"expected cron-key-gated 403 from /health, got {r.status_code}: {r.text[:200]}"
    )


def test_extensionless_setup_route_resolves(payserver: PayserverHandle) -> None:
    """/setup (no .php) must reach the setup wizard (HTTP 200)."""
    r = requests.get(f"{payserver.url}/setup", timeout=15, allow_redirects=True)
    assert r.status_code == 200, f"extension-less /setup did not resolve: {r.status_code}"
    assert "setup" in r.url.lower(), f"did not land on the setup wizard: {r.url}"


def test_extensionless_admin_route_resolves(payserver: PayserverHandle) -> None:
    """/admin (no .php) must reach admin.php and not 404.

    On this un-walked install admin.php sees setup is incomplete and redirects
    to the setup wizard, so we assert the pretty path *routed into the app*
    (a redirect, never router.php's hard 404) rather than pinning the landing
    page — post-setup it would instead canonicalize to /admin/dashboard."""
    r = requests.get(f"{payserver.url}/admin", timeout=15, allow_redirects=False)
    assert r.status_code != 404, "extension-less /admin did not route to admin.php"
    assert r.status_code in (200, 302), (
        f"unexpected status from /admin: {r.status_code}: {r.text[:200]}"
    )


def test_payment_method_logo_served_through_front_controller(
    payserver: PayserverHandle,
) -> None:
    """/images/payment-methods/*.png must serve the real image, not 404.

    The clean /payment/{id} page links its "Pay with …" wallet logos with a
    base-rooted /images/payment-methods/... URL. Those static files must resolve
    through the front controller (the router serves /images/* the same way it
    serves /assets/*); a 404 here is exactly the blank-logo regression."""
    r = requests.get(
        f"{payserver.url}/images/payment-methods/strike.png",
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 200, (
        f"payment-method logo did not resolve: {r.status_code}: {r.text[:120]}"
    )
    assert r.headers.get("Content-Type", "").startswith("image/"), (
        f"expected an image content-type, got {r.headers.get('Content-Type')!r}"
    )


def test_api_reachable_at_bare_base(payserver: PayserverHandle) -> None:
    """The BTCPay-compat API base must answer at the bare /api/v1 path (this is
    what 'clean' and 'direct' modes both advertise as the server URL)."""
    r = requests.get(f"{payserver.url}/api/v1/server/info", timeout=15, allow_redirects=False)
    # 200 once configured, 503 before setup completes — both mean the route
    # resolved. Never 404.
    assert r.status_code in (200, 503), (
        f"/api/v1/server/info did not resolve: {r.status_code}: {r.text[:200]}"
    )
