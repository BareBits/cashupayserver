"""Customer payment page: cash back rewards (RewardsFlow), in a real browser
against the regtest stack (nutshell mint + LND payer).

The store runs 5 % rewards in sats. One payer walks the whole loop:

  1. Pays a plain invoice; the page flips to the success screen, the poll
     carries the earn and the claim prompt fills in ("You are due 1,000 sats
     in rewards. ..."); claiming with a username shows the credited line.
  2. Opens a second invoice, clicks "Apply rewards", enters the username: the
     page reloads with the original struck through, the effective amount, the
     "Rewards applied" line and Remove; pays the discounted bolt11; the earn
     on the NET amount is auto-credited (credited line, no prompt).
  3. A third invoice the balance covers outright: the apply settles it and
     the reload lands on the success screen without any payment.
  4. Skip on the claim prompt navigates to checkout.redirectURL.
"""
from __future__ import annotations

import pytest

from conftest import ConfiguredPayserver
from fixtures.lnd import LndHandle

pytestmark = pytest.mark.ui

USERNAME = "user123"
CLAIM_COPY = (
    "Pick a username to claim them. Pick a difficult to predict username, "
    "there is no password, many people use their phone number. Unclaimed rewards disappear."
)


def _enable_rewards(cfg: ConfiguredPayserver, percent: float = 5) -> None:
    with cfg.handle.db() as db:
        db.execute(
            "UPDATE stores SET rewards_enabled = 1, rewards_percent = ?, rewards_currency = 'sat' WHERE id = ?",
            (percent, cfg.store_id),
        )


def _row(cfg: ConfiguredPayserver, invoice_id: str) -> dict:
    with cfg.handle.db() as db:
        r = db.execute("SELECT * FROM invoices WHERE id = ?", (invoice_id,)).fetchone()
        assert r is not None, invoice_id
        return dict(r)


def _balance_sats(cfg: ConfiguredPayserver, username: str) -> int:
    with cfg.handle.db() as db:
        r = db.execute(
            "SELECT balance_micro FROM rewards_accounts WHERE store_id = ? AND username_key = ?",
            (cfg.store_id, username.lower()),
        ).fetchone()
    return int(r["balance_micro"]) // 1_000_000 if r else 0


def _bolt11(invoice: dict) -> str:
    return invoice["checkout"]["paymentMethods"]["BTC-LightningNetwork"]["destination"]


def _open(page, cfg: ConfiguredPayserver, invoice_id: str) -> None:
    page.goto(f"{cfg.handle.url}/payment?id={invoice_id}")
    page.wait_for_function(
        "() => { const el = document.getElementById('qr-lightning'); return el && el.childElementCount > 0; }"
    )


def _wait_success(page) -> None:
    page.wait_for_function(
        "() => { const el = document.getElementById('payment-success'); return el && el.classList.contains('show'); }",
        timeout=30000,
    )


