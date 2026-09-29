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

	private const META_REFUND_ATTEMPTED = '_nofraud_refund_attempted';

	public static function init(): void {
		add_filter( 'woocommerce_payment_successful_result', [ __CLASS__, 'intercept_classic_checkout' ], 999, 2 );
		// The Store API fires checkout_order_processed BEFORE taking payment, so the decision
		// only exists after the payment hook; run after WC's legacy gateway bridge (999).
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ __CLASS__, 'intercept_block_checkout' ], 1000, 2 );
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
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentResult  $result
	 * @throws \Exception Caught by the Store API and returned to the shopper as a 400 checkout error.
	 */
	public static function intercept_block_checkout( $context, $result ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$order = $context->order ?? null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// Payment just updated the order in another instance; read the decision fresh.
		$order = wc_get_order( $order->get_id() );

		$decision = $order->get_meta( NoFraud_Settings::META_DECISION );
		if ( ! NoFraud_Settings::is_fail_decision( $decision ) ) {
			return;
		}

		NoFraud_Settings::log( 'Block checkout intercepted for order #' . $order->get_id() . ': decision=' . $decision );

		self::attempt_refund( $order );

		throw new \Exception( self::get_error_message() );
	}

	/**
	 * Attempt a full refund. Guarded against double execution.
	 */
	public static function attempt_refund( \WC_Order $order ): void {
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
