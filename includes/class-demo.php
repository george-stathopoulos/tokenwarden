<?php
/**
 * Demo mode: explore Tokenwarden with sample data, without touching the live site.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * A sandbox with its own request log, spend totals and settings.
 *
 * Demo mode is switched on per administrator and only applies inside Tokenwarden's own dashboard
 * requests (REST routes under `tokenwarden/v1`). Real AI calls, budgets,
 * alerts and redaction always use the live data and settings, so demo mode is safe on a live site.
 */
final class Tokenwarden_Demo {

	const META    = 'tokenwarden_demo_mode';
	const VERSION = 'tokenwarden_demo_db_version';

	/**
	 * Whether the current code runs against the sandbox.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'scope' ), 1, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'unscope' ), 999 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'unscope' ), 999 );
	}

	/**
	 * End the sandbox when the dashboard request is done, so later code in the same PHP process
	 * (batch requests, other plugins' internal REST calls) uses live data.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response Unchanged.
	 */
	public static function unscope( $response ) {
		if ( self::$active ) {
			self::switch_to( false );
		}
		return $response;
	}

	/**
	 * Use the sandbox for Tokenwarden dashboard requests from an administrator in demo mode.
	 *
	 * @param mixed           $result  Dispatch result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed Unchanged.
	 */
	public static function scope( $result, $server, $request ) {
		$route = $request->get_route();
		$ours  = 0 === strpos( $route, '/tokenwarden/v1/' );
		// The demo switch and the setup guide always work on live data.
		$live = in_array( $route, array( '/tokenwarden/v1/demo', '/tokenwarden/v1/setup' ), true );
		if ( $ours && ! $live && self::enabled_for_user() ) {
			self::switch_to( true );
		}
		return $result;
	}

	/**
	 * Whether the current user has demo mode on.
	 *
	 * @return bool
	 */
	public static function enabled_for_user() {
		$user = get_current_user_id();
		return $user && (bool) get_user_meta( $user, self::META, true );
	}

	/**
	 * Whether the sandbox is in use right now.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$active;
	}

	/**
	 * Run code against the sandbox.
	 *
	 * @param callable $callback Code to run.
	 * @return mixed Callback result.
	 */
	public static function run( callable $callback ) {
		$previous = self::$active;
		self::switch_to( true );
		try {
			return $callback();
		} finally {
			self::switch_to( $previous );
		}
	}

	/**
	 * Turn demo mode on for the current user, creating sample data the first time.
	 *
	 * @return int Sample rows created (0 when the sandbox already had data).
	 */
	public static function enable() {
		update_user_meta( get_current_user_id(), self::META, 1 );
		return self::run(
			static function () {
				if ( get_option( self::VERSION ) !== Tokenwarden_Ledger::DB_VERSION ) {
					Tokenwarden_Ledger::install();
				}
				if ( self::has_data() ) {
					return 0;
				}
				return ( new Tokenwarden_Sample() )->generate(
					array(
						'days'       => 60,
						'per_day'    => 120,
						'cache_days' => 12,
					)
				);
			}
		);
	}

	/**
	 * Turn demo mode off for the current user. Sample data is kept for next time.
	 */
	public static function disable() {
		delete_user_meta( get_current_user_id(), self::META );
	}

	/**
	 * Delete the sandbox and switch every user back to live data.
	 */
	public static function reset() {
		self::run(
			static function () {
				Tokenwarden_Ledger::uninstall();
				delete_option( Tokenwarden_Settings::option_name() );
			}
		);
		delete_metadata( 'user', 0, self::META, '', true );
	}

	/**
	 * Whether the sandbox has any requests.
	 *
	 * @return bool
	 */
	public static function has_data() {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) > 0;
	}

	/**
	 * Switch between sandbox and live storage.
	 *
	 * @param bool $on Sandbox on.
	 */
	private static function switch_to( $on ) {
		self::$active = (bool) $on;
		Tokenwarden_Settings::flush();
	}
}
