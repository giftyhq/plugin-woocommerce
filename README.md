# Gifty for WooCommerce plugin
![Release](https://img.shields.io/github/v/release/giftyhq/plugin-woocommerce)
[![CI](https://github.com/giftyhq/plugin-woocommerce/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/giftyhq/plugin-woocommerce/actions/workflows/ci.yml)
![License](https://img.shields.io/github/license/giftyhq/plugin-woocommerce)

## Overview
Gifty for WooCommerce is a WordPress plugin that allows WooCommerce stores to accept Gifty gift cards as a payment method. Customers can easily apply their Gifty gift card codes at checkout.

## Features
- **Gift Card Acceptance**: Allows customers to pay for their orders using Gifty gift cards.
- **Easy Integration**: Simple setup process that integrates smoothly with WooCommerce's checkout system.
- **Developer Friendly**: Provides an API for developers to interact with the plugin programmatically.

## Installation
1. **Download the Plugin**: Download the plugin from the WordPress plugin repository or directly through your WordPress admin panel.
2. **Install and Activate**: Navigate to your WordPress admin panel, go to Plugins > Add New, upload the plugin files and activate the plugin.
3. **Configuration**: Configure the plugin by navigating to WooCommerce > Settings > Integrations > Gifty.

## Usage
Once installed and activated, customers will see an option to enter their Gifty gift card code during checkout. If the gift card covers the total cost of the order, no additional payment will be required. If the order total exceeds the gift card balance, customers can pay the remaining amount using the standard payment methods set up in your WooCommerce store.

## API Documentation

### Overview
The Gifty for WooCommerce API allows third-party plugins and themes to interact with the Gifty system. It provides methods for retrieving gift card data to be used in reporting and accounting processes.

### Checking API Availability
To ensure that the API is available in your environment, use the following code snippet:
```php
if (class_exists('Gifty\WooCommerce\Shared\V1\Api')) {
    // The API class is available for use
} else {
    // Handle the unavailability of the API class appropriately
    error_log('The required Gifty API class is not available.');
}
```

### API Methods

*   **Get Gift Cards Applied to an Order**: Retrieve all gift cards applied to a specific order.
*   **Get Total Applied Gift Card Amount**: Calculate the total amount paid with gift cards for an order.
*   **Get Total Refunded Gift Card Amount**: Calculate the total amount refunded with gift cards for an order.

### Example Usage
```php
$api = new Gifty\WooCommerce\Shared\V1\Api();
$order_id = 123; // Example order ID
$gift_cards = $api->get_gift_cards_applied_to_order($order_id);
$total_paid = $api->get_total_applied_gift_card_amount($order_id);
$total_refunded = $api->get_total_refunded_gift_card_amount($order_id);
```

## Support
For support, questions, or more information, please visit our [support page](https://gifty.nl/contact).

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
