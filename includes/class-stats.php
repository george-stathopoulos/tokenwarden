<?php
/**
 * Aggregations for the dashboard.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds dashboard data from the ledger.
 *
 * Queries stay portable (plain SUM/COUNT/SUBSTRING) so they work on MySQL, MariaDB and the
 * SQLite integration; time bucketing that needs date functions is done in PHP.
 */
final class Tokenwarden_Stats {

	/** Number of named sources in the stacked chart before folding into "Other". */
	const TOP_SOURCES = 5;

	/**
	 * Everything the Overview page needs.
	 *
	 * @param int $days Window length in days.
	 * @return array
	 */
	public static function overview( $days ) {
		$days  = max( 1, min( 365, (int) $days ) );
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$today = strtotime( gmdate( 'Y-m-d', $now ) );
		$start = $today - ( $days - 1 ) * DAY_IN_SECONDS;
		$prev  = $start - $days * DAY_IN_SECONDS;

		$window   = self::range( $start, $now + 1 );
		$previous = self::range( $prev, $start );

		return array(
			'days'      => $days,
			'start'     => gmdate( 'Y-m-d', $start ),
			'kpis'      => self::kpis( $window ),
			'previous'  => self::kpis( $previous ),
			'series'    => self::series( $start, $days ),
			'sources'   => self::sources( $start, $now + 1 ),
			'models'    => self::models( $start, $now + 1 ),
			'heatmap'   => self::heatmap( $start, $now + 1 ),
			'recent'    => self::recent( 8 ),
			'month'     => self::month(),
			'providers' => self::providers(),
			'order'     => self::source_order(),
			'pricing'   => Tokenwarden_Pricing::info(),
			'approval'  => Tokenwarden_Compat::connector_approval(),
			'spikes'    => self::spikes(),
		);
	}

