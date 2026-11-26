# Cash back rewards

BareBits ships an optional, per-store **cash back rewards** program: a
percentage of every payment is credited to a rewards account the payer
names, and that balance can be spent on the payer's next invoice at the
same store — up to covering it entirely. It is a loyalty mechanism, not a
wallet: balances are bookkeeping on your own server, nothing is custodied
and nothing leaves the server.

The pitch to merchants is simple. Card processors charge several percent;
BareBits charges 1%. Give a slice of the difference back as rewards and
your customers have a reason to come back and pay with Bitcoin again — and
you are *still* ahead.

## Enabling it

Rewards are off by default and switched on per store.

- **Setup wizard** — the "(Optional) Enable Rewards" screen (shown for
  every install type and when adding a store) takes the checkbox and the
  percentage; "Skip for now" leaves rewards off.
- **Store settings → Rewards card** — the same two controls afterwards,
  plus the currency the balances are kept in and a link to the Rewards
  view.
- **Admin → Rewards view** — an admin-only list of every rewards account
  (search by username; balance with its fiat/sats equivalent, lifetime
  earned and spent, last activity) with a CSV export. It can show one store
  or all stores. A store with rewards off can be switched on from here
  (percentage + "Enable rewards"); changes afterwards go through the Store
  settings card. A balance line "+ X held for an open invoice" is money the
  customer applied to an invoice that is not paid yet: it becomes spent when
  that invoice is paid and returns to the balance if it expires or the
  customer removes it.

The percentage must be between **0.0001% and 50%**, with up to four
decimal places.

## The money model

**Earn basis is net.** The percentage applies to what the payer actually
paid — the invoice amount minus any rewards they applied to it. Rewards
never earn rewards, so an invoice covered entirely by rewards earns
nothing.

**Balances live in the store's rewards currency.** When rewards are first
enabled for a store, the store's default currency at that moment becomes
its *rewards currency* (`sat`, or a fiat code such as `USD`) and balances
are kept in it from then on. Internally every balance is an integer number
of **micro-units** (one millionth of a sat or of a cent-bearing fiat unit),
so the fraction of a cent or of a sat a small invoice earns carries over
exactly to the next one; only display rounds (two decimals for fiat, whole
sats for sat) and only spending rounds (whole units of the invoice's
currency, floored).

**Accounts are per store.** The same username at two stores is two
unrelated accounts; nothing is shared between stores.

**Changing the store's default currency** while accounts hold balances
prompts the admin for a decision: **convert** every balance (and every open
reservation) to the new currency at today's rate, or **keep** them in the
old rewards currency. With "keep", balances continue to be displayed with
their converted value but are never moved.

**Lifecycle of a spend.** Applying rewards *reserves* the amount on the
account; settlement *commits* it (lifetime spent); an invoice that expires
or is marked invalid before it is paid *releases* it back to the balance. A
zero-conf on-chain payment that is later reversed (the invoice goes from
Settled to Invalid) *reverses* the earn, floored at zero. Each of those
steps is a `rewards_ledger` row keyed on (invoice, kind), so no race
between pollers can ever apply one twice.

## The payer's side

On a **pending** invoice of a store with rewards enabled, the payment page
offers **Apply rewards**. The payer types their username; their whole
balance is applied, capped at the invoice amount:

- **Partial coverage** — the amount owed drops (the original amount is
  shown struck through), every QR code and payment option is re-issued for
  the new amount, and a "Rewards applied: −X (username) · Remove" line
  appears. **Remove** undoes it before paying: the reservation is released
  and the invoice is re-issued at the full amount.
- **Full coverage** — when the balance covers the invoice, it settles on
  the spot (status `Settled`, payment rail `rewards`, zero sats received)
  and the payer lands on the paid screen.

On the **paid** screen:

- If rewards were applied, the account is known, so the earn is credited
  to it automatically — the page says "X in rewards credited to
  *username*".
- Otherwise the page says what the payer is due and asks for a username to
  claim it. Pick a hard-to-guess one — there is no password, and many
  people use their phone number. An account is created on first claim. The
  claim stays available on that paid page for **24 hours** after
  settlement; after that the unclaimed earn simply disappears. **Skip**
  dismisses the prompt (and follows the shop's redirect URL when the
  invoice has one).

