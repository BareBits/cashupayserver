# BareBits Privacy Policy

_Last updated: September 15, 2026_

BareBits is **self-hosted** payment processing software published by Zaphaus LLC ("BareBits",
"we"). You download the software and run it on infrastructure you control. We do not operate a
hosted service, we have no access to your installation, and — with the single exception of the
development fee described in the next section — **no data about you, your store, or your
customers is ever transmitted to us**. The software contains no analytics, no telemetry, no
crash reporting, and no install "phone-home".

To process Bitcoin payments, your BareBits server does talk to other services over the
internet. You (the operator) are the data controller for your installation; this document
exists so you know exactly which external services can be contacted, what is sent to each,
and when — so you can make informed choices and, where required, disclose these flows to
your own customers in your own privacy policy. Services fall into three groups: the one flow
that reaches us, **services you choose** when configuring payment rails, **built-in services**
used with sensible defaults, and connections made **from a web browser** rather than by your
server.

## 1. The only data flow to BareBits: the development fee

The software collects a 1% development fee on incoming payments (disclosed and agreed to
during setup). Fees reach us in one of two ways: your server periodically pays out accrued
fees as a Lightning payment to our Lightning address (`fees@getbarebits.com`, resolved at
`getbarebits.com`), or — occasionally, when an owed fee covers a whole invoice — a customer
payment is redirected to a fee invoice directly. In both cases it is **your server** that
fetches the fee invoice from our Lightning-address host over HTTPS, so that host necessarily
learns:

- your **server's** IP address (from the invoice fetch — see the note below on what
  Lightning payments themselves reveal),
- the fee amount and the time of payment,
- on periodic settlements only, a deployment label sent as the payment comment
  (`Deployment: <id>`). Unless your installer or hosting provider explicitly configured a
  deployment ID, this label is the literal word `ANONYMOUS` — it is **not** a generated
  fingerprint of your installation. Redirected customer payments carry no such label.

**What about the payer of a fee invoice?** Lightning payments are onion-routed: the
recipient of a payment learns which node forwarded the final hop, not who initiated the
payment or from what IP address. So when a fee is paid by your mint melting ecash, or by a
redirected customer payment, we do not learn the payer's IP address or identity — not your
customer's, and not your server's — from the payment itself. The only IP we see is your
server's, from the HTTPS invoice fetch described above; your customers never connect to us.

No order data, customer data, store configuration, wallet keys, addresses, or revenue
figures other than the fee amount itself are included. This is everything we receive from a
running installation.

## 2. Services you choose and configure

These services are only contacted if you configure the corresponding payment rail, and only
the provider **you** select is contacted. Each provider has its own terms and privacy policy —
review them when choosing.

### Cashu mints

If you use the Cashu rail, your server talks to the mint(s) you configure: it requests
payment quotes (amounts and currency unit), redeems and swaps ecash (blinded messages and
proofs), checks proof spend-states, and — when paying out — submits the Lightning invoices
you are paying to. Your chosen mint acts as a custodian and necessarily sees your server's IP
address, every payment amount, payment timing, and the payout destinations of your melt
operations. This happens at invoice creation, during background payment polling, and on
withdrawals/auto-cashout.

**What the mint learns about your customers:** the mint issues the Lightning invoice each
customer pays, so it observes every sale — amount, time, and whether it was paid — and can
therefore reconstruct your store's sales pattern. It does not, however, learn who your
customers are: quote requests contain only the amount and unit (no order description,
customer name, or order metadata), customers' browsers never contact the mint (all
interaction goes through your server), and a Lightning payment does not reveal the payer's
IP address or identity to its recipient (payments are onion-routed; the recipient sees only
the final forwarding hop). When a customer pays with a Cashu token directly (offline
payments), your server — not the customer — submits the token to the mint for verification
and redemption. Independently of your store, the customer's *own* wallet or wallet service
may of course know what they paid for; that is between them and their wallet provider.

### Nostr relays (wallet connections and CLINK offers)

Two optional rails communicate through Nostr relays **you** supply:

- **Nostr Wallet Connect (NWC):** invoice requests and lookups are sent to the relay named in
  your `nostr+walletconnect://` connection string. Message contents (amounts, descriptions,
  payment hashes) are end-to-end encrypted to your wallet; the relay still sees your server's
  IP address, the connection's public keys, and message timing.
