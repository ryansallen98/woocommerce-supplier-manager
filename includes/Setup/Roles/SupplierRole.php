<?php
namespace WCSM\Setup\Roles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SupplierRole {
	const ROLE = 'supplier';
	const CAP_SUPPLIER = 'wcsm_supplier';

	public static function register() {
		$caps = [
			'read'             => true,
			'upload_files'     => true,
			self::CAP_SUPPLIER => true,
		];

		$role = get_role( self::ROLE );
		if ( $role ) {
			foreach ( $caps as $cap => $grant ) {
				if ( $grant && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		} else {
			add_role( self::ROLE, __( 'Supplier', 'wc-supplier-manager' ), $caps );
		}
	}

	public static function remove_role_or_caps( $remove_role = true ) {
		if ( $remove_role ) {
			remove_role( self::ROLE );
			return;
		}
		$role = get_role( self::ROLE );
		if ( $role ) {
			$role->remove_cap( self::CAP_SUPPLIER );
			$role->remove_cap( 'upload_files' );
		}
	}
}