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