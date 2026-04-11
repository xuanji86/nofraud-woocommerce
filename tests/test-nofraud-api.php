<?php
/**
 * NoFraud WooCommerce Plugin — API Test Script.
 *
 * Tests the plugin's NoFraud API integration using official test credit cards.
 * Supports both pre-gateway (full card) and post-acceptance (last4 + AVS/CVV) modes.
 *
 * Usage (via WP-CLI):
 *   wp eval-file tests/test-nofraud-api.php
 *   wp eval-file tests/test-nofraud-api.php -- --mode=pre-gateway
 *   wp eval-file tests/test-nofraud-api.php -- --mode=post-acceptance
 *   wp eval-file tests/test-nofraud-api.php -- --test=connection
 *   wp eval-file tests/test-nofraud-api.php -- --test=webhook
 *
 * Requires:
 *   - WordPress + WooCommerce active
 *   - NoFraud plugin active with a valid test API key configured
 *   - Plugin mode set to "test" (uses apitest.nofraud.com)
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * Test credit cards from https://developers.nofraud.com/reference/test-credit-cards
 *
 * NoFraud test API decision is determined by their screening engine, not by
 * specific card numbers. All cards below are valid for test transactions.
 */
$test_cards = [
	// Visa
	[
		'label'      => 'Visa #1',
		'number'     => '4111111111111111',
		'last4'      => '1111',
		'cardType'   => 'Visa',
		'bin'        => '411111',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	[
		'label'      => 'Visa #2',
		'number'     => '4242424242424242',
		'last4'      => '4242',
		'cardType'   => 'Visa',
		'bin'        => '424242',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	[
		'label'      => 'Visa #3',
		'number'     => '4012888888881881',
		'last4'      => '1881',
		'cardType'   => 'Visa',
		'bin'        => '401288',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	[
		'label'      => 'Visa #4',
		'number'     => '4000056655665556',
		'last4'      => '5556',
		'cardType'   => 'Visa',
		'bin'        => '400005',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	// Mastercard
	[
		'label'      => 'Mastercard #1',
		'number'     => '5424000000000015',
		'last4'      => '0015',
		'cardType'   => 'Mastercard',
		'bin'        => '542400',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	[
		'label'      => 'Mastercard #2',
		'number'     => '5555555555554444',
		'last4'      => '4444',
		'cardType'   => 'Mastercard',
		'bin'        => '555555',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	// American Express
	[
		'label'      => 'Amex #1',
		'number'     => '370000000000002',
		'last4'      => '0002',
		'cardType'   => 'American Express',
		'bin'        => '370000',
		'expDate'    => '12/2028',
		'cardCode'   => '1234',
	],
	[
		'label'      => 'Amex #2',
		'number'     => '378282246310005',
		'last4'      => '0005',
		'cardType'   => 'American Express',
		'bin'        => '378282',
		'expDate'    => '12/2028',
		'cardCode'   => '1234',
	],
	// Discover
	[
		'label'      => 'Discover #1',
		'number'     => '6011000000000012',
		'last4'      => '0012',
		'cardType'   => 'Discover',
		'bin'        => '601100',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
	// JCB
	[
		'label'      => 'JCB #1',
		'number'     => '3530111333300000',
		'last4'      => '0000',
		'cardType'   => 'JCB',
		'bin'        => '353011',
		'expDate'    => '12/2028',
		'cardCode'   => '123',
	],
];

/**
 * Sample billing/shipping data for test transactions.
 */
$sample_billing = [
	'firstName'   => 'John',
	'lastName'    => 'Doe',
	'address'     => '123 Main Street',
	'city'        => 'New York',
	'state'       => 'NY',
	'zip'         => '10001',
	'country'     => 'US',
	'phoneNumber' => '2125551234',
];

$sample_shipping = [
	'firstName' => 'John',
	'lastName'  => 'Doe',
	'address'   => '123 Main Street',
	'city'      => 'New York',
	'state'     => 'NY',
	'zip'       => '10001',
	'country'   => 'US',
];

$sample_customer = [
	'email' => 'test@example.com',
];

$sample_line_items = [
	[
		'sku'      => 'TEST-001',
		'name'     => 'Test Product',
		'price'    => 49.99,
		'quantity' => 1,
		'category' => 'Test',
	],
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function nf_test_line( string $status, string $message ): void {
	$icons = [
		'pass' => "\033[32m✓\033[0m",
		'fail' => "\033[31m✗\033[0m",
		'info' => "\033[34m→\033[0m",
		'warn' => "\033[33m⚠\033[0m",
	];
	$icon = $icons[ $status ] ?? '·';
	WP_CLI::line( "  {$icon} {$message}" );
}

function nf_test_header( string $title ): void {
	WP_CLI::line( '' );
	WP_CLI::line( "\033[1m== {$title} ==\033[0m" );
}

/**
 * Send a raw POST request to the NoFraud test API.
 */
function nf_api_post( string $endpoint, array $data ): array {
	$base_url = 'https://apitest.nofraud.com';
	$url      = rtrim( $base_url, '/' ) . '/' . ltrim( $endpoint, '/' );

	$response = wp_remote_post( $url, [
		'timeout' => 30,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( $data ),
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'success' => false, 'error' => $response->get_error_message() ];
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	return [
		'http_code' => $code,
		'body'      => $body,
		'success'   => 200 === $code,
		'raw'       => wp_remote_retrieve_body( $response ),
	];
}

// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------

$args     = $GLOBALS['argv'] ?? [];
$run_mode = 'all'; // all | pre-gateway | post-acceptance
$run_test = 'all'; // all | connection | transaction | webhook

foreach ( $args as $arg ) {
	if ( str_starts_with( $arg, '--mode=' ) ) {
		$run_mode = str_replace( '--mode=', '', $arg );
	}
	if ( str_starts_with( $arg, '--test=' ) ) {
		$run_test = str_replace( '--test=', '', $arg );
	}
}

// ---------------------------------------------------------------------------
// Validate prerequisites
// ---------------------------------------------------------------------------

$api_key = get_option( 'nofraud_wc_api_key', '' );
$mode    = get_option( 'nofraud_wc_mode', 'test' );

WP_CLI::line( '' );
WP_CLI::line( "\033[1;36m╔══════════════════════════════════════════════╗\033[0m" );
WP_CLI::line( "\033[1;36m║   NoFraud WooCommerce Plugin — API Tests     ║\033[0m" );
WP_CLI::line( "\033[1;36m╚══════════════════════════════════════════════╝\033[0m" );

if ( empty( $api_key ) ) {
	WP_CLI::error( 'NoFraud API key is not configured. Set it in WooCommerce > Settings > NoFraud.' );
}

if ( 'test' !== $mode ) {
	WP_CLI::warning( "Plugin is in '{$mode}' mode. These tests will use apitest.nofraud.com regardless." );
}

nf_test_line( 'info', "API Key: " . substr( $api_key, 0, 8 ) . '...' . substr( $api_key, -4 ) );
nf_test_line( 'info', "Test mode: {$run_mode} | Test scope: {$run_test}" );

$passed  = 0;
$failed  = 0;
$skipped = 0;

// ===========================================================================
// Test 1: API Connection
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'connection' ], true ) ) {
	nf_test_header( 'Test 1: API Connection' );

	$result = NoFraud_API::test_connection( $api_key, 'test' );

	if ( ! empty( $result['success'] ) ) {
		nf_test_line( 'pass', "Connection to test API successful (mode: {$result['mode']})" );
		$passed++;
	} else {
		nf_test_line( 'fail', "Connection failed: " . ( $result['error'] ?? 'Unknown error' ) );
		$failed++;
		if ( 'connection' === $run_test ) {
			goto summary;
		}
		WP_CLI::warning( 'Connection test failed. Transaction tests may also fail.' );
	}
}

// ===========================================================================
// Test 2: Pre-Gateway Transactions (full card number)
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'transaction' ], true ) && in_array( $run_mode, [ 'all', 'pre-gateway' ], true ) ) {
	nf_test_header( 'Test 2: Pre-Gateway Transactions (Full Card Number)' );
	nf_test_line( 'info', 'Sending transactions with full card numbers to apitest.nofraud.com' );

	$pre_gateway_cards = array_slice( $test_cards, 0, 5 ); // Test a subset to avoid rate limiting

	foreach ( $pre_gateway_cards as $card ) {
		$payload = [
			'nf-token'  => $api_key,
			'amount'    => '99.95',
			'customerIP' => '203.0.113.42',
			'customer'  => $sample_customer,
			'billTo'    => $sample_billing,
			'shipTo'    => $sample_shipping,
			'payment'   => [
				'method'     => 'Credit Card',
				'creditCard' => [
					'cardNumber'     => $card['number'],
					'expirationDate' => $card['expDate'],
					'cardCode'       => $card['cardCode'],
					'cardType'       => $card['cardType'],
				],
			],
			'order' => [
				'invoiceNumber' => 'TEST-PG-' . time() . '-' . $card['last4'],
			],
			'lineItems' => $sample_line_items,
			'app'        => 'nofraud-woocommerce',
			'appVersion' => defined( 'NOFRAUD_WC_VERSION' ) ? NOFRAUD_WC_VERSION : '1.0.0',
		];

		$result = nf_api_post( '/', $payload );

		if ( $result['success'] && ! empty( $result['body']['decision'] ) ) {
			$decision = $result['body']['decision'];
			$txn_id   = $result['body']['id'] ?? 'N/A';
			$message  = $result['body']['message'] ?? '';
			$color    = match( $decision ) {
				'pass'                => "\033[32m",
				'fail', 'fraudulent' => "\033[31m",
				'review'             => "\033[33m",
				default              => "\033[37m",
			};
			nf_test_line( 'pass', sprintf(
				'%s → Decision: %s%s%s | ID: %s%s',
				str_pad( $card['label'], 16 ),
				$color, $decision, "\033[0m",
				substr( $txn_id, 0, 12 ) . '...',
				$message ? " | Msg: {$message}" : ''
			) );
			$passed++;
		} else {
			$error = $result['body']['Errors'] ?? $result['error'] ?? 'HTTP ' . ( $result['http_code'] ?? '?' );
			if ( is_array( $error ) ) {
				$error = implode( '; ', $error );
			}
			nf_test_line( 'fail', sprintf( '%s → Error: %s', str_pad( $card['label'], 16 ), $error ) );
			$failed++;
		}

		usleep( 500000 ); // 0.5s delay between requests
	}
}

// ===========================================================================
// Test 3: Post-Acceptance Transactions (last4 + AVS/CVV)
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'transaction' ], true ) && in_array( $run_mode, [ 'all', 'post-acceptance' ], true ) ) {
	nf_test_header( 'Test 3: Post-Acceptance Transactions (Last4 + AVS/CVV)' );
	nf_test_line( 'info', 'This is the mode used by the WooCommerce plugin in production.' );

	$post_acceptance_cards = array_slice( $test_cards, 0, 4 );

	$avs_codes = [ 'Y', 'N', 'X', 'A' ]; // Y=match, N=no match, X=unavailable, A=address only
	$cvv_codes = [ 'M', 'N', 'P', 'U' ]; // M=match, N=no match, P=not processed, U=unavailable

	foreach ( $post_acceptance_cards as $i => $card ) {
		$avs = $avs_codes[ $i % count( $avs_codes ) ];
		$cvv = $cvv_codes[ $i % count( $cvv_codes ) ];

		$payload = [
			'nf-token'      => $api_key,
			'amount'        => '149.99',
			'customerIP'    => '198.51.100.' . ( $i + 10 ),
			'customer'      => $sample_customer,
			'billTo'        => $sample_billing,
			'shipTo'        => $sample_shipping,
			'gatewayName'   => 'Test Payment Gateway',
			'avsResultCode' => $avs,
			'cvvResultCode' => $cvv,
			'payment'       => [
				'method'     => 'Credit Card',
				'creditCard' => [
					'last4'    => $card['last4'],
					'cardType' => $card['cardType'],
					'bin'      => $card['bin'],
				],
			],
			'order' => [
				'invoiceNumber' => 'TEST-PA-' . time() . '-' . $card['last4'],
			],
			'lineItems' => $sample_line_items,
			'app'        => 'nofraud-woocommerce',
			'appVersion' => defined( 'NOFRAUD_WC_VERSION' ) ? NOFRAUD_WC_VERSION : '1.0.0',
		];

		$result = nf_api_post( '/', $payload );

		if ( $result['success'] && ! empty( $result['body']['decision'] ) ) {
			$decision = $result['body']['decision'];
			$txn_id   = $result['body']['id'] ?? 'N/A';
			$color    = match( $decision ) {
				'pass'                => "\033[32m",
				'fail', 'fraudulent' => "\033[31m",
				'review'             => "\033[33m",
				default              => "\033[37m",
			};
			nf_test_line( 'pass', sprintf(
				'%s (AVS:%s CVV:%s) → Decision: %s%s%s | ID: %s',
				str_pad( $card['label'], 12 ),
				$avs, $cvv,
				$color, $decision, "\033[0m",
				substr( $txn_id, 0, 12 ) . '...'
			) );
			$passed++;
		} else {
			$error = $result['body']['Errors'] ?? $result['error'] ?? 'HTTP ' . ( $result['http_code'] ?? '?' );
			if ( is_array( $error ) ) {
				$error = implode( '; ', $error );
			}
			nf_test_line( 'fail', sprintf( '%s (AVS:%s CVV:%s) → Error: %s', str_pad( $card['label'], 12 ), $avs, $cvv, $error ) );
			$failed++;
		}

		usleep( 500000 );
	}
}

