<?php
/**
 * Offline checks for FFL routing, holds, retries and sync-fail cleanup.
 *
 * No NoFraud account needed: API calls are answered by a pre_http_request stub.
 * Creates throwaway products/orders and deletes them. Run on a local site only:
 *
 *   wp eval-file tests/test-ffl-routing.php
 */

defined( 'ABSPATH' ) || exit;

$nf_pass = 0;
$nf_fail = 0;
$check   = function ( string $label, bool $ok, string $extra = '' ) use ( &$nf_pass, &$nf_fail ) {
	$ok ? $nf_pass++ : $nf_fail++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $extra ? "  [$extra]" : '' ) . "\n";
};

$saved_options = [];
foreach ( [ 'nofraud_wc_enabled', 'nofraud_wc_api_key', 'nofraud_wc_ffl_orders', 'woocommerce_manage_stock', 'ffl_core_in_store_pickup_license' ] as $opt ) {
	$saved_options[ $opt ] = get_option( $opt, null );
}
update_option( 'nofraud_wc_enabled', 'yes' );
update_option( 'nofraud_wc_api_key', 'NF_offline_test' );
update_option( 'woocommerce_manage_stock', 'yes' );

// Stubbed NoFraud: $api_reply = [ http code, body ].
$api_reply = [ 200, [ 'id' => '11111111-2222-3333-4444-555555555555', 'decision' => 'pass' ] ];
$api_calls = 0;
$stub      = function ( $pre, $args, $url ) use ( &$api_reply, &$api_calls ) {
	if ( false === strpos( $url, 'nofraud.com' ) ) {
		return $pre;
	}
	$api_calls++;
	return [ 'headers' => [], 'body' => wp_json_encode( $api_reply[1] ), 'response' => [ 'code' => $api_reply[0], 'message' => '' ], 'cookies' => [] ];
};
add_filter( 'pre_http_request', $stub, 10, 3 );

$products = [];
$mk_product = function ( bool $firearm, int $stock = 0 ) use ( &$products ) {
	$p = new WC_Product_Simple();
	$p->set_name( $firearm ? 'NF test rifle' : 'NF test magazine' );
	$p->set_regular_price( '100' );
	if ( $stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
	}
	$p->save();
	if ( $firearm ) {
		update_post_meta( $p->get_id(), '_firearm_product', 'yes' );
	}
	return $products[] = wc_get_product( $p->get_id() );
};
$gun = $mk_product( true );
$acc = $mk_product( false );

$orders = [];
$mk = function ( array $items, array $meta = [], string $status = 'pending' ) use ( &$orders ) {
	$o = wc_create_order();
	foreach ( $items as $p ) {
		$o->add_product( $p, 1 );
	}
	$a = [ 'first_name' => 'Jane', 'last_name' => 'Buyer', 'address_1' => '12 Home St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US', 'email' => 'jane@example.com', 'phone' => '5125550100' ];
	$o->set_address( $a, 'billing' );
	$o->set_address( $a, 'shipping' );
	$o->set_customer_ip_address( '203.0.113.9' );
	foreach ( $meta as $k => $v ) {
		$o->update_meta_data( $k, $v );
	}
	$o->set_status( $status );
	$o->calculate_totals();
	$o->save();
	return $orders[] = $o;
};
$fresh = fn( WC_Order $o ) => wc_get_order( $o->get_id() );

$ffl = [
	'_shipping_fflno'              => '1-74-123-01-2B-12345',
	'_shipping_ffl_name'           => 'ACME GUNS',
	'_shipping_ffl_premise_street' => '500 Dealer Rd',
	'_shipping_ffl_premise_city'   => 'Dallas',
	'_shipping_ffl_premise_state'  => 'TX',
	'_shipping_ffl_premise_zip'    => '75201',
];
$pickup_license = (string) get_option( 'ffl_core_in_store_pickup_license', '' ) ?: '5-75-000-01-9Z-00000';
update_option( 'ffl_core_in_store_pickup_license', $pickup_license );

$cases = [
	'ffl_only'     => [ [ $gun ], $ffl + [ '_order_shipment_type' => 'ffl_only' ], '500 Dealer Rd' ],
	'mixed_to_ffl' => [ [ $gun, $acc ], $ffl + [ '_order_shipment_type' => 'mixed' ], '500 Dealer Rd' ],
	'mixed_home'   => [ [ $gun, $acc ], $ffl + [ '_order_shipment_type' => 'mixed', 'ffl_core_ship_home' => [ 'address_1' => '9 Elm St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78702' ] ], '9 Elm St' ],
	'pickup'       => [ [ $gun ], [ '_shipping_fflno' => $pickup_license, '_order_shipment_type' => 'ffl_only' ], null ],
	'cr'           => [ [ $gun ], [ '_shipping_fflno' => '1-23-456-03-7C-89012', '_shipping_ffl_is_cr' => 'yes', '_order_shipment_type' => 'ffl_only' ], '12 Home St' ],
	'regular'      => [ [ $acc ], [ '_order_shipment_type' => 'regular_only' ], '12 Home St' ],
];

