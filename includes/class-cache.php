<?php
/**
 * Response cache.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers repeated AI requests from a cache.
 *
 * How a call flows (Tokenwarden has already redacted the request and added the brief):
 *
 * 1. `pre_http_request` (priority 50): look up the exact request body. On a hit, return the stored
 *    response; nothing is sent to the provider. On a miss, remember the key.
 * 2. `http_response` (priority 1, before Tokenwarden restores personal data at priority 5): store the
 *    provider's answer. Because redaction ran first, the cache holds placeholders such as [EMAIL_1],
 *    not personal data, and a cached answer is restored with the *current* request's values.
 * 3. `tokenwarden_record_row`: mark the logged call as cached, with its cost moved to `saved`.
 *
 * Only successful text-generation responses are cached, and only for the sources the site owner
 * chose (or all sources, if they chose that).
 */
final class Tokenwarden_Cache {

	const OPTION     = 'tokenwarden_cache_settings';
	const PRUNE      = 'tokenwarden_cache_prune';
	const MAX_ENTRY  = 1048576;
	const DB_VERSION = '1';

	/**
	 * Miss waiting for a response: [ key, source, model, ttl ].
	 *
	 * @var array|null
	 */
	private static $pending = null;

	/**
	 * Whether the call in flight was answered from the cache.
	 *
	 * @var bool
	 */
	private static $hit = false;

