<?php
namespace WCSM\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

class Options {
	// Allowed values: display_name (default), first_last, username, email, company
	const OPTION_DISPLAY_FORMAT = 'wcsm_supplier_display_name';

	/** Return saved format or default */
	public static function get_display_format() : string {
		$fmt   = get_option( self::OPTION_DISPLAY_FORMAT, 'display_name' );
		$valid = array_keys( self::display_format_choices() );
		return in_array( $fmt, $valid, true ) ? $fmt : 'display_name';
	}

	/** Choices map used by UI */
	public static function display_format_choices() : array {
		return [
			'display_name' => __( 'WordPress Display Name', 'wc-supplier-manager' ),
			'first_last'   => __( 'First + Last Name', 'wc-supplier-manager' ),
			'username'     => __( 'Username', 'wc-supplier-manager' ),
			'email'        => __( 'Email address', 'wc-supplier-manager' ),
			'company'      => __( 'Company name', 'wc-supplier-manager' ),
		];
	}

	/** Format a supplier name according to current setting */
	public static function format_supplier_name( \WP_User $user ) : string {
		switch ( self::get_display_format() ) {
			case 'company':
				// Company meta key from SupplierCompanyInfo
				$company = (string) get_user_meta( $user->ID, '_wcsm_company_name', true );
				if ( $company !== '' ) return $company;
				// fallback chain if company not set
				return $user->display_name ?: $user->user_login;

			case 'first_last':
				$first = (string) get_user_meta( $user->ID, 'first_name', true );
				$last  = (string) get_user_meta( $user->ID, 'last_name', true );
				$name  = trim( "{$first} {$last}" );
				return $name !== '' ? $name : ( $user->display_name ?: $user->user_login );

			case 'username':
				return $user->user_login;

			case 'email':
				return $user->user_email;

			case 'display_name':
			default:
				return $user->display_name ?: $user->user_login;
		}
	}
}