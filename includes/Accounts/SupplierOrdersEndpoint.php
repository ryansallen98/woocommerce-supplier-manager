<?php
namespace WCSM\Accounts;

use WCSM\Support\TemplateLoader;
use WCSM\Orders\Utils;
use WCSM\Admin\Settings\Tabs\OrdersDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SupplierOrdersEndpoint {
	const ENDPOINT = 'supplier-orders';

	// Fallbacks in case options are empty/missing (keeps legacy behaviour)
	private const FALLBACK_VISIBLE_STATUSES = [ 'processing', 'on-hold', 'completed', 'cancelled', 'refunded' ];
	private const FALLBACK_NOTIFY_STATUSES  = [ 'cancelled', 'refunded' ];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 6 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ __CLASS__, 'render_endpoint' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_download_packing_slip' ] );

		// Notify suppliers when order status changes (admin-controlled list)
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'maybe_notify_suppliers' ], 10, 4 );

		// Clamp any column-provided order-status filter options to allowed set (belt & braces)
		add_filter( 'wcsm_supplier_orders_columns', [ __CLASS__, 'limit_status_column_options' ], 40 );
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

	/** Helper: read admin-configured visible statuses with fallback */
	private static function get_visible_statuses() : array {
		if ( class_exists( OrdersDashboard::class ) ) {
			$vis = OrdersDashboard::get_saved_visible_statuses();
			if ( ! empty( $vis ) ) {
				return array_values( array_unique( array_map( 'sanitize_key', $vis ) ) );
			}
		}
		return self::FALLBACK_VISIBLE_STATUSES;
	}

	/** Helper: read admin-configured notify statuses with fallback */
	private static function get_notify_statuses() : array {
		if ( class_exists( OrdersDashboard::class ) ) {
			$not = OrdersDashboard::get_saved_notify_statuses();
			if ( ! empty( $not ) ) {
				return array_values( array_unique( array_map( 'sanitize_key', $not ) ) );
			}
		}
		return self::FALLBACK_NOTIFY_STATUSES;
	}

	/** Build allowed order-status choices for the template dropdown */
	private static function get_supplier_status_choices() : array {
		$allowed = self::get_visible_statuses();                  // slugs without 'wc-'
		$all     = wc_get_order_statuses();                       // ['wc-processing' => 'Processing', ...]
		$out     = [ 'any' => __( 'All confirmed statuses', 'wc-supplier-manager' ) ];

		foreach ( $allowed as $slug ) {
			$key        = 'wc-' . $slug;
			$out[ $slug ] = $all[ $key ] ?? ucfirst( str_replace( '-', ' ', $slug ) );
		}
		return $out;
	}

	/**
	 * Ensure any column that exposes an order-status filter only offers allowed statuses.
	 * Runs on the Supplier Orders table columns definition.
	 */
	public static function limit_status_column_options( array $cols ) : array {
		// Only apply on the front-end supplier endpoint
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_account_page() || ! \is_wc_endpoint_url( self::ENDPOINT ) ) {
			return $cols;
		}

		$choices  = self::get_supplier_status_choices(); // ['any' => ..., 'processing' => 'Processing', ...]
		$filtered = $choices;
		unset( $filtered['any'] ); // enum options do not include "any"

		foreach ( [ 'order-status', 'status' ] as $k ) {
			if ( isset( $cols[ $k ] ) ) {
				$cols[ $k ]['filterable'] = 'enum';
				$cols[ $k ]['options']    = $filtered; // [slug => label]
			}
		}
		return $cols;
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
		$columns = \WCSM\Support\OrdersTable::get_columns();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw     = wp_unslash( $_GET );

		// Supplier-fulfilment status (legacy UI): pending|received|sent|rejected|any
		$ff_status  = isset( $raw['status'] ) ? sanitize_text_field( $raw['status'] ) : 'any';
		if ( $ff_status !== 'any' && ! in_array( $ff_status, [ 'pending', 'received', 'sent', 'rejected' ], true ) ) {
			$ff_status = 'any';
		}

		// Order status filter (confirmed set only) — query param 'os'
		$allowed_statuses    = self::get_visible_statuses();
		$order_status        = isset( $raw['os'] ) ? sanitize_text_field( $raw['os'] ) : 'any';
		if ( 'any' !== $order_status && ! in_array( $order_status, $allowed_statuses, true ) ) {
			$order_status = 'any';
		}
		$order_status_choices = self::get_supplier_status_choices();

		$paged   = isset( $raw['paged'] ) ? max( 1, (int) $raw['paged'] ) : 1;
		$perpage = isset( $raw['per_page'] ) ? max( 1, min( 50, (int) $raw['per_page'] ) ) : 10;
		$q       = isset( $raw['wcsm_q'] ) ? sanitize_text_field( $raw['wcsm_q'] ) : '';

		// Column-driven controls
		$filter_in = ( isset( $raw['wcsm_f'] ) && is_array( $raw['wcsm_f'] ) ) ? $raw['wcsm_f'] : [];
		$sort_by   = isset( $raw['wcsm_sort'] ) ? sanitize_key( $raw['wcsm_sort'] ) : '';
		$sort_dir  = isset( $raw['wcsm_dir'] ) && strtolower( $raw['wcsm_dir'] ) === 'desc' ? 'desc' : 'asc';

		// Date range (legacy UI or wcsm_f['order-date'])
		$from_raw = $raw['wcsm_from'] ?? '';
		$to_raw   = $raw['wcsm_to']   ?? '';

		if ( isset( $filter_in['order-date'] ) && is_array( $filter_in['order-date'] ) ) {
			$from_raw = $filter_in['order-date']['min'] ?? $from_raw;
			$to_raw   = $filter_in['order-date']['max'] ?? $to_raw;
		}

		if ( is_array( $from_raw ) ) { $from_raw = reset( $from_raw ); }
		if ( is_array( $to_raw ) )   { $to_raw   = reset( $to_raw ); }

		$from = ( is_string( $from_raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) ) ? $from_raw : '';
		$to   = ( is_string( $to_raw )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_raw ) )   ? $to_raw   : '';

		// ---------- Order query base ----------
		$statuses = ( 'any' === $order_status ) ? $allowed_statuses : [ $order_status ];

		$args = [
			'limit'      => $perpage,
			'paginate'   => true,
			'paged'      => $paged,
			'type'       => 'shop_order',
			'status'     => $statuses, // ← only confirmed/allowed statuses
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

		// HPOS-safe date query
		$date_query = [];
		if ( $from ) {
			$date_query[] = [
				'column'    => 'date_created',
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
		if ( $date_query ) {
			$args['date_query'] = array_merge( [ 'relation' => 'AND' ], $date_query );
		}

		// ---------- Decide manual filtering/sorting ----------
		$needs_manual = false;

		foreach ( (array) $filter_in as $key => $val ) {
			if ( $key === 'order-date' ) { continue; }
			if ( $val === '' || $val === [] ) { continue; }
			$needs_manual = true; break;
		}
		if ( $sort_by && isset( $columns[ $sort_by ] ) && $sort_by !== 'order-date' ) {
			$needs_manual = true;
		}
		if ( in_array( $ff_status, [ 'pending', 'received', 'sent', 'rejected' ], true ) ) {
			$needs_manual = true;
		}
		if ( $q !== '' ) {
			$needs_manual = true;
		}

		// Small helpers
		$get_raw = static function( $col_def, \WC_Order $o ) {
			return is_callable( $col_def['value'] ?? null ) ? call_user_func( $col_def['value'], $o ) : '';
		};
		$cmp_scalar = static function( $a, $b ): int {
			if ( $a == $b ) { return 0; } // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			return ( $a < $b ) ? -1 : 1;
		};

		// ---------- Fetch & Filter ----------
		if ( $needs_manual ) {
			$candidate_args = $args;
			$candidate_args['limit']    = -1;
			$candidate_args['paginate'] = false;
			$candidate_args['return']   = 'objects';
			unset( $candidate_args['orderby'], $candidate_args['order'] );

			$orders = wc_get_orders( $candidate_args );
			$orders = is_array( $orders ) ? $orders : [];

			// A) Free text search
			if ( $q !== '' ) {
				$needle = ltrim( trim( $q ), '#' );
				$orders = array_values( array_filter(
					$orders,
					static function( $o ) use ( $needle ) {
						$field_hit = false;
						$field_hit = $field_hit || stripos( (string) $o->get_id(), $needle ) !== false;
						$field_hit = $field_hit || stripos( (string) $o->get_billing_first_name(), $needle ) !== false;
						$field_hit = $field_hit || stripos( (string) $o->get_billing_last_name(), $needle ) !== false;
						$field_hit = $field_hit || stripos( (string) $o->get_billing_email(), $needle ) !== false;
						$field_hit = $field_hit || stripos( (string) $o->get_billing_company(), $needle ) !== false;
						$field_hit = $field_hit || stripos( (string) $o->get_billing_phone(), $needle ) !== false;

						if ( ! $field_hit ) {
							foreach ( $o->get_items( 'line_item' ) as $li ) {
								if ( stripos( $li->get_name(), $needle ) !== false ) {
									$field_hit = true; break;
								}
							}
						}
						return $field_hit;
					}
				) );
			}

			// B) Supplier-fulfilment status (pending/received/sent/rejected)
			if ( in_array( $ff_status, [ 'pending', 'received', 'sent', 'rejected' ], true ) ) {
				$orders = array_values(
					array_filter(
						$orders,
						static function ( $order ) use ( $supplier_id, $ff_status ) {
							$ff = Utils::get_fulfilment( $order );
							return isset( $ff[ $supplier_id ]['status'] )
								? ( $ff[ $supplier_id ]['status'] === $ff_status )
								: ( $ff_status === 'pending' ); // no record => treat as pending
						}
					)
				);
			}

			// C) Column-driven filters
			foreach ( (array) $filter_in as $key => $needle ) {
				if ( $key === 'order-date' ) { continue; } // already in date_query
				if ( ! isset( $columns[ $key ] ) ) { continue; }
				$col = $columns[ $key ];
				if ( empty( $col['filterable'] ) ) { continue; }

				$mode = $col['filterable'];
				$type = $col['type'] ?? 'text';

				$orders = array_values( array_filter(
					$orders,
					static function( $o ) use ( $col, $mode, $type, $needle, $get_raw ) {
						$raw = $get_raw( $col, $o );

						if ( $mode === 'enum' ) {
							$val = is_string( $needle ) ? $needle : '';
							return $val === '' ? true : ( (string) $raw === $val );
						}

						if ( $mode === 'range' ) {
							$min = is_array( $needle ) ? ( $needle['min'] ?? '' ) : '';
							$max = is_array( $needle ) ? ( $needle['max'] ?? '' ) : '';

							if ( $type === 'date' ) {
								$ts = $raw instanceof \WC_DateTime ? $raw->getTimestamp() : ( is_numeric( $raw ) ? (int) $raw : 0 );
								$ok = true;
								if ( $min && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $min ) ) {
									$ok = $ok && ( $ts >= strtotime( $min . ' 00:00:00' ) );
								}
								if ( $max && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $max ) ) {
									$ok = $ok && ( $ts <= strtotime( $max . ' 23:59:59' ) );
								}
								return $ok;
							}

							$val = (float) wc_format_decimal( (string) $raw );
							$ok  = true;
							if ( $min !== '' ) { $ok = $ok && ( $val >= (float) wc_format_decimal( (string) $min ) ); }
							if ( $max !== '' ) { $ok = $ok && ( $val <= (float) wc_format_decimal( (string) $max ) ); }
							return $ok;
						}

						$hay = is_scalar( $raw ) ? (string) $raw : '';
						$nd  = is_string( $needle ) ? $needle : '';
						return $nd === '' ? true : ( stripos( $hay, $nd ) !== false );
					}
				) );
			}

			// D) Sorting
			if ( $sort_by && isset( $columns[ $sort_by ] ) ) {
				$col = $columns[ $sort_by ];
				usort(
					$orders,
					static function( $a, $b ) use ( $col, $get_raw, $cmp_scalar, $sort_dir ) {
						$ra = $get_raw( $col, $a );
						$rb = $get_raw( $col, $b );

						$type   = $col['type']   ?? 'text';
						$format = $col['format'] ?? '';

						if ( $type === 'date' ) {
							$ra = $ra instanceof \WC_DateTime ? $ra->getTimestamp() : ( is_numeric( $ra ) ? (int) $ra : 0 );
							$rb = $rb instanceof \WC_DateTime ? $rb->getTimestamp() : ( is_numeric( $rb ) ? (int) $rb : 0 );
						} elseif ( $type === 'number' || $format === 'price' || $format === 'number' ) {
							$ra = (float) wc_format_decimal( (string) $ra );
							$rb = (float) wc_format_decimal( (string) $rb );
						} else {
							$ra = (string) $ra;
							$rb = (string) $rb;
						}

						$res = $cmp_scalar( $ra, $rb );
						return ( $sort_dir === 'desc' ) ? -$res : $res;
					}
				);
			} else {
				usort(
					$orders,
					static function( $a, $b ) use ( $cmp_scalar ) {
						$ta = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
						$tb = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
						return - $cmp_scalar( $ta, $tb );
					}
				);
			}

			// E) Manual pagination
			$total     = count( $orders );
			$max_pages = max( 1, (int) ceil( $total / $perpage ) );
			$offset    = ( $paged - 1 ) * $perpage;
			$orders    = array_slice( $orders, $offset, $perpage );

		} else {
			// Normal paginated query (no advanced filters/sorting)
			$results   = wc_get_orders( $args );
			$orders    = is_array( $results ) ? $results : ( $results->orders ?? [] );
			$total     = is_array( $results ) ? count( $orders ) : ( $results->total ?? 0 );
			$max_pages = is_array( $results ) ? 1 : ( $results->max_num_pages ?? 1 );
		}

		// If nothing found, backfill recent orders for this supplier, then try once more.
		if ( empty( $orders ) ) {
			self::backfill_recent_orders_for_supplier( $supplier_id, 120 );

			if ( $needs_manual ) {
				$candidate_args = $args;
				$candidate_args['limit']    = -1;
				$candidate_args['paginate'] = false;
				$candidate_args['return']   = 'objects';
				unset( $candidate_args['orderby'], $candidate_args['order'] );
				$orders = wc_get_orders( $candidate_args );
				$orders = is_array( $orders ) ? $orders : [];

				$total     = count( $orders );
				$max_pages = max( 1, (int) ceil( $total / $perpage ) );
				$offset    = ( $paged - 1 ) * $perpage;
				$orders    = array_slice( $orders, $offset, $perpage );
			} else {
				$results   = wc_get_orders( $args );
				$orders    = is_array( $results ) ? $results : ( $results->orders ?? [] );
				$total     = is_array( $results ) ? count( $orders ) : ( $results->total ?? 0 );
				$max_pages = is_array( $results ) ? 1 : ( $results->max_num_pages ?? 1 );
			}
		}

		// ---------- Pagination base URL (preserve filters/sorting) ----------
		$endpoint_url = wc_get_account_endpoint_url( self::ENDPOINT );

		// Build wcsm_f param compactly (only non-empty parts)
		$wcsm_f_clean = [];
		foreach ( (array) $filter_in as $k => $v ) {
			if ( $k === 'order-date' ) { continue; }
			if ( $v === '' || $v === [] ) { continue; }
			$wcsm_f_clean[ $k ] = $v;
		}

		$base_query = array_filter(
			[
				// legacy fulfilment filter
				'status'    => ( 'any' !== $ff_status ) ? $ff_status : null,
				// order-status filter
				'os'        => ( 'any' !== $order_status ) ? $order_status : null,
				// search & paging
				'wcsm_q'    => ( '' !== $q ) ? $q : null,
				'per_page'  => ( 10 !== $perpage ) ? $perpage : null,
				'wcsm_from' => ( '' !== $from ) ? $from : null,
				'wcsm_to'   => ( '' !== $to ) ? $to : null,
				// column sort/filter
				'wcsm_sort' => $sort_by ?: null,
				'wcsm_dir'  => ( $sort_by && $sort_dir === 'desc' ) ? 'desc' : null,
				'wcsm_f'    => ! empty( $wcsm_f_clean ) ? $wcsm_f_clean : null,
			]
		);

		$base_url = add_query_arg( $base_query, $endpoint_url );

		TemplateLoader::get(
			'myaccount/supplier-orders.php',
			[
				'orders'       => $orders,
				'supplier_id'  => $supplier_id,
				'controls'     => [
					// fulfilment status (legacy)
					'status'                => $ff_status,
					// order-status filter pieces
					'order_status'          => $order_status,
					'order_status_choices'  => $order_status_choices,

					'wcsm_q'    => $q,
					'per_page'  => $perpage,
					'wcsm_from' => $from,
					'wcsm_to'   => $to,
					'wcsm_f'    => $filter_in,
					'wcsm_sort' => $sort_by,
					'wcsm_dir'  => $sort_dir,
				],
				'pagination'   => [
					'current'     => $paged,
					'total'       => $max_pages ?? 1,
					'base'        => $base_url,
					'total_items' => isset( $total ) ? (int) $total : 0,
				],
				'endpoint_url' => $endpoint_url,
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

		// Allowed supplier-fulfilment statuses
		$allowed_status = [ 'pending', 'received', 'sent', 'rejected' ];
		$status = isset( $_POST['wcsm_status'] ) ? sanitize_text_field( $_POST['wcsm_status'] ) : 'pending';
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'pending';
		}
		$carrier = isset( $_POST['wcsm_carrier'] ) ? sanitize_text_field( $_POST['wcsm_carrier'] ) : '';
		$number  = isset( $_POST['wcsm_tracking'] ) ? sanitize_text_field( $_POST['wcsm_tracking'] ) : '';
		$url     = isset( $_POST['wcsm_url'] ) ? esc_url_raw( $_POST['wcsm_url'] ) : '';
		$notes   = isset( $_POST['wcsm_notes'] ) ? sanitize_textarea_field( $_POST['wcsm_notes'] ) : '';

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

		$after_all  = \WCSM\Orders\Utils::get_fulfilment( $order );
		$new_for_me = $after_all[ $supplier_id ] ?? [];

		/**
		 * Fire: supplier changed their fulfilment in My Account.
		 *
		 * @param \WC_Order $order
		 * @param int       $supplier_id
		 * @param array     $old
		 * @param array     $new
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
			'status'       => self::get_visible_statuses(), // only confirmed/visible statuses
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

	/* ===================== Notifications ===================== */

	/**
	 * Notify suppliers when an order changes to a status we care about (admin-configurable).
	 *
	 * @param int        $order_id
	 * @param string     $old_status e.g., 'processing'
	 * @param string     $new_status e.g., 'cancelled'
	 * @param \WC_Order  $order
	 */
	public static function maybe_notify_suppliers( $order_id, $old_status, $new_status, $order ): void {
		$notify_on = self::get_notify_statuses();
		if ( ! in_array( $new_status, $notify_on, true ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) return;
		}

		// Collect supplier user IDs for this order
		$groups       = \WCSM\Orders\Utils::group_items_by_supplier( $order ); // [ supplier_id => [ line items... ] ]
		$supplier_ids = array_keys( (array) $groups );
		if ( empty( $supplier_ids ) ) return;

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: site name, 2: order number, 3: new status */
			__( '[%1$s] Order #%2$s is now %3$s', 'wc-supplier-manager' ),
			$site_name,
			$order->get_order_number(),
			$new_status
		);

		$body_lines = [];
		$body_lines[] = sprintf( __( 'Order #%s status changed: %s → %s', 'wc-supplier-manager' ), $order->get_order_number(), $old_status, $new_status );
		$body_lines[] = '';
		$body_lines[] = __( 'This order includes items you are assigned to fulfil.', 'wc-supplier-manager' );
		$body_lines[] = '';
		$body_lines[] = admin_url( sprintf( 'post.php?post=%d&action=edit', $order->get_id() ) );

		// Build recipients
		$emails = [];
		foreach ( $supplier_ids as $uid ) {
			$user = get_user_by( 'id', (int) $uid );
			if ( $user && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
		$emails = array_unique( array_filter( $emails ) );

		/**
		 * Filters to customize supplier notice.
		 */
		$subject = apply_filters( 'wcsm_supplier_status_notice_subject', $subject, $order, $new_status, $old_status );
		$message = apply_filters( 'wcsm_supplier_status_notice_message', implode( "\n", $body_lines ), $order, $new_status, $old_status );
		$emails  = apply_filters( 'wcsm_supplier_status_notice_recipients', $emails, $order, $new_status, $old_status );

		if ( empty( $emails ) ) return;

		wp_mail( $emails, $subject, $message );
	}
}