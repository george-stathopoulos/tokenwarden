<?php
/**
 * Privacy: suggested policy text, personal data export and erasure.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects the request log to WordPress's privacy tools (Tools → Export / Erase Personal Data).
 *
 * The log stores the ID of the logged-in user who triggered each AI call and, only when the
 * admin turns it on, short prompt and response excerpts. Erasure anonymises those rows instead
 * of deleting them, so costs and budgets stay correct.
 */
final class Tokenwarden_Privacy {

	const PAGE = 100;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Live request table (privacy requests never use the demo sandbox).
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tokenwarden_requests';
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Tokenwarden records the AI requests your site makes. Adapt the text below to how your site uses AI features.', 'tokenwarden' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When you use a feature of this site that is powered by artificial intelligence, we record that the request was made, when, which AI model handled it, how many tokens it used and what it cost. If you were logged in, the record is linked to your account. These records are kept for up to 365 days and are used to control costs.', 'tokenwarden' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Text sent to the AI provider may pass through a filter that replaces email addresses, phone numbers and similar details with placeholders before it leaves this site. If the site owner turns on debugging excerpts, the first part of each request and response is stored with the record.', 'tokenwarden' ) . '</p>';
		$content .= '<p>' . esc_html__( 'You can ask for a copy of these records, or for them to be anonymised, using this site\'s personal data request process.', 'tokenwarden' ) . '</p>';
		wp_add_privacy_policy_content( 'Tokenwarden', wp_kses_post( $content ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['tokenwarden'] = array(
			'exporter_friendly_name' => __( 'Tokenwarden request log', 'tokenwarden' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['tokenwarden'] = array(
			'eraser_friendly_name' => __( 'Tokenwarden request log', 'tokenwarden' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of a user's AI requests.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user || ! self::table_exists() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$offset = ( max( 1, (int) $page ) - 1 ) * self::PAGE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export reads the plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, source, provider, model, status, input_tokens, output_tokens, cost, prompt_excerpt, response_excerpt FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
				self::table(),
				$user->ID,
				self::PAGE,
				$offset
			),
			ARRAY_A
		);

		$data = array();
		foreach ( (array) $rows as $r ) {
			$items = array(
				array(
					'name'  => __( 'Date', 'tokenwarden' ),
					'value' => $r['created_at'],
				),
				array(
					'name'  => __( 'Source', 'tokenwarden' ),
					'value' => $r['source'],
				),
				array(
					'name'  => __( 'Model', 'tokenwarden' ),
					'value' => trim( $r['provider'] . ' ' . $r['model'] ),
				),
				array(
					'name'  => __( 'Status', 'tokenwarden' ),
					'value' => $r['status'],
				),
				array(
					'name'  => __( 'Tokens', 'tokenwarden' ),
					'value' => (int) $r['input_tokens'] + (int) $r['output_tokens'],
				),
				array(
					'name'  => __( 'Estimated cost (USD)', 'tokenwarden' ),
					'value' => $r['cost'],
				),
			);
			if ( null !== $r['prompt_excerpt'] && '' !== $r['prompt_excerpt'] ) {
				$items[] = array(
					'name'  => __( 'Prompt excerpt', 'tokenwarden' ),
					'value' => $r['prompt_excerpt'],
				);
			}
			if ( null !== $r['response_excerpt'] && '' !== $r['response_excerpt'] ) {
				$items[] = array(
					'name'  => __( 'Response excerpt', 'tokenwarden' ),
					'value' => $r['response_excerpt'],
				);
			}
			$data[] = array(
				'group_id'    => 'tokenwarden',
				'group_label' => __( 'AI requests', 'tokenwarden' ),
				'item_id'     => 'tokenwarden-request-' . (int) $r['id'],
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => count( (array) $rows ) < self::PAGE,
		);
	}

	/**
	 * Anonymise one page of a user's AI requests. Costs are kept so budgets stay correct.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: anonymised rows drop out of the query).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user || ! self::table_exists() ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure updates the plugin's own table.
		$changed = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET user_id = 0, prompt_excerpt = NULL, response_excerpt = NULL WHERE user_id = %d LIMIT %d',
				self::table(),
				$user->ID,
				self::PAGE * 5
			)
		);
		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $changed < self::PAGE * 5,
		);
	}

	/**
	 * Whether the live table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
	}
}
