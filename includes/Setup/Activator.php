<?php
namespace WCSM\Setup;

use WCSM\Setup\Roles\SupplierRole;

if (!defined('ABSPATH')) {
	exit;
}

class Activator
{
	public static function activate()
	{
		// Ensure WooCommerce is active first
		if (!class_exists('WooCommerce')) {
			if (!function_exists('deactivate_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins(plugin_basename(WCSM_FILE));
			wp_die(
				esc_html__('WooCommerce Supplier Manager requires WooCommerce. Please activate WooCommerce first.', 'wc-supplier-manager'),
				esc_html__('Dependency missing', 'wc-supplier-manager'),
				['back_link' => true]
			);
		}

		// Create/update Supplier role
		require_once __DIR__ . '/Roles/SupplierRole.php';
		SupplierRole::register();

		// Register our account endpoints
		// Safer to hardcode slugs here (no autoload dependency)
		add_rewrite_endpoint('supplier-products', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('supplier-orders', EP_ROOT | EP_PAGES);

		// Flush once after all rewrite changes
		flush_rewrite_rules();
	}
}