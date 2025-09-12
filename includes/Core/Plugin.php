<?php
namespace WCSM\Core;

if (!defined('ABSPATH')) {
	exit;
}

class Plugin
{
	public static function init()
	{
		// Init runtime filters for OrdersDashboard tab
		\WCSM\Admin\Settings\Tabs\OrdersDashboard::init_runtime_filters();

		if (is_admin()) {
			// Always available
			\WCSM\Admin\Product\QuickEdit::init();
			\WCSM\Admin\Orders\SupplierFulfilmentMetaBox::init();

			// Product screens (normal page loads)
			add_action('current_screen', function ($screen) {
				if ($screen && isset($screen->id) && in_array($screen->id, ['product', 'edit-product'], true)) {
					\WCSM\Admin\Product\SupplierField::init();
				}
			});

			// Supplier company info
			\WCSM\Admin\Users\SupplierCompanyInfo::init();

			// Product list column
			\WCSM\Admin\Product\SupplierListColumn::init();

			// Product pricing
			\WCSM\Admin\Product\SupplierPricing::init();

			// Settings menu + tabs
			\WCSM\Admin\Settings\Menu::init();

			// General settings hooks
			\WCSM\Admin\Settings\Tabs\General::hooks();

			// OrdersDashboard save handler:
			add_action('admin_post_wcsm_save_supplier_columns', [\WCSM\Admin\Settings\Tabs\OrdersDashboard::class, 'handle_save']);

			// Supplier index rebuilder (AJAX)
			add_action('admin_init', function(){ \WCSM\Admin\Tools\SupplierIndexRebuilder::init(); });
		}

		\WCSM\Emails\Mailer::init();

		// Frontend endpoints
		\WCSM\Accounts\SupplierProductsEndpoint::init();
		\WCSM\Accounts\SupplierOrdersEndpoint::init();

		// Orders helpers (index suppliers on new orders)
		\WCSM\Orders\Hooks::init();

		// Frontend assets (handles its own enqueue conditions)
		\WCSM\Frontend\Assets::init();
	}
}