	/**
	 * True while a cached response passes through the `http_response` filters.
	 *
	 * @var bool
	 */
	private static $serving = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'pre_http_request', array( __CLASS__, 'serve' ), 50, 3 );
		add_filter( 'http_response', array( __CLASS__, 'store' ), 1, 3 );
		add_filter( 'tokenwarden_record_row', array( __CLASS__, 'mark' ) );
		add_action( self::PRUNE, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tokenwarden_cache';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				cache_key char(64) NOT NULL,
				source varchar(191) NOT NULL DEFAULT '',
				model varchar(191) NOT NULL DEFAULT '',
				response longtext NOT NULL,
				bytes int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				last_hit_at datetime NULL,
				PRIMARY KEY  (cache_key),
				KEY expires_at (expires_at)
			) {$charset};"
		);
		update_option( 'tokenwarden_cache_db_version', self::DB_VERSION, false );
	}

	/**
	 * Install when missing.
	 */
	public static function maybe_install() {
		if ( get_option( 'tokenwarden_cache_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Settings merged over defaults.
	 *
	 * Defaults: caching on, 24-hour lifetime, only for sources the owner selects (none at first),
	 * because some plugins expect a fresh answer every time (for example "regenerate" buttons).
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'enabled'   => true,
				'ttl_hours' => 24,
				'mode'      => 'selected',
				'sources'   => array(),
				'max_mb'    => 50,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Sanitize and save settings.
	 *
	 * @param array $input Partial settings.
	 * @return array Saved settings.
	 */
	public static function update_settings( array $input ) {
		$s   = array_merge( self::settings(), $input );
		$out = array(
			'enabled'   => ! empty( $s['enabled'] ),
			'ttl_hours' => in_array( (int) $s['ttl_hours'], array( 1, 24, 168, 720 ), true ) ? (int) $s['ttl_hours'] : 24,
			'mode'      => 'all' === $s['mode'] ? 'all' : 'selected',
			'sources'   => array_values( array_unique( array_filter( array_map( array( 'Tokenwarden_Settings', 'source_id' ), (array) $s['sources'] ) ) ) ),
			'max_mb'    => in_array( (int) $s['max_mb'], array( 25, 50, 100, 250 ), true ) ? (int) $s['max_mb'] : 50,
		);
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/**
	 * Whether calls from a source may be cached right now.
	 *
	 * @param string $source Source id.
	 * @return bool
	 */
	public static function enabled_for( $source ) {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return false;
		}
		return 'all' === $s['mode'] || in_array( $source, $s['sources'], true );
	}

	/**
	 * Answer from the cache, or remember the key for storing the answer.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error
	 */
	public static function serve( $pre, $args, $url ) {
		self::$pending = null;
		self::$hit     = false;
		if ( false !== $pre || empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
			return $pre;
		}
		if ( null === Tokenwarden_Provider_Adapters::provider_for( $url, $args['method'] ?? 'GET' ) || null === Tokenwarden_Provider_Adapters::text_format( $url ) ) {
			return $pre;
		}
		$call   = Tokenwarden_Gateway::current_call();
		$source = (string) ( $call['source'] ?? '' );
		if ( '' === $source || ! self::enabled_for( $source ) ) {
			return $pre;
		}

		global $wpdb;
		$key = self::key( $url, $args['body'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT response FROM %i WHERE cache_key = %s AND expires_at > %s', self::table(), $key, current_time( 'mysql', true ) ), ARRAY_A );

		if ( ! $row ) {
			$body          = json_decode( $args['body'], true );
			self::$pending = array(
				'key'    => $key,
				'source' => $source,
				'model'  => is_array( $body ) && isset( $body['model'] ) && is_string( $body['model'] ) ? $body['model'] : '',
			);
			return $pre;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit_at = %s WHERE cache_key = %s', self::table(), current_time( 'mysql', true ), $key ) );

		self::$hit = true;
		$response  = array(
			'headers'  => array(
				'content-type'   => 'application/json',
				'x-tokenwarden-cache' => 'hit',
			),
			'body'     => $row['response'],
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);

		// A short-circuited response skips the transport, so run the response filters ourselves;
		// this is where Tokenwarden restores the redacted values for the current request.
		self::$serving = true;
		$response      = apply_filters( 'http_response', $response, $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core HTTP API filter, applied as the transport would.
		self::$serving = false;
		return $response;
	}

	/**
	 * Store a fresh provider answer (still containing placeholders).
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $args     Request arguments.
	 * @param string         $url      URL.
	 * @return array|WP_Error
	 */
	public static function store( $response, $args, $url ) {
		if ( self::$serving || null === self::$pending || ! is_array( $response ) ) {
			return $response;
		}
		$pending       = self::$pending;
		self::$pending = null;

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || self::key( $url, (string) ( $args['body'] ?? '' ) ) !== $pending['key'] ) {
			return $response;
		}
		$body  = (string) wp_remote_retrieve_body( $response );
		$bytes = strlen( $body );
		if ( 0 === $bytes || $bytes > self::MAX_ENTRY || ! is_array( json_decode( $body, true ) ) ) {
			return $response;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'cache_key'  => $pending['key'],
				'source'     => $pending['source'],
				'model'      => $pending['model'],
				'response'   => $body,
				'bytes'      => $bytes,
				'created_at' => $now,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) self::settings()['ttl_hours'] * HOUR_IN_SECONDS ),
				'hits'       => 0,
			)
		);
		self::enforce_size();
		return $response;
	}

	/**
	 * Mark the logged call as answered from the cache.
	 *
	 * @param array $row Ledger row.
	 * @return array
	 */
	public static function mark( $row ) {
		if ( self::$hit ) {
			self::$hit = false;
			if ( 'ok' === ( $row['status'] ?? '' ) ) {
				$row['cached'] = 1;
				$row['saved']  = (float) $row['cost'];
				$row['cost']   = 0;
			}
		}
		return $row;
	}

	/**
	 * Cache statistics for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cache = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS entries, COALESCE(SUM(bytes), 0) AS bytes, COALESCE(SUM(hits), 0) AS hits FROM %i WHERE expires_at > %s', self::table(), current_time( 'mysql', true ) ), ARRAY_A );
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$usage = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(saved), 0) AS saved, COALESCE(SUM(cached), 0) AS cached, SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok FROM %i WHERE created_at >= %s", Tokenwarden_Ledger::table(), $since ), ARRAY_A );
		// phpcs:enable
		$ok = (int) ( $usage['ok'] ?? 0 );
		return array(
			'entries'  => (int) $cache['entries'],
			'bytes'    => (int) $cache['bytes'],
			'saved'    => round( (float) $usage['saved'], 6 ),
			'cached'   => (int) $usage['cached'],
			'hit_rate' => $ok ? round( (int) $usage['cached'] / $ok, 4 ) : 0,
		);
	}

	/**
	 * Delete every cached answer.
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete expired answers and keep the cache under its size limit (daily cron).
	 *
	 * @return int Entries deleted.
	 */
	public static function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', self::table(), current_time( 'mysql', true ) ) );
		return $deleted + self::enforce_size();
	}

	/**
	 * Delete least-recently used answers while the cache is over its size limit.
	 *
	 * @return int Entries deleted.
	 */
	private static function enforce_size() {
		global $wpdb;
		$limit   = (int) self::settings()['max_mb'] * 1048576;
		$deleted = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		while ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes), 0) FROM %i', self::table() ) ) > $limit ) {
			$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT cache_key FROM %i ORDER BY COALESCE(last_hit_at, created_at) ASC LIMIT 50', self::table() ) );
			if ( ! $keys ) {
				break;
			}
			foreach ( $keys as $key ) {
				$deleted += (int) $wpdb->delete( self::table(), array( 'cache_key' => $key ) );
			}
		}
		// phpcs:enable
		return $deleted;
	}

	/**
	 * Cache key: the provider endpoint plus the exact request body.
	 *
	 * @param string $url  URL.
	 * @param string $body Body.
	 * @return string
	 */
	private static function key( $url, $body ) {
		return hash( 'sha256', (string) wp_parse_url( $url, PHP_URL_HOST ) . (string) wp_parse_url( $url, PHP_URL_PATH ) . '|' . $body );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function uninstall() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_option( 'tokenwarden_cache_db_version' );
	}
}
