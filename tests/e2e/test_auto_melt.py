"""Auto-melt: when store balance exceeds threshold, the cron task should
issue a melt to the configured Lightning address and the receiving LND
node should observe a settled BOLT11 invoice for ~that amount."""
from __future__ import annotations

import time

import pytest

from conftest import ConfiguredPayserver
from fixtures import backend
from fixtures.lnd import LndHandle
from fixtures.lnurlp_server import LnurlpServer


SETTLE_AMOUNT_SAT = 5000
AUTO_MELT_THRESHOLD_SAT = 100  # well below 5000 so the cron will trigger


def _settle_invoice(configured: ConfiguredPayserver, lnd_payer: LndHandle, amount_sat: int) -> None:
    invoice = configured.greenfield.create_invoice(
        configured.store_id, amount=str(amount_sat), currency="sat"
    )
    bolt11 = invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]
    lnd_payer.pay_invoice_sync(bolt11, timeout=30)
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        if configured.greenfield.get_invoice(configured.store_id, invoice["id"])["status"] == "Settled":
            return
        time.sleep(0.3)
    raise AssertionError("source invoice did not settle")


def _enable_auto_melt(configured: ConfiguredPayserver, address: str, threshold_sat: int) -> None:
    # Destination chain and cashout toggle are separate actions now: the
    # Lightning destination goes to save_lightning_payments, then the
    # enable/threshold toggle to save_auto_melt (which no longer accepts or
    # returns addresses).
    result = configured.admin._post_action(
        "save_lightning_payments",
        store_id=configured.store_id,
        address=address,
    )
    assert result.get("success"), result
    result = configured.admin._post_action(
        "save_auto_melt",
        store_id=configured.store_id,
        enabled="1",
        threshold=str(threshold_sat),
        mode_override="0",
    )
    assert result.get("success"), result
    assert "addresses" not in result, result


def _store_balance(configured: ConfiguredPayserver) -> int:
    r = configured.admin.s.get(
        f"{configured.handle.url}/admin?api=dashboard&store_id={configured.store_id}",
        timeout=15,
    )
    r.raise_for_status()
    return int(r.json()["balance"])


def _run_auto_melt_cron(configured: ConfiguredPayserver) -> object:
    """Trigger cron and return the parsed `auto_melt` task result, tolerating
    non-JSON preamble lines the cron may emit before its JSON payload."""
    r = configured.handle.trigger_cron()
    assert r.status_code == 200, r.text
    body_text = r.text.strip()
    try:
        cron_body = r.json()
    except Exception:
        import json as _json

        idx = body_text.find("{")
        cron_body = _json.loads(body_text[idx:]) if idx >= 0 else {}
    return cron_body.get("tasks", {}).get("auto_melt")


def test_auto_melt_drains_balance_to_lightning_address(
    configured_with_lnurlp: ConfiguredPayserver,
    lnd_payer: LndHandle,
    lnurlp_server: LnurlpServer,
) -> None:
    configured = configured_with_lnurlp

    # 1. Fund the store via a paid invoice.
    _settle_invoice(configured, lnd_payer, SETTLE_AMOUNT_SAT)
    assert _store_balance(configured) >= SETTLE_AMOUNT_SAT

    payer_channel_before = lnd_payer.channel_balance_sat()

    # 2. Enable auto-melt; the LNURL mock domain doesn't have to resolve in DNS
    #    because CASHU_LNURL_URL_TEMPLATE overrides the URL builder.
    _enable_auto_melt(configured, address="test@example.test", threshold_sat=AUTO_MELT_THRESHOLD_SAT)

    # 3. On the deterministic php -S backend only our trigger runs cron, so
    #    this exact run must report the task fired. On apache/nginx an
    #    opportunistic background cron (Background::trigger on page loads) may
    #    race us and drain first, making any particular run report "skipped" —
    #    there the drained *outcome* below is the assertion.
    if backend.is_phps():
        auto_melt_result = _run_auto_melt_cron(configured)
        assert auto_melt_result and auto_melt_result != "skipped", (
            f"auto_melt task didn't run; result={auto_melt_result!r}"
        )

    # 4. Drive cron until the drain is observable: balance near zero and the
    #    funds delivered to the receiving LND (allowing 1-100 sat regtest
    #    routing fees).
    configured.handle.drive_cron_until(
        lambda: _store_balance(configured) < AUTO_MELT_THRESHOLD_SAT
        and lnd_payer.channel_balance_sat() - payer_channel_before
        >= SETTLE_AMOUNT_SAT - 200,
        timeout_s=60,
        label="auto-melt drains the balance to the lightning address",
    )

    delta = lnd_payer.channel_balance_sat() - payer_channel_before
    assert delta >= SETTLE_AMOUNT_SAT - 200, (
        f"expected ~{SETTLE_AMOUNT_SAT} sats delivered, got delta={delta}"
    )
    remaining = _store_balance(configured)
    # After a melt the residual change is folded back as new proofs; the
    # remainder should be small relative to the melt amount.
    assert remaining < AUTO_MELT_THRESHOLD_SAT, f"store balance not drained: {remaining}"


def test_auto_melt_ignores_threshold_for_lightning(
    configured_with_lnurlp: ConfiguredPayserver,
    lnd_payer: LndHandle,
    lnurlp_server: LnurlpServer,
) -> None:
    """The LNURL/noffer rail is the always-on primary drain: it cashes the mint
    balance out to the configured Lightning address every cycle regardless of
    auto_melt_threshold. Funding a balance far *below* a huge threshold must
    still drain — under the old threshold-gated behaviour it would not have."""
    configured = configured_with_lnurlp

    # 1. Fund the store, then set a threshold far above the funded balance.
    _settle_invoice(configured, lnd_payer, SETTLE_AMOUNT_SAT)
    assert _store_balance(configured) >= SETTLE_AMOUNT_SAT

    payer_channel_before = lnd_payer.channel_balance_sat()

    huge_threshold = SETTLE_AMOUNT_SAT * 1000  # balance is nowhere near this
    _enable_auto_melt(configured, address="test@example.test", threshold_sat=huge_threshold)

    # 2. The drain must fire even though balance < threshold. Same backend
    #    split as above: pin the task result only where cron is deterministic.
    if backend.is_phps():
        auto_melt_result = _run_auto_melt_cron(configured)
        assert auto_melt_result and auto_melt_result != "skipped", (
            f"auto_melt task didn't run despite a fundable balance; result={auto_melt_result!r}"
        )

    # 3. Funds were delivered to the receiving node and the mint was drained.
    configured.handle.drive_cron_until(
        lambda: _store_balance(configured) < 500
        and lnd_payer.channel_balance_sat() - payer_channel_before
        >= SETTLE_AMOUNT_SAT - 200,
        timeout_s=60,
        label="lightning drain fires despite huge threshold",
    )

    delta = lnd_payer.channel_balance_sat() - payer_channel_before
    assert delta >= SETTLE_AMOUNT_SAT - 200, (
        f"expected ~{SETTLE_AMOUNT_SAT} sats delivered, got delta={delta}"
    )
    remaining = _store_balance(configured)
    assert remaining < 500, (
        f"store balance not drained despite huge threshold: {remaining}"
    )
