<?php
namespace WCSM\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

class Menu {
	const PAGE_SLUG = 'wcsm-supplier-settings'; // keep your slug

	/** @var string|null */
	private static $hook_suffix = null;

	public static function init() : void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );

		// (Optional) enqueue per-screen assets later:
		// add_action( 'load-' . self::$hook_suffix, [ __CLASS__, 'on_load_screen' ] );
	}

	public static function add_menu() : void {
		self::$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Supplier Settings', 'wc-supplier-manager' ),
			__( 'Supplier Settings', 'wc-supplier-manager' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ],
			56
		);

		// Add Help tab when screen loads
		if ( self::$hook_suffix ) {
			add_action( 'load-' . self::$hook_suffix, [ __CLASS__, 'add_screen_help' ] );
		}
	}

	public static function add_screen_help() : void {
		$screen = get_current_screen();
		if ( ! $screen ) return;

		// Delegate to the Orders Dashboard tab to keep your existing developer docs.
		$help = \WCSM\Admin\Settings\Tabs\OrdersDashboard::help_content();

		$screen->add_help_tab( [
			'id'    => 'wcsm_supplier_help',
			'title' => __( 'Developer Help', 'wc-supplier-manager' ),
			'content' => $help['content'],
		] );

		$screen->set_help_sidebar( $help['sidebar'] );
	}

	public static function render() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'wc-supplier-manager' ) );
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

		// Tabs registry (order matters)
		$tabs = [
			'general' => [
				'label'   => __( 'General', 'wc-supplier-manager' ),
				'render'  => [ \WCSM\Admin\Settings\Tabs\General::class, 'render' ],
			],
			'orders' => [
				'label'   => __( 'Orders dashboard', 'wc-supplier-manager' ),
				'render'  => [ \WCSM\Admin\Settings\Tabs\OrdersDashboard::class, 'render' ],
			],
		];

		// Fallback if unknown tab
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Supplier Settings', 'wc-supplier-manager' ) . '</h1>';

		// Notices (shared)
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wc-supplier-manager' ) . '</p></div>';
		}

		// WP nav tabs
		echo '<h2 class="nav-tab-wrapper" style="margin-top:12px;">';
		foreach ( $tabs as $key => $tab ) {
			$url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $key ], admin_url( 'admin.php' ) );
			$cls = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $cls ), esc_html( $tab['label'] ) );
		}
		echo '</h2>';

		// Render active tab
		call_user_func( $tabs[ $active ]['render'] );

		echo '</div>';
	}
}