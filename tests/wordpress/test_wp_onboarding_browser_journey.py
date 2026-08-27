"""The merchant journey, end to end, in a real browser.

The requests-driven install-mode suite (test_wp_onboarding_install_mode.py)
pins the server-side behavior of every onboarding step; what it cannot see is
what a merchant's BROWSER does between those steps — form actions resolving
against the wrong URL mode, native validation blocking a submit, the wizard
iframe navigating somewhere unexpected. Both regressions this file guards
were exactly that kind of blind spot:

  - the install-mode flash said "finish the wizard, then come back here"
    while the wizard was embedded right below the notice, and
  - the wizard's on-chain screen was the first whose form posted to the
    absolute Urls::setup() — which, with no URL mode detected (a host that
    routes neither extension-less nor PATH_INFO URLs), built a
    /router.php/setup.php URL that WordPress swallowed with its themed 404,
    dumping the merchant out of setup when they clicked "Skip for now".

Two scenarios, both driven with Playwright:

  1. The full journey on a friendly (Apache-like) host: upload + activate
     the BUILT plugin zip through wp-admin, choose install-alongside, expand
     the wizard to full screen, walk it (no password screen — the admin is
     pre-seeded), return through the wizard's own finish button (which
     collects the credentials with no manual step), wire WooCommerce.
  2. The SAME full journey on a rewrite-hostile host (nginx-style: real
     *.php files execute, everything else falls into WordPress — Local WP's
     layout): the plugin's API bridge must keep the /api/v1 routes answering
     (no warning, and the WooCommerce wiring's webhook registration works),
     with the manual "I finished the wizard" fallback button standing in for
     the wizard's return link.
"""
from __future__ import annotations

import pytest

from wordpress.conftest import wp_option
from fixtures.wordpress import (
    WP_ADMIN_PASSWORD,
    WP_ADMIN_USER,
    WordPressHandle,
    install_woocommerce,
    stage_elex_discount_plugin,
)

pytestmark = [pytest.mark.wordpress, pytest.mark.ui]

# The wizard iframe on the provision step (see cashupay_render_step_provision).
WIZARD_IFRAME = "iframe[title='BareBits setup']"


def _login(page, wp: WordPressHandle, redirect_to: str) -> None:
    page.goto(f"{wp.url}/wp-login.php?redirect_to={redirect_to}")
    page.fill("#user_login", WP_ADMIN_USER)
    page.fill("#user_pass", WP_ADMIN_PASSWORD)
    page.click("#wp-submit")


def _open_onboarding(page, wp: WordPressHandle) -> None:
    page.goto(f"{wp.url}/wp-admin/admin.php?page=cashupay")
    page.wait_for_selector("h1:has-text('BareBits')")


def _choose_install_and_run(page, wp: WordPressHandle) -> str:
    """Chooser -> preflight -> run the installer. Returns the flash text."""
    page.wait_for_selector("#cashupay-mode-install")
    page.check("#cashupay-mode-install")
    page.click("#submit")

    page.wait_for_selector("h2:has-text('Install BareBits alongside WordPress')")
    # Download + unpack of the fixture release zip; generous ceiling for CI.
    page.click("#submit")
    # Target the flash paragraph precisely: WooCommerce and friends drop their
    # own .notice boxes onto wp-admin pages.
    page.wait_for_selector(".notice p:has-text('BareBits is installed at')", timeout=180_000)
    flash = page.inner_text(".notice p:has-text('BareBits is installed at')")

    # The wizard renders right below this very notice: any "come back here"
    # choreography reads as "you are on the wrong page" and sends merchants
    # hunting for a page they are already on.
    assert "come back here" not in flash, flash
    return flash


