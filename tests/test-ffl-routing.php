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
$api_reply = [ 429, [ 'Errors' => [ 'Too Many Requests' ] ] ];
$o = $mk( [ $acc ] );
NoFraud_Order_Handler::screen_order( $o->get_id() );
$check( 'retry: 429 is retried', 'retrying' === $fresh( $o )->get_meta( '_nofraud_decision' ) );
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

// 6. Cart restore: 'Any' attribute variations keep the shopper's choice; no add_to_cart hooks.
if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
	wc_load_cart();
}
if ( WC()->cart ) {
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Color' );
	$attr->set_options( [ 'Red', 'Blue' ] );
	$attr->set_variation( true );
	$var_parent = new WC_Product_Variable();
	$var_parent->set_name( 'NF test sling' );
	$var_parent->set_attributes( [ $attr ] );
	$var_parent->save();
	$var = new WC_Product_Variation();
	$var->set_parent_id( $var_parent->get_id() );
	$var->set_attributes( [ 'color' => '' ] ); // Any Color
	$var->set_regular_price( '20' );
	$var->save();
	$products[] = wc_get_product( $var->get_id() );
	$products[] = wc_get_product( $var_parent->get_id() );

	$o = wc_create_order();
	$o->add_product( wc_get_product( $var->get_id() ), 1, [ 'variation' => [ 'attribute_color' => 'Red' ] ] );
	$o->add_product( $acc, 2 );
	$o->set_status( 'cancelled' );
	$o->save();
	$orders[] = $o;

	WC()->cart->empty_cart();
	$added = 0;
	$count_add = function () use ( &$added ) { $added++; };
	add_action( 'woocommerce_add_to_cart', $count_add );
	$reject->invoke( null, $fresh( $o ) );
	remove_action( 'woocommerce_add_to_cart', $count_add );

	$colors = [];
	$qty    = 0;
	foreach ( WC()->cart->get_cart() as $ci ) {
		$qty += $ci['quantity'];
		if ( $ci['variation_id'] ) {
			$colors[] = $ci['variation']['attribute_color'] ?? '';
		}
	}
	$check( 'cart restore: all lines back', 3 === $qty, (string) $qty );
	$check( 'cart restore: Any variation keeps Red', [ 'Red' ] === $colors, wp_json_encode( $colors ) );
	$check( 'cart restore: no add_to_cart hooks', 0 === $added, (string) $added );
	WC()->cart->empty_cart();
} else {
	echo "SKIP cart restore (no cart in this context)\n";
}

// 7. Webhook: a throttled hit is deferred (202 + queued sync), not dropped.
$o = $mk( [ $acc ], [ '_nofraud_transaction_id' => '99999999-2222-3333-4444-555555555555', '_nofraud_decision' => 'review' ], 'on-hold' );
set_transient( 'nofraud_wh_' . $o->get_id(), 1, 10 );
$req = new WP_REST_Request( 'POST', '/nofraud/v1/webhook' );
$req->set_param( 'invoiceNumber', (string) $o->get_id() );
$res = NoFraud_Webhook::handle_webhook( $req );
$queued = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'nofraud_wc_webhook_sync', [ $o->get_id() ], 'nofraud' );
$check( 'webhook: throttled hit deferred', 202 === $res->get_status() && $queued, $res->get_status() . ' queued=' . (int) $queued );
as_unschedule_all_actions( 'nofraud_wc_webhook_sync', [ $o->get_id() ], 'nofraud' );
delete_transient( 'nofraud_wh_' . $o->get_id() );

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
