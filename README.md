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
- **Firearm / FFL awareness (ffl-core)** — FFL-only orders are skipped by default (or screened with the dealer as ship-to when enabled); mixed carts are screened against the address the covered goods actually ship to. See [FFL / Firearm Order Handling](#ffl--firearm-order-handling).
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

FFL awareness works with **ffl-core** (the OSA/CGA FFL checkout plugin) and with **[g-FFL Checkout](https://wordpress.org/plugins/g-ffl-checkout/)**; both write the same `_shipping_ffl*` meta. The rules follow NoFraud's guidance (2026-09-28): coverage follows the address that is screened, and FFL orders must not be allowlisted on NoFraud's side.

| Order | NoFraud behavior | `shipTo` sent |
|-------|------------------|---------------|
| Every item ships to an FFL (`_order_shipment_type = ffl_only`) | Skipped with an order note (not covered) unless **FFL Orders** = *Screen all orders*, then screened (covered). | Dealer premise from ffl-core (`_shipping_ffl_premise_*`); NoFraud matches it against the ATF licensee list. |
| Mixed cart, shopper sent the non-firearm items home (`ffl_core_ship_home`) | Screened; all items (firearm included) in `lineItems`. Only the home-bound items are covered. | Customer's home address. |
| g-FFL mixed cart (`_is_mixed_cart_order`, mixed-cart support on) | Screened. | Customer's shipping address (billing if g-FFL overwrote it with the dealer's). |
| Mixed cart, everything to the FFL (ffl-core default; g-FFL with mixed-cart support off) | Screened. | Dealer premise. |
| In-store pickup (the store's own FFL, `ffl_core_in_store_pickup_license`) | Screened when not skipped; `isBopis: "true"`. | Store base address. |
| C&R transfer (ships to the collector) | As above. | Order shipping address (the collector's). |
| No FFL items | Screened. | Order shipping address. |

**FFL Orders** setting (skipped orders get an order note and are not covered):

| Option | Skips |
|---|---|
| Skip when every item requires FFL | FFL-only orders (C&R and in-store pickup included). |
| Skip whenever the order goes to an FFL address *(default)* | Anything whose ship-to resolves to a dealer premise or in-store pickup — FFL-only orders plus mixed carts shipped entirely to the dealer. C&R (ships to the collector) and split mixed carts are still screened. |
| Screen all orders | Nothing. |

FFL-only detection: with g-FFL active, its per-item `item_requires_ffl_shipment()` helper (g-FFL stamps `_order_shipment_type = ffl_only` on mixed carts when its mixed-cart support is off, so that field is not trusted there); otherwise ffl-core's `_order_shipment_type`; orders neither classified (admin/REST-created) fall back to the `_firearm_product` flag (variations inherit the parent's). The FFL license and shipment type also go to NoFraud reviewers in `userFields`.

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

Firearm-only orders ship to a licensed dealer and the buyer passes a 4473/NICS check in person, so by default anything going to an FFL address is not sent (as on Coreware) — including mixed carts shipped entirely to the dealer. Those orders are **not covered** by chargeback protection. Set **FFL Orders** to *Screen all orders* to send them with the dealer as `shipTo` and get coverage, or to *Skip when every item requires FFL* to screen mixed carts shipped to the dealer. See [FFL / Firearm Order Handling](#ffl--firearm-order-handling).

## Changelog

### 1.3.0

- **ffl-core compatibility** (g-FFL Checkout still supported): FFL-only detection from `_order_shipment_type`; `shipTo` = home address on split mixed carts, dealer premise otherwise, store address + `isBopis` for in-store pickup. New **FFL Orders** setting: skip anything going to an FFL address (default) / skip FFL-only / screen all.
- **Fix: Block checkout never intercepted a fail.** The hook ran before payment; it now runs after the Store API payment hook and returns the error to the shopper.
- **Fix: Payroc AVS/CVV/card data was never captured** — the host allowlist did not match Payroc 2.7.9.x (`payments.payroc.com` / `payments.uat.payroc.com`).
- **Webhook hardening:** the payload is only a trigger; the decision is re-read from `GET /status/{nf-token}/{id}`. `id` may be the portal URL. A `pass` releases only holds NoFraud placed (not ffl-core restricted-state or staff holds); a late `fail` on a completed order only adds a note; async cancels now refund.
- **API conformance:** create-transaction posts to `/transaction`; `payment.creditCard` and `billTo` are always objects; AVS/CVV codes outside the API's length limits are dropped; gateway `transaction-id` / `authcode` are sent; addresses clipped to 128 chars.
- **Retries:** a transport failure or `error` decision is retried up to 3 times (5 min apart, Action Scheduler) instead of leaving the order silently unscreened.

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
