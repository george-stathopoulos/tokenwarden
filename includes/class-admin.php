<?php
/**
 * Admin screen.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tokenwarden admin page and loads the dashboard app.
 */
final class Tokenwarden_Admin {

	const SLUG = 'tokenwarden';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TOKENWARDEN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the top-level menu.
	 */
	public static function menu() {
		self::$hook = add_menu_page(
			__( 'Tokenwarden', 'tokenwarden' ),
			__( 'Tokenwarden', 'tokenwarden' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			80
		);
	}

	/**
	 * Mount point for the React app.
	 */
	public static function render() {
		echo '<div id="tokenwarden-root" class="tokenwarden-root"><div class="tokenwarden-boot" role="status">' . esc_html__( 'Loading Tokenwarden…', 'tokenwarden' ) . '</div></div>';
	}

	/**
	 * Enqueue the dashboard bundle on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		$asset_file = TOKENWARDEN_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'tokenwarden-admin', TOKENWARDEN_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'tokenwarden-admin', TOKENWARDEN_URL . 'build/style-index.css', array(), $asset['version'] );
		wp_style_add_data( 'tokenwarden-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'tokenwarden-admin', 'tokenwarden' );

		$user = wp_get_current_user();
		wp_add_inline_script(
			'tokenwarden-admin',
			'window.tokenwardenBoot = ' . wp_json_encode(
				array(
					'version'   => TOKENWARDEN_VERSION,
					'siteUrl'   => TOKENWARDEN_SITE,
					'adminUrl'  => admin_url(),
					'siteName'  => get_bloginfo( 'name' ),
					'userName'  => $user->display_name,
					'aiEnabled' => function_exists( 'wp_supports_ai' ) ? wp_supports_ai() : false,
					'connectorsUrl' => admin_url( 'options-connectors.php' ),
					'localAi'       => Tokenwarden_Pricing::local_ai(),
					'gmtOffset'     => (float) get_option( 'gmt_offset' ),
					'demo'          => Tokenwarden_Demo::enabled_for_user(),
					'onboarded'     => Tokenwarden_Onboarding::done(),
					'docsUrl'       => esc_url_raw( (string) apply_filters( 'tokenwarden_docs_url', TOKENWARDEN_SITE . 'docs/' ) ),
					'supportUrl'    => esc_url_raw( (string) apply_filters( 'tokenwarden_support_url', 'https://wordpress.org/support/plugin/tokenwarden/' ) ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * "Dashboard" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'tokenwarden' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu icon: a gateway arch with a flow line, as a data URI SVG.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 2a7 7 0 0 0-7 7v8a1 1 0 0 0 1 1h3v-6a3 3 0 0 1 6 0v6h3a1 1 0 0 0 1-1V9a7 7 0 0 0-7-7Zm0 3.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
