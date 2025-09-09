<?php
/**
 * Pagination
 *
 * @var array $pagination
 */
defined( 'ABSPATH' ) || exit;

$p = $pagination ?? [];
if ( empty( $p ) || ( $p['total'] ?? 0 ) <= 1 ) {
	return;
}
?>
<nav class="woocommerce-pagination wcsm-pagination" style="margin-top:1rem;">
	<?php
	echo paginate_links( [
		'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $p['base'] ) ),
		'format'    => '',
		'current'   => max( 1, (int) ( $p['current'] ?? 1 ) ),
		'total'     => (int) ( $p['total'] ?? 1 ),
		'prev_text' => '&laquo;',
		'next_text' => '&raquo;',
		'type'      => 'list',
	] );
	?>
</nav>