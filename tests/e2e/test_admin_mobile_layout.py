"""Browser e2e: the admin UI must not overflow a phone-width viewport.

Regression guard for the Dashboard balance-card action row and the Invoices
card header, whose fixed (non-wrapping) button rows pushed "Create invoice" /
"Create invoice (simple)" past the right edge of the screen on mobile. Every
admin view is loaded at 375px (iPhone-class width) and, for each, we assert:

  - the page does not scroll horizontally (scrollWidth == clientWidth), and
  - no visible element extends past the right edge of the viewport.

Tables and code blocks that legitimately scroll inside their own
`overflow-x:auto` container are exempt: an element is only reported when
none of its ancestors is horizontally scrollable (a plain overflow:hidden
ancestor does not count — it merely hides the cut-off content).
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

MOBILE = {"width": 375, "height": 812}

# Every admin view reachable from the bottom nav (admin role sees them all).
VIEWS = ["dashboard", "invoices", "stores", "products", "stats", "customers", "log", "settings"]

# Returns [{sel, right, width}] for visible elements poking past the viewport
# whose horizontal overflow is not clipped by an ancestor (tables inside an
# overflow-x:auto wrapper are fine; a flex row of buttons is not).
_OFFENDERS_JS = """
() => {
  const vw = document.documentElement.clientWidth;
  const out = [];
  const clipped = (el) => {
    for (let p = el.parentElement; p && p !== document.documentElement; p = p.parentElement) {
      const ox = getComputedStyle(p).overflowX;
      // Only a *scrollable* wrapper excuses overflow. overflow:hidden on a
      // card just chops the content off, which is exactly the bug.
      if (ox === 'auto' || ox === 'scroll') return true;
    }
    return false;
  };
  const describe = (el) => {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    else if (el.className && typeof el.className === 'string') s += '.' + el.className.trim().split(/\\s+/).slice(0, 2).join('.');
    const txt = (el.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 40);
    return txt ? `${s} "${txt}"` : s;
  };
  // "svg" alone says nothing; name the nearest ancestor with an id or class.
  const where = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      if (p.id || (p.className && typeof p.className === 'string' && p.className.trim())) return describe(p);
    }
    return '';
  };
  for (const el of document.body.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    if (cs.position === 'fixed') continue;  // nav/toasts handled by their own rules
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (r.right > vw + 1 && !clipped(el)) {
      out.push({sel: describe(el) + (el.id ? '' : ' in ' + where(el)), right: Math.round(r.right), width: Math.round(r.width)});
    }
  }
  return {vw, scrollWidth: document.documentElement.scrollWidth, offenders: out.slice(0, 15)};
}
"""


@pytest.fixture
def mobile_admin(shared_server: ConfiguredPayserver, browser):
    ctx = browser.new_context(viewport=MOBILE, is_mobile=True, has_touch=True, device_scale_factor=2)
    ctx.request.post(
        f"{shared_server.handle.url}/admin",
        form={"action": "login", "username": "admin", "password": shared_server.admin_password},
    )
    page = ctx.new_page()
    yield page, shared_server.handle.url
    ctx.close()


def _goto(page, base, view):
    page.goto(f"{base}/admin/{view}", wait_until="networkidle")
    page.wait_for_selector(f"#view-{view}.active")
    page.wait_for_timeout(1500)  # let async view loads render their lists


def _assert_fits(page, label):
    res = page.evaluate(_OFFENDERS_JS)
    problems = []
    if res["scrollWidth"] > res["vw"]:
        problems.append(f"page scrolls horizontally: scrollWidth {res['scrollWidth']} > viewport {res['vw']}")
    problems += [f"{o['sel']} right={o['right']} width={o['width']}" for o in res["offenders"]]
    assert not problems, f"{label} overflows a {res['vw']}px viewport:\n  " + "\n  ".join(problems)


@pytest.mark.parametrize("view", VIEWS)
def test_admin_view_fits_phone_width(mobile_admin, view):
    page, base = mobile_admin
    _goto(page, base, view)
    _assert_fits(page, f"/admin/{view}")


def test_dashboard_action_buttons_fit_phone_width(mobile_admin):
    """The specific report: every balance-card action button is fully on screen."""
    page, base = mobile_admin
    _goto(page, base, "dashboard")
    vw = page.evaluate("document.documentElement.clientWidth")
    for btn_id in ("btn-withdraw", "btn-request", "btn-request-simple"):
        box = page.locator(f"#{btn_id}").bounding_box()
        assert box is not None, f"#{btn_id} not rendered"
        assert box["x"] >= 0 and box["x"] + box["width"] <= vw + 1, f"#{btn_id} is cut off: {box} (viewport {vw})"


def test_invoices_header_buttons_fit_phone_width(mobile_admin):
    page, base = mobile_admin
    _goto(page, base, "invoices")
    vw = page.evaluate("document.documentElement.clientWidth")
    for btn_id in ("btn-export-invoices-csv", "btn-new-invoice-advanced", "btn-new-invoice"):
        box = page.locator(f"#{btn_id}").bounding_box()
        assert box is not None, f"#{btn_id} not rendered"
        assert box["x"] >= 0 and box["x"] + box["width"] <= vw + 1, f"#{btn_id} is cut off: {box} (viewport {vw})"


@pytest.mark.parametrize("modal", ["modal-cart", "modal-request", "modal-withdraw"])
def test_dashboard_modals_fit_phone_width(mobile_admin, modal):
    """The Create invoice (cart), Create invoice (simple) and Withdraw sheets
    opened from the dashboard also fit."""
    page, base = mobile_admin
    _goto(page, base, "dashboard")
    trigger = {"modal-cart": "#btn-request", "modal-request": "#btn-request-simple", "modal-withdraw": "#btn-withdraw"}[modal]
    page.click(trigger)
    page.wait_for_selector(f"#{modal}.visible")
    page.wait_for_timeout(500)  # slide-in transition
    _assert_fits(page, f"/admin/dashboard + #{modal}")
