<?php
/**
 * Compatibility with the site's AI setup: provider credentials and the AI plugin's Connector Approval.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads AI setup state without making any AI or network request.
 *
 * Tokenwarden never calls AI itself. Checking a provider by calling its API would make Tokenwarden an
 * AI "caller", which the AI plugin's Connector Approval feature then asks an administrator to
 * approve. So providers are checked by looking at their configured credentials only.
 */
final class Tokenwarden_Compat {

	/** Error code the AI plugin's Connector Approval uses when it blocks a request. */
	const APPROVAL_ERROR = 'wpai_connector_not_approved';

	/**
	 * Registered AI providers and whether an API key is configured for each.
	 *
	 * @return array[] { id, name, configured }
	 */
	public static function providers() {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return array();
		}
		$out = array();
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				$class = $registry->getProviderClassName( $id );
				$out[] = array(
					'id'         => $id,
					'name'       => $class::metadata()->getName(),
					'configured' => self::has_credentials( $id, $registry ),
				);
			}
		} catch ( \Throwable $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * Whether a provider has credentials, from its connector settings (environment variable,
	 * constant or saved option) or from authentication registered with the AI Client.
	 *
	 * @param string $id       Provider id.
	 * @param object $registry AI Client provider registry.
	 * @return bool
	 */
	private static function has_credentials( $id, $registry ) {
		try {
			if ( null !== $registry->getProviderRequestAuthentication( $id ) ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$connector = function_exists( 'wp_get_connector' ) ? wp_get_connector( $id ) : null;
		$auth      = is_array( $connector ) && isset( $connector['authentication'] ) ? $connector['authentication'] : array();
		if ( ! empty( $auth['env_var_name'] ) && '' !== (string) getenv( $auth['env_var_name'] ) ) {
			return true;
		}
		if ( ! empty( $auth['constant_name'] ) && defined( $auth['constant_name'] ) && '' !== (string) constant( $auth['constant_name'] ) ) {
			return true;
		}
		if ( ! empty( $auth['setting_name'] ) && '' !== (string) get_option( $auth['setting_name'], '' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * The AI plugin's Connector Approval feature: whether it is on, where its screen is, and which
	 * plugins are waiting for approval.
	 *
	 * @return array { active, url, pending: [ { name, type, connector, attempts, last_seen } ] }
	 */
	public static function connector_approval() {
		$installed = class_exists( '\WordPress\AI\Connector_Approval\Http_Guard' );
		$enabled   = (bool) get_option( 'wpai_feature_connector-approval_enabled', false );
		if ( defined( '\WordPress\AI\Settings\Settings_Registration::GLOBAL_OPTION' ) ) {
			$enabled = $enabled && (bool) get_option( constant( '\WordPress\AI\Settings\Settings_Registration::GLOBAL_OPTION' ), false );
		}
		/**
		 * Filters whether the AI plugin's Connector Approval feature is treated as active.
		 *
		 * @param bool $active Active.
		 */
		$active = (bool) apply_filters( 'tokenwarden_connector_approval_active', $installed && $enabled );

		$pending = array();
		if ( $active ) {
			foreach ( (array) get_option( 'wpai_connector_approval_pending', array() ) as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['caller_basename'] ) ) {
					continue;
				}
				$pending[] = array(
					'name'      => (string) ( $entry['caller_name'] ?? $entry['caller_basename'] ),
					'basename'  => (string) $entry['caller_basename'],
					'type'      => (string) ( $entry['caller_type'] ?? 'plugin' ),
					'connector' => (string) ( $entry['connector_id'] ?? '' ),
					'attempts'  => (int) ( $entry['attempts'] ?? 1 ),
					'self'      => 0 === strpos( (string) $entry['caller_basename'], 'tokenwarden' ),
				);
			}
		}

		return array(
			'active'  => $active,
			'url'     => admin_url( 'tools.php?page=ai-connector-approval' ),
			'pending' => $pending,
		);
	}
}
