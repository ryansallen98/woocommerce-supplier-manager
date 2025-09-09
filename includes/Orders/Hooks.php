<?php
namespace WCSM\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Hooks {
	public static function init(): void {
		// Checkout (modern + legacy)
		add_action( 'woocommerce_checkout_create_order',        [ __CLASS__, 'index_on_event' ], 20, 1 ); // modern (receives WC_Order)
		add_action( 'woocommerce_checkout_update_order_meta',   [ __CLASS__, 'index_on_event_id' ], 20, 1 ); // legacy (receives order_id)
		add_action( 'woocommerce_checkout_order_created',       [ __CLASS__, 'index_on_event' ], 20, 1 ); // modern

		// When a new order is created programmatically
		add_action( 'woocommerce_new_order',                    [ __CLASS__, 'index_on_event_id' ], 20, 1 );

		// When items are edited in wp-admin, ensure index refreshes
		add_action( 'woocommerce_saved_order_items',            [ __CLASS__, 'index_on_event_id' ], 20, 1 ); // order_id
		add_action( 'save_post_shop_order',                     [ __CLASS__, 'index_on_event_id' ], 20, 1 );

		// HPOS (custom orders table) safe: both hooks above still fire via compat layer
	}

	public static function index_on_event( $order ): void {
		if ( $order instanceof \WC_Order ) {
			Utils::ensure_supplier_index( $order );
		}
	}

	public static function index_on_event_id( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			Utils::ensure_supplier_index( $order );
		}
	}
}