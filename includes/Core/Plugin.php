<?php
namespace WCSM\Core;

if (!defined('ABSPATH')) {
	exit;
}

class Plugin
{
	public static function init()
	{
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


			\WCSM\Admin\Product\SupplierPricing::init();
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