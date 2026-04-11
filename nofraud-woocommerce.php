<?php
/**
 * Plugin Name: NoFraud for WooCommerce
 * Plugin URI:  https://www.nofraud.com
 * Description: Credit card fraud detection for WooCommerce powered by NoFraud.
 * Version:     1.2.0
 * Author:      Anji Xu
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nofraud-woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.9.4
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 */

defined( 'ABSPATH' ) || exit;

define( 'NOFRAUD_WC_VERSION', '1.2.0' );
define( 'NOFRAUD_WC_PLUGIN_FILE', __FILE__ );
define( 'NOFRAUD_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOFRAUD_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Check that WooCommerce is active before bootstrapping.
 */
function nofraud_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'NoFraud for WooCommerce requires WooCommerce to be installed and active.', 'nofraud-woocommerce' );
			echo '</p></div>';
		} );
		return;
	}

	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-api.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-settings.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-order-handler.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-webhook.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-device-js.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-admin-order.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/class-nofraud-checkout.php';
	require_once NOFRAUD_WC_PLUGIN_DIR . 'includes/gateways/class-nofraud-payroc.php';

	NoFraud_Settings::init();
	NoFraud_Order_Handler::init();
	NoFraud_Checkout::init();
	NoFraud_Webhook::init();
	NoFraud_Device_JS::init();
	NoFraud_Admin_Order::init();
	NoFraud_Payroc::init();
}
add_action( 'plugins_loaded', 'nofraud_wc_init' );

register_activation_hook( __FILE__, function () {
	update_option( 'nofraud_wc_version', NOFRAUD_WC_VERSION );
} );