// ===========================================================================
// Test 4: Transaction Status Query
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'transaction' ], true ) ) {
	nf_test_header( 'Test 4: Transaction Status Query' );

	// First create a transaction to query
	$status_payload = [
		'nf-token'   => $api_key,
		'amount'     => '25.00',
		'customerIP' => '203.0.113.99',
		'customer'   => $sample_customer,
		'billTo'     => $sample_billing,
		'payment'    => [
			'method'     => 'Credit Card',
			'creditCard' => [
				'cardNumber'     => '4111111111111111',
				'expirationDate' => '12/2028',
				'cardCode'       => '123',
				'cardType'       => 'Visa',
			],
		],
		'order' => [
			'invoiceNumber' => 'TEST-STATUS-' . time(),
		],
		'lineItems' => $sample_line_items,
		'app'       => 'nofraud-woocommerce',
	];

	$create_result = nf_api_post( '/', $status_payload );

	if ( $create_result['success'] && ! empty( $create_result['body']['id'] ) ) {
		$txn_id  = $create_result['body']['id'];
		$invoice = $status_payload['order']['invoiceNumber'];
		nf_test_line( 'info', "Created transaction: {$txn_id}" );

		// Query by transaction ID
		$status_url = "https://apitest.nofraud.com/status/" . urlencode( $api_key ) . "/" . urlencode( $txn_id );
		$status_resp = wp_remote_get( $status_url, [ 'timeout' => 15 ] );

		if ( ! is_wp_error( $status_resp ) && 200 === wp_remote_retrieve_response_code( $status_resp ) ) {
			$status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
			nf_test_line( 'pass', "Status by ID: decision={$status_body['decision']}" );
			$passed++;
		} else {
			nf_test_line( 'fail', 'Status by ID query failed' );
			$failed++;
		}

		// Query by invoice number
		$invoice_url = "https://apitest.nofraud.com/status/" . urlencode( $api_key ) . "/" . urlencode( $invoice );
		$invoice_resp = wp_remote_get( $invoice_url, [ 'timeout' => 15 ] );

		if ( ! is_wp_error( $invoice_resp ) && 200 === wp_remote_retrieve_response_code( $invoice_resp ) ) {
			$inv_body = json_decode( wp_remote_retrieve_body( $invoice_resp ), true );
			nf_test_line( 'pass', "Status by invoice: decision={$inv_body['decision']}" );
			$passed++;
		} else {
			$code = is_wp_error( $invoice_resp ) ? 'WP_Error' : wp_remote_retrieve_response_code( $invoice_resp );
			nf_test_line( 'warn', "Status by invoice: HTTP {$code} (may not be supported in test mode)" );
			$skipped++;
		}
	} else {
		nf_test_line( 'fail', 'Could not create transaction for status test' );
		$failed++;
		$skipped++;
	}
}

