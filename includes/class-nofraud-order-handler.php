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

	private const META_ATTEMPTS = '_nofraud_attempts';
	private const MAX_ATTEMPTS  = 3;
	private const RETRY_HOOK    = 'nofraud_wc_retry_screen';
	/** Decision placeholder while a retry is queued; blocks status-transition re-screening. */
	private const RETRYING      = 'retrying';

	public static function init(): void {
		add_action( self::RETRY_HOOK, [ __CLASS__, 'retry_screen' ], 10, 1 );

		// Standards-compliant gateways fire this when they call $order->payment_complete().
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'screen_order' ], 20, 1 );

		// Gateways that bypass payment_complete() only surface via status-transition hooks.
		// Use transition-specific hooks (from→to) so only genuine payment events trigger
		// screening. Generic hooks like woocommerce_order_status_completed would also fire
		// when admin marks historical orders complete (processing→completed), causing those
		// orders to be screened and incorrectly receive a NoFraud decision.
		foreach ( [ 'pending', 'on-hold', 'failed' ] as $from ) {
			add_action( "woocommerce_order_status_{$from}_to_processing", [ __CLASS__, 'screen_order' ], 20, 1 );
			add_action( "woocommerce_order_status_{$from}_to_completed",  [ __CLASS__, 'screen_order' ], 20, 1 );
		}
	}

	/**
	 * Screen an order after payment is complete.
	 */
	public static function screen_order( int $order_id, bool $async = false ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency: this hook can fire multiple times per order (payment_complete +
		// status_processing + status_completed). A recorded decision or skip flag means
		// a previous firing already handled it; a queued retry is owned by the retry job.
		$decision = (string) $order->get_meta( NoFraud_Settings::META_DECISION );
		$retrying = self::RETRYING === $decision;
		if ( $order->get_meta( NoFraud_Settings::META_TRANSACTION_ID ) || ( '' !== $decision && ! ( $retrying && $async ) ) ) {
			return;
		}

		$ship = self::resolve_ship_to( $order );

		// Per NoFraud: FFL orders are ours to skip (then uncovered) or to send with the
		// dealer as shipTo (then covered). Never allowlist them on NoFraud's side instead.
		$reason = self::ffl_skip_reason( $order, $ship );
		if ( apply_filters( 'nofraud_wc_should_skip_order', '' !== $reason, $order ) ) {
			$reason = $reason ?: __( 'excluded by the nofraud_wc_should_skip_order filter', 'nofraud-woocommerce' );
			$order->update_meta_data( NoFraud_Settings::META_DECISION, 'skipped' );
			/* translators: %s: skip reason */
			$order->add_order_note( sprintf( __( 'NoFraud: Skipped screening — %s.', 'nofraud-woocommerce' ), $reason ) );
			$order->save();
			NoFraud_Settings::log( 'Skipping NoFraud for order #' . $order_id . ' — ' . $reason . '.' );
			return;
		}

		$transaction_data = self::build_transaction_data( $order, $ship );

		NoFraud_Settings::log( 'Screening order #' . $order_id . '. Payload: ' . wp_json_encode( $transaction_data ) );

		$result = NoFraud_API::create_transaction( $transaction_data );

		NoFraud_Settings::log( 'NoFraud response for order #' . $order_id . ': ' . wp_json_encode( $result ) );

		// Transport/HTTP failures and an `error` decision both mean "not screened": record
		// nothing final so a retry can still screen the order.
		if ( empty( $result['success'] ) || 'error' === ( $result['decision'] ?? '' ) ) {
			$error = $result['error'] ?? ( $result['message'] ?? 'NoFraud returned decision "error"' );
			NoFraud_Settings::log( 'NoFraud screening error for order #' . $order_id . ': ' . $error, 'error' );
			// 4xx (bad payload, invalid key) will fail the same way again.
			$code = (int) ( $result['code'] ?? 0 );
			self::schedule_retry( $order, $error, $code >= 400 && $code < 500 );
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

		self::apply_decision( $order, $decision, $message, $async );
	}

	public static function retry_screen( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || $order->has_status( [ 'cancelled', 'refunded', 'failed' ] ) ) {
			return;
		}
		self::screen_order( (int) $order_id, true );
	}

	/**
	 * @param bool $permanent The error will not go away on retry (HTTP 4xx).
	 */
	private static function schedule_retry( \WC_Order $order, string $error, bool $permanent = false ): void {
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, (string) $attempts );

		if ( ! $permanent && $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			$order->update_meta_data( NoFraud_Settings::META_DECISION, self::RETRYING );
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::RETRY_HOOK, [ $order->get_id() ], 'nofraud' );
			/* translators: 1: error message, 2: attempt number */
			$note = sprintf( __( 'NoFraud screening failed: %1$s. Retrying in 5 minutes (attempt %2$d).', 'nofraud-woocommerce' ), $error, $attempts );
		} else {
			// Terminal: record it so the orders list shows "Error" and nothing re-screens it silently.
			$order->update_meta_data( NoFraud_Settings::META_DECISION, 'error' );
			/* translators: %s: error message */
			$note = sprintf( __( 'NoFraud screening failed: %s. This order was NOT screened; review it manually before fulfilment.', 'nofraud-woocommerce' ), $error );
		}
		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * @param bool $async True when the decision arrives after checkout (retry). The checkout
	 *                    interceptor refunds synchronous fails; async cancellations refund here.
	 */
	private static function apply_decision( \WC_Order $order, string $decision, string $message = '', bool $async = false ): void {
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
				if ( $async && 'cancel' === NoFraud_Settings::get_fail_action() ) {
					NoFraud_Checkout::attempt_refund( $order );
				}
				break;

			case 'review':
				$note = __( 'NoFraud: Transaction is under manual review. The order will be updated when a decision is made.', 'nofraud-woocommerce' );
				if ( 'hold' === NoFraud_Settings::get_review_action() ) {
					NoFraud_Settings::hold( $order, $note );
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

	/**
	 * Why the FFL Orders setting skips this order, or '' to screen it.
	 *
	 * @param array{to_ffl: bool} $ship resolve_ship_to() result.
	 */
	private static function ffl_skip_reason( \WC_Order $order, array $ship ): string {
		switch ( NoFraud_Settings::ffl_orders_mode() ) {
			case 'skip_ffl_only':
				return self::order_is_ffl_only( $order ) ? __( 'all items require FFL shipment', 'nofraud-woocommerce' ) : '';
			case 'screen':
				return '';
			default: // skip_ffl_address
				return $ship['to_ffl'] ? __( 'the order goes to an FFL address', 'nofraud-woocommerce' ) : '';
		}
	}

	/**
	 * Returns true when every line item on the order ships to an FFL. Mixed carts
	 * return false so they are always screened.
	 */
	private static function order_is_ffl_only( \WC_Order $order ): bool {
		// g-FFL Checkout also writes `_order_shipment_type`, but stamps a mixed cart
		// `ffl_only` when its mixed-cart support is off — so ask its per-item helper.
		$g_ffl = function_exists( 'item_requires_ffl_shipment' );

		// ffl-core classifies the cart at checkout (firearms plus its state ammo /
		// all-goods compliance rules); its `ffl_only` means every item needs an FFL.
		$type = (string) $order->get_meta( '_order_shipment_type' );
		if ( ! $g_ffl && '' !== $type ) {
			return 'ffl_only' === $type;
		}

		// g-FFL, or orders ffl-core never classified (admin- or REST-created).
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				return false;
			}
			$requires = $g_ffl ? (bool) item_requires_ffl_shipment( $product, $order ) : self::is_firearm( $product );
			if ( ! $requires ) {
				return false;
			}
		}
		return true;
	}

	/** ffl-core's "Requires FFL Shipment" flag; variations inherit it from the parent. */
	private static function is_firearm( \WC_Product $product ): bool {
		if ( 'yes' === $product->get_meta( '_firearm_product' ) ) {
			return true;
		}
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		return $parent instanceof \WC_Product && 'yes' === $parent->get_meta( '_firearm_product' );
	}

	/**
	 * The address NoFraud should screen: where the covered goods actually go
	 * (NoFraud's ruling, 2026-09-28 — coverage follows the address screened).
	 *
	 *  - Mixed cart, shopper sent the non-firearm items home (ffl-core `ffl_core_ship_home`)
	 *    → that home address; the firearm on the FFL leg is not covered.
	 *  - g-FFL mixed cart (`_is_mixed_cart_order`): g-FFL keeps the customer's address in
	 *    shipping_* → that address (billing if it was overwritten with the dealer's).
	 *  - In-store pickup (the store's own FFL) → the store address, flagged isBopis.
	 *  - Anything else going to a dealer → the dealer premise, which NoFraud matches
	 *    against the ATF licensee list.
	 *  - Otherwise (regular goods, C&R to the collector) → the order's shipping address.
	 *
	 * @return array{ship_to: array<string,string>|null, bopis: bool, to_ffl: bool}
	 */
	private static function resolve_ship_to( \WC_Order $order ): array {
		$first = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();

		$home = $order->get_meta( 'ffl_core_ship_home' );
		if ( is_array( $home ) && ! empty( $home['address_1'] ) ) {
			return self::ship( self::address( [
				'firstName' => $home['first_name'] ?? $first,
				'lastName'  => $home['last_name'] ?? $last,
				'address'   => trim( ( $home['address_1'] ?? '' ) . ' ' . ( $home['address_2'] ?? '' ) ),
				'city'      => $home['city'] ?? '',
				'state'     => $home['state'] ?? '',
				'zip'       => $home['postcode'] ?? '',
				'country'   => ( $home['country'] ?? '' ) ?: $order->get_shipping_country(),
			] ), false, false );
		}

		$premise = trim( (string) $order->get_meta( '_shipping_ffl_premise_street' ) );

		if ( 'yes' === $order->get_meta( '_is_mixed_cart_order' ) ) {
			$overwritten = '' !== $premise && 0 === strcasecmp( $premise, trim( $order->get_shipping_address_1() ) );
			$type        = $overwritten ? 'billing' : 'shipping';
			if ( $overwritten ) {
				NoFraud_Settings::log( 'Order #' . $order->get_id() . ': g-FFL mixed cart carries the dealer address; using billing as shipTo.', 'warning' );
			}
			return self::ship( self::address( [
				'firstName' => $first,
				'lastName'  => $last,
				'company'   => $order->{"get_{$type}_company"}(),
				'address'   => trim( $order->{"get_{$type}_address_1"}() . ' ' . $order->{"get_{$type}_address_2"}() ),
				'city'      => $order->{"get_{$type}_city"}(),
				'state'     => $order->{"get_{$type}_state"}(),
				'zip'       => $order->{"get_{$type}_postcode"}(),
				'country'   => $order->{"get_{$type}_country"}(),
			] ), false, false );
		}

		$license = trim( (string) $order->get_meta( '_shipping_fflno' ) );
		$pickup  = trim( (string) get_option( 'ffl_core_in_store_pickup_license', '' ) );
		if ( '' !== $license && $license === $pickup ) {
			$wc = WC()->countries;
			return self::ship( self::address( [
				'firstName' => $first,
				'lastName'  => $last,
				'company'   => get_bloginfo( 'name' ),
				'address'   => trim( $wc->get_base_address() . ' ' . $wc->get_base_address_2() ),
				'city'      => $wc->get_base_city(),
				'state'     => $wc->get_base_state(),
				'zip'       => $wc->get_base_postcode(),
				'country'   => $wc->get_base_country(),
			] ), true, true );
		}

		if ( '' !== $license && '' !== $premise ) {
			return self::ship( self::address( [
				'firstName' => $first,
				'lastName'  => $last,
				'company'   => (string) $order->get_meta( '_shipping_ffl_name' ),
				'address'   => $premise,
				'city'      => (string) $order->get_meta( '_shipping_ffl_premise_city' ),
				'state'     => (string) $order->get_meta( '_shipping_ffl_premise_state' ),
				'zip'       => (string) $order->get_meta( '_shipping_ffl_premise_zip' ),
				'country'   => 'US',
			] ), false, true );
		}

		if ( $order->has_shipping_address() ) {
			return self::ship( self::address( [
				'firstName' => $first,
				'lastName'  => $last,
				'company'   => $order->get_shipping_company(),
				'address'   => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() ),
				'city'      => $order->get_shipping_city(),
				'state'     => $order->get_shipping_state(),
				'zip'       => $order->get_shipping_postcode(),
				'country'   => $order->get_shipping_country(),
			] ), false, false );
		}

		return self::ship( null, false, false );
	}

	private static function ship( ?array $ship_to, bool $bopis, bool $to_ffl ): array {
		return [ 'ship_to' => $ship_to, 'bopis' => $bopis, 'to_ffl' => $to_ffl ];
	}

	/** Drop empty fields and clip to NoFraud's 128-char address limit. */
	private static function address( array $fields ): array {
		return array_map(
			static fn( $v ) => mb_substr( (string) $v, 0, 128 ),
			array_filter( $fields, static fn( $v ) => '' !== trim( (string) $v ) )
		);
	}

	/**
	 * @param array|null $ship resolve_ship_to() result; resolved here when omitted.
	 */
	private static function build_transaction_data( \WC_Order $order, ?array $ship = null ): array {
		$ship = $ship ?? self::resolve_ship_to( $order );

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

		// Required by the API even when empty — cast so an empty one encodes as {} not [].
		$data['billTo'] = (object) self::address( [
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

		if ( $ship['ship_to'] ) {
			$data['shipTo'] = $ship['ship_to'];
		}
		if ( $ship['bopis'] ) {
			$data['isBopis'] = 'true';
		}

		$user_fields = array_filter( [
			'fflLicense'   => (string) $order->get_meta( '_shipping_fflno' ),
			'shipmentType' => (string) $order->get_meta( '_order_shipment_type' ),
		] );
		if ( $user_fields ) {
			$data['userFields'] = $user_fields;
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

		if ( $order->get_transaction_id() ) {
			$data['transaction-id'] = $order->get_transaction_id();
		}
		$authcode = self::extract_first_meta( $order, [ '_payroc_approval_code' ] );
		if ( $authcode ) {
			$data['authcode'] = $authcode;
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
			'bin'            => self::extract_first_meta( $order, [ '_payroc_card_bin', '_card_bin', '_stripe_card_bin' ] ),
			'expirationDate' => self::extract_first_meta( $order, [ '_payroc_card_expiry' ] ),
		] );

		// Fallback: check nested transaction data for last4.
		if ( empty( $credit_card['last4'] ) ) {
			$txn_data = $order->get_meta( '_transaction_data' );
			if ( is_array( $txn_data ) && ! empty( $txn_data['last4'] ) ) {
				$credit_card['last4'] = sanitize_text_field( $txn_data['last4'] );
			}
		}

		// creditCard is required by the API; send {} rather than omit it when the gateway exposed nothing.
		$payment['creditCard'] = (object) $credit_card;

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

		// NoFraud takes gateway result codes only (AVS ≤3 chars, CVV 1 uppercase char);
		// drop anything else (e.g. Stripe's "pass"/"unavailable") rather than fail the request.
		$avs = strtoupper( trim( self::extract_first_meta( $order, $avs_keys ) ) );
		$cvv = strtoupper( trim( self::extract_first_meta( $order, $cvv_keys ) ) );
		return [
			'avs' => strlen( $avs ) <= 3 ? $avs : '',
			'cvv' => 1 === strlen( $cvv ) ? $cvv : '',
		];
	}
}
