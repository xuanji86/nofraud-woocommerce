<?php
/**
 * Payroc Gateway compatibility layer.
 *
 * The Payroc WooCommerce plugin extracts AVS, CVV, and approval code from its
 * XML gateway response into local variables but never persists them to order meta.
 * Card last4 and type are only stored on payment tokens, not on orders.
 *
 * This class hooks into the HTTP API to intercept Payroc XML responses and store
 * the missing data as order meta, making it available for NoFraud screening.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_Payroc {

	private const META_AVS       = '_payroc_avs_response';
	private const META_CVV       = '_payroc_cvv_response';
	private const META_AUTH_CODE = '_payroc_approval_code';
	private const META_LAST4     = '_payroc_card_last4';
	private const META_CARD_TYPE = '_payroc_card_type';

	private const GATEWAY_IDS = [ 'payroc', 'payroc_gateway' ];

	public static function init(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'http_response', [ __CLASS__, 'capture_payroc_response' ], 10, 3 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'capture_token_card_data' ], 5, 1 );
	}

	private static function is_active(): bool {
		return class_exists( 'PayrocGatewayXmlAuthResponse' ) || defined( 'PAYROC_GATEWAY_VERSION' );
	}

	/**
	 * Intercept HTTP responses from Payroc's XML API endpoints and parse
	 * AVS, CVV, and approval code from the XML body.
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

		$unique_ref = $parsed['unique_ref'] ?? '';
		if ( $unique_ref ) {
			set_transient( 'nofraud_payroc_' . $unique_ref, $parsed, 300 );
		}

		return $response;
	}

	/**
	 * After payment completes, attach captured gateway response data
	 * and payment token card data to the order.
	 */
	public static function capture_token_card_data( int $order_id ): void {
		if ( ! NoFraud_Settings::is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), self::GATEWAY_IDS, true ) ) {
			return;
		}

		$txn_id = $order->get_transaction_id();
		if ( $txn_id ) {
			$cached = get_transient( 'nofraud_payroc_' . $txn_id );
			if ( is_array( $cached ) ) {
				if ( ! empty( $cached['avs'] ) ) {
					$order->update_meta_data( self::META_AVS, sanitize_text_field( $cached['avs'] ) );
				}
				if ( ! empty( $cached['cvv'] ) ) {
					$order->update_meta_data( self::META_CVV, sanitize_text_field( $cached['cvv'] ) );
				}
				if ( ! empty( $cached['approval_code'] ) ) {
					$order->update_meta_data( self::META_AUTH_CODE, sanitize_text_field( $cached['approval_code'] ) );
				}
				delete_transient( 'nofraud_payroc_' . $txn_id );
			}
		}

		// Extract card data from payment token via WooCommerce API.
		$tokens = \WC_Payment_Tokens::get_order_tokens( $order->get_id() );
		foreach ( $tokens as $token ) {
			if ( $token instanceof \WC_Payment_Token_CC ) {
				$last4 = $token->get_last4();
				if ( $last4 ) {
					$order->update_meta_data( self::META_LAST4, sanitize_text_field( $last4 ) );
				}
				$card_type = $token->get_card_type();
				if ( $card_type ) {
					$order->update_meta_data( self::META_CARD_TYPE, sanitize_text_field( $card_type ) );
				}
				break; // Use first CC token.
			}
		}

		$order->save();
	}

	private static function is_payroc_url( string $url ): bool {
		static $payroc_hosts = [
			'payments.globalone.me',
			'payments.sandbox.globalone.me',
			'testpayments.globalone.me',
			'api.payroc.com',
			'test.payroc.com',
		];

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