// 1. Ship-to per order type (under ffl-core; g-FFL is not loaded here).
$build = new ReflectionMethod( 'NoFraud_Order_Handler', 'build_transaction_data' );
foreach ( $cases as $name => [ $items, $meta, $street ] ) {
	$d = $build->invoke( null, $mk( $items, $meta ) );
	if ( null === $street ) {
		$check( "shipTo $name: store address + isBopis", 'true' === ( $d['isBopis'] ?? '' ) && ! empty( $d['shipTo']['address'] ) );
	} else {
		$check( "shipTo $name", $street === ( $d['shipTo']['address'] ?? '' ), wp_json_encode( $d['shipTo'] ?? null ) );
	}
}

// 2. FFL Orders modes → skipped or screened.
$expect = [
	'skip_ffl_address' => [ 'ffl_only' => 1, 'mixed_to_ffl' => 1, 'mixed_home' => 0, 'pickup' => 1, 'cr' => 0, 'regular' => 0 ],
	'skip_ffl_only'    => [ 'ffl_only' => 1, 'mixed_to_ffl' => 0, 'mixed_home' => 0, 'pickup' => 1, 'cr' => 1, 'regular' => 0 ],
	'screen'           => [ 'ffl_only' => 0, 'mixed_to_ffl' => 0, 'mixed_home' => 0, 'pickup' => 0, 'cr' => 0, 'regular' => 0 ],
];
foreach ( $expect as $mode => $exp ) {
	update_option( 'nofraud_wc_ffl_orders', $mode );
	foreach ( $cases as $name => [ $items, $meta ] ) {
		$o = $mk( $items, $meta );
		NoFraud_Order_Handler::screen_order( $o->get_id() );
		$got = $fresh( $o )->get_meta( '_nofraud_decision' );
		$check( "mode $mode / $name", ( 'skipped' === $got ? 1 : 0 ) === $exp[ $name ], $got );
	}
}
delete_option( 'nofraud_wc_ffl_orders' );
$check( 'default mode is skip_ffl_address', 'skip_ffl_address' === NoFraud_Settings::ffl_orders_mode() );

// 3. Hold ownership: never claim an order someone else already held.
$o = $mk( [ $acc ], [], 'on-hold' );
NoFraud_Settings::hold( $o, 'test' );
$check( 'hold: pre-existing hold not claimed', '' === $fresh( $o )->get_meta( NoFraud_Settings::META_HOLD ) );
$o = $mk( [ $acc ], [], 'processing' );
NoFraud_Settings::hold( $o, 'test' );
$check( 'hold: own hold marked', '1' === $fresh( $o )->get_meta( NoFraud_Settings::META_HOLD ) );

// 4. Retries: transient error queues a retry and blocks re-screening; 4xx is terminal.
update_option( 'nofraud_wc_ffl_orders', 'screen' );
$api_reply = [ 500, [ 'Errors' => [ 'boom' ] ] ];
$o = $mk( [ $acc ] );
NoFraud_Order_Handler::screen_order( $o->get_id() );
$check( 'retry: 5xx marks retrying', 'retrying' === $fresh( $o )->get_meta( '_nofraud_decision' ) );
$calls = $api_calls;
NoFraud_Order_Handler::screen_order( $o->get_id() ); // a status transition while the retry is queued
$check( 'retry: transition does not re-screen', $calls === $api_calls );
$api_reply = [ 200, [ 'id' => '11111111-2222-3333-4444-555555555555', 'decision' => 'pass' ] ];
NoFraud_Order_Handler::retry_screen( $o->get_id() );
$check( 'retry: job screens it', 'pass' === $fresh( $o )->get_meta( '_nofraud_decision' ) );
$api_reply = [ 403, [ 'Errors' => [ 'Not Authorized' ] ] ];
$o = $mk( [ $acc ] );
NoFraud_Order_Handler::screen_order( $o->get_id() );
$check( 'retry: 403 is terminal error', 'error' === $fresh( $o )->get_meta( '_nofraud_decision' ) );
delete_option( 'nofraud_wc_ffl_orders' );

// 5. Sync fail cleanup restores the stock the gateway reduced after cancelling.
$stocked = $mk_product( false, 5 );
$o       = $mk( [ $stocked ], [], 'cancelled' );
wc_reduce_stock_levels( $o->get_id() );
$reject = new ReflectionMethod( 'NoFraud_Checkout', 'reject' );
$reject->invoke( null, $fresh( $o ) );
$check( 'reject: stock restored', 5 === (int) wc_get_product( $stocked->get_id() )->get_stock_quantity(), (string) wc_get_product( $stocked->get_id() )->get_stock_quantity() );

// Cleanup.
remove_filter( 'pre_http_request', $stub, 10 );
foreach ( $orders as $o ) {
	$o->delete( true );
}
foreach ( $products as $p ) {
	$p->delete( true );
}
foreach ( $saved_options as $opt => $val ) {
	null === $val ? delete_option( $opt ) : update_option( $opt, $val );
}

echo "\n$nf_pass passed, $nf_fail failed\n";
