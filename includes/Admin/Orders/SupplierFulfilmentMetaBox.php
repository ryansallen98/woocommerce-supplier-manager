<?php
namespace WCSM\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin meta box: Supplier fulfilment (per supplier) with override fields.
 * - Uses \WCSM\Orders\Utils::{get_fulfilment,update_fulfilment_for_supplier} so it's in sync
 *   with the supplier dashboard (same meta key/structure).
 * - Works with classic orders and HPOS.
 * - No $order->save() to avoid recursion/memory spikes.
 * - Sends emails on admin overrides (supplier + admin), with optional packing slip PDF.
 */
class SupplierFulfilmentMetaBox {

	const ID    = 'wcsm_supplier_fulfilment';
	const TITLE = 'Supplier Fulfilment';
	const NONCE = 'wcsm_admin_ff';

	private static $saving = false;

	public static function init(): void {
		// Show box (classic + HPOS)
		add_action( 'add_meta_boxes_shop_order',                 [ __CLASS__, 'register_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_box_hpos' ] );
		add_action( 'add_meta_boxes',                            [ __CLASS__, 'register_box_generic' ], 10, 2 );

		// Save (HPOS & classic main submit)
		add_action( 'woocommerce_process_shop_order_meta',       [ __CLASS__, 'save_from_process_meta' ], 9, 2 );

		// Classic fallback
		add_action( 'save_post_shop_order',                      [ __CLASS__, 'save_from_post' ], 10, 2 );
	}

	/** Put the box in the “normal/low” area (right under the product/items box). */
	public static function register_box(): void {
		if ( ! self::can_view_box() ) {
			return;
		}
		add_meta_box(
			self::ID,
			__( self::TITLE, 'wc-supplier-manager' ),
			[ __CLASS__, 'render' ],
			'shop_order',
			'normal',
			'low'
		);
	}

	public static function register_box_hpos(): void {
		if ( ! self::can_view_box() ) {
			return;
		}
		add_meta_box(
			self::ID,
			__( self::TITLE, 'wc-supplier-manager' ),
			[ __CLASS__, 'render' ],
			'woocommerce_page_wc-orders',
			'normal',
			'low'
		);
	}

	public static function register_box_generic( $screen_id, $obj = null ): void {
		if ( ! self::can_view_box() ) {
			return;
		}
		if ( 'shop_order' === $screen_id ) {
			self::register_box();
		} elseif ( 'woocommerce_page_wc-orders' === $screen_id ) {
			self::register_box_hpos();
		}
	}

	private static function can_view_box(): bool {
		return is_admin() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Render the meta box. Accepts WP_Post (classic) or Order-like (HPOS) object.
	 *
	 * @param mixed $object
	 */
	public static function render( $object ): void {
		$order_id = 0;

		if ( $object instanceof \WP_Post ) {
			$order_id = (int) $object->ID;
		} elseif ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
			$order_id = (int) $object->get_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['id'] ) ) {
			$order_id = (int) $_GET['id'];
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order could not be loaded.', 'wc-supplier-manager' ) . '</p>';
			return;
		}
		if ( ! class_exists( '\WCSM\Orders\Utils' ) ) {
			echo '<p>' . esc_html__( 'Supplier data unavailable.', 'wc-supplier-manager' ) . '</p>';
			return;
		}

		// Read fulfilment via Utils (same as supplier dashboard).
		$ff     = \WCSM\Orders\Utils::get_fulfilment( $order ); // [supplier_id => ['status'=>..,'tracking'=>..]]
		$groups = \WCSM\Orders\Utils::group_items_by_supplier( $order ); // [supplier_id => WC_Order_Item_Product[]]

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		if ( empty( $groups ) ) {
			echo '<p>' . esc_html__( 'No supplier-linked items in this order.', 'wc-supplier-manager' ) . '</p>';
			return;
		}

		$statuses = [
			'pending'  => __( 'Pending', 'wc-supplier-manager' ),
			'received' => __( 'Received', 'wc-supplier-manager' ),
			'sent'     => __( 'Sent for delivery', 'wc-supplier-manager' ),
			'rejected' => __( 'Rejected', 'wc-supplier-manager' ),
		];

		echo '<div class="wcsm-supplier-fulfilment" style="display:flex;flex-direction:column;gap:12px;">';

