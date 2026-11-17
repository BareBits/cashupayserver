"""The "Discover" mint dialog opens *in view* wherever it is launched from.

Regression: on the admin page the discovery overlay (#mint-discovery-modal)
carried class="modal", which admin.php styles as a bottom sheet — 500px max
width, 90vh max height and transform: translateY(100%) until a
.modal-overlay.visible parent slides it back. This overlay has no such parent,
so clicking Discover slid it 90% of the viewport downwards: all the user saw
was an empty, scrollable dark strip at the bottom of the screen. The dialog is
now its own centred overlay (class mint-discovery-overlay), like the wizard's.

Covered here, at a desktop and a phone viewport:

  - Store settings → Cashu section → "Enable a Cashu mint" → Discover
  - Store settings → Edit store → Backup mints → Add → Discover
  - Onboarding wizard → mints screen → "Choose my own mints" → Browse
    (setup.php has no .modal rule, so it never had the bug; this guards it)

For each: the overlay covers the viewport, the panel sits fully inside it,
the disclaimer checkbox is clickable, Select fills the launching field and
closes only the discovery dialog (the parent dialog stays open), and a click
on the scrim closes the discovery dialog the same way (admin only — the
wizard's never had scrim-close and this change does not touch it).

Discovery itself is stubbed: window.MintDiscovery is pinned to a fake that
returns one mint, so the test is deterministic and needs no Nostr relays.
"""
from __future__ import annotations

import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.browser import expand_settings_cards, open_manual_mints
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard, browser_walk_to_store

pytestmark = pytest.mark.ui

STUB_MINT_URL = "https://stub-mint.test"

# Pins window.MintDiscovery before the UMD bundle loads. The bundle assigns
# `window.MintDiscovery = ...`; the accessor's no-op setter swallows that so
# the page keeps the stub. The stub honours both call shapes in use:
# admin.php awaits the list returned by discover(); setup.php uses
# discoverStreaming() and listens on onMint / onComplete.
STUB_DISCOVERY_INIT = """
(() => {
    const stubMints = [{
        url: %r,
        info: {
            name: 'Stub Mint',
            nuts: { 4: { methods: [{ method: 'bolt11', unit: 'sat' }] } },
        },
        error: false,
        averageRating: 4.5,
        reviewsCount: 3,
    }];
    const stub = {
        create() {
            return {
                discover(opts) {
                    const mints = stubMints.map(m => Object.assign({}, m));
                    if (opts && typeof opts.onComplete === 'function') opts.onComplete(mints);
                    return Promise.resolve(mints);
                },
                discoverStreaming(opts) {
                    const mints = stubMints.map(m => Object.assign({}, m));
                    if (opts && typeof opts.onMint === 'function') mints.forEach(m => opts.onMint(m));
                    if (opts && typeof opts.onComplete === 'function') opts.onComplete(mints);
                    return Promise.resolve(mints);
                },
                close() {},
                getReviewsForMint() { return []; },
            };
        },
    };
    Object.defineProperty(window, 'MintDiscovery', {
        configurable: true,
        get: () => stub,
        set: () => {},
    });
})();
""" % STUB_MINT_URL

VIEWPORTS = [
    pytest.param({"width": 1280, "height": 900}, id="desktop"),
    pytest.param({"width": 400, "height": 800}, id="phone"),
]


# ------------------------------------------------------------------ fixtures


def _walk_wizard_mintless(url: str, store: str) -> None:
    w = SetupWizard(url)
    w.through_store(store)
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="mints", mints_enabled="0")


@pytest.fixture()
def mintless_payserver() -> Iterator[PayserverHandle]:
    """A store without a Cashu mint: the Cashu section is greyed out with the
    "Enable a Cashu mint" button, and the store dialog has no backup mints."""
    workdir = SESSION_TMP / f"payserver-discover-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    _walk_wizard_mintless(handle.url, "Discover Store")
    yield handle
    stop_payserver(handle)


@pytest.fixture()
def fresh_payserver() -> Iterator[PayserverHandle]:
    """Un-walked install for the onboarding wizard case."""
    workdir = SESSION_TMP / f"payserver-discover-wizard-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir)
    yield handle
    stop_payserver(handle)


