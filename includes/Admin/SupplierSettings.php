<?php
namespace WCSM\Admin;

use WCSM\Support\OrdersTable;

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

class SupplierSettings {
	const OPTION_KEY = 'wcsm_supplier_columns';        // stores per-column settings
	const NONCE      = 'wcsm_save_supplier_columns';

	/** @var string|null */
	private static $hook_suffix = null;

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'add_menu']);
		add_action('admin_post_wcsm_save_supplier_columns', [__CLASS__, 'handle_save']);

		// Apply settings to the live columns config (front-end)
		add_filter('wcsm_supplier_orders_columns', [__CLASS__, 'apply_settings'], 50);
	}

	public static function add_menu(): void {
		self::$hook_suffix = add_submenu_page(
			'woocommerce',
			__('Supplier Settings', 'wc-supplier-manager'),
			__('Supplier Settings', 'wc-supplier-manager'),
			'manage_woocommerce',
			'wcsm-supplier-settings',
			[__CLASS__, 'render_page'],
			56
		);

		// Add WP Screen "Help" tab when this screen loads
		if (self::$hook_suffix) {
			add_action('load-' . self::$hook_suffix, [__CLASS__, 'add_screen_help']);
		}
	}

	/**
	 * Add a WP-admin Help tab (top-right “Help” button).
	 */
	public static function add_screen_help(): void {
		$screen = get_current_screen();
		if (!$screen) return;

		$screen->add_help_tab([
			'id'      => 'wcsm_supplier_help',
			'title'   => __('Developer Help', 'wc-supplier-manager'),
			'content' =>
				'<p><strong>' . esc_html__('Adding custom columns to the Supplier Orders table', 'wc-supplier-manager') . '</strong></p>' .
				'<p>' . esc_html__('Hook the wcsm_supplier_orders_columns filter and register your column. Define label, type, and either a renderer or a value callback. Type can be text, number, date, enum, or action. If you set filterable/ sortable, the admin toggles on this page will enable/disable them.', 'wc-supplier-manager') . '</p>' .
				'<pre><code>' . esc_html(self::example_column_code()) . '</code></pre>' .
				'<p><em>' . esc_html__('Notes:', 'wc-supplier-manager') . '</em> ' .
				esc_html__('• Use a stable array key (slug) for your column.', 'wc-supplier-manager') . ' ' .
				esc_html__('• For enum columns, provide an options array [value => label].', 'wc-supplier-manager') . ' ' .
				esc_html__('• If you provide only "value", a default renderer will format output based on type/format.', 'wc-supplier-manager') .
				'</p>'
		]);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__('Quick tips', 'wc-supplier-manager') . '</strong></p>' .
			'<ul>' .
				'<li>' . esc_html__('“Visible” and “Sortable” are runtime toggles; they don’t alter your code.', 'wc-supplier-manager') . '</li>' .
				'<li>' . esc_html__('“Filter enabled” only turns a code-defined filter on/off; it does not change its type.', 'wc-supplier-manager') . '</li>' .
				'<li>' . esc_html__('Action-type columns are never sortable.', 'wc-supplier-manager') . '</li>' .
			'</ul>'
		);
	}

	/**
	 * Example snippet shown in Help UI.
	 */
	private static function example_column_code(): string {
		return <<<'PHP'
add_filter('wcsm_supplier_orders_columns', function(array $cols) {
    // Insert a custom column after "order-customer"
    $cols['my-custom'] = [
        'label'      => __('My Custom', 'your-textdomain'),
        'order'      => 28,                 // position among columns
        'th_class'   => 'my-custom',
        'td_class'   => 'my-custom',
        'type'       => 'text',             // text|number|date|enum|action
        'format'     => null,               // optional: price|number|date|text
        'visible'    => true,
        'sortable'   => true,
        'filterable' => 'search',           // or 'enum', 'range' (based on type)
        // Raw value used for sort/filter; default renderer will display it.
        'value'      => static function(\WC_Order $order) {
            return get_post_meta($order->get_id(), '_my_custom_meta', true) ?: '';
        },
        // Optional custom render (echo or return string):
        // 'render'  => static function(\WC_Order $order) { echo esc_html('…'); },
    ];

    // Example enum column
    $cols['my-stage'] = [
        'label'      => __('Stage', 'your-textdomain'),
        'order'      => 29,
        'type'       => 'enum',
        'visible'    => true,
        'sortable'   => true,
        'filterable' => 'enum',
        'options'    => [
            'queued'   => __('Queued', 'your-textdomain'),
            'running'  => __('Running', 'your-textdomain'),
            'done'     => __('Done', 'your-textdomain'),
        ],
        'value'      => static function(\WC_Order $order) {
            return get_post_meta($order->get_id(), '_my_stage', true) ?: 'queued';
        },
    ];

    return $cols;
}, 20);
PHP;
	}

	/**
	 * Read saved settings from wp_options.
	 */
	private static function get_saved(): array {
		$opt = get_option(self::OPTION_KEY, []);
		return is_array($opt) ? $opt : [];
	}

	/**
	 * Get the native columns for the Admin UI (un-mutated, and all forced visible),
	 * so even hidden columns still appear on this page.
	 */
	private static function get_all_columns_for_admin(): array {
		// Temporarily remove runtime settings so we see native definitions
		remove_filter('wcsm_supplier_orders_columns', [__CLASS__, 'apply_settings'], 50);

		// Force all columns visible for the admin page
		add_filter('wcsm_supplier_orders_columns', [__CLASS__, 'force_visible_for_admin'], 9999);

		$columns = OrdersTable::get_columns();

		// Undo our admin-only hook, restore runtime settings
		remove_filter('wcsm_supplier_orders_columns', [__CLASS__, 'force_visible_for_admin'], 9999);
		add_filter('wcsm_supplier_orders_columns', [__CLASS__, 'apply_settings'], 50);

		return $columns;
	}

	/**
	 * Make every column visible (admin UI only).
	 */
	public static function force_visible_for_admin(array $cols): array {
		foreach ($cols as &$c) {
			$c['visible'] = true;
		}
		unset($c);
		return $cols;
	}

	public static function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission to access this page.', 'wc-supplier-manager'));
		}

		$columns = self::get_all_columns_for_admin(); // native, all visible
		$saved   = self::get_saved();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Supplier Settings', 'wc-supplier-manager'); ?></h1>

			<?php if (isset($_GET['updated'])): ?>
				<div class="updated notice is-dismissible"><p><?php esc_html_e('Settings saved.', 'wc-supplier-manager'); ?></p></div>
			<?php endif; ?>

			<!-- Help accordion notice -->
			<details class="notice notice-info" style="padding:12px 16px;border-radius:4px;">
				<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Help: Add custom columns & tips', 'wc-supplier-manager'); ?></summary>
				<div style="margin-top:8px;">
					<p><?php esc_html_e('Developers can add columns by filtering wcsm_supplier_orders_columns. Define label, type, and either a renderer or a value callback. The filter/search/sort toggles on this page only enable/disable features already declared in your column definition.', 'wc-supplier-manager'); ?></p>
					<p><em><?php esc_html_e('Quick example:', 'wc-supplier-manager'); ?></em></p>
					<pre style="white-space:pre-wrap;"><code><?php echo esc_html(self::example_column_code()); ?></code></pre>
					<ul style="margin:0;">
						<li><?php esc_html_e('Use a stable array key (slug).', 'wc-supplier-manager'); ?></li>
						<li><?php esc_html_e('For enum types, provide an options map [value => label].', 'wc-supplier-manager'); ?></li>
						<li><?php esc_html_e('If you only provide "value", the default renderer formats output based on type/format.', 'wc-supplier-manager'); ?></li>
						<li><?php esc_html_e('Action-type columns are never sortable.', 'wc-supplier-manager'); ?></li>
					</ul>
					<p style="margin-top:8px;"><em><?php esc_html_e('Need more?', 'wc-supplier-manager'); ?></em> <?php esc_html_e('Click the admin “Help” tab (top-right) for the same guidance.', 'wc-supplier-manager'); ?></p>
				</div>
			</details>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field(self::NONCE); ?>
				<input type="hidden" name="action" value="wcsm_save_supplier_columns" />

				<p><?php esc_html_e('Control which columns appear in the Supplier Orders table, whether they are sortable, and whether their (code-defined) filter is enabled.', 'wc-supplier-manager'); ?></p>

				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th><?php esc_html_e('Column', 'wc-supplier-manager'); ?></th>
							<th><?php esc_html_e('Key', 'wc-supplier-manager'); ?></th>
							<th><?php esc_html_e('Type', 'wc-supplier-manager'); ?></th>
							<th style="text-align:center;"><?php esc_html_e('Visible', 'wc-supplier-manager'); ?></th>
							<th style="text-align:center;"><?php esc_html_e('Sortable', 'wc-supplier-manager'); ?></th>
							<th style="text-align:center;"><?php esc_html_e('Filter enabled', 'wc-supplier-manager'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($columns as $key => $col):
							$label     = $col['label'] ?? ucfirst(str_replace('-', ' ', $key));
							$type      = $col['type'] ?? 'text';
							$native_filterable = !empty($col['filterable']); // as defined in code

							$curr      = $saved[$key] ?? [];
							$vis       = array_key_exists('visible', $curr)        ? (int)$curr['visible']        : (int)($col['visible']  ?? 1);
							$sortable  = array_key_exists('sortable', $curr)       ? (int)$curr['sortable']       : (int)($col['sortable'] ?? 0);
							$filter_on = array_key_exists('filter_enabled', $curr) ? (int)$curr['filter_enabled'] : (int)($native_filterable ? 1 : 0);

							if (($col['type'] ?? '') === 'action') {
								$sortable = 0;
							}
						?>
							<tr>
								<td><strong><?php echo esc_html($label); ?></strong></td>
								<td><code><?php echo esc_html($key); ?></code></td>
								<td><?php echo esc_html($type); ?></td>

								<td style="text-align:center;">
									<label>
										<input type="checkbox" name="wcsm_cols[<?php echo esc_attr($key); ?>][visible]" value="1" <?php checked($vis, 1); ?> />
									</label>
								</td>

								<td style="text-align:center;">
									<label>
										<input type="checkbox"
											name="wcsm_cols[<?php echo esc_attr($key); ?>][sortable]"
											value="1"
											<?php checked($sortable, 1); ?>
											<?php if (($col['type'] ?? '') === 'action') echo ' disabled'; ?>
										/>
									</label>
									<?php if (($col['type'] ?? '') === 'action'): ?>
										<input type="hidden" name="wcsm_cols[<?php echo esc_attr($key); ?>][sortable]" value="0" />
									<?php endif; ?>
								</td>

								<td style="text-align:center;">
									<?php if ($native_filterable): ?>
										<label>
											<input type="checkbox" name="wcsm_cols[<?php echo esc_attr($key); ?>][filter_enabled]" value="1" <?php checked($filter_on, 1); ?> />
										</label>
									<?php else: ?>
										<span style="opacity:.65;"><?php esc_html_e('Not filterable', 'wc-supplier-manager'); ?></span>
										<input type="hidden" name="wcsm_cols[<?php echo esc_attr($key); ?>][filter_enabled]" value="0" />
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e('Save changes', 'wc-supplier-manager'); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission.', 'wc-supplier-manager'));
		}
		check_admin_referer(self::NONCE);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$in = isset($_POST['wcsm_cols']) && is_array($_POST['wcsm_cols']) ? wp_unslash($_POST['wcsm_cols']) : [];

		// Use native definitions to validate input (so even hidden columns can be toggled back on)
		$native_cols = self::get_all_columns_for_admin();
		$out = [];

		foreach ($native_cols as $key => $col) {
			$row = $in[$key] ?? [];

			$visible  = !empty($row['visible']) ? 1 : 0;

			$type     = $col['type'] ?? 'text';
			$sortable = !empty($row['sortable']) ? 1 : 0;
			if ($type === 'action') $sortable = 0;

			$native_filterable = !empty($col['filterable']);
			$filter_enabled    = ($native_filterable && !empty($row['filter_enabled'])) ? 1 : 0;

			$out[$key] = [
				'visible'        => $visible,
				'sortable'       => $sortable,
				'filter_enabled' => $filter_enabled,
			];
		}

		update_option(self::OPTION_KEY, $out, false);
		wp_safe_redirect(add_query_arg(['page' => 'wcsm-supplier-settings', 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/**
	 * Merge saved settings into the columns array shown on the front-end.
	 * We NEVER change the column's native filter type here—just enable/disable it.
	 */
	public static function apply_settings(array $columns): array {
		$saved = self::get_saved();

		foreach ($columns as $key => &$col) {
			$orig_filterable = !empty($col['filterable']); // remember native filterability

			if (isset($saved[$key])) {
				// Visible
				if (array_key_exists('visible', $saved[$key])) {
					$col['visible'] = (bool) $saved[$key]['visible'];
				}

				// Sortable (keep actions not sortable)
				if (($col['type'] ?? 'text') === 'action') {
					$col['sortable'] = false;
				} elseif (array_key_exists('sortable', $saved[$key])) {
					$col['sortable'] = (bool) $saved[$key]['sortable'];
				}

				// Filter enabled toggle: if native filterable but user turned it off, blank it.
				if ($orig_filterable) {
					$enabled = array_key_exists('filter_enabled', $saved[$key]) ? (bool) $saved[$key]['filter_enabled'] : true;
					if (!$enabled) {
						$col['filterable'] = '';
					}
				}
			}
		}
		unset($col);

		return $columns;
	}
}