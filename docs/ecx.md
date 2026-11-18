# eCash (ECX) payments

CashuPayServer can accept **eCash (ECX)** — the Drivechain hard fork of
Bitcoin launched by ecash.com on 2026-10-29 at block 973,728 — alongside
Bitcoin on-chain and Lightning. This is *not* eCash XEC (e.cash), the
Bitcoin Cash derived chain with CashAddr addresses; the two share a name
and nothing else.

## Why it is almost free to support

ECX kept Bitcoin's address and key formats byte for byte: `bc1q…`, `3…`
and `1…` addresses, `xpub`/`zpub` extended keys, coin type `0'`. An xpub
therefore yields exactly the same addresses on both chains. The eCash rail
is the existing on-chain engine ([docs/onchain.md](onchain.md)) run against
the ECX chain: same derivation, same Esplora watcher, same state machine
(`New` → `Processing` on first sighting → `Settled` at the required
confirmations → `Invalid` when the confirmation window lapses), same
static-address mode with per-invoice amount tweaks and manual attribution.

What differs:

| | Bitcoin on-chain | eCash (ECX) |
|---|---|---|
| Unit | sat (1e-8 BTC) | **szat** (1e-8 ECX) |
| Payment URI | `bitcoin:<addr>?amount=` | `ecash:<addr>?amount=` |
| Default explorers | mempool.space → blockstream.info | explorer.ecash.com → esplora.ecash.ninja |
| Price source | CoinGecko / Binance / Kraken | CoinMarketCap (ECX/USD), other currencies via the Bitcoin providers |
| Greenfield method | `BTC-OnChain` | `ECX-OnChain` |
| `settled_rail` | `onchain` | `ecx` |

### One address, one chain

Because an address is the same string on both chains, the per-xpub
derivation counter is **shared** between the rails: any address is shown
for exactly one invoice on exactly one chain. Reusing your Bitcoin xpub for
eCash is therefore safe — a Bitcoin invoice and an eCash invoice never
display the same address — and a transaction replayed from one chain onto
the other (unsplit post-fork coins) can only land on the invoice that
address belongs to. Observations are kept in a separate table
(`ecx_payments`) for the same reason: a replayed transaction carries the
same txid on both chains.

### Payment URI

The official eCash.com mobile wallet registers the `ecash:` scheme and
treats `bitcoin:` strictly as Bitcoin, so the QR code and the
"Pay with ECX wallet" button use `ecash:<address>?amount=<ECX>`. The bare
address is also copyable for wallets that use a different scheme
(Electrum-ECX uses `ecx:`). The payment page states next to the address
that BTC sent to it will not complete the invoice.

## Configuring

Admin panel → store settings → **eCash (ECX) payments** card:

- **Offer eCash to customers** — hide the tab without losing the settings.
- **Address source** — an xpub (recommended; **Use my Bitcoin xpub** fills
  in the one from the Bitcoin card) or a single static address. Validate
  and preview the first three addresses against your ECX wallet before
  saving.
- **Advanced** — address type, required confirmations and confirmation
  window (seeded from the Bitcoin card's values on first save, then
  independent: ECX retargets difficulty every 288 blocks, so block times
  vary more), your own ECX Esplora API URL with the same exclusivity /
  public-fallback rule as Bitcoin.

The card shows the current ECX/USD price. **When no price is available**
(CoinMarketCap unreachable and nothing cached within the last hour) the
eCash option is left off new invoices rather than quoting a stale amount;
the invoice detail's attempt log records the skip.

### Pricing

A fiat invoice is quoted as `amount / ECX price in that currency`; a
sats-denominated invoice goes through the BTC:ECX ratio. Only the ECX/USD
quote is fetched (CoinMarketCap's public web endpoint, no key); every other
currency is derived with the Bitcoin providers' cross rate. Long-lived
payment requests re-quote the szat amount on load like the Bitcoin amount
and remember the smallest amount ever shown.

## Fees in ECX

Payments taken in eCash accrue the same percentages as everything else —
the mandatory dev fee and the per-store hosting fee — but the fee is owed
**in ECX**, on its own ledger, because there is no Lightning or mint path
the cron could melt it over. It is collected only through the redirect
mechanism the Bitcoin rails also use: when a fee owed in ECX is at least an
invoice's szat amount, that invoice's **eCash address only** is derived from
the fee payee's xpub (dev: the BareBits zpub under the install's branch,
exactly like a Bitcoin dev-fee address; hosting: the **Hosting Fee eCash
(ECX) xpub** in the Hosting Fee card). The Bitcoin and Lightning options on
that invoice keep their normal routing. Settling over a fee-routed eCash
address writes an `ecx_fee_credits` row and suppresses the merchant's
"you were paid" notification, as a Bitcoin fee redirect does.