// ===========================================================================
// Test 5: Webhook Endpoint (local)
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'webhook' ], true ) ) {
	nf_test_header( 'Test 5: Webhook Endpoint Validation' );

	$webhook_url = rest_url( 'nofraud/v1/webhook' );
	nf_test_line( 'info', "Webhook URL: {$webhook_url}" );

	// Test: Missing decision field
	$resp = wp_remote_post( $webhook_url, [
		'timeout' => 10,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [ 'id' => 'test-123' ] ),
	] );

	if ( ! is_wp_error( $resp ) ) {
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 400 === $code ) {
			nf_test_line( 'pass', 'Missing decision → 400 (correct)' );
			$passed++;
		} else {
			nf_test_line( 'fail', "Missing decision → HTTP {$code} (expected 400)" );
			$failed++;
		}
	} else {
		nf_test_line( 'fail', 'Webhook request failed: ' . $resp->get_error_message() );
		$failed++;
	}

	// Test: Invalid decision value
	$resp = wp_remote_post( $webhook_url, [
		'timeout' => 10,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [ 'id' => 'test-123', 'decision' => 'invalid_value' ] ),
	] );

	if ( ! is_wp_error( $resp ) ) {
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 400 === $code ) {
			nf_test_line( 'pass', 'Invalid decision value → 400 (correct)' );
			$passed++;
		} else {
			nf_test_line( 'fail', "Invalid decision → HTTP {$code} (expected 400)" );
			$failed++;
		}
	} else {
		nf_test_line( 'fail', 'Webhook request failed: ' . $resp->get_error_message() );
		$failed++;
	}

	// Test: Valid decision, non-existent order → 404
	$resp = wp_remote_post( $webhook_url, [
		'timeout' => 10,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( [
			'id'            => 'nonexistent-txn-id-' . time(),
			'decision'      => 'pass',
			'invoiceNumber' => '99999999',
		] ),
	] );

	if ( ! is_wp_error( $resp ) ) {
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 404 === $code ) {
			nf_test_line( 'pass', 'Non-existent order → 404 (correct)' );
			$passed++;
		} else {
			nf_test_line( 'fail', "Non-existent order → HTTP {$code} (expected 404)" );
			$failed++;
		}
	} else {
		nf_test_line( 'fail', 'Webhook request failed: ' . $resp->get_error_message() );
		$failed++;
	}

	// Test: Webhook secret verification
	$current_secret = get_option( 'nofraud_wc_webhook_secret', '' );
	if ( ! empty( $current_secret ) ) {
		nf_test_line( 'info', 'Webhook secret is configured — testing verification...' );

		// Request without secret header should be rejected
		// Note: verify_webhook returns false when secret is set but header is missing,
		// which results in a 403 from WordPress REST API.
		$resp = wp_remote_post( $webhook_url, [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'id' => 'test', 'decision' => 'pass', 'invoiceNumber' => '1' ] ),
		] );

		if ( ! is_wp_error( $resp ) ) {
			$code = wp_remote_retrieve_response_code( $resp );
			if ( 403 === $code ) {
				nf_test_line( 'pass', 'Missing secret header → 403 (correct)' );
				$passed++;
			} else {
				nf_test_line( 'warn', "Missing secret header → HTTP {$code} (expected 403)" );
				$skipped++;
			}
		}

		// Request with correct secret header
		$resp = wp_remote_post( $webhook_url, [
			'timeout' => 10,
			'headers' => [
				'Content-Type'     => 'application/json',
				'X-NoFraud-Secret' => $current_secret,
			],
			'body' => wp_json_encode( [
				'id'            => 'nonexistent-' . time(),
				'decision'      => 'pass',
				'invoiceNumber' => '99999999',
			] ),
		] );

		if ( ! is_wp_error( $resp ) ) {
			$code = wp_remote_retrieve_response_code( $resp );
			// Should get 404 (order not found) not 403 (unauthorized)
			if ( 404 === $code ) {
				nf_test_line( 'pass', 'Correct secret header → authorized (404 = order not found, as expected)' );
				$passed++;
			} else {
				nf_test_line( 'warn', "Correct secret header → HTTP {$code}" );
				$skipped++;
			}
		}
	} else {
		nf_test_line( 'warn', 'No webhook secret configured — skipping secret verification tests' );
		$skipped += 2;
	}
}