def test_rewards_earn_claim_apply_and_full_coverage(
    shared_configured: ConfiguredPayserver,
    lnd_payer: LndHandle,
    page,
) -> None:
    cfg = shared_configured
    page.set_default_timeout(20000)
    _enable_rewards(cfg, 5)

    # ---- 1. earn on a plain payment, claim on the paid page -------------
    inv1 = cfg.greenfield.create_invoice(cfg.store_id, amount="20000", currency="sat")
    _open(page, cfg, inv1["id"])
    page.wait_for_selector("#payment-pending", state="visible")
    # Nothing to apply yet (no account), but the store runs rewards: the
    # Apply button is offered and the claim block waits hidden.
    assert page.locator("#rewards-apply-btn").is_visible()
    assert page.locator("#rewards-apply-form").is_hidden()
    assert page.locator("#rewards-claim").is_hidden()
    assert page.locator(".amount-original").count() == 0

    lnd_payer.pay_invoice_sync(_bolt11(inv1), timeout=30)
    _wait_success(page)
    # The poll that saw the settlement carried the earn: the prompt fills in.
    page.wait_for_selector("#rewards-claim", state="visible")
    text = page.locator("#rewards-claim .rewards-claim-text").inner_text()
    assert text.startswith("You are due 1,000 sats in rewards."), text  # 5 % of 20 000
    assert CLAIM_COPY in " ".join(text.split())
    assert page.locator("#rewards-credited").is_hidden()
    assert page.locator("#rewards-claim-skip").is_visible()

    page.fill("#rewards-claim-username", USERNAME)
    page.click("#rewards-claim-submit")
    page.wait_for_selector("#rewards-credited", state="visible")
    assert page.locator("#rewards-claim").is_hidden()
    credited = " ".join(page.locator("#rewards-credited").inner_text().split())
    assert credited == f"1,000 sats in rewards credited to {USERNAME}.", credited
    # The Continue button is only rendered with a redirectURL; the success
    # screen itself stays up (no auto-redirect).
    assert page.locator("#payment-success").is_visible()
    assert _balance_sats(cfg, USERNAME) == 1000

    # ---- 2. apply to the next invoice, pay the rest, earn auto-credited ---
    inv2 = cfg.greenfield.create_invoice(cfg.store_id, amount="5000", currency="sat")
    _open(page, cfg, inv2["id"])
    page.click("#rewards-apply-btn")
    page.wait_for_selector("#rewards-apply-form", state="visible")
    assert page.locator("#rewards-apply-btn").is_hidden()
    assert "Many people use their phone number." in page.locator("#rewards-apply-form .rewards-hint").inner_text()
    # A wrong username shows the server's text inline and keeps the form.
    page.fill("#rewards-apply-username", "nobody999")
    page.click("#rewards-apply-submit")
    page.wait_for_selector("#rewards-apply-status.error", state="visible")
    assert page.locator("#rewards-apply-status").inner_text() == "No rewards found for that username"
    page.fill("#rewards-apply-username", USERNAME)
    page.click("#rewards-apply-submit")
    # The apply reloads the page: the discount is rendered server-side.
    page.wait_for_selector("#rewards-remove-btn", state="visible")
    assert page.locator("#payment-pending .amount-original").inner_text() == "5000 SAT"
    assert page.locator("#payment-pending .amount-effective").inner_text() == "4000 SAT"
    assert page.locator("#rewards-applied-text").inner_text() == f"Rewards applied: −1000 SAT ({USERNAME})"
    assert page.locator("#rewards-apply-btn").count() == 0
    row = _row(cfg, inv2["id"])
    assert row["amount_sats"] == 4000
    assert row["rewards_spend_state"] == "reserved"
    assert _balance_sats(cfg, USERNAME) == 0, "whole balance reserved"
    # The Lightning QR / copy button carry the discounted bolt11.
    copied = page.locator('[data-method-block="lightning"] button[data-copy]').first.get_attribute("data-copy")
    assert copied == row["bolt11"] != _bolt11(inv2)

    lnd_payer.pay_invoice_sync(row["bolt11"], timeout=30)
    _wait_success(page)
    # Account known: the earn (5 % of the NET 4 000 = 200 sats) is credited
    # at settlement — credited line, no prompt.
    page.wait_for_selector("#rewards-credited", state="visible")
    assert page.locator("#rewards-claim").is_hidden()
    credited = " ".join(page.locator("#rewards-credited").inner_text().split())
    assert credited == f"200 sats in rewards credited to {USERNAME}.", credited
    assert page.locator("#payment-success .amount-original").inner_text() == "5000 SAT"
    assert page.locator("#payment-success .amount-effective").inner_text() == "4000 SAT"
    assert page.locator("#rewards-applied-success").inner_text().strip() == f"Rewards applied: −1000 SAT ({USERNAME})"
    # The pending screen (and its Remove) is hidden, not re-rendered.
    assert page.locator("#rewards-remove-btn").is_hidden()
    assert _balance_sats(cfg, USERNAME) == 200

    # ---- 3. full coverage: settled by the apply alone ----------------------
    inv3 = cfg.greenfield.create_invoice(cfg.store_id, amount="100", currency="sat")
    _open(page, cfg, inv3["id"])
    page.click("#rewards-apply-btn")
    page.fill("#rewards-apply-username", USERNAME.upper())  # case-insensitive
    page.click("#rewards-apply-submit")
    _wait_success(page)
    page.wait_for_selector("#payment-pending", state="hidden")
    assert page.locator("#payment-success .amount-original").inner_text() == "100 SAT"
    assert page.locator("#payment-success .amount-effective").inner_text() == "0 SAT"
    assert page.locator("#rewards-applied-success").inner_text().strip() == f"Rewards applied: −100 SAT ({USERNAME})"
    assert page.locator("#rewards-claim").is_hidden(), "nothing earned on full coverage"
    assert page.locator("#rewards-credited").is_hidden()
    row = _row(cfg, inv3["id"])
    assert row["status"] == "Settled"
    assert row["settled_rail"] == "rewards"
    assert row["amount_sats"] == 0
    assert _balance_sats(cfg, USERNAME) == 100
    assert cfg.greenfield.get_invoice(cfg.store_id, inv3["id"])["status"] == "Settled"


def test_rewards_claim_skip_follows_redirect_url(
    shared_configured: ConfiguredPayserver,
    page,
) -> None:
    cfg = shared_configured
    page.set_default_timeout(20000)
    _enable_rewards(cfg, 5)
    target = f"{cfg.handle.url}/health.php"
    inv = cfg.greenfield.create_invoice(
        cfg.store_id, amount="1000", currency="sat", checkout={"redirectURL": target}
    )
    cfg.greenfield.mark_invoice_status(cfg.store_id, inv["id"], "Settled")
    assert _row(cfg, inv["id"])["rewards_earned_micro"] == 50 * 1_000_000

    page.goto(f"{cfg.handle.url}/payment?id={inv['id']}")
    _wait_success(page)
    page.wait_for_selector("#rewards-claim", state="visible")
    text = page.locator("#rewards-claim .rewards-claim-text").inner_text()
    assert text.startswith("You are due 50 sats in rewards."), text
    # Order on the success screen: amount, claim block, invoice details,
    # then the Continue link.
    assert page.evaluate(
        """() => {
            const after = (a, b) => !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
            const amount = document.querySelector('#payment-success .amount');
            const claim = document.getElementById('rewards-claim');
            const details = document.querySelector('#payment-success .invoice-details');
            const cont = document.getElementById('redirect-btn');
            return after(amount, claim) && after(claim, details) && after(details, cont);
        }"""
    )
    assert page.locator("#redirect-btn").is_visible()
    page.click("#rewards-claim-skip")
    page.wait_for_url(lambda url: url.startswith(target), timeout=15000)
    # Nothing was claimed by skipping.
    assert _row(cfg, inv["id"])["rewards_claimed_at"] is None
