"""The QR stack is served locally — no CDN script may sit in the path of QR
rendering (PRIVACY.md promises it, and a CDN-served renderer could encode an
attacker's address). qrious used to come from cdn.jsdelivr.net and bc-ur from
cdn.skypack.dev; this drives the REAL admin page in a browser and proves the
local replacements load and work: the QRious shim renders a scannable-shaped
canvas, and the bc-ur bundle produces multi-part `ur:` fountain frames exactly
as assets/js/animated-qr.js consumes them."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver

pytestmark = pytest.mark.ui


def test_admin_qr_libraries_load_locally_and_render(
    shared_configured: ConfiguredPayserver, page
) -> None:
    configured = shared_configured
    external: list[str] = []
    page.on(
        "request",
        lambda req: external.append(req.url)
        if req.url.startswith("http") and not req.url.startswith(configured.handle.url)
        else None,
    )

    page.set_default_timeout(15000)
    page.goto(f"{configured.handle.url}/admin")
    page.fill("#password-input", configured.admin_password)
    page.click("#password-submit")
    page.wait_for_selector("#app", state="visible")

    # Both globals come from locally-served files.
    page.wait_for_function(
        "() => typeof QRious === 'function' && window.bcur && window.bcur.UR && window.bcur.UREncoder"
    )

    # The shim renders onto a canvas: correct size, dark modules present, and
    # a white quiet zone at the border (all four corners background-colored).
    shim = page.evaluate(
        """() => {
            const canvas = document.createElement('canvas');
            new QRious({element: canvas, value: 'lightning:LNBC1TESTVALUE', size: 200,
                        backgroundAlpha: 1, foreground: '#000000', background: '#ffffff', level: 'M'});
            const ctx = canvas.getContext('2d');
            const px = ctx.getImageData(0, 0, 200, 200).data;
            let dark = 0;
            for (let i = 0; i < px.length; i += 4) { if (px[i] < 128) dark++; }
            const corner = (x, y) => ctx.getImageData(x, y, 1, 1).data[0];
            return {w: canvas.width, h: canvas.height, dark,
                    corners: [corner(1,1), corner(198,1), corner(1,198), corner(198,198)]};
        }"""
    )
    assert shim["w"] == 200 and shim["h"] == 200, shim
    assert shim["dark"] > 1000, f"QR canvas has no meaningful dark modules: {shim}"
    assert all(c > 200 for c in shim["corners"]), f"quiet zone missing: {shim}"

    # The bc-ur bundle behaves like the old Skypack module did for
    # animated-qr.js: CBOR bytes in, uppercase-able multi-part ur:bytes out.
    parts = page.evaluate(
        """() => {
            const token = 'cashuBtest' + 'x'.repeat(400);
            const bytes = new TextEncoder().encode(token);
            const cbor = new Uint8Array(3 + bytes.length);
            cbor[0] = 0x79; cbor[1] = (bytes.length >> 8) & 0xFF; cbor[2] = bytes.length & 0xFF;
            cbor.set(bytes, 3);
            const ur = new window.bcur.UR(cbor, 'bytes');
            const enc = new window.bcur.UREncoder(ur, 150);
            return [enc.nextPart(), enc.nextPart()];
        }"""
    )
    for part in parts:
        assert part.lower().startswith("ur:bytes/"), f"unexpected UR part: {part[:60]}"

    # And nothing on the page reached out to any other origin.
    assert external == [], f"admin page made external requests: {external}"
