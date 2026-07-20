<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/barebits-logo.svg">
    <img src="assets/img/barebits-logo-dark.svg" alt="BareBits" width="360">
  </picture>
</p>

# BareBits
> **A BTCPay-compatible payment gateway that runs on any PHP hosting.**

Accept Bitcoin payments (lightning and on-chain) without running a full BTCPay Server instance. No Docker, no VPS, no command line. Just upload and go. **Low 1% fee**. 

**Are you a web developer? Re-sell this software to your customers at a custom fee rate. Your customers pay a x% fee, payments go directly to your LNURL.** Just modify the appropriate settings in the config file.

---

## ⚠️ AS-IS SOFTWARE - USE AT YOUR OWN RISK ⚠️

**This software is produced AS-IS without any warranty, do not use it to store significant funds**

- Do NOT use with amounts you cannot afford to lose
- The software may contain bugs that could result in loss of funds. Use the suggested default pattern of having funds go to a cold wallet to limit risk.

**You are responsible for your own funds. The developers are not liable for any losses.**

**Important:** A Cashu mint (if enabled) takes custody of your funds until you withdraw. For maximum sovereignty, run your own mint or enable auto-cashout to move funds immediately to your Lightning wallet.

---

## What is BareBits?

BareBits is a PHP-based Bitcoin Lightning payment gateway that implements BTCPay Server's Greenfield API. Any e-commerce software that works with BTCPay Server can work with BareBits. 

BareBits supports a number of payment types, risk/trust levels, and capabilities including:
 