def _walk_wizard_in_iframe(page) -> None:
    """Drive the embedded BareBits wizard the way a merchant would, declining
    every optional rail. Works identically on friendly and hostile hosts —
    which is the point: every step form must post back to the URL the wizard
    is actually served at, never a URL-mode guess."""
    frame = page.frame_locator(WIZARD_IFRAME)

    # Terms. The first checkbox must carry BOTH agreement links.
    frame.locator("h2:has-text(\"Let's agree on a few things\")").wait_for()
    label = frame.locator("label[for='terms_legal']").inner_text()
    assert "I agree with the terms of the" in label, label
    assert "use policy" in label, label
    assert frame.locator(
        "label[for='terms_legal'] a[href='https://github.com/BareBits/cashupayserver/blob/main/LICENSE.md']"
    ).count() == 1, "license must link to LICENSE.md on GitHub"
    assert frame.locator(
        "label[for='terms_legal'] a[href='https://github.com/BareBits/cashupayserver/blob/main/USE_POLICY.md']"
    ).count() == 1, "use policy must link to USE_POLICY.md on GitHub"
    for box in ("#terms_legal", "#terms_warranty", "#terms_fee"):
        frame.locator(box).check()
    frame.locator("button[type=submit]:has-text('Continue')").click()

    # Managed install: terms lands straight on the store screen. No security
    # screen (data dir outside the web root) and — the regression — no
    # password screen: the admin account was pre-seeded from
    # CASHUPAY_ADMIN_PASSWORD_HASH, the merchant never types a BareBits
    # credential.
    frame.locator("h2:has-text(\"Let's name your store\")").wait_for()
    assert frame.locator("h2:has-text('admin password')").count() == 0
    frame.locator("input[name='store_name']").fill("Browser Journey Store")
    frame.locator("button[type=submit]").first.click()

    # On-chain screen: "Skip for now" must advance the WIZARD (to the
    # Lightning screen — no zero-conf without an on-chain rail), not bounce
    # the iframe into a WordPress "page not found". This is the exact click
    # that used to strand merchants on hosts with no URL rewriting.
    frame.locator("h2:has-text('On-chain Bitcoin payments')").wait_for()
    frame.locator("button:has-text('Skip for now')").click()
    frame.locator("h2:has-text('Lightning payments')").wait_for()

    frame.locator("button:has-text('Skip for now')").click()
    frame.locator("h2:has-text('Submarine swaps')").wait_for()
    frame.locator("button:has-text('No thanks')").click()
    frame.locator("h2:has-text('Cashu mints')").wait_for()
    frame.locator("button:has-text('No thanks, run without mints')").click()

    # Managed install: completion directly — never the crontab screen
    # (WP-cron owns the heartbeat).
    frame.locator("h2:has-text(\"You're all set\")").wait_for()


def _collect_credentials(page) -> None:
    page.click("#submit")  # "I finished the wizard — continue" (manual fallback)
    page.wait_for_selector(".notice p:has-text('Connected!')")


def _expand_wizard(page) -> None:
    """The provision step's "Continue" button must switch the embedded wizard
    into the full-viewport view (everything except the admin menu + bar), and
    the exit control must switch it back."""
    page.click("#cashupay-wizard-expand")
    shell = page.locator("#cashupay-wizard-shell")
    assert "cashupay-expanded" in (shell.get_attribute("class") or "")
    # position:fixed makes it viewport-sized; sanity-check it actually grew
    # past the 720px column the step normally renders in.
    box = shell.bounding_box()
    assert box is not None and box["width"] > 800, box
    page.click("#cashupay-wizard-exit")
    assert "cashupay-expanded" not in (shell.get_attribute("class") or "")
    # Leave it expanded for the walk — the way a merchant would use it.
    page.click("#cashupay-wizard-expand")


def _finish_wizard_via_return(page) -> None:
    """The wizard's completion screen carries the managed-install return
    button (CASHUPAY_MANAGED_RETURN_URL): one click breaks out of the iframe
    (target=_top), the plugin collects the credentials itself, and the
    onboarding page lands on the wire step — no manual collect."""
    frame = page.frame_locator(WIZARD_IFRAME)
    frame.locator("#managed-return-link").click()
    page.wait_for_selector(".notice p:has-text('Connected!')")