ECX-settled invoices are excluded from the sats revenue the Bitcoin fee
math uses, so nothing is double-counted.

## Dashboard

Statistics fold the eCash ledger into the headline totals at the current
ECX price (total revenue, total fees paid, fees owed, profit) and break it
out on its own rows: **eCash (ECX) revenue**, **Dev fee (eCash)**,
**Hosting fee (eCash)**, **Fees owed (eCash)**, all in szats with the fiat
value. Without an ECX price the eCash figures are shown but not folded in.
Charts gain an **eCash** series.

## Notifications, reports and statistics

- The merchant **"invoice paid" email** and the payer **receipt** for an
  invoice settled in eCash name what actually moved — `25,000,000 szats
  (0.25 ECX)` — alongside the fiat amount billed, label the rail
  `eCash (ECX)` and list the ECX receiving address and txid(s). The sats
  figure of such an invoice is only the quote and is not presented as what
  was received.
- The **diagnostic report** (Settings → Diagnostics) exports the
  `ecx_payments` and `ecx_fee_credits` tables, the ECX leg of each invoice
  and an `aggregates.ecx` block. Anonymized reports drop the ECX address, fee
  destination, manual candidates and txids exactly as they do for Bitcoin.
- **Anonymous usage statistics** (opt-in, see PRIVACY.md §1a) count stores
  with eCash configured (`stores_with_ecx`, rail `ecx`) and, at the Volume
  level, eCash settlements at the **sats they were quoted at**
  (`settled_30d_ecx_count` / `_sats`); no szat figure or address is ever
  reported. The collector in `scripts/analytics-endpoint/` must be deployed
  before a release carrying these fields, or level 2/3 reports are refused
  until it is.

Manual confirmation (static-address ambiguities) is listed per chain on the
Invoices view; eCash rows carry an `eCash (ECX)` chip, szat amounts and resolve
on the ECX chain (`resolve_onchain_manual` with `chain=ecx`).

## API

An invoice with an eCash address carries an `ECX-OnChain` payment method:

```json
"ECX-OnChain": {
  "destination": "bc1q…",
  "paymentLink": "ecash:bc1q…?amount=0.5",
  "amount": "50000000",
  "due": "50000000",
  "rate": 41.78
}
```

`amount` / `due` are szats; `rate` is the ECX price in the invoice
currency. A settled invoice reports `paymentRail: "ecx"` and an `ecx`
block (`amountSzat`, `rate`, `settledToFee`). Webhooks are unchanged.

## Test seams

- `CASHUPAY_ECX_ESPLORA_DEFAULT_URLS` — comma-separated Esplora bases
  replacing the built-in ECX explorer chain (honoured only while private
  endpoints are allowed).
- `CASHUPAY_CMC_API_BASE` — CoinMarketCap base URL for a local mock (same
  gate).
- `EcxRates::$testEcxUsd` — in-process price override for PHP unit tests
  (`0.0` simulates "no price").

## Tests

PHP unit tests (`tests/php/test_ecx_*.php`): chain descriptor, rates (seam
and live CoinMarketCap stub: cache / stale / cooldown / sanity), invoice
creation, poll and settle, shared counter, fee ledger, hosting-fee redirect,
stats, notifications, diagnostics export, expiry window, static-address
manual attribution (incl. a transaction replayed on both chains) and the
schema migration of a populated pre-ECX install.

End to end (`tests/e2e/test_ecx_payments.py`): the rig runs **two**
independent mock explorers — one for the Bitcoin chain, one for the ECX
chain — and a CoinMarketCap mock, so it can prove that each poller watches
its own chain and that BTC sent to an eCash address (or ECX sent to a Bitcoin
address) never settles an invoice. It covers the admin card (validation,
previews, custom explorer, offer toggle), allocation, settlement, webhooks,
emails, static-address collisions resolved per chain, the hosting-fee
redirect in ECX, and the Playwright flows (payment page tab / QR / live
confirmation copy, admin invoice list, detail modal, stats rows and the
manual-confirmation banner).
