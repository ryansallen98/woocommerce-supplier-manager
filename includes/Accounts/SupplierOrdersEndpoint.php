<?php
namespace WCSM\Accounts;

use WCSM\Support\TemplateLoader;
use WCSM\Orders\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SupplierOrdersEndpoint {
	const ENDPOINT = 'supplier-orders';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 6 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ __CLASS__, 'render_endpoint' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_download_packing_slip' ] );
	}

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function add_menu_item( array $items ): array {
		if ( ! self::current_user_is_supplierish() ) {
			return $items;
		}
		$label = __( 'Supplier orders', 'wc-supplier-manager' );

		$new      = [];
		$inserted = false;
		foreach ( $items as $key => $text ) {
			$new[ $key ] = $text;
			if ( ( 'dashboard' === $key || 'supplier-products' === $key ) && ! $inserted ) {
				$new[ self::ENDPOINT ] = $label;
				$inserted              = true;
			}
		}
		if ( ! $inserted ) {
			$new[ self::ENDPOINT ] = $label;
		}
		return $new;
	}

	public static function render_endpoint(): void {
		if ( ! self::current_user_is_supplierish() ) {
			wc_print_notice( __( 'You do not have permission to view supplier orders.', 'wc-supplier-manager' ), 'error' );
			return;
		}
		$supplier_id = get_current_user_id();

		// Handle POST first (update fulfilment for this supplier).
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_post_update( (int) $supplier_id );
		}

		// ---------- Controls ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw     = wp_unslash( $_GET );
		$status  = isset( $raw['status'] ) ? sanitize_text_field( $raw['status'] ) : 'any'; // any|pending|received|sent|rejected
		$paged   = isset( $raw['paged'] ) ? max( 1, (int) $raw['paged'] ) : 1;
		$perpage = isset( $raw['per_page'] ) ? max( 1, min( 50, (int) $raw['per_page'] ) ) : 10;
		$q       = isset( $raw['wcsm_q'] ) ? sanitize_text_field( $raw['wcsm_q'] ) : '';

		// Date range (strict YYYY-MM-DD); force single strings even if arrays were sent.
		$from_raw = $raw['wcsm_from'] ?? '';
		$to_raw   = $raw['wcsm_to'] ?? '';

		if ( is_array( $from_raw ) ) { $from_raw = reset( $from_raw ); }
		if ( is_array( $to_raw ) )   { $to_raw   = reset( $to_raw ); }

		$from = ( is_string( $from_raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) ) ? $from_raw : '';
		$to   = ( is_string( $to_raw )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_raw ) )   ? $to_raw   : '';

		// Build a date_query for HPOS-compatible ranges.
		$date_query = [];
		if ( $from ) {
			$date_query[] = [
				'column'    => 'date_created',     // or 'date_created_gmt'
				'after'     => $from . ' 00:00:00',
				'inclusive' => true,
			];
		}
		if ( $to ) {
			$date_query[] = [
				'column'    => 'date_created',
				'before'    => $to . ' 23:59:59',
				'inclusive' => true,
			];
		}

		// ---------- Order query base ----------
		$args = [
			'limit'      => $perpage,
			'paginate'   => true,
			'paged'      => $paged,
			'type'       => 'shop_order',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_query' => [
				[
					'key'     => Utils::META_ORDER_SUPPLIERS,
					'value'   => ',' . $supplier_id . ',',
					'compare' => 'LIKE',
				],
			],
			'orderby'    => 'date',
			'order'      => 'DESC',
		];

		if ( ! empty( $date_query ) ) {
			$args['date_query'] = array_merge( [ 'relation' => 'AND' ], $date_query );
		}

		// ----- Search: build matching IDs (fields + line items), then manual paginate -----
		$matching_ids = null;

		if ( '' !== $q ) {
			$needle = ltrim( trim( $q ), '#' );

			// A) IDs that match order fields (respect supplier/date filter)
			$field_search_args = $args;
			$field_search_args['limit']          = -1;
			$field_search_args['paginate']       = false;
			$field_search_args['return']         = 'ids';
			$field_search_args['search']         = $needle;
			$field_search_args['search_columns'] = [
				'id',
				'billing_first_name',
				'billing_last_name',
				'billing_email',
				'billing_company',
				'billing_phone',
				'order_key',
			];
			$field_ids = wc_get_orders( $field_search_args );

			// B) IDs that match line-item names
			global $wpdb;
			$like  = '%' . $wpdb->esc_like( $needle ) . '%';
			$table = $wpdb->prefix . 'woocommerce_order_items';
			$item_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_id
					 FROM {$table}
					 WHERE order_item_type = 'line_item'
					   AND order_item_name LIKE %s",
					$like
				)
			);

			// Candidate IDs respecting supplier/date (cheap ids-only query)
			$candidate_args = $args;
			$candidate_args['limit']    = -1;
			$candidate_args['paginate'] = false;
			$candidate_args['return']   = 'ids';
			unset( $candidate_args['orderby'], $candidate_args['order'] );
			$candidate_ids = wc_get_orders( $candidate_args );

			// Union (fields ∪ items) ∩ candidates
			$union_ids    = array_unique( array_map( 'intval', array_merge( $field_ids ?: [], $item_ids ?: [] ) ) );
			$matching_ids = array_values( array_intersect( $candidate_ids ?: [], $union_ids ) );

			if ( empty( $matching_ids ) ) {
				$matching_ids = [ 0 ]; // short-circuit to empty
			}
		}

		// ----- Final fetch -----
		if ( $matching_ids !== null ) {
			// Order all matching by date desc
			$all_orders = wc_get_orders( [
				'include'  => $matching_ids,
				'limit'    => -1,
				'paginate' => false,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'type'     => 'shop_order',
			] );
			$ordered_ids = array_map( static function( $o ) { return (int) $o->get_id(); }, $all_orders );

			// Manual pagination
			$total     = count( $ordered_ids );
			$max_pages = max( 1, (int) ceil( $total / $perpage ) );
			$offset    = ( $paged - 1 ) * $perpage;
			$page_ids  = array_slice( $ordered_ids, $offset, $perpage );

			$orders = wc_get_orders( [
				'include'  => $page_ids ?: [ 0 ],
				'limit'    => -1,
				'paginate' => false,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'type'     => 'shop_order',
			] );

		} else {
			// No search term -> normal paginated query
			$results   = wc_get_orders( $args );
			$orders    = is_array( $results ) ? $results : ( $results->orders ?? [] );
			$total     = is_array( $results ) ? count( $orders ) : ( $results->total ?? 0 );
			$max_pages = is_array( $results ) ? 1 : ( $results->max_num_pages ?? 1 );
		}

		// If nothing found, backfill recent orders to ensure index meta exists, then re-run once.
		if ( empty( $orders ) ) {
			self::backfill_recent_orders_for_supplier( $supplier_id, 120 );

			if ( $matching_ids !== null ) {
				$all_orders = wc_get_orders( [
					'include'  => $matching_ids,
					'limit'    => -1,
					'paginate' => false,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'type'     => 'shop_order',
				] );
				$ordered_ids = array_map( static function( $o ) { return (int) $o->get_id(); }, $all_orders );
				$total       = count( $ordered_ids );
				$max_pages   = max( 1, (int) ceil( $total / $perpage ) );
				$offset      = ( $paged - 1 ) * $perpage;
				$page_ids    = array_slice( $ordered_ids, $offset, $perpage );

				$orders = wc_get_orders( [
					'include'  => $page_ids ?: [ 0 ],
					'limit'    => -1,
					'paginate' => false,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'type'     => 'shop_order',
				] );
			} else {
				$results   = wc_get_orders( $args );
				$orders    = is_array( $results ) ? $results : ( $results->orders ?? [] );
				$total     = is_array( $results ) ? count( $orders ) : ( $results->total ?? 0 );
				$max_pages = is_array( $results ) ? 1 : ( $results->max_num_pages ?? 1 );
			}
		}

		// Filter by supplier fulfilment status if requested (pending/received/sent/rejected).
		if ( in_array( $status, [ 'pending', 'received', 'sent', 'rejected' ], true ) ) {
			$orders = array_values(
				array_filter(
					$orders,
					function ( $order ) use ( $supplier_id, $status ) {
						$ff = Utils::get_fulfilment( $order );
						if ( isset( $ff[ $supplier_id ]['status'] ) ) {
							return $ff[ $supplier_id ]['status'] === $status;
						}
						// no record = treat as pending
						return 'pending' === $status;
					}
				)
			);
		}

		// Build pagination base
		$endpoint_url = wc_get_account_endpoint_url( self::ENDPOINT );
		$base_url     = add_query_arg(
			array_filter(
				[
					'status'    => ( 'any' !== $status ) ? $status : null,
					'wcsm_q'    => ( '' !== $q ) ? $q : null,
					'per_page'  => ( 10 !== $perpage ) ? $perpage : null,
					'wcsm_from' => ( '' !== $from ) ? $from : null,
					'wcsm_to'   => ( '' !== $to ) ? $to : null,
				]
			),
			$endpoint_url
		);

		TemplateLoader::get(
			'myaccount/supplier-orders.php',
			[
				'orders'       => $orders,
				'supplier_id'  => $supplier_id,
				'controls'     => [
					'status'    => $status,
					'wcsm_q'    => $q,
					'per_page'  => $perpage,
					'wcsm_from' => $from,
					'wcsm_to'   => $to,
				],
				'pagination'   => [
					'current'    => $paged,
					'total'      => $max_pages ?? 1,
					'base'       => $base_url,
					'total_items'=> isset( $total ) ? (int) $total : 0,
				],
			]
		);
	}

	private static function current_user_is_supplierish(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( \WCSM\Setup\Roles\SupplierRole::CAP_SUPPLIER );
	}

	private static function product_belongs_to_supplier( int $product_id, int $supplier_id ): bool {
		$parent_id = (int) wp_get_post_parent_id( $product_id );
		$check_id  = $parent_id ? $parent_id : $product_id;
		return (int) get_post_meta( $check_id, '_wcsm_supplier_id', true ) === $supplier_id;
	}

	private static function handle_post_update( int $supplier_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['wcsm_so_action'] ) || 'update' !== $_POST['wcsm_so_action'] ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wcsm_supplier_orders' ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'wc-supplier-manager' ), 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( ! \WCSM\Orders\Utils::order_has_supplier( $order, $supplier_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wc_add_notice( __( 'You cannot modify this order.', 'wc-supplier-manager' ), 'error' );
			return;
		}

		// Allowed statuses (includes 'rejected').
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$allowed_status = [ 'pending', 'received', 'sent', 'rejected' ];
		$status = isset( $_POST['wcsm_status'] ) ? sanitize_text_field( $_POST['wcsm_status'] ) : 'pending';
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'pending';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$carrier = isset( $_POST['wcsm_carrier'] ) ? sanitize_text_field( $_POST['wcsm_carrier'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$number  = isset( $_POST['wcsm_tracking'] ) ? sanitize_text_field( $_POST['wcsm_tracking'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url     = isset( $_POST['wcsm_url'] ) ? esc_url_raw( $_POST['wcsm_url'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$notes   = isset( $_POST['wcsm_notes'] ) ? sanitize_textarea_field( $_POST['wcsm_notes'] ) : '';

		/**
		 * >>> EMAIL TRIGGER SUPPORT <<<
		 * Capture old state for this supplier, perform update, capture new state,
		 * and fire an action so the Mailer can notify admins and attach the PDF.
		 */
		$before_all = \WCSM\Orders\Utils::get_fulfilment( $order );
		$old_for_me = $before_all[ $supplier_id ] ?? [];

		\WCSM\Orders\Utils::update_fulfilment_for_supplier(
			$order,
			$supplier_id,
			[
				'status'   => $status,
				'carrier'  => $carrier,
				'number'   => $number,
				'url'      => $url,
				'notes'    => $notes,
			]
		);

		$after_all = \WCSM\Orders\Utils::get_fulfilment( $order );
		$new_for_me = $after_all[ $supplier_id ] ?? [];

		/**
		 * Fire: supplier changed their fulfilment in My Account.
		 * Listeners (e.g., WCSM\Emails\Mailer) can email the admin and attach a PDF.
		 *
		 * @param \WC_Order $order
		 * @param int       $supplier_id
		 * @param array     $old (old fulfilment record for this supplier)
		 * @param array     $new (new fulfilment record for this supplier)
		 */
		do_action( 'wcsm_supplier_fulfilment_changed_by_supplier', $order, (int) $supplier_id, (array) $old_for_me, (array) $new_for_me );

		wc_add_notice( __( 'Supplier fulfilment updated.', 'wc-supplier-manager' ), 'success' );
	}

	public static function maybe_download_packing_slip(): void {
		if ( ! is_account_page() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcsm_packing_slip'], $_GET['_wpnonce'], $_GET['order_id'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'wcsm_download_slip' ) ) {
			return;
		}

		$supplier_id = get_current_user_id();
		if ( ! self::current_user_is_supplierish() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = (int) $_GET['order_id'];
		$order    = wc_get_order( $order_id );
		if ( ! $order || ( ! \WCSM\Orders\Utils::order_has_supplier( $order, $supplier_id ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wc-supplier-manager' ), 403 );
		}

		// Generate/stream the PDF packing slip for this supplier portion.
		if ( ! class_exists( '\WCSM\Docs\PackingSlip' ) ) {
			return;
		}
		\WCSM\Docs\PackingSlip::stream_for_supplier( $order, (int) $supplier_id );
		exit;
	}

	private static function backfill_recent_orders_for_supplier( int $supplier_id, int $days = 90 ): void {
		$args = [
			'limit'        => -1,
			'status'       => array_keys( wc_get_order_statuses() ),
			'type'         => 'shop_order',
			'date_created' => '>' . ( new \WC_DateTime( '-' . (int) $days . ' days' ) )->date( 'Y-m-d H:i:s' ),
			'return'       => 'objects',
		];

		$orders = wc_get_orders( $args );
		foreach ( $orders as $order ) {
			$groups = \WCSM\Orders\Utils::group_items_by_supplier( $order );
			if ( isset( $groups[ $supplier_id ] ) ) {
				\WCSM\Orders\Utils::ensure_supplier_index( $order );
			}
		}
	}
}