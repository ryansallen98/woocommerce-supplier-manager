<?php
/**
 * Plugin Name: WooCommerce Supplier Manager
 * Description: Assign products to suppliers, track supplier costs, email suppliers on new orders, supplier dashboards, and invoice generation.
 * Plugin URI: https://github.com/ryansallen98/woocommerce-supplier-manager
 * Author: Ryan Allen
 * Author URI: https://github.com/ryansallen98
 * Version: 1.3.2
 * Requires Plugins: woocommerce
 * Text Domain: wc-supplier-manager
 * Domain Path: /languages
 * Update URI: https://github.com/ryansallen98/woocommerce-supplier-manager
 */

if (!defined('ABSPATH')) {
	exit;
}

// Minimal constants for includes
define('WCSM_FILE', __FILE__);
define('WCSM_DIR', plugin_dir_path(__FILE__));
define('WCSM_URL', plugin_dir_url(__FILE__));
define('WCSM_VER', '1.0.0');

// Activation hook: load activator just-in-time
register_activation_hook(__FILE__, function () {
	require_once WCSM_DIR . 'includes/Setup/Activator.php';
	\WCSM\Setup\Activator::activate();
});

// Deactivation hook (optional for now)
register_deactivation_hook(__FILE__, function () {
	// reserved for later (e.g., unschedule events); keep loader minimal
});

if (file_exists(WCSM_DIR . 'vendor/autoload.php')) {
	require WCSM_DIR . 'vendor/autoload.php';
}

// Hand off everything else to bootstrap
require_once WCSM_DIR . 'includes/bootstrap.php';

// Add settings link on plugins page
add_action('plugins_loaded', [\WCSM\Admin\SupplierSettingsLink::class, 'init']);