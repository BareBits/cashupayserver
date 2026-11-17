"""Cron URL with the key in the query string.

The admin Cron URL card hands operators a bare ``cron.php?key=…`` URL (for
hosting panels / scheduler services) plus a crontab line wrapping it. This
proves the URL the card shows actually authenticates against cron.php, that
the swap fast-lane variant does too, that a wrong key is refused, and that
the older header form keeps working for schedulers set up before the switch.
"""
from __future__ import annotations

import requests

from conftest import ConfiguredPayserver


def _cron_url_payload(configured: ConfiguredPayserver) -> dict:
    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=cron_url", timeout=15
    )
    r.raise_for_status()
    return r.json()


def test_cron_url_card_payload_shape(shared_configured: ConfiguredPayserver) -> None:
    data = _cron_url_payload(shared_configured)
    url = data["url"]
    swaps_url = data["swaps_url"]

    assert url.startswith(shared_configured.handle.url)
    assert "/cron.php?key=" in url
    assert swaps_url.startswith(shared_configured.handle.url)
    assert "/cron.php?only=swaps&key=" in swaps_url
    assert url.rsplit("key=", 1)[1] == swaps_url.rsplit("key=", 1)[1], "one key for both URLs"

    # The crontab lines wrap exactly those URLs, single-quoted so the shell
    # never sees the & in the swaps variant; no header for the operator to
    # know about.
    assert data["crontab"] == f"* * * * * curl -fsS '{url}' > /dev/null"
    assert f"curl -fsS '{swaps_url}'" in data["crontab_swaps"]
    assert "X-CRON-KEY" not in data["crontab"]
    assert "X-CRON-KEY" not in data["crontab_swaps"]


def test_displayed_cron_url_authenticates(shared_configured: ConfiguredPayserver) -> None:
    """Hitting the URL the card shows (plus ?ping=1 so no task run happens
    inside the test) is accepted; the swaps variant too."""
    data = _cron_url_payload(shared_configured)
    for url in (data["url"], data["swaps_url"]):
        r = requests.get(url + "&ping=1", timeout=15)
        assert r.status_code == 200, (url, r.status_code, r.text[:200])
        assert r.json() == {"mode": "ping", "ok": True}


def test_wrong_key_in_url_is_refused(shared_configured: ConfiguredPayserver) -> None:
    base = f"{shared_configured.handle.url}/cron.php"
    r = requests.get(base, params={"key": "not-the-key", "ping": "1"}, timeout=15)
    assert r.status_code == 403
    r = requests.get(base, params={"ping": "1"}, timeout=15)
    assert r.status_code == 403, "no key at all is refused too"


def test_header_form_still_accepted(shared_configured: ConfiguredPayserver) -> None:
    """Installs whose scheduler was set up from the old X-CRON-KEY line must
    keep working after upgrading."""
    data = _cron_url_payload(shared_configured)
    key = data["url"].rsplit("key=", 1)[1]
    r = requests.get(
        f"{shared_configured.handle.url}/cron.php",
        params={"ping": "1"},
        headers={"X-CRON-KEY": key},
        timeout=15,
    )
    assert r.status_code == 200, r.text[:200]
    assert r.json() == {"mode": "ping", "ok": True}


def test_updater_recommended_url_uses_same_key(shared_configured: ConfiguredPayserver) -> None:
    key = _cron_url_payload(shared_configured)["url"].rsplit("key=", 1)[1]
    r = shared_configured.admin.s.get(
        f"{shared_configured.handle.url}/admin?api=update_status", timeout=15
    )
    r.raise_for_status()
    data = r.json()
    assert data["recommended_cron_url"] == f"{shared_configured.handle.url}/update.php?key={key}"
    assert data["recommended_cron"] == f"*/15 * * * * curl -fsS '{data['recommended_cron_url']}' > /dev/null"
    assert "X-CRON-KEY" not in data["recommended_cron"]
