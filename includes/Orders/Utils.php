<?php
namespace WCSM\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Utils {
	const META_ORDER_SUPPLIERS = '_wcsm_supplier_ids';        // ",12,45,"
	const META_FULFILMENT      = '_wcsm_supplier_fulfilment'; // array

	public static function supplier_id_for_product( int $product_id ): int {
		$pid = (int) wp_get_post_parent_id( $product_id );
		$check = $pid ?: $product_id;
		return (int) get_post_meta( $check, '_wcsm_supplier_id', true );
	}

	public static function group_items_by_supplier( \WC_Order $order ): array {
		$groups = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) { continue; }
			$supplier_id = self::supplier_id_for_product( $product->get_id() );
			if ( $supplier_id <= 0 ) { continue; }
			$groups[ $supplier_id ][ $item_id ] = $item;
		}
		return $groups;
	}

	public static function ensure_supplier_index( \WC_Order $order ): string {
		$val = (string) $order->get_meta( self::META_ORDER_SUPPLIERS, true );
		if ( '' !== $val ) { return $val; }
		return self::recompute_supplier_index( $order );
	}

	public static function recompute_supplier_index( \WC_Order $order ): string {
		$groups = self::group_items_by_supplier( $order );
		$list   = ',' . implode( ',', array_map( 'intval', array_keys( $groups ) ) ) . ',';
		$order->update_meta_data( self::META_ORDER_SUPPLIERS, $list );
		$order->save();
		return $list;
	}

	public static function get_fulfilment( \WC_Order $order ): array {
		$val = $order->get_meta( self::META_FULFILMENT, true );
		return is_array( $val ) ? $val : [];
	}

	/**
	 * $data['status'] in: pending|received|sent|rejected
	 */
	public static function update_fulfilment_for_supplier(
		\WC_Order $order,
		int $supplier_id,
		array $data
	): void {
		$all   = self::get_fulfilment( $order );
		$entry = isset( $all[ $supplier_id ] ) && is_array( $all[ $supplier_id ] ) ? $all[ $supplier_id ] : [];

		$allowed = [ 'pending', 'received', 'sent', 'rejected' ];
		$entry['status']   = isset( $data['status'] ) && in_array( $data['status'], $allowed, true )
			? $data['status']
			: ( $entry['status'] ?? 'pending' );

		$entry['tracking'] = [
			'carrier' => sanitize_text_field( $data['carrier'] ?? ( $entry['tracking']['carrier'] ?? '' ) ),
			'number'  => sanitize_text_field( $data['number']  ?? ( $entry['tracking']['number']  ?? '' ) ),
			'url'     => esc_url_raw(       $data['url']      ?? ( $entry['tracking']['url']     ?? '' ) ),
			'notes'   => sanitize_textarea_field( $data['notes'] ?? ( $entry['tracking']['notes'] ?? '' ) ),
		];

		$entry['updated_by'] = get_current_user_id();
		$entry['updated_at'] = time();

		$all[ $supplier_id ] = $entry;

		$order->update_meta_data( self::META_FULFILMENT, $all );
		$order->save();
	}

	public static function order_has_supplier( \WC_Order $order, int $supplier_id ): bool {
		$idx = self::ensure_supplier_index( $order );
		return ( $supplier_id > 0 ) && ( false !== strpos( $idx, ',' . $supplier_id . ',' ) );
	}
}