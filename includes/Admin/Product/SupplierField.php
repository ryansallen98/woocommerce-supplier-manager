<?php
namespace WCSM\Admin\Product;

use WCSM\Admin\Settings\Options; // ← use the new formatter

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SupplierField {
	const META_KEY = '_wcsm_supplier_id'; // stores user ID or 0

	public static function init() : void {
		// Render the field in Product data > General
		add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_field' ] );

		// Save on product save
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_field' ], 10, 1 );

		// (Optional) expose via REST if you want the value available in API:
		// add_action( 'init', [ __CLASS__, 'register_rest_meta' ] );
	}

	public static function render_field() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return; // only shop managers/admins assign suppliers
		}

		// Build <select> options: 0 => No supplier, then all users with role "supplier"
		$options = [ 0 => __( '— No supplier —', 'wc-supplier-manager' ) ];

		$users = get_users( [
			'role'    => 'supplier',
			'orderby' => 'display_name', // ordering key only; the label below may differ per setting
			'order'   => 'ASC',
			// don't limit 'fields' so we have full WP_User objects available to the formatter
		] );

		foreach ( $users as $user ) {
			// Use the global setting for how supplier names should appear in admin
			$label = Options::format_supplier_name( $user );
			$options[ $user->ID ] = $label;
		}

		global $post;
		$selected = (int) get_post_meta( $post->ID, self::META_KEY, true );
		if ( $selected <= 0 ) { $selected = 0; }

		echo '<div class="options_group">';

		woocommerce_wp_select( [
			'id'            => self::META_KEY,
			'label'         => __( 'Supplier', 'wc-supplier-manager' ),
			'description'   => __( 'Assign this product to a supplier. Leave as “No supplier” if none.', 'wc-supplier-manager' ),
			'desc_tip'      => true,
			'options'       => $options,
			'value'         => $selected,
			'wrapper_class' => 'form-field',
		] );

		echo '</div>';
	}

	public static function save_field( int $product_id ) : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core screen
		$raw = isset( $_POST[ self::META_KEY ] ) ? wp_unslash( $_POST[ self::META_KEY ] ) : null;

		if ( $raw === null ) {
			return; // field not present
		}

		$val = absint( $raw );

		// If a non-zero supplier was chosen, ensure it’s actually a supplier user
		if ( $val > 0 ) {
			$user = get_user_by( 'id', $val );
			if ( ! $user || ! in_array( 'supplier', (array) $user->roles, true ) ) {
				$val = 0; // fallback to "No supplier"
			}
		}

		if ( $val > 0 ) {
			update_post_meta( $product_id, self::META_KEY, $val );
		} else {
			// treat 0/none as no meta
			delete_post_meta( $product_id, self::META_KEY );
		}
	}

	// Optional: make it visible/editable via REST (commented out above)
	public static function register_rest_meta() : void {
		register_post_meta( 'product', self::META_KEY, [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'auth_callback'     => function() {
				return current_user_can( 'manage_woocommerce' );
			},
			'show_in_rest'      => true,
		] );
	}
}