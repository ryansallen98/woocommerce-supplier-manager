<?php
/**
 * Supplier packing slip (HTML -> PDF via Dompdf)
 * Override: yourtheme/woocommerce/wc-supplier-manager/myaccount/packing-slip.php
 *
 * @var \WC_Order $order
 * @var int       $supplier_id
 */
defined('ABSPATH') || exit;

use WCSM\Orders\Utils;

// --- helpers ---
if ( ! function_exists( 'wcsm_supplier_price_for_product_id' ) ) {
	/**
	 * Get supplier price for a product (variation or simple).
	 * Falls back to parent meta, then regular price if not set.
	 */
	function wcsm_supplier_price_for_product_id( int $product_id ): string {
		$meta_key = '_wcsm_supplier_price'; // matches your SupplierPricing::META_KEY
		$pid = $product_id;
		// direct meta
		$val = get_post_meta( $pid, $meta_key, true );
		if ( $val !== '' ) { return $val; }
		// check parent (for variations)
		$parent_id = (int) wp_get_post_parent_id( $pid );
		if ( $parent_id ) {
			$val = get_post_meta( $parent_id, $meta_key, true );
			if ( $val !== '' ) { return $val; }
		}
		// fallback to regular price
		$prod = wc_get_product( $pid );
		return $prod ? (string) $prod->get_regular_price() : '';
	}
}

if ( ! function_exists( 'wcsm_thumb_url' ) ) {
	function wcsm_thumb_url( ?\WC_Product $product ): string {
		if ( ! $product ) { return ''; }
		$id = $product->get_image_id();
		if ( ! $id ) { return ''; }
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		return $url ?: '';
	}
}

$items_by_supplier = Utils::group_items_by_supplier( $order );
$items             = $items_by_supplier[ $supplier_id ] ?? [];
$number            = $order->get_order_number();
$date_str          = $order->get_date_created() ? $order->get_date_created()->date_i18n( wc_date_format() . ' ' . wc_time_format() ) : '';
$shop_name         = get_bloginfo('name');
$ship_address      = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
$currency          = $order->get_currency();

$ff_all            = Utils::get_fulfilment( $order );
$mine              = $ff_all[ $supplier_id ] ?? [ 'status' => 'pending', 'tracking' => ['carrier'=>'','number'=>'','url'=>'','notes'=>''] ];
$tracking          = $mine['tracking'] ?? ['carrier'=>'','number'=>'','url'=>'','notes'=>''];

$status_label_map  = [
	'pending'  => __('Pending', 'wc-supplier-manager'),
	'received' => __('Received', 'wc-supplier-manager'),
	'sent'     => __('Sent', 'wc-supplier-manager'),
	'rejected' => __('Rejected', 'wc-supplier-manager'),
];