		foreach ( $groups as $supplier_id => $items ) {
			$supplier_id = (int) $supplier_id;
			$user        = get_user_by( 'id', $supplier_id );
			$sup_name    = $user ? ( $user->display_name ?: $user->user_email ) : sprintf( __( 'Supplier #%d', 'wc-supplier-manager' ), $supplier_id );

			$rec      = $ff[ $supplier_id ] ?? [];
			$status   = $rec['status'] ?? 'pending';
			$tracking = $rec['tracking'] ?? [];
			$carrier  = $tracking['carrier'] ?? '';
			$number   = $tracking['number'] ?? '';
			$url      = $tracking['url'] ?? '';
			$notes    = $tracking['notes'] ?? '';

			echo '<div class="wcsm-supplier-box" style="border:1px solid #e5e5e5;padding:10px;">';

			// Header
			echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
			echo '<strong>' . esc_html( $sup_name ) . '</strong>';
			echo '<span class="description">' . esc_html( sprintf( _n( '%d item', '%d items', count( $items ), 'wc-supplier-manager' ), count( $items ) ) ) . '</span>';
			echo '</div>';

			// Items table (brief)
			echo '<table class="widefat striped" style="margin:6px 0 12px;">';
			echo '<thead><tr>';
			echo '<th style="width:55%;">' . esc_html__( 'Item', 'woocommerce' ) . '</th>';
			echo '<th style="width:20%;">' . esc_html__( 'SKU', 'woocommerce' ) . '</th>';
			echo '<th style="width:15%;">' . esc_html__( 'Qty', 'woocommerce' ) . '</th>';
			echo '<th style="width:10%;">' . esc_html__( 'Supplier price', 'wc-supplier-manager' ) . '</th>';
			echo '</tr></thead><tbody>';

			$total_cost = 0.0;

			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product   = $item->get_product();
				$name      = $item->get_name();
				$qty       = (int) $item->get_quantity();
				$sku       = $product ? $product->get_sku() : '';
				$supp_cost = 0;

				if ( $product ) {
					$supp_meta = $product->get_meta( '_wcsm_supplier_price', true );
					$supp_cost = $supp_meta === '' ? (float) $product->get_regular_price() : (float) $supp_meta;
				}

				$total_cost += (float) $supp_cost * (float) $qty;

				echo '<tr>';
				echo '<td>' . esc_html( $name );
				if ( $product && $product->is_type( 'variation' ) ) {
					$atts = $product->get_attributes();
					if ( ! empty( $atts ) ) {
						echo '<ul style="margin:.25em 0 0 .9em;padding:0;list-style:disc;">';
						foreach ( $atts as $k => $v ) {
							$label = wc_attribute_label( str_replace( 'attribute_', '', $k ) );
							echo '<li><small>' . esc_html( $label ) . ': ' . esc_html( (string) $v ) . '</small></li>';
						}
						echo '</ul>';
					}
				}
				echo '</td>';
				echo '<td>' . ( $sku ? esc_html( $sku ) : '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( $qty ) . '</td>';
				echo '<td>' . ( $supp_cost !== '' ? wc_price( $supp_cost ) : '&mdash;' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			// Override fields (admin)
			echo '<div class="wcsm-fields" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';

			echo '<p><label for="wcsm_ff_' . esc_attr( $supplier_id ) . '_status"><strong>' . esc_html__( 'Status', 'wc-supplier-manager' ) . '</strong></label><br/>';
			echo '<select name="wcsm_admin_ff[' . esc_attr( $supplier_id ) . '][status]" id="wcsm_ff_' . esc_attr( $supplier_id ) . '_status">';
			foreach ( $statuses as $val => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $val ),
					selected( $status, $val, false ),
					esc_html( $label )
				);
			}
			echo '</select></p>';

			echo '<p><label for="wcsm_ff_' . esc_attr( $supplier_id ) . '_carrier"><strong>' . esc_html__( 'Carrier', 'wc-supplier-manager' ) . '</strong></label><br/>';
			echo '<input type="text" class="regular-text" name="wcsm_admin_ff[' . esc_attr( $supplier_id ) . '][carrier]" id="wcsm_ff_' . esc_attr( $supplier_id ) . '_carrier" value="' . esc_attr( $carrier ) . '"/></p>';

			echo '<p><label for="wcsm_ff_' . esc_attr( $supplier_id ) . '_number"><strong>' . esc_html__( 'Tracking number', 'wc-supplier-manager' ) . '</strong></label><br/>';
			echo '<input type="text" class="regular-text" name="wcsm_admin_ff[' . esc_attr( $supplier_id ) . '][number]" id="wcsm_ff_' . esc_attr( $supplier_id ) . '_number" value="' . esc_attr( $number ) . '"/></p>';

			echo '<p><label for="wcsm_ff_' . esc_attr( $supplier_id ) . '_url"><strong>' . esc_html__( 'Tracking URL', 'wc-supplier-manager' ) . '</strong></label><br/>';
			echo '<input type="url" class="regular-text" name="wcsm_admin_ff[' . esc_attr( $supplier_id ) . '][url]" id="wcsm_ff_' . esc_attr( $supplier_id ) . '_url" value="' . esc_attr( $url ) . '"/></p>';

			echo '<p style="grid-column:1/-1;"><label for="wcsm_ff_' . esc_attr( $supplier_id ) . '_notes"><strong>' . esc_html__( 'Notes', 'wc-supplier-manager' ) . '</strong></label><br/>';
			echo '<textarea name="wcsm_admin_ff[' . esc_attr( $supplier_id ) . '][notes]" id="wcsm_ff_' . esc_attr( $supplier_id ) . '_notes" rows="3" style="width:100%;">' . esc_textarea( $notes ) . '</textarea></p>';

			echo '</div>';

			echo '<p style="margin-top:6px;"><strong>' . esc_html__( 'Supplier subtotal (for reference):', 'wc-supplier-manager' ) . '</strong> ' . wc_price( $total_cost ) . '</p>';

			echo '</div>'; // .wcsm-supplier-box
		}

