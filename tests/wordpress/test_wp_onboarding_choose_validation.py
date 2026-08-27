"""The onboarding chooser's browser-side form validation.

The chooser is ONE form holding both mode radios and the URL-mode
`<input type="url">`. Browsers validate every enabled field in a form on
submit, whatever radio is selected — so any text in the URL field that is
not a scheme-qualified URL (a pasted "pay.example.com", browser autofill)
used to block "Install BareBits alongside WordPress" with the native
"Please enter a URL" bubble. The plugin now disables the URL field while
the install mode is selected (and requires it while URL mode is), which is
inherently browser behavior — hence a real Chromium via Playwright instead
of the requests-driven flow the rest of the suite uses.
"""
from __future__ import annotations

import pytest

from fixtures.wordpress import WP_ADMIN_PASSWORD, WP_ADMIN_USER, WordPressHandle

pytestmark = pytest.mark.wordpress


def _login_and_open_onboarding(page, wp: WordPressHandle) -> None:
    onboarding = f"{wp.url}/wp-admin/admin.php?page=cashupay"
    # The fixture's php -S router cannot serve the bare /wp-admin/ directory,
    # so send the post-login redirect straight to the plugin page.
    page.goto(f"{wp.url}/wp-login.php?redirect_to={onboarding}")
    page.fill("#user_login", WP_ADMIN_USER)
    page.fill("#user_pass", WP_ADMIN_PASSWORD)
    page.click("#wp-submit")
    page.wait_for_selector("#cashupay-mode-install")


def test_stale_url_text_does_not_block_install_mode(wordpress, page) -> None:
    """Regression: text left in the URL field must not veto the install
    choice. The merchant types a schemeless address, thinks better of it,
    picks "Install alongside", and must land on the install preflight."""
    wp = wordpress
    _login_and_open_onboarding(page, wp)

    page.fill("#cashupay-server-url", "pay.example.com")
    page.check("#cashupay-mode-install")
    # Selecting install mode takes the URL field out of the game entirely.
    assert page.is_disabled("#cashupay-server-url")

    page.click("#submit")
    page.wait_for_selector("h2:has-text('Install BareBits alongside WordPress')")
    assert wp.wp_cli("option", "get", "cashupay_mode").stdout.strip() == "install"


def test_url_mode_requires_a_url(wordpress, page) -> None:
    """URL mode keeps (and strengthens) the validation: an empty URL field
    never leaves the browser, and the field is live again after switching
    back from install mode."""
    wp = wordpress
    _login_and_open_onboarding(page, wp)

    # Flip to install and back: the field must re-enable.
    page.check("#cashupay-mode-install")
    page.check("#cashupay-mode-url")
    assert page.is_enabled("#cashupay-server-url")

    page.click("#submit")
    # The native `required` bubble held the submit; still on the chooser,
    # nothing chosen server-side.
    assert page.is_visible("#cashupay-mode-url")
    message = page.eval_on_selector(
        "#cashupay-server-url", "el => el.validationMessage"
    )
    assert message, "expected the browser's required-field message"
    assert wp.wp_cli("option", "get", "cashupay_mode", check=False).stdout.strip() == ""
