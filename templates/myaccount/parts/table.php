<?php
/**
 * Table wrapper — thead + iterate rows
 *
 * @var \WC_Product[] $products
 */
defined( 'ABSPATH' ) || exit;

?>
<table class="shop_table shop_table_responsive wcsm-supplier-products-table">
	<thead>
		<tr>
			<th class="product-thumbnail">&nbsp;</th>
			<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
			<th class="product-sku"><?php esc_html_e( 'SKU', 'woocommerce' ); ?></th>
			<th class="product-regular"><?php esc_html_e( 'Regular price', 'woocommerce' ); ?></th>
			<th class="product-supplier"><?php esc_html_e( 'Supplier price', 'wc-supplier-manager' ); ?></th>
			<th class="product-stock"><?php esc_html_e( 'Stock status', 'woocommerce' ); ?></th>
			<th class="product-backorders"><?php esc_html_e( 'Backorders', 'woocommerce' ); ?></th>
			<th class="product-qty"><?php esc_html_e( 'Qty', 'woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $products as $product ) :
			TemplateLoader::get( 'myaccount/parts/row-parent.php', [ 'product' => $product ] );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( ! $variation || ! $variation->exists() ) { continue; }
					TemplateLoader::get( 'myaccount/parts/row-variation.php', [
						'product'   => $product,
						'variation' => $variation,
					] );
				}
			}
		endforeach; ?>
	</tbody>
</table>