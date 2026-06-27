"""Webhook URL scheme is enforced at create/update time.

FILTER_VALIDATE_URL alone accepts ftp:/javascript:/etc. The create + update
handlers now run the URL through Security::sanitizeUrl (http/https only), so a
non-http(s) scheme is rejected up front instead of being persisted and only
failing later at delivery time.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver


@pytest.mark.parametrize(
    "bad_url",
    [
        "javascript:alert(1)",
        "ftp://example.com/hook",
        "file:///etc/passwd",
        "not-a-url",
    ],
)
def test_create_webhook_rejects_non_http_scheme(
    configured: ConfiguredPayserver, bad_url: str,
) -> None:
    with pytest.raises(RuntimeError) as exc:
        configured.greenfield.create_webhook(configured.store_id, bad_url, secret="x")
    # Greenfield client raises with the HTTP status in the message; a rejected
    # URL is a 4xx validation error, never a 2xx.
    assert "-> 4" in str(exc.value), str(exc.value)


def test_create_webhook_accepts_https(
    configured: ConfiguredPayserver,
) -> None:
    wh = configured.greenfield.create_webhook(
        configured.store_id, "https://example.com/hook", secret="x"
    )
    assert wh["url"] == "https://example.com/hook"
