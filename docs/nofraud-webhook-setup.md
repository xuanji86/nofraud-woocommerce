# NoFraud Webhook Configuration Request

Hi NoFraud Support,

We have built a custom WooCommerce integration and would like to set up transaction status webhooks for our store. Below are the details for our webhook endpoint.

## Endpoint

```
https://{our-domain}/wp-json/nofraud/v1/webhook
```

> Replace `{our-domain}` with the actual store domain.

## HTTP Method

**POST**

## Headers

| Header | Value | Required |
|--------|-------|----------|
| `Content-Type` | `application/json` | Yes |
| `X-NoFraud-Secret` | `{shared_secret}` | Yes |

> The `X-NoFraud-Secret` header is used for request verification. We will provide the shared secret value separately via a secure channel.

## Request Body (JSON)

```json
{
  "id": "%transaction_url%",
  "decision": "%status%",
  "invoiceNumber": "%invoice_number%"
}
```

### Field Descriptions

| Field | NoFraud Variable | Description |
|-------|------------------|-------------|
| `id` | `%transaction_url%` | The NoFraud transaction ID (UUID) returned when the transaction was created. |
| `decision` | `%status%` | The updated decision. Expected values: `pass`, `fail`, `fraudulent`. |
| `invoiceNumber` | `%invoice_number%` | The WooCommerce order number included in the original transaction. |

## Expected Behavior

When our endpoint receives the webhook:

- **`pass`** — The order is released from "On Hold" to "Processing" (ready to ship).
- **`fail` / `fraudulent`** — The order is cancelled.

## Response

Our endpoint returns:

| HTTP Code | Meaning |
|-----------|---------|
| `200` | Webhook processed successfully. |
| `400` | Missing or invalid fields in request body. |
| `403` | Authentication failed (invalid or missing `X-NoFraud-Secret` header). |
| `404` | Order not found for the given transaction ID or invoice number. |

## Additional Notes

- Our integration uses the NoFraud Custom/Direct API.
- Plugin: NoFraud for WooCommerce v1.0.0.
- Platform: WordPress 6.9.4 / WooCommerce 9.x / PHP 8.4.

Please let us know if you need any additional information.

Thanks!
