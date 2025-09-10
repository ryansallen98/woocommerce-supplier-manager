<?php
namespace WCSM\Admin\Product;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SupplierListColumn {
	const META_KEY       = '_wcsm_supplier_id'; // product meta key storing supplier user ID
	const COL_KEY        = 'wcsm_supplier';     // column slug
	const QUERY_VAR      = 'supplier_user';     // GET param for filter dropdown
	const SUPPLIER_ROLES = [ 'supplier' ];      // allowed supplier roles

	public static function init() : void {
		if ( ! is_admin() ) return;

		add_filter( 'manage_edit-product_columns', [ __CLASS__, 'add_column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );

		add_filter( 'manage_edit-product_sortable_columns', [ __CLASS__, 'make_sortable' ] );
		add_filter( 'posts_clauses', [ __CLASS__, 'sort_by_supplier_name' ], 10, 2 );

		add_action( 'restrict_manage_posts', [ __CLASS__, 'add_filter_dropdown' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'apply_supplier_filter' ] );
	}

	public static function add_column( array $cols ) : array {
		$new = [];
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'sku' ) {
				$new[ self::COL_KEY ] = __( 'Supplier', 'wc-supplier-manager' );
			}
		}
		if ( ! isset( $new[ self::COL_KEY ] ) ) {
			$new[ self::COL_KEY ] = __( 'Supplier', 'wc-supplier-manager' );
		}
		return $new;
	}

	public static function render_column( string $column, int $post_id ) : void {
		if ( $column !== self::COL_KEY ) return;

		$uid = (int) get_post_meta( $post_id, self::META_KEY, true );
		if ( $uid <= 0 ) {
			echo '<span style="color:#888;">—</span>';
			return;
		}

		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			echo '<span style="color:#888;">(missing user #' . esc_html( (string) $uid ) . ')</span>';
			return;
		}

		$display = $user->display_name ?: $user->user_login;

		// Link display name to filter products by this supplier
		$filter_link = esc_url( add_query_arg( [
			'post_type'     => 'product',
			self::QUERY_VAR => $uid,
		], admin_url( 'edit.php' ) ) );

		printf(
			'<a href="%s">%s</a>',
			$filter_link,
			esc_html( $display )
		);
	}

	public static function make_sortable( array $cols ) : array {
		$cols[ self::COL_KEY ] = self::COL_KEY;
		return $cols;
	}

	public static function sort_by_supplier_name( array $clauses, \WP_Query $q ) : array {
		if ( ! is_admin() || ! $q->is_main_query() ) return $clauses;
		if ( $q->get( 'post_type' ) !== 'product' ) return $clauses;
		if ( $q->get( 'orderby' ) !== self::COL_KEY ) return $clauses;

		global $wpdb;
		$pm = 'pm_supplier';
		$u  = 'u_supplier';

		if ( strpos( $clauses['join'], " {$wpdb->postmeta} {$pm} " ) === false ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} {$pm}
				  ON ({$wpdb->posts}.ID = {$pm}.post_id AND {$pm}.meta_key = %s) ",
				self::META_KEY
			);
		}
		if ( strpos( $clauses['join'], " {$wpdb->users} {$u} " ) === false ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->users} {$u} ON (CAST({$pm}.meta_value AS UNSIGNED) = {$u}.ID) ";
		}
		if ( empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		}

		$order = strtoupper( $q->get( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';
		$clauses['orderby'] = " {$u}.display_name {$order}, {$wpdb->posts}.ID {$order} ";

		return $clauses;
	}

	public static function add_filter_dropdown( $post_type ) : void {
		if ( $post_type !== 'product' ) return;

		$selected = isset( $_GET[ self::QUERY_VAR ] ) ? (int) $_GET[ self::QUERY_VAR ] : 0;
		$users    = self::get_supplier_users();

		echo '<label for="filter-by-supplier" class="screen-reader-text">' . esc_html__( 'Filter by supplier', 'wc-supplier-manager' ) . '</label>';
		echo '<select id="filter-by-supplier" name="' . esc_attr( self::QUERY_VAR ) . '">';
		echo '<option value="0">' . esc_html__( 'All suppliers', 'wc-supplier-manager' ) . '</option>';

		foreach ( $users as $u ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $u->ID,
				selected( $selected, (int) $u->ID, false ),
				esc_html( $u->display_name ?: $u->user_login )
			);
		}

		echo '</select>';
	}

	public static function apply_supplier_filter( \WP_Query $q ) : void {
		if ( ! is_admin() || ! $q->is_main_query() ) return;
		if ( $q->get( 'post_type' ) !== 'product' ) return;

		$uid = isset( $_GET[ self::QUERY_VAR ] ) ? (int) $_GET[ self::QUERY_VAR ] : 0;
		if ( $uid <= 0 ) return;

		$meta_query   = (array) $q->get( 'meta_query' );
		$meta_query[] = [
			'key'     => self::META_KEY,
			'value'   => $uid,
			'compare' => '=',
			'type'    => 'NUMERIC',
		];
		$q->set( 'meta_query', $meta_query );
	}

	protected static function get_supplier_users() : array {
		$args = [
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 500,
			'fields'  => [ 'ID', 'display_name', 'user_login' ],
		];
		if ( ! empty( self::SUPPLIER_ROLES ) ) {
			$args['role__in'] = self::SUPPLIER_ROLES;
		}
		return get_users( $args );
	}
}