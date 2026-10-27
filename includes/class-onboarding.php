<?php
/**
 * First-run setup guide.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a new administrator to the setup guide once after activation, and remembers when the
 * guide has been completed or skipped.
 */
final class Tokenwarden_Onboarding {

	const OPTION   = 'tokenwarden_onboarding';
	const REDIRECT = 'tokenwarden_activation_redirect';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * Called on plugin activation.
	 */
	public static function on_activate() {
		if ( ! self::done() ) {
			set_transient( self::REDIRECT, 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Open the setup guide right after a single activation (not bulk activation, not AJAX, not CLI).
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( self::REDIRECT ) ) {
			return;
		}
		delete_transient( self::REDIRECT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of WordPress's own bulk-activation flag.
		if ( self::done() || wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=tokenwarden#/welcome' ) );
		exit;
	}

	/**
	 * Whether the guide was completed or skipped.
	 *
	 * @return bool
	 */
	public static function done() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) && ! empty( $state['done'] );
	}

	/**
	 * Mark the guide as completed (or skipped).
	 */
	public static function complete() {
		update_option(
			self::OPTION,
			array(
				'done' => true,
				'at'   => time(),
			),
			false
		);
	}

	/**
	 * Everything the setup guide shows.
	 *
	 * @return array
	 */
	public static function status() {
		$settings = Tokenwarden_Settings::all();
		return array(
			'done'       => self::done(),
			'ai_enabled' => function_exists( 'wp_supports_ai' ) ? wp_supports_ai() : false,
			'providers'  => Tokenwarden_Compat::providers(),
			'approval'   => Tokenwarden_Compat::connector_approval(),
			'connectors' => admin_url( 'options-connectors.php' ),
			'plugins'    => admin_url( 'plugin-install.php?s=ai+provider&tab=search&type=term' ),
			'budget'     => $settings['global_budget'],
			'rate_limit' => (int) $settings['rate_limit'],
			'alerts'     => $settings['alerts'],
			'redaction'  => (bool) $settings['redaction']['enabled'],
			'prices_auto'=> (bool) $settings['prices_auto'],
			'demo'       => Tokenwarden_Demo::enabled_for_user(),
		);
	}
}