- **CLINK / noffer:** payment requests are sent to the relay(s) embedded in your noffer
  string, encrypted, using a fresh throwaway key per request. The relay sees your server's IP
  address, your offer's public key, and timing. On this rail the **customer's browser** also
  subscribes to the same relay to hear about settlement — see section 4.

Saving an NWC connection performs a real 1-sat test invoice to verify it works.

### Lightning address / LNURL providers

If you set a Lightning address (yours, at a provider you chose) as a payment destination or
cashout target, your server fetches `https://<your provider>/.well-known/lnurlp/<name>` and
the callback URL the provider returns. The provider sees your server's IP address, the
address username, exact amounts in millisatoshis, payment timing, and — on cashouts — a
comment reading `BareBits auto-cashout` or `BareBits withdrawal`. Saving a destination
performs a small test invoice request to verify the provider supports payment verification.

### Strike

If you connect a [Strike](https://strike.me) account, your server calls `api.strike.me` with
your API key to create invoices (amount, an order description truncated to 200 characters,
and the BareBits invoice ID as a correlation ID), fetch quotes, poll payment status, and —
if you enable Strike on-chain receiving — request fresh deposit addresses in your Strike
account. Only receive-side API scopes are used; the software never holds spend permissions.
Saving a Strike key performs a real 1-sat test invoice (it will remain, unpaid, in your
Strike history). Strike: [Terms of Service](https://strike.me/legal/tos) ·
[Privacy Policy](https://strike.me/legal/privacy).

### Your e-commerce webhooks

Webhooks you register (for example, for WooCommerce) receive BTCPay-compatible JSON on
invoice events: invoice ID, store ID, amount, currency, status, and the order metadata your
shop attached. Deliveries are HMAC-signed and go only to URLs you configured — normally your
own shop.

### Email (SMTP)

If you configure email notifications, messages are sent through the SMTP server **you**
supply (there is no default and no BareBits mail relay). Depending on which notifications you
enable, messages can contain invoice IDs and amounts, payout destinations, customer email
addresses, and — on payment receipts requested by a customer — the mint hostname or on-chain
address and transaction IDs of their payment. Your SMTP provider's privacy policy applies.

### Other operator-configured endpoints

- **Trusted-mint list** (off by default): if you set a list URL, it is fetched about once a
  day. Nothing is sent beyond the request itself.
- **Custom block explorer / Bitcoin Core:** the on-chain rail's default explorer (section 3)
  can be replaced with your own Esplora instance or your own Bitcoin Core node, in which case
  no third party is involved in chain watching.

## 3. Built-in services (default configuration)

### Exchange rates — CoinGecko, Binance, Kraken

Stores priced in fiat need a BTC exchange rate. The server queries, in fallback order,
[CoinGecko](https://www.coingecko.com) ([Terms](https://www.coingecko.com/en/terms) ·
[Privacy](https://www.coingecko.com/en/privacy)),
[Binance](https://www.binance.com) ([Terms](https://www.binance.com/en/terms) ·
[Privacy](https://www.binance.com/en/about-legal/privacy-portal)) and
[Kraken](https://www.kraken.com) ([Terms](https://www.kraken.com/legal) ·
[Privacy](https://www.kraken.com/legal/privacy)). The request contains only your store's
currency code; the provider sees your server's IP address and request timing. Results are
cached for five minutes. Stores priced in satoshis never trigger these requests.

### Bitcoin block explorer — mempool.space

The on-chain rail watches for payments by querying
[mempool.space](https://mempool.space) ([Terms](https://mempool.space/terms-of-service) ·
[Privacy](https://mempool.space/privacy-policy)) unless you configure your own explorer or
node (section 2). mempool.space sees your server's IP address and **the individual receiving
addresses your store generates** (your extended public key itself is never transmitted —
addresses are derived locally), queried repeatedly while an invoice is pending. It is also
used for fee estimates and as a fallback for broadcasting swap-claim transactions.

### Submarine swap providers — Zeus, Boltz

If you enable swaps (off by default; converts Lightning receipts to on-chain funds), the
server requests quotes from the enabled providers and creates swaps with the cheaper one:
[Zeus](https://zeusln.com) (`swaps.zeuslsp.com`,
[Privacy](https://zeusln.com/privacy-policy)) and
[Boltz](https://boltz.exchange) ([Terms](https://boltz.exchange/terms) ·
[Privacy](https://boltz.exchange/privacy)). The chosen provider sees your server's IP
address, swap amounts and timing, per-swap public keys, and the claim transaction — which
contains the on-chain address the funds land on.

### Software updates — GitHub

Once a day the server asks GitHub (`api.github.com`) whether a newer release exists, so the
dashboard can show an update banner. The request is anonymous: GitHub sees your server's IP
address and a `cashupayserver-updater` user agent — your installed version and configuration
are **not** sent; version comparison happens locally. Release files are downloaded from
GitHub only when an update is actually applied (automatic updates are opt-in on source
installs and enabled by default in the official Docker image; the Windows desktop package
disables the updater entirely). GitHub:
[Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) ·
[Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

### IP-geolocation database — DB-IP

About once a month the server downloads the free IP-to-country database from
[DB-IP](https://db-ip.com) ([Terms](https://db-ip.com/tos.php) ·
[Privacy](https://db-ip.com/privacy.php)) so the admin can see which country a Cashu mint is
hosted in. Only the download request itself (your server's IP address) reaches DB-IP. **All
lookups run locally against the downloaded file** — no customer IP address, mint URL, or any
other data is ever sent to DB-IP, and the software never geolocates your customers at all.

## 4. Connections made from web browsers

Some connections originate in a browser (yours or your customer's) rather than from your
server, so the *browser's* IP address and user agent are what the service sees. All
JavaScript, stylesheets, fonts, and images are served from your own server — no CDN is in
the path of any page, and in particular QR codes are rendered entirely by locally-served
code, so no third party can observe payment pages or tamper with what a QR encodes. The
remaining browser-side connections are:

- **Mint discovery (admin only, on demand):** when you open the mint-discovery browser in the
  admin or setup wizard, **your browser** connects read-only to four public Nostr relays
  (`relay.damus.io`, `relay.8333.space`, `nos.lol`, `relay.primal.net`) to fetch mint
  announcements and reviews, and then fetches `/v1/info` from each discovered mint to check
  it is alive. Nothing is published; no keys or identity are involved; the relays and mints
  see your browser's IP address.
- **Payment page on the CLINK rail:** when an invoice uses a CLINK noffer, the customer's
  browser opens a read-only connection to the Nostr relay from your noffer string to learn of
  settlement quickly. That relay sees the customer's IP address. Other rails do not do this.
- **Plain links:** pages link to external sites (for example, transaction views on
  mempool.space, wallet vendors on the payment page, documentation). These transmit nothing
  unless clicked.

## 5. What the software does *not* do

- No analytics, tracking pixels, tag managers, telemetry, or error/crash reporting — to us or
  to anyone else.
- No third-party CDNs: every script, stylesheet, font, and image is served by your own
  server. No customer geolocation.
- Customer IP addresses are not sent to any geolocation or reputation service.
- The diagnostics report in the admin is generated locally as a download for you to inspect
  and share manually if you seek support; nothing is uploaded automatically.
- We cannot access, recover, or delete anything on your server — including wallet keys.

## 6. The WordPress plugin

The companion WordPress plugin talks only to **your own** BareBits server (setup wizard,
dashboard sign-on, background-task pings, and the checkout API bridge), to the
**wordpress.org** plugin directory ([Privacy](https://wordpress.org/about/privacy/)) to
install the WooCommerce payment gateway, and — in the full build distributed on GitHub
releases only — to **GitHub** to download a BareBits server for the optional
install-alongside flow. The plugin's readme documents these in detail; none of them send data
to BareBits.

## 7. Data stored on your server

Invoices, order metadata from your shop, ecash proofs and wallet material, customer email
addresses (only when a customer requests an email receipt), and operational logs are stored
in your installation's local database and data directory. They stay on your infrastructure,
under your control and your responsibility (see the
[Terms of Use](https://github.com/BareBits/cashupayserver/blob/main/USE_POLICY.md)).

## 8. A note on DNS

Any outbound connection above also resolves the target hostname through the DNS resolver
your server (or the visitor's device) is configured to use, which therefore sees the
hostnames being contacted. This is a property of the internet, not of BareBits, but for
completeness: choose your resolver accordingly.

## 9. Changes and contact

We update this document when the software's external connections change; the revision
history is public in the project's Git repository. Questions: via
[getbarebits.com](https://getbarebits.com) or the project's
[GitHub issues](https://github.com/BareBits/cashupayserver/issues).
