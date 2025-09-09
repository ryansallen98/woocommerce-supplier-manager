<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Load text domain
add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( 'wc-supplier-manager', false, dirname( plugin_basename( WCSM_FILE ) ) . '/languages' );
});

// Simple PSR-4 autoloader for \WCSM\
require_once __DIR__ . '/autoload.php';

// Admin notices + WC dependency check
\WCSM\Admin\Notices::boot();

// Start plugin core
\WCSM\Core\Plugin::init();

// GitHub updates via Plugin Update Checker (v5)
if ( is_admin() ) {
    // v5 is namespaced. No "use" at top? You can reference it as a string:
    $factoryClass = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

    if ( class_exists( $factoryClass ) ) {
        $updateChecker = $factoryClass::buildUpdateChecker(
            'https://github.com/ryansallen98/woocommerce-supplier-manager/',
            WCSM_FILE,                             // main plugin file constant
            'woocommerce-supplier-manager'         // plugin slug
        );

        $updateChecker->setBranch('main');
        $updateChecker->getVcsApi()->enableReleaseAssets();
        // $updateChecker->setAuthentication('ghp_xxx'); // if repo is private
    }
}