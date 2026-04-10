<?php
/**
 * NoFraud order handler.
 *
 * Hooks into the WooCommerce payment flow to screen orders via NoFraud
 * using the Pre-Acceptance workflow (recommended):
 *   1. Customer places order, payment gateway processes the charge.
 *   2. After payment completes, transaction is sent to NoFraud with last4 + AVS/CVV.
 *   3. Order status is updated based on NoFraud decision.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Order_Handler {

	public static function init(): void {
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'screen_order' ], 10, 1 );
	}

	/**
	 * Screen an order after payment is complete.
	 */
	public static function screen_order( int $order_id ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( NoFraud_Settings::META_TRANSACTION_ID ) ) {
			return;
		}

		$transaction_data = self::build_transaction_data( $order );

		NoFraud_Settings::log( 'Screening order #' . $order_id . '. Payload: ' . wp_json_encode( $transaction_data ) );

		$result = NoFraud_API::create_transaction( $transaction_data );

		NoFraud_Settings::log( 'NoFraud response for order #' . $order_id . ': ' . wp_json_encode( $result ) );

		if ( empty( $result['success'] ) ) {
			$error = $result['error'] ?? 'Unknown error';
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'NoFraud screening failed: %s', 'nofraud-woocommerce' ),
					$error
				)
			);
			NoFraud_Settings::log( 'NoFraud screening error for order #' . $order_id . ': ' . $error, 'error' );
			return;
		}

		$transaction_id = $result['id'] ?? '';
		$decision       = $result['decision'] ?? 'error';
		$message        = $result['message'] ?? '';

		$order->update_meta_data( NoFraud_Settings::META_TRANSACTION_ID, sanitize_text_field( $transaction_id ) );
		$order->update_meta_data( NoFraud_Settings::META_DECISION, sanitize_text_field( $decision ) );
		$order->update_meta_data( NoFraud_Settings::META_SCREENED_AT, gmdate( 'Y-m-d H:i:s' ) );
		if ( $message ) {
			$order->update_meta_data( NoFraud_Settings::META_MESSAGE, sanitize_text_field( $message ) );
		}
		$order->save();

		self::apply_decision( $order, $decision, $message );
	}

	private static function apply_decision( \WC_Order $order, string $decision, string $message = '' ): void {
		switch ( $decision ) {
			case 'pass':
				$order->add_order_note(
					__( 'NoFraud: Transaction passed fraud screening.', 'nofraud-woocommerce' )
				);
				break;

			case 'fail':
			case 'fraudulent':
				$label = 'fraudulent' === $decision ? 'fraudulent' : 'failed';
				$note  = sprintf(
					/* translators: 1: decision label, 2: custom message */
					__( 'NoFraud: Transaction marked as %1$s.%2$s', 'nofraud-woocommerce' ),
					$label,
					$message ? ' ' . $message : ''
				);
				NoFraud_Settings::apply_fail_decision( $order, $decision, $note );
				break;

			case 'review':
				$note = __( 'NoFraud: Transaction is under manual review. The order will be updated when a decision is made.', 'nofraud-woocommerce' );
				if ( 'hold' === NoFraud_Settings::get_review_action() ) {
					$order->update_status( 'on-hold', $note );
				} else {
					$order->add_order_note( $note );
				}
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: decision value */
						__( 'NoFraud: Unexpected decision "%s". Please check the NoFraud Portal.', 'nofraud-woocommerce' ),
						$decision
					)
				);
				break;
		}
	}

	private static function build_transaction_data( \WC_Order $order ): array {
		$data = [
			'amount'      => $order->get_total(),
			'customerIP'  => $order->get_customer_ip_address(),
			'gatewayName' => $order->get_payment_method_title(),
			'payment'     => self::build_payment_data( $order ),
			'app'         => 'nofraud-woocommerce',
			'appVersion'  => NOFRAUD_WC_VERSION,
		];

		$currency = $order->get_currency();
		if ( $currency && 'USD' !== $currency ) {
			$data['currency_code'] = $currency;
		}

		$data['customer'] = [ 'email' => $order->get_billing_email() ];
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$data['customer']['id'] = (string) $customer_id;

			$customer   = new \WC_Customer( $customer_id );
			$registered = $customer->get_date_created();
			if ( $registered ) {
				$data['customer']['joined_on'] = $registered->date( 'm/d/Y' );
			}

			$order_count = wc_get_customer_order_count( $customer_id );
			if ( $order_count > 0 ) {
				$data['customer']['total_previous_purchases'] = (string) ( $order_count - 1 );
			}

			$total_spent = wc_get_customer_total_spent( $customer_id );
			if ( $total_spent > 0 ) {
				$data['customer']['total_purchase_value'] = (string) $total_spent;
			}
		}

		$data['billTo'] = array_filter( [
			'firstName'   => $order->get_billing_first_name(),
			'lastName'    => $order->get_billing_last_name(),
			'company'     => $order->get_billing_company(),
			'address'     => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'city'        => $order->get_billing_city(),
			'state'       => $order->get_billing_state(),
			'zip'         => $order->get_billing_postcode(),
			'country'     => $order->get_billing_country(),
			'phoneNumber' => $order->get_billing_phone(),
		] );

		if ( $order->has_shipping_address() ) {
			$data['shipTo'] = array_filter( [
				'firstName' => $order->get_shipping_first_name(),
				'lastName'  => $order->get_shipping_last_name(),
				'company'   => $order->get_shipping_company(),
				'address'   => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ),
				'city'      => $order->get_shipping_city(),
				'state'     => $order->get_shipping_state(),
				'zip'       => $order->get_shipping_postcode(),
				'country'   => $order->get_shipping_country(),
			] );
		}

		$shipping_total = $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$data['shippingAmount'] = (string) $shipping_total;
		}

		$shipping_methods = $order->get_shipping_methods();
		if ( ! empty( $shipping_methods ) ) {
			$first_method           = reset( $shipping_methods );
			$data['shippingMethod'] = $first_method->get_method_title();
		}

		$discount = $order->get_discount_total();
		if ( $discount > 0 ) {
			$data['discountAmount'] = (string) $discount;
		}

		$avs_cvv = self::extract_avs_cvv( $order );
		if ( ! empty( $avs_cvv['avs'] ) ) {
			$data['avsResultCode'] = $avs_cvv['avs'];
		}
		if ( ! empty( $avs_cvv['cvv'] ) ) {
			$data['cvvResultCode'] = $avs_cvv['cvv'];
		}

		$data['order'] = [
			'invoiceNumber' => (string) $order->get_order_number(),
		];

		$data['lineItems'] = self::build_line_items( $order );

		return $data;
	}

	/**
	 * Build line items with batched category lookup to avoid N+1 queries.
	 */
	private static function build_line_items( \WC_Order $order ): array {
		$items       = $order->get_items();
		$products    = []; // Cache products to avoid double get_product() calls.
		$product_ids = [];

		foreach ( $items as $item_id => $item ) {
			$product = $item->get_product();
			$products[ $item_id ] = $product;
			if ( $product ) {
				$product_ids[] = $product->get_id();
			}
		}

		$category_map = [];
		if ( ! empty( $product_ids ) ) {
			$terms = wp_get_object_terms( $product_ids, 'product_cat', [ 'fields' => 'all' ] );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! isset( $category_map[ $term->object_id ] ) ) {
						$category_map[ $term->object_id ] = $term->name;
					}
				}
			}
		}

		$line_items = [];
		foreach ( $items as $item_id => $item ) {
			$product = $products[ $item_id ];
			$li      = [
				'name'     => $item->get_name(),
				'price'    => (float) $order->get_item_subtotal( $item, false ),
				'quantity' => $item->get_quantity(),
			];
			if ( $product ) {
				$li['sku'] = $product->get_sku() ?: (string) $product->get_id();
				if ( isset( $category_map[ $product->get_id() ] ) ) {
					$li['category'] = $category_map[ $product->get_id() ];
				}
			}
			$line_items[] = $li;
		}

		return $line_items;
	}

	private static function build_payment_data( \WC_Order $order ): array {
		$payment = [ 'method' => 'Credit Card' ];

		$credit_card = array_filter( [
			'last4'    => self::extract_first_meta( $order, [
				'_payroc_card_last4',
				'_stripe_card_last4',
				'_card_last4',
				'_braintree_card_last_four',
				'_authorize_net_card_last4',
				'_square_card_last4',
				'_wc_paypal_braintree_card_last_four',
			] ),
			'cardType' => self::extract_first_meta( $order, [
				'_payroc_card_type',
				'_stripe_card_type',
				'_card_type',
				'_braintree_card_type',
				'_authorize_net_card_type',
				'_square_card_brand',
			] ),
			'bin'      => self::extract_first_meta( $order, [ '_card_bin', '_stripe_card_bin' ] ),
		] );

		// Fallback: check nested transaction data for last4.
		if ( empty( $credit_card['last4'] ) ) {
			$txn_data = $order->get_meta( '_transaction_data' );
			if ( is_array( $txn_data ) && ! empty( $txn_data['last4'] ) ) {
				$credit_card['last4'] = sanitize_text_field( $txn_data['last4'] );
			}
		}

		if ( ! empty( $credit_card ) ) {
			$payment['creditCard'] = $credit_card;
		}

		return $payment;
	}

	/**
	 * Return the first non-empty meta value from a list of keys.
	 */
	private static function extract_first_meta( \WC_Order $order, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( $value ) {
				return sanitize_text_field( $value );
			}
		}
		return '';
	}

	/**
	 * @return array{avs: string, cvv: string}
	 */
	private static function extract_avs_cvv( \WC_Order $order ): array {
		$avs_keys = [
			'_payroc_avs_response',
			'_stripe_avs_result',
			'_stripe_address_line1_check',
			'_authorize_net_avs_result',
			'_avs_result_code',
		];
		$cvv_keys = [
			'_payroc_cvv_response',
			'_stripe_cvc_result',
			'_authorize_net_cvv_result',
			'_cvv_result_code',
		];

		return [
			'avs' => self::extract_first_meta( $order, $avs_keys ),
			'cvv' => self::extract_first_meta( $order, $cvv_keys ),
		];
	}
}
