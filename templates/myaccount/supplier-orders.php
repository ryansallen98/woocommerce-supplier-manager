<?php
/**
 * Supplier orders (My Account)
 *
 * @var \WC_Order[] $orders
 * @var int         $supplier_id
 * @var array       $controls  [status,q,per_page,wcsm_from,wcsm_to]
 * @var array       $pagination [current,total,base]
 */
defined('ABSPATH') || exit;

use WCSM\Support\TemplateLoader;

$endpoint_url = wc_get_account_endpoint_url(\WCSM\Accounts\SupplierOrdersEndpoint::ENDPOINT);

$statuses = [
	'any' => __('Any status', 'wc-supplier-manager'),
	'pending' => __('Pending', 'wc-supplier-manager'),
	'received' => __('Received', 'wc-supplier-manager'),
	'sent' => __('Sent', 'wc-supplier-manager'),
	'rejected' => __('Rejected', 'wc-supplier-manager'),
];

$c = wp_parse_args($controls ?? [], [
	'status' => 'any',
	'wcsm_q' => '',
	'per_page' => 10,
	'wcsm_from' => '',
	'wcsm_to' => '',
]);
?>

<?php wc_print_notices(); ?>

<form method="get" action="<?php echo esc_url($endpoint_url); ?>" class="wcsm-controls"
	style="margin-bottom:1rem; display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end;">
	<div>
		<label
			style="display:block; font-size:12px; color:#555; margin-bottom:2px;"><?php esc_html_e('Status', 'wc-supplier-manager'); ?></label>
		<select name="status">
			<?php foreach ($statuses as $k => $label): ?>
				<option value="<?php echo esc_attr($k); ?>" <?php selected($c['status'], $k); ?>>
					<?php echo esc_html($label); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div>
		<label
			style="display:block; font-size:12px; color:#555; margin-bottom:2px;"><?php esc_html_e('From', 'wc-supplier-manager'); ?></label>
		<input type="date" name="wcsm_from" value="<?php echo esc_attr($c['wcsm_from']); ?>" />
	</div>

	<div>
		<label
			style="display:block; font-size:12px; color:#555; margin-bottom:2px;"><?php esc_html_e('To', 'wc-supplier-manager'); ?></label>
		<input type="date" name="wcsm_to" value="<?php echo esc_attr($c['wcsm_to']); ?>" />
	</div>

	<div>
		<label
			style="display:block; font-size:12px; color:#555; margin-bottom:2px;"><?php esc_html_e('Per page', 'wc-supplier-manager'); ?></label>
		<select name="per_page">
			<?php foreach ([10, 20, 30, 50] as $pp): ?>
				<option value="<?php echo esc_attr($pp); ?>" <?php selected((int) $c['per_page'], $pp); ?>>
					<?php echo esc_html($pp); ?>/<?php esc_html_e('page', 'wc-supplier-manager'); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>

	<div style="align-self:center;">
		<button type="submit" class="button"><?php esc_html_e('Apply', 'woocommerce'); ?></button>
	</div>
</form>

<?php if (empty($orders)): ?>
	<p><?php esc_html_e('No orders found for your filters.', 'wc-supplier-manager'); ?></p>
<?php else: ?>
	<?php TemplateLoader::get('myaccount/parts/orders-table.php', [
		'orders' => $orders,
		'supplier_id' => $supplier_id,
	]); ?>
	<?php if (!empty($pagination) && ($pagination['total'] ?? 1) > 1): ?>
		<nav class="woocommerce-pagination wcsm-pagination" style="margin-top:1rem;">
			<?php
			echo paginate_links([
				'base' => esc_url_raw(add_query_arg('paged', '%#%', $pagination['base'])),
				'format' => '',
				'current' => (int) ($pagination['current'] ?? 1),
				'total' => (int) ($pagination['total'] ?? 1),
				'type' => 'list',
			]);
			?>
		</nav>
	<?php endif; ?>
<?php endif; ?>