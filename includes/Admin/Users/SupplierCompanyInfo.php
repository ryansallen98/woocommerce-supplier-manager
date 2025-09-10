<?php
namespace WCSM\Admin\Users;

use WCSM\Setup\Roles\SupplierRole;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin UI: Company details for Supplier users.
 *
 * File: includes/Admin/Users/SupplierCompanyInfo.php
 */
class SupplierCompanyInfo {
	// Meta keys
	const META_COMPANY_NAME   = '_wcsm_company_name';
	const META_COMPANY_NO     = '_wcsm_company_number';
	const META_VAT_NO         = '_wcsm_vat_number';
	const META_ADDR_1         = '_wcsm_company_addr1';
	const META_ADDR_2         = '_wcsm_company_addr2';
	const META_CITY           = '_wcsm_company_city';
	const META_POSTCODE       = '_wcsm_company_postcode';
	const META_COUNTRY        = '_wcsm_company_country';
	const META_PHONE          = '_wcsm_company_phone';
	const META_WEBSITE        = '_wcsm_company_website';

	/**
	 * Wire up admin hooks.
	 */
	public static function init() : void {
		if ( ! is_admin() ) return;

		// Render on existing user profile pages
		add_action( 'show_user_profile', [ __CLASS__, 'render_for_existing_user' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'render_for_existing_user' ] );

		// Render on "Add New User" screen
		add_action( 'user_new_form', [ __CLASS__, 'render_for_new_user' ] );

		// Save on update
		add_action( 'personal_options_update',   [ __CLASS__, 'save_existing_user' ] );
		add_action( 'edit_user_profile_update',  [ __CLASS__, 'save_existing_user' ] );

		// Save on create
		add_action( 'user_register', [ __CLASS__, 'save_new_user' ] );
	}

	/* -------------------- Renderers -------------------- */

	/**
	 * Existing user pages: show only for supplier users.
	 */
	public static function render_for_existing_user( \WP_User $user ) : void {
		if ( ! self::is_supplier_user( $user ) ) {
			return;
		}
		self::render_fields( $user, false );
	}

	/**
	 * Add New User page: render fields but keep them hidden until the "supplier" role is selected.
	 *
	 * @param string $form_type Context string from WP (e.g. 'add-new-user').
	 */
	public static function render_for_new_user( string $form_type ) : void {
		self::render_fields( null, true );
	}

