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
			// Quick Edit must always be available (even during admin-ajax).
			\WCSM\Admin\Product\QuickEdit::init();
			\WCSM\Admin\Orders\SupplierFulfilmentMetaBox::init();

			// Load heavier product edit UI only on product screens.
			add_action('current_screen', function ($screen) {
				if (!$screen || !isset($screen->id)) {
					return;
				}

				if (in_array($screen->id, ['product', 'edit-product'], true)) {
					\WCSM\Admin\Product\SupplierField::init();
					\WCSM\Admin\Product\SupplierPricing::init();
				}
			});
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


