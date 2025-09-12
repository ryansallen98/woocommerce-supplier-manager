<?php
namespace WCSM\Admin\Tools;

use WCSM\Orders\Utils;

if (!defined('ABSPATH')) exit;

class SupplierIndexRebuilder {
	const AJAX_ACTION = 'wcsm_rebuild_supplier_index';
	const NONCE       = 'wcsm_rebuild_supplier_index_nonce';

	public static function init(): void {
		add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax']);
	}

	private static function debug_enabled(): bool {
		return ( defined('WCSM_DEBUG') && WCSM_DEBUG ) || ( defined('WP_DEBUG') && WP_DEBUG );
	}

	public static function ajax(): void {
		// Always JSON; avoid stray output corrupting JSON:
		if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
		@header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'utf-8' ) );
		ob_start();

		try {
			if (!current_user_can('manage_woocommerce')) {
				wp_send_json_error(['message' => __('Permission denied.', 'wc-supplier-manager')], 403);
			}
			check_ajax_referer(self::NONCE, 'nonce');

			$page     = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
			$per_page = isset($_POST['per_page']) ? max(1, min(500, (int) $_POST['per_page'])) : 100;

			// Build all statuses (slugs without wc-)
			$all_statuses = array_map(
				static function ($k) { return preg_replace('/^wc-/', '', $k); },
				array_keys(wc_get_order_statuses())
			);

			$args = [
				'type'     => 'shop_order',
				'status'   => $all_statuses,
				'paginate' => true,
				'paged'    => $page,
				'limit'    => $per_page,
				'return'   => 'ids',
				'orderby'  => 'ID',
				'order'    => 'ASC',
			];

			$results   = wc_get_orders($args);
			$ids       = [];
			$total     = 0;
			$max_pages = 1;

			// Correctly normalize WooCommerce paginated shape
			if ( is_array($results) && array_key_exists('orders', $results) ) {
				$ids       = is_array($results['orders']) ? array_map('intval', $results['orders']) : [];
				$total     = (int) ( $results['total'] ?? count($ids) );
				$max_pages = (int) ( $results['max_num_pages'] ?? 1 );
			} elseif ( is_object($results) && isset($results->orders) ) {
				$ids       = is_array($results->orders) ? array_map('intval', $results->orders) : [];
				$total     = (int) ( $results->total ?? count($ids) );
				$max_pages = (int) ( $results->max_num_pages ?? 1 );
			} else {
				// Defensive fallback (filters/versions)
				$ids   = is_array($results) ? array_map('intval', $results) : [];
				$total = count($ids);
				$max_pages = max(1, (int)ceil($total / max(1, $per_page)));
			}

			$fixed = 0;
			$processed = 0;

			foreach ($ids as $order_id) {
				if ($order_id <= 0) { continue; }
				$order = wc_get_order($order_id);
				if (!$order) { continue; }

				$processed++;

				$groups   = Utils::group_items_by_supplier($order);
				$expected = ',' . implode(',', array_map('intval', array_keys($groups))) . ',';
				$current  = (string) $order->get_meta(Utils::META_ORDER_SUPPLIERS, true);

				if ($current !== $expected) {
					$order->update_meta_data(Utils::META_ORDER_SUPPLIERS, $expected);
					$order->save();
					$fixed++;
				}
			}

			// Flush ob to avoid stray output:
			ob_end_clean();
			wp_send_json_success([
				'page'       => $page,
				'perPage'    => $per_page,
				'fixed'      => $fixed,
				'processed'  => $processed,
				'total'      => $total,
				'maxPages'   => max(1, $max_pages),
				'done'       => ($page >= max(1, $max_pages)),
			]);
		} catch ( \Throwable $e ) {
			@ob_end_clean();
			$base = sprintf(
				'WCSM rebuild error on page %d: %s',
				isset($page) ? (int)$page : 0,
				$e->getMessage()
			);
			// Log full error server-side:
			error_log('[WCSM] ' . $base . ' in ' . $e->getFile() . ':' . $e->getLine());

			$data = [ 'message' => $base ];
			if ( self::debug_enabled() ) {
				$data['trace'] = $e->getTraceAsString();
			}
			wp_send_json_error( $data, 500 );
		}
	}
}