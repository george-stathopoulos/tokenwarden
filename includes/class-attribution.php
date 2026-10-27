<?php
/**
 * Works out which plugin or theme made an AI call.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attribution by call stack.
 */
final class Tokenwarden_Attribution {

	/**
	 * Source id of the code that triggered the current AI Client call.
	 *
	 * Walks the stack from the innermost frame and returns the first frame that belongs to a
	 * plugin, must-use plugin or theme, skipping this plugin and AI provider plugins.
	 *
	 * @param array|null $trace Optional backtrace (for tests).
	 * @return string Such as `plugin:woocommerce`, `theme:twentytwentysix`, `mu-plugin:custom` or `core`.
	 */
	public static function detect( $trace = null ) {
		$trace   = null === $trace ? debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) : $trace; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$self    = wp_normalize_path( dirname( TOKENWARDEN_FILE ) ) . '/';
		$roots   = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		$skipped = (array) apply_filters( 'tokenwarden_attribution_skip', array( 'tokenwarden', 'ai-provider-for-anthropic', 'ai-provider-for-openai', 'ai-provider-for-google' ) );

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $self ) ) {
				continue;
			}
			foreach ( $roots as $type => $root ) {
				if ( 0 !== strpos( $file, $root ) ) {
					continue;
				}
				$rest = substr( $file, strlen( $root ) );
				$slug = false !== strpos( $rest, '/' ) ? strtok( $rest, '/' ) : preg_replace( '/\.php$/', '', $rest );
				if ( in_array( $slug, $skipped, true ) || 0 === strpos( $slug, 'ai-provider-' ) ) {
					continue 2;
				}
				return Tokenwarden_Settings::source_id( $type . ':' . $slug );
			}
		}
		return 'core';
	}

	/**
	 * Human-readable name for a source id.
	 *
	 * @param string $source Source id.
	 * @return string
	 */
	public static function label( $source ) {
		static $plugins = null;
		list( $type, $slug ) = array_pad( explode( ':', $source, 2 ), 2, '' );

		$name = '';
		if ( 'plugin' === $type ) {
			if ( null === $plugins ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = get_plugins();
			}
			foreach ( $plugins as $file => $data ) {
				if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
					$name = $data['Name'];
					break;
				}
			}
		} elseif ( 'theme' === $type ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$name = $theme->get( 'Name' );
			}
		} elseif ( 'core' === $type ) {
			return __( 'WordPress core', 'tokenwarden' );
		}

		// Remember names so history stays readable after a plugin or theme is removed.
		$known = get_option( 'tokenwarden_source_labels', array() );
		if ( '' !== $name ) {
			if ( ( $known[ $source ] ?? '' ) !== $name ) {
				$known[ $source ] = $name;
				update_option( 'tokenwarden_source_labels', $known, false );
			}
			return $name;
		}
		if ( isset( $known[ $source ] ) ) {
			return $known[ $source ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ? $slug : $source ) );
	}

	/**
	 * Source type for display.
	 *
	 * @param string $source Source id.
	 * @return string `plugin`, `theme`, `mu-plugin` or `core`.
	 */
	public static function type( $source ) {
		return strtok( $source, ':' );
	}
}
