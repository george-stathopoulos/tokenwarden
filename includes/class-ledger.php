<?php
/**
 * Request ledger: one row per AI call.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores AI calls and keeps month-to-date spend totals for fast budget checks.
 */
final class Tokenwarden_Ledger {

	const DB_VERSION        = '4';
	const DB_VERSION_OPTION = 'tokenwarden_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . ( Tokenwarden_Demo::active() ? 'tokenwarden_demo_requests' : 'tokenwarden_requests' );
	}

	/**
	 * Option that stores the table version (separate for the demo sandbox).
	 *
	 * @return string
	 */
	private static function version_option() {
		return Tokenwarden_Demo::active() ? Tokenwarden_Demo::VERSION : self::DB_VERSION_OPTION;
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				source varchar(191) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				provider varchar(64) NOT NULL DEFAULT '',
				model varchar(191) NOT NULL DEFAULT '',
				capability varchar(48) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'ok',
				input_tokens int(10) unsigned NOT NULL DEFAULT 0,
				output_tokens int(10) unsigned NOT NULL DEFAULT 0,
				cost decimal(14,6) NOT NULL DEFAULT 0,
				priced tinyint(1) NOT NULL DEFAULT 1,
				latency_ms int(10) unsigned NOT NULL DEFAULT 0,
				redactions smallint(5) unsigned NOT NULL DEFAULT 0,
				pii varchar(191) NOT NULL DEFAULT '',
				channel varchar(16) NOT NULL DEFAULT 'ai_client',
				note varchar(255) NOT NULL DEFAULT '',
				prompt_hash char(40) NOT NULL DEFAULT '',
				cached tinyint(1) NOT NULL DEFAULT 0,
				saved decimal(14,6) NOT NULL DEFAULT 0,
				prompt_excerpt text NULL,
				response_excerpt text NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY source_created (source,created_at),
				KEY status (status),
				KEY prompt_hash (prompt_hash)
			) {$charset};"
		);

		update_option( self::version_option(), self::DB_VERSION, false );
	}

	/**
	 * Install the table when it is missing or outdated.
	 */
	public static function maybe_install() {
		if ( get_option( self::version_option() ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Record one AI call.
	 *
	 * @param array $row Column values. `created_at` defaults to now (site time).
	 * @return int Inserted id.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row = array_merge(
			array(
				'created_at'       => current_time( 'mysql' ),
				'source'           => 'core',
				'user_id'          => get_current_user_id(),
				'provider'         => '',
				'model'            => '',
				'capability'       => '',
				'status'           => 'ok',
				'input_tokens'     => 0,
				'output_tokens'    => 0,
				'cost'             => 0,
				'priced'           => 1,
				'latency_ms'       => 0,
				'redactions'       => 0,
				'pii'              => '',
				'channel'          => 'ai_client',
				'note'             => '',
				'prompt_excerpt'   => null,
				'response_excerpt' => null,
				'prompt_hash'      => '',
				'cached'           => 0,
				'saved'            => 0,
			),
			$row
		);

		/**
		 * Filters a call's row just before it is recorded.
		 *
		 * The response cache uses this to mark a call that was answered from the cache
		 * (set `cached` to 1, move the cost into `saved`, set `cost` to 0).
		 *
		 * @param array $row Row values.
		 */
		$row = (array) apply_filters( 'tokenwarden_record_row', $row );

		$row['note'] = mb_substr( (string) $row['note'], 0, 255 );

		// Load (or rebuild) the month totals before inserting so the new row is not counted twice.
		self::month_totals( substr( $row['created_at'], 0, 7 ) );

		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$id = (int) $wpdb->insert_id;

		if ( (float) $row['cost'] > 0 ) {
			self::add_spend( $row['source'], (float) $row['cost'], substr( $row['created_at'], 0, 7 ) );
		}

		/**
		 * Fires after an AI call is recorded.
		 *
		 * @param int   $id  Row id.
		 * @param array $row Row values.
		 */
		do_action( 'tokenwarden_recorded', $id, $row );

		return $id;
	}

	/**
	 * Calls a source made in the last hour (completed or failed; blocked calls don't count).
	 *
	 * @param string $source Source id.
	 * @return int
	 */
	public static function calls_last_hour( $source ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = %s AND created_at >= %s AND status <> 'blocked'", self::table(), $source, $since ) );
	}

	/**
	 * Month-to-date spend for one source, or for the whole site with `__total`.
	 *
	 * @param string      $source Source id or `__total`.
	 * @param string|null $month  Month as `YYYY-MM`, default current.
	 * @return float
	 */
	public static function month_spend( $source = '__total', $month = null ) {
		$totals = self::month_totals( $month );
		return isset( $totals[ $source ] ) ? (float) $totals[ $source ] : 0.0;
	}

	/**
	 * All month-to-date totals keyed by source plus `__total`.
	 *
	 * @param string|null $month Month as `YYYY-MM`.
	 * @return array<string,float>
	 */
	public static function month_totals( $month = null ) {
		$month  = $month ? $month : current_time( 'Y-m' );
		$totals = get_option( self::spend_option( $month ), null );
		if ( ! is_array( $totals ) ) {
			$totals = self::rebuild_month( $month );
		}
		return $totals;
	}

	/**
	 * Rebuild cached month totals from the table.
	 *
	 * @param string $month Month as `YYYY-MM`.
	 * @return array<string,float>
	 */
	public static function rebuild_month( $month ) {
		global $wpdb;
		$table = self::table();
		$start = $month . '-01 00:00:00';
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 month' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source, SUM(cost) AS spend FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY source", $table, $start, $end ), ARRAY_A );

		$totals = array( '__total' => 0.0 );
		foreach ( (array) $rows as $r ) {
			$totals[ $r['source'] ]  = round( (float) $r['spend'], 6 );
			$totals['__total']      += (float) $r['spend'];
		}
		$totals['__total'] = round( $totals['__total'], 6 );
		update_option( self::spend_option( $month ), $totals, false );
		return $totals;
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;
		$days   = (int) Tokenwarden_Settings::get( 'logging' )['retention_days'];
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created_at < %s", $table, $cutoff ) );
	}

	/**
	 * Delete every row and cached total.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::delete_spend_options();
	}

	/**
	 * Drop the table and cached totals (uninstall).
	 */
	public static function uninstall() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		self::delete_spend_options();
		delete_option( self::version_option() );
	}

	/**
	 * Add to cached month totals.
	 *
	 * @param string $source Source id.
	 * @param float  $cost   Cost.
	 * @param string $month  Month as `YYYY-MM`.
	 */
	private static function add_spend( $source, $cost, $month ) {
		$totals              = self::month_totals( $month );
		$totals[ $source ]   = round( ( $totals[ $source ] ?? 0 ) + $cost, 6 );
		$totals['__total']   = round( ( $totals['__total'] ?? 0 ) + $cost, 6 );
		update_option( self::spend_option( $month ), $totals, false );
	}

	/**
	 * Option name for a month's totals.
	 *
	 * @param string $month Month as `YYYY-MM`.
	 * @return string
	 */
	private static function spend_option( $month ) {
		return self::spend_prefix() . str_replace( '-', '', $month );
	}

	/**
	 * Prefix of the month-total options (separate for the demo sandbox).
	 *
	 * @return string
	 */
	private static function spend_prefix() {
		return Tokenwarden_Demo::active() ? 'tokenwarden_demo_spend_' : 'tokenwarden_spend_';
	}

	/**
	 * Remove every cached month total.
	 */
	private static function delete_spend_options() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::spend_prefix() ) . '%' ) );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
}
