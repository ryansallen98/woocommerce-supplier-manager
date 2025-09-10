<?php
namespace WCSM\Admin\Settings\Tabs;

use WCSM\Support\OrdersTable;

if ( ! defined( 'ABSPATH' ) ) exit;

class OrdersDashboard {
	const OPTION_KEY_COLUMNS          = 'wcsm_supplier_columns';
	const OPTION_KEY_STATUSES_VISIBLE = 'wcsm_supplier_visible_statuses';
	const OPTION_KEY_STATUSES_NOTIFY  = 'wcsm_supplier_notify_statuses';

	const NONCE = 'wcsm_save_supplier_columns';

	public static function help_content() : array {
		return [
			'content' =>
				'<p><strong>' . esc_html__( 'Adding custom columns to the Supplier Orders table', 'wc-supplier-manager' ) . '</strong></p>' .
				'<p>' . esc_html__( 'Hook the wcsm_supplier_orders_columns filter and register your column. Define label, type, and either a renderer or a value callback. Type can be text, number, date, enum, or action. If you set filterable/ sortable, the admin toggles on this page will enable/disable them.', 'wc-supplier-manager' ) . '</p>' .
				'<pre><code>' . esc_html( self::example_column_code() ) . '</code></pre>' .
				'<p><em>' . esc_html__( 'Notes:', 'wc-supplier-manager' ) . '</em> ' .
				esc_html__( '• Use a stable array key (slug) for your column.', 'wc-supplier-manager' ) . ' ' .
				esc_html__( '• For enum columns, provide an options array [value => label].', 'wc-supplier-manager' ) . ' ' .
				esc_html__( '• If you provide only "value", a default renderer will format output based on type/format.', 'wc-supplier-manager' ) .
				'</p>',
			'sidebar' =>
				'<p><strong>' . esc_html__( 'Quick tips', 'wc-supplier-manager' ) . '</strong></p>' .
				'<ul>' .
					'<li>' . esc_html__( '“Visible” and “Sortable” are runtime toggles; they don’t alter your code.', 'wc-supplier-manager' ) . '</li>' .
					'<li>' . esc_html__( '“Filter enabled” only turns a code-defined filter on/off; it does not change its type.', 'wc-supplier-manager' ) . '</li>' .
					'<li>' . esc_html__( 'Action-type columns are never sortable.', 'wc-supplier-manager' ) . '</li>' .
				'</ul>'
		];
	}