	/**
	 * Shared renderer.
	 *
	 * @param \WP_User|null $user   User when editing existing, or null on Add New User.
	 * @param bool          $is_new Are we on Add New User?
	 */
	private static function render_fields( ?\WP_User $user, bool $is_new ) : void {
		$vals = [
			self::META_COMPANY_NAME => $user ? get_user_meta( $user->ID, self::META_COMPANY_NAME, true ) : '',
			self::META_COMPANY_NO   => $user ? get_user_meta( $user->ID, self::META_COMPANY_NO, true )   : '',
			self::META_VAT_NO       => $user ? get_user_meta( $user->ID, self::META_VAT_NO, true )       : '',
			self::META_ADDR_1       => $user ? get_user_meta( $user->ID, self::META_ADDR_1, true )       : '',
			self::META_ADDR_2       => $user ? get_user_meta( $user->ID, self::META_ADDR_2, true )       : '',
			self::META_CITY         => $user ? get_user_meta( $user->ID, self::META_CITY, true )         : '',
			self::META_POSTCODE     => $user ? get_user_meta( $user->ID, self::META_POSTCODE, true )     : '',
			self::META_COUNTRY      => $user ? get_user_meta( $user->ID, self::META_COUNTRY, true )      : '',
			self::META_PHONE        => $user ? get_user_meta( $user->ID, self::META_PHONE, true )        : '',
			self::META_WEBSITE      => $user ? get_user_meta( $user->ID, self::META_WEBSITE, true )      : '',
		];

		// On Add New User, hide until role field switches to "supplier".
		$wrapper_attr = $is_new ? ' id="wcsm-company-info-wrapper" style="display:none;"' : '';

		// Nonce for edit screens (user-new flow may not include it on all paths).
		wp_nonce_field( 'wcsm_save_company_info', 'wcsm_company_info_nonce' );
		?>
		<h2><?php esc_html_e( 'Supplier Company Info', 'wc-supplier-manager' ); ?></h2>

		<table class="form-table"<?php echo $wrapper_attr; ?> role="presentation">
			<tr>
				<th><label for="wcsm_company_name"><?php esc_html_e( 'Company name', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_name" name="<?php echo esc_attr( self::META_COMPANY_NAME ); ?>" value="<?php echo esc_attr( $vals[self::META_COMPANY_NAME] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_number"><?php esc_html_e( 'Company number', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_number" name="<?php echo esc_attr( self::META_COMPANY_NO ); ?>" value="<?php echo esc_attr( $vals[self::META_COMPANY_NO] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_vat_number"><?php esc_html_e( 'VAT number', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_vat_number" name="<?php echo esc_attr( self::META_VAT_NO ); ?>" value="<?php echo esc_attr( $vals[self::META_VAT_NO] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_addr1"><?php esc_html_e( 'Address 1', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_addr1" name="<?php echo esc_attr( self::META_ADDR_1 ); ?>" value="<?php echo esc_attr( $vals[self::META_ADDR_1] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_addr2"><?php esc_html_e( 'Address 2', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_addr2" name="<?php echo esc_attr( self::META_ADDR_2 ); ?>" value="<?php echo esc_attr( $vals[self::META_ADDR_2] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_city"><?php esc_html_e( 'City', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_city" name="<?php echo esc_attr( self::META_CITY ); ?>" value="<?php echo esc_attr( $vals[self::META_CITY] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_postcode"><?php esc_html_e( 'Postcode / ZIP', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_postcode" name="<?php echo esc_attr( self::META_POSTCODE ); ?>" value="<?php echo esc_attr( $vals[self::META_POSTCODE] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_country"><?php esc_html_e( 'Country', 'wc-supplier-manager' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wcsm_company_country" name="<?php echo esc_attr( self::META_COUNTRY ); ?>" value="<?php echo esc_attr( $vals[self::META_COUNTRY] ); ?>" placeholder="<?php esc_attr_e( 'e.g., GB or United Kingdom', 'wc-supplier-manager' ); ?>">
					<p class="description"><?php esc_html_e( 'You can store either a 2-letter code or a full country name.', 'wc-supplier-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wcsm_company_phone"><?php esc_html_e( 'Phone', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wcsm_company_phone" name="<?php echo esc_attr( self::META_PHONE ); ?>" value="<?php echo esc_attr( $vals[self::META_PHONE] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wcsm_company_website"><?php esc_html_e( 'Website', 'wc-supplier-manager' ); ?></label></th>
				<td><input type="url" class="regular-text" id="wcsm_company_website" name="<?php echo esc_attr( self::META_WEBSITE ); ?>" value="<?php echo esc_attr( $vals[self::META_WEBSITE] ); ?>" placeholder="https://"></td>
			</tr>
		</table>
		<?php
		// On Add New User, auto-toggle visibility when role becomes "supplier".
		if ( $is_new ) : ?>
			<script>
			(function(){
				function toggleCompanyInfo(){
					var roleSel = document.getElementById('role');
					var wrap    = document.getElementById('wcsm-company-info-wrapper');
					if (!roleSel || !wrap) return;
					wrap.style.display = (roleSel.value === '<?php echo esc_js( SupplierRole::ROLE ); ?>') ? '' : 'none';
				}
				document.addEventListener('change', function(e){
					if (e.target && e.target.id === 'role') toggleCompanyInfo();
				});
				document.addEventListener('DOMContentLoaded', toggleCompanyInfo);
			})();
			</script>
		<?php
		endif;
	}

	/* -------------------- Saving -------------------- */

	/**
	 * Save from Edit Profile / Edit User.
	 */
	public static function save_existing_user( int $user_id ) : void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['wcsm_company_info_nonce'] ) || ! wp_verify_nonce( $_POST['wcsm_company_info_nonce'], 'wcsm_save_company_info' ) ) {
			return;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! self::is_supplier_user( $user ) ) {
			return; // Only save for suppliers
		}
		self::do_save( $user_id );
	}

	/**
	 * Save right after user creation on Add New User, if the new user is a supplier.
	 */
	public static function save_new_user( int $user_id ) : void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! self::is_supplier_user( $user ) ) {
			return;
		}
		self::do_save( $user_id );
	}

	/**
	 * Normalize and save all fields.
	 */
	private static function do_save( int $user_id ) : void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$in = isset( $_POST ) ? wp_unslash( $_POST ) : [];

		self::save_text( $user_id, self::META_COMPANY_NAME, $in );
		self::save_text( $user_id, self::META_COMPANY_NO,   $in );
		self::save_text( $user_id, self::META_VAT_NO,       $in );
		self::save_text( $user_id, self::META_ADDR_1,       $in );
		self::save_text( $user_id, self::META_ADDR_2,       $in );
		self::save_text( $user_id, self::META_CITY,         $in );
		self::save_text( $user_id, self::META_POSTCODE,     $in );
		self::save_text( $user_id, self::META_COUNTRY,      $in );
		self::save_text( $user_id, self::META_PHONE,        $in );

		$website = isset( $in[ self::META_WEBSITE ] ) ? esc_url_raw( $in[ self::META_WEBSITE ] ) : '';
		self::update_meta( $user_id, self::META_WEBSITE, $website );
	}

	private static function save_text( int $user_id, string $key, array $in ) : void {
		$val = isset( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : '';
		self::update_meta( $user_id, $key, $val );
	}

	private static function update_meta( int $user_id, string $key, string $val ) : void {
		if ( $val === '' ) {
			delete_user_meta( $user_id, $key );
		} else {
			update_user_meta( $user_id, $key, $val );
		}
	}

	/* -------------------- Helpers -------------------- */

	/**
	 * Check if a user is a Supplier (by role or custom cap).
	 */
	private static function is_supplier_user( \WP_User $user ) : bool {
		if ( in_array( SupplierRole::ROLE, (array) $user->roles, true ) ) {
			return true;
		}
		if ( user_can( $user, SupplierRole::CAP_SUPPLIER ) ) {
			return true;
		}
		return false;
	}
}