<?php
/**
 * NoFraud webhook handler.
 *
 * Registers a WP REST API endpoint to receive transaction status updates
 * from NoFraud when review orders are updated to pass, fail, or fraudulent.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Webhook {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( 'nofraud/v1', '/webhook', [
			'methods'             => [ 'POST', 'PUT', 'PATCH' ],
			'callback'            => [ __CLASS__, 'handle_webhook' ],
			'permission_callback' => [ __CLASS__, 'verify_webhook' ],
		] );
	}

	/**
	 * If a webhook secret is configured, validate it against the request header.
	 * When no secret is set the endpoint is open — configure a secret in settings for production use.
	 */
	public static function verify_webhook( \WP_REST_Request $request ): bool {
		$secret = get_option( 'nofraud_wc_webhook_secret', '' );
		if ( empty( $secret ) ) {
			return true;
		}

		$header_secret = $request->get_header( 'X-NoFraud-Secret' );
		return hash_equals( $secret, (string) $header_secret );
	}

	/**
	 * Handle an incoming webhook from NoFraud.
	 *
	 * Expected body:
	 * {
	 *   "id": "<nofraud-transaction-id>",
	 *   "decision": "pass|fail|fraudulent",
	 *   "invoiceNumber": "<order-number>"
	 * }
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		NoFraud_Settings::log( 'Webhook received: ' . wp_json_encode( $body ) );

		$transaction_id = sanitize_text_field( $body['id'] ?? '' );
		$decision       = sanitize_text_field( $body['decision'] ?? '' );
		$invoice_number = sanitize_text_field( $body['invoiceNumber'] ?? '' );

		if ( empty( $decision ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing decision field.' ], 400 );
		}

		$allowed_decisions = [ 'pass', 'fail', 'fraudulent', 'review', 'error' ];
		if ( ! in_array( $decision, $allowed_decisions, true ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid decision value.' ], 400 );
		}

		$order = self::find_order( $transaction_id, $invoice_number );
		if ( ! $order ) {
			NoFraud_Settings::log( 'Webhook: Could not find order for transaction ' . $transaction_id . ' / invoice ' . $invoice_number, 'warning' );
			return new \WP_REST_Response( [ 'error' => 'Order not found.' ], 404 );
		}

		$previous_decision = $order->get_meta( NoFraud_Settings::META_DECISION );

		$order->update_meta_data( NoFraud_Settings::META_DECISION, $decision );
		$order->update_meta_data( NoFraud_Settings::META_WEBHOOK_UPDATED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->save();

		NoFraud_Settings::log(
			sprintf( 'Webhook: Order #%s decision updated from "%s" to "%s".', $order->get_id(), $previous_decision, $decision )
		);

		switch ( $decision ) {
			case 'pass':
				$order->add_order_note(
					__( 'NoFraud: Review completed - transaction approved. Releasing order.', 'nofraud-woocommerce' )
				);
				if ( 'on-hold' === $order->get_status() ) {
					$order->update_status( 'processing', __( 'NoFraud review passed.', 'nofraud-woocommerce' ) );
				}
				break;

			case 'fail':
			case 'fraudulent':
				$label = 'fraudulent' === $decision ? 'fraudulent' : 'failed';
				$note  = sprintf(
					/* translators: %s: decision label */
					__( 'NoFraud: Review completed - transaction marked as %s.', 'nofraud-woocommerce' ),
					$label
				);
				NoFraud_Settings::apply_fail_decision( $order, $decision, $note );
				break;

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: decision value */
						__( 'NoFraud webhook: Received decision "%s".', 'nofraud-woocommerce' ),
						$decision
					)
				);
				break;
		}

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	private static function find_order( string $transaction_id, string $invoice_number ): ?\WC_Order {
		if ( $transaction_id ) {
			$orders = wc_get_orders( [
				'meta_key'   => NoFraud_Settings::META_TRANSACTION_ID,
				'meta_value' => $transaction_id,
				'limit'      => 1,
			] );

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		if ( $invoice_number ) {
			$order = wc_get_order( $invoice_number );
			if ( $order ) {
				return $order;
			}
		}

		return null;
	}
}
