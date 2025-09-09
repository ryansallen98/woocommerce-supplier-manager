<?php
use WCSM\Support\OrdersTable;

/** @var WC_Order[] $orders */
$columns = OrdersTable::get_columns();

// Read current controls from query (fallback to your $c defaults)
$filter_in = isset($_GET['wcsm_f']) && is_array($_GET['wcsm_f']) ? wp_unslash($_GET['wcsm_f']) : [];
$sort_by = isset($_GET['wcsm_sort']) ? sanitize_key($_GET['wcsm_sort']) : '';
$sort_dir = isset($_GET['wcsm_dir']) ? (strtolower($_GET['wcsm_dir']) === 'desc' ? 'desc' : 'asc') : 'asc';
$per_page = isset($_GET['per_page']) ? max(1, (int) $_GET['per_page']) : (int) ($c['per_page'] ?? 10);

// Partition columns
$filterable_cols = array_filter($columns, static fn($col) => !empty($col['filterable']));
$sortable_cols = array_filter($columns, static fn($col) => !empty($col['sortable']));

// Simple helpers
$val = static function ($arr, $key, $default = '') {
	return isset($arr[$key]) ? $arr[$key] : $default;
};
?>
<?php wc_print_notices(); ?>

<form method="get" action="<?php echo esc_url($endpoint_url); ?>" class="wcsm-controls"
	style="margin: 0 0 1rem; display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">

	<?php
	// Render one control per filterable column based on its "filterable" mode
	foreach ($filterable_cols as $key => $col) {
		$mode = $col['filterable'];      // 'search' | 'range' | 'enum' | true
		$type = $col['type'] ?? 'text';  // helps pick input types
		$label = $col['label'] ?? ucfirst($key);
		$name = "wcsm_f[$key]";          // base name
		$value = $val($filter_in, $key, '');

		echo '<div>';
		printf('<label style="display:block;font-size:12px;color:#555;margin-bottom:2px;">%s</label>', esc_html($label));

		// ENUM: select box
		if ($mode === 'enum' && !empty($col['options']) && is_array($col['options'])) {
			echo '<select name="' . esc_attr($name) . '">';
			echo '<option value="">' . esc_html__('Any', 'wc-supplier-manager') . '</option>';
			foreach ($col['options'] as $opt_val => $opt_label) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr($opt_val),
					selected((string) $value, (string) $opt_val, false),
					esc_html($opt_label)
				);
			}
			echo '</select>';

			// RANGE: min/max (date or number/price)
		} elseif ($mode === 'range') {
			// Expect wcsm_f[key][min] / wcsm_f[key][max]
			$min_name = $name . '[min]';
			$max_name = $name . '[max]';
			$min_val = $val($value, 'min', '');
			$max_val = $val($value, 'max', '');

			// Choose input types
			if ($type === 'date') {
				printf('<input type="date" name="%s" value="%s" />', esc_attr($min_name), esc_attr($min_val));
				echo ' &nbsp;–&nbsp; ';
				printf('<input type="date" name="%s" value="%s" />', esc_attr($max_name), esc_attr($max_val));
			} else {
				$step = ($col['format'] ?? '') === 'price' ? '0.01' : '1';
				printf('<input type="number" step="%s" name="%s" value="%s" style="width:8em;" />', esc_attr($step), esc_attr($min_name), esc_attr($min_val));
				echo ' &nbsp;–&nbsp; ';
				printf('<input type="number" step="%s" name="%s" value="%s" style="width:8em;" />', esc_attr($step), esc_attr($max_name), esc_attr($max_val));
			}

			// SEARCH (or generic true): text box
		} else {
			printf(
				'<input type="search" name="%s" value="%s" placeholder="%s" />',
				esc_attr($name),
				esc_attr(is_string($value) ? $value : ''),
				esc_attr(sprintf(__('Search %s…', 'wc-supplier-manager'), $label))
			);
		}

		echo '</div>';
	}
	?>

	<!-- Sorting -->
	<?php if (!empty($sortable_cols)): ?>
		<div>
			<label
				style="display:block;font-size:12px;color:#555;margin-bottom:2px;"><?php esc_html_e('Sort by', 'wc-supplier-manager'); ?></label>
			<select name="wcsm_sort">
				<?php foreach ($sortable_cols as $key => $col): ?>
					<option value="<?php echo esc_attr($key); ?>" <?php selected($sort_by, $key); ?>>
						<?php echo esc_html($col['label'] ?? ucfirst($key)); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div>
			<label
				style="display:block;font-size:12px;color:#555;margin-bottom:2px;"><?php esc_html_e('Direction', 'wc-supplier-manager'); ?></label>
			<select name="wcsm_dir">
				<option value="asc" <?php selected($sort_dir, 'asc'); ?>>
					<?php esc_html_e('Ascending', 'wc-supplier-manager'); ?>
				</option>
				<option value="desc" <?php selected($sort_dir, 'desc'); ?>>
					<?php esc_html_e('Descending', 'wc-supplier-manager'); ?>
				</option>
			</select>
		</div>
	<?php endif; ?>

	<!-- Per page -->
	<div>
		<label
			style="display:block;font-size:12px;color:#555;margin-bottom:2px;"><?php esc_html_e('Per page', 'wc-supplier-manager'); ?></label>
		<select name="per_page">
			<?php foreach ([10, 20, 30, 50] as $pp): ?>
				<option value="<?php echo esc_attr($pp); ?>" <?php selected($per_page, $pp); ?>>
					<?php echo esc_html($pp); ?>/<?php esc_html_e('page', 'wc-supplier-manager'); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div style="align-self:center;">
		<button type="submit" class="button"><?php esc_html_e('Apply', 'woocommerce'); ?></button>
	</div>