def _new_page(browser, viewport):
    ctx = browser.new_context(viewport=viewport)
    ctx.add_init_script(STUB_DISCOVERY_INIT)
    return ctx


@pytest.fixture()
def admin_page(mintless_payserver, browser, viewport):
    ctx = _new_page(browser, viewport)
    ctx.request.post(
        f"{mintless_payserver.url}/admin",
        form={
            "action": "login",
            "username": "admin",
            "password": SetupWizard.DEFAULT_PASSWORD,
        },
    )
    page = ctx.new_page()
    yield page, mintless_payserver.url
    ctx.close()


# ------------------------------------------------------------------- helpers


def _goto_store_settings(page, base) -> None:
    page.goto(f"{base}/admin/stores", wait_until="networkidle")
    expand_settings_cards(page)
    page.wait_for_timeout(1500)


def _dialog_geometry(page) -> dict:
    return page.evaluate(
        """() => {
            const overlay = document.getElementById('mint-discovery-modal');
            const panel = overlay.firstElementChild;
            const o = overlay.getBoundingClientRect();
            const p = panel.getBoundingClientRect();
            return {
                display: getComputedStyle(overlay).display,
                transform: getComputedStyle(overlay).transform,
                panelBg: getComputedStyle(panel).backgroundColor,
                overlay: {left: o.left, top: o.top, width: o.width, height: o.height},
                panel: {left: p.left, top: p.top, right: p.right, bottom: p.bottom,
                        width: p.width, height: p.height},
                vw: window.innerWidth,
                vh: window.innerHeight,
            };
        }"""
    )


def _assert_dialog_in_view(page, viewport, *, opaque_panel: bool = True) -> None:
    """`opaque_panel` is the admin page's requirement (another dialog sits
    underneath); the wizard's panel is deliberately frosted glass over an
    otherwise empty scrim."""
    g = _dialog_geometry(page)
    assert g["display"] == "flex", g
    # No bottom-sheet slide: the overlay is the full viewport scrim.
    assert g["transform"] in ("none", "matrix(1, 0, 0, 1, 0, 0)"), g
    assert g["overlay"] == {
        "left": 0, "top": 0, "width": viewport["width"], "height": viewport["height"],
    }, g
    # Opaque panel: the launching dialog must not bleed through (admin.php
    # never defined --card-bg, which the panel used to paint with).
    if opaque_panel:
        assert g["panelBg"].startswith("rgb(") and "rgba" not in g["panelBg"], g
    p = g["panel"]
    assert p["width"] > 0 and p["height"] > 0, g
    assert p["left"] >= 0 and p["top"] >= 0, g
    assert p["right"] <= g["vw"] and p["bottom"] <= g["vh"], g
    # Phones: the panel clears both screen edges; desktop: it uses the room.
    if viewport["width"] <= 500:
        assert p["left"] >= 8 and p["right"] <= g["vw"] - 8, g
    else:
        assert p["width"] >= 600, g


def _exercise_select(page, viewport, *, target_field: str, parent_modal: str) -> None:
    """Dialog just opened: it is in view, the stubbed mint renders, the
    disclaimer gate works, Select fills `target_field` and closes only the
    discovery dialog."""
    _assert_dialog_in_view(page, viewport)

    # The close button and disclaimer are real, hit-testable targets.
    close_btn = page.locator("#mint-discovery-modal button:has-text('×')")
    assert close_btn.is_visible()
    card = page.locator(f".mint-discovery-card[data-mint-url='{STUB_MINT_URL}']")
    card.wait_for(state="visible", timeout=10000)
    select_btn = card.locator("button.select-discovered-mint")
    assert select_btn.is_disabled()

    page.check("#mint-disclaimer-checkbox")
    assert select_btn.is_enabled()
    select_btn.click()

    page.wait_for_function(
        "getComputedStyle(document.getElementById('mint-discovery-modal')).display === 'none'"
    )
    assert page.input_value(target_field) == STUB_MINT_URL
    # The launching dialog is untouched.
    assert page.locator(f"#{parent_modal}").evaluate("el => el.classList.contains('visible')")


