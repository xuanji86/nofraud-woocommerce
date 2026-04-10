<?php
/**
 * NoFraud API client.
 *
 * Handles all HTTP communication with the NoFraud Transaction API.
 */

defined( 'ABSPATH' ) || exit;

class NoFraud_API {

	private const LIVE_URL = 'https://api.nofraud.com';
	private const TEST_URL = 'https://apitest.nofraud.com';

	private static function get_base_url( string $mode = '' ): string {
		if ( ! $mode ) {
			$mode = get_option( 'nofraud_wc_mode', 'test' );
		}
		return 'live' === $mode ? self::LIVE_URL : self::TEST_URL;
	}

	/**
	 * Get and validate the API token, returning an error array if missing.
	 *
	 * @return array{0: string, 1: null}|array{0: null, 1: array} [token, null] or [null, error].
	 */
	private static function require_token(): array {
		$token = get_option( 'nofraud_wc_api_key', '' );
		if ( empty( $token ) ) {
			return [ null, [ 'success' => false, 'error' => 'NoFraud API key is not configured.' ] ];
		}
		return [ $token, null ];
	}

	/**
	 * Create a transaction for fraud screening.
	 *
	 * @param array $data Transaction data (without nf-token).
	 * @return array{success: bool, decision?: string, id?: string, message?: string, error?: string}
	 */
	public static function create_transaction( array $data ): array {
		[ $token, $error ] = self::require_token();
		if ( $error ) {
			return $error;
		}

		$data['nf-token'] = $token;

		$response = wp_remote_post(
			self::get_base_url() . '/',
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $data ),
			]
		);

		return self::parse_response( $response );
	}

	/**
	 * Send gateway response update (for pre-gateway workflow).
	 *
	 * @param string $transaction_id NoFraud transaction ID.
	 * @param string $result         Gateway result: pass, fail, error.
	 * @param string $authcode       Gateway authorization code.
	 * @param string $gateway_txn_id Gateway transaction ID.
	 * @return array{success: bool, error?: string}
	 */
	public static function update_gateway_response( string $transaction_id, string $result, string $authcode = '', string $gateway_txn_id = '' ): array {
		[ $token, $error ] = self::require_token();
		if ( $error ) {
			return $error;
		}

		$body = [
			'nf-token'         => $token,
			'nf-id'            => $transaction_id,
			'gateway-response' => [
				'result' => $result,
			],
		];

		if ( $authcode ) {
			$body['gateway-response']['authcode'] = $authcode;
		}
		if ( $gateway_txn_id ) {
			$body['gateway-response']['transaction-id'] = $gateway_txn_id;
		}

		$response = wp_remote_post(
			self::get_base_url() . '/gateway_response',
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		return self::parse_response( $response );
	}

	/**
	 * Get transaction status by NoFraud transaction ID or invoice number.
	 *
	 * @param string $identifier Transaction ID or invoice number.
	 * @return array{success: bool, decision?: string, id?: string, error?: string}
	 */
	public static function get_transaction_status( string $identifier ): array {
		[ $token, $error ] = self::require_token();
		if ( $error ) {
			return $error;
		}

		$url      = self::get_base_url() . '/status/' . urlencode( $token ) . '/' . urlencode( $identifier );
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		return self::parse_response( $response );
	}

	/**
	 * Test the API connection using the given key and mode.
	 * Calls the status endpoint with a dummy ID: 403 = invalid key, 404 = valid key.
	 *
	 * @param string $api_key API key to test.
	 * @param string $mode    'test' or 'live'.
	 * @return array{success: bool, error?: string, mode?: string}
	 */
	/**
	 * Test API connection. Uses status endpoint with a dummy ID:
	 * 403 = invalid key, 404 or 200 = valid key.
	 */
	public static function test_connection( string $api_key, string $mode ): array {
		if ( empty( $api_key ) ) {
			return [ 'success' => false, 'error' => 'API key is empty.' ];
		}

		$url      = self::get_base_url( $mode ) . '/status/' . urlencode( $api_key ) . '/test-connection';
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, [ 200, 404 ], true ) ) {
			return [ 'success' => true, 'mode' => $mode ];
		}

		if ( 403 === $code ) {
			return [ 'success' => false, 'error' => 'Invalid API key (403 Not Authorized).' ];
		}

		return [ 'success' => false, 'error' => 'Unexpected response (HTTP ' . $code . ').' ];
	}

	/**
	 * @param array|\WP_Error $response
	 */
	private static function parse_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error = 'NoFraud API error (HTTP ' . $code . ')';
			if ( ! empty( $body['Errors'] ) ) {
				$error .= ': ' . implode( '; ', (array) $body['Errors'] );
			}
			return [ 'success' => false, 'error' => $error ];
		}

		if ( ! is_array( $body ) ) {
			return [ 'success' => false, 'error' => 'Invalid JSON response from NoFraud.' ];
		}

		$body['success'] = true;
		return $body;
	}
}
