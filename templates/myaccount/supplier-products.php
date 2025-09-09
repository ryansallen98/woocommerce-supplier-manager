<?php
/**
 * Supplier products (My Account) — main template
 *
 * Copy to override:
 * yourtheme/woocommerce/wc-supplier-manager/myaccount/supplier-products.php
 *
 * @var \WC_Product[] $products
 * @var int           $user_id
 * @var array         $controls
 * @var array         $pagination
 * @var string        $endpoint_url
 */
defined('ABSPATH') || exit;

use WCSM\Support\TemplateLoader;

$controls = $controls ?? [];
$endpoint_url = $endpoint_url ?? wc_get_account_endpoint_url('supplier-products');

// detect if any non-default filter is active
$defaults = [
	'q' => '',
	'type' => 'all',
	'stock' => 'all',
	'orderby' => 'date',
	'order' => 'DESC',
	'per_page' => 10,
];
$active_filters = false;
foreach ($defaults as $k => $def) {
	if (isset($controls[$k]) && (string) $controls[$k] !== (string) $def) {
		$active_filters = true;
		break;
	}
}
?>

<?php wc_print_notices(); ?>

<?php
// Controls are always shown (even if empty results)
TemplateLoader::get('myaccount/parts/controls.php', [
	'controls' => $controls,
	'endpoint_url' => $endpoint_url,
]);

if ($active_filters): ?>
	<p style="margin:.25rem 0 1rem;">
		<a class="button button-secondary" href="<?php echo esc_url($endpoint_url); ?>">
			<?php esc_html_e('Reset filters', 'wc-supplier-manager'); ?>
		</a>
	</p>
<?php endif; ?>

<?php if (empty($products)): ?>
	<p><?php esc_html_e('No products match your current filters.', 'wc-supplier-manager'); ?></p>
<?php else: ?>
	<form method="post" action="<?php echo esc_url($endpoint_url); ?>">
		<?php wp_nonce_field('wcsm_sp_update'); ?>
		<input type="hidden" name="wcsm_sp_action" value="update" />

		<?php
		TemplateLoader::get('myaccount/parts/table.php', [
			'products' => $products,
		]);
		?>

		<p style="margin-top:1rem;">
			<button type="submit" class="button button-primary">
				<?php esc_html_e('Apply changes', 'wc-supplier-manager'); ?>
			</button>
		</p>
	</form>

	<?php
	TemplateLoader::get('myaccount/parts/pagination.php', [
		'pagination' => $pagination ?? [],
	]);
endif;

do_action('wcsm_after_supplier_products_table', $products, $user_id);
?>