Usernames are **3 to 64 letters and digits**, matched case-insensitively
(`Alice` and `alice` are the same account). There is no password: a
username is a usability feature, not a security boundary — anyone who
knows it can spend that balance on an invoice at that store. The
payment-page actions are rate limited per client like every other
payment-page request, which blunts guessing but does not make a short
username safe. Say so to your customers, and keep balances modest.

### What is excluded

- **Tip jar invoices** neither earn nor spend rewards.
- Invoices that are not `New` cannot have rewards applied; rewards cannot
  be applied twice to one invoice; and an invoice whose currency cannot be
  priced at the moment (no exchange rate) refuses the apply.

### Edge cases the implementation handles

- **Static shared on-chain / eCash address.** Stores that receive at a
  single fixed address tell invoices apart by their exact amount. When
  rewards change the amount, the *previous* total stays attributable to the
  invoice (`onchain_amount_alternates` / `ecx_amount_alternates`): the
  tweak picker treats it as used and the poller accepts either amount, so
  a payer who already sent the old amount still settles the invoice.
- **Fee-redirected invoices.** An invoice whose Lightning payment was
  redirected to a fee destination re-fetches a bolt11 from the same payee
  at the reduced amount; the redirect gate still holds because the owed
  fee covered the original, larger amount. A fee on-chain address is
  unique per invoice, so it is simply re-amounted.
- **Zero-conf reversal.** A Settled invoice later marked Invalid reverses
  its earn (never below zero) and leaves the committed spend as it is.
- **Expiry.** An invoice that expires with rewards applied releases the
  reservation back to the account.

## WooCommerce

The WordPress plugin never changes an order total — the gateway invoiced
the full amount and the server settled it. Instead, when a BareBits-paid
order completes, the plugin fetches the invoice once over the Greenfield
API and adds an **order note** describing what happened, for example:

- *Customer applied 1.20 USD of BareBits rewards (account alice); the
  remaining 8.80 USD was paid in Bitcoin. Customer earned 0.09 USD in
  BareBits rewards (claimed by alice).*
- *Paid entirely with BareBits rewards (account alice).*
- *Customer earned 0.09 USD in BareBits rewards (unclaimed).*

The note is written once per order (meta `barebits_rewards_note_added`);
an unreachable server is ignored silently — the order is already paid.

## API

`GET /api/v1/stores/{storeId}/invoices/{invoiceId}` carries a `rewards`
object when anything rewards-related happened on the invoice, and omits
the key otherwise. The same object rides the `InvoiceSettled` webhook as
`invoice.rewards`.

```json
"rewards": {
  "appliedAmount": "1.20",
  "appliedCurrency": "USD",
  "appliedUsername": "alice",
  "fullCoverage": false,
  "earnedAmount": "0.09",
  "earnedCurrency": "USD",
  "earnedClaimed": true,
  "earnedUsername": "alice"
}
```

| Field | Meaning |
|---|---|
| `appliedAmount`, `appliedCurrency` | Rewards spent on this invoice (null when none). |
| `appliedUsername` | The account they came from. |
| `fullCoverage` | `true` when rewards covered the whole invoice (`paymentRail` is then `rewards`). |
| `earnedAmount`, `earnedCurrency` | Cash back earned by paying this invoice, in the store's rewards currency (null when none). |
| `earnedClaimed` | Whether the earn has been credited to an account. |
| `earnedUsername` | The account it was credited to (null while unclaimed). |

`paymentRail` on a fully covered invoice is `rewards`; an invoice list or
CSV export shows the same value.

## Data

Everything is local to your installation's SQLite database:
`rewards_accounts` (one row per store + username, with balance, reserved,
lifetime earned and spent), `rewards_ledger` (every movement), and the
`rewards_*` columns on `invoices`. Usernames are often phone numbers —
treat the Rewards view and its CSV export as customer data. See
[PRIVACY.md](../PRIVACY.md) §7.
