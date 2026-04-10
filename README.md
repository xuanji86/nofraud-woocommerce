# NoFraud for WooCommerce

Credit card fraud detection for WooCommerce 

## Description

This plugin integrates the NoFraud fraud screening API into your WooCommerce store using the **Pre-Acceptance workflow** (recommended by NoFraud). After a customer completes payment, the order is automatically sent to NoFraud for real-time fraud analysis. Based on the decision, the plugin can approve, hold, or cancel the order — and optionally display a friendly error on the checkout page so the customer can retry.

### Features

- **Real-time fraud screening** — Every credit card order is sent to NoFraud after payment, with billing/shipping addresses, line items, customer history, card last4, AVS/CVV codes, and more.
- **Automatic order handling** — Orders are approved, put on hold, or cancelled based on NoFraud's decision (`pass`, `fail`, `review`, `fraudulent`).
- **Checkout error display** — When NoFraud returns `fail`, the customer stays on the checkout page with a security-focused error message and can retry with different payment details. Supports both Classic Checkout and Block Checkout.
- **Automatic refund** — Failed orders are automatically refunded via the payment gateway (with fallback to manual refund if the gateway doesn't support it).
- **Webhook support** — Receives status updates from NoFraud when orders under manual review get a final decision, and updates the order accordingly.
- **Device fingerprinting** — Loads the NoFraud Device JavaScript on cart and checkout pages for improved fraud detection accuracy.
- **Admin UI** — Color-coded decision badges on the orders list, a detailed meta box on order edit pages, and a direct link to the NoFraud Portal for each transaction.
- **API connection test** — One-click button in settings to verify your API key (works with unsaved values).
- **Payroc gateway support** — Compatibility layer that intercepts Payroc's XML API responses to capture AVS, CVV, and card data that the Payroc plugin discards.
- **HPOS compatible** — Fully supports WooCommerce High-Performance Order Storage.
- **Debug logging** — Optional logging to WooCommerce > Status > Logs for troubleshooting.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- A NoFraud account with an API key ([sign up](https://www.nofraud.com))

## Installation

1. Upload the `nofraud-woocommerce` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins > Installed Plugins**.
3. Go to **WooCommerce > Settings > NoFraud**.
4. Enter your API key and Device JS account code (both found on your [NoFraud Portal Integrations page](https://portal.nofraud.com/integration)).
5. Select **Test** mode for development or **Live** for production.
6. Click **Test Connection** to verify your API key.
7. Check **Enable NoFraud fraud screening** and save.

## Configuration

### Settings (WooCommerce > Settings > NoFraud)

| Setting | Description |
|---------|-------------|
| **Enable/Disable** | Master switch for fraud screening. |
| **Mode** | `Test` (sandbox) or `Live` (production). Test mode uses `apitest.nofraud.com`. |
| **API Key** | Your NoFraud API key. |
| **Device JS Account Code** | Account code for the NoFraud device fingerprinting script. |
| **Webhook Secret** | Optional shared secret for webhook request verification. |
| **On Fail Decision** | Cancel the order (default) or put on hold. |
| **On Review Decision** | Put on hold (default) or do nothing. |
| **Debug Logging** | Log API requests/responses to WooCommerce logs. |

### Webhook Setup

To receive automatic updates when reviewed orders get a final decision:

1. Copy the **Webhook URL** shown on the settings page.
2. Contact NoFraud support (support@nofraud.com) and provide:
   - Your webhook URL
   - HTTP method: `POST`
   - (Optional) The shared secret you configured, sent as the `X-NoFraud-Secret` header

## How It Works

### Order Screening Flow

```
Customer submits checkout
        |
Payment gateway processes charge
        |
woocommerce_payment_complete fires
        |
Plugin sends order data to NoFraud API
        |
    +---+---+---+
    |       |       |
  pass    fail   review
    |       |       |
 Order    Cancel  On-hold
proceeds  order   (awaits
          + auto   webhook
          refund   update)
          + error
          on checkout
```

### Decision Handling

| NoFraud Decision | Default Action | Checkout Behavior |
|------------------|----------------|-------------------|
| `pass` | Order proceeds normally | Redirect to thank-you page |
| `fail` | Order cancelled + auto refund | Error shown, customer can retry |
| `fraudulent` | Order cancelled + auto refund | Error shown, customer can retry |
| `review` | Order put on hold | Redirect to thank-you page (order held) |

### Supported Payment Gateways

The plugin extracts card last4, type, AVS, and CVV codes from these gateways:

| Gateway | Status |
|---------|--------|
| **Payroc** | Full support via compatibility layer (intercepts XML responses) |
| **Stripe** | Supported (reads standard Stripe order meta) |
| **Braintree** | Supported |
| **Authorize.Net** | Supported |
| **Square** | Supported |
| **PayPal Braintree** | Supported |
| **Others** | Partial support via generic meta keys (`_card_last4`, `_avs_result_code`, `_cvv_result_code`) |

## File Structure

```
nofraud-woocommerce/
├── nofraud-woocommerce.php              # Plugin bootstrap, WC dependency check, HPOS compat
├── README.md
└── includes/
    ├── class-nofraud-api.php            # NoFraud API client (create transaction, status, test)
    ├── class-nofraud-settings.php       # WooCommerce settings tab, shared constants, AJAX test
    ├── class-nofraud-order-handler.php  # Order screening on payment_complete
    ├── class-nofraud-checkout.php       # Checkout error display + auto refund
    ├── class-nofraud-webhook.php        # REST endpoint for status update webhooks
    ├── class-nofraud-device-js.php      # Device fingerprinting JS on cart/checkout
    ├── class-nofraud-admin-order.php    # Admin meta box + orders list column
    └── gateways/
        └── class-nofraud-payroc.php     # Payroc gateway compatibility layer
```

## Frequently Asked Questions

### Does this plugin work with the WooCommerce Block Checkout?

Yes. The checkout error display supports both Classic Checkout (shortcode) and Block Checkout (default in WooCommerce 8+).

### What happens if the NoFraud API is down or times out?

The order proceeds normally. The plugin logs the error and adds an order note, but never blocks a legitimate sale due to an API issue.

### Can customers retry after a fraud check failure?

Yes. When an order fails fraud screening, the customer stays on the checkout page with an error message and can update their billing information or use a different payment method.

### Does the plugin automatically refund failed orders?

Yes. When NoFraud returns `fail` or `fraudulent`, the plugin attempts an automatic full refund via the payment gateway. If the gateway doesn't support programmatic refunds, the order note will indicate that a manual refund is needed.

### How does the Payroc compatibility work?

The Payroc WooCommerce plugin extracts AVS, CVV, and approval codes from gateway responses but never stores them to the database. This plugin hooks into WordPress's HTTP API to intercept the Payroc XML responses, parse the missing data, and persist it as order meta before the NoFraud screening runs.

## Changelog

### 1.0.0

- Initial release.
- Pre-Acceptance workflow integration with NoFraud Transaction API.
- Support for Classic and Block Checkout error display with retry.
- Automatic refund on fail/fraudulent decisions.
- Webhook endpoint for review status updates.
- Device JavaScript fingerprinting.
- Admin order UI with decision badges and NoFraud Portal links.
- API connection test button.
- Payroc gateway compatibility layer.
- HPOS support.

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
