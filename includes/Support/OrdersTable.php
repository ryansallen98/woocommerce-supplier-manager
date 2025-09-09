<?php
namespace WCSM\Support;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

class OrdersTable
{
    /**
     * Default renderer that prints based on type/format/options and a value callback.
     */
    private static function default_renderer(
        string $type,
        ?string $format,
        ?array $options,
        ?callable $valueCb
    ): callable {
        return static function (WC_Order $order) use ($type, $format, $options, $valueCb) {
            if (!is_callable($valueCb)) {
                echo esc_html__('—', 'wc-supplier-manager');
                return;
            }
            $raw = \call_user_func($valueCb, $order);

            if ($type === 'enum') {
                $label = $options[$raw] ?? (string) $raw;
                echo esc_html($label !== '' ? $label : '—');
                return;
            }

            switch ($format ?: $type) {
                case 'price':
                    echo wp_kses_post(wc_price((float) $raw));
                    break;

                case 'number':
                    echo esc_html(wc_format_decimal($raw));
                    break;

                case 'date':
                    if ($raw instanceof \WC_DateTime) {
                        echo esc_html($raw->date_i18n(get_option('date_format')));
                    } elseif (is_numeric($raw)) {
                        echo esc_html(date_i18n(get_option('date_format'), (int) $raw));
                    } else {
                        echo esc_html((string) $raw ?: '—');
                    }
                    break;

                default: // text
                    $out = (string) $raw;
                    echo $out !== '' ? esc_html($out) : esc_html__('—', 'wc-supplier-manager');
            }
        };
    }

