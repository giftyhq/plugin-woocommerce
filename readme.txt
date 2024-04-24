=== Gifty for WooCommerce ===
Contributors:
Tags: gifty, cadeaubon, cadeaukaart, woocommerce, gift card, gift cards, voucher
Text Domain: gifty-woocommerce
Requires at least: 6.2
Tested up to: 6.5
Stable tag: 2.0.6
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress plugin for accepting Gifty gift cards in your WooCommerce shop.

== Description ==

The [Gifty for WooCommerce](https://gifty.nl) plugin enables you to accept Gifty gift cards in your WooCommerce shop.

Your customers can enter their Gifty gift card code during checkout and in their cart. The gift card will then be applied as payment on the order.

== Frequently Asked Questions ==

= How can customers enter their gift card? =

Entering gift card codes can be done through the added gift card form on the cart page and during checkout. The gift card form on the cart page can be enabled or disabled in the plugin settings.

= Can I sell gift cards with this plugin? =

No, this plugin has the purpose of accepting Gifty gift cards in your WooCommerce shop. You can sell your gift card by installing the [Gifty Order Module plugin](https://nl.wordpress.org/plugins/gifty/).

= I have a different question. How can I get in touch? =

We're more than happy to help you out! You can contact us through our [contact page](https://gifty.nl/contact).

== Changelog ==

= 2.0.6 =
* Fix incorrect use of order item meta to order meta data

= 2.0.5 =
* Removed the external dependency of jQuery BlockUI module

= 2.0.4 =
* Migrations are now processed in the background using Action Scheduler, improving performance for stores with a high volume of orders.
* Added an option in plugin settings to allow gift cards to be applied through the coupon field.
* Front-end fields are now conditionally displayed only when a valid API key is active.

= 2.0.3 =
* Bugfix: path names did not match the namespace capitalization, causing issues on Linux servers

= 2.0.2 =
* Updated third party dependencies

= 2.0.1 =
* Updated documentation and versioning

= 2.0.0 =
* Apply gift cards as payment instead of as coupon discount
* Added a dedicated gift card form on the cart and checkout page
* Added support for WooCommerce Refunds as long as the order is unprocessed
* Added support for WooCommerce Analytics
* Added support for WooCommerce HPOS
* Compatibility support with WooCommerce Gift Cards
* Changed the minimum required PHP version from 7.2 to 8.0
* Updated the main Gifty library

= 1.1.0 =
* Apply code formatting on applied gift cards
* Fixed an issue on updating multiple orders at once
* A gift card without value will now return a not found notification

= 1.0.6 =
* Updated the main Gifty library

= 1.0.5 =
* Updated the main Gifty library

= 1.0.4 =
* Updated the main Gifty library

= 1.0.3 =
* Improved the automated build process for new releases.

= 1.0.2 =
* Added missing translations for error messages.

= 1.0.1 =
* Lowered the required PHP version from 7.3 to 7.2.

= 1.0.0 =
* First release of the Gifty for WooCommerce plugin.
