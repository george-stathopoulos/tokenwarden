<?php
/**
 * Settings storage and sanitization.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `tokenwarden_settings` option.
 */
final class Tokenwarden_Settings {

	const OPTION = 'tokenwarden_settings';

	/**
	 * Per-request cache of the merged settings, keyed by option name.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Option holding the settings (separate for the demo sandbox).
	 *
	 * @return string
	 */
	public static function option_name() {
		return Tokenwarden_Demo::active() ? 'tokenwarden_demo_settings' : self::OPTION;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'global_budget'   => 0,
			// Most AI calls one source may make in an hour (0 = no limit). Counts every call, including
			// streamed ones whose cost isn't known, so it stops loops that dollar budgets can miss.
			'rate_limit'      => 0,
			'alerts'          => array(
				'enabled'   => true,
				'threshold' => 80,
				'email'     => get_option( 'admin_email' ),
			),
			'sources'         => array(),
			// Personal data. `enabled` turns on detection: requests are scanned and what was found is
			// reported, but nothing is changed. Values are only replaced for the sources whose policy
			// has `redact` turned on.
			'redaction'       => array(
				'enabled' => true,
				'email'   => true,
				'phone'   => true,
				'card'    => true,
				'iban'    => true,
				'ssn'     => true,
				'ip'      => false,
				'custom'  => array(),
			),
			'logging'         => array(
				'store_excerpts' => false,
				'retention_days' => 90,
			),
			'prices'          => array(),
			'prices_auto'     => false,
		);
	}

	/**
	 * Default per-source policy.
	 *
	 * @return array
	 */
	public static function source_defaults() {
		return array(
			'budget'         => 0,
			'paused'         => false,
			'redact'         => false,
			'rate_limit'     => 0, // Calls per hour; 0 uses the site-wide limit.
		);
	}

	/**
	 * Full settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$name = self::option_name();
		if ( ! isset( self::$cache[ $name ] ) ) {
			$stored              = get_option( $name, array() );
			self::$cache[ $name ] = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache[ $name ];
	}

	/**
	 * One top-level setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Policy for one source, merged over defaults.
	 *
	 * @param string $source Source id such as `plugin:woocommerce`.
	 * @return array
	 */
	public static function source( $source ) {
		$sources = self::get( 'sources' );
		$stored  = isset( $sources[ $source ] ) && is_array( $sources[ $source ] ) ? $sources[ $source ] : array();
		return array_merge( self::source_defaults(), $stored );
	}

	/**
	 * Hourly call limit that applies to a source: its own, or else the site-wide one (0 = none).
	 *
	 * @param string $source Source id.
	 * @return int
	 */
	public static function rate_limit_for( $source ) {
		$own = (int) self::source( $source )['rate_limit'];
		return $own > 0 ? $own : (int) self::get( 'rate_limit' );
	}

	/**
	 * Sanitize and save a partial or full settings array.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$merged = self::merge( self::all(), $input );
		// Price overrides are edited as a whole table, so replace instead of merging.
		if ( isset( $input['prices'] ) && is_array( $input['prices'] ) ) {
			$merged['prices'] = $input['prices'];
		}
		$settings = self::sanitize( $merged );
		update_option( self::option_name(), $settings, false );
		unset( self::$cache[ self::option_name() ] );
		return self::all();
	}

	/**
	 * Update the policy for one source.
	 *
	 * @param string $source Source id.
	 * @param array  $policy Partial policy.
	 * @return array Saved policy.
	 */
	public static function update_source( $source, array $policy ) {
		$all                       = self::all();
		$all['sources'][ $source ] = array_merge( self::source( $source ), array_intersect_key( $policy, self::source_defaults() ) );
		self::update( $all );
		return self::source( $source );
	}

	/**
	 * Clear the request cache (used by tests and after external option writes).
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Recursive merge where later scalar values and lists replace earlier ones.
	 *
	 * @param array $base     Base array.
	 * @param array $override Override array.
	 * @return array
	 */
	private static function merge( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! wp_is_numeric_array( $value ) && array() !== $value ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Sanitize the whole settings tree.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function sanitize( array $s ) {
		$d   = self::defaults();
		$out = array(
			'global_budget' => self::money( $s['global_budget'] ?? 0 ),
			'rate_limit'    => min( 1000000, absint( $s['rate_limit'] ?? 0 ) ),
			'alerts'        => array(
				'enabled'   => ! empty( $s['alerts']['enabled'] ),
				'threshold' => max( 1, min( 100, absint( $s['alerts']['threshold'] ?? $d['alerts']['threshold'] ) ) ),
				'email'     => sanitize_email( $s['alerts']['email'] ?? '' ),
			),
			'sources'       => array(),
			'redaction'     => array(
				'enabled' => ! empty( $s['redaction']['enabled'] ),
				'custom'  => array(),
			),
			'logging'       => array(
				'store_excerpts' => ! empty( $s['logging']['store_excerpts'] ),
				'retention_days' => max( 1, min( 3650, absint( $s['logging']['retention_days'] ?? 90 ) ) ),
			),
			'prices'        => array(),
			'prices_auto'   => ! empty( $s['prices_auto'] ),
		);

		foreach ( Tokenwarden_Redactor::detector_keys() as $key ) {
			$out['redaction'][ $key ] = ! empty( $s['redaction'][ $key ] );
		}

		$custom = isset( $s['redaction']['custom'] ) ? (array) $s['redaction']['custom'] : array();
		foreach ( $custom as $term ) {
			$term = sanitize_text_field( (string) $term );
			if ( '' !== $term && mb_strlen( $term ) >= 2 ) {
				$out['redaction']['custom'][] = $term;
			}
		}
		$out['redaction']['custom'] = array_values( array_unique( array_slice( $out['redaction']['custom'], 0, 200 ) ) );

		foreach ( (array) ( $s['sources'] ?? array() ) as $id => $policy ) {
			$id = self::source_id( $id );
			if ( '' === $id || ! is_array( $policy ) ) {
				continue;
			}
			$out['sources'][ $id ] = array(
				'budget'         => self::money( $policy['budget'] ?? 0 ),
				'paused'         => ! empty( $policy['paused'] ),
				'redact'         => ! empty( $policy['redact'] ),
				'rate_limit'     => min( 1000000, absint( $policy['rate_limit'] ?? 0 ) ),
			);
		}

		foreach ( (array) ( $s['prices'] ?? array() ) as $model => $price ) {
			$model = strtolower( preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) $model ) );
			if ( '' === $model || ! is_array( $price ) || count( $price ) < 2 ) {
				continue;
			}
			$price = array_values( $price );
			$out['prices'][ $model ] = array( self::money( $price[0] ), self::money( $price[1] ) );
		}

		return $out;
	}

	/**
	 * Sanitize a source id such as `plugin:woocommerce`.
	 *
	 * @param string $id Raw id.
	 * @return string
	 */
	public static function source_id( $id ) {
		return preg_replace( '/[^a-z0-9:_.\-]/', '', strtolower( (string) $id ) );
	}

	/**
	 * Non-negative money value rounded to 6 decimals.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function money( $value ) {
		return round( max( 0, (float) $value ), 6 );
	}
}
