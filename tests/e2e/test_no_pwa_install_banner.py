"""The admin page must not be installable as a PWA.

Regression for the Android "install app" banner: admin.php linked a web-app
manifest (manifest.json with display: standalone), so Chrome on Android showed
an automatic "Install app / Add to Home screen" banner on the admin panel.
There is no app — the banner just offered to pin the admin page — and it was
removed on request. Chrome keys installability off `<link rel="manifest">`,
so this proves the served admin HTML carries no manifest link and the
manifest file itself is gone from the install.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver


def test_admin_page_has_no_web_app_manifest(
    shared_configured: ConfiguredPayserver,
) -> None:
    r = requests.get(f"{shared_configured.handle.url}/admin", timeout=15)
    assert r.status_code == 200, f"admin shell did not render: {r.status_code}"
    body = r.text.lower()
    assert 'rel="manifest"' not in body and "rel='manifest'" not in body, (
        "admin.php links a web-app manifest again — this re-enables the "
        "Android PWA install banner"
    )


def test_manifest_json_is_not_served(
    shared_configured: ConfiguredPayserver,
) -> None:
    r = requests.get(
        f"{shared_configured.handle.url}/manifest.json",
        timeout=15,
        allow_redirects=False,
    )
    assert r.status_code == 404, (
        f"manifest.json is still served (HTTP {r.status_code}) — delete it "
        "from the install and from scripts/build-standalone.sh"
    )
