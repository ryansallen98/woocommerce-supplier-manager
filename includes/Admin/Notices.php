<?php
namespace WCSM\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Notices {
	public static function boot() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_deactivate_without_wc' ] );
	}

	public static function maybe_deactivate_without_wc() {
		if ( class_exists( 'WooCommerce' ) ) return;

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( WCSM_FILE ) );
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'WooCommerce Supplier Manager requires WooCommerce and has been deactivated.', 'wc-supplier-manager' )
				. '</p></div>';
		} );
	}
}