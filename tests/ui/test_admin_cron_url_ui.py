"""Settings → Cron URL card: the bare keyed URL (for hosting panels /
scheduler services) sits above the crontab line, for both the main cron and
the swap fast-lane, and each has its own Copy button."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.browser import expand_settings_cards

pytestmark = pytest.mark.ui


def _open_cron_card(configured: ConfiguredPayserver, page) -> None:
    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")
    page.click('[data-view="settings"]')
    expand_settings_cards(page)
    page.wait_for_selector("#card-cron-url", state="visible")
    page.wait_for_function(
        "() => { const c = document.querySelector('#cron-plain-url-display');"
        " return c && c.textContent.includes('cron.php?key='); }"
    )


def test_cron_card_shows_keyed_urls_and_matching_crontab_lines(
    shared_configured: ConfiguredPayserver, page
) -> None:
    _open_cron_card(shared_configured, page)
    base = shared_configured.handle.url

    url = page.inner_text("#cron-plain-url-display").strip()
    line = page.inner_text("#cron-url-display").strip()
    assert url.startswith(base) and "/cron.php?key=" in url
    assert line == f"* * * * * curl -fsS '{url}' > /dev/null"

    swaps_url = page.inner_text("#cron-swaps-plain-url-display").strip()
    swaps_line = page.inner_text("#cron-swaps-url-display").strip()
    assert swaps_url.startswith(base) and "/cron.php?only=swaps&key=" in swaps_url
    assert f"curl -fsS '{swaps_url}'" in swaps_line

    card_text = page.inner_text("#card-cron-url")
    assert "X-CRON-KEY" not in card_text, "operators are not asked to send a header"
    assert "treat it like a password" in card_text, "the key-in-URL caveat is spelled out"


def test_copy_url_buttons_copy_the_bare_urls(
    shared_configured: ConfiguredPayserver, page
) -> None:
    """Headless Chromium has no clipboard permission, so capture what the
    page tries to write instead of reading the clipboard back."""
    _open_cron_card(shared_configured, page)
    page.evaluate(
        "() => { window.__copied = [];"
        " navigator.clipboard.writeText = (t) => { window.__copied.push(t); return Promise.resolve(); }; }"
    )
    page.click("#btn-copy-cron-plain-url")
    page.wait_for_selector(".toast:has-text('Cron URL copied')", state="attached")
    page.click("#btn-copy-cron-swaps-plain-url")
    page.wait_for_selector(".toast:has-text('Swap fast-lane URL copied')", state="attached")
    page.click("#btn-copy-cron-url")
    page.wait_for_selector(".toast:has-text('Cron entry copied')", state="attached")

    copied = page.evaluate("() => window.__copied")
    assert copied[0] == page.inner_text("#cron-plain-url-display").strip()
    assert copied[1] == page.inner_text("#cron-swaps-plain-url-display").strip()
    assert copied[2] == page.inner_text("#cron-url-display").strip()
