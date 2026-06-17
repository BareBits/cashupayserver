"""Webhook delivery semantics: event filtering, multiple subscribers, retry."""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.webhook_sink import WebhookSink


def test_multiple_webhooks_each_receive_event(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("alpha"), secret="a"
    )
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("beta"), secret="b"
    )

    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    alpha = webhook_sink.wait_for("/hook/alpha", count=1, timeout_s=15)
    beta = webhook_sink.wait_for("/hook/beta", count=1, timeout_s=15)

    assert alpha[0].json()["type"] == "InvoiceCreated"
    assert beta[0].json()["type"] == "InvoiceCreated"


def test_event_filter_only_delivers_subscribed_events(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """A webhook subscribed only to InvoiceSettled should NOT receive InvoiceCreated."""
    configured.greenfield.create_webhook(
        configured.store_id,
        webhook_sink.endpoint("settled-only"),
        secret="x",
        authorized_events={"specificEvents": ["InvoiceSettled"]},
    )

    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    # Brief wait — if the filter is wrong, an InvoiceCreated webhook would land.
    import time
    time.sleep(2)
    captured = webhook_sink.by_path("/hook/settled-only")
    types = [r.json().get("type") for r in captured]
    assert "InvoiceCreated" not in types, f"filter not honored, saw: {types}"


def test_webhook_delivery_logged_in_database(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """Each delivery attempt should create a row in webhook_deliveries."""
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("logged"), secret="x"
    )
    invoice = configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    webhook_sink.wait_for("/hook/logged", count=1, timeout_s=15)

    with configured.handle.db() as db:
        deliveries = db.execute(
            "SELECT event_type, status_code, invoice_id FROM webhook_deliveries WHERE invoice_id = ?",
            (invoice["id"],),
        ).fetchall()
    assert len(deliveries) >= 1, "no delivery row written"
    assert any(d["event_type"] == "InvoiceCreated" for d in deliveries)
    assert all(200 <= d["status_code"] < 300 for d in deliveries), [dict(d) for d in deliveries]


def test_webhook_retried_on_5xx_response(
    configured: ConfiguredPayserver,
    webhook_sink: WebhookSink,
) -> None:
    """A 5xx response should re-queue the delivery; a later cron drain retries it.

    Delivery is now an outbox (enqueue -> drain with backoff). The first attempt
    fails (503) and the row is re-queued with a future next_retry_at; subsequent
    cron ticks past the backoff window perform the retry. We drive cron to
    advance the outbox rather than waiting on a real operator cron.
    """
    import time

    webhook_sink.force_response("/hook/retry", 503)
    configured.greenfield.create_webhook(
        configured.store_id, webhook_sink.endpoint("retry"), secret="x"
    )
    configured.greenfield.create_invoice(configured.store_id, amount="500", currency="sat")

    # First attempt (fails with 503).
    webhook_sink.wait_for("/hook/retry", count=1, timeout_s=15)

    # Drive cron across the retry backoff window until the retry lands.
    deadline = time.time() + 45
    while time.time() < deadline:
        if len(webhook_sink.by_path("/hook/retry")) >= 2:
            break
        configured.handle.trigger_cron()
        time.sleep(3)

    captured = webhook_sink.by_path("/hook/retry")
    assert len(captured) >= 2, f"expected at least one retry, saw {len(captured)}"

    # Once the endpoint recovers, the re-queued delivery succeeds. We force the
    # pending row due now (rather than waiting out the production backoff) so the
    # test stays fast; this exercises the drain's success path on a recovered
    # endpoint.
    webhook_sink.force_response("/hook/retry", 200)
    with configured.handle.db() as db:
        db.execute(
            "UPDATE webhook_deliveries SET next_retry_at = 0 "
            "WHERE event_type = 'InvoiceCreated' AND status = 'pending'"
        )
    deadline = time.time() + 30
    delivered = False
    while time.time() < deadline:
        configured.handle.trigger_cron()
        with configured.handle.db() as db:
            row = db.execute(
                "SELECT status FROM webhook_deliveries "
                "WHERE event_type = 'InvoiceCreated' AND status = 'delivered' LIMIT 1"
            ).fetchone()
        if row is not None:
            delivered = True
            break
        time.sleep(2)
    assert delivered, "delivery should reach 'delivered' once the endpoint recovers"
