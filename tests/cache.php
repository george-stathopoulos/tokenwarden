<?php
/**
 * Response caching integration tests. Included from run.php.
 *
 * @package Tokenwarden
 */

/**
 * Empty the cache and cache only the demo plugin.
 */
function cache_demo_caller() {
	reset_state();
	delete_option( Tokenwarden_Cache::OPTION );
	Tokenwarden_Cache::clear();
	Tokenwarden_Cache::update_settings( array( 'sources' => array( 'plugin:demo-caller' ) ) );
}

/* ------------------------------------------------------------------ */
group( 'Caching' );
cache_demo_caller();
$first  = demo_caller_generate( 'Is this spam? From alice@example.com: cheap watches' );
$second = demo_caller_generate( 'Is this spam? From bob@example.org: cheap watches' );
eq( 1, count( $GLOBALS['mock']['requests'] ), 'repeat is answered from the cache: provider called once' );
eq( 'Model saw: Is this spam? From alice@example.com: cheap watches', $first, 'first answer restored with its own data' );
eq( 'Model saw: Is this spam? From bob@example.org: cheap watches', $second, 'cached answer is restored with the second request’s data' );

global $wpdb;
$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT response FROM %i', Tokenwarden_Cache::table() ) ); // phpcs:ignore
check( false !== strpos( $stored, '[EMAIL_1]' ) && false === strpos( $stored, 'alice@example.com' ), 'cache stores placeholders, never the personal data', $stored );

$rows = rows();
eq( 0, (int) $rows[0]['cached'], 'first call logged as a normal call' );
eq( 1, (int) $rows[1]['cached'], 'second call logged as answered from the cache' );
eq( 0.0, (float) $rows[1]['cost'], 'cached call costs nothing' );
eq( 0.0054, round( (float) $rows[1]['saved'], 6 ), 'cached call records what it saved' );
eq( 0.0054, round( Tokenwarden_Ledger::month_spend( 'plugin:demo-caller' ), 6 ), 'month-to-date spend only counts the real call' );
$stats = Tokenwarden_Cache::stats();
eq( 1, $stats['entries'], 'one cache entry' );
eq( 1, $stats['cached'], 'one call served from the cache in 30 days' );
eq( 0.5, $stats['hit_rate'], 'hit rate counts cached calls among completed calls' );

demo_caller_generate( 'A different question' );
eq( 2, count( $GLOBALS['mock']['requests'] ), 'a different request is not served from the cache' );

Tokenwarden_Cache::update_settings( array( 'sources' => array() ) );
demo_caller_generate( 'Is this spam? From carol@example.net: cheap watches' );
eq( 3, count( $GLOBALS['mock']['requests'] ), 'sources not selected are never cached' );

Tokenwarden_Cache::update_settings( array( 'mode' => 'all' ) );
demo_caller_generate( 'Is this spam? From dave@example.net: cheap watches' );
eq( 3, count( $GLOBALS['mock']['requests'] ), '"All sources" mode caches every source' );

Tokenwarden_Cache::update_settings( array( 'enabled' => false ) );
demo_caller_generate( 'Is this spam? From erin@example.net: cheap watches' );
eq( 4, count( $GLOBALS['mock']['requests'] ), 'master switch turns caching off' );

cache_demo_caller();
$GLOBALS['mock']['status'] = 529;
demo_caller_generate( 'Fails' );
$GLOBALS['mock']['status'] = 200;
demo_caller_generate( 'Fails' );
eq( 2, count( $GLOBALS['mock']['requests'] ), 'provider errors are never cached' );

cache_demo_caller();
demo_caller_generate( 'Expire me' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s', Tokenwarden_Cache::table(), '2000-01-01 00:00:00' ) ); // phpcs:ignore
demo_caller_generate( 'Expire me' );
eq( 2, count( $GLOBALS['mock']['requests'] ), 'expired entries are not served' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s', Tokenwarden_Cache::table(), '2000-01-01 00:00:00' ) ); // phpcs:ignore
eq( 1, Tokenwarden_Cache::prune(), 'prune deletes expired entries' );

cache_demo_caller();
demo_caller_generate( 'Clear me' );
Tokenwarden_Cache::clear();
eq( 0, Tokenwarden_Cache::stats()['entries'], 'clear empties the cache' );

$s = Tokenwarden_Cache::update_settings( array( 'ttl_hours' => 5, 'max_mb' => 7, 'mode' => 'weird', 'sources' => array( 'Plugin:X<b>' ) ) );
eq( 24, $s['ttl_hours'], 'unsupported lifetime falls back to 24 hours' );
eq( 50, $s['max_mb'], 'unsupported size falls back to 50 MB' );
eq( 'selected', $s['mode'], 'unknown mode falls back to selected sources' );
eq( array( 'plugin:xb' ), $s['sources'], 'source ids sanitized' );

/* ------------------------------------------------------------------ */
group( 'Caching: REST API' );
$sub = wp_insert_user( array( 'user_login' => 'cachesub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
eq( 403, rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/caching' ) )->get_status(), 'subscribers are refused' );
wp_set_current_user( 1 );
cache_demo_caller();
$status = rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/caching' ) )->get_data();
check( isset( $status['settings'], $status['cache'], $status['sources'] ), 'status returns settings, cache and sources' );
$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/caching' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'ttl_hours' => 168 ) ) );
eq( 168, rest_do_request( $req )->get_data()['ttl_hours'] ?? null, 'settings are saved through the dashboard' );
demo_caller_generate( 'Clear me too' );
eq( 0, rest_do_request( new WP_REST_Request( 'DELETE', '/tokenwarden/v1/caching' ) )->get_data()['entries'] ?? null, 'cache is cleared through the dashboard' );

