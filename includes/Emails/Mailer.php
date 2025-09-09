<?php
namespace WCSM\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central mailer for supplier notifications.
 * Uses WooCommerce mailer wrapper for styling; sends via wp_mail().
 */
class Mailer {

	public static function init(): void {
		// New order -> notify each supplier involved
		add_action( 'woocommerce_new_order', [ __CLASS__, 'on_new_order' ], 999, 1 );

		// Supplier changed their fulfilment (frontend My Account)
		add_action( 'wcsm_supplier_fulfilment_changed_by_supplier', [ __CLASS__, 'on_supplier_changed' ], 10, 4 );

		// Admin changed fulfilment (order edit)
		add_action( 'wcsm_supplier_fulfilment_changed_by_admin', [ __CLASS__, 'on_admin_changed' ], 10, 4 );
	}

	/* ---------- Event callbacks ---------- */

	public static function on_new_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! class_exists( '\WCSM\Orders\Utils' ) ) {
			return;
		}

		$groups = \WCSM\Orders\Utils::group_items_by_supplier( $order );
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $supplier_id => $_items ) {
			$user = get_user_by( 'id', (int) $supplier_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$attachments = [];
			$pdf = self::maybe_generate_pdf( $order, (int) $supplier_id );
			if ( $pdf ) {
				$attachments[] = $pdf;
			}

			$subject = sprintf(
				/* translators: 1: order number */
				__( 'New order %s contains your items', 'wc-supplier-manager' ),
				$order->get_order_number()
			);

			$dashboard_url = wc_get_account_endpoint_url( 'supplier-orders' );

			$body_html = sprintf(
				'<p>%1$s</p><p><a href="%2$s" class="button" target="_blank" rel="noopener">%3$s</a></p>',
				sprintf(
					esc_html__(
						'You have new items to fulfil in order %1$s. Please review and accept/reject the order in your dashboard.',
						'wc-supplier-manager'
					),
					'<strong>#' . esc_html( $order->get_order_number() ) . '</strong>'
				),
				esc_url( $dashboard_url ),
				esc_html__( 'Open Supplier Dashboard', 'wc-supplier-manager' )
			);

			self::send_mail( $user->user_email, $subject, $body_html, $attachments );

			// Clean temp file
			if ( $pdf && file_exists( $pdf ) ) {
				@unlink( $pdf ); // phpcs:ignore
			}
		}
	}

	public static function on_supplier_changed( \WC_Order $order, int $supplier_id, array $old, array $new ): void {
		// Notify ADMIN
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}

		$diff = self::format_diff_list( $old, $new );
		$attachments = [];
		$pdf = self::maybe_generate_pdf( $order, $supplier_id );
		if ( $pdf ) {
			$attachments[] = $pdf;
		}

		$subject = sprintf(
			__( 'Supplier updated fulfilment for order %s', 'wc-supplier-manager' ),
			$order->get_order_number()
		);

		$supplier = get_user_by( 'id', $supplier_id );
		$sup_name = $supplier ? ( $supplier->display_name ?: $supplier->user_email ) : 'Supplier #' . $supplier_id;

		$body_html  = '<p>' . sprintf(
			esc_html__( '%1$s updated their fulfilment details for order %2$s.', 'wc-supplier-manager' ),
			'<strong>' . esc_html( $sup_name ) . '</strong>',
			'<strong>#' . esc_html( $order->get_order_number() ) . '</strong>'
		) . '</p>';
		$body_html .= $diff;

		self::send_mail( $admin_email, $subject, $body_html, $attachments );

		if ( $pdf && file_exists( $pdf ) ) {
			@unlink( $pdf ); // phpcs:ignore
		}
	}

	public static function on_admin_changed( \WC_Order $order, int $supplier_id, array $old, array $new ): void {
		// Notify SUPPLIER
		$user = get_user_by( 'id', $supplier_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$diff = self::format_diff_list( $old, $new );
		$attachments = [];
		$pdf = self::maybe_generate_pdf( $order, $supplier_id );
		if ( $pdf ) {
			$attachments[] = $pdf;
		}

		$subject = sprintf(
			__( 'Admin updated your fulfilment for order %s', 'wc-supplier-manager' ),
			$order->get_order_number()
		);

		$dashboard_url = wc_get_account_endpoint_url( 'supplier-orders' );

		$body_html  = '<p>' . sprintf(
			esc_html__( 'An administrator has updated your fulfilment details for order %s.', 'wc-supplier-manager' ),
			'<strong>#' . esc_html( $order->get_order_number() ) . '</strong>'
		) . '</p>';
		$body_html .= $diff;
		$body_html .= '<p><a href="' . esc_url( $dashboard_url ) . '" class="button" target="_blank" rel="noopener">' . esc_html__( 'View in Supplier Dashboard', 'wc-supplier-manager' ) . '</a></p>';

		self::send_mail( $user->user_email, $subject, $body_html, $attachments );

		if ( $pdf && file_exists( $pdf ) ) {
			@unlink( $pdf ); // phpcs:ignore
		}
	}

	/* ---------- Helpers ---------- */

	private static function maybe_generate_pdf( \WC_Order $order, int $supplier_id ): string {
		if ( ! class_exists( '\WCSM\Docs\PackingSlip' ) ) {
			return '';
		}
		try {
			return \WCSM\Docs\PackingSlip::generate_pdf( $order, $supplier_id );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Send HTML email using WC wrapper (for brand styling).
	 */
	private static function send_mail( string $to, string $subject, string $body_html, array $attachments = [] ): void {
		$mailer = wc()->mailer();

		$content = $mailer->wrap_message( $subject, $body_html );

		add_filter( 'wp_mail_content_type', [ __CLASS__, 'content_type_html' ] );
		$mailer->send( $to, $subject, $content, [], $attachments );
		remove_filter( 'wp_mail_content_type', [ __CLASS__, 'content_type_html' ] );
	}

	public static function content_type_html(): string {
		return 'text/html';
	}

	/**
	 * Produce a <table> summary of changes (old -> new).
	 * Expected array shape:
	 *   [ 'status' => 'pending', 'tracking' => ['carrier'=>'','number'=>'','url'=>'','notes'=>''] ]
	 */
	private static function format_diff_list( array $old, array $new ): string {
		$flat_old = self::flatten_ff( $old );
		$flat_new = self::flatten_ff( $new );

		$labels = [
			'status'         => __( 'Status', 'wc-supplier-manager' ),
			'carrier'        => __( 'Carrier', 'wc-supplier-manager' ),
			'number'         => __( 'Tracking #', 'wc-supplier-manager' ),
			'url'            => __( 'Tracking URL', 'wc-supplier-manager' ),
			'notes'          => __( 'Notes', 'wc-supplier-manager' ),
		];

		$rows = '';
		foreach ( $labels as $key => $label ) {
			$ov = $flat_old[ $key ] ?? '';
			$nv = $flat_new[ $key ] ?? '';
			if ( (string) $ov === (string) $nv ) {
				continue;
			}
			$rows .= '<tr><th style="text-align:left;padding:6px 8px;border:1px solid #eee;">' . esc_html( $label ) . '</th>'
				. '<td style="padding:6px 8px;border:1px solid #eee;"><em>' . esc_html( (string) $ov ) . '</em></td>'
				. '<td style="padding:6px 8px;border:1px solid #eee;"><strong>' . esc_html( (string) $nv ) . '</strong></td></tr>';
		}

		if ( '' === $rows ) {
			return '<p>' . esc_html__( 'No visible changes.', 'wc-supplier-manager' ) . '</p>';
		}

		return '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #eee;margin:8px 0 12px;">'
			. '<thead><tr>'
			. '<th style="text-align:left;padding:6px 8px;border:1px solid #eee;">' . esc_html__( 'Field', 'wc-supplier-manager' ) . '</th>'
			. '<th style="text-align:left;padding:6px 8px;border:1px solid #eee;">' . esc_html__( 'Old', 'wc-supplier-manager' ) . '</th>'
			. '<th style="text-align:left;padding:6px 8px;border:1px solid #eee;">' . esc_html__( 'New', 'wc-supplier-manager' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	private static function flatten_ff( array $rec ): array {
		$out = [
			'status' => $rec['status'] ?? '',
			'carrier' => '',
			'number'  => '',
			'url'     => '',
			'notes'   => '',
		];
		if ( ! empty( $rec['tracking'] ) && is_array( $rec['tracking'] ) ) {
			$out['carrier'] = $rec['tracking']['carrier'] ?? '';
			$out['number']  = $rec['tracking']['number'] ?? '';
			$out['url']     = $rec['tracking']['url'] ?? '';
			$out['notes']   = $rec['tracking']['notes'] ?? '';
		}
		return $out;
	}
}