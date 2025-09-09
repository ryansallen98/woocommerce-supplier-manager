<?php
/**
 * One variation sub-row (hidden by default; toggled by JS)
 *
 * @var \WC_Product            $product
 * @var \WC_Product_Variation  $variation
 */
defined( 'ABSPATH' ) || exit;

use function WCSM\Support\wcsm_get_supplier_price_for_product;
use function WCSM\Support\wcsm_variation_label;

$parent_id  = $product->get_id();
$vid        = $variation->get_id();

$v_thumb    = $variation->get_image( 'thumbnail' );
$v_name     = wcsm_variation_label( $variation );
$v_sku      = $variation->get_sku();
$v_reg      = $variation->get_regular_price();
$v_supp     = wcsm_get_supplier_price_for_product( $variation );
$v_in_stock = $variation->is_in_stock();
?>
<tr class="wcsm-variation" data-parent="<?php echo esc_attr( $parent_id ); ?>" aria-hidden="true">
	<input type="hidden" name="wcsm_ids[]" value="<?php echo esc_attr( $vid ); ?>" />

	<td class="product-thumbnail"><?php echo $v_thumb ? wp_kses_post( $v_thumb ) : $product->get_image( 'thumbnail' ); ?></td>
	<td class="product-name"><span class="wcsm-variation-indent">&mdash;</span><?php echo esc_html( $v_name ); ?></td>
	<td class="product-sku"><?php echo $v_sku ? esc_html( $v_sku ) : '&mdash;'; ?></td>
	<td class="product-regular"><?php echo $v_reg !== '' ? wc_price( $v_reg ) : '&mdash;'; ?></td>
	<td class="product-supplier"><?php echo $v_supp !== '' ? wc_price( $v_supp ) : '&mdash;'; ?></td>

	<td class="product-stock">
		<?php $ss = $variation->get_stock_status(); ?>
		<select name="wcsm_stock_status[<?php echo esc_attr( $vid ); ?>]">
			<?php
			foreach ( [ 'instock' => __( 'In stock', 'woocommerce' ), 'outofstock' => __( 'Out of stock', 'woocommerce' ), 'onbackorder' => __( 'On backorder', 'woocommerce' ) ] as $val => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $ss, $val, false ), esc_html( $label ) );
			}
			?>
		</select>
	</td>

	<td class="product-backorders">
		<?php $bo = $variation->get_backorders(); ?>
		<select name="wcsm_backorders[<?php echo esc_attr( $vid ); ?>]">
			<?php
			$opts = [
				'no'     => __( 'Do not allow', 'woocommerce' ),
				'notify' => __( 'Allow, but notify customer', 'woocommerce' ),
				'yes'    => __( 'Allow', 'woocommerce' ),
			];
			foreach ( $opts as $val => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $bo, $val, false ), esc_html( $label ) );
			}
			?>
		</select>
	</td>

	<td class="product-qty">
		<?php if ( $variation->managing_stock() ) : ?>
			<?php $q = $variation->get_stock_quantity(); ?>
			<input type="number" step="1" min="0" name="wcsm_qty[<?php echo esc_attr( $vid ); ?>]" value="<?php echo esc_attr( null === $q ? '' : $q ); ?>" style="width:80px;" />
		<?php else : ?>
			<span>&mdash;</span>
		<?php endif; ?>
	</td>
</tr>