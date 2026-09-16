"""Playwright browser fixture.

We don't use pytest-playwright to keep the plugin set small. The synchronous
playwright API is fine for our needs: one browser per session, fresh context
per test for cookie isolation.
"""
from __future__ import annotations

from typing import Iterator

import pytest


@pytest.fixture(scope="session")
def playwright_instance():
    from playwright.sync_api import sync_playwright

    with sync_playwright() as p:
        yield p


@pytest.fixture(scope="session")
def browser(playwright_instance):
    browser = playwright_instance.chromium.launch(
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage"],
    )
    yield browser
    browser.close()


@pytest.fixture
def page(browser):
    context = browser.new_context()
    page = context.new_page()
    yield page
    context.close()


def open_manual_mints(page) -> None:
    """Expand the wizard's manual mint entry with verify-and-retry.

    The toggle's click handler is bound by an inline script that runs after
    the anchor is already in the DOM, so a click that lands in that gap hits
    the bare href="#" and expands nothing. Re-click until the manual field is
    actually visible.
    """
    from playwright.sync_api import TimeoutError as PlaywrightTimeoutError

    page.wait_for_selector("#mint-manual-toggle")
    for attempt in range(3):
        page.click("#mint-manual-toggle")
        try:
            page.wait_for_selector("#mint_url_manual", state="visible", timeout=3000)
            return
        except PlaywrightTimeoutError:
            if attempt == 2:
                raise
