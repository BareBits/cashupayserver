"""Stored XSS regression test for API key labels.

Audit finding #4: the admin Settings panel rendered ${key.label} via
innerHTML without escaping, so a label registered through
/api-keys/authorize?applicationName=<img onerror=...> ran in the admin's
origin on the next dashboard load. Fix wraps with escapeHtml().
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver, DEFAULT_ADMIN_PASSWORD

pytestmark = pytest.mark.ui


XSS_PAYLOAD = "<img src=x onerror=\"window.__xss_fired = true\">label"


def test_api_key_label_is_html_escaped(configured: ConfiguredPayserver, page) -> None:
    # Register an API key whose label is a stored-XSS payload. Use a direct
    # DB write to bypass any future server-side label sanitization — we want
    # to assert the *renderer* is safe, not just the input filter.
    import time
    with configured.handle.db() as db:
        db.execute(
            "INSERT INTO api_keys (id, key_hash, store_id, label, permissions, created_at) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (
                "key_xss_test",
                "0" * 64,
                configured.store_id,
                XSS_PAYLOAD,
                '["btcpay.store.cancreateinvoice"]',
                int(time.time()),
            ),
        )

    # Trip-wire that the payload would set if it executed.
    page.add_init_script("window.__xss_fired = false;")

    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#username-input", "admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # Render the API keys list directly. loadStoreApiKeys() reads the global
    # currentStoreId, so set it first and then call the renderer. This avoids
    # depending on the exact settings-page click flow (and the view's
    # display:none hiding the list items from inner_text()).
    page.evaluate(
        """async (id) => {
            window.currentStoreId = id;
            await window.loadStoreApiKeys();
        }""",
        configured.store_id,
    )
    # Wait until the renderer wrote at least one list-item.
    page.wait_for_function(
        "() => document.querySelectorAll('#store-api-keys .list-item').length > 0",
        timeout=5000,
    )

    # The literal payload should appear as text content (escapeHtml turns
    # `<img …>` into &lt;img …&gt;) — but the renderer must NOT have parsed
    # it into an actual <img> element.
    container_text = page.locator("#store-api-keys").text_content()
    assert "<img" in container_text, "label text must be visible verbatim, got: " + container_text
    img_count = page.locator("#store-api-keys img").count()
    assert img_count == 0, f"label must not parse as HTML; found {img_count} img elements"
    assert page.evaluate("() => window.__xss_fired") is False, "XSS payload executed"
