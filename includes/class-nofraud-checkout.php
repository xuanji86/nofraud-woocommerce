<?php
/**
 * NoFraud checkout integration.
 *
 * Intercepts the checkout flow when NoFraud returns a fail/fraudulent decision,
 * keeping the customer on the checkout page with a friendly error message and
 * attempting an automatic refund.
 *
 * Works with both WooCommerce Classic Checkout and Block Checkout.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Checkout {

	private const ERROR_MESSAGE_KEY = 'nofraud_checkout_error';
	private const META_REFUND_ATTEMPTED = '_nofraud_refund_attempted';

	public static function init(): void {
		add_filter( 'woocommerce_payment_successful_result', [ __CLASS__, 'intercept_classic_checkout' ], 999, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'intercept_block_checkout' ], 999, 1 );
	}

	public static function intercept_classic_checkout( array $result, int $order_id ): array {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return $result;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $result;
		}

		$decision = $order->get_meta( NoFraud_Settings::META_DECISION );
		if ( ! NoFraud_Settings::is_fail_decision( $decision ) ) {
			return $result;
		}

		NoFraud_Settings::log( 'Checkout intercepted for order #' . $order_id . ': decision=' . $decision );

		self::attempt_refund( $order );

		wc_add_notice( self::get_error_message(), 'error' );

		return [
			'result'   => 'failure',
			'messages' => wc_print_notices( true ),
			'redirect' => '',
		];
	}

	/**
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException
	 */
	public static function intercept_block_checkout( \WC_Order $order ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$decision = $order->get_meta( NoFraud_Settings::META_DECISION );
		if ( ! NoFraud_Settings::is_fail_decision( $decision ) ) {
			return;
		}

		NoFraud_Settings::log( 'Block checkout intercepted for order #' . $order->get_id() . ': decision=' . $decision );

		self::attempt_refund( $order );

		if ( class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				self::ERROR_MESSAGE_KEY,
				self::get_error_message(),
				400
			);
		}
	}

	/**
	 * Attempt a full refund. Guarded against double execution.
	 */
	private static function attempt_refund( \WC_Order $order ): void {
		// Prevent double refund if both classic and block hooks fire.
		if ( $order->get_meta( self::META_REFUND_ATTEMPTED ) ) {
			return;
		}
		$order->update_meta_data( self::META_REFUND_ATTEMPTED, '1' );
		$order->save();

		$total = (float) $order->get_total();
		if ( $total <= 0 ) {
			return;
		}

		if ( ! $order->get_transaction_id() ) {
			$order->add_order_note(
				__( 'NoFraud: No transaction ID found. Skipping automatic refund — manual refund may be required.', 'nofraud-woocommerce' )
			);
			return;
		}

		$refund = wc_create_refund( [
			'order_id'       => $order->get_id(),
			'amount'         => $total,
			'reason'         => __( 'NoFraud fraud screening: transaction failed.', 'nofraud-woocommerce' ),
			'refund_payment' => true,
		] );

		if ( is_wp_error( $refund ) ) {
			$error_msg = $refund->get_error_message();
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'NoFraud: Automatic refund failed — %s. Manual refund may be required.', 'nofraud-woocommerce' ),
					$error_msg
				)
			);
			NoFraud_Settings::log( 'Refund failed for order #' . $order->get_id() . ': ' . $error_msg, 'error' );
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: refund amount */
					__( 'NoFraud: Automatic refund of %s processed successfully.', 'nofraud-woocommerce' ),
					wc_price( $total, [ 'currency' => $order->get_currency() ] )
				)
			);
			NoFraud_Settings::log( 'Refund of ' . $total . ' processed for order #' . $order->get_id() );
		}
	}

	private static function get_error_message(): string {
		return __( 'For the security of your account, we were unable to complete this transaction. Please verify your billing address and payment information, then try again.', 'nofraud-woocommerce' );
	}
}