		echo '<p class="description" style="margin-top:4px;">' .
			esc_html__( 'Use these fields to override supplier-provided fulfilment details.', 'wc-supplier-manager' ) .
		'</p>';

		echo '</div>';
	}

	/* ===== Saving (no recursion) ===== */

	public static function save_from_process_meta( $post_id, $order ): void {
		if ( self::$saving ) return;
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $post_id );
		}
		if ( ! $order ) return;

		self::$saving = true;
		self::maybe_save_from_request( $order );
		self::$saving = false;
	}

	public static function save_from_post( int $post_id, \WP_Post $post ): void {
		if ( self::$saving ) return;
		if ( 'shop_order' !== $post->post_type ) return;
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;

		$order = wc_get_order( $post_id );
		if ( ! $order ) return;

		self::$saving = true;
		self::maybe_save_from_request( $order );
		self::$saving = false;
	}

	/**
	 * Persist changes via Utils::update_fulfilment_for_supplier (the same writer used by the supplier dashboard).
	 * Also emails supplier + admin about the changes. No $order->save().
	 */
	private static function maybe_save_from_request( \WC_Order $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ), self::NONCE ) ) {
			return;
		}
		if ( ! class_exists( '\WCSM\Orders\Utils' ) ) {
			return;
		}

		$old_ff = \WCSM\Orders\Utils::get_fulfilment( $order );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data = isset( $_POST['wcsm_admin_ff'] ) ? (array) $_POST['wcsm_admin_ff'] : [];
		if ( empty( $data ) ) {
			return;
		}

		$all_changes = [];

		foreach ( $data as $supplier_id => $fields ) {
			$supplier_id = (int) $supplier_id;

			$old_rec   = $old_ff[ $supplier_id ] ?? [];
			$old_stat  = $old_rec['status'] ?? 'pending';
			$old_track = $old_rec['tracking'] ?? [ 'carrier' => '', 'number' => '', 'url' => '', 'notes' => '' ];

			$status = isset( $fields['status'] ) ? sanitize_text_field( $fields['status'] ) : 'pending';
			if ( ! in_array( $status, [ 'pending', 'received', 'sent', 'rejected' ], true ) ) {
				$status = 'pending';
			}
			$carrier = isset( $fields['carrier'] ) ? sanitize_text_field( $fields['carrier'] ) : '';
			$number  = isset( $fields['number'] )  ? sanitize_text_field( $fields['number'] )  : '';
			$url     = isset( $fields['url'] )     ? esc_url_raw( $fields['url'] )             : '';
			$notes   = isset( $fields['notes'] )   ? sanitize_textarea_field( $fields['notes'] ) : '';

			// Persist via Utils (same path as supplier dashboard)
			\WCSM\Orders\Utils::update_fulfilment_for_supplier( $order, $supplier_id, [
				'status'  => $status,
				'carrier' => $carrier,
				'number'  => $number,
				'url'     => $url,
				'notes'   => $notes,
			] );

			// Diff for emails
			$changes = [];
			if ( $status !== $old_stat ) {
				$changes[] = sprintf( __( 'Status: %s → %s', 'wc-supplier-manager' ), $old_stat, $status );
			}
			foreach ( [ 'carrier', 'number', 'url', 'notes' ] as $k ) {
				$old_v = (string) ( $old_track[ $k ] ?? '' );
				$new_v = (string) ( ${$k} );
				if ( $old_v !== $new_v ) {
					$label     = ucfirst( $k );
					$changes[] = sprintf( '%s: %s → %s', $label, $old_v !== '' ? $old_v : '—', $new_v !== '' ? $new_v : '—' );
				}
			}
			if ( ! empty( $changes ) ) {
				$all_changes[ $supplier_id ] = $changes;
			}
		}

		// Notify if changed
		if ( ! empty( $all_changes ) ) {
			foreach ( $all_changes as $supplier_id => $changes ) {
				self::email_supplier_about_admin_override( $order, (int) $supplier_id, $changes );
			}
			self::email_admin_summary( $order, $all_changes );
		}
	}

	/* ===== Emails ===== */

	private static function email_supplier_about_admin_override( \WC_Order $order, int $supplier_id, array $changes ): void {
		$user = get_user_by( 'id', $supplier_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$mailer        = wc()->mailer();
		$to            = $user->user_email;
		$subject       = sprintf( __( '[%s] Fulfilment updated by admin for order #%s', 'wc-supplier-manager' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
		$dashboard_url = wc_get_account_endpoint_url( 'supplier-orders' );

		$body_txt = sprintf(
			/* translators: 1: supplier name, 2: order number, 3: bullet list, 4: link */
			__( "Hello %1\$s,\n\nThe store admin changed your fulfilment details for order #%2\$s.\n\nChanges:\n- %3\$s\n\nReview in your dashboard:\n%4\$s\n", 'wc-supplier-manager' ),
			$user->display_name ?: $user->user_login,
			$order->get_order_number(),
			implode( "\n- ", $changes ),
			$dashboard_url
		);

		$body_html = wpautop( esc_html( $body_txt ) );

		$attachments = [];
		$slip = self::maybe_generate_packing_slip_pdf( $order, $supplier_id );
		if ( $slip && file_exists( $slip ) ) {
			$attachments[] = $slip;
		}

		$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $body_html ), '', $attachments );

		if ( $slip && file_exists( $slip ) ) {
			@unlink( $slip );
		}
	}

	private static function email_admin_summary( \WC_Order $order, array $all_changes ): void {
		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}
		$mailer  = wc()->mailer();
		$subject = sprintf( __( '[%s] Supplier fulfilment overrides saved for order #%s', 'wc-supplier-manager' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );

		$lines = [];
		foreach ( $all_changes as $supplier_id => $changes ) {
			$user  = get_user_by( 'id', (int) $supplier_id );
			$label = $user ? ( $user->display_name ?: $user->user_email ) : sprintf( __( 'Supplier #%d', 'wc-supplier-manager' ), (int) $supplier_id );
			$lines[] = $label . ":\n- " . implode( "\n- ", $changes );
		}

		$body_txt  = "The following supplier fulfilment overrides were saved:\n\n" . implode( "\n\n", $lines ) . "\n\n";
		$body_txt .= sprintf( "Order: #%s\nOrder link: %s\n", $order->get_order_number(), admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ) );

		$body_html = wpautop( esc_html( $body_txt ) );

		$mailer->send( $admin_email, $subject, $mailer->wrap_message( $subject, $body_html ) );
	}

	/**
	 * Optional PDF attach (requires \WCSM\Docs\PackingSlip::render_pdf_for_supplier).
	 */
	private static function maybe_generate_packing_slip_pdf( \WC_Order $order, int $supplier_id ): string {
		if ( ! class_exists( '\WCSM\Docs\PackingSlip' ) ) {
			return '';
		}
		if ( ! method_exists( '\WCSM\Docs\PackingSlip', 'render_pdf_for_supplier' ) ) {
			return '';
		}
		$pdf_bytes = \WCSM\Docs\PackingSlip::render_pdf_for_supplier( $order, $supplier_id );
		if ( empty( $pdf_bytes ) ) {
		 return '';
		}
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'wcsm_tmp/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = $dir . sprintf( 'packing-slip-%s-supplier-%d.pdf', $order->get_order_number(), $supplier_id );
		file_put_contents( $file, $pdf_bytes );
		return $file;
	}
}