def _exercise_scrim_close(page, *, parent_modal: str, discover_button: str) -> None:
    """Dialog just re-opened: a click on the scrim (outside the panel) closes
    the discovery dialog and nothing else; a click inside the panel does not
    close it."""
    overlay = page.locator("#mint-discovery-modal")
    overlay.click(position={"x": 4, "y": 4})
    page.wait_for_function(
        "getComputedStyle(document.getElementById('mint-discovery-modal')).display === 'none'"
    )
    assert page.locator(f"#{parent_modal}").evaluate("el => el.classList.contains('visible')")

    page.click(discover_button)
    page.wait_for_function(
        "getComputedStyle(document.getElementById('mint-discovery-modal')).display === 'flex'"
    )
    page.click("#mint-discovery-status")
    assert page.evaluate(
        "getComputedStyle(document.getElementById('mint-discovery-modal')).display"
    ) == "flex"


# --------------------------------------------------------------------- tests


@pytest.mark.parametrize("viewport", VIEWPORTS)
def test_enable_mint_discover_opens_in_view(admin_page, viewport) -> None:
    page, base = admin_page
    _goto_store_settings(page, base)

    page.click("#ln-section-cashu .subsection-toggle")
    page.click("#btn-enable-cashu-mint")
    page.wait_for_selector("#modal-enable-mint.visible")
    assert page.input_value("#enable-mint-url") == ""

    page.click("#btn-enable-mint-discover")
    _exercise_select(
        page, viewport, target_field="#enable-mint-url", parent_modal="modal-enable-mint",
    )

    page.click("#btn-enable-mint-discover")
    _exercise_scrim_close(
        page, parent_modal="modal-enable-mint", discover_button="#btn-enable-mint-discover",
    )


@pytest.mark.parametrize("viewport", VIEWPORTS)
def test_backup_mint_discover_opens_in_view(admin_page, viewport) -> None:
    page, base = admin_page
    _goto_store_settings(page, base)

    page.click("#btn-edit-store")
    page.wait_for_selector("#modal-store.visible")
    page.click("[data-store-action='show-add-backup-mint']")
    page.wait_for_selector("#backup-mint-url", state="visible")

    page.click("[data-store-action='discover-backup-mint']")
    _exercise_select(
        page, viewport, target_field="#backup-mint-url", parent_modal="modal-store",
    )

    page.click("[data-store-action='discover-backup-mint']")
    _exercise_scrim_close(
        page, parent_modal="modal-store", discover_button="[data-store-action='discover-backup-mint']",
    )


@pytest.mark.parametrize("viewport", VIEWPORTS)
def test_wizard_browse_mints_opens_in_view(fresh_payserver, browser, viewport) -> None:
    """Onboarding guard: the wizard's copy of the dialog keeps working."""
    ctx = _new_page(browser, viewport)
    page = ctx.new_page()
    try:
        browser_walk_to_store(page, fresh_payserver.url, "wizard-test-pw", store_name="Disco Store")
        page.wait_for_selector("#onchain-form")
        page.click("button:has-text('Skip for now')")
        page.wait_for_selector("#lightning_address")
        page.click("button:has-text('Skip for now')")
        # The mints screen auto-runs discovery to pick two mints; the stub
        # yields one, so the wizard falls back to manual entry on its own
        # (toggle hidden). Open it by hand only if the auto-pick did not.
        page.wait_for_selector("#mint-manual-toggle", state="attached")
        if not page.locator("#mint_url_manual").is_visible():
            open_manual_mints(page)
        page.wait_for_selector("#mint_url_manual", state="visible")

        page.click("#mint_url_manual ~ button:has-text('Browse')")
        page.wait_for_function(
            "getComputedStyle(document.getElementById('mint-discovery-modal')).display === 'flex'"
        )
        _assert_dialog_in_view(page, viewport, opaque_panel=False)

        card = page.locator(f"button.select-discovered-mint[data-mint-url='{STUB_MINT_URL}']")
        card.wait_for(state="visible", timeout=10000)
        page.check("#mint-disclaimer-checkbox")
        card.click()
        page.wait_for_function(
            "getComputedStyle(document.getElementById('mint-discovery-modal')).display === 'none'"
        )
        assert page.input_value("#mint_url_manual") == STUB_MINT_URL
    finally:
        ctx.close()
