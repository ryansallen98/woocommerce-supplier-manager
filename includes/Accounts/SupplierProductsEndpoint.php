<?php
namespace WCSM\Accounts;

use WCSM\Support\TemplateLoader;

if (!defined('ABSPATH')) {
    exit;
}

class SupplierProductsEndpoint
{
    const ENDPOINT = 'supplier-products';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item'], 5);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render_endpoint']);
    }

    public static function add_endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function add_menu_item(array $items): array
    {
        if (!self::current_user_is_supplierish()) {
            return $items;
        }
        $label = __('Supplier products', 'wc-supplier-manager');

        if (empty($items) || !is_array($items)) {
            return ['dashboard' => __('Dashboard', 'woocommerce'), self::ENDPOINT => $label];
        }

        $new = [];
        $inserted = false;
        foreach ($items as $key => $text) {
            $new[$key] = $text;
            if ('dashboard' === $key && !$inserted) {
                $new[self::ENDPOINT] = $label;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new[self::ENDPOINT] = $label;
        }
        return $new;
    }

    public static function render_endpoint(): void
    {
        if (!self::current_user_is_supplierish()) {
            wc_print_notice(__('You do not have permission to view supplier products.', 'wc-supplier-manager'), 'error');
            return;
        }

        $user_id = get_current_user_id();

        // Handle POST (updates) first
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            self::handle_post_update((int) $user_id);
        }

        // Read filters (use "q" for search to avoid WP's ?s= search-mode)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = wp_unslash($_GET);
        $per_page = isset($raw['per_page']) ? max(1, min(100, (int) $raw['per_page'])) : 10;
        $paged = isset($raw['paged']) ? max(1, (int) $raw['paged']) : 1;
        $search = isset($raw['q']) ? sanitize_text_field($raw['q']) : '';
        $type = isset($raw['type']) ? sanitize_text_field($raw['type']) : 'all'; // all|simple|variable
        $stock = isset($raw['stock']) ? sanitize_text_field($raw['stock']) : 'all'; // all|instock|outofstock
        $orderby = isset($raw['orderby']) ? sanitize_text_field($raw['orderby']) : 'date'; // date|title|price|sku|stock
        $order = isset($raw['order']) ? strtoupper(sanitize_text_field($raw['order'])) : 'DESC';
        $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        // Build meta/tax filters (PARENTS ONLY; exclude grouped/external)
        $meta_query = [
            [
                'key' => '_wcsm_supplier_id',
                'value' => (string) $user_id,
                'compare' => '=',
            ],
        ];
        if (in_array($stock, ['instock', 'outofstock'], true)) {
            $meta_query[] = [
                'key' => '_stock_status',
                'value' => $stock,
                'compare' => '=',
            ];
        }

        $tax_query = [
            'relation' => 'AND',
            [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => ['grouped', 'external'],
                'operator' => 'NOT IN', // hard exclude grouped/external
            ],
        ];
        if (in_array($type, ['simple', 'variable'], true)) {
            $tax_query[] = [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => [$type],
            ];
        }

        // Sorting
        $wp_orderby = 'date';
        $meta_key = '';
        $meta_type = '';
        switch ($orderby) {
            case 'title':
                $wp_orderby = 'title';
                break;
            case 'price':
                $wp_orderby = 'meta_value_num';
                $meta_key = '_price';
                $meta_type = 'DECIMAL';
                break;
            case 'sku':
                $wp_orderby = 'meta_value';
                $meta_key = '_sku';
                break;
            case 'stock':
                $wp_orderby = 'meta_value';
                $meta_key = '_stock_status';
                break;
            default:
                $wp_orderby = 'date';
        }

        $args = [
            'post_type' => 'product', // parents only
            'post_status' => ['publish', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => $wp_orderby,
            'order' => $order,
            'meta_query' => $meta_query,
            'tax_query' => $tax_query,
            'fields' => 'ids',
            'no_found_rows' => false,
        ];

        if ($search !== '') {
            $args['s'] = $search;
            // If search looks like SKU, also match _sku loosely
            if (preg_match('/^[A-Z0-9\-\._]+$/i', $search)) {
                $args['meta_query'][] = [
                    'key' => '_sku',
                    'value' => $search,
                    'compare' => 'LIKE',
                ];
            }
        }
        if ($meta_key) {
            $args['meta_key'] = $meta_key;
            if ($meta_type) {
                $args['meta_type'] = $meta_type;
            }
        }

        $q = new \WP_Query($args);

        $product_ids = array_map('intval', (array) $q->posts);
        $products = array_map('wc_get_product', $product_ids);
        $products = array_values(array_filter($products));

        // Build pagination/base URL off the endpoint URL
        $endpoint_url = wc_get_account_endpoint_url(self::ENDPOINT);
        $preserve = array_filter([
            'q' => $search,
            'type' => $type,
            'stock' => $stock,
            'orderby' => $orderby,
            'order' => $order,
            'per_page' => $per_page,
        ], static function ($v) {
            return $v !== '' && $v !== 'all';
        });
        $base_url = add_query_arg($preserve, $endpoint_url);

        TemplateLoader::get('myaccount/supplier-products.php', [
            'products' => $products,
            'user_id' => $user_id,
            'controls' => [
                'q' => $search,
                'type' => $type,
                'stock' => $stock,
                'orderby' => $orderby,
                'order' => $order,
                'per_page' => $per_page,
            ],
            'pagination' => [
                'current' => max(1, (int) get_query_var('paged', $paged)),
                'total' => (int) $q->max_num_pages,
                'base' => $base_url,
                'total_items' => (int) $q->found_posts,
            ],
            'endpoint_url' => $endpoint_url,
        ]);
    }

    /* ===== Internals ===== */

    private static function current_user_is_supplierish(): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        return current_user_can(\WCSM\Setup\Roles\SupplierRole::CAP_SUPPLIER);
    }

    private static function product_belongs_to_supplier(int $product_id, int $supplier_id): bool
    {
        $parent_id = (int) wp_get_post_parent_id($product_id);
        $check_id = $parent_id ? $parent_id : $product_id;
        return (int) get_post_meta($check_id, '_wcsm_supplier_id', true) === $supplier_id;
    }

    private static function handle_post_update(int $supplier_id): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (empty($_POST['wcsm_sp_action']) || 'update' !== $_POST['wcsm_sp_action']) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wcsm_sp_update')) {
            wc_add_notice(__('Security check failed. Please try again.', 'wc-supplier-manager'), 'error');
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $ids = isset($_POST['wcsm_ids']) ? array_map('intval', (array) $_POST['wcsm_ids']) : [];
        if (empty($ids)) {
            wc_add_notice(__('No products to update.', 'wc-supplier-manager'), 'error');
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $stock_status = isset($_POST['wcsm_stock_status']) ? array_map('wc_clean', (array) $_POST['wcsm_stock_status']) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $backorders = isset($_POST['wcsm_backorders']) ? array_map('wc_clean', (array) $_POST['wcsm_backorders']) : [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $qty = isset($_POST['wcsm_qty']) ? (array) $_POST['wcsm_qty'] : [];

        $updated = 0;
        $parents_to_sync = [];

        foreach ($ids as $id) {
            if (!self::product_belongs_to_supplier($id, $supplier_id) && !current_user_can('manage_woocommerce')) {
                continue;
            }

            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            // Ignore variable parents if they somehow get posted
            if ($product->is_type('variable')) {
                continue;
            }

            $parent_id = (int) wp_get_post_parent_id($id);
            if ($parent_id) {
                $parents_to_sync[$parent_id] = true;
            }

            $touched = false;

            // Backorders
            if (isset($backorders[$id]) && in_array($backorders[$id], ['no', 'notify', 'yes'], true)) {
                if ($product->get_backorders() !== $backorders[$id]) {
                    $product->set_backorders($backorders[$id]);
                    $touched = true;
                }
            }

            // Quantity (only if managing stock)
            if ($product->managing_stock() && array_key_exists($id, $qty)) {
                $new_qty = wc_stock_amount($qty[$id]);
                $old_qty = $product->get_stock_quantity();
                if ((int) $new_qty !== (int) $old_qty) {
                    $product->set_stock_quantity($new_qty);
                    $touched = true;
                }
            }

            // Stock status — use helper, compare first
            if (isset($stock_status[$id]) && in_array($stock_status[$id], ['instock', 'outofstock', 'onbackorder'], true)) {
                if ($product->get_stock_status() !== $stock_status[$id]) {
                    wc_update_product_stock_status($id, $stock_status[$id]);
                    $touched = true;
                    // refresh object after helper
                    $product = wc_get_product($id);
                }
            }

            if ($touched) {
                $product->save();
                $updated++;
            }
        }
        
        // Sync any affected variable parents so their derived status/stock is correct
        if (!empty($parents_to_sync)) {
            foreach (array_keys($parents_to_sync) as $pid) {
                $parent = wc_get_product($pid);
                if ($parent && $parent->is_type('variable') && class_exists('\WC_Product_Variable')) {
                    \WC_Product_Variable::sync($pid, true);
                }
                // Clear transients to be safe
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients($pid);
                }
            }
        }

        if ($updated) {
            wc_add_notice(
                sprintf(
                    /* translators: %d = number of products updated */
                    _n('%d item updated.', '%d items updated.', $updated, 'wc-supplier-manager'),
                    $updated
                ),
                'success'
            );
        } else {
            wc_add_notice(__('No changes saved.', 'wc-supplier-manager'), 'notice');
        }
    }
}