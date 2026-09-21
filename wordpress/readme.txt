=== BareBits - Lightning Payments via Bitcoin ===
Contributors: barebits
Tags: bitcoin, lightning, payments, woocommerce, btcpay
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin (on-chain and Lightning) in WooCommerce through a BareBits server. No approval process, no middlemen. Low 1% fee.

== Description ==

Accept Bitcoin (on-chain and Lightning) in WooCommerce through a BareBits server. No approval process, no middlemen. Low 1% fee.

BareBits lets your WooCommerce shop accept Bitcoin — on-chain and over the Lightning Network — with funds going straight to wallets you control. No payment processor account, no approval process, no chargebacks.

This plugin is the WordPress-side glue: during onboarding you connect your self-hosted BareBits server by entering its URL. (The plugin's full build, distributed on the project's GitHub releases page, can additionally install a BareBits server alongside WordPress for you.)

The plugin then installs and configures the "BTCPay for WooCommerce" gateway plugin, points it at your BareBits server, registers the payment webhook, and can apply an automatic checkout discount for Bitcoin payments — on both the classic and the block-based checkout, with the percentage advertised in the payment method's title.

The BareBits server itself is a separate, self-hosted application (https://github.com/BareBits/cashupayserver) with its own license. This plugin contains no BareBits server code and talks to it purely over its HTTP API.

== Installation ==

1. Install and activate WooCommerce, if it isn't active already.
2. Install and activate this plugin, then open **BareBits** in the wp-admin menu — onboarding starts there.
3. Enter the URL of your self-hosted BareBits server and approve the pairing request on the server's dashboard.
4. Finish onboarding: the plugin installs and configures the "BTCPay for WooCommerce" gateway plugin, registers the payment webhook, and enables Bitcoin at checkout. Optionally, set a percentage discount for customers paying with Bitcoin.

Don't have a BareBits server yet? Any host that can run WordPress can run one — see https://github.com/BareBits/cashupayserver. (The plugin's full build, distributed on the project's GitHub releases page, can also install a server alongside WordPress for you.)

== Frequently Asked Questions ==

= Do I need my own server? =

Yes — a BareBits server you host yourself, which is the point: your money never touches anyone else's infrastructure. Any host that can run WordPress can run BareBits, even shared hosting; set it up once and connect it here by URL. (The full plugin build from the project's GitHub releases can also install it next to your existing site for you.)

= Where does the money go? =

To wallets you control: your own Lightning address, your own on-chain wallet (xpub), or a Cashu mint of your choosing. The BareBits admin walks you through it.

= What happens if I uninstall this plugin? =

Only the WordPress-side wiring is removed. A BareBits server installed alongside WordPress keeps running, and its data directory (which holds wallet keys) is never deleted by this plugin. The record of where that server lives — including its saved admin password, which is your only way into its dashboard — also survives, so reinstalling the plugin later offers to reconnect it.

== Screenshots ==

1. Point of sale on the BareBits server dashboard — build an invoice from the product catalog.
2. Or create a simple invoice from just an amount.
3. The customer payment page: Lightning and on-chain, payable with any Bitcoin or Cashu wallet.
4. Payment complete.
5. Every invoice in one list — filter by store and status, export as CSV.

== External services ==

This plugin talks to the following services. None of them receive data on behalf of BareBits: the server you connect is your own, and BareBits (Zaphaus LLC) operates no hosted service and receives nothing from your site.

= Your own BareBits server =

Everything this plugin does at runtime is communication with the BareBits server that you host yourself and connect during onboarding, at the URL you enter (often the same host as WordPress): the embedded setup wizard, dashboard single-sign-on, a WP-cron pinger that triggers the server's background tasks, and the checkout API bridge the payment pages ride. Requests carry the keys the two sides exchanged when you paired them (API key, cron key, sign-on key) and the WooCommerce order data needed to take payment (amounts, currency, order IDs, invoice status). This traffic goes only to your own server — no third party is involved and nothing reaches BareBits.

