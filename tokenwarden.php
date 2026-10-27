<?php
/**
 * Plugin Name:       Tokenwarden – AI Cost & Privacy Control
 * Plugin URI:        https://wordpress.org/plugins/tokenwarden/
 * Description:       Cost tracking, budgets and personal-data checks for the AI calls plugins make, through the WordPress AI Client or directly with their own API key.
 * Version:           2.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            George Stathopoulos
 * Author URI:        https://george-stathopoulos.github.io/tokenwarden/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tokenwarden
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

define( 'TOKENWARDEN_VERSION', '2.0.0' );
define( 'TOKENWARDEN_FILE', __FILE__ );
define( 'TOKENWARDEN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOKENWARDEN_URL', plugin_dir_url( __FILE__ ) );
define( 'TOKENWARDEN_SITE', 'https://george-stathopoulos.github.io/tokenwarden/' );

require_once TOKENWARDEN_DIR . 'includes/class-settings.php';
require_once TOKENWARDEN_DIR . 'includes/class-pricing.php';
require_once TOKENWARDEN_DIR . 'includes/class-price-sync.php';
require_once TOKENWARDEN_DIR . 'includes/class-compat.php';
require_once TOKENWARDEN_DIR . 'includes/class-demo.php';
require_once TOKENWARDEN_DIR . 'includes/class-sample.php';
require_once TOKENWARDEN_DIR . 'includes/class-onboarding.php';
require_once TOKENWARDEN_DIR . 'includes/class-attribution.php';
require_once TOKENWARDEN_DIR . 'includes/class-redactor.php';
require_once TOKENWARDEN_DIR . 'includes/class-provider-adapters.php';
require_once TOKENWARDEN_DIR . 'includes/class-ledger.php';
require_once TOKENWARDEN_DIR . 'includes/class-cache.php';
require_once TOKENWARDEN_DIR . 'includes/class-alerts.php';
require_once TOKENWARDEN_DIR . 'includes/class-privacy.php';
require_once TOKENWARDEN_DIR . 'includes/class-gateway.php';
require_once TOKENWARDEN_DIR . 'includes/class-stats.php';
require_once TOKENWARDEN_DIR . 'includes/class-rest-controller.php';
require_once TOKENWARDEN_DIR . 'includes/class-admin.php';
require_once TOKENWARDEN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Tokenwarden_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Tokenwarden_Plugin', 'deactivate' ) );

Tokenwarden_Plugin::instance();
