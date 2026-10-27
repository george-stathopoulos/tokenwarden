<?php
/**
 * REST API for the admin dashboard.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under `tokenwarden/v1`. Every route requires `manage_options`.
 */
final class Tokenwarden_REST_Controller {

	const NS = 'tokenwarden/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		$admin = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::NS,
			'/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => $admin,
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 365,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sources',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'sources' ),
					'permission_callback' => $admin,
					'args'                => array(
						'days' => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 365,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_source' ),
					'permission_callback' => $admin,
					'args'                => array(
						'source' => array(
							'type'     => 'string',
							'required' => true,
						),
						'policy' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/requests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'requests' ),
					'permission_callback' => $admin,
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 25,
							'minimum' => 1,
							'maximum' => 200,
						),
						'source'   => array( 'type' => 'string' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( '', 'ok', 'blocked', 'error' ),
						),
						'model'    => array( 'type' => 'string' ),
						'pii'      => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'clear_requests' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/setup',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'setup' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'complete_setup' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/demo',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_demo' ),
					'permission_callback' => $admin,
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'reset_demo' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/caching',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'caching' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_caching' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'clear_cache' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/prices/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_prices' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/data-map',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'data_map' ),
				'permission_callback' => $admin,
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 90,
						'minimum' => 1,
						'maximum' => 3650,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/redact-preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'redact_preview' ),
				'permission_callback' => $admin,
				'args'                => array(
					'text'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'redaction' => array( 'type' => 'object' ),
				),
			)
		);
	}

	/**
	 * GET /caching: settings, cache statistics and per-source recommendations.
	 *
	 * @return WP_REST_Response
	 */
	public static function caching() {
		$now     = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$from    = $now - 30 * DAY_IN_SECONDS;
		$repeats = Tokenwarden_Stats::repeats( $from, $now + 1 );
		$by_id   = array();
		foreach ( $repeats['sources'] as $r ) {
			$by_id[ $r['id'] ] = $r;
		}
		$sources = array();
		foreach ( Tokenwarden_Stats::sources( $from, $now + 1 ) as $s ) {
			$sources[] = array(
				'id'        => $s['id'],
				'label'     => $s['label'],
				'type'      => $s['type'],
				'requests'  => $s['requests'],
				'repeats'   => $by_id[ $s['id'] ]['calls'] ?? 0,
				'share'     => $by_id[ $s['id'] ]['share'] ?? 0,
				'potential' => $by_id[ $s['id'] ]['potential'] ?? 0,
			);
		}
		usort(
			$sources,
			static function ( $a, $b ) {
				return $b['potential'] <=> $a['potential'] ?: $b['requests'] <=> $a['requests'];
			}
		);

		return rest_ensure_response(
			array(
				'settings' => Tokenwarden_Cache::settings(),
				'cache'    => Tokenwarden_Cache::stats(),
				'repeats'  => array(
					'calls'     => $repeats['calls'],
					'potential' => $repeats['potential'],
				),
				'sources'  => $sources,
				'order'    => Tokenwarden_Stats::source_order(),
			)
		);
	}

	/**
	 * POST /caching: save the cache settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_caching( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		return rest_ensure_response( Tokenwarden_Cache::update_settings( is_array( $input ) ? $input : array() ) );
	}

	/**
	 * DELETE /caching: delete every cached answer.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_cache() {
		Tokenwarden_Cache::clear();
		return rest_ensure_response( Tokenwarden_Cache::stats() );
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /overview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function overview( WP_REST_Request $request ) {
		return rest_ensure_response( Tokenwarden_Stats::overview( (int) $request['days'] ) );
	}

	/**
	 * GET /sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	/**
	 * GET /data-map: which plugins send what to which AI providers, for records and reports.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function data_map( WP_REST_Request $request ) {
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$start = strtotime( gmdate( 'Y-m-d', $now ) ) - ( (int) $request['days'] - 1 ) * DAY_IN_SECONDS;
		return rest_ensure_response(
			array(
				'site'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'       => home_url( '/' ),
				'from'      => gmdate( 'Y-m-d', $start ),
				'to'        => gmdate( 'Y-m-d', $now ),
				'generated' => gmdate( 'Y-m-d H:i', $now ),
				'detection' => (bool) Tokenwarden_Settings::get( 'redaction' )['enabled'],
				'sources'   => Tokenwarden_Stats::data_map( $start, $now + 1 ),
			)
		);
	}

	public static function sources( WP_REST_Request $request ) {
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$start = strtotime( gmdate( 'Y-m-d', $now ) ) - ( (int) $request['days'] - 1 ) * DAY_IN_SECONDS;
		return rest_ensure_response(
			array(
				'sources'   => Tokenwarden_Stats::sources( $start, $now + 1 ),
				'order'     => Tokenwarden_Stats::source_order(),
				'threshold' => (int) Tokenwarden_Settings::get( 'alerts' )['threshold'],
				'month'     => Tokenwarden_Stats::overview_month(),
			)
		);
	}

	/**
	 * POST /sources: update one source's policy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_source( WP_REST_Request $request ) {
		$source = Tokenwarden_Settings::source_id( $request['source'] );
		if ( '' === $source ) {
			return new WP_Error( 'tokenwarden_invalid_source', __( 'Invalid source.', 'tokenwarden' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'source' => $source,
				'policy' => Tokenwarden_Settings::update_source( $source, (array) $request['policy'] ),
			)
		);
	}

	/**
	 * GET /requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function requests( WP_REST_Request $request ) {
		$data           = Tokenwarden_Stats::requests( $request->get_params() );
		$data['facets'] = Tokenwarden_Stats::facets();
		return rest_ensure_response( $data );
	}

	/**
	 * DELETE /requests: clear the log.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_requests() {
		Tokenwarden_Ledger::clear();
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return rest_ensure_response( self::settings_payload() );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$input   = $request->get_json_params();
		$was_on  = Tokenwarden_Price_Sync::enabled();
		Tokenwarden_Settings::update( is_array( $input ) ? $input : array() );
		// Turning automatic prices on downloads them straight away instead of waiting a day.
		if ( ! $was_on && Tokenwarden_Price_Sync::enabled() && ! Tokenwarden_Demo::active() ) {
			Tokenwarden_Price_Sync::update();
			Tokenwarden_Price_Sync::schedule();
		}
		return rest_ensure_response( self::settings_payload() );
	}

	/**
	 * GET /setup: what the setup guide needs.
	 *
	 * @return WP_REST_Response
	 */
	public static function setup() {
		return rest_ensure_response( Tokenwarden_Onboarding::status() );
	}

	/**
	 * POST /setup: save the guide's choices (budget, alerts, redaction) and, with `complete`,
	 * mark the guide as done.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function complete_setup( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? array_intersect_key( $input, array_flip( array( 'global_budget', 'rate_limit', 'alerts', 'redaction', 'prices_auto' ) ) ) : array();
		if ( $input ) {
			Tokenwarden_Settings::update( $input );
		}
		if ( ! empty( $input['prices_auto'] ) && ! Tokenwarden_Price_Sync::state()['updated_at'] ) {
			Tokenwarden_Price_Sync::update();
		}
		if ( ! empty( $request['complete'] ) ) {
			Tokenwarden_Onboarding::complete();
		}
		return rest_ensure_response( Tokenwarden_Onboarding::status() );
	}

	/**
	 * POST /demo: switch demo mode for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function set_demo( WP_REST_Request $request ) {
		$created = 0;
		if ( $request['enabled'] ) {
			$created = Tokenwarden_Demo::enable();
		} else {
			Tokenwarden_Demo::disable();
		}
		return rest_ensure_response(
			array(
				'demo'    => Tokenwarden_Demo::enabled_for_user(),
				'created' => $created,
			)
		);
	}

	/**
	 * DELETE /demo: delete the sample data and switch everyone back to live data.
	 *
	 * @return WP_REST_Response
	 */
	public static function reset_demo() {
		Tokenwarden_Demo::reset();
		return rest_ensure_response( array( 'demo' => false ) );
	}

	/**
	 * POST /prices/update: download current prices now.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_prices() {
		if ( ! Tokenwarden_Price_Sync::enabled() ) {
			return new WP_Error( 'tokenwarden_prices_off', __( 'Automatic price updates are turned off.', 'tokenwarden' ), array( 'status' => 400 ) );
		}
		$result = Tokenwarden_Price_Sync::update();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		return rest_ensure_response( self::settings_payload() );
	}

	/**
	 * POST /redact-preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function redact_preview( WP_REST_Request $request ) {
		$config = Tokenwarden_Settings::get( 'redaction' );
		if ( is_array( $request['redaction'] ) ) {
			foreach ( Tokenwarden_Redactor::detector_keys() as $key ) {
				if ( isset( $request['redaction'][ $key ] ) ) {
					$config[ $key ] = (bool) $request['redaction'][ $key ];
				}
			}
			if ( isset( $request['redaction']['custom'] ) ) {
				$config['custom'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $request['redaction']['custom'] ) ) );
			}
		}
		$redactor = new Tokenwarden_Redactor( $config );
		$text     = $redactor->redact( (string) $request['text'] );
		return rest_ensure_response(
			array(
				'text'   => $text,
				'count'  => $redactor->count(),
				'counts' => $redactor->counts(),
			)
		);
	}

	/**
	 * Settings plus read-only context for the settings screens.
	 *
	 * @return array
	 */
	private static function settings_payload() {
		return array(
			'settings'       => Tokenwarden_Settings::all(),
			'default_prices' => Tokenwarden_Pricing::defaults(),
			'prices_checked' => Tokenwarden_Pricing::CHECKED,
			'pricing'        => Tokenwarden_Pricing::info(),
			'live_prices'    => Tokenwarden_Price_Sync::prices(),
			'usage'          => Tokenwarden_Stats::usage_context(),
			'seen_models'    => Tokenwarden_Stats::facets()['models'],
		);
	}
}
