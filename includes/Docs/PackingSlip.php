<?php
namespace WCSM\Docs;

use Dompdf\Dompdf;
use Dompdf\Options;
use WCSM\Support\TemplateLoader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PackingSlip {

	/**
	 * Stream a supplier-specific packing slip as PDF.
	 */
	public static function stream_for_supplier( \WC_Order $order, int $supplier_id ): void {
		$html = TemplateLoader::capture( 'myaccount/packing-slip.php', [
			'order'       => $order,
			'supplier_id' => $supplier_id,
		] );

		$filename = sprintf(
			'packing-slip-%s-supplier-%d.pdf',
			$order->get_order_number(),
			$supplier_id
		);

		$options = new Options();
		$options->set( 'isRemoteEnabled', true );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$dompdf->stream( $filename, [ 'Attachment' => true ] );
		exit;
	}
}