### Key Features
- **Any PHP hosting** - Works on $3/month shared hosting. If it runs WordPress, it runs this.
- **No accounts or KYC**
- **Self-custody** - A fully self-custody solution, or add Cashu mint support for increased convenience
- **Multiple stores** - Each store can have it's own invoice settings, cashout addresses, etc.
- **BTCPay-compatible API** - WooCommerce and other BTCPay plugins work by changing one URL.
- **User management** - Admin users can modify store settings and products and withdraw funds, regular users can only take payments.
- **Product management** - Add commonly-used products to your store to make invoicing fast and clear
- **Receipts** E-mail receipts to your customers (optional)
 - **On-chain payments** to an off-server wallet using xpub addresses
 - **Lightning payments** to an LNURL lightning address and/or a cashu mint (no need to manage liquidity). No LNURL? Don't want to rely on a cashu mint? You can use dubmarine swaps so your customer's can pay in lightning but you receive the funds on-chain. Your customer pays the swap fee. Noffers/CLINK are also supported, so you can direct lightning payments to an off-server wallet ([Electrum](https://electrum.org/) is suggested)
 - **Offline** payments powered by Cashu tokens (optional), melded to lightning when back online.
- **Open source** - Read every line of code. Fork it, audit it yourself. Dual-licensed MIT (pre-2026-05-30) and Modified MIT (post-2026-05-30). See [LICENSE.md](LICENSE.md) and [USE_POLICY.md](USE_POLICY.md).

### Trade-offs

BareBits sits between custodial payment gateways and full self-hosting:

| Solution | Pros | Cons |
|----------|------|------|
| Custodial gateways like [OpenNode](https://opennode.com)| Easy setup | KYC, can freeze funds, geographic restrictions |
| [BTCPay Server](https://btcpayserver.org/) | Full sovereignty | Needs VPS ($20+/mo), Docker, ongoing maintenance |
| [Bitcart](https://bitcart.ai/) | Full sovereignty, limited lightning support | Needs smaller VPS ($10+/mo), Docker, ongoing maintenance |
| **BareBits** | Simple, cheap hosting | No KYC, trust mint with funds until withdrawal, or go full self-custody |


## Suggested Configurations
BareBits is robust payment software that can direct payments to you via many methods depending on your security and speed needs. It offers a ton of configuration options. Below are several suggested setups. No matter which setup you choose, on-chain payments will ALWAYS go to your on-chain wallet. Lightning payments can take several paths depending on your needs.

### Dead simple setup with automatic USD conversion
- Get an account at [strike.me](https://strike.me) and enable USD conversion in settings. Strike works in over 100 countries and native fiat currencies.
- You can grab an LNURL (lightning address) from your profile page and an on-chain adress from the receive tab.
- Note: Strike is a custodial exchange that holds onto funds for you, which means there is risk they may take them. Don't keep significant funds on exchanges.
- Note: Strike does not work with all kinds of merchants.
  
### Full self-custody setup (suggested, no USD conversion):
- Run an [Electrum](https://electrum.org/) wallet on your desktop computer and enable automatic liquidity management. Keep $100 or so in the wallet to keep liquidity flowing smoothly. You can start with zero and build up gradually as payments arrive. See [How to get an LNURL or CLINK Noffer](#how-to-get-an-lnurl-or-clink-noffer)
- Enable submarine swaps as a fallback in case your desktop is offline or doesn't have sufficient inbound liquidity.
- Suggestion: leave "strict mode" disabled. If your electrum wallet is unavailable AND a payment would be uneconomical to do a submarine swap for, lightning payments will land in a cashu mint (custodial) and be automatically withdrawn to your Electrum wallet once you have sufficient inbound liquidity OR will be withdrawn on-chain once it's economically reasonable.
- Need USD or other fiat currency? Use an exchange to convert your funds.

### On-chain Absolutist
Don't want to mess around with LNURLs or CLINK noffers? Just want everything to go to your cold on-chain wallet? No problem!
- Get an xpub or bare address from your wallet, add it to your BareBits store configuration
- Want your customers to be able to pay with lightning? Enable submarine swaps: your customers pay in lightning, you get funds on-chain
- Suggested: allow fallback to mint (strict mode disabled, the default) so smaller lightning payments are workable. Submarine swap providers won't let you make swaps < around $25. Funds will be temporarily stored in the cashu mint, then forwarded to you on-chain when fees permit.


## Installation

### Standalone (Any PHP Hosting)

1. **Download** the latest `cashupayserver.zip` from [GitHub Releases](https://github.com/BareBits/cashupayserver/releases)
2. **Extract** the zip file
3. **Upload** to your web hosting via FTP or file manager
4. **Open** the URL in your browser (e.g., `https://yourdomain.com/barebits/`)
5. **Follow** the setup wizard to configure your mint and password
6. **Customize** your store settings to your heart's content!

### Integration with ecommerce tools (woocommerce, magneto, etc)

BareBits integrates with most ecommerce platforms including woocommerce, magneto, drupal, and prestashop. Full list [here](https://docs.btcpayserver.org/FAQ/Integrations/#what-e-commerce-integrations-are-available).

1. Download the BTCPayServer plugin for your ecommerce platform
2. When asked to input your BTCPayServer URL, input your store API URL instead. You can find this URL in the store settings


### Recommended: Configure system cron

BareBits's background tasks — invoice polling, auto-cashout, and
fee settlement — run on a tight schedule when your hosting environment
invokes `cron.php` once a minute. Without it, the same tasks still fire
opportunistically when an admin or customer loads a page, but with
multi-minute latency. 

**This is particularly critical for submarine swaps: without cron, payments may not be claimed in time and may be refunded to the customer!**

Open **Settings** in the admin UI to copy the suggested entry, then add it
to your system crontab (or your host's cron panel). The admin dashboard
will show a one-time dismissable banner if external cron hasn't been
detected in over 24 hours.

## Payment Flow

Generally speaking, BareBits tries to offer both on-chain and lightning as payment options to all customers. On-chain payments are always enabled, lightning payments are enabled depending on configuration. Here's what that decision tree looks like:
- Is the customer paying on-chain? Send directly to merchant xpub wallet
- Does the merchant have a working LNURL or CLINK Noffer? Present a lightning invoice
- If submarine swaps are NOT enabled and a cashu mint is NOT enabled, do not present a lightning invoice.
- If submarine swaps ARE enabled AND the invoice amount falls within the swap provider's min/max limits, present a lightning invoice that settles directly to the merchant's on-chain wallet. When several providers are configured, the preferred (first reachable) provider is used unless another is cheaper by more than the auto-select threshold (`swaps_auto_select_threshold_pct`, default 10%).
- If submarine swaps are NOT enabled, or no provider can serve the amount, display a lightning invoice that sends payment to the cashu mint — unless strict mode is on, in which case the invoice is rejected instead. Funds are later emptied from the mint to the merchant's on-chain wallet by auto-cashout once it's worth it (auto-cashout caps the swap fee at 1% of the amount by default).


## Fee Payments and Structure

The BareBits software charges a 1% fee for usage. If you are a web developer, you can sell this service to your clients and charge an additional fee on top. Fees are paid in a number of ways.

 - When any invoice is generated where the invoice amount is < the fee due, the payment will be automatically redirected to the fee destination.
 - If any funds are in the cashu mint and a fee is due, those funds will be used to pay the fee

## Submarine Swaps (LN → on-chain, optional)

When enabled, BareBits can route a customer's Lightning payment through a
third-party submarine-swap provider (Zeus or Boltz) and settle the proceeds
**directly on-chain to the merchant's xpub** — without the cashu mint ever
holding the funds. This is useful for merchants who DON'T have a lightning address, 
DON'T want to trust a cashu mint, and DON'T want to manage their own liquidity. 
This can also be used as a fallback option if your lightning address is offline or
has no inbound liquidity.

The customer's experience is unchanged: they pay the same single BOLT11 invoice
on the checkout page.

**Pros**

- **Non-custodial.** Customer's funds never touch a mint, instead, funds go directly to your on-chain wallet
- **Provider-agnostic.** Both Boltz and Zeus are automatically enabled, Zeus is preferred but Boltz is used if significantly cheaper than Zeus.
- **Customer pays the fees.** Swap fees (provider percent + on-chain lockup  miner fee) are bundled into the LN invoice amount; the merchant receives the
  target sat amount on-chain net of fees.

**Cons**

- **Per-invoice fees.** Boltz currently charges ~0.5% + the lockup miner fee.
  Stats dashboard tracks both as a "Swap fees" line item. This means it's not adviseable for smaller invoice amounts.
- **Min/max amount limits.** Boltz mainnet currently enforces 10,000 sats min
  and 5,000,000 sats max per swap; invoices outside this range can't use the
  swap rail.
- **Provider dependency.** The provider must be reachable at invoice creation
  to bake an HTLC; the cron poller must run on a regular cadence to claim the
  on-chain output before the timeout. If neither runs, the customer's LN
  payment is refunded automatically (no loss), but the merchant receives
  nothing.
- **Optional mint fallback.** By default, if all configured providers are
  unreachable or the amount is out-of-range, BareBits falls back to the
  cashu mint (if enabled). You can enable "strict mode" in settings to disable the fallback
  and reject the invoice instead — useful for operators who want to eliminate
  the mint entirely from their payment flow.

**Enabling**

1. Go to *Settings* → *Submarine Swaps*. Toggle the master switch on, configure
   the provider preference order (`zeus,boltz` recommended; first reachable
   wins), and decide whether to allow mint fallback.
2. Each store that should use swaps needs an on-chain xpub configured under
   *Bitcoin* in that store's settings.
3. (Optional) Per-store override: a store can force swaps on or off
   independently of the site default.

## Requirements

- PHP 8.0 or higher
- Extensions: `curl`, `json`, `sqlite3`, `gmp`, `mbstring`
- Apache with mod_rewrite, nginx, or any PHP-capable web server

For on-chain Bitcoin payment support, the release zip ships with the required
PHP libraries (bitwasp/bitcoin et al.) under `vendor/`. Composer is only needed
if you're building from source (see Development below).

## How to get an LNURL or CLINK Noffer

If you want your customers to be able to pay natively via lightning (lowest fees, fastest payments), you will need to generate a lightning invoice for each customer. There are two ways to automate this: LNURLs (lightning address) and CLINK Noffers. Alternatively, you can use cashu mints and submarine swaps (accept customer payments in LN, automatically send to you on-chain).

### CLINK Noffers (suggested) (self-custody)
CLINK Noffers enable you to generate lightning invoices on the fly from a desktop wallet that is left running online. This means you maintain full self-custody over your funds! You will need to manage your liquidity (make sure you have room for incoming payments), but this can be automated fairly easily. BareBits will automatically fall back to submarine swaps, cashu mints, etc if there is no liquidity available or the wallet is offline, so it's not a big deal.

Suggested setup: [Electrum wallet](https://electrum.org/) with the [CLINK plugin](https://github.com/BareBits/electrum_clink) and [Liquidity management plugin](https://github.com/BareBits/electrum_clink). Once the CLINK plugin is installed, go to the settings and generate an noffer to add to your store settings page. You only need to generate a single noffer.

The default settings on these plugins should work, just be sure to set the liquidity management plugin to run in automatic mode.

### LNURL Providers (simplest, instant USD conversion)
Many centralized exchanges like [Strike](https://strike.me) offer LNURLs out of the box and can offer instant USD conversion. Note that these exchanges are custodial: they hold onto funds for you. This introduces risk of theft, exchange collapse, etc so we do not suggest storing significant funds on them.

| Provider   |      USD Conversion |  Notes |
|----------|:-------------:|------:|
| [Strike](https://strike.me) |  ✅ | Works in most countries, some merchant type restrictions, KYC process |
| [CoinOS](https://coinos.io) |    ✖️   |  Works in all countries, no restrictions, no KYC |
| [Rizful](https://rizful.com/) |  ✖️ |    Works in all countries, no restrictions, no KYC |

You can also get your own LNURL by hosting your own lightning node (rather complex, not suggested if you are not technically inclined)

### BareBits vs CashuPayServer
BareBits adds a number of enhancements to the original CashuPayServer software including on-chain payments, accepting cashu tokens, offline payments, automatic submarine swaps, automatic updates, security enhancements, LNURL support, user management, product management, a stats menu, and more. BareBits charges a **1% fee** for usage

## Security

### Database Protection

The `data/` directory contains your SQLite database with ecash tokens (real Bitcoin value). It **must** be protected from HTTP access.

**Apache**: The `.htaccess` file handles this automatically, if enabled and honored. Verify.

**nginx**: Add `location /data/ { deny all; }` to your config.

**Verify protection**:
```bash
curl -I https://yoursite.com/cashupayserver/data/cashupay.sqlite
# Should return 403 Forbidden or 404 Not Found, NOT the file!
```

**Best practice**: Store data outside web root by creating `includes/config.local.php`:
```php
<?php
define('CASHUPAY_DATA_DIR', '/home/user/cashupay-data');
```

### Seed Phrase

Your seed phrase is your backup. Write it down and store it safely. If you lose access to your server, you can recover your funds with the seed phrase.

**Warning**: Do not import the seed phrase into another wallet you use actively. Using the same seed in multiple wallets causes coin loss.

### Resetting a lost admin password

If you're locked out of the admin dashboard there are two ways to recover, both reachable from the **Forgot password?** link on the sign-in screen.

**Option 1 — Email a reset link.** Requires two things to be set up in advance:

- A **recovery email** on the admin account. Set it during the setup wizard (the optional "Recovery email" field on the password step) or later under **Settings → My Account → Recovery email**.
- **Outbound email (SMTP)** configured under **Settings → Email Notifications** in the admin UI (global, with an optional per-store override). As a fallback, SMTP can also be set via the `CASHUPAY_SMTP_*` settings in `user_config.php` (see `user_config.example.php`). Without working email this option cannot deliver the link.

Click **Forgot password? → Email me a reset link**, enter the admin recovery email, and open the link that arrives. The link is valid for **one hour** and can be used **once**. (For privacy, the page always reports success regardless of whether the address matched an account.)

**Option 2 — Reset via a file on the server.** Use this when you have filesystem access (SSH / SFTP / your host's file manager) but no working email. Create an **empty** file named `reset-admin-password` inside the server's data directory:

```bash
touch data/reset-admin-password
```

If you've relocated the data directory with `CASHUPAY_DATA_DIR`, create the file there instead (e.g. `/home/user/cashupay-data/reset-admin-password`). 

Then reload the sign-in page. It detects the file and shows a **"Set a new admin password"** form. Your existing password keeps working until you complete the form; once you submit a new password the trigger file is **deleted automatically**. Only the primary `admin` account is reset. If you created the file by mistake, just delete it.

## Development

### Quick Start with PHP Built-in Server

The simplest way to run BareBits locally:

```bash
git clone --recurse-submodules https://github.com/BareBits/cashupayserver.git
cd cashupayserver
# Install PHP dependencies (bitwasp/bitcoin for on-chain payment support).
# Composer is only needed for development and at build time — the deploy zip
# bundles vendor/ so end users don't need Composer on their hosting.
php composer.phar install --no-dev --ignore-platform-reqs   # or: composer install ...
php -S localhost:8000 router.php
```

If `composer.phar` isn't checked in, fetch it once with:

```bash
curl -sS https://getcomposer.org/installer | php
```

Open http://localhost:8000 in your browser.

The `router.php` handles routing and **blocks access to sensitive directories** even without Apache/nginx configuration.

### Docker Test Environment

Two Docker configurations are available for **testing only** (not production). Both include WordPress, WooCommerce, and the BTCPay plugin pre-installed with SQLite (no MySQL needed).

See [DOCKER.md](DOCKER.md) for detailed setup instructions, persistent data volumes, and troubleshooting.

#### Standalone + WordPress (for testing both)

```bash
git clone --recurse-submodules https://github.com/BareBits/cashupayserver.git
cd cashupayserver

docker build -f docker/Dockerfile.standalone -t cashupayserver-standalone .
docker run -p 80:80 -p 8080:8080 cashupayserver-standalone
```

This starts:
- **WordPress + WooCommerce**: http://localhost (login: admin/admin)
- **BareBits standalone**: http://localhost:8080

#### WordPress Plugin Only

```bash
docker build -f docker/Dockerfile.wordpress -t cashupayserver-wordpress .
docker run -p 80:80 cashupayserver-wordpress
```

This starts:
- **WordPress with BareBits plugin pre-installed**: http://localhost (login: admin/admin)
- Plugin available at **Tools → BareBits**

### Building Distribution Packages

```bash
# Build standalone zip
./scripts/build-standalone.sh
# Output: build/cashupayserver.zip

# Build WordPress plugin
./scripts/build-wordpress-plugin.sh
# Output: build/cashupay-wordpress.zip
```

### Building mint-discovery Bundle

If you modify the mint-discovery submodule:

```bash
cd mint-discovery
npm install
npm run build
cp dist/mint-discovery.bundle.js ../assets/js/
```

## Security for Development Environments

**When running directly from the git repository (not from the distribution zip), you MUST ensure your web server blocks access to sensitive files.**

The distribution zip only includes necessary files. The git repository contains test scripts, examples, and documentation that should never be web-accessible.

### What Needs Protection

| Directory/File | Contains | Risk if Exposed |
|---------------|----------|-----------------|
| `data/` | SQLite database with ecash tokens | **Critical** - Loss of funds |
| `includes/` | PHP classes | Code execution |
| `cashu-wallet-php/` | Library with test scripts | Code execution |
| `mint-discovery/` | Node.js source | Info leak |
| `scripts/` | Build scripts | Info leak |
| `docker/` | Docker configs | Info leak |

### Apache (Development)

The `.htaccess` file handles this if:
- `mod_rewrite` is enabled
- `AllowOverride All` is set

Verify it's working:
```bash
curl -I http://localhost/data/
# Should return 403 Forbidden

curl -I http://localhost/cashu-wallet-php/test-wallet.php
# Should return 403 Forbidden
```

### nginx (Development)

nginx ignores `.htaccess`. Add this to your server block:

```nginx
# Block sensitive directories
location ~ ^/(data|includes|cashu-wallet-php|mint-discovery|scripts|docker|docs|\.claude)/ {
    deny all;
    return 403;
}

# Block dotfiles
location ~ /\. {
    deny all;
}

# Block database files
location ~* \.(sqlite|db)$ {
    deny all;
}
```

### PHP Built-in Server

Using `router.php` is safe - it blocks sensitive paths:

```bash
php -S localhost:8000 router.php
```

**Do NOT use** `php -S localhost:8000` without the router - it will serve all files!

## API Reference

BareBits implements the BTCPay Server Greenfield API:

### Create Invoice
```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/invoices" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": "10.00", "currency": "EUR"}'
```

### Get Invoice
```bash
curl "https://yoursite.com/api/v1/stores/{storeId}/invoices/{invoiceId}" \
  -H "Authorization: token YOUR_API_KEY"
```

### Webhooks

Register webhooks to receive payment notifications:

```bash
curl -X POST "https://yoursite.com/api/v1/stores/{storeId}/webhooks" \
  -H "Authorization: token YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://yourshop.com/webhook",
    "authorizedEvents": {"everything": true}
  }'
```

Webhook events: `InvoiceCreated`, `InvoiceReceivedPayment`, `InvoiceProcessing`, `InvoiceProvisional`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid`

## Troubleshooting

### "404 Not Found" on API calls

Your server may not support URL rewriting. Use the front controller URL:
```
https://yoursite.com/cashupayserver/router.php
```

### "Forbidden" when accessing setup

Check that the web server can read PHP executables.

### Payments not detected

1. Check that your mint is online
2. Click the refresh button in your dashboard
3. Enable PHP error logging to see detailed errors

## Contributing

Contributions are welcome! Please discuss with us first prior to committing and making a PR.

## Forked from CashuPayServer

BareBits is a fork of [CashuPayServer](https://github.com/jooray/cashupayserver) by Juraj Bednár. The original project established the core idea: a PHP-only Bitcoin payment gateway built on Cashu mints, speaking the BTCPay Greenfield API. BareBits preserves that foundation and adds direct on-chain support, multi-user administration, security hardening, an in-app updater, a sustainable revenue model, and a comprehensive test suite — all while keeping the "upload and go" deployment story on any PHP host.

---

## License and Use Policy

> **Use of this software is subject to the BareBits Use Policy. You are free
> to use and modify this software as you wish, provided you do not remove the
> fee component. See [LICENSE.md](LICENSE.md) and [USE_POLICY.md](USE_POLICY.md)
> for full terms.**

This software is **dual-licensed**:

- All commits at or prior to `5812584` (dated 2026-05-30) are licensed under
  the **MIT License**.
- All subsequent commits are licensed under the **MODIFIED MIT LICENSE**,
  Copyright © 2026 Zaphaus LLC. The Modified MIT License permits use, copy,
  modify, merge, publish, distribute, sublicense and sell *subject to* a
  built-in fee component that may not be removed.

BareBits is self-hosted payment processing software. You may download, deploy,
and modify it on your own infrastructure (subject to applicable open-source
and third-party licenses). You are solely responsible for configuration,
security hardening, key custody, compliance, and any transactions processed
through your instance. To the maximum extent permitted by law, BareBits and
its creators disclaim liability for your deployment, modifications,
integrations, and downstream use, and provide the Software "as is" with no
warranties. BareBits does not provide a hosted service unless you have a
separate written Service Agreement.