    public static function get_columns(): array
    {
        // Build enum options once for order statuses.
        $order_status_options = (static function () {
            $opts = [];
            foreach (wc_get_order_statuses() as $key => $label) {
                $opts[str_replace('wc-', '', $key)] = $label;
            }
            return $opts;
        })();

        $defaults = [
            'order-number' => [
                'label' => __('Order', 'wc-supplier-manager'),
                'order' => 10,
                'th_class' => 'order-number',
                'td_class' => 'order-number',
                'type' => 'number',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'search',
                'value' => static fn(WC_Order $o) => (int) $o->get_id(),
                'render' => static function (WC_Order $o) {
                    echo '#' . esc_html($o->get_id());
                },
            ],

            'order-date' => [
                'label' => __('Date', 'wc-supplier-manager'),
                'order' => 20,
                'th_class' => 'order-date',
                'td_class' => 'order-date',
                'type' => 'date',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'range',
                'value' => static fn(WC_Order $o) => $o->get_date_created(), // WC_DateTime
                // default renderer will format date
            ],

            'order-customer' => [
                'label' => __('Customer', 'wc-supplier-manager'),
                'order' => 25,
                'th_class' => 'order-customer',
                'td_class' => 'order-customer',
                'type' => 'text',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'search',
                'value' => static function (WC_Order $o) {
                    $name = trim((string) $o->get_formatted_shipping_full_name());
                    if ($name === '') {
                        $name = trim((string) $o->get_formatted_billing_full_name());
                    }
                    return $name; // default renderer prints a dash if empty
                },
            ],

            'order-items' => [
                'label' => __('Items', 'wc-supplier-manager'),
                'order' => 35,
                'th_class' => 'order-items',
                'td_class' => 'order-items',
                'type' => 'text',
                'visible' => true,
                'sortable' => false,      // complex HTML; set true only if you supply a useful 'value'
                'filterable' => 'search',
                'value' => static fn(WC_Order $o) => '', // (optional) could build a flat string of items for searching
                'render' => static function (WC_Order $order) {
                    $supplier_id = get_current_user_id();

                    $groups = \WCSM\Orders\Utils::group_items_by_supplier($order);
                    $items = $groups[$supplier_id] ?? [];

                    if (empty($items)) {
                        echo esc_html__('—', 'wc-supplier-manager');
                        return;
                    }

                    $normalize = static function ($keyOrLabel) {
                        $k = strtolower(wc_clean((string) $keyOrLabel));
                        $k = preg_replace('/^attribute_/', '', $k);
                        $k = preg_replace('/^pa_/', '', $k);
                        $k = str_replace([' ', '-'], '', $k);
                        return $k;
                    };

                    echo '<ul class="wcsm-order-items">';
                    foreach ($items as $item) {
                        $qty = $item->get_quantity();
                        $product = $item->get_product();

                        $base_title = $product
                            ? ($product->is_type('variation') && $product->get_parent_id()
                                ? get_the_title($product->get_parent_id())
                                : $product->get_name()
                            )
                            : $item->get_name();

                        echo '<li>' . esc_html($base_title) . ' × ' . esc_html($qty);

                        $sub = [];
                        $handled = [];

                        // (a) Variation attributes from the product
                        if ($product && $product->is_type('variation')) {
                            foreach ($product->get_attributes() as $attr_key => $attr_val) {
                                $tax_key = str_replace('attribute_', '', $attr_key);
                                $label = wc_attribute_label($tax_key, $product);

                                if (taxonomy_exists($tax_key)) {
                                    $term = get_term_by('slug', (string) $attr_val, $tax_key);
                                    $value = $term ? $term->name : $attr_val;
                                } else {
                                    $value = $attr_val;
                                }

                                $value = is_string($value) ? wc_clean($value) : $value;
                                if ($value !== '') {
                                    $sub[] = sprintf(
                                        '%s: %s',
                                        esc_html($label ?: ucfirst(str_replace('_', ' ', $tax_key))),
                                        esc_html((string) $value)
                                    );
                                    $handled[$normalize($tax_key)] = true;
                                    $handled[$normalize($label)] = true;
                                    $handled[$normalize('attribute_' . $tax_key)] = true;
                                }
                            }
                        }

                        // (b) Visible item meta (skip hidden + duplicates)
                        foreach ($item->get_meta_data() as $meta) {
                            $data = $meta->get_data();
                            $key = $data['key'] ?? '';
                            if ($key === '' || $key[0] === '_') {
                                continue;
                            }

                            $normKey = $normalize($key);
                            if (isset($handled[$normKey])) {
                                continue;
                            }

                            $label = wc_attribute_label($key) ?: $key;
                            $normLbl = $normalize($label);
                            if (isset($handled[$normLbl])) {
                                continue;
                            }

                            $label = wc_clean($label);
                            $val = $data['value'] ?? '';
                            $val_out = is_string($val) ? wp_kses_post($val) : esc_html(wc_clean((string) $val));

                            $sub[] = sprintf('%s: %s', esc_html($label), $val_out);
                            $handled[$normKey] = true;
                            $handled[$normLbl] = true;
                        }

                        if (!empty($sub)) {
                            echo '<ul class="wcsm-item-meta">';
                            foreach ($sub as $line) {
                                echo '<li>' . $line . '</li>';
                            }
                            echo '</ul>';
                        }

                        echo '</li>';
                    }
                    echo '</ul>';
                },
            ],

            'order-status' => [
                'label' => __('Order Status', 'wc-supplier-manager'),
                'order' => 30,
                'th_class' => 'order-status',
                'td_class' => 'order-status',
                'type' => 'enum',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'enum',
                'options' => $order_status_options,
                'value' => static fn(WC_Order $o) => $o->get_status(),
                // default renderer will map via options
            ],

            'order-supplier-status' => [
                'label' => __('Supplier Status', 'wc-supplier-manager'),
                'order' => 31,
                'th_class' => 'order-supplier-status',
                'td_class' => 'order-supplier-status',
                'type' => 'enum',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'enum',
                'options' => [
                    'pending' => __('Pending', 'wc-supplier-manager'),
                    'received' => __('Received', 'wc-supplier-manager'),
                    'sent' => __('Sent', 'wc-supplier-manager'),
                    'rejected' => __('Rejected', 'wc-supplier-manager'),
                ],
                'value' => static function (WC_Order $o) {
                    $supplier_id = get_current_user_id();
                    $ff = \WCSM\Orders\Utils::get_fulfilment($o);
                    return $ff[$supplier_id]['status'] ?? 'pending';
                },
            ],

            'order-total' => [
                'label' => __('Order Total', 'wc-supplier-manager'),
                'order' => 40,
                'th_class' => 'order-total',
                'td_class' => 'order-total',
                'type' => 'number',
                'format' => 'price',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'range',
                'value' => static fn(WC_Order $o) => (float) $o->get_total(),
            ],

            'order-supplier-total' => [
                'label' => __('Supplier Total', 'wc-supplier-manager'),
                'order' => 45,
                'th_class' => 'order-supplier-total',
                'td_class' => 'order-supplier-total',
                'type' => 'number',
                'format' => 'price',
                'visible' => true,
                'sortable' => true,
                'filterable' => 'range',
                'value' => static function (WC_Order $order) {
                    $supplier_id = get_current_user_id();

                    $groups = \WCSM\Orders\Utils::group_items_by_supplier($order);
                    $items = $groups[$supplier_id] ?? [];

                    $total = 0.0;
                    foreach ($items as $item) {
                        $prod = $item->get_product();
                        $qty = (int) $item->get_quantity();

                        $cost = 0.0;
                        if ($prod) {
                            if (\function_exists('wcsm_supplier_price_for_product_id')) {
                                $raw = \wcsm_supplier_price_for_product_id($prod->get_id());
                            } else {
                                $raw = get_post_meta($prod->get_id(), '_wcsm_supplier_price', true);
                            }
                            $cost = ($raw !== '') ? (float) wc_format_decimal($raw) : 0.0;
                        }

                        $total += $cost * max(0, $qty);
                    }
                    return $total;
                },
            ],

            'order-actions' => [
                'label' => __('Actions', 'wc-supplier-manager'),
                'order' => 100,
                'th_class' => 'order-actions',
                'td_class' => 'order-actions',
                'type' => 'action',
                'visible' => true,
                'value' => static fn() => null, // not used for sorting
                'render' => static function (WC_Order $order) {
                    $order_id = $order->get_id();
                    // Packing slip URL (same as the original template)
                    $slip_url = add_query_arg(
                        [
                            'wcsm_packing_slip' => 1,
                            'order_id' => $order_id,
                            '_wpnonce' => wp_create_nonce('wcsm_download_slip'),
                        ],
                        wc_get_account_endpoint_url(\WCSM\Accounts\SupplierOrdersEndpoint::ENDPOINT)
                    );

                    echo '<a class="button" href="' . esc_url($slip_url) . '">'
                        . esc_html__('Packing slip', 'wc-supplier-manager') . '</a> ';

                    // Toggle button to reveal the inline form row
                    echo '<button type="button" class="button wcsm-toggle" aria-expanded="false" '
                        . 'data-target="#wcsm-order-form-' . esc_attr($order_id) . '">'
                        . esc_html__('Update', 'wc-supplier-manager') . '</button>';
                },
            ],
        ];

        // Allow 3rd-parties to modify the columns.
        $columns = apply_filters('wcsm_supplier_orders_columns', $defaults);

        // Attach default renderers if missing, and ensure visibility.
        foreach ($columns as $key => &$col) {
            if (isset($col['visible']) && !$col['visible']) {
                continue;
            }
            $type = $col['type'] ?? 'text';
            $format = $col['format'] ?? null;
            $opts = $col['options'] ?? null;

            if (!isset($col['render'])) {
                $col['render'] = self::default_renderer(
                    $type,
                    $format,
                    is_array($opts) ? $opts : null,
                    $col['value'] ?? null
                );
            }
        }
        unset($col);

        // Drop invisible, then sort by 'order'.
        $columns = array_filter($columns, static fn($c) => !isset($c['visible']) || (bool) $c['visible']);
        uasort($columns, static function ($a, $b) {
            $ao = isset($a['order']) ? (int) $a['order'] : 50;
            $bo = isset($b['order']) ? (int) $b['order'] : 50;
            return $ao <=> $bo;
        });

        return $columns;
    }
}