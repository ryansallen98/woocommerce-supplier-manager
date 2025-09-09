<?php
namespace WCSM\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

class Assets
{
    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
    }

    public static function enqueue_styles()
    {
        // Only load on My Account pages
        if (function_exists('is_account_page') && is_account_page()) {
            wp_enqueue_style(
                'wcsm-supplier-products',
                WCSM_URL . 'assets/css/frontend.css',
                ['woocommerce-general'], // depend on Woo base styles
                WCSM_VER
            );

            wp_enqueue_script(
                'wcsm-supplier-products',
                WCSM_URL . 'assets/js/frontend.js',
                ['jquery'],
                WCSM_VER,
                true
            );
        }
    }
}