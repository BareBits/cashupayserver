"""Onboarding wizard in Windows-desktop mode.

The desktop package's launcher (windows/CashuPayServer.bat) sets
CASHUPAY_DESKTOP=1 for every process it starts. In that mode the wizard must
drop two screens that don't apply to a localhost-only package with its own
cron ticker:

  - security: the server only listens on 127.0.0.1 and router.php refuses
    /data requests, so the manual "verify your database isn't downloadable"
    walkthrough has nothing to protect against;
  - cron: desktop-helper.php already ticks cron-runner.php on a timer, and a
    crontab line is meaningless on a system without cron.

Everything else — and the step counter — must stay coherent. The env-var
path is exactly what the shipped .bat exercises, which is what makes this
testable on a Linux rig; the layout-sniffing fallback is covered by
tests/php/test_setup_flow_desktop.php.
"""
from __future__ import annotations

import uuid
from typing import Iterator

import pytest

from conftest import SESSION_TMP
from fixtures.payserver import PayserverHandle, start_payserver, stop_payserver
from fixtures.setup_helpers import SetupWizard, wizard_heading as _heading


@pytest.fixture()
def desktop_payserver() -> Iterator[PayserverHandle]:
    workdir = SESSION_TMP / f"payserver-desktop-{uuid.uuid4().hex[:8]}"
    handle = start_payserver(workdir, extra_env={"CASHUPAY_DESKTOP": "1"})
    yield handle
    stop_payserver(handle)


def test_desktop_flow_skips_security_and_cron(desktop_payserver: PayserverHandle) -> None:
    w = SetupWizard(desktop_payserver.url)

    # terms → password directly: no security screen. On this rig the data dir
    # sits inside the web root (the desktop package's own shape), which is
    # precisely the case that used to force the screen in.
    body = w.accept_terms()
    assert _heading(body) == "Create your admin password", (
        "desktop mode must go straight from terms to password"
    )
    # 10 standalone screens minus security and cron.
    assert "of 8" in body, "the step counter must not promise the dropped screens"

    w.post(
        step="password",
        password=SetupWizard.DEFAULT_PASSWORD,
        confirm_password=SetupWizard.DEFAULT_PASSWORD,
    )
    w.post(step="store", store_name="Desktop Store", default_currency="sat")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    body = w.post(step="swaps", swaps_enabled="0")
    assert _heading(body) == "Cashu mints"

    # Declining mints flips setup_complete; on desktop the wizard must land on
    # the completion screen with the background-jobs note, never on the
    # crontab instructions.
    body = w.post(step="mints", mints_enabled="0")
    assert _heading(body) == "You're all set!", (
        "desktop mode must skip the cron screen after mints"
    )
    assert "Background jobs run automatically" in body, (
        "the completion screen must say the launcher handles background jobs"
    )
    assert "crontab" not in body


def test_server_flow_still_has_both_screens(payserver: PayserverHandle) -> None:
    """Control: without CASHUPAY_DESKTOP the wizard keeps its server shape —
    the desktop skips must not leak into ordinary installs."""
    w = SetupWizard(payserver.url)

    body = w.accept_terms()
    assert _heading(body) == "Quick safety check", "servers still get the security screen"

    body = w.post(step="security", security_acknowledged="1")
    assert _heading(body) == "Create your admin password"

    w.post(
        step="password",
        password=SetupWizard.DEFAULT_PASSWORD,
        confirm_password=SetupWizard.DEFAULT_PASSWORD,
    )
    w.post(step="store", store_name="Server Store", default_currency="sat")
    w.post(step="onchain", onchain_action="skip")
    w.post(step="lightning", lightning_action="skip")
    w.post(step="swaps", swaps_enabled="0")
    body = w.post(step="mints", mints_enabled="0")
    assert _heading(body) == "Enable cron", "servers still get the cron screen"
    # This Linux rig gets the crontab line, not the Windows schtasks variant.
    assert "crontab" in body
    assert "schtasks" not in body

    body = w.post(step="cron")
    assert _heading(body) == "You're all set!"
    assert "Background jobs run automatically" not in body, (
        "the desktop note must not appear on server installs"
    )
