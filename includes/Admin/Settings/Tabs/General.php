<?php
namespace WCSM\Admin\Settings\Tabs;

use WCSM\Admin\Settings\Options;

if (!defined('ABSPATH'))
	exit;

class General
{
	const NONCE = 'wcsm_save_general';
	const REBUILD_NONCE = 'wcsm_rebuild_supplier_index_nonce'; // NEW

	public static function render(): void
	{
		$current = Options::get_display_format();
		$choices = Options::display_format_choices();
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;">
			<?php wp_nonce_field(self::NONCE); ?>
			<input type="hidden" name="action" value="wcsm_save_general" />

			<h2><?php esc_html_e('General settings', 'wc-supplier-manager'); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e('Supplier display name', 'wc-supplier-manager'); ?>
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">
									<?php esc_html_e('Supplier display name', 'wc-supplier-manager'); ?></legend>
								<?php foreach ($choices as $value => $label): ?>
									<label style="display:block;margin:4px 0;">
										<input type="radio" name="wcsm_display_format" value="<?php echo esc_attr($value); ?>"
											<?php checked($current, $value); ?> />
										<?php echo esc_html($label); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e('Controls how supplier names appear in admin dropdowns and the Products table.', 'wc-supplier-manager'); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit"
					class="button button-primary"><?php esc_html_e('Save changes', 'wc-supplier-manager'); ?></button>
			</p>
		</form>

		<hr />

		<!-- Maintenance -->
		<h2><?php esc_html_e('Maintenance', 'wc-supplier-manager'); ?></h2>
		<p><?php esc_html_e('If suppliers are missing orders, rebuild the supplier index on all orders.', 'wc-supplier-manager'); ?>
		</p>

		<?php
		$nonce = wp_create_nonce(self::REBUILD_NONCE);
		$ajax = admin_url('admin-ajax.php');
		?>
		<div id="wcsm-rebuild-box">
			<button id="wcsm-rebuild-btn" class="button button-secondary">
				<?php esc_html_e('Rebuild supplier index', 'wc-supplier-manager'); ?>
			</button>
			<span id="wcsm-rebuild-status" style="margin-left:8px;"></span>
			<div id="wcsm-rebuild-progress" style="margin-top:8px;"></div>
		</div>

		<script>
			(function () {
				const btn = document.getElementById('wcsm-rebuild-btn');
				const status = document.getElementById('wcsm-rebuild-status');
				const prog = document.getElementById('wcsm-rebuild-progress');

				// Progress UI
				prog.innerHTML = '<progress id="wcsm-rebuild-bar" max="1" value="0" style="width:320px;"></progress> ' +
					'<span id="wcsm-rebuild-counters"></span>' +
					'<div id="wcsm-rebuild-log" style="margin-top:6px;white-space:pre-wrap;font-family:monospace;"></div>';
				const bar = document.getElementById('wcsm-rebuild-bar');
				const ctrs = document.getElementById('wcsm-rebuild-counters');
				const logEl = document.getElementById('wcsm-rebuild-log');

				const ajaxUrl = <?php echo wp_json_encode($ajax); ?>;
				const nonce = <?php echo wp_json_encode($nonce); ?>;

				let running = false;

				async function runPage(page, perPage) {
					const form = new FormData();
					form.append('action', 'wcsm_rebuild_supplier_index');
					form.append('nonce', nonce);
					form.append('page', String(page));
					form.append('per_page', String(perPage));

					let res, text;
					try {
						res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
					} catch (netErr) {
						throw new Error('Network error: ' + netErr.message);
					}

					// Read text first so we can show body on parse errors
					text = await res.text();

					let json;
					try {
						json = JSON.parse(text);
					} catch (e) {
						const snippet = text ? (text.substring(0, 500) + (text.length > 500 ? '…' : '')) : '(empty)';
						throw new Error('Bad JSON from server (HTTP ' + res.status + '): ' + snippet);
					}

					if (!json.success) {
						const msg = (json.data && json.data.message) ? json.data.message : 'Server returned an error.';
						// Include extra trace if present (debug mode)
						const trace = (json.data && json.data.trace) ? '\n' + json.data.trace : '';
						throw new Error(msg + trace);
					}
					return json.data;
				}

				function writeLog(line) {
					logEl.textContent += (logEl.textContent ? '\n' : '') + line;
				}

				async function start(perPage) {
					if (running) return;
					running = true;
					btn.disabled = true;
					status.textContent = <?php echo wp_json_encode(__('Starting…', 'wc-supplier-manager')); ?>;
					logEl.textContent = '';
					bar.value = 0; bar.max = 1; // will update once we know maxPages
					ctrs.textContent = '';

					let page = 1, totalFixed = 0, totalProcessed = 0, maxPages = 1;

					try {
						while (true) {
							const data = await runPage(page, perPage);

							// Update totals/progress
							maxPages = data.maxPages || 1;
							bar.max = Math.max(1, maxPages);
							bar.value = Math.min(page, maxPages);

							totalFixed += data.fixed || 0;
							totalProcessed += data.processed || 0;

							status.textContent = <?php echo wp_json_encode(__('Processing…', 'wc-supplier-manager')); ?> +
								' ' + <?php echo wp_json_encode(__('Page', 'wc-supplier-manager')); ?> + ' ' + page + ' / ' + maxPages;

							ctrs.textContent = <?php echo wp_json_encode(__('Processed', 'wc-supplier-manager')); ?> +
								': ' + totalProcessed + ' — ' +
								<?php echo wp_json_encode(__('Updated', 'wc-supplier-manager')); ?> +
								': ' + totalFixed;

							writeLog('Page ' + page + ': processed ' + (data.processed || 0) + ', updated ' + (data.fixed || 0));

							if (data.done || page >= maxPages) break;
							page++;
						}

						status.textContent = <?php echo wp_json_encode(__('Done.', 'wc-supplier-manager')); ?>;
						writeLog('Completed. Total processed: ' + totalProcessed + ', updated: ' + totalFixed);
					} catch (e) {
						status.textContent = <?php echo wp_json_encode(__('Error', 'wc-supplier-manager')); ?> + ': ' + e.message;
						writeLog('ERROR: ' + e.message);
						writeLog('Tip: Try a lower "Per page" value (e.g. 50 or 100) and run again.');
					} finally {
						btn.disabled = false;
						running = false;
					}
				}

				// Add a simple prompt for perPage so admins can tune batch size on failures
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					const def = '200';
					const input = prompt(<?php echo wp_json_encode(__('How many orders per batch? (10–500)', 'wc-supplier-manager')); ?>, def);
					if (input === null) return;
					const per = Math.max(10, Math.min(500, parseInt(input, 10) || 200));
					start(per);
				});
			})();
		</script>
		<?php
	}

	public static function hooks(): void
	{
		add_action('admin_post_wcsm_save_general', [__CLASS__, 'handle_save']);
	}

	public static function handle_save(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have permission.', 'wc-supplier-manager'));
		}
		check_admin_referer(self::NONCE);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fmt = isset($_POST['wcsm_display_format']) ? sanitize_key(wp_unslash($_POST['wcsm_display_format'])) : 'display_name';

		$valid = array_keys(Options::display_format_choices());
		if (!in_array($fmt, $valid, true)) {
			$fmt = 'display_name';
		}

		update_option(Options::OPTION_DISPLAY_FORMAT, $fmt, false);

		wp_safe_redirect(add_query_arg([
			'page' => \WCSM\Admin\Settings\Menu::PAGE_SLUG,
			'tab' => 'general',
			'updated' => '1',
		], admin_url('admin.php')));
		exit;
	}
}