	public static function init_runtime_filters() : void {
		add_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'apply_settings' ], 50 );
	}

	public static function render() : void {
		$columns          = self::get_all_columns_for_admin();
		$saved_columns    = self::get_saved_columns();

		// Order status controls (pulled from Woo core)
		$wc_statuses      = wc_get_order_statuses(); // [ 'wc-processing' => 'Processing', ... ]
		$visible_saved    = self::get_saved_visible_statuses();
		$notify_saved     = self::get_saved_notify_statuses();

		?>
		<?php if ( isset( $_GET['updated'] ) ): ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wc-supplier-manager' ); ?></p></div>
		<?php endif; ?>

		<details class="notice notice-info" style="padding:12px 16px;border-radius:4px;">
			<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Help: Add custom columns & tips', 'wc-supplier-manager' ); ?></summary>
			<div style="margin-top:8px;">
				<p><?php esc_html_e( 'Developers can add columns by filtering wcsm_supplier_orders_columns. Define label, type, and either a renderer or a value callback. The filter/search/sort toggles on this page only enable/disable features already declared in your column definition.', 'wc-supplier-manager' ); ?></p>
				<p><em><?php esc_html_e( 'Quick example:', 'wc-supplier-manager' ); ?></em></p>
				<pre style="white-space:pre-wrap;"><code><?php echo esc_html( self::example_column_code() ); ?></code></pre>
				<ul style="margin:0;">
					<li><?php esc_html_e( 'Use a stable array key (slug).', 'wc-supplier-manager' ); ?></li>
					<li><?php esc_html_e( 'For enum types, provide an options map [value => label].', 'wc-supplier-manager' ); ?></li>
					<li><?php esc_html_e( 'If you only provide "value", the default renderer formats output based on type/format.', 'wc-supplier-manager' ); ?></li>
					<li><?php esc_html_e( 'Action-type columns are never sortable.', 'wc-supplier-manager' ); ?></li>
				</ul>
				<p style="margin-top:8px;"><em><?php esc_html_e( 'Need more?', 'wc-supplier-manager' ); ?></em> <?php esc_html_e( 'Click the admin “Help” tab (top-right) for the same guidance.', 'wc-supplier-manager' ); ?></p>
			</div>
		</details>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wcsm_save_supplier_columns" />

			<p><?php esc_html_e( 'Control which columns appear in the Supplier Orders table, whether they are sortable, and whether their (code-defined) filter is enabled.', 'wc-supplier-manager' ); ?></p>

			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Column', 'wc-supplier-manager' ); ?></th>
						<th><?php esc_html_e( 'Key', 'wc-supplier-manager' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wc-supplier-manager' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Visible', 'wc-supplier-manager' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Sortable', 'wc-supplier-manager' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Filter enabled', 'wc-supplier-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $columns as $key => $col ):
						$label     = $col['label'] ?? ucfirst( str_replace( '-', ' ', $key ) );
						$type      = $col['type'] ?? 'text';
						$native_filterable = ! empty( $col['filterable'] );

						$curr      = $saved_columns[ $key ] ?? [];
						$vis       = array_key_exists( 'visible', $curr )        ? (int) $curr['visible']        : (int) ( $col['visible']  ?? 1 );
						$sortable  = array_key_exists( 'sortable', $curr )       ? (int) $curr['sortable']       : (int) ( $col['sortable'] ?? 0 );
						$filter_on = array_key_exists( 'filter_enabled', $curr ) ? (int) $curr['filter_enabled'] : (int) ( $native_filterable ? 1 : 0 );

						if ( ( $col['type'] ?? '' ) === 'action' ) {
							$sortable = 0;
						}
					?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( $type ); ?></td>

							<td style="text-align:center;">
								<label><input type="checkbox" name="wcsm_cols[<?php echo esc_attr( $key ); ?>][visible]" value="1" <?php checked( $vis, 1 ); ?> /></label>
							</td>

							<td style="text-align:center;">
								<label>
									<input type="checkbox"
										name="wcsm_cols[<?php echo esc_attr( $key ); ?>][sortable]"
										value="1"
										<?php checked( $sortable, 1 ); ?>
										<?php if ( ( $col['type'] ?? '' ) === 'action' ) echo ' disabled'; ?>
									/>
								</label>
								<?php if ( ( $col['type'] ?? '' ) === 'action' ) : ?>
									<input type="hidden" name="wcsm_cols[<?php echo esc_attr( $key ); ?>][sortable]" value="0" />
								<?php endif; ?>
							</td>

							<td style="text-align:center;">
								<?php if ( $native_filterable ) : ?>
									<label><input type="checkbox" name="wcsm_cols[<?php echo esc_attr( $key ); ?>][filter_enabled]" value="1" <?php checked( $filter_on, 1 ); ?> /></label>
								<?php else : ?>
									<span style="opacity:.65;"><?php esc_html_e( 'Not filterable', 'wc-supplier-manager' ); ?></span>
									<input type="hidden" name="wcsm_cols[<?php echo esc_attr( $key ); ?>][filter_enabled]" value="0" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr style="margin:24px 0;">

			<h2 style="margin-top:0;"><?php esc_html_e( 'Supplier order statuses', 'wc-supplier-manager' ); ?></h2>
			<p><?php esc_html_e( 'Choose which WooCommerce order statuses are visible to suppliers, and which status changes should trigger an email notification to the assigned suppliers.', 'wc-supplier-manager' ); ?></p>

			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order status', 'wc-supplier-manager' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Visible to suppliers', 'wc-supplier-manager' ); ?></th>
						<th style="text-align:center;"><?php esc_html_e( 'Notify suppliers on change to this status', 'wc-supplier-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_statuses as $key => $label ) :
						// $key looks like 'wc-processing' → slug 'processing'
						$slug = preg_replace( '/^wc-/', '', $key );
						$vis_checked    = in_array( $slug, $visible_saved, true );
						$notify_checked = in_array( $slug, $notify_saved, true );
					?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong> <code style="opacity:.65;margin-left:6px;"><?php echo esc_html( $slug ); ?></code></td>
							<td style="text-align:center;">
								<label>
									<input type="checkbox" name="wcsm_statuses_visible[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $vis_checked ); ?> />
								</label>
							</td>
							<td style="text-align:center;">
								<label>
									<input type="checkbox" name="wcsm_statuses_notify[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $notify_checked ); ?> />
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'wc-supplier-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------- Helpers: example code + saved column settings ---------- */

	private static function example_column_code() : string {
		return <<<'PHP'
add_filter('wcsm_supplier_orders_columns', function(array $cols) {
    $cols['my-custom'] = [
        'label'      => __('My Custom', 'your-textdomain'),
        'order'      => 28,
        'th_class'   => 'my-custom',
        'td_class'   => 'my-custom',
        'type'       => 'text',
        'format'     => null,
        'visible'    => true,
        'sortable'   => true,
        'filterable' => 'search',
        'value'      => static function(\WC_Order $order) {
            return get_post_meta($order->get_id(), '_my_custom_meta', true) ?: '';
        },
    ];
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

	private static function get_saved_columns() : array {
		$opt = get_option( self::OPTION_KEY_COLUMNS, [] );
		return is_array( $opt ) ? $opt : [];
	}

	private static function get_all_columns_for_admin() : array {
		// Temporarily remove runtime settings so we see native definitions
		remove_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'apply_settings' ], 50 );
		// Force all columns visible for the admin page
		add_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'force_visible_for_admin' ], 9999 );

		$columns = OrdersTable::get_columns();

		// Restore
		remove_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'force_visible_for_admin' ], 9999 );
		add_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'apply_settings' ], 50 );

		return $columns;
	}

	public static function force_visible_for_admin( array $cols ) : array {
		foreach ( $cols as &$c ) { $c['visible'] = true; }
		unset( $c );
		return $cols;
	}

	/* ---------- Save handler ---------- */

	public static function handle_save() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission.', 'wc-supplier-manager' ) );
		}
		check_admin_referer( self::NONCE );

		// 1) Columns table
		$in_cols = isset( $_POST['wcsm_cols'] ) && is_array( $_POST['wcsm_cols'] ) ? wp_unslash( $_POST['wcsm_cols'] ) : [];
		$native_cols = self::get_all_columns_for_admin();
		$out_cols = [];

		foreach ( $native_cols as $key => $col ) {
			$row = $in_cols[ $key ] ?? [];

			$visible  = ! empty( $row['visible'] ) ? 1 : 0;

			$type     = $col['type'] ?? 'text';
			$sortable = ! empty( $row['sortable'] ) ? 1 : 0;
			if ( $type === 'action' ) $sortable = 0;

			$native_filterable = ! empty( $col['filterable'] );
			$filter_enabled    = ( $native_filterable && ! empty( $row['filter_enabled'] ) ) ? 1 : 0;

			$out_cols[ $key ] = [
				'visible'        => $visible,
				'sortable'       => $sortable,
				'filter_enabled' => $filter_enabled,
			];
		}
		update_option( self::OPTION_KEY_COLUMNS, $out_cols, false );

		// 2) Status tables (visible + notify)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$in_visible = isset( $_POST['wcsm_statuses_visible'] ) ? (array) wp_unslash( $_POST['wcsm_statuses_visible'] ) : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$in_notify  = isset( $_POST['wcsm_statuses_notify'] )  ? (array) wp_unslash( $_POST['wcsm_statuses_notify'] )  : [];

		// Sanitize to valid slugs present in wc_get_order_statuses()
		$valid_slugs = array_map(
			static function( $key ){ return preg_replace( '/^wc-/', '', $key ); },
			array_keys( wc_get_order_statuses() )
		);

		$vis_clean = array_values( array_intersect( $valid_slugs, array_map( 'sanitize_key', $in_visible ) ) );
		$not_clean = array_values( array_intersect( $valid_slugs, array_map( 'sanitize_key', $in_notify ) ) );

		update_option( self::OPTION_KEY_STATUSES_VISIBLE, $vis_clean, false );
		update_option( self::OPTION_KEY_STATUSES_NOTIFY,  $not_clean, false );

		wp_safe_redirect( add_query_arg( [
			'page'    => \WCSM\Admin\Settings\Menu::PAGE_SLUG,
			'tab'     => 'orders',
			'updated' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Runtime application of saved column settings ---------- */

	public static function apply_settings( array $columns ) : array {
		$saved = self::get_saved_columns();

		foreach ( $columns as $key => &$col ) {
			$orig_filterable = ! empty( $col['filterable'] );

			if ( isset( $saved[ $key ] ) ) {
				if ( array_key_exists( 'visible', $saved[ $key ] ) ) {
					$col['visible'] = (bool) $saved[ $key ]['visible'];
				}
				if ( ( $col['type'] ?? 'text' ) === 'action' ) {
					$col['sortable'] = false;
				} elseif ( array_key_exists( 'sortable', $saved[ $key ] ) ) {
					$col['sortable'] = (bool) $saved[ $key ]['sortable'];
				}
				if ( $orig_filterable ) {
					$enabled = array_key_exists( 'filter_enabled', $saved[ $key ] ) ? (bool) $saved[ $key ]['filter_enabled'] : true;
					if ( ! $enabled ) {
						$col['filterable'] = '';
					}
				}
			}
		}
		unset( $col );

		return $columns;
	}

	/* ---------- Public getters for use elsewhere (e.g., endpoint) ---------- */

	/**
	 * Returns array of slugs (e.g., ['processing','on-hold','completed'])
	 */
	public static function get_saved_visible_statuses() : array {
		$vals = get_option( self::OPTION_KEY_STATUSES_VISIBLE, [] );
		return is_array( $vals ) ? array_values( array_unique( array_map( 'sanitize_key', $vals ) ) ) : [];
	}

	/**
	 * Returns array of slugs (e.g., ['cancelled','refunded'])
	 */
	public static function get_saved_notify_statuses() : array {
		$vals = get_option( self::OPTION_KEY_STATUSES_NOTIFY, [] );
		return is_array( $vals ) ? array_values( array_unique( array_map( 'sanitize_key', $vals ) ) ) : [];
	}
}