	/**
	 * Sources whose activity right now is far above their own normal level.
	 *
	 * - Calls: the last hour has at least 20 calls and 5× the source's average hourly calls over the
	 *   previous 7 days.
	 * - Spend: the last 24 hours cost at least $1 and 4× the source's average daily spend over the
	 *   previous 14 days.
	 * A source needs 3 days of history first, so a newly installed plugin doesn't raise an alarm.
	 *
	 * @return array[] { source, label, kind: calls|spend, now, normal }
	 */
	public static function spikes() {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$at    = function ( $ts ) {
			return gmdate( 'Y-m-d H:i:s', $ts );
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS calls_hour,
					SUM(CASE WHEN created_at >= %s AND created_at < %s THEN 1 ELSE 0 END) AS calls_week,
					SUM(CASE WHEN created_at >= %s THEN cost ELSE 0 END) AS spend_day,
					SUM(CASE WHEN created_at >= %s AND created_at < %s THEN cost ELSE 0 END) AS spend_prev,
					MIN(created_at) AS first_seen
				FROM %i WHERE created_at >= %s AND status <> 'blocked' GROUP BY source",
				$at( $now - HOUR_IN_SECONDS ),
				$at( $now - 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS ),
				$at( $now - HOUR_IN_SECONDS ),
				$at( $now - DAY_IN_SECONDS ),
				$at( $now - 15 * DAY_IN_SECONDS ),
				$at( $now - DAY_IN_SECONDS ),
				$table,
				$at( $now - 15 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $r ) {
			$history = $now - strtotime( $r['first_seen'] );
			if ( $history < 3 * DAY_IN_SECONDS ) {
				continue;
			}
			$label = Tokenwarden_Attribution::label( $r['source'] );

			$hours  = max( 1, min( 7 * 24, ( $history - HOUR_IN_SECONDS ) / HOUR_IN_SECONDS ) );
			$hourly = (int) $r['calls_week'] / $hours;
			$calls  = (int) $r['calls_hour'];
			if ( $calls >= 20 && $calls >= 5 * $hourly ) {
				$out[] = array(
					'source' => $r['source'],
					'label'  => $label,
					'kind'   => 'calls',
					'now'    => $calls,
					'normal' => round( $hourly, 1 ),
				);
			}

			$days  = max( 1, min( 14, ( $history - DAY_IN_SECONDS ) / DAY_IN_SECONDS ) );
			$daily = (float) $r['spend_prev'] / $days;
			$spend = (float) $r['spend_day'];
			if ( $spend >= 1 && $spend >= 4 * $daily ) {
				$out[] = array(
					'source' => $r['source'],
					'label'  => $label,
					'kind'   => 'spend',
					'now'    => round( $spend, 2 ),
					'normal' => round( $daily, 2 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Repeated prompts: completed, uncached calls whose request was identical to another call in
	 * the window, and what answering the repeats from a cache would have saved.
	 *
	 * For a prompt sent n times at total cost c, the repeats cost c × (n − 1) / n.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array { calls, potential, total_calls, sources: [ { id, label, calls, potential, share } ] }
	 */
	public static function repeats( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, prompt_hash, COUNT(*) AS n, SUM(cost) AS cost FROM %i
				WHERE created_at >= %s AND created_at < %s AND status = 'ok' AND cached = 0 AND prompt_hash <> ''
				GROUP BY source, prompt_hash",
				$table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);

		$by     = array();
		$calls  = 0;
		$saving = 0.0;
		foreach ( (array) $rows as $r ) {
			$n  = (int) $r['n'];
			$id = $r['source'];
			if ( ! isset( $by[ $id ] ) ) {
				$by[ $id ] = array( 'calls' => 0, 'total' => 0, 'potential' => 0.0 );
			}
			$by[ $id ]['total'] += $n;
			if ( $n > 1 ) {
				$p                        = (float) $r['cost'] * ( $n - 1 ) / $n;
				$by[ $id ]['calls']      += $n - 1;
				$by[ $id ]['potential']  += $p;
				$calls                   += $n - 1;
				$saving                  += $p;
			}
		}

		$sources = array();
		foreach ( $by as $id => $v ) {
			if ( $v['calls'] < 1 ) {
				continue;
			}
			$sources[] = array(
				'id'        => $id,
				'label'     => Tokenwarden_Attribution::label( $id ),
				'calls'     => $v['calls'],
				'potential' => round( $v['potential'], 6 ),
				'share'     => $v['total'] ? round( $v['calls'] / $v['total'], 4 ) : 0,
			);
		}
		usort(
			$sources,
			function ( $a, $b ) {
				return $b['potential'] <=> $a['potential'];
			}
		);

		return array(
			'calls'       => $calls,
			'potential'   => round( $saving, 6 ),
			'total_calls' => array_sum( array_column( $by, 'total' ) ),
			'sources'     => $sources,
		);
	}

	/**
	 * Every source id ordered by first appearance.
	 *
	 * The dashboard assigns chart colors in this order, so a source keeps its color across
	 * date ranges and filters.
	 *
	 * @return string[]
	 */
	public static function source_order() {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col( $wpdb->prepare( 'SELECT source FROM %i GROUP BY source ORDER BY MIN(id) ASC', $table ) );
	}

	/**
	 * Totals for a time range.
	 *
	 * @param int $from Start timestamp (site time).
	 * @param int $to   End timestamp (exclusive).
	 * @return array
	 */
	private static function range( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests,
					SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok,
					SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked,
					SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
					SUM(cost) AS cost,
					SUM(input_tokens) AS input_tokens,
					SUM(output_tokens) AS output_tokens,
					SUM(redactions) AS redactions,
					SUM(CASE WHEN pii <> '' THEN 1 ELSE 0 END) AS pii_calls,
					SUM(CASE WHEN status = 'ok' THEN latency_ms ELSE 0 END) AS latency_sum,
					SUM(CASE WHEN status = 'ok' AND priced = 0 THEN 1 ELSE 0 END) AS unpriced,
					SUM(cached) AS cached,
					SUM(saved) AS saved
				FROM %i WHERE created_at >= %s AND created_at < %s", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	/**
	 * KPI values from a range row.
	 *
	 * @param array $r Range row.
	 * @return array
	 */
	private static function kpis( array $r ) {
		$ok = (int) ( $r['ok'] ?? 0 );
		return array(
			'requests'     => (int) ( $r['requests'] ?? 0 ),
			'ok'           => $ok,
			'blocked'      => (int) ( $r['blocked'] ?? 0 ),
			'errors'       => (int) ( $r['errors'] ?? 0 ),
			'cost'         => round( (float) ( $r['cost'] ?? 0 ), 6 ),
			'tokens'       => (int) ( $r['input_tokens'] ?? 0 ) + (int) ( $r['output_tokens'] ?? 0 ),
			'input_tokens' => (int) ( $r['input_tokens'] ?? 0 ),
			'redactions'   => (int) ( $r['redactions'] ?? 0 ),
			'pii_calls'    => (int) ( $r['pii_calls'] ?? 0 ),
			'avg_cost'     => $ok ? round( (float) ( $r['cost'] ?? 0 ) / $ok, 6 ) : 0,
			'avg_latency'  => $ok ? (int) round( (int) ( $r['latency_sum'] ?? 0 ) / $ok ) : 0,
			'unpriced'     => (int) ( $r['unpriced'] ?? 0 ),
			'cached'       => (int) ( $r['cached'] ?? 0 ),
			'saved'        => round( (float) ( $r['saved'] ?? 0 ), 6 ),
		);
	}

	/**
	 * Daily cost per top source, plus daily requests, tokens, redactions and blocks.
	 *
	 * @param int $start Start timestamp (midnight, site time).
	 * @param int $days  Number of days.
	 * @return array
	 */
	private static function series( $start, $days ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		$end   = $start + $days * DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTRING(created_at, 1, 10) AS day, source,
					COUNT(*) AS requests, SUM(cost) AS cost,
					SUM(input_tokens + output_tokens) AS tokens, SUM(CASE WHEN pii <> '' THEN 1 ELSE 0 END) AS pii_calls,
					SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked
				FROM %i WHERE created_at >= %s AND created_at < %s
				GROUP BY SUBSTRING(created_at, 1, 10), source", $table,
				gmdate( 'Y-m-d H:i:s', $start ),
				gmdate( 'Y-m-d H:i:s', $end )
			),
			ARRAY_A
		);

		// Rank sources by cost over the window, then fold the tail into "other".
		$totals = array();
		foreach ( (array) $rows as $r ) {
			$totals[ $r['source'] ] = ( $totals[ $r['source'] ] ?? 0 ) + (float) $r['cost'];
		}
		arsort( $totals );
		$top = array_slice( array_keys( array_filter( $totals ) ), 0, self::TOP_SOURCES );

		$dates = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$dates[] = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
		}
		$blank = array_fill_keys( $dates, 0 );
		$cost  = array_fill_keys( array_merge( $top, array( 'other' ) ), $blank );
		$meta  = array(
			'requests'   => $blank,
			'tokens'     => $blank,
			'pii_calls'  => $blank,
			'blocked'    => $blank,
			'cost'       => $blank,
		);

		foreach ( (array) $rows as $r ) {
			if ( ! isset( $blank[ $r['day'] ] ) ) {
				continue;
			}
			$key                          = in_array( $r['source'], $top, true ) ? $r['source'] : 'other';
			$cost[ $key ][ $r['day'] ]   += (float) $r['cost'];
			$meta['requests'][ $r['day'] ]   += (int) $r['requests'];
			$meta['tokens'][ $r['day'] ]     += (int) $r['tokens'];
			$meta['pii_calls'][ $r['day'] ]  += (int) $r['pii_calls'];
			$meta['blocked'][ $r['day'] ]    += (int) $r['blocked'];
			$meta['cost'][ $r['day'] ]       += (float) $r['cost'];
		}

		$stack = array();
		foreach ( $cost as $source => $values ) {
			if ( 'other' === $source && 0.0 === (float) array_sum( $values ) ) {
				continue;
			}
			$stack[] = array(
				'source' => $source,
				'label'  => 'other' === $source ? __( 'Other', 'tokenwarden' ) : Tokenwarden_Attribution::label( $source ),
				'values' => array_map(
					function ( $v ) {
						return round( $v, 6 );
					},
					array_values( $values )
				),
			);
		}

		return array(
			'dates'      => $dates,
			'stack'      => $stack,
			'requests'   => array_values( $meta['requests'] ),
			'tokens'     => array_values( $meta['tokens'] ),
			'pii_calls'  => array_values( $meta['pii_calls'] ),
			'blocked'    => array_values( $meta['blocked'] ),
			'cost'       => array_map(
				function ( $v ) {
					return round( $v, 6 );
				},
				array_values( $meta['cost'] )
			),
		);
	}

	/**
	 * Per-source totals for a range, with budgets and month-to-date spend.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array
	 */
	public static function sources( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, COUNT(*) AS requests, SUM(cost) AS cost,
					SUM(input_tokens + output_tokens) AS tokens, SUM(redactions) AS redactions,
					SUM(CASE WHEN pii <> '' THEN 1 ELSE 0 END) AS pii_calls,
					SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked,
					SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
					MAX(created_at) AS last_seen
				FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY source", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);

		$models   = self::source_models( $from, $to );
		$month    = Tokenwarden_Ledger::month_totals();
		$forecast = self::forecasts();
		$out      = array();
		$seen     = array();

		foreach ( (array) $rows as $r ) {
			$seen[ $r['source'] ] = true;
			$out[]                = self::source_row( $r['source'], $r, $models, $month, $forecast );
		}
		// Sources with a saved policy but no traffic in the window still appear.
		foreach ( array_keys( (array) Tokenwarden_Settings::get( 'sources' ) ) as $source ) {
			if ( ! isset( $seen[ $source ] ) ) {
				$out[] = self::source_row( $source, array(), $models, $month, $forecast );
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['cost'] <=> $a['cost'] ?: $b['requests'] <=> $a['requests'];
			}
		);
		return $out;
	}

	/**
	 * One source row.
	 *
	 * @param string $source Source id.
	 * @param array  $r      Aggregates.
	 * @param array  $models Model mix per source.
	 * @param array  $month    Month-to-date totals.
	 * @param array  $forecast Month-end forecasts.
	 * @return array
	 */
	private static function source_row( $source, array $r, array $models, array $month, array $forecast ) {
		$policy = Tokenwarden_Settings::source( $source );
		$spend  = (float) ( $month[ $source ] ?? 0 );
		// A paused source makes no more calls, so its forecast is what it has already spent.
		$fc = $policy['paused'] ? $spend : (float) ( $forecast[ $source ] ?? $spend );
		return array(
			'id'         => $source,
			'label'      => Tokenwarden_Attribution::label( $source ),
			'type'       => Tokenwarden_Attribution::type( $source ),
			'requests'   => (int) ( $r['requests'] ?? 0 ),
			'cost'       => round( (float) ( $r['cost'] ?? 0 ), 6 ),
			'tokens'     => (int) ( $r['tokens'] ?? 0 ),
			'redactions' => (int) ( $r['redactions'] ?? 0 ),
			'pii_calls'  => (int) ( $r['pii_calls'] ?? 0 ),
			'blocked'    => (int) ( $r['blocked'] ?? 0 ),
			'errors'     => (int) ( $r['errors'] ?? 0 ),
			'last_seen'  => $r['last_seen'] ?? null,
			'models'     => $models[ $source ] ?? array(),
			'month'      => round( $spend, 6 ),
			'forecast'   => round( $fc, 6 ),
			'policy'     => $policy,
			'status'     => self::source_status( $policy, $spend, $fc, $source ),
			'last_hour'  => Tokenwarden_Ledger::calls_last_hour( $source ),
			'rate_limit' => Tokenwarden_Settings::rate_limit_for( $source ),
		);
	}

	/**
	 * Status of a source: `active`, `forecast` (on pace to exceed), `near`, `capped`, `limited`
	 * (hit its hourly call limit) or `paused`.
	 *
	 * @param array  $policy   Policy.
	 * @param float  $spend    Month-to-date spend.
	 * @param float  $forecast Month-end forecast.
	 * @param string $source   Source id.
	 * @return string
	 */
	private static function source_status( array $policy, $spend, $forecast, $source = '' ) {
		if ( $policy['paused'] ) {
			return 'paused';
		}
		$limit = Tokenwarden_Settings::rate_limit_for( $source );
		if ( $limit > 0 && Tokenwarden_Ledger::calls_last_hour( $source ) >= $limit ) {
			return 'limited';
		}
		if ( $policy['budget'] > 0 ) {
			$pct = $spend / $policy['budget'] * 100;
			if ( $pct >= 100 ) {
				return 'capped';
			}
			if ( $pct >= (int) Tokenwarden_Settings::get( 'alerts' )['threshold'] ) {
				return 'near';
			}
			if ( $forecast > $policy['budget'] ) {
				return 'forecast';
			}
		}
		return 'active';
	}

	/**
	 * Month-end spend forecast per source and for the whole site (`__total`).
	 *
	 * Month-to-date spend plus the trailing 7-day daily average for each remaining day.
	 * A trailing average is steadier than extrapolating from the first days of a month.
	 *
	 * @return array<string,float>
	 */
	public static function forecasts() {
		global $wpdb;
		$table     = Tokenwarden_Ledger::table();
		$now       = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$remaining = ( strtotime( gmdate( 'Y-m-01', $now ) . ' +1 month' ) - $now ) / DAY_IN_SECONDS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source, SUM(cost) AS cost FROM %i WHERE created_at >= %s GROUP BY source", $table, gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ) ), ARRAY_A );

		$month = Tokenwarden_Ledger::month_totals();
		$out   = array( '__total' => (float) ( $month['__total'] ?? 0 ) );
		foreach ( (array) $rows as $r ) {
			$add                  = (float) $r['cost'] / 7 * $remaining;
			$out[ $r['source'] ]  = (float) ( $month[ $r['source'] ] ?? 0 ) + $add;
			$out['__total']      += $add;
		}
		return $out;
	}

	/**
	 * Models used per source.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array<string,string[]>
	 */
	private static function source_models( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, model, COUNT(*) AS n FROM %i
				WHERE created_at >= %s AND created_at < %s AND model <> '' GROUP BY source, model", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['source'] ][ $r['model'] ] = (int) $r['n'];
		}
		foreach ( $out as $source => $counts ) {
			arsort( $counts );
			$out[ $source ] = array_keys( $counts );
		}
		return $out;
	}

	/**
	 * Cost and calls per model.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array
	 */
	private static function models( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT model, provider, COUNT(*) AS requests, SUM(cost) AS cost,
					SUM(input_tokens + output_tokens) AS tokens, MIN(priced) AS priced
				FROM %i WHERE created_at >= %s AND created_at < %s AND status = 'ok'
				GROUP BY model, provider", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'model'    => $r['model'],
				'provider' => $r['provider'],
				'requests' => (int) $r['requests'],
				'cost'     => round( (float) $r['cost'], 6 ),
				'tokens'   => (int) $r['tokens'],
				'priced'   => (bool) $r['priced'],
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['cost'] <=> $a['cost'] ?: $b['requests'] <=> $a['requests'];
			}
		);
		return $out;
	}

