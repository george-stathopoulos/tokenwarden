<?php
/**
 * Uninstall: remove the request table and every option.
 *
 * @package Tokenwarden
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-ledger.php';
require_once __DIR__ . '/includes/class-alerts.php';
require_once __DIR__ . '/includes/class-cache.php';
require_once __DIR__ . '/includes/class-demo.php';

Tokenwarden_Ledger::uninstall();
Tokenwarden_Demo::run(
	static function () {
		Tokenwarden_Ledger::uninstall();
	}
);
delete_option( 'tokenwarden_demo_settings' );
delete_option( 'tokenwarden_onboarding' );
delete_option( 'tokenwarden_synced_prices' );
wp_clear_scheduled_hook( 'tokenwarden_price_sync' );
delete_metadata( 'user', 0, 'tokenwarden_demo_mode', '', true );
Tokenwarden_Alerts::uninstall();
Tokenwarden_Cache::uninstall();
delete_option( Tokenwarden_Cache::OPTION );
wp_clear_scheduled_hook( Tokenwarden_Cache::PRUNE );
delete_option( Tokenwarden_Settings::OPTION );
delete_option( 'tokenwarden_source_labels' );
delete_transient( 'tokenwarden_providers' );
wp_clear_scheduled_hook( 'tokenwarden_prune' );
