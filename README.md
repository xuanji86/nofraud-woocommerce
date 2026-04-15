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
- **Firearm / FFL awareness** — Orders whose line items all require FFL shipment (via [g-FFL Checkout](https://wordpress.org/plugins/g-ffl-checkout/)) are skipped automatically, since the delivery address is a licensed dealer rather than the customer. Mixed carts (FFL + non-FFL) are still screened, using the customer's own shipping address.
- **Works with gateways that skip `payment_complete()`** — Screening is hooked onto order status transitions (`processing`, `completed`) as well as `woocommerce_payment_complete`, so gateways like Payroc that move orders straight to `processing` are still covered.
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
Order transitions to processing/completed
(via woocommerce_payment_complete OR
 woocommerce_order_status_processing/_completed)
        |
All items require FFL shipment? --yes--> Skip, note on order
        |
        no
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

The screening trigger listens to both `woocommerce_payment_complete` and the
`woocommerce_order_status_processing` / `woocommerce_order_status_completed`
transitions. Gateways that call `$order->payment_complete()` (most standards-
compliant processors) hit the first hook; gateways that bypass it and move the
order directly into `processing` (e.g. Payroc) are caught by the status hooks.
A per-order idempotency guard on `_nofraud_transaction_id` ensures each order
is screened exactly once.

### Decision Handling

| NoFraud Decision | Default Action | Checkout Behavior |
|------------------|----------------|-------------------|
| `pass` | Order proceeds normally | Redirect to thank-you page |
| `fail` | Order cancelled + auto refund | Error shown, customer can retry |
| `fraudulent` | Order cancelled + auto refund | Error shown, customer can retry |
| `review` | Order put on hold | Redirect to thank-you page (order held) |

### FFL / Firearm Order Handling

If the [g-FFL Checkout](https://wordpress.org/plugins/g-ffl-checkout/) plugin is active, the screening logic becomes FFL-aware:

| Cart contents | NoFraud behavior | `shipTo` sent |
|---------------|------------------|---------------|
| All items require FFL shipment (firearms / ammunition under state compliance) | **Skipped.** An order note is added and the skip is logged. | — |
| Mix of FFL and non-FFL items | Screened normally. | Customer's own shipping address (g-FFL preserves it on mixed-cart orders). |
| No FFL items | Screened normally. | Customer's shipping address. |

The "all-FFL" check uses g-FFL Checkout's own `item_requires_ffl_shipment()` helper when available, and falls back to the `_firearm_product` product meta when it isn't.

As a defensive measure, if the order's shipping address appears to be a dealer premise (indicating g-FFL's mixed-cart support is disabled or the address was overwritten), the plugin sends the customer's **billing** address as `shipTo` instead, so NoFraud's geo/velocity heuristics aren't skewed by a dealer address. A warning is logged when this fallback triggers.

The skip logic is filterable — customize it by hooking `nofraud_wc_should_skip_order`:

```php
add_filter( 'nofraud_wc_should_skip_order', function ( $skip, $order ) {
    // Return true to skip NoFraud screening for this order.
    return $skip;
}, 10, 2 );
```

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
    ├── class-nofraud-order-handler.php  # Order screening on payment_complete + status transitions; FFL skip
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

### Why are firearm orders being skipped?

When the [g-FFL Checkout](https://wordpress.org/plugins/g-ffl-checkout/) plugin is active, orders whose line items *all* require FFL shipment are skipped automatically: the physical destination is a licensed dealer, not the customer, so running the billing/shipping address through NoFraud's geo heuristics produces noise rather than signal. Mixed carts (FFL + non-FFL items) are still screened against the customer's own shipping address. See the [FFL / Firearm Order Handling](#ffl--firearm-order-handling) section above for the full behavior matrix and the `nofraud_wc_should_skip_order` filter.

## Changelog

### 1.2.1

- **Fix: Payroc and other gateways that skip `$order->payment_complete()` were never screened.** Screening now also hooks onto `woocommerce_order_status_processing` and `woocommerce_order_status_completed`, covering gateways that transition orders directly to `processing`. A per-order idempotency guard ensures each order is still screened exactly once.
- **Firearm / FFL order awareness.** Orders whose line items all require FFL shipment (detected via g-FFL Checkout's `item_requires_ffl_shipment()` helper, with a fallback on the `_firearm_product` product meta) are skipped automatically with an order note and log entry. Mixed carts are still screened.
- **Defensive shipping-address fallback.** If a mixed-cart order's shipping address is found to be the FFL dealer's premise (e.g. g-FFL mixed-cart support disabled), the plugin sends the customer's billing address as `shipTo` and logs a warning, so NoFraud's geo scoring isn't skewed.
- New `nofraud_wc_should_skip_order` filter for customizing the skip logic.

### 1.2.0

- Test mode with sandbox credentials, test-transaction button in settings, various checkout polish.

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
