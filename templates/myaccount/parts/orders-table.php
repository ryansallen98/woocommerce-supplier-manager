<?php
/**
 * Orders table wrapper
 * @var \WC_Order[] $orders
 * @var int         $supplier_id
 */
defined('ABSPATH') || exit;

use WCSM\Support\TemplateLoader;
?>
<table class="shop_table shop_table_responsive wcsm-supplier-orders-table">
	<thead>
	<tr>
		<th><?php esc_html_e( 'Order', 'woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Date', 'woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Customer', 'woocommerce' ); ?></th>
		<th><?php esc_html_e( 'Items (yours)', 'wc-supplier-manager' ); ?></th>
		<th><?php esc_html_e( 'Status (yours)', 'wc-supplier-manager' ); ?></th>
		<th><?php esc_html_e( 'Actions', 'woocommerce' ); ?></th>
	</tr>
	</thead>
	<tbody>
	<?php foreach ( $orders as $order ) :
		TemplateLoader::get( 'myaccount/parts/order-row.php', [
			'order'       => $order,
			'supplier_id' => $supplier_id,
		] );
	endforeach; ?>
	</tbody>
</table>