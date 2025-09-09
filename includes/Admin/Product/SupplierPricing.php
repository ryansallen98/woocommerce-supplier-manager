<?php
namespace WCSM\Admin\Product;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SupplierPricing {
	const META_KEY = '_wcsm_supplier_price';

	public static function init() : void {
		// SIMPLE product field (General tab)
		add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'render_simple_price_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_simple_price_field' ] );

		// VARIATION field (below regular/sale)
		add_action( 'woocommerce_variation_options_pricing', [ __CLASS__, 'render_variation_price_field' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation_price_field' ], 10, 2 );
	}

	/* ---------- Simple products ---------- */

	public static function render_simple_price_field() : void {
		global $post;

		$value = get_post_meta( $post->ID, self::META_KEY, true );

		echo '<div class="options_group">';
		woocommerce_wp_text_input( [
			'id'            => self::META_KEY,
			'label'         => sprintf(
				/* translators: %s = currency symbol */
				__( 'Supplier price (%s)', 'wc-supplier-manager' ),
				get_woocommerce_currency_symbol()
			),
			'description'   => __( 'Internal supplier cost. Leave empty to use the Regular price as the supplier price.', 'wc-supplier-manager' ),
			'desc_tip'      => true,
			'class'         => 'wc_input_price short',
			'wrapper_class' => 'form-field pricing',
			'value'         => $value !== '' ? wc_format_localized_price( $value ) : '',
			'data_type'     => 'price',
		] );
		echo '</div>';
	}

	public static function save_simple_price_field( \WC_Product $product ) : void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC screen
		$raw = isset( $_POST[ self::META_KEY ] ) ? wp_unslash( $_POST[ self::META_KEY ] ) : '';
		$val = wc_format_decimal( $raw );

		if ( $val === '' ) {
			$product->delete_meta_data( self::META_KEY ); // fallback to Regular
		} else {
			$product->update_meta_data( self::META_KEY, $val );
		}
	}

	/* ---------- Variations ---------- */

	public static function render_variation_price_field( $loop, $variation_data, $variation ) : void {
		$meta_key = self::META_KEY;
		$value    = get_post_meta( $variation->ID, $meta_key, true );

		echo '<div class="form-row form-row-full">';
		woocommerce_wp_text_input( [
			'id'            => "variable_{$meta_key}[{$loop}]",
			'name'          => "variable_{$meta_key}[{$loop}]",
			'label'         => sprintf(
				/* translators: %s = currency symbol */
				__( 'Supplier price (%s)', 'wc-supplier-manager' ),
				get_woocommerce_currency_symbol()
			),
			'description'   => __( 'Leave empty to use the Regular price as the supplier price.', 'wc-supplier-manager' ),
			'desc_tip'      => true,
			'class'         => 'wc_input_price short',
			'wrapper_class' => 'form-field',
			'value'         => $value !== '' ? wc_format_localized_price( $value ) : '',
			'data_type'     => 'price',
		] );
		echo '</div>';
	}

	public static function save_variation_price_field( $variation_id, $i ) : void {
		$key = "variable_" . self::META_KEY;

		if ( isset( $_POST[ $key ][ $i ] ) ) {
			$raw = wp_unslash( $_POST[ $key ][ $i ] );
			$val = wc_format_decimal( $raw );

			if ( $val === '' ) {
				delete_post_meta( $variation_id, self::META_KEY ); // fallback to Regular
			} else {
				update_post_meta( $variation_id, self::META_KEY, $val );
			}
		}
	}
}