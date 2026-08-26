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

Either way, the plugin then installs and configures the "BTCPay for WooCommerce" gateway plugin, points it at your BareBits server, registers the payment webhook, and can set up an automatic checkout discount for Bitcoin payments (via the free "ELEX Discount Per Payment Method" plugin).

The BareBits server itself is a separate, self-hosted application (https://github.com/BareBits/cashupayserver) with its own license. This plugin contains no BareBits server code and talks to it purely over its HTTP API.

== Frequently Asked Questions ==

= Do I need my own server? =

No — any host that can run WordPress can run BareBits. The "install alongside WordPress" option sets it up next to your existing site, even on shared hosting.

= Where does the money go? =

To wallets you control: your own Lightning address, your own on-chain wallet (xpub), or a Cashu mint of your choosing. The BareBits admin walks you through it.

= What happens if I uninstall this plugin? =

Only the WordPress-side wiring is removed. A BareBits server installed alongside WordPress keeps running, and its data directory (which holds wallet keys) is never deleted by this plugin.

== Changelog ==

= 1.3.1 =
* The plugin no longer bundles the BareBits server. Onboarding now connects an existing server by URL or installs the latest stable release alongside WordPress.
