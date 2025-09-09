<?php
namespace WCSM\Support;

if (!defined('ABSPATH')) {
	exit;
}

class TemplateLoader
{
	/**
	 * Load a plugin template with WooCommerce override support.
	 *
	 * Theme override path:  wp-content/themes/yourtheme/woocommerce/wc-supplier-manager/{template}
	 * Plugin default path:  {plugin}/templates/{template}
	 */
	public static function get(string $template, array $args = [])
	{
		$template_path = 'woocommerce/wc-supplier-manager/'; // theme override base
		$default_path = trailingslashit(\WCSM_DIR) . 'templates/'; // plugin templates

		if (function_exists('wc_get_template')) {
			wc_get_template($template, $args, $template_path, $default_path);
		} else {
			// Fallback include (shouldn't happen without WC)
			$file = $default_path . $template;
			if (file_exists($file)) {
				extract($args, EXTR_SKIP);
				include $file;
			}
		}
	}

	public static function locate(string $template): string
	{
		$template_path = 'woocommerce/wc-supplier-manager/';
		$default_path = trailingslashit(\WCSM_DIR) . 'templates/';

		if (function_exists('wc_locate_template')) {
			$file = wc_locate_template($template, $template_path, $default_path);
			if ($file)
				return $file;
		}
		$file = $default_path . $template;
		return file_exists($file) ? $file : '';
	}

	public static function capture(string $template, array $args = []): string
	{
		$file = self::locate($template);
		if (!$file) {
			return '';
		}
		ob_start();
		extract($args, EXTR_SKIP);
		include $file;
		return ob_get_clean();
	}
}