=== BareBits - Lightning Payments via Bitcoin ===
Contributors: barebits
Tags: bitcoin, lightning, payments, woocommerce, btcpay
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin (on-chain and Lightning) in WooCommerce through a BareBits server. No approval process, no middlemen.

== Description ==

BareBits lets your WooCommerce shop accept Bitcoin — on-chain and over the Lightning Network — with funds going straight to wallets you control. No payment processor account, no approval process, no chargebacks.

This plugin is the WordPress-side glue. During onboarding you either:

* **Connect an existing BareBits server** by entering its URL, or
* **Install BareBits alongside WordPress** — the plugin downloads the latest stable BareBits release from GitHub and installs it next to your WordPress site (in its own folder, updated independently of this plugin).

Either way, the plugin then installs and configures the "BTCPay for WooCommerce" gateway plugin, points it at your BareBits server, registers the payment webhook, and can apply an automatic checkout discount for Bitcoin payments — on both the classic and the block-based checkout, with the percentage advertised in the payment method's title.

The BareBits server itself is a separate, self-hosted application (https://github.com/BareBits/cashupayserver) with its own license. This plugin contains no BareBits server code and talks to it purely over its HTTP API.

== Frequently Asked Questions ==

= Do I need my own server? =

No — any host that can run WordPress can run BareBits. The "install alongside WordPress" option sets it up next to your existing site, even on shared hosting.

= Where does the money go? =

To wallets you control: your own Lightning address, your own on-chain wallet (xpub), or a Cashu mint of your choosing. The BareBits admin walks you through it.

= What happens if I uninstall this plugin? =

Only the WordPress-side wiring is removed. A BareBits server installed alongside WordPress keeps running, and its data directory (which holds wallet keys) is never deleted by this plugin. The record of where that server lives — including its saved admin password, which is your only way into its dashboard — also survives, so reinstalling the plugin later offers to reconnect it.

== Changelog ==

= Unreleased =
* The Bitcoin checkout discount is now applied by this plugin itself instead of the third-party ELEX plugin, and works on the block-based checkout as well as the classic one. The percentage (decimals allowed) is editable on the BareBits Connection page and in the gateway's WooCommerce settings, and the payment method's title always advertises the current value.
* Pairing with an existing BareBits server no longer shows "page not found" on servers whose host ignores rewrite rules: the plugin now always opens the authorization page by its real file name (/api-keys/authorize.php).
* The install-alongside server checks now show directly on the onboarding chooser, below the two options, instead of on a separate page afterwards. When a check fails, the install option is disabled with the reason in view.
* Onboarding and Connection-page actions, the pairing approval's return to this site, and the password reveal now wait out WordPress's brief maintenance windows (auto-updates) instead of landing on the "briefly unavailable" screen with the action lost.

= 1.3.1 =
* The plugin no longer bundles the BareBits server. Onboarding now connects an existing server by URL or installs the latest stable release alongside WordPress.
