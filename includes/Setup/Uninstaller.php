<?php
namespace WCSM\Setup;

use WCSM\Setup\Roles\SupplierRole;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Uninstaller {
	public static function uninstall() {
		require_once __DIR__ . '/Roles/SupplierRole.php';
		// Remove the role entirely; change to false if you prefer to keep it but strip caps
		SupplierRole::remove_role_or_caps( true );

		// Delete options/transients here later as needed.
	}
}