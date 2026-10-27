<?php
/**
 * Model price table.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns token usage into an estimated cost in USD.
 *
 * Keys are model ids or id prefixes. A key matches a model when the id equals the key or
 * starts with the key followed by "-" (so `claude-haiku-4-5` also covers dated snapshots).
 * The longest matching key wins.
 */
final class Tokenwarden_Pricing {

	/**
	 * Date the built-in price table was last checked against the providers' price lists.
	 *
	 * The built-in table ships with the plugin and is the fallback. When the site owner turns on
	 * automatic price updates, Tokenwarden_Price_Sync downloads current prices daily and those replace it.
	 * Update this date whenever the table is reviewed for a release.
	 */
	const CHECKED = '2026-10-01';

	/** Built-in prices older than this many days are flagged as possibly out of date. */
	const STALE_AFTER_DAYS = 120;

	/**
	 * Default USD prices per million tokens: [input, output].
	 *
	 * @return array<string,float[]>
	 */
	public static function defaults() {
		$prices = array(
			// Anthropic.
			'claude-fable-5'        => array( 10.0, 50.0 ),
			'claude-mythos-5'       => array( 10.0, 50.0 ),
			'claude-opus-5-5'       => array( 4.0, 20.0 ),
			'claude-opus-5'         => array( 5.0, 25.0 ),
			'claude-opus-4'         => array( 5.0, 25.0 ),
			'claude-opus-4-1'       => array( 15.0, 75.0 ),
			'claude-opus-4-0'       => array( 15.0, 75.0 ),
			'claude-sonnet-5-5'     => array( 2.0, 10.0 ),
			'claude-sonnet-5'       => array( 2.0, 10.0 ),
			'claude-sonnet-4'       => array( 3.0, 15.0 ),
			'claude-haiku-4-5'      => array( 1.0, 5.0 ),
			'claude-3-5-haiku'      => array( 0.8, 4.0 ),
			// OpenAI.
			'gpt-5.5'               => array( 5.0, 30.0 ),
			'gpt-5.4-mini'          => array( 0.75, 4.5 ),
			'gpt-5.4-nano'          => array( 0.2, 1.25 ),
			'gpt-5'                 => array( 1.25, 10.0 ),
			'gpt-5-mini'            => array( 0.25, 2.0 ),
			'gpt-5-nano'            => array( 0.05, 0.4 ),
			// Google.
			'gemini-3.1-pro'        => array( 2.0, 12.0 ),
			'gemini-3-pro'          => array( 2.0, 12.0 ),
			'gemini-3.8-flash'      => array( 0.75, 3.75 ),
			'gemini-3.7-flash'      => array( 0.75, 3.75 ),
			'gemini-3.6-flash'      => array( 0.75, 3.75 ),
			'gemini-3.1-flash-lite' => array( 0.25, 1.5 ),
			'gemini-2.5-pro'        => array( 1.25, 10.0 ),
			'gemini-2.5-flash'      => array( 0.3, 2.5 ),
			'gemini-2.5-flash-lite' => array( 0.1, 0.4 ),
		);

		/**
		 * Filters the default model price table (USD per million tokens, [input, output]).
		 *
		 * @param array $prices Price table.
		 */
		return apply_filters( 'tokenwarden_default_prices', $prices );
	}

