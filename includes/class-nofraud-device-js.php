<?php
/**
 * NoFraud Device JavaScript.
 *
 * Adds the NoFraud device fingerprinting script to cart and checkout pages.
 * This script captures device information and the customer's IP address,
 * which NoFraud links to the transaction for improved fraud detection accuracy.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Device_JS {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_device_script' ] );
	}

	public static function enqueue_device_script(): void {
		// Cheapest check first — skip all option reads on non-checkout pages.
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$account_code = sanitize_text_field( get_option( 'nofraud_wc_device_js_code', '' ) );
		if ( empty( $account_code ) ) {
			return;
		}

		wp_enqueue_script(
			'nofraud-device-js',
			'https://services.nofraud.com/js/' . $account_code . '/customer_code.js',
			[],
			null,
			[
				'in_footer' => true,
				'strategy'  => 'async',
			]
		);
	}
}
