<?php
namespace WCSM\Admin;

if (!defined('ABSPATH')) exit;

class SupplierSettingsLink {
	public static function init(): void {
		add_filter('plugin_action_links_' . plugin_basename(WCSM_FILE), [__CLASS__, 'add_settings_link']);
	}

	public static function add_settings_link(array $links): array {
		$url = admin_url('admin.php?page=wcsm-supplier-settings');
		$settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'wc-supplier-manager') . '</a>';

		// Put our link first
		array_unshift($links, $settings_link);

		return $links;
	}
}