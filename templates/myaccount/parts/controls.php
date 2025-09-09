<?php
/**
 * Controls bar
 *
 * @var array  $controls
 * @var string $endpoint_url
 */
defined('ABSPATH') || exit;

$c = $controls ?? [];
$endpoint_url = $endpoint_url ?? wc_get_account_endpoint_url('supplier-products');

$orderby_opts = [
	'date' => __('Date', 'woocommerce'),
	'title' => __('Name', 'woocommerce'),
	'price' => __('Price', 'woocommerce'),
	'sku' => __('SKU', 'woocommerce'),
	'stock' => __('Stock status', 'woocommerce'),
];
$type_opts = [
	'all' => __('All types', 'woocommerce'),
	'simple' => __('Simple', 'woocommerce'),
	'variable' => __('Variable', 'woocommerce'),
];
$stock_opts = [
	'all' => __('All stock', 'woocommerce'),
	'instock' => __('In stock', 'woocommerce'),
	'outofstock' => __('Out of stock', 'woocommerce'),
];
?>
<form class="wcsm-controls" method="get" action="<?php echo esc_url($endpoint_url); ?>"
	style="margin:0 0 1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
	<input type="search" name="q" value="<?php echo esc_attr($c['q'] ?? ''); ?>"
		placeholder="<?php esc_attr_e('Search products…', 'woocommerce'); ?>" />
	<select name="type">
		<?php foreach ($type_opts as $val => $label): ?>
			<option value="<?php echo esc_attr($val); ?>" <?php selected($c['type'] ?? 'all', $val); ?>>
				<?php echo esc_html($label); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="stock">
		<?php foreach ($stock_opts as $val => $label): ?>
			<option value="<?php echo esc_attr($val); ?>" <?php selected($c['stock'] ?? 'all', $val); ?>>
				<?php echo esc_html($label); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="orderby">
		<?php foreach ($orderby_opts as $val => $label): ?>
			<option value="<?php echo esc_attr($val); ?>" <?php selected($c['orderby'] ?? 'date', $val); ?>>
				<?php echo esc_html($label); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="order">
		<option value="DESC" <?php selected($c['order'] ?? 'DESC', 'DESC'); ?>>
			<?php esc_html_e('Desc', 'woocommerce'); ?></option>
		<option value="ASC" <?php selected($c['order'] ?? 'DESC', 'ASC'); ?>>
			<?php esc_html_e('Asc', 'woocommerce'); ?></option>
	</select>
	<select name="per_page">
		<?php foreach ([10, 20, 30, 50] as $pp): ?>
			<option value="<?php echo esc_attr($pp); ?>" <?php selected((int) ($c['per_page'] ?? 10), $pp); ?>>
				<?php echo esc_html($pp); ?>/page</option>
		<?php endforeach; ?>
	</select>

	<button type="submit" class="button"><?php esc_html_e('Apply', 'woocommerce'); ?></button>
</form>