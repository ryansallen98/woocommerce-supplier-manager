<?php
/**
 * One order row (with inline form)
 *
 * @var \WC_Order $order
 * @var int       $supplier_id
 */
defined( 'ABSPATH' ) || exit;

use WCSM\Orders\Utils;

$order_id = $order->get_id();
$number   = $order->get_order_number();
$date     = $order->get_date_created() ? $order->get_date_created()->date_i18n( wc_date_format() . ' ' . wc_time_format() ) : '';
$name     = trim( $order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name() );
$groups   = Utils::group_items_by_supplier( $order );
$items    = $groups[ $supplier_id ] ?? [];

$ff       = Utils::get_fulfilment( $order );
$mine     = $ff[ $supplier_id ] ?? [ 'status' => 'pending', 'tracking' => [ 'carrier'=>'', 'number'=>'', 'url'=>'', 'notes'=>'' ] ];

$slip_url = add_query_arg( [
	'wcsm_packing_slip' => 1,
	'order_id'          => $order_id,
	'_wpnonce'          => wp_create_nonce( 'wcsm_download_slip' ),
], wc_get_account_endpoint_url( \WCSM\Accounts\SupplierOrdersEndpoint::ENDPOINT ) );

$status_opts = [
	'pending'  => __( 'Pending',  'wc-supplier-manager' ),
	'received' => __( 'Received', 'wc-supplier-manager' ),
	'sent'     => __( 'Sent',     'wc-supplier-manager' ),
	'rejected' => __( 'Rejected', 'wc-supplier-manager' ),
];
?>
<tr>
	<td>
		<span>#<?php echo esc_html( $number ); ?></span>
	</td>
	<td><?php echo esc_html( $date ); ?></td>
	<td><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></td>
	<td>
		<ul style="margin:0; padding-left:1rem;">
			<?php foreach ( $items as $item ) :
				$meta = wc_display_item_meta( $item, [ 'echo' => false ] );
				?>
				<li>
					<?php echo esc_html( $item->get_name() ) . ' × ' . esc_html( $item->get_quantity() ); ?>
					<?php if ( $meta ) : ?><div class="wcsm-item-meta"><?php echo wp_kses_post( $meta ); ?></div><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</td>
	<td>
		<strong><?php echo esc_html( $status_opts[ $mine['status'] ?? 'pending' ] ?? 'Pending' ); ?></strong>
	</td>
	<td>
		<a class="button" href="<?php echo esc_url( $slip_url ); ?>"><?php esc_html_e( 'Packing slip', 'wc-supplier-manager' ); ?></a>
		<button type="button" class="button wcsm-toggle" aria-expanded="false" data-target="#wcsm-order-form-<?php echo esc_attr( $order_id ); ?>">
			<?php esc_html_e( 'Update', 'wc-supplier-manager' ); ?>
		</button>
	</td>
</tr>
<tr id="wcsm-order-form-<?php echo esc_attr( $order_id ); ?>" class="wcsm-order-edit" style="display:none;" aria-hidden="true">
	<td colspan="6">
		<form method="post" action="<?php echo esc_url( wc_get_account_endpoint_url( \WCSM\Accounts\SupplierOrdersEndpoint::ENDPOINT ) ); ?>">
			<?php wp_nonce_field( 'wcsm_supplier_orders' ); ?>
			<input type="hidden" name="wcsm_so_action" value="update" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />

			<p>
				<label>
					<span class="title"><?php esc_html_e( 'Your status', 'wc-supplier-manager' ); ?></span>
					<select name="wcsm_status">
						<?php foreach ( $status_opts as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $mine['status'] ?? 'pending', $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>

			<p style="display:flex; gap:1rem; flex-wrap:wrap;">
				<label>
					<span class="title"><?php esc_html_e( 'Carrier', 'wc-supplier-manager' ); ?></span>
					<input type="text" name="wcsm_carrier" value="<?php echo esc_attr( $mine['tracking']['carrier'] ?? '' ); ?>" />
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Tracking number', 'wc-supplier-manager' ); ?></span>
					<input type="text" name="wcsm_tracking" value="<?php echo esc_attr( $mine['tracking']['number'] ?? '' ); ?>" />
				</label>
				<label style="flex:1 1 260px;">
					<span class="title"><?php esc_html_e( 'Tracking URL', 'wc-supplier-manager' ); ?></span>
					<input type="url" name="wcsm_url" value="<?php echo esc_url( $mine['tracking']['url'] ?? '' ); ?>" />
				</label>
			</p>

			<p>
				<label style="width:100%;">
					<span class="title"><?php esc_html_e( 'Notes (optional, required if rejecting)', 'wc-supplier-manager' ); ?></span>
					<textarea name="wcsm_notes" rows="3" style="width:100%;"><?php echo esc_textarea( $mine['tracking']['notes'] ?? '' ); ?></textarea>
				</label>
			</p>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wc-supplier-manager' ); ?></button>
			</p>
		</form>
	</td>
</tr>