def test_full_merchant_journey_in_browser(wordpress_bare_install, wp_plugin_zip, page) -> None:
    wp = wordpress_bare_install
    page.set_default_timeout(60_000)

    # WooCommerce up front so the final wiring step has something to wire.
    install_woocommerce(wp)
    stage_elex_discount_plugin(wp)

    # Install the BUILT plugin zip through wp-admin's upload form — the exact
    # artifact (and the exact path) a real merchant uses.
    _login(page, wp, f"{wp.url}/wp-admin/plugin-install.php?tab=upload")
    page.wait_for_selector("input[name='pluginzip']")
    page.set_input_files("input[name='pluginzip']", str(wp_plugin_zip))
    page.click("#install-plugin-submit")
    page.wait_for_selector("a:has-text('Activate Plugin')")
    page.click("a:has-text('Activate Plugin')")

    _open_onboarding(page, wp)
    flash = _choose_install_and_run(page, wp)
    # Friendly host: the routing probe must not cry wolf.
    assert "routes are not answering" not in flash, flash

    page.wait_for_selector(WIZARD_IFRAME)
    _expand_wizard(page)
    _walk_wizard_in_iframe(page)
    _finish_wizard_via_return(page)

    # Wire WooCommerce (no discount) and land fully configured.
    page.wait_for_selector("h2:has-text('connect WooCommerce')")
    page.fill("#cashupay-discount", "0")
    page.click("#submit")
    page.wait_for_selector(".notice p:has-text('WooCommerce now takes Bitcoin')")

    assert wp_option(wp, "cashupay_store_id") != ""
    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_url
    assert wp_option(wp, "cashupay_wired_at") != ""
    # The one-time provisioning token is spent.
    assert wp_option(wp, "cashupay_provision_token") == ""


def test_full_journey_on_rewrite_hostile_host(wordpress_hostile_host, page) -> None:
    """nginx-style host (Local WP's layout): /barebits/*.php executes, every
    other /barebits URL (extension-less, PATH_INFO) falls into WordPress.
    The plugin's API bridge catches those fallen-through /api/v1 requests and
    replays them against the install's api.php — so the routing probe must
    stay quiet, the wizard must walk start to finish, and the WooCommerce
    wiring (whose webhook registration is a real Greenfield API call) must
    complete end to end THROUGH the bridge."""
    wp = wordpress_hostile_host
    page.set_default_timeout(60_000)

    install_woocommerce(wp)

    _login(page, wp, f"{wp.url}/wp-admin/admin.php?page=cashupay")
    page.wait_for_selector("h1:has-text('BareBits')")
    flash = _choose_install_and_run(page, wp)
    # The install-time probe goes through the freshly recorded install's
    # /api/v1 URL — on this host that means through the bridge. It answering
    # is the whole fix: no warning, no dead end at the wiring step.
    assert "routes are not answering" not in flash, flash

    page.wait_for_selector(WIZARD_IFRAME)
    _walk_wizard_in_iframe(page)

    # Credential collection via the manual fallback button — the path a
    # merchant takes if they never click the wizard's own return link (the
    # friendly-host journey drives that link).
    _collect_credentials(page)
    assert wp_option(wp, "cashupay_store_id") != ""
    assert wp_option(wp, "cashupay_cron_key") != ""

    # Wire WooCommerce: registering the invoice webhook is a live
    # /api/v1/stores/{id}/webhooks call that only succeeds if the bridge
    # really carries the Greenfield API on this host.
    page.wait_for_selector("h2:has-text('connect WooCommerce')")
    page.fill("#cashupay-discount", "0")
    page.click("#submit")
    page.wait_for_selector(".notice p:has-text('WooCommerce now takes Bitcoin')")
    assert wp_option(wp, "btcpay_gf_url") == wp.barebits_url
    assert wp_option(wp, "cashupay_wired_at") != ""
