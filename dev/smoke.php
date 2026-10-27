<?php
require '/wordpress/wp-load.php';
$out = array();
$out['active'] = is_plugin_active( 'tokenwarden/tokenwarden.php' );
$out['table']  = $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE '" . Tokenwarden_Ledger::table() . "'" );
$o = Tokenwarden_Stats::overview( 30 );
$out['kpis'] = $o['kpis'];
$out['month'] = $o['month'];
$out['providers'] = $o['providers'];
$out['stack'] = array_map( function( $s ) { return $s['label'] . ' ' . round( array_sum( $s['values'] ), 2 ); }, $o['series']['stack'] );
$out['sources'] = array_map( function( $s ) { return $s['label'] . ' ' . $s['status'] . ' month=' . $s['month'] . ' budget=' . $s['policy']['budget']; }, $o['sources'] );
$out['heat_total'] = array_sum( array_map( 'array_sum', $o['heatmap'] ) );
file_put_contents( '/out/smoke.json', json_encode( $out, JSON_PRETTY_PRINT ) );
