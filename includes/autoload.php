<?php
spl_autoload_register( function( $class ) {
	if ( strpos( $class, 'WCSM\\' ) !== 0 ) return;

	$base_dir = __DIR__ . '/';
	$relative = substr( $class, strlen( 'WCSM\\' ) );
	$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );


require_once __DIR__ . '/Admin/Notices.php';
require_once __DIR__ . '/Core/Plugin.php';
require_once __DIR__ . '/Support/TemplateLoader.php';
require_once __DIR__ . '/Support/TemplateTags.php';
require_once __DIR__ . '/Accounts/SupplierProductsEndpoint.php';