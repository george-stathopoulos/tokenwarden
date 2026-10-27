<?php
/**
 * Plugin bootstrap.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class Tokenwarden_Plugin {

	const PRUNE_HOOK = 'tokenwarden_prune';

	/**
	 * Singleton.
	 *
	 * @var Tokenwarden_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Tokenwarden_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		Tokenwarden_Gateway::init();
		Tokenwarden_Alerts::init();
		Tokenwarden_Admin::init();
		Tokenwarden_Demo::init();
		Tokenwarden_Onboarding::init();
		Tokenwarden_Price_Sync::init();
		Tokenwarden_Privacy::init();
		Tokenwarden_Cache::init();

		add_action( 'init', array( 'Tokenwarden_Ledger', 'maybe_install' ) );
		add_action( 'rest_api_init', array( 'Tokenwarden_REST_Controller', 'register' ) );
		add_action( self::PRUNE_HOOK, array( 'Tokenwarden_Ledger', 'prune' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once TOKENWARDEN_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'tokenwarden', 'Tokenwarden_CLI' );
		}
	}

	/**
	 * Activation: create the table and schedule pruning.
	 */
	public static function activate() {
		Tokenwarden_Ledger::install();
		Tokenwarden_Cache::install();
		Tokenwarden_Onboarding::on_activate();
		if ( ! wp_next_scheduled( Tokenwarden_Cache::PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Tokenwarden_Cache::PRUNE );
		}
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Deactivation: stop pruning. Data is kept until uninstall.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
		wp_clear_scheduled_hook( Tokenwarden_Alerts::SPIKE_HOOK );
		wp_clear_scheduled_hook( Tokenwarden_Price_Sync::CRON );
		wp_clear_scheduled_hook( Tokenwarden_Cache::PRUNE );
	}
}
