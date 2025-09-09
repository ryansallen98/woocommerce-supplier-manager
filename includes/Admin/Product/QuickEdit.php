<?php
namespace WCSM\Admin\Product;

if (!defined('ABSPATH')) {
    exit;
}

class QuickEdit
{
    const META_SUPPLIER_ID = '_wcsm_supplier_id';
    const META_SUPPLIER_PRICE = '_wcsm_supplier_price';

    public static function init(): void
    {
        // Render our fields inside Woo's Quick Edit left column.
        add_action('woocommerce_product_quick_edit_end', [__CLASS__, 'render_wc_quick_edit_fields']);

        // Inline data per row to prefill Quick Edit.
        add_action('manage_product_posts_custom_column', [__CLASS__, 'print_inline_data'], 10, 2);

        // Save handler (Woo calls this after inline save).
        add_action('woocommerce_product_quick_edit_save', [__CLASS__, 'save_quick_edit'], 10, 1);

        // Small admin JS/CSS.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

        add_action('bulk_edit_custom_box', [__CLASS__, 'render_bulk_edit_fields'], 20, 2);
        add_action('load-edit.php', [__CLASS__, 'handle_bulk_edit']);
    }

    /**
     * Renders Supplier + Supplier price fields in Woo Quick Edit.
     * Appears under the "Product data" block on the left.
     */
    public static function render_wc_quick_edit_fields(): void
    {
        // Supplier options
        $options = [0 => __('— No supplier —', 'wc-supplier-manager')];
        $users = get_users([
            'role' => 'supplier',
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        foreach ($users as $user) {
            $label = sprintf('%s (%s)', $user->display_name ?: $user->user_email, $user->user_email);
            $options[$user->ID] = $label;
        }

        $placeholder = function_exists('wc_format_localized_price') ? wc_format_localized_price(0) : '0';
        ?>
        <!-- Row 1: Supplier -->
        <div class="inline-edit-group wcsm-quick-edit-group">
            <label class="alignleft">
                <span class="title"><?php esc_html_e('Supplier', 'wc-supplier-manager'); ?></span>
                <span class="input-text-wrap">
                    <select name="wcsm_supplier_id" class="wcsm-qe-supplier">
                        <?php foreach ($options as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </label>
            <br class="clear" />
        </div>

        <!-- Row 2: Supplier price (shown only when simple + supplier selected) -->
        <div class="inline-edit-group wcsm-quick-edit-group wcsm-quick-edit-price">
            <label class="alignleft wcsm-qe-price-wrap">
                <span class="title wcsm-nowrap">
                    <?php
                    printf(
                        /* translators: %s = currency symbol */
                        esc_html__('Supplier price (%s)', 'wc-supplier-manager'),
                        esc_html(function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '')
                    );
                    ?>
                </span>
                <span class="input-text-wrap">
                    <input type="text" name="wcsm_supplier_price" class="wcsm-qe-price" autocomplete="off"
                        placeholder="<?php echo esc_attr($placeholder); ?>" />
                </span>
                <span class="description" style="display:block;margin-top:4px;">
                    <?php esc_html_e('Leave empty to use Regular price. Only applies to simple products.', 'wc-supplier-manager'); ?>
                </span>
            </label>
            <br class="clear" />
        </div>
        <?php
    }

    /**
     * Prints one hidden div per row with data-* so JS can prefill Quick Edit.
     * Fires for each column; we output only on the "name" column.
     */
    public static function print_inline_data($column, $post_id): void
    {
        if ('name' !== $column) {
            return;
        }
        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }
        $supplier_id = (int) get_post_meta($post_id, self::META_SUPPLIER_ID, true);
        $supplier_price = get_post_meta($post_id, self::META_SUPPLIER_PRICE, true); // '' or decimal string
        $type = $product->get_type();

        printf(
            '<div class="wcsm-inline" id="wcsm-inline-%1$d" data-id="%1$d" data-type="%2$s" data-supplier="%3$d" data-supplier-price="%4$s"></div>',
            (int) $post_id,
            esc_attr($type),
            $supplier_id > 0 ? $supplier_id : 0,
            esc_attr((string) $supplier_price)
        );
    }

    /**
     * Save Quick Edit fields.
     * Woo sometimes passes an ID; normalize to a WC_Product.
     *
     * @param \WC_Product|int $product_or_id
     */
    public static function save_quick_edit($product_or_id): void
    {
        $product = is_numeric($product_or_id) ? wc_get_product((int) $product_or_id) : $product_or_id;
        if (!$product instanceof \WC_Product) {
            return;
        }
        $post_id = $product->get_id();
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by core inline edit
        $supplier_raw = isset($_REQUEST['wcsm_supplier_id']) ? wp_unslash($_REQUEST['wcsm_supplier_id']) : null;
        $price_raw = isset($_REQUEST['wcsm_supplier_price']) ? wp_unslash($_REQUEST['wcsm_supplier_price']) : null;

        // 1) Save supplier (0 clears)
        if (null !== $supplier_raw) {
            $val = absint($supplier_raw);
            if ($val > 0) {
                $user = get_user_by('id', $val);
                if ($user && in_array('supplier', (array) $user->roles, true)) {
                    update_post_meta($post_id, self::META_SUPPLIER_ID, $val);
                } else {
                    delete_post_meta($post_id, self::META_SUPPLIER_ID);
                }
            } else {
                delete_post_meta($post_id, self::META_SUPPLIER_ID);
            }
        }

        // 2) Save supplier price only when:
        //    - product is SIMPLE
        //    - AND a supplier is selected (>0)
        if (null !== $price_raw) {
            $supplier_id = (int) get_post_meta($post_id, self::META_SUPPLIER_ID, true);
            if ('simple' === $product->get_type() && $supplier_id > 0) {
                $val = function_exists('wc_format_decimal') ? wc_format_decimal($price_raw) : trim((string) $price_raw);
                if ('' === $val) {
                    delete_post_meta($post_id, self::META_SUPPLIER_PRICE); // null -> fallback to regular
                } else {
                    update_post_meta($post_id, self::META_SUPPLIER_PRICE, $val);
                }
            }
        }
    }

    /**
     * JS/CSS to prefill and to toggle Supplier price visibility.
     * - Hide price for non-simple products
     * - Hide price when Supplier = "No supplier"
     * - Save fix: ensure price input is disabled when hidden so it won't submit stale data
     */
    public static function enqueue_admin_assets($hook): void
    {
        // Only on Products list table
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        if ('edit.php' !== $hook || 'product' !== $post_type) {
            return;
        }

        // Use NOWDOC so $row / $wrap / $data etc. aren't interpolated by PHP
        $js = <<<'JS'
	(function($){
		'use strict';

		function togglePriceVisibility($row){
			var supplier = parseInt($row.find('select.wcsm-qe-supplier').val(), 10) || 0;
			var type     = $row.attr('data-wcsm-type') || '';
			var $wrap    = $row.find('.wcsm-qe-price-wrap');

			// Only show when simple + supplier selected
			var show = (type === 'simple' && supplier > 0);

			if (!show) {
				$wrap.hide().find('input').prop('disabled', true).val('');
			} else {
				$wrap.show().find('input').prop('disabled', false);
			}
		}

		// Intercept Quick Edit open
		var _edit = inlineEditPost.edit;
		inlineEditPost.edit = function(id){
			_edit.apply(this, arguments);

			var postId = 0;
			if (typeof id === 'object') { postId = parseInt(this.getId(id), 10) || 0; }
			if (!postId) return;

			var $row  = $('#edit-' + postId),
			    $data = $('#wcsm-inline-' + postId);
			if (!$row.length || !$data.length) return;

			// Stash product type for this row
			$row.attr('data-wcsm-type', ($data.data('type') || '').toString());

			// Prefill supplier + price
			var supplier = parseInt($data.data('supplier'), 10) || 0;
			var price    = ($data.data('supplier-price') || '').toString();

			$row.find('select.wcsm-qe-supplier').val(supplier);
			$row.find('input.wcsm-qe-price').val(price);

			// Initial visibility + change handler
			togglePriceVisibility($row);
			$row.find('select.wcsm-qe-supplier').off('change.wcsm').on('change.wcsm', function(){
				togglePriceVisibility($row);
			});
		};
	})(jQuery);
	JS;

        wp_add_inline_script('inline-edit-post', $js);

        // Minor alignment + no-wrap for the price label
        wp_add_inline_style(
            'common',
            '.wcsm-quick-edit-group .alignleft{margin-right:12px;}'
            . '.wcsm-quick-edit-group .input-text-wrap input[type=text]{width:140px;}'
            . '.wcsm-quick-edit-group select.wcsm-qe-supplier{min-width:220px;}'
            . '.wcsm-nowrap{white-space:nowrap;}'
        );
    }

    /**
     * Render Supplier field in the Bulk Edit panel.
     */
    public static function render_bulk_edit_fields($column_name, $post_type): void
    {
        if ('product' !== $post_type || 'name' !== $column_name) {
            return;
        }

        // Build supplier options
        $options = [
            '-1' => __('— No change —', 'wc-supplier-manager'), // sentinel
            '0' => __('— No supplier —', 'wc-supplier-manager'),
        ];
        $users = get_users([
            'role' => 'supplier',
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        foreach ($users as $user) {
            $label = sprintf('%s (%s)', $user->display_name ?: $user->user_email, $user->user_email);
            $options[(string) $user->ID] = $label;
        }
        ?>
        <div class="inline-edit-group wcsm-bulk-edit-group">
            <label class="alignleft">
                <span class="title"><?php esc_html_e('Supplier', 'wc-supplier-manager'); ?></span>
                <span class="input-text-wrap">
                    <select name="wcsm_bulk_supplier_id" class="wcsm-bulk-supplier">
                        <?php foreach ($options as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </label>
            <br class="clear" />
        </div>
        <?php
    }


    /**
     * Handle Bulk Edit save for Supplier field.
     * Runs on load-edit.php when bulk action "Edit" is submitted.
     */
    public static function handle_bulk_edit(): void
    {
        // Check we are on the Products list and bulk edit is being processed
        $screen = get_current_screen();
        if (!$screen || 'edit-product' !== $screen->id) {
            return;
        }

        // Only run for the core bulk "Edit" action
        if (!isset($_REQUEST['action']) || 'edit' !== $_REQUEST['action']) {
            return;
        }

        // Required fields from Bulk Edit form
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- bulk-posts nonce checked below
        $post_ids = isset($_REQUEST['post']) ? array_map('intval', (array) $_REQUEST['post']) : [];
        if (empty($post_ids)) {
            return;
        }

        // Verify nonce used by Bulk Edit
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-posts')) {
            return;
        }

        // Our field (may be absent if user didn't touch Bulk Edit UI)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $supplier_raw = isset($_REQUEST['wcsm_bulk_supplier_id']) ? wp_unslash($_REQUEST['wcsm_bulk_supplier_id']) : null;
        if (null === $supplier_raw) {
            return; // nothing to do
        }

        // '-1' means "No change" -> don't touch selected posts
        if ('-1' === (string) $supplier_raw) {
            return;
        }

        $val = (int) $supplier_raw;

        // If a positive supplier ID, validate it exists and has supplier role
        $valid_supplier = false;
        if ($val > 0) {
            $user = get_user_by('id', $val);
            $valid_supplier = ($user && in_array('supplier', (array) $user->roles, true));
        }

        // Apply to each selected product
        foreach ($post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }
            $product = wc_get_product($post_id);
            if (!$product) {
                continue;
            }

            if (0 === $val) {
                // "— No supplier —" => clear
                delete_post_meta($post_id, self::META_SUPPLIER_ID);
                // Do NOT touch supplier price on bulk clear
            } elseif ($val > 0 && $valid_supplier) {
                update_post_meta($post_id, self::META_SUPPLIER_ID, $val);
                // (Intentionally not touching supplier price in bulk edit)
            }
        }
    }
}