</form>

<table class="shop_table shop_table_responsive my_account_orders">
	<thead>
		<tr>
			<?php foreach ($columns as $key => $col): ?>
				<th class="<?php echo esc_attr($col['th_class'] ?? $key); ?>">
					<?php echo esc_html($col['label'] ?? ucfirst(str_replace('-', ' ', $key))); ?>
				</th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ($orders as $order): ?>
			<tr>
				<?php foreach ($columns as $key => $col): ?>
					<td class="<?php echo esc_attr($col['td_class'] ?? $key); ?>"
						data-title="<?php echo esc_attr($col['label'] ?? $key); ?>">
						<?php
						if (is_callable($col['render'] ?? null)) {
							$out = call_user_func($col['render'], $order);
							if (is_string($out)) {
								echo $out; // phpcs:ignore
							}
						} else {
							echo '&nbsp;';
						}
						?>
					</td>
				<?php endforeach; ?>
			</tr>

			<?php
			$order_id = $order->get_id();
			$supplier_id = (int) ($supplier_id ?? get_current_user_id()); // keep supplied var, fallback to current user
			$ff = \WCSM\Orders\Utils::get_fulfilment($order);
			$mine = $ff[$supplier_id] ?? ['status' => 'pending', 'tracking' => ['carrier' => '', 'number' => '', 'url' => '', 'notes' => '']];

			$status_opts = [
				'pending' => __('Pending', 'wc-supplier-manager'),
				'received' => __('Received', 'wc-supplier-manager'),
				'sent' => __('Sent', 'wc-supplier-manager'),
				'rejected' => __('Rejected', 'wc-supplier-manager'),
			];
			?>

			<tr id="wcsm-order-form-<?php echo esc_attr($order_id); ?>" class="wcsm-order-edit" style="display:none;"
				aria-hidden="true">
				<td colspan="<?php echo (int) count($columns); ?>">
					<form method="post"
						action="<?php echo esc_url(wc_get_account_endpoint_url(\WCSM\Accounts\SupplierOrdersEndpoint::ENDPOINT)); ?>">
						<?php wp_nonce_field('wcsm_supplier_orders'); ?>
						<input type="hidden" name="wcsm_so_action" value="update" />
						<input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>" />

						<p>
							<label>
								<span class="title"><?php esc_html_e('Your status', 'wc-supplier-manager'); ?></span>
								<select name="wcsm_status">
									<?php foreach ($status_opts as $k => $label): ?>
										<option value="<?php echo esc_attr($k); ?>" <?php selected($mine['status'] ?? 'pending', $k); ?>>
											<?php echo esc_html($label); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>

						<p style="display:flex; gap:1rem; flex-wrap:wrap;">
							<label>
								<span class="title"><?php esc_html_e('Carrier', 'wc-supplier-manager'); ?></span>
								<input type="text" name="wcsm_carrier"
									value="<?php echo esc_attr($mine['tracking']['carrier'] ?? ''); ?>" />
							</label>
							<label>
								<span class="title"><?php esc_html_e('Tracking number', 'wc-supplier-manager'); ?></span>
								<input type="text" name="wcsm_tracking"
									value="<?php echo esc_attr($mine['tracking']['number'] ?? ''); ?>" />
							</label>
							<label style="flex:1 1 260px;">
								<span class="title"><?php esc_html_e('Tracking URL', 'wc-supplier-manager'); ?></span>
								<input type="url" name="wcsm_url"
									value="<?php echo esc_url($mine['tracking']['url'] ?? ''); ?>" />
							</label>
						</p>

						<p>
							<label style="width:100%;">
								<span
									class="title"><?php esc_html_e('Notes (optional, required if rejecting)', 'wc-supplier-manager'); ?></span>
								<textarea name="wcsm_notes" rows="3"
									style="width:100%;"><?php echo esc_textarea($mine['tracking']['notes'] ?? ''); ?></textarea>
							</label>
						</p>

						<p>
							<button type="submit"
								class="button button-primary"><?php esc_html_e('Save', 'wc-supplier-manager'); ?></button>
						</p>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if (!empty($pagination) && ($pagination['total'] ?? 1) > 1): ?>
    <nav class="woocommerce-pagination wcsm-pagination" style="margin-top:1rem;">
        <?php
        echo paginate_links([
            'base'    => esc_url_raw(add_query_arg('paged', '%#%', $pagination['base'])),
            'format'  => '',
            'current' => (int) ($pagination['current'] ?? 1),
            'total'   => (int) ($pagination['total'] ?? 1),
            'type'    => 'list',
        ]);
        ?>
    </nav>
<?php endif; ?>