=== ByteNFT Onramp Payment Gateway ===
Contributors: ByteNFT Onramp
Tags: woocommerce, payment gateway, fiat, ByteNFT Onramp
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The ByteNFT Onramp Payment Gateway plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates ByteNFT Onramp Payment Gateway with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 1.0.5 =
* Updated payment domain to `pay.bytenft.xyz` for improved security and reliability.

= 1.0.4 =
* Fixed an issue where incorrect amounts were passed during checkout.
* Properly unslashed `_wpnonce` before verification for better compatibility.
* Fixed a bug where order status was not updated correctly after cancelled payments.
* Reduced unnecessary gateway visibility logs by limiting them to the checkout page.

= 1.0.3 =
* Fixed an issue where changes to account settings were not properly synced after refreshing the admin settings page.
* Improved handling of failed transactions during checkout with proper redirection and error messaging.
* Refactored internal logic for account validation and improved sandbox/live switching.
* Enhanced error logging for better debugging.

= 1.0.2 =
* Fixed incorrect option key lookup for sandbox/live mode.
* Improved settings consistency between frontend and admin.
* Code cleanup in REST API key validation.

= 1.0.1 =
* Fixed: Issues with multiple account detection and fallback.
* Improved: Handling of sandbox/live account modes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.5 =
We’ve moved to a new payment domain: `pay.bytenft.xyz`. Please update saved links, API integrations, or whitelists if required.

= 1.0.4 =
This update fixes incorrect amount handling during checkout, improves nonce verification for broader compatibility, and resolves an issue where order statuses were not correctly updated after cancelled payments.

= 1.0.3 =
This update fixes account settings sync issues, improves failed transaction handling during checkout, and enhances overall reliability. It's recommended for all users.

= 1.0.2 =
Fixes an issue with sandbox/live settings not loading properly during API verification. Recommended for all users.

= 1.0.1 =
This update improves support for multiple merchant accounts and better handles fallback logic. Upgrade is recommended.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: [https://pay.bytenft.xyz/reach-out](https://pay.bytenft.xyz/reach-out)
