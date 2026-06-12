<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/barebits-logo.svg">
    <img src="assets/img/barebits-logo-dark.svg" alt="BareBits" width="360">
  </picture>
</p>

# BareBits
## ⚠️ DO NOT USE THIS WE ARE MOVING FAST AND BREAKING THINGS IN THIS CODE, THERE IS A SIGNIFICANT RISK OF FUNDS LOSS ⚠️
> **A BTCPay-compatible payment gateway that runs on any PHP hosting.**

Accept Bitcoin Lightning payments without running a full BTCPay Server instance. No Docker, no VPS, no command line. Just upload and go.

**Forked from [cashupayserver.org](https://cashupayserver.org/)**

---

## ⚠️ EXPERIMENTAL SOFTWARE - USE AT YOUR OWN RISK ⚠️

**This project is in early development and has NOT been thoroughly tested in production environments.**

- Do NOT use with amounts you cannot afford to lose
- Test with small transactions first
- The software may contain bugs that could result in loss of funds
- No warranty is provided - see the LICENSE file
- Security audits have not been performed

**You are responsible for your own funds. The developers are not liable for any losses.**

**Important:** A selected Cashu mint takes custody of your funds until you withdraw. For maximum sovereignty, run your own mint or enable auto-withdrawal to move funds immediately to your Lightning wallet.

---

## What is BareBits?

BareBits is a PHP-based Bitcoin Lightning payment gateway that implements BTCPay Server's Greenfield API. It uses [Cashu](https://cashu.space/) mints to handle Lightning payments, so you don't need to run your own Lightning node.

```
E-commerce (WooCommerce, etc.)
    │
    ▼ (Greenfield API)
BareBits (PHP)
    │
    ▼ (Cashu Protocol)
Cashu Mint ──► Lightning Network
```

### Key Features

- **Any PHP hosting** - Works on $3/month shared hosting. If it runs WordPress, it runs this.
- **BTCPay-compatible API** - WooCommerce and other BTCPay plugins work by changing one URL.
- **No accounts or KYC** - Your store talks to the mint's public API directly.
- **Pure Lightning experience** - Customers see a normal Lightning invoice.
- **On-chain Bitcoin payments** - Accept direct Bitcoin transactions alongside Lightning. Funds go straight to *your* wallet (xpub-derived addresses) — never to the mint. See [docs/onchain.md](docs/onchain.md).
- **Auto-withdrawal** - Optionally send funds directly to your Lightning address.
- **Open source** - Read every line of code. Fork it, audit it yourself. Dual-licensed MIT (pre-2026-05-30) and Modified MIT (post-2026-05-30). See [LICENSE.md](LICENSE.md) and [USE_POLICY.md](USE_POLICY.md).

### Trade-offs

BareBits sits between custodial payment gateways and full self-hosting:

| Solution | Pros | Cons |
|----------|------|------|
| Custodial gateways | Easy setup | KYC, can freeze funds, geographic restrictions |
| BTCPay Server | Full sovereignty | Needs VPS ($20+/mo), Docker, ongoing maintenance |
| **BareBits** | Simple, cheap hosting | Trust mint with funds until withdrawal |

## Submarine Swaps (LN → on-chain, optional)

When enabled, BareBits can route a customer's Lightning payment through a
third-party submarine-swap provider (Zeus or Boltz) and settle the proceeds
**directly on-chain to the merchant's xpub** — without the cashu mint ever
holding the funds.

The customer's experience is unchanged: they pay the same single BOLT11 invoice
on the checkout page. Behind the scenes, the provider holds the LN payment
until BareBits has claimed the corresponding on-chain output via a
Taproot HTLC; the held LN invoice is then settled atomically with the on-chain
claim.

**Pros**

- **Non-custodial.** Customer's funds never touch a mint. The provider holds the
  LN payment for at most the swap window (typically tens of minutes); on success
  the LN payment settles only when the on-chain claim completes. On failure the
  LN HTLC times out and the customer's wallet refunds itself automatically.
- **Direct on-chain settlement.** Funds land at an xpub-derived address you
  control, discoverable by any standard wallet that scans the xpub.
- **Provider-agnostic.** Site-wide preference order across multiple providers;
  if the primary is unreachable at invoice creation, the next one is tried.
- **Customer pays the fees.** Swap fees (provider percent + on-chain lockup
  miner fee) are bundled into the LN invoice amount; the merchant receives the
  target sat amount on-chain net of fees.

**Cons**

- **Per-invoice fees.** Boltz currently charges ~0.5% + the lockup miner fee.
  Stats dashboard tracks both as a "Swap fees" line item.
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
  cashu mint. You can enable "strict mode" in settings to disable the fallback
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

**Providers and testnet**

| Provider | Mainnet | Testnet | Regtest |
|----------|---------|---------|---------|
| Zeus     | `https://swaps.zeuslsp.com/api`       | `https://testnet-swaps.zeuslsp.com/api` | — |
| Boltz    | `https://api.boltz.exchange`          | — (discontinued upstream)               | configurable (`swaps_boltz_regtest_url`) |

**Out of scope for this version (planned follow-ups)**

- Cooperative musig2 keypath claim (currently script-path only — slightly less
  private on-chain, otherwise functionally identical).
- On-chain → LN direction (only LN → on-chain is implemented; interface is
  designed to accommodate it later).

## Requirements

- PHP 8.0 or higher
- Extensions: `curl`, `json`, `sqlite3`, `gmp`, `mbstring`
- Apache with mod_rewrite, nginx, or any PHP-capable web server

For on-chain Bitcoin payment support, the release zip ships with the required
PHP libraries (bitwasp/bitcoin et al.) under `vendor/`. Composer is only needed
if you're building from source (see Development below).

## Installation

### Standalone (Any PHP Hosting)

1. **Download** the latest `cashupayserver.zip` from [GitHub Releases](https://github.com/jooray/cashupayserver/releases)
2. **Extract** the zip file
3. **Upload** to your web hosting via FTP or file manager
4. **Open** the URL in your browser (e.g., `https://yourdomain.com/cashupayserver/`)
5. **Follow** the setup wizard to configure your mint and password

### WordPress Plugin

1. **Download** `cashupay-wordpress.zip` from [GitHub Releases](https://github.com/jooray/cashupayserver/releases)
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. **Upload** the zip file and activate
4. Go to **Tools → BareBits** to configure
5. Optionally integrates with the BTCPay for WooCommerce plugin if installed (configured during setup), or paste the pairing URL into BTCPay plugin settings manually

### Deployment Options

#### Option 1: Shared Hosting (No Server Configuration)

Upload files to your hosting and use the **front controller URL**:

```
Server URL: https://yoursite.com/cashupayserver/router.php
```

This works on any PHP host without server configuration. The e-commerce plugin will access URLs like:
- `https://yoursite.com/cashupayserver/router.php/api/v1/stores/.../invoices`

#### Option 2: Apache with mod_rewrite

If your host supports `.htaccess` and mod_rewrite (most do), use clean URLs:

```
Server URL: https://yoursite.com/cashupayserver
```

The included `.htaccess` handles URL rewriting automatically.

#### Option 3: nginx

Add to your server block:

```nginx
location /cashupayserver/ {
    # API routing
    location ~ ^/cashupayserver/api/v1/ {
        try_files $uri /cashupayserver/api.php$is_args$args;
    }

    # Block sensitive directories
    location ~ ^/cashupayserver/(data|includes|cashu-wallet-php)/ {
        deny all;
        return 403;
    }
}
```

Then use:
```
Server URL: https://yoursite.com/cashupayserver
```

### Connecting to WooCommerce

After installation, configure WooCommerce to use BareBits:

1. Install the [BTCPay for WooCommerce](https://wordpress.org/plugins/btcpay-greenfield-for-woocommerce/) plugin
2. In WooCommerce → Settings → Payments → BTCPay, set:
   - **BTCPay Server URL**: Your BareBits URL
   - Click **Connect to BTCPay** to start the pairing flow
3. Save and test with a small purchase

### Recommended: Configure system cron

BareBits's background tasks — invoice polling, auto-withdrawal, and
fee settlement — run on a tight schedule when your hosting environment
invokes `cron.php` once a minute. Without it, the same tasks still fire
opportunistically when an admin or customer loads a page, but with
multi-minute latency.

Open **Settings** in the admin UI to copy the suggested entry, then add it
to your system crontab (or your host's cron panel). The admin dashboard
will show a one-time dismissable banner if external cron hasn't been
detected in over 24 hours.

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

## Development

### Quick Start with PHP Built-in Server

The simplest way to run BareBits locally:

```bash
git clone --recurse-submodules https://github.com/jooray/cashupayserver.git
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
git clone --recurse-submodules https://github.com/jooray/cashupayserver.git
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

Webhook events: `InvoiceCreated`, `InvoiceReceivedPayment`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid`

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

## Related Projects

- **[cashu-wallet-php](https://github.com/jooray/cashu-wallet-php)** - Standalone PHP library for Cashu protocol
- **[btcpay-greenfield-test](https://github.com/jooray/btcpay-greenfield-test)** - Minimalistic PHP page to test BTCPay Server's Greenfield API integrations
- **[BTCPay Server](https://btcpayserver.org/)** - The gold standard for self-hosted Bitcoin payments
- **[Cashu](https://cashu.space/)** - Ecash protocol for Bitcoin

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Support and Value4Value

If you like this project, I would appreciate if you contributed time, talent, or treasure.

**Time and talent** can be used in testing it out, fixing bugs, or submitting pull requests.

**Treasure** can be [donated here](https://cashupayserver.org/#donate). The
software also pays a small built-in upstream development fee on revenue routed
through it; see [LICENSE.md](LICENSE.md) for details.

## Forked from CashuPayServer

BareBits is a fork of [CashuPayServer](https://github.com/jooray/cashupayserver) by Juraj Bednár. The original project established the core idea: a PHP-only Bitcoin payment gateway built on Cashu mints, speaking the BTCPay Greenfield API. BareBits preserves that foundation and adds direct on-chain support, multi-user administration, security hardening, an in-app updater, a sustainable revenue model, and a comprehensive test suite — all while keeping the "upload and go" deployment story on any PHP host.

### Major changes since the fork

#### On-chain Bitcoin payments

Accept native Bitcoin transactions alongside Lightning. Funds derive from your own xpub — never the mint — so on-chain payments go straight to your wallet. Built on `bitwasp/bitcoin`, with pluggable blockchain providers (Esplora HTTP and `bitcoind` RPC), per-xpub derivation counters that survive address reuse, and a setup-wizard step plus an admin settings card. See [docs/onchain.md](docs/onchain.md).

#### Multi-user admin with role-based access

A real account system replaces the original single-password model. Admins can create users, assign roles, and manage their own account from a header user-menu. Non-admin surfaces are gated server-side, not just hidden in the UI.

#### Authentication & security hardening

CSRF protection on login/approve/deny POSTs, hardened session cookies, XSS fixes across pairing, payment, and admin surfaces (including escaped API-key labels in Settings), removal of the legacy client-side PIN in favor of server-side session checks, and a setup-flow guard that refuses to create a second admin when one already exists.

#### Auto-update from main/testing channels

A PHP-only updater pulls release zips from a chosen channel (`main` or `testing`) and overlays them on the install in place, preserving local data, user config, and the updater itself. Includes backups, a reachable `recover.php` that accepts POST tokens, and a cron integration so updates can run unattended.

#### Email notifications

Optional SMTP or `mail()`-based notifications for invoice-paid and auto-withdrawal events, with per-recipient resolution, dedupe, queue-drain, and a master kill-switch. Plugs into the existing cron loop.

#### Mint reliability tracking

A trusted-mints list and per-mint reliability tracking. Mints marked `MINT_UNREACHABLE` are temporarily gated and automatically re-admitted after a retry interval, so a single flaky mint doesn't permanently break the dashboard.

#### Free trial, dev fee, and license restructure

A deployment-time free trial waives platform fees for new installs. After the trial, a small built-in upstream development fee is collected on revenue routed through the instance. Licensing was restructured to a dual MIT / Modified MIT model documented in [LICENSE.md](LICENSE.md) and the [BareBits Use Policy](USE_POLICY.md).

#### Stats dashboard

An admin stats view with CSV export, including a fix for the `dev_fee` status bug that previously misclassified some entries.

#### Stale-cron warning + Settings cron URL

The admin dashboard shows a dismissable banner if external cron hasn't fired in 24+ hours, and the Settings page displays the exact cron entry to copy into your host's crontab.

#### Comprehensive test infrastructure

The fork added an end-to-end test framework covering the Greenfield API surface (38+ tests), an auto-melt LNURL-pay path, a Playwright-driven UI tier, a WordPress-hosted plugin tier, an on-chain tier against `bitcoind` regtest, and GitHub Actions CI that runs the suite on push and PR. Customer-wallet fixtures cover Electrum, cashu.me, and Fulcrum.

#### Brand rename to BareBits

User-facing surfaces (page titles, headings, footers, button labels, PWA manifest, WordPress admin labels, withdrawal-memo descriptions, API "setup not complete" error) were renamed to "BareBits". Code identifiers, file paths, the composer package name, the `isCashuPayServer` BTCPay-compat API flag, and the WordPress menu slug are kept as-is to preserve compatibility.

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

## Links

- **Website**: [cashupayserver.org](https://cashupayserver.org/)
- **Issues**: [GitHub Issues](https://github.com/jooray/cashupayserver/issues)