	/**
	 * Where prices come from and how old the built-in table is, for the dashboard.
	 *
	 * @return array
	 */
	public static function info() {
		$age  = (int) floor( ( time() - strtotime( self::CHECKED . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
		$sync = Tokenwarden_Price_Sync::state();
		$auto = Tokenwarden_Price_Sync::enabled();
		// Live prices count as current when they were downloaded in the last week.
		$live = $auto && $sync['count'] > 0 && ( time() - (int) $sync['updated_at'] ) < 7 * DAY_IN_SECONDS;
		return array(
			'checked'    => self::CHECKED,
			'version'    => TOKENWARDEN_VERSION,
			'age_days'   => max( 0, $age ),
			'stale'      => ! $live && $age > self::STALE_AFTER_DAYS,
			'overrides'  => count( (array) Tokenwarden_Settings::get( 'prices' ) ),
			'auto'       => $auto,
			'live'       => $live,
			'updated_at' => $sync['updated_at'] ? gmdate( 'c', (int) $sync['updated_at'] ) : null,
			'checked_at' => $sync['checked_at'] ? gmdate( 'c', (int) $sync['checked_at'] ) : null,
			'count'      => (int) $sync['count'],
			'error'      => $auto ? (string) $sync['error'] : '',
			'source'     => 'OpenRouter',
		);
	}

	/**
	 * Defaults with the admin's overrides applied.
	 *
	 * @return array<string,float[]>
	 */
	public static function table() {
		$table = self::defaults();
		// Live prices (when automatic updates are on) replace built-in ones; admin edits win over both.
		foreach ( Tokenwarden_Price_Sync::prices() as $key => $price ) {
			$table[ $key ] = $price;
		}
		foreach ( (array) Tokenwarden_Settings::get( 'prices' ) as $key => $price ) {
			$table[ $key ] = $price;
		}
		return $table;
	}

	/**
	 * Price for one model, or null when the model is not in the table.
	 *
	 * @param string $model Model id.
	 * @return float[]|null
	 */
	public static function price_for( $model ) {
		$model = strtolower( (string) $model );
		$best  = null;
		$len   = 0;
		foreach ( self::table() as $key => $price ) {
			$key = (string) $key;
			if ( ( $model === $key || 0 === strpos( $model, $key . '-' ) ) && strlen( $key ) > $len ) {
				$best = $price;
				$len  = strlen( $key );
			}
		}
		return $best;
	}

	/**
	 * Whether a provider runs models locally at no cost, such as AI Provider for WebLLM (the model
	 * runs in the browser).
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function is_local( $provider ) {
		/**
		 * Filters the providers whose calls cost nothing (models run locally).
		 *
		 * @param string[] $providers Provider ids.
		 */
		return in_array( (string) $provider, (array) apply_filters( 'tokenwarden_local_providers', array( 'webllm' ) ), true );
	}

	/**
	 * The local, in-browser AI provider "AI Provider for WebLLM": installed, active, and whether its
	 * in-browser worker (needed for requests started on the server) is on.
	 *
	 * @return array
	 */
	public static function local_ai() {
		$active = defined( 'WEBLLM_API_KEY' ) || class_exists( 'WordPress\\WebLlmAiProvider\\Provider\\WebLlmProvider' );
		return array(
			'installed' => $active || self::webllm_installed(),
			'active'    => $active,
			'worker'    => $active && (bool) get_option( 'ai_provider_webllm_worker_enabled', false ),
			'settings'  => admin_url( 'options-general.php?page=ai-provider-webllm' ),
			'plugins'   => admin_url( 'plugins.php' ),
			'url'       => 'https://github.com/ProgressPlanner/ai-provider-for-webllm',
		);
	}

	/**
	 * Whether AI Provider for WebLLM is installed, whatever its folder is called (a GitHub
	 * "Download ZIP" installs it as ai-provider-for-webllm-main).
	 *
	 * @return bool
	 */
	private static function webllm_installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'AI Provider for WebLLM' === ( $data['Name'] ?? '' ) || 0 === strpos( $file, 'ai-provider-for-webllm' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Estimated cost in USD, or null when the model has no price.
	 *
	 * @param string $model      Model id.
	 * @param int    $input      Input tokens.
	 * @param int    $output     Output tokens, including thinking tokens.
	 * @return float|null
	 */
	public static function cost( $model, $input, $output ) {
		$price = self::price_for( $model );
		if ( null === $price ) {
			return null;
		}
		return round( ( $input * $price[0] + $output * $price[1] ) / 1000000, 6 );
	}
}