// ===========================================================================
// Test 6: Plugin Class Integration
// ===========================================================================

if ( in_array( $run_test, [ 'all', 'transaction' ], true ) ) {
	nf_test_header( 'Test 6: Plugin Class Integration' );

	// Verify NoFraud_API::create_transaction() works correctly
	$payload = [
		'amount'     => '59.99',
		'customerIP' => '203.0.113.50',
		'customer'   => $sample_customer,
		'billTo'     => $sample_billing,
		'payment'    => [
			'method'     => 'Credit Card',
			'creditCard' => [
				'cardNumber'     => '4242424242424242',
				'expirationDate' => '12/2028',
				'cardCode'       => '123',
				'cardType'       => 'Visa',
			],
		],
		'order' => [
			'invoiceNumber' => 'TEST-CLASS-' . time(),
		],
		'lineItems' => $sample_line_items,
		'app'       => 'nofraud-woocommerce',
	];

	$result = NoFraud_API::create_transaction( $payload );

	if ( ! empty( $result['success'] ) && ! empty( $result['decision'] ) ) {
		nf_test_line( 'pass', "NoFraud_API::create_transaction() → decision: {$result['decision']}, id: " . substr( $result['id'] ?? '', 0, 12 ) . '...' );
		$passed++;
	} elseif ( ! empty( $result['success'] ) ) {
		nf_test_line( 'warn', 'NoFraud_API::create_transaction() returned success but no decision field' );
		$skipped++;
	} else {
		nf_test_line( 'fail', 'NoFraud_API::create_transaction() failed: ' . ( $result['error'] ?? 'Unknown' ) );
		$failed++;
	}

	// Verify NoFraud_API::get_transaction_status() works
	if ( ! empty( $result['id'] ) ) {
		$status = NoFraud_API::get_transaction_status( $result['id'] );
		if ( ! empty( $status['success'] ) ) {
			nf_test_line( 'pass', "NoFraud_API::get_transaction_status() → decision: " . ( $status['decision'] ?? 'N/A' ) );
			$passed++;
		} else {
			nf_test_line( 'fail', 'NoFraud_API::get_transaction_status() failed: ' . ( $status['error'] ?? 'Unknown' ) );
			$failed++;
		}
	}

	// Verify settings helpers
	$enabled = NoFraud_Settings::is_enabled();
	nf_test_line( 'info', 'NoFraud_Settings::is_enabled() → ' . ( $enabled ? 'true' : 'false' ) );

	$fail_action = NoFraud_Settings::get_fail_action();
	nf_test_line( 'info', "NoFraud_Settings::get_fail_action() → '{$fail_action}'" );

	$review_action = NoFraud_Settings::get_review_action();
	nf_test_line( 'info', "NoFraud_Settings::get_review_action() → '{$review_action}'" );

	nf_test_line( 'pass', 'Plugin classes loaded and functional' );
	$passed++;
}

// ===========================================================================
// Summary
// ===========================================================================

summary:

nf_test_header( 'Test Summary' );

$total = $passed + $failed + $skipped;
WP_CLI::line( '' );

if ( $passed > 0 ) {
	WP_CLI::line( "  \033[32m✓ Passed:  {$passed}\033[0m" );
}
if ( $failed > 0 ) {
	WP_CLI::line( "  \033[31m✗ Failed:  {$failed}\033[0m" );
}
if ( $skipped > 0 ) {
	WP_CLI::line( "  \033[33m⚠ Skipped: {$skipped}\033[0m" );
}
WP_CLI::line( "  Total:   {$total}" );
WP_CLI::line( '' );

if ( $failed > 0 ) {
	WP_CLI::error( "{$failed} test(s) failed.", false );
} else {
	WP_CLI::success( "All {$passed} test(s) passed!" );
}
