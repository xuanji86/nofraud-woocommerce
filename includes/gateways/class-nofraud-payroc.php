<?php
/**
 * Payroc Gateway compatibility layer.
 *
 * The Payroc WooCommerce plugin extracts AVS, CVV, and approval code from its
 * XML gateway response into local variables but never persists them to order meta.
 * Card last4 and type are only stored on payment tokens, not on orders.
 *
 * This class hooks into the HTTP API to intercept Payroc XML requests/responses
 * and store the missing data as order meta, making it available for NoFraud screening.
 *
 * Timing note: Payroc calls update_status('processing') BEFORE set_transaction_id(),
 * so the captured data is passed via a static variable within the same PHP request
 * rather than relying solely on transients keyed by transaction ID.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Payroc {

	private const META_AVS        = '_payroc_avs_response';
	private const META_CVV        = '_payroc_cvv_response';
	private const META_AUTH_CODE  = '_payroc_approval_code';
	private const META_LAST4      = '_payroc_card_last4';
	private const META_CARD_TYPE  = '_payroc_card_type';
	private const META_BIN        = '_payroc_card_bin';
	private const META_EXPIRY     = '_payroc_card_expiry';
	private const META_CAPTURED   = '_nofraud_payroc_captured';

	/** Maps captured-data array keys to their order meta constants. */
	private const CACHED_FIELD_MAP = [
		'avs'           => self::META_AVS,
		'cvv'           => self::META_CVV,
		'approval_code' => self::META_AUTH_CODE,
		'last4'         => self::META_LAST4,
		'card_type'     => self::META_CARD_TYPE,
		'bin'           => self::META_BIN,
		'expiry'        => self::META_EXPIRY,
	];

	private const GATEWAY_IDS = [ 'payroc', 'payroc_gateway' ];

	/**
	 * Captured data from the most recent Payroc payment round-trip within this
	 * PHP request. Consumed by capture_token_card_data() and cleared afterward.
	 */
	private static array $last_captured = [];

	public static function init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'http_response', [ __CLASS__, 'capture_payroc_response' ], 10, 3 );

		// Payroc's process_payment() never calls $order->payment_complete(); it transitions the
		// order directly to 'processing' via update_status(). Use transition-specific hooks so
		// capture only runs for genuine payment events, not for admin marking orders complete.
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'capture_token_card_data' ], 5, 1 );
		foreach ( [ 'pending', 'on-hold', 'failed' ] as $from ) {
			add_action( "woocommerce_order_status_{$from}_to_processing", [ __CLASS__, 'capture_token_card_data' ], 5, 1 );
			add_action( "woocommerce_order_status_{$from}_to_completed",  [ __CLASS__, 'capture_token_card_data' ], 5, 1 );
		}
	}

	private static function is_active(): bool {
		return class_exists( 'PayrocGatewayXmlAuthResponse' ) || defined( 'PAYROC_GATEWAY_VERSION' );
	}

	/**
	 * Intercept HTTP responses from Payroc's XML API endpoints.
	 * Parse AVS, CVV, approval code from the response, and card last4/BIN/type/expiry
	 * from the request body. Store everything in a static var and a transient.
	 *
	 * @return array Unmodified response (passthrough).
	 */
	public static function capture_payroc_response( array $response, array $parsed_args, string $url ): array {
		if ( ! self::is_payroc_url( $url ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $response;
		}

		$parsed = self::parse_xml_response( $body );
		if ( empty( $parsed ) ) {
			return $response;
		}

		// Only parse the request body for payment transactions (indicated by a
		// UNIQUEREF in the response). Skips refunds, token registrations, etc.
		if ( ! empty( $parsed['unique_ref'] ) ) {
			$request_body = $parsed_args['body'] ?? '';
			if ( is_string( $request_body ) && $request_body !== '' ) {
				$parsed = array_merge( $parsed, self::parse_xml_request_card( $request_body ) );
			}
		}

		self::$last_captured = $parsed;

		$unique_ref = $parsed['unique_ref'] ?? '';
		if ( $unique_ref ) {
			set_transient( 'nofraud_payroc_' . $unique_ref, $parsed, 300 );
		}

		return $response;
	}

	/**
	 * After payment completes, attach captured gateway response data
	 * and card data to the order.
	 */
	public static function capture_token_card_data( int $order_id ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			self::$last_captured = [];
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), self::GATEWAY_IDS, true ) ) {
			self::$last_captured = [];
			return;
		}

		// Idempotency: status-transition hooks can fire this multiple times per order.
		if ( $order->get_meta( self::META_CAPTURED ) ) {
			self::$last_captured = [];
			return;
		}

		// Try transient first (keyed by transaction_id), then fall back to the
		// in-memory static var captured during the same PHP request.
		$cached = null;
		$txn_id = $order->get_transaction_id();
		if ( $txn_id ) {
			$cached = get_transient( 'nofraud_payroc_' . $txn_id );
			if ( is_array( $cached ) ) {
				delete_transient( 'nofraud_payroc_' . $txn_id );
			}
		}
		if ( ! is_array( $cached ) && ! empty( self::$last_captured ) ) {
			$cached = self::$last_captured;
		}
		self::$last_captured = [];

		$dirty = false;

		if ( is_array( $cached ) ) {
			foreach ( self::CACHED_FIELD_MAP as $cache_key => $meta_key ) {
				if ( ! empty( $cached[ $cache_key ] ) ) {
					$order->update_meta_data( $meta_key, sanitize_text_field( $cached[ $cache_key ] ) );
					$dirty = true;
				}
			}
		}

		// Fallback: extract card data from payment token (works when customer
		// used a saved card, since Payroc only creates tokens in that case).
		if ( ! $order->get_meta( self::META_LAST4 ) ) {
			$tokens = \WC_Payment_Tokens::get_order_tokens( $order->get_id() );
			foreach ( $tokens as $token ) {
				if ( $token instanceof \WC_Payment_Token_CC ) {
					$last4 = $token->get_last4();
					if ( $last4 ) {
						$order->update_meta_data( self::META_LAST4, sanitize_text_field( $last4 ) );
						$dirty = true;
					}
					$card_type = $token->get_card_type();
					if ( $card_type ) {
						$order->update_meta_data( self::META_CARD_TYPE, sanitize_text_field( $card_type ) );
						$dirty = true;
					}
					break;
				}
			}
		}

		if ( $dirty ) {
			$order->update_meta_data( self::META_CAPTURED, '1' );
			$order->save();
		}
	}

	private static function is_payroc_url( string $url ): bool {
		// Payroc 2.7.9.x posts XML to payments.payroc.com / payments.uat.payroc.com;
		// older builds used the GlobalOne hosts. Match the registrable domains.
		static $payroc_hosts = [ 'payroc.com', 'globalone.me' ];

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		foreach ( $payroc_hosts as $payroc_host ) {
			if ( $host === $payroc_host || str_ends_with( $host, '.' . $payroc_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{unique_ref?: string, avs?: string, cvv?: string, approval_code?: string}
	 */
	private static function parse_xml_response( string $xml_body ): array {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_body );
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return [];
		}

		$result    = [];
		$field_map = [
			'UNIQUEREF'    => 'unique_ref',
			'AVSRESPONSE'  => 'avs',
			'CVVRESPONSE'  => 'cvv',
			'APPROVALCODE' => 'approval_code',
		];

		foreach ( $field_map as $xml_field => $key ) {
			$value = self::get_xml_value( $xml, $xml_field );
			if ( $value !== '' ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Extract card last4, BIN, type, and expiry from the Payroc XML request body.
	 * Only last4 (last 4 digits) and BIN (first 6 digits) are retained from the
	 * card number — the full PAN is never stored.
	 *
	 * @return array{last4?: string, bin?: string, card_type?: string, expiry?: string}
	 */
	private static function parse_xml_request_card( string $xml_body ): array {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_body );
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return [];
		}

		$result = [];

		$card_number = self::get_xml_value( $xml, 'CARDNUMBER' );
		if ( strlen( $card_number ) >= 4 ) {
			$result['last4'] = substr( $card_number, -4 );
		}
		if ( strlen( $card_number ) >= 6 ) {
			$result['bin'] = substr( $card_number, 0, 6 );
		}

		$card_type = self::get_xml_value( $xml, 'CARDTYPE' );
		if ( $card_type !== '' && $card_type !== 'SECURECARD' ) {
			$result['card_type'] = strtolower( $card_type );
		}

		// Payroc sends CARDEXPIRY as MMYY — convert to MM/YYYY for NoFraud.
		$card_expiry = self::get_xml_value( $xml, 'CARDEXPIRY' );
		if ( strlen( $card_expiry ) === 4 ) {
			$result['expiry'] = substr( $card_expiry, 0, 2 ) . '/20' . substr( $card_expiry, 2 );
		}

		return $result;
	}

	/**
	 * Try uppercase, lowercase, and XPath to find the field value.
	 */
	private static function get_xml_value( \SimpleXMLElement $xml, string $field ): string {
		if ( isset( $xml->{$field} ) ) {
			return (string) $xml->{$field};
		}

		$lower = strtolower( $field );
		if ( isset( $xml->{$lower} ) ) {
			return (string) $xml->{$lower};
		}

		$nodes = $xml->xpath( '//' . $field );
		if ( ! empty( $nodes ) ) {
			return (string) $nodes[0];
		}

		return '';
	}
}
