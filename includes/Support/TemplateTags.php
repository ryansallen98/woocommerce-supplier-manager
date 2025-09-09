<?php
namespace WCSM\Support;

use WCSM\Admin\Product\SupplierPricing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Get supplier price for a product/variation with fallback to regular price.
 */
function wcsm_get_supplier_price_for_product( \WC_Product $product ) {
	$meta = $product->get_meta( SupplierPricing::META_KEY, true );
	return $meta === '' ? $product->get_regular_price() : $meta;
}

/**
 * Human-friendly variation label ("Color: Blue, Size: M")
 */
function wcsm_variation_label( \WC_Product_Variation $variation ) {
	if ( function_exists( 'wc_get_formatted_variation' ) ) {
		return wc_get_formatted_variation( $variation, true );
	}
	$atts = [];
	foreach ( $variation->get_attributes() as $k => $v ) {
		$atts[] = trim( str_replace( 'attribute_', '', $k ) ) . ': ' . $v;
	}
	return implode( ', ', $atts );
}