"""Fresh-install directory root must redirect to setup, never 500.

Regression for the pre-setup 500: on a fresh standalone install (no schema
yet), index.php built its redirect via Urls::setup(), which reads DB-backed
config (base_url / url_mode). That query hit the not-yet-created `config`
table and raised an uncaught "no such table: config" -> HTTP 500 on every
visit to the bare directory URL until setup.php had been opened once.

The `payserver` fixture is an un-walked install (the wizard has not run), so
its schema is absent — exactly the state that triggered the bug.
"""
from __future__ import annotations

import requests

from fixtures.payserver import PayserverHandle


def test_fresh_root_redirects_to_setup_without_500(payserver: PayserverHandle) -> None:
    r = requests.get(f"{payserver.url}/", timeout=15, allow_redirects=False)

    assert r.status_code != 500, f"fresh directory root 500'd: {r.text[:400]}"
    assert r.status_code in (301, 302), f"expected a redirect, got {r.status_code}"
    location = r.headers.get("Location", "")
    assert location.rstrip("/").endswith("setup.php"), (
        f"expected redirect to setup.php, got Location: {location!r}"
    )


def test_fresh_root_redirect_is_followable_to_setup(payserver: PayserverHandle) -> None:
    """Following the redirect reaches the setup wizard (HTTP 200), confirming
    the relative Location resolves correctly through the front controller."""
    r = requests.get(f"{payserver.url}/", timeout=15, allow_redirects=True)
    assert r.status_code == 200, f"followed redirect did not reach setup: {r.status_code}"
    assert "setup" in r.url.lower(), f"did not land on setup: {r.url}"
