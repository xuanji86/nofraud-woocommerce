<?php
/**
 * NoFraud webhook handler.
 *
 * Registers a WP REST API endpoint to receive transaction status updates
 * from NoFraud when review orders are updated to pass, fail, or fraudulent.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Webhook {

	private const SYNC_HOOK = 'nofraud_wc_webhook_sync';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( self::SYNC_HOOK, [ __CLASS__, 'deferred_sync' ], 10, 1 );
	}

	public static function register_routes(): void {
		register_rest_route( 'nofraud/v1', '/webhook', [
			'methods'             => [ 'GET', 'POST', 'PUT', 'PATCH' ],
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
	 * NoFraud's suggested body is { "id": "%transaction_url%", "decision": ..., "invoiceNumber": ... }
	 * and it sends no auth header by default, so the body is only a ping: the decision acted
	 * on is re-read from the status API with our own key. A forged "pass" cannot release an order.
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_params(); // JSON, form or query string — NoFraud may send any of them.

		NoFraud_Settings::log( 'Webhook received: ' . wp_json_encode( $body ) );

		// `id` may be the bare UUID or the portal URL ending in it.
		$transaction_id = preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) ( $body['id'] ?? '' ), $m ) ? $m[0] : '';
		$invoice_number = ltrim( sanitize_text_field( (string) ( $body['invoiceNumber'] ?? '' ) ), '#' );

		$order = self::find_order( $transaction_id, $invoice_number );
		if ( ! $order ) {
			NoFraud_Settings::log( 'Webhook: Could not find order for transaction ' . $transaction_id . ' / invoice ' . $invoice_number, 'warning' );
			return new \WP_REST_Response( [ 'error' => 'Order not found.' ], 404 );
		}

		$stored_id = (string) $order->get_meta( NoFraud_Settings::META_TRANSACTION_ID );
		if ( '' === $stored_id ) {
			// Never screened by this plugin (skipped / not yet sent): nothing to update.
			return new \WP_REST_Response( [ 'error' => 'Order has no NoFraud transaction.' ], 404 );
		}

		// The endpoint is unauthenticated unless a secret is set; cap the status lookups
		// it can trigger so it cannot be used to hammer the API with the store's key. A
		// throttled hit is deferred, never dropped: it may be NoFraud's real notification.
		$throttle = 'nofraud_wh_' . $order->get_id();
		if ( get_transient( $throttle ) ) {
			$args = [ $order->get_id() ];
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SYNC_HOOK, $args, 'nofraud' ) ) {
				as_schedule_single_action( time() + 15, self::SYNC_HOOK, $args, 'nofraud' );
			}
			return new \WP_REST_Response( [ 'status' => 'queued' ], 202 );
		}
		set_transient( $throttle, 1, 10 );

		return self::sync_order( $order );
	}

	public static function deferred_sync( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			self::sync_order( $order );
		}
	}

	/**
	 * Re-read the order's decision from the status API and apply any change.
	 */
	private static function sync_order( \WC_Order $order ): \WP_REST_Response {
		$stored_id = (string) $order->get_meta( NoFraud_Settings::META_TRANSACTION_ID );
		$status    = NoFraud_API::get_transaction_status( $stored_id );
		if ( empty( $status['success'] ) || empty( $status['decision'] ) ) {
			NoFraud_Settings::log( 'Webhook: status lookup failed for order #' . $order->get_id() . ': ' . ( $status['error'] ?? 'no decision' ), 'error' );
			return new \WP_REST_Response( [ 'error' => 'Could not verify decision.' ], 503 );
		}
		$decision = sanitize_text_field( (string) $status['decision'] );

		$previous_decision = (string) $order->get_meta( NoFraud_Settings::META_DECISION );
		if ( $decision === $previous_decision ) {
			return new \WP_REST_Response( [ 'status' => 'unchanged' ], 200 );
		}

		$order->update_meta_data( NoFraud_Settings::META_DECISION, $decision );
		$order->update_meta_data( NoFraud_Settings::META_WEBHOOK_UPDATED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->save();

		NoFraud_Settings::log(
			sprintf( 'Webhook: Order #%s decision updated from "%s" to "%s".', $order->get_id(), $previous_decision, $decision )
		);

		switch ( $decision ) {
			case 'pass':
				$order->add_order_note(
					__( 'NoFraud: Review completed - transaction approved.', 'nofraud-woocommerce' )
				);
				// Release only a hold NoFraud placed; staff / ffl-core holds stay put.
				if ( 'on-hold' === $order->get_status() && $order->get_meta( NoFraud_Settings::META_HOLD ) ) {
					$order->delete_meta_data( NoFraud_Settings::META_HOLD );
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
				if ( $order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
					// Too late (or moot) to act automatically; flag it for staff.
					$order->add_order_note( $note . ' ' . __( 'Order status left unchanged — review manually.', 'nofraud-woocommerce' ) );
					break;
				}
				NoFraud_Settings::apply_fail_decision( $order, $decision, $note );
				if ( 'cancel' === NoFraud_Settings::get_fail_action() ) {
					NoFraud_Checkout::attempt_refund( $order );
				}
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