The BareBits server software is governed by its Terms of Use (https://github.com/BareBits/cashupayserver/blob/main/USE_POLICY.md) and Privacy Policy (https://github.com/BareBits/cashupayserver/blob/main/PRIVACY.md), which you accept in its setup wizard. The Privacy Policy also documents, in plain language, every external service the server itself contacts to process payments (exchange-rate providers, Bitcoin network services, and the payment counterparties you configure) and what each one receives.

= wordpress.org plugin directory =

During onboarding the plugin installs and activates the "BTCPay for WooCommerce" gateway plugin from the wordpress.org plugin directory using WordPress's own plugin installer, which makes your site contact api.wordpress.org and downloads.wordpress.org (as any dashboard plugin install does). WordPress.org privacy policy: https://wordpress.org/about/privacy/

= GitHub (full plugin build only) =

The full plugin build distributed on the project's GitHub releases page can additionally install a BareBits server alongside WordPress. That flow fetches release metadata from api.github.com and downloads the release archive and its checksums from github.com. The build distributed on wordpress.org does not include this component and never contacts GitHub. GitHub terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service — privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Changelog ==

= 1.5.3 =
* The plugin page's Description now opens with the essentials the tagline already stated: no approval process, no middlemen, low 1% fee.

= 1.5.2 =
* First release distributed through the wordpress.org plugin directory: stable releases now publish there automatically, and the directory page gained its art (banner, icon, screenshots) plus this readme's Installation and Screenshots sections.
* The BareBits ₿ mark replaces the legacy icon everywhere it survived: the server's browser favicon and the dashboard's home-screen (PWA) icon.

= 1.5.1 =
* The readme now documents every external service the plugin communicates with (your own BareBits server, the wordpress.org plugin directory, and — in the full build — GitHub), and the BareBits server project gained a plain-language Privacy Policy (PRIVACY.md) covering every external service the server itself contacts, linked from its setup wizard's terms step.
* Server-side: QR codes are now rendered by libraries bundled with the software instead of loaded from public CDNs, so no third party sits in the path of payment pages — neither observing visitors nor able to tamper with what a QR encodes.

= 1.5 =
* All admin styling and scripts now load as enqueued asset files, every admin action carries explicit capability and nonce checks, request input is sanitized on read and output escaped on print, and plugin-owned names use the barebits prefix throughout — per wordpress.org plugin review feedback.
* Server-side (for installs updating the companion BareBits server): on-chain receive through a Strike account, a confirmation-policy step for every on-chain source, and a fix for background payment polling that could miss payments made after the customer closed the payment page.

= 1.4.2 =
* The plugin's text domain now matches the wordpress.org plugin slug (barebits-lightning-payments-via-bitcoin), so community language packs from translate.wordpress.org will load.

= 1.4.1 =
* The plugin now passes WordPress.org's Plugin Check with zero errors and zero warnings: escaped output everywhere, WordPress filesystem/URL APIs, sanitized request input, and readme metadata kept in sync with the plugin version by the build.
* A wordpress.org distribution variant is now built alongside the full plugin. It omits the install-alongside flow (the plugin directory's guidelines forbid plugins downloading executable code) and connects to a BareBits server by URL only; the full plugin from GitHub releases is unchanged.

= 1.4 =
* The Bitcoin checkout discount is now applied by this plugin itself instead of the third-party ELEX plugin, and works on the block-based checkout as well as the classic one. The percentage (decimals allowed) is editable on the BareBits Connection page and in the gateway's WooCommerce settings, and the payment method's title always advertises the current value.
* Pairing with an existing BareBits server no longer shows "page not found" on servers whose host ignores rewrite rules: the plugin now always opens the authorization page by its real file name (/api-keys/authorize.php).
* The install-alongside server checks now show directly on the onboarding chooser, below the two options, instead of on a separate page afterwards. When a check fails, the install option is disabled with the reason in view.
* Onboarding and Connection-page actions, the pairing approval's return to this site, and the password reveal now wait out WordPress's brief maintenance windows (auto-updates) instead of landing on the "briefly unavailable" screen with the action lost.

= 1.3.1 =
* The plugin no longer bundles the BareBits server. Onboarding now connects an existing server by URL or installs the latest stable release alongside WordPress.
