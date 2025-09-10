<?php
namespace WCSM\Admin\Settings\Tabs;

use WCSM\Admin\Settings\Options;

if ( ! defined( 'ABSPATH' ) ) exit;

class General {
	const NONCE = 'wcsm_save_general';

	public static function render() : void {
		$current = Options::get_display_format();
		$choices = Options::display_format_choices();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="wcsm_save_general" />

			<h2><?php esc_html_e( 'General settings', 'wc-supplier-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Supplier display name', 'wc-supplier-manager' ); ?>
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Supplier display name', 'wc-supplier-manager' ); ?></legend>
								<?php foreach ( $choices as $value => $label ) : ?>
									<label style="display:block;margin:4px 0;">
										<input type="radio" name="wcsm_display_format" value="<?php echo esc_attr( $value ); ?>"
											<?php checked( $current, $value ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Controls how supplier names appear in admin dropdowns and the Products table.', 'wc-supplier-manager' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'wc-supplier-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	public static function hooks() : void {
		add_action( 'admin_post_wcsm_save_general', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save() : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have permission.', 'wc-supplier-manager' ) );
		}
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fmt = isset( $_POST['wcsm_display_format'] ) ? sanitize_key( wp_unslash( $_POST['wcsm_display_format'] ) ) : 'display_name';

		$valid = array_keys( Options::display_format_choices() );
		if ( ! in_array( $fmt, $valid, true ) ) {
			$fmt = 'display_name';
		}

		update_option( Options::OPTION_DISPLAY_FORMAT, $fmt, false );

		wp_safe_redirect( add_query_arg( [
			'page'    => \WCSM\Admin\Settings\Menu::PAGE_SLUG,
			'tab'     => 'general',
			'updated' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}