	/**
	 * Requests by weekday (0 = Monday) and hour.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return int[][] 7 rows of 24 counts.
	 */
	private static function heatmap( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTRING(created_at, 1, 13) AS hour, COUNT(*) AS n FROM %i
				WHERE created_at >= %s AND created_at < %s GROUP BY SUBSTRING(created_at, 1, 13)", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		foreach ( (array) $rows as $r ) {
			$ts = strtotime( $r['hour'] . ':00:00 UTC' );
			if ( false === $ts ) {
				continue;
			}
			$grid[ (int) gmdate( 'N', $ts ) - 1 ][ (int) gmdate( 'G', $ts ) ] += (int) $r['n'];
		}
		return $grid;
	}

	/**
	 * Most recent requests.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	private static function recent( $limit ) {
		return self::requests( array( 'per_page' => $limit ) )['rows'];
	}

	/**
	 * Volume context for the settings screens: 30-day calls, average input price and personal data.
	 *
	 * @return array
	 */
	public static function usage_context() {
		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$from   = $now - 30 * DAY_IN_SECONDS;
		$models = self::models( $from, $now + 1 );
		$calls  = 0;
		$price  = 0.0;
		foreach ( $models as $m ) {
			$p = Tokenwarden_Pricing::price_for( $m['model'] );
			if ( null !== $p ) {
				$calls += $m['requests'];
				$price += $p[0] * $m['requests'];
			}
		}
		$kpis = self::kpis( self::range( $from, $now + 1 ) );
		return array(
			'calls_30d'       => $kpis['ok'],
			'avg_input_price' => $calls ? round( $price / $calls, 4 ) : 0,
			'redactions_30d'  => $kpis['redactions'],
			'pii_calls_30d'   => $kpis['pii_calls'],
			'privacy'         => self::privacy_report( $from, $now + 1 ),
			'sources'         => array_map(
				function ( $s ) {
					return array(
						'id'     => $s['id'],
						'label'  => $s['label'],
						'type'   => $s['type'],
						'policy' => $s['policy'],
					);
				},
				self::sources( $from, $now + 1 )
			),
		);
	}

	/**
	 * Personal data found in requests, per source: how many calls carried it, of which types, and
	 * whether the source has redaction turned on.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array
	 */
	public static function privacy_report( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, pii, COUNT(*) AS calls, SUM(redactions) AS redactions
				FROM %i WHERE created_at >= %s AND created_at < %s AND status <> 'blocked'
				GROUP BY source, pii", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$id = $r['source'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'id'         => $id,
					'label'      => Tokenwarden_Attribution::label( $id ),
					'type'       => Tokenwarden_Attribution::type( $id ),
					'calls'      => 0,
					'pii_calls'  => 0,
					'redactions' => 0,
					'types'      => array(),
					'redact'     => (bool) Tokenwarden_Settings::source( $id )['redact'],
				);
			}
			$calls                     = (int) $r['calls'];
			$out[ $id ]['calls']      += $calls;
			$out[ $id ]['redactions'] += (int) $r['redactions'];
			if ( '' !== $r['pii'] ) {
				$out[ $id ]['pii_calls'] += $calls;
				foreach ( Tokenwarden_Redactor::decode_counts( $r['pii'] ) as $type => $n ) {
					$out[ $id ]['types'][ $type ] = ( $out[ $id ]['types'][ $type ] ?? 0 ) + $n * $calls;
				}
			}
		}

		$out = array_values( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['pii_calls'] <=> $a['pii_calls'] ?: $b['calls'] <=> $a['calls'];
			}
		);
		return $out;
	}

	/**
	 * AI data map: per source, the providers, models and routes it used, its volume and spend,
	 * the personal data found in its requests and the controls that apply to it.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array[]
	 */
	public static function data_map( $from, $to ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, provider, model, channel,
					SUM(CASE WHEN status <> 'blocked' THEN 1 ELSE 0 END) AS calls,
					SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked,
					SUM(cost) AS cost, SUM(redactions) AS redactions,
					MIN(created_at) AS first_seen, MAX(created_at) AS last_seen
				FROM %i WHERE created_at >= %s AND created_at < %s
				GROUP BY source, provider, model, channel", $table,
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			),
			ARRAY_A
		);

		$privacy = array();
		foreach ( self::privacy_report( $from, $to ) as $p ) {
			$privacy[ $p['id'] ] = $p;
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$id = $r['source'];
			if ( ! isset( $out[ $id ] ) ) {
				$policy     = Tokenwarden_Settings::source( $id );
				$out[ $id ] = array(
					'id'          => $id,
					'label'       => Tokenwarden_Attribution::label( $id ),
					'type'        => Tokenwarden_Attribution::type( $id ),
					'providers'   => array(),
					'models'      => array(),
					'routes'      => array(),
					'calls'       => 0,
					'blocked'     => 0,
					'cost'        => 0.0,
					'pii_calls'   => (int) ( $privacy[ $id ]['pii_calls'] ?? 0 ),
					'pii_types'   => $privacy[ $id ]['types'] ?? array(),
					'redact'      => (bool) $policy['redact'],
					'redactions'  => 0,
					'budget'      => (float) $policy['budget'],
					'rate_limit'  => Tokenwarden_Settings::rate_limit_for( $id ),
					'paused'      => (bool) $policy['paused'],
					'first_seen'  => $r['first_seen'],
					'last_seen'   => $r['last_seen'],
				);
			}
			$row = &$out[ $id ];
			if ( '' !== $r['provider'] ) {
				$row['providers'][ $r['provider'] ] = true;
			}
			if ( '' !== $r['model'] ) {
				$row['models'][ $r['model'] ] = true;
			}
			$row['routes'][ 'direct' === $r['channel'] ? 'direct' : 'ai_client' ] = true;
			$row['calls']      += (int) $r['calls'];
			$row['blocked']    += (int) $r['blocked'];
			$row['cost']       += (float) $r['cost'];
			$row['redactions'] += (int) $r['redactions'];
			$row['first_seen']  = min( $row['first_seen'], $r['first_seen'] );
			$row['last_seen']   = max( $row['last_seen'], $r['last_seen'] );
			unset( $row );
		}

		foreach ( $out as &$row ) {
			$row['providers'] = array_keys( $row['providers'] );
			$row['models']    = array_keys( $row['models'] );
			$row['routes']    = array_keys( $row['routes'] );
			$row['cost']      = round( $row['cost'], 6 );
		}
		unset( $row );

		$out = array_values( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['pii_calls'] <=> $a['pii_calls'] ?: $b['calls'] <=> $a['calls'];
			}
		);
		return $out;
	}

	/**
	 * Month summary for other screens.
	 *
	 * @return array
	 */
	public static function overview_month() {
		return self::month();
	}

	/**
	 * Month-to-date spend, budget and projection.
	 *
	 * @return array
	 */
	private static function month() {
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return array(
			'label'      => wp_date( 'F Y', $now, new DateTimeZone( 'UTC' ) ),
			'spend'      => round( Tokenwarden_Ledger::month_spend( '__total' ), 6 ),
			'budget'     => (float) Tokenwarden_Settings::get( 'global_budget' ),
			'projection' => round( self::forecasts()['__total'], 6 ),
			'day'        => (int) gmdate( 'j', $now ),
			'days'       => (int) gmdate( 't', $now ),
		);
	}

	/**
	 * Registered AI providers and whether each has credentials.
	 *
	 * @return array
	 */
	public static function providers() {
		return Tokenwarden_Compat::providers();
	}

	/**
	 * Paged, filtered request log.
	 *
	 * @param array $args `page`, `per_page`, `source`, `status`, `model`, `search`.
	 * @return array `rows`, `total`, `pages`.
	 */
	public static function requests( array $args ) {
		global $wpdb;
		$table    = Tokenwarden_Ledger::table();
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$source = ! empty( $args['source'] ) ? Tokenwarden_Settings::source_id( $args['source'] ) : '';
		$status = ! empty( $args['status'] ) && in_array( $args['status'], array( 'ok', 'blocked', 'error' ), true ) ? $args['status'] : '';
		$model  = ! empty( $args['model'] ) ? sanitize_text_field( $args['model'] ) : '';
		$only_r = ! empty( $args['pii'] ) ? 1 : 0;
		$offset = ( $page - 1 ) * $per_page;

		// One fixed query; an empty filter value switches its condition off.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR source = %s ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR model = %s ) AND ( %d = 0 OR pii <> '' )",
				$table,
				$source,
				$source,
				$status,
				$status,
				$model,
				$model,
				$only_r
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR source = %s ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR model = %s ) AND ( %d = 0 OR pii <> '' ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$table,
				$source,
				$source,
				$status,
				$status,
				$model,
				$model,
				$only_r,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows   = is_array( $rows ) ? $rows : array();
		$labels = array();
		foreach ( $rows as &$row ) {
			if ( ! isset( $labels[ $row['source'] ] ) ) {
				$labels[ $row['source'] ] = Tokenwarden_Attribution::label( $row['source'] );
			}
			$row['source_label']  = $labels[ $row['source'] ];
			$row['id']            = (int) $row['id'];
			$row['user_id']       = (int) $row['user_id'];
			$row['input_tokens']  = (int) $row['input_tokens'];
			$row['output_tokens'] = (int) $row['output_tokens'];
			$row['cost']          = (float) $row['cost'];
			$row['priced']        = (bool) $row['priced'];
			$row['latency_ms']    = (int) $row['latency_ms'];
			$row['redactions']    = (int) $row['redactions'];
			$row['pii']           = Tokenwarden_Redactor::decode_counts( (string) ( $row['pii'] ?? '' ) );
			unset( $row['brief'] );
			$row['cached']        = (bool) $row['cached'];
			$row['saved']         = (float) $row['saved'];
			unset( $row['prompt_hash'] );
		}
		unset( $row );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Distinct models and sources in the log, for filters.
	 *
	 * @return array
	 */
	public static function facets() {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$models  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT model FROM %i WHERE model <> '' ORDER BY model", $table ) );
		$sources = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i ORDER BY source', $table ) );
		// phpcs:enable
		return array(
			'models'  => $models,
			'order'   => self::source_order(),
			'sources' => array_map(
				function ( $s ) {
					return array(
						'id'    => $s,
						'label' => Tokenwarden_Attribution::label( $s ),
					);
				},
				$sources
			),
		);
	}
}
