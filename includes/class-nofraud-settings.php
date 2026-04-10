<?php
/**
 * NoFraud WooCommerce settings.
 *
 * Adds a "NoFraud" tab to WooCommerce > Settings.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Settings {

	// Order meta keys.
	public const META_TRANSACTION_ID     = '_nofraud_transaction_id';
	public const META_DECISION           = '_nofraud_decision';
	public const META_SCREENED_AT        = '_nofraud_screened_at';
	public const META_MESSAGE            = '_nofraud_message';
	public const META_WEBHOOK_UPDATED_AT = '_nofraud_webhook_updated_at';

	public static function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_settings_tab' ], 50 );
		add_action( 'woocommerce_settings_tabs_nofraud', [ __CLASS__, 'output_settings' ] );
		add_action( 'woocommerce_update_options_nofraud', [ __CLASS__, 'save_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( NOFRAUD_WC_PLUGIN_FILE ), [ __CLASS__, 'plugin_action_links' ] );
		add_action( 'wp_ajax_nofraud_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
	}

	/**
	 * Add the NoFraud tab to WooCommerce settings.
	 */
	public static function add_settings_tab( array $tabs ): array {
		$tabs['nofraud'] = __( 'NoFraud', 'nofraud-woocommerce' );
		return $tabs;
	}

	/**
	 * Output settings fields.
	 */
	public static function output_settings(): void {
		woocommerce_admin_fields( self::get_settings() );

		// Test connection button.
		self::render_test_connection_button();

		// Show webhook URL info.
		$webhook_url = rest_url( 'nofraud/v1/webhook' );
		echo '<tr valign="top"><th scope="row" class="titledesc">';
		esc_html_e( 'Webhook URL', 'nofraud-woocommerce' );
		echo '</th><td class="forminp">';
		echo '<code>' . esc_html( $webhook_url ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Provide this URL to NoFraud support to receive transaction status updates for orders in review.', 'nofraud-woocommerce' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Render the API test connection button and inline JS.
	 */
	private static function render_test_connection_button(): void {
		$nonce = wp_create_nonce( 'nofraud_test_connection' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php esc_html_e( 'API Connection', 'nofraud-woocommerce' ); ?>
			</th>
			<td class="forminp">
				<button type="button" id="nofraud-test-connection" class="button button-secondary">
					<?php esc_html_e( 'Test Connection', 'nofraud-woocommerce' ); ?>
				</button>
				<span id="nofraud-test-result" style="margin-left: 12px; vertical-align: middle;"></span>
				<p class="description">
					<?php esc_html_e( 'Test your API key using the values currently entered above (no need to save first).', 'nofraud-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<script>
		(function() {
			var btn = document.getElementById('nofraud-test-connection');
			var result = document.getElementById('nofraud-test-result');
			if (!btn) return;

			btn.addEventListener('click', function() {
				var apiKey = document.getElementById('nofraud_wc_api_key');
				var mode = document.getElementById('nofraud_wc_mode');

				if (!apiKey || !apiKey.value.trim()) {
					result.innerHTML = '<span style="color:#dc3232;">&#10005; <?php echo esc_js( __( 'Please enter an API key first.', 'nofraud-woocommerce' ) ); ?></span>';
					return;
				}

				btn.disabled = true;
				result.innerHTML = '<span style="color:#666;"><?php echo esc_js( __( 'Testing...', 'nofraud-woocommerce' ) ); ?></span>';

				var data = new FormData();
				data.append('action', 'nofraud_test_connection');
				data.append('nonce', '<?php echo esc_js( $nonce ); ?>');
				data.append('api_key', apiKey.value.trim());
				data.append('mode', mode ? mode.value : 'test');

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(resp) {
						btn.disabled = false;
						if (resp.success) {
							var modeLabel = resp.data.mode === 'live' ? 'Live' : 'Test';
							result.innerHTML = '<span style="color:#46b450;">&#10003; ' +
								'<?php echo esc_js( __( 'Connected successfully!', 'nofraud-woocommerce' ) ); ?>' +
								' (' + modeLabel + ')</span>';
						} else {
							result.innerHTML = '<span style="color:#dc3232;">&#10005; ' +
								(resp.data && resp.data.error ? resp.data.error : '<?php echo esc_js( __( 'Connection failed.', 'nofraud-woocommerce' ) ); ?>') +
								'</span>';
						}
					})
					.catch(function() {
						btn.disabled = false;
						result.innerHTML = '<span style="color:#dc3232;">&#10005; <?php echo esc_js( __( 'Request failed. Please try again.', 'nofraud-woocommerce' ) ); ?></span>';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler for testing API connection.
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'nofraud_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'error' => 'Unauthorized.' ], 403 );
		}

		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$mode    = sanitize_text_field( wp_unslash( $_POST['mode'] ?? 'test' ) );

		if ( ! in_array( $mode, [ 'test', 'live' ], true ) ) {
			$mode = 'test';
		}

		$result = NoFraud_API::test_connection( $api_key, $mode );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( [ 'mode' => $result['mode'] ] );
		} else {
			// Sanitize error to avoid leaking internal network details (DNS, proxy errors).
			$error = $result['error'] ?? '';
			if ( str_contains( $error, 'API key' ) || str_contains( $error, '403' ) ) {
				$safe_error = $error;
			} else {
				$safe_error = __( 'Connection failed. Please check your API key and mode, then try again.', 'nofraud-woocommerce' );
			}
			wp_send_json_error( [ 'error' => $safe_error ] );
		}
	}

	/**
	 * Save settings.
	 */
	public static function save_settings(): void {
		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Get the settings fields definition.
	 */
	public static function get_settings(): array {
		return [
			'section_title' => [
				'name' => __( 'NoFraud Fraud Protection', 'nofraud-woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Configure your NoFraud integration for credit card fraud detection.', 'nofraud-woocommerce' ),
				'id'   => 'nofraud_wc_section_title',
			],
			'enabled' => [
				'name'    => __( 'Enable/Disable', 'nofraud-woocommerce' ),
				'type'    => 'checkbox',
				'desc'    => __( 'Enable NoFraud fraud screening', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_enabled',
				'default' => 'no',
			],
			'mode' => [
				'name'    => __( 'Mode', 'nofraud-woocommerce' ),
				'type'    => 'select',
				'desc'    => __( 'Use Test mode while developing. Switch to Live for production.', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_mode',
				'options' => [
					'test' => __( 'Test (Sandbox)', 'nofraud-woocommerce' ),
					'live' => __( 'Live (Production)', 'nofraud-woocommerce' ),
				],
				'default' => 'test',
			],
			'api_key' => [
				'name'     => __( 'API Key', 'nofraud-woocommerce' ),
				'type'     => 'text',
				'desc'     => __( 'Your NoFraud API key from the Integrations page in the NoFraud Portal.', 'nofraud-woocommerce' ),
				'id'       => 'nofraud_wc_api_key',
				'default'  => '',
				'css'      => 'min-width: 400px;',
			],
			'device_js_code' => [
				'name'    => __( 'Device JS Account Code', 'nofraud-woocommerce' ),
				'type'    => 'text',
				'desc'    => __( 'Your account code for the NoFraud Device JavaScript tag. Found on your Integrations page.', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_device_js_code',
				'default' => '',
				'css'     => 'min-width: 300px;',
			],
			'webhook_secret' => [
				'name'    => __( 'Webhook Secret', 'nofraud-woocommerce' ),
				'type'    => 'text',
				'desc'    => __( 'Optional shared secret for webhook verification. If set, NoFraud must send this value in the X-NoFraud-Secret header.', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_webhook_secret',
				'default' => '',
				'css'     => 'min-width: 300px;',
			],
			'fail_action' => [
				'name'    => __( 'On Fail Decision', 'nofraud-woocommerce' ),
				'type'    => 'select',
				'desc'    => __( 'What to do when NoFraud returns a "fail" decision.', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_fail_action',
				'options' => [
					'cancel' => __( 'Cancel the order', 'nofraud-woocommerce' ),
					'hold'   => __( 'Put on hold for manual review', 'nofraud-woocommerce' ),
				],
				'default' => 'cancel',
			],
			'review_action' => [
				'name'    => __( 'On Review Decision', 'nofraud-woocommerce' ),
				'type'    => 'select',
				'desc'    => __( 'What to do when NoFraud returns a "review" decision.', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_review_action',
				'options' => [
					'hold'    => __( 'Put on hold', 'nofraud-woocommerce' ),
					'nothing' => __( 'Do nothing (process normally)', 'nofraud-woocommerce' ),
				],
				'default' => 'hold',
			],
			'debug_logging' => [
				'name'    => __( 'Debug Logging', 'nofraud-woocommerce' ),
				'type'    => 'checkbox',
				'desc'    => __( 'Log API requests and responses to WooCommerce > Status > Logs', 'nofraud-woocommerce' ),
				'id'      => 'nofraud_wc_debug_logging',
				'default' => 'no',
			],
			'section_end' => [
				'type' => 'sectionend',
				'id'   => 'nofraud_wc_section_end',
			],
		];
	}

	/**
	 * Add settings link to the plugin list.
	 */
	public static function plugin_action_links( array $links ): array {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=nofraud' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'nofraud-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Check if NoFraud screening is enabled.
	 */
	public static function is_enabled(): bool {
		return 'yes' === get_option( 'nofraud_wc_enabled', 'no' );
	}

	public static function get_fail_action(): string {
		return get_option( 'nofraud_wc_fail_action', 'cancel' );
	}

	public static function get_review_action(): string {
		return get_option( 'nofraud_wc_review_action', 'hold' );
	}

	public static function is_fail_decision( string $decision ): bool {
		return in_array( $decision, [ 'fail', 'fraudulent' ], true );
	}

	/**
	 * Apply a fail/fraudulent decision to an order.
	 * Shared by order handler, webhook handler, and checkout interceptor.
	 */
	public static function apply_fail_decision( \WC_Order $order, string $decision, string $note ): void {
		if ( 'cancel' === self::get_fail_action() ) {
			$order->update_status( 'cancelled', $note );
		} else {
			$order->update_status( 'on-hold', $note );
		}
	}

	/**
	 * Log a message if debug logging is enabled.
	 */
	public static function log( string $message, string $level = 'info' ): void {
		static $debug_enabled = null;
		if ( null === $debug_enabled ) {
			$debug_enabled = 'yes' === get_option( 'nofraud_wc_debug_logging', 'no' );
		}
		if ( ! $debug_enabled ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log( $level, $message, [ 'source' => 'nofraud-woocommerce' ] );
	}
}
