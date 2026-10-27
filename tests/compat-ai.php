<?php
/**
 * Compatibility tests with the official AI plugin's Connector Approval feature.
 * Runs on its own site (tests/blueprint-ai.json) because approval blocks every unapproved plugin.
 *
 * @package Tokenwarden
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$results = array();
$current = 'Connector Approval (AI plugin)';

function check( $ok, $message, $detail = null ) {
	global $results, $current;
	$results[] = array( 'group' => $current, 'ok' => (bool) $ok, 'test' => $message, 'detail' => $ok ? null : $detail );
}
function eq( $expected, $actual, $message ) {
	check( $expected === $actual, $message, array( 'expected' => $expected, 'actual' => $actual ) );
}
function rows() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Tokenwarden_Ledger::table() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore
}
function self_pending() {
	foreach ( (array) get_option( 'wpai_connector_approval_pending', array() ) as $p ) {
		if ( 0 === strpos( (string) ( $p['caller_basename'] ?? '' ), 'tokenwarden' ) ) {
			return true;
		}
	}
	return false;
}

wp_set_current_user( 1 );
check( is_plugin_active( 'ai/ai.php' ), 'AI plugin is active' );
check( class_exists( '\WordPress\AI\Connector_Approval\Http_Guard' ), 'Connector Approval code is loaded' );

$approval = Tokenwarden_Compat::connector_approval();
eq( true, $approval['active'], 'Tokenwarden detects that Connector Approval is on' );

// Tokenwarden's own screens must never make Tokenwarden an AI caller.
Tokenwarden_Compat::providers();
rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/overview' ) );
rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/setup' ) );
eq( false, self_pending(), 'loading Tokenwarden’s dashboard never asks for AI approval for Tokenwarden' );

$result = demo_caller_generate();
check( is_wp_error( $result ), 'an unapproved plugin is blocked by Connector Approval' );
$rows = rows();
$row  = end( $rows );
eq( 'blocked', $row['status'] ?? null, 'the blocked call is logged by Tokenwarden' );
eq( 'plugin:demo-caller', $row['source'] ?? null, 'the blocked call is attributed to the right plugin' );
check( false !== strpos( (string) ( $row['note'] ?? '' ), 'Connector Approval' ), 'the log says where to approve it', $row['note'] ?? null );

$approval = Tokenwarden_Compat::connector_approval();
$names    = wp_list_pluck( $approval['pending'], 'name' );
check( in_array( 'Demo Caller (test fixture)', $names, true ), 'the plugin appears in Tokenwarden’s “waiting for approval” list', $names );
eq( false, self_pending(), 'Tokenwarden still never asks for approval itself' );

$entry = $approval['pending'][0];
( new \WordPress\AI\Connector_Approval\Approvals_Store() )->set_approval( $entry['basename'], $entry['connector'], true );
$result = demo_caller_generate();
check( ! is_wp_error( $result ), 'after approval the plugin’s call goes through', is_wp_error( $result ) ? $result->get_error_message() : null );
$rows = rows();
eq( 'ok', end( $rows )['status'] ?? null, 'the approved call is logged as completed' );

$passed = count( array_filter( array_column( $results, 'ok' ) ) );
file_put_contents( '/out/results-ai.json', wp_json_encode( array( 'passed' => $passed, 'failed' => count( $results ) - $passed, 'results' => $results ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
