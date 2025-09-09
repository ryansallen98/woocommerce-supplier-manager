<?php
defined( 'ABSPATH' ) || exit;

use function WCSM\Support\wcsm_get_supplier_price_for_product;

$thumb       = $product->get_image( 'thumbnail' );
$name        = $product->get_name();
$sku         = $product->get_sku();
$reg         = $product->get_regular_price();
$supp        = wcsm_get_supplier_price_for_product( $product );
$is_variable = $product->is_type( 'variable' );
$product_id  = $product->get_id();
?>
<tr class="wcsm-parent" data-product="<?php echo esc_attr( $product_id ); ?>">

	<?php if ( ! $is_variable ) : ?>
		<!-- Only POST parent IDs when the parent is a SIMPLE product -->
		<input type="hidden" name="wcsm_ids[]" value="<?php echo esc_attr( $product_id ); ?>" />
	<?php endif; ?>

	<td class="product-thumbnail"><?php echo wp_kses_post( $thumb ); ?></td>

	<td class="product-name">
		<?php if ( $is_variable ) : ?>
			<button type="button" class="button wcsm-toggle" aria-expanded="false" data-product="<?php echo esc_attr( $product_id ); ?>" title="<?php esc_attr_e( 'Toggle variations', 'wc-supplier-manager' ); ?>">
				<span class="wcsm-toggle-icon" aria-hidden="true">+</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Toggle variations', 'wc-supplier-manager' ); ?></span>
			</button>
		<?php endif; ?>
		<strong><?php echo esc_html( $name ); ?></strong>
	</td>

	<td class="product-sku"><?php echo $sku ? esc_html( $sku ) : '&mdash;'; ?></td>
	<td class="product-regular"><?php echo $reg !== '' ? wc_price( $reg ) : '&mdash;'; ?></td>
	<td class="product-supplier"><?php echo $supp !== '' ? wc_price( $supp ) : '&mdash;'; ?></td>

	<?php if ( $is_variable ) : ?>
		<!-- Variable parent: controls are managed at variation level -->
		<td class="product-stock"><span>&mdash;</span></td>
		<td class="product-backorders"><span>&mdash;</span></td>
		<td class="product-qty"><span>&mdash;</span></td>
	<?php else : ?>
		<!-- SIMPLE parent: show editable controls -->
		<td class="product-stock">
			<?php $ss = $product->get_stock_status(); ?>
			<select name="wcsm_stock_status[<?php echo esc_attr( $product_id ); ?>]">
				<?php
				$opts = [
					'instock'     => __( 'In stock', 'woocommerce' ),
					'outofstock'  => __( 'Out of stock', 'woocommerce' ),
					'onbackorder' => __( 'On backorder', 'woocommerce' ),
				];
				foreach ( $opts as $val => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $ss, $val, false ), esc_html( $label ) );
				}
				?>
			</select>
		</td>

		<td class="product-backorders">
			<?php $bo = $product->get_backorders(); ?>
			<select name="wcsm_backorders[<?php echo esc_attr( $product_id ); ?>]">
				<?php
				$bo_opts = [
					'no'     => __( 'Do not allow', 'woocommerce' ),
					'notify' => __( 'Allow, but notify customer', 'woocommerce' ),
					'yes'    => __( 'Allow', 'woocommerce' ),
				];
				foreach ( $bo_opts as $val => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $bo, $val, false ), esc_html( $label ) );
				}
				?>
			</select>
		</td>

		<td class="product-qty">
			<?php if ( $product->managing_stock() ) : ?>
				<?php $q = $product->get_stock_quantity(); ?>
				<input type="number" step="1" min="0" name="wcsm_qty[<?php echo esc_attr( $product_id ); ?>]" value="<?php echo esc_attr( null === $q ? '' : $q ); ?>" style="width:80px;" />
			<?php else : ?>
				<span>&mdash;</span>
			<?php endif; ?>
		</td>
	<?php endif; ?>
</tr>