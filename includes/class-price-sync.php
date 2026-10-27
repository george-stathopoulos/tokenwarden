<?php
/**
 * Automatic model price updates from OpenRouter's public model list.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads current per-token prices once a day, when the site owner has turned this on.
 *
 * Source: https://openrouter.ai/api/v1/models, a public list with no key or account needed.
 * The request sends nothing about the site: no site address, no usage data. Its model ids are
 * mapped to the ids the providers use (for example `anthropic/claude-sonnet-5.5` becomes
 * `claude-sonnet-5-5`). Prices the admin edited always win, and the built-in table remains the
 * fallback, so a failed download never leaves models without a price.
 */
final class Tokenwarden_Price_Sync {

	const OPTION = 'tokenwarden_synced_prices';
	const CRON   = 'tokenwarden_price_sync';
	// Not an AI integration: this public list is downloaded only to read model prices (opt-in, disclosed
	// in readme.txt under External services). AI calls themselves always go through wp_ai_client_prompt().
	const SOURCE = 'https://openrouter.ai/api/v1/models'; // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration

	/**
	 * Providers whose models are imported under the ids those providers use themselves (as the list
	 * names them). Every model in the list is also imported under its OpenRouter id, such as
	 * `anthropic/claude-sonnet-5.5`, for plugins that call OpenRouter directly.
	 */
	const PROVIDERS = array( 'anthropic', 'openai', 'google', 'x-ai', 'mistralai', 'deepseek' );

	/** A list smaller than this is treated as broken and ignored. */
	const MIN_MODELS = 10;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Whether automatic price updates are on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( Tokenwarden_Settings::get( 'prices_auto' ) );
	}

	/**
	 * Keep the daily event in step with the setting.
	 */
	public static function schedule() {
		$next = wp_next_scheduled( self::CRON );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Daily update (does nothing when turned off).
	 */
	public static function run() {
		if ( self::enabled() ) {
			self::update();
		}
	}

	/**
	 * Download and store current prices.
	 *
	 * @return array|WP_Error Stored state, or an error (the previous prices are kept).
	 */
	public static function update() {
		$state = self::state();

		/**
		 * Filters the URL of the public model price list.
		 *
		 * @param string $url URL.
		 */
		$url      = (string) apply_filters( 'tokenwarden_price_source_url', self::SOURCE );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 15,
				// No site address in the user agent: the request carries nothing about the site.
				'user-agent' => 'Tokenwarden/' . TOKENWARDEN_VERSION,
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		$error = null;
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			/* translators: %d: HTTP status code. */
			$error = sprintf( __( 'The price list returned HTTP %d.', 'tokenwarden' ), (int) wp_remote_retrieve_response_code( $response ) );
		}

		$prices = array();
		if ( null === $error ) {
			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			$prices = self::parse( is_array( $data ) && isset( $data['data'] ) ? (array) $data['data'] : array() );
			if ( count( $prices ) < self::MIN_MODELS ) {
				$error  = __( 'The price list was empty or in an unexpected format.', 'tokenwarden' );
				$prices = array();
			}
		}

		$state['checked_at'] = time();
		if ( null !== $error ) {
			$state['error'] = $error;
			update_option( self::OPTION, $state, false );
			return new WP_Error( 'tokenwarden_price_sync', $error );
		}

		$state = array(
			'prices'     => $prices,
			'updated_at' => time(),
			'checked_at' => time(),
			'count'      => count( $prices ),
			'error'      => '',
		);
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/**
	 * Map the public list to provider model ids and USD per million tokens.
	 *
	 * @param array $models Entries with `id` and `pricing.prompt` / `pricing.completion` (USD per token).
	 * @return array<string,float[]>
	 */
	public static function parse( array $models ) {
		$out = array();
		foreach ( $models as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) || ! is_string( $m['id'] ) || false !== strpos( $m['id'], ':' ) ) {
				continue; // Variants such as ":free" or ":batch" have special pricing.
			}
			list( $provider, $name ) = array_pad( explode( '/', $m['id'], 2 ), 2, '' );
			if ( '' === $name ) {
				continue;
			}
			$in  = isset( $m['pricing']['prompt'] ) ? (float) $m['pricing']['prompt'] * 1000000 : -1;
			$out_price = isset( $m['pricing']['completion'] ) ? (float) $m['pricing']['completion'] * 1000000 : -1;
			if ( $in <= 0 || $out_price <= 0 || $in > 1000 || $out_price > 5000 ) {
				continue; // Free, unknown or implausible prices are not imported.
			}
			$price = array( round( $in, 6 ), round( $out_price, 6 ) );

			// OpenRouter's own id, for calls made through OpenRouter.
			$out[ strtolower( $m['id'] ) ] = $price;

			// The provider's own id, for calls made to the provider directly.
			if ( in_array( $provider, self::PROVIDERS, true ) ) {
				$native         = self::native_id( $provider, strtolower( $name ) );
				$out[ $native ] = $price;
				// xAI and others write versions both ways (grok-4.1 / grok-4-1); accept both.
				$dashed = (string) preg_replace( '/(\d)\.(\d)/', '$1-$2', $native );
				if ( $dashed !== $native && ! isset( $out[ $dashed ] ) ) {
					$out[ $dashed ] = $price;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Provider-native model id. Anthropic uses dashes in version numbers (claude-sonnet-5-5),
	 * where the list uses dots (claude-sonnet-5.5); OpenAI and Google ids are used as they are.
	 *
	 * @param string $provider Provider.
	 * @param string $name     Model name from the list.
	 * @return string
	 */
	public static function native_id( $provider, $name ) {
		if ( 'anthropic' === $provider ) {
			return (string) preg_replace( '/(\d)\.(\d)/', '$1-$2', $name );
		}
		return $name;
	}

	/**
	 * Stored state.
	 *
	 * @return array { prices, updated_at, checked_at, count, error }
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'prices'     => array(),
				'updated_at' => 0,
				'checked_at' => 0,
				'count'      => 0,
				'error'      => '',
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Synced prices, only while automatic updates are on.
	 *
	 * @return array<string,float[]>
	 */
	public static function prices() {
		return self::enabled() ? (array) self::state()['prices'] : array();
	}

	/**
	 * Remove stored prices and the schedule (uninstall).
	 */
	public static function uninstall() {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
	}
}
