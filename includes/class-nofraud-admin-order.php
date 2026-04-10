<?php
/**
 * NoFraud admin order UI.
 *
 * Adds a meta box on the order edit screen showing the NoFraud screening result,
 * and adds a NoFraud Status column to the orders list.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Admin_Order {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );

		$hpos_enabled = self::is_hpos_enabled();

		if ( $hpos_enabled ) {
			add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_order_column' ] );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_order_column_hpos' ], 10, 2 );
		} else {
			add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_order_column' ] );
			add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_order_column' ], 10, 2 );
		}

		add_action( 'admin_head', [ __CLASS__, 'admin_styles' ] );
	}

	private static function is_hpos_enabled(): bool {
		static $enabled = null;
		if ( null !== $enabled ) {
			return $enabled;
		}
		if ( ! class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
			$enabled = false;
			return false;
		}
		$enabled = wc_get_container()
			->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			->custom_orders_table_usage_is_enabled();
		return $enabled;
	}

	public static function add_meta_box(): void {
		$screen = self::is_hpos_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'nofraud-woocommerce',
			__( 'NoFraud Fraud Screening', 'nofraud-woocommerce' ),
			[ __CLASS__, 'render_meta_box' ],
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order
	 */
	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'nofraud-woocommerce' ) . '</p>';
			return;
		}

		$transaction_id = $order->get_meta( NoFraud_Settings::META_TRANSACTION_ID );
		$decision       = $order->get_meta( NoFraud_Settings::META_DECISION );
		$screened_at    = $order->get_meta( NoFraud_Settings::META_SCREENED_AT );
		$message        = $order->get_meta( NoFraud_Settings::META_MESSAGE );

		if ( ! $transaction_id ) {
			echo '<p>' . esc_html__( 'This order has not been screened by NoFraud.', 'nofraud-woocommerce' ) . '</p>';
			return;
		}

		$portal_url = 'https://portal.nofraud.com/transaction/' . urlencode( $transaction_id );
		$badge      = self::decision_badge( $decision );
		?>
		<div class="nofraud-meta-box">
			<p>
				<strong><?php esc_html_e( 'Decision:', 'nofraud-woocommerce' ); ?></strong>
				<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in decision_badge(). ?>
			</p>
			<?php if ( $message ) : ?>
				<p>
					<strong><?php esc_html_e( 'Message:', 'nofraud-woocommerce' ); ?></strong>
					<?php echo esc_html( $message ); ?>
				</p>
			<?php endif; ?>
			<p>
				<strong><?php esc_html_e( 'Transaction ID:', 'nofraud-woocommerce' ); ?></strong><br>
				<code style="font-size: 11px;"><?php echo esc_html( $transaction_id ); ?></code>
			</p>
			<?php if ( $screened_at ) : ?>
				<p>
					<strong><?php esc_html_e( 'Screened:', 'nofraud-woocommerce' ); ?></strong>
					<?php echo esc_html( $screened_at ); ?>
				</p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener noreferrer" class="button">
					<?php esc_html_e( 'View in NoFraud Portal', 'nofraud-woocommerce' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}

	public static function add_order_column( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['nofraud_decision'] = __( 'NoFraud', 'nofraud-woocommerce' );
			}
		}
		return $new_columns;
	}

	public static function render_order_column( string $column, int $post_id ): void {
		if ( 'nofraud_decision' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			self::output_column_badge( $order );
		}
	}

	public static function render_order_column_hpos( string $column, \WC_Order $order ): void {
		if ( 'nofraud_decision' !== $column ) {
			return;
		}
		self::output_column_badge( $order );
	}

	private static function output_column_badge( \WC_Order $order ): void {
		$decision = $order->get_meta( NoFraud_Settings::META_DECISION );
		if ( $decision ) {
			echo self::decision_badge( $decision ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<span class="nofraud-badge nofraud-badge--none">&mdash;</span>';
		}
	}

	private static function decision_badge( string $decision ): string {
		$labels = [
			'pass'       => __( 'Pass', 'nofraud-woocommerce' ),
			'fail'       => __( 'Fail', 'nofraud-woocommerce' ),
			'review'     => __( 'Review', 'nofraud-woocommerce' ),
			'fraudulent' => __( 'Fraudulent', 'nofraud-woocommerce' ),
			'error'      => __( 'Error', 'nofraud-woocommerce' ),
		];

		$label = $labels[ $decision ] ?? ucfirst( $decision );
		$class = 'nofraud-badge nofraud-badge--' . esc_attr( $decision );

		return '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
	}

	public static function admin_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$order_screens = [ 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $screen->id, $order_screens, true ) ) {
			return;
		}

		?>
		<style>
			.nofraud-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.5;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			.nofraud-badge--pass {
				background: #d4edda;
				color: #155724;
			}
			.nofraud-badge--fail,
			.nofraud-badge--fraudulent {
				background: #f8d7da;
				color: #721c24;
			}
			.nofraud-badge--review {
				background: #fff3cd;
				color: #856404;
			}
			.nofraud-badge--error {
				background: #e2e3e5;
				color: #383d41;
			}
			.nofraud-badge--none {
				color: #999;
			}
			.nofraud-meta-box p {
				margin: 8px 0;
			}
		</style>
		<?php
	}
}