// compute grand total for supplier (sum of supplier_price * qty)
$grand_total = 0.0;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title><?php printf( esc_html__( 'Packing slip #%s (Supplier %d)', 'wc-supplier-manager' ), esc_html( $number ), (int) $supplier_id ); ?></title>
<style>
	body { font-family: -apple-system, system-ui, Segoe UI, Roboto, Arial, sans-serif; font-size: 13px; color: #111; }
	h1,h2,h3 { margin: 0 0 8px; }
	.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
	.header .shop { font-size: 18px; font-weight: 600; }
	.meta { font-size: 12px; color:#555; }
	.grid { display: table; width: 100%; table-layout: fixed; margin-top: 8px; }
	.col { display: table-cell; vertical-align: top; padding-right: 12px; }
	.box { border: 1px solid #ddd; padding: 8px; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
	table.items th, table.items td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
	table.items th { background: #f6f6f6; }
	.small { font-size: 12px; color:#555; }
	.note { margin-top: 10px; font-size:12px; color:#444; }
	hr { border: 0; border-top: 1px solid #e5e5e5; margin: 12px 0; }
	.flex { display:flex; align-items:flex-start; gap:8px; }
	.thumb { width:48px; height:48px; object-fit:cover; border:1px solid #e5e5e5; }
	.attrs { margin:4px 0 0 0; padding-left:16px; }
	.right { text-align:right; }
	.totals { margin-top: 12px; width: 100%; border-collapse: collapse; }
	.totals td { padding: 6px 8px; }
	.totals .label { text-align: right; font-weight: 600; }
</style>
</head>
<body>

<div class="header">
	<div class="shop"><?php echo esc_html( $shop_name ); ?></div>
	<div class="meta">
		<strong><?php printf( esc_html__( 'Packing slip #%s', 'wc-supplier-manager' ), esc_html( $number ) ); ?></strong><br/>
		<?php echo esc_html( $date_str ); ?>
	</div>
</div>

<div class="grid">
	<div class="col" style="width:50%;">
		<div class="box">
			<h3><?php esc_html_e( 'Ship to', 'wc-supplier-manager' ); ?></h3>
			<div class="small">
				<?php echo wp_kses_post( $ship_address ); ?><br/>
				<?php echo esc_html( $order->get_billing_email() ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					&middot; <?php echo esc_html( $order->get_billing_phone() ); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<div class="col" style="width:50%;">
		<div class="box">
			<h3><?php esc_html_e( 'Your fulfilment', 'wc-supplier-manager' ); ?></h3>
			<div class="small">
				<?php esc_html_e( 'Status', 'wc-supplier-manager' ); ?>:
				<strong><?php echo esc_html( $status_label_map[ $mine['status'] ?? 'pending' ] ?? 'Pending' ); ?></strong><br/>
				<?php if ( ! empty( $tracking['carrier'] ) ) : ?>
					<?php esc_html_e( 'Carrier', 'wc-supplier-manager' ); ?>: <?php echo esc_html( $tracking['carrier'] ); ?><br/>
				<?php endif; ?>
				<?php if ( ! empty( $tracking['number'] ) ) : ?>
					<?php esc_html_e( 'Tracking #', 'wc-supplier-manager' ); ?>: <?php echo esc_html( $tracking['number'] ); ?><br/>
				<?php endif; ?>
				<?php if ( ! empty( $tracking['url'] ) ) : ?>
					URL: <?php echo esc_url( $tracking['url'] ); ?><br/>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<table class="items">
	<thead>
		<tr>
			<th style="width:38%;"><?php esc_html_e( 'Item', 'woocommerce' ); ?></th>
			<th style="width:12%;"><?php esc_html_e( 'SKU', 'woocommerce' ); ?></th>
			<th style="width:10%;"><?php esc_html_e( 'Qty', 'woocommerce' ); ?></th>
			<th style="width:14%;"><?php esc_html_e( 'Supplier price', 'wc-supplier-manager' ); ?></th>
			<th style="width:14%;"><?php esc_html_e( 'Line total', 'wc-supplier-manager' ); ?></th>
			<th style="width:12%;"><?php esc_html_e( 'Photo', 'wc-supplier-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $items ) ) : ?>
		<tr><td colspan="6" class="small"><?php esc_html_e( 'No items for this supplier in this order.', 'wc-supplier-manager' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $items as $item ) :
			/** @var \WC_Order_Item_Product $item */
			$prod     = $item->get_product();
			$sku      = $prod ? $prod->get_sku() : '';
			$qty      = (int) $item->get_quantity();

			// Supplier price for this product/variation
			$supp_raw = $prod ? wcsm_supplier_price_for_product_id( $prod->get_id() ) : '';
			$supp_num = $supp_raw !== '' ? (float) wc_format_decimal( $supp_raw ) : 0.0;

			$line_total = $supp_num * max( 0, $qty );
			$grand_total += $line_total;

			// Variation attributes as bullet points (under title)
			$meta_items = $item->get_formatted_meta_data( '' ); // returns WC_Meta_Data[]
			$thumb_url  = wcsm_thumb_url( $prod );
		?>
			<tr>
				<td>
					<div class="flex">
						<div>
							<strong><?php echo esc_html( $item->get_name() ); ?></strong>
							<?php if ( ! empty( $meta_items ) ) : ?>
								<ul class="attrs">
									<?php foreach ( $meta_items as $m ) : ?>
										<li><?php echo esc_html( wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</div>
				</td>
				<td><?php echo $sku ? esc_html( $sku ) : '&mdash;'; ?></td>
				<td><?php echo esc_html( $qty ); ?></td>
				<td class="right"><?php echo wc_price( $supp_num, [ 'currency' => $currency ] ); ?></td>
				<td class="right"><?php echo wc_price( $line_total, [ 'currency' => $currency ] ); ?></td>
				<td>
					<?php if ( $thumb_url ) : ?>
						<img class="thumb" src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
					<?php else : ?>
						<span class="small">&mdash;</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>

<table class="totals">
	<tr>
		<td class="label" style="width:88%;"><?php esc_html_e( 'Supplier total (to invoice admin):', 'wc-supplier-manager' ); ?></td>
		<td class="right" style="width:12%;"><strong><?php echo wc_price( $grand_total, [ 'currency' => $currency ] ); ?></strong></td>
	</tr>
</table>

<?php if ( ! empty( $tracking['notes'] ) ) : ?>
	<div class="note"><strong><?php esc_html_e( 'Notes:', 'wc-supplier-manager' ); ?></strong> <?php echo nl2br( esc_html( $tracking['notes'] ) ); ?></div>
<?php endif; ?>

</body>
</html>