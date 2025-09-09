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

// Build the update checker instance.
$updateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/ryansallen98/woocommerce-supplier-manager/',
    __FILE__,                                                        
    'woocommerce-supplier-manager'                                  
);

// If your primary branch is "main" (default is "master"):
$updateChecker->setBranch('main');

// Optional: if you publish release assets (.zip) on GitHub Releases:
$updateChecker->getVcsApi()->enableReleaseAssets();