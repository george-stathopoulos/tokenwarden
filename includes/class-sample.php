<?php
/**
 * Fictional sample traffic for demos.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates realistic, fictional AI traffic: seven made-up plugins and a theme, daily and weekly
 * rhythms, repeated prompts, personal data, failures, budgets and a paused source.
 *
 * Writes to whichever ledger is current: the demo sandbox when called through Tokenwarden_Demo, or the
 * live log from `wp tokenwarden seed`.
 */
final class Tokenwarden_Sample {

	/**
	 * Seeded generator state, so the same seed always gives the same data.
	 *
	 * @var int
	 */
	private $rng = 7;

	/**
	 * Generate sample traffic.
	 *
	 * @param array         $opts `days`, `per_day`, `seed`, `cache_days` (simulate response caching for the last N days).
	 * @param callable|null $tick Called once per generated day (progress bars).
	 * @return int Rows added.
	 */
	public function generate( array $opts = array(), $tick = null ) {
		global $wpdb;
		$opts      = array_merge(
			array(
				'days'       => 60,
				'per_day'    => 140,
				'seed'       => 7,
				'cache_days' => 0,
			),
			$opts
		);
		$days      = max( 1, min( 365, (int) $opts['days'] ) );
		$per_day   = max( 1, min( 2000, (int) $opts['per_day'] ) );
		$this->rng = max( 1, (int) $opts['seed'] );

		$sources = self::sources();
		$labels  = get_option( 'tokenwarden_source_labels', array() );
		foreach ( $sources as $id => $s ) {
			$labels[ $id ] = $s['label'];
		}
		update_option( 'tokenwarden_source_labels', $labels, false );

		Tokenwarden_Settings::update(
			array(
				// Helpdesk Bot replies to customers, so its personal data is replaced. Form Guard checks
				// whether sign-ups are spam and needs the real email addresses, so it is only monitored.
				'sources'       => array(
					'plugin:helpdesk-bot' => array( 'redact' => true ),
					'plugin:form-guard'   => array( 'rate_limit' => 200 ),
				),
				'rate_limit'    => 500,
			)
		);

		$now      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$today    = strtotime( gmdate( 'Y-m-d', $now ) );
		$weights  = array_column( $sources, 'weight' );
		$ids      = array_keys( $sources );
		$total_w  = array_sum( $weights );
		$inserted = 0;
		$table    = Tokenwarden_Ledger::table();
		$hours    = array( 1, 1, 1, 1, 1, 2, 3, 5, 8, 10, 11, 11, 10, 11, 12, 11, 10, 8, 7, 6, 5, 4, 3, 2 );
		$hour_sum = array_sum( $hours );
		$pool     = array();
		$seen     = array();
		$cache_on = (int) $opts['cache_days'] > 0 ? $now - (int) $opts['cache_days'] * DAY_IN_SECONDS : PHP_INT_MAX;
		$cached   = array( 'plugin:helpdesk-bot', 'plugin:form-guard' );

		$batch    = array();
		for ( $d = $days - 1; $d >= 0; $d-- ) {
			$day     = $today - $d * DAY_IN_SECONDS;
			$weekday = (int) gmdate( 'N', $day );
			$growth  = 0.55 + 0.45 * ( ( $days - $d ) / $days );
			$factor  = $weekday >= 6 ? 0.55 : 1.0;
			$count   = (int) round( $per_day * $growth * $factor * ( 0.85 + $this->rand( 0, 30 ) / 100 ) );

			for ( $i = 0; $i < $count; $i++ ) {
				$hour = $this->pick( $hours, $hour_sum );
				$ts   = $day + $hour * HOUR_IN_SECONDS + $this->rand( 0, 3599 );
				if ( $ts > $now ) {
					continue;
				}
				$id    = $ids[ $this->pick( $weights, $total_w ) ];
				$s     = $sources[ $id ];
				$model = $s['models'][ $this->rand( 0, count( $s['models'] ) - 1 ) ];
				$in    = (int) round( $s['in'] * ( 0.5 + $this->rand( 0, 100 ) / 100 ) );
				$out   = (int) round( $s['out'] * ( 0.5 + $this->rand( 0, 100 ) / 100 ) );

				// Some sources repeat popular prompts (FAQ answers, spam checks); repeats reuse the same request.
				if ( $this->rand( 1, 100 ) <= $s['repeat'] ) {
					$key = $this->rand( 1, 15 );
					if ( ! isset( $pool[ $id ][ $key ] ) ) {
						$pool[ $id ][ $key ] = array( $model, $in, $out );
					}
					list( $model, $in, $out ) = $pool[ $id ][ $key ];
					$hash = sha1( $id . '|popular-' . $key );
				} else {
					$hash = sha1( $id . '|unique-' . $inserted );
				}
				$cost  = Tokenwarden_Pricing::cost( $model, $in, $out );
				$month = gmdate( 'Y-m', $ts );

				$status = 'ok';
				$note   = '';
				if ( $this->rand( 1, 1000 ) <= 6 ) {
					$status = 'error';
					$note   = 'HTTP 529: Overloaded';
					$in     = 0;
					$out    = 0;
					$cost   = 0;
				}

				$pii = '';
				if ( 'blocked' !== $status && $this->rand( 1, 100 ) <= $s['pii'] ) {
					$found = array();
					foreach ( $s['pii_types'] as $type ) {
						if ( ! $found || $this->rand( 1, 100 ) <= 35 ) {
							$found[ $type ] = $this->rand( 1, 2 );
						}
					}
					$pii = Tokenwarden_Redactor::encode_counts( $found );
				}

				$from_cache = 'ok' === $status && $ts >= $cache_on && in_array( $id, $cached, true ) && isset( $seen[ $hash ] );
				$seen[ $hash ] = true;

				$batch[] = array(
						'created_at'    => gmdate( 'Y-m-d H:i:s', $ts ),
						'source'        => $id,
						'user_id'       => 1,
						'provider'      => $s['provider'],
						'model'         => $model,
						'capability'    => 'text_generation',
						'status'        => $status,
						'input_tokens'  => $in,
						'output_tokens' => $out,
						'cost'          => null === $cost || $from_cache ? 0 : $cost,
						'saved'         => $from_cache && null !== $cost ? $cost : 0,
						'cached'        => $from_cache ? 1 : 0,
						'prompt_hash'   => 'ok' === $status ? $hash : '',
						'priced'        => null === $cost ? 0 : 1,
						'latency_ms'    => $from_cache ? $this->rand( 30, 140 ) : ( 'ok' === $status ? (int) round( $s['latency'] * ( 0.6 + $this->rand( 0, 80 ) / 100 ) ) : ( 'error' === $status ? $this->rand( 200, 4000 ) : 0 ) ),
						'pii'           => $pii,
						// Two of the sample plugins call their provider directly, with their own API key.
						'channel'       => in_array( $id, array( 'plugin:shop-copy-ai', 'plugin:form-guard' ), true ) ? 'direct' : 'ai_client',
						'redactions'    => 'plugin:helpdesk-bot' === $id ? array_sum( Tokenwarden_Redactor::decode_counts( $pii ) ) : 0,
						'note'          => $note,
				);
				++$inserted;
				if ( count( $batch ) >= 250 ) {
					self::insert_batch( $table, $batch );
					$batch = array();
				}
			}
			if ( $tick ) {
				call_user_func( $tick );
			}
		}
		self::insert_batch( $table, $batch );

		self::apply_budgets( $now );

		for ( $d = $days; $d >= 0; $d -= 28 ) {
			Tokenwarden_Ledger::rebuild_month( gmdate( 'Y-m', $today - $d * DAY_IN_SECONDS ) );
		}
		Tokenwarden_Ledger::rebuild_month( gmdate( 'Y-m', $now ) );

		return $inserted;
	}

	/**
	 * Insert a batch of rows.
	 *
	 * @param string $table Table.
	 * @param array  $rows  Rows with identical keys.
	 */
	private static function insert_batch( $table, array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		// One transaction per batch keeps thousands of single-row inserts fast.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $rows as $row ) {
			$wpdb->insert( $table, $row );
		}
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
	}


	/**
	 * Repeatable pseudo-random integer (xorshift32), so `--seed` gives the same sample data.
	 * Not for anything security-related.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	private function rand( $min, $max ) {
		$x         = $this->rng;
		$x        ^= ( $x << 13 ) & 0xFFFFFFFF;
		$x        ^= $x >> 17;
		$x        ^= ( $x << 5 ) & 0xFFFFFFFF;
		$this->rng = $x & 0xFFFFFFFF;
		return $min + ( $this->rng % ( $max - $min + 1 ) );
	}

	/**
	 * Weighted random index.
	 *
	 * @param array $weights Weights.
	 * @param float $total   Sum of weights.
	 * @return int
	 */
	private function pick( array $weights, $total ) {
		$r = $this->rand( 0, (int) ( $total * 1000 ) ) / 1000;
		foreach ( array_values( $weights ) as $i => $w ) {
			$r -= $w;
			if ( $r <= 0 ) {
				return $i;
			}
		}
		return count( $weights ) - 1;
	}

	/**
	 * Set sample budgets scaled to the generated traffic so every budget state is visible.
	 *
	 * Helpdesk Bot is on pace to exceed its budget, Shop Copy AI is comfortably inside it,
	 * SEO Insights is paused (recent calls blocked), and the site-wide budget sits about 40%
	 * above the 30-day run rate.
	 *
	 * @param int $now Current site timestamp.
	 */
	private static function apply_budgets( $now ) {
		global $wpdb;
		$table = Tokenwarden_Ledger::table();

		$rate = function ( $source ) use ( $wpdb, $table, $now ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM %i WHERE source = %s AND created_at >= %s", $table, $source, gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ) ) );
		};

		// Helpdesk Bot: budget below its 30-day run rate, so it is on pace to exceed.
		$help_budget = max( 1, floor( $rate( 'plugin:helpdesk-bot' ) * 0.8 ) );
		// Shop Copy AI: comfortable budget.
		$shop_budget = max( 1, ceil( $rate( 'plugin:shop-copy-ai' ) * 1.5 / 5 ) * 5 );

		// SEO Insights: paused 20 hours ago; calls since then were blocked.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'blocked', input_tokens = 0, output_tokens = 0, cost = 0, latency_ms = 0, redactions = 0, pii = '', note = %s WHERE source = %s AND created_at >= %s", $table, __( 'Source is paused', 'tokenwarden' ), 'plugin:seo-insights', gmdate( 'Y-m-d H:i:s', $now - 20 * HOUR_IN_SECONDS ) ) );

		// Site-wide budget: about 40% above the 30-day run rate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM %i WHERE created_at >= %s", $table, gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ) ) );
		$global = max( 5, ceil( $all * 1.4 / 5 ) * 5 );

		Tokenwarden_Settings::update(
			array(
				'global_budget' => $global,
				'sources'       => array(
					'plugin:helpdesk-bot' => array( 'budget' => $help_budget ),
					'plugin:shop-copy-ai' => array( 'budget' => $shop_budget ),
					'plugin:seo-insights' => array(
						'budget' => 15,
						'paused' => true,
					),
				),
			)
		);
	}

	/**
	 * Fictional sources for sample data.
	 *
	 * @return array
	 */
	public static function sources() {
		return array(
			'plugin:helpdesk-bot'      => array(
				'label'    => 'Helpdesk Bot',
				'provider' => 'anthropic',
				'models'   => array( 'claude-haiku-4-5', 'claude-haiku-4-5', 'claude-sonnet-5-5' ),
				'weight'   => 34,
				'in'       => 2600,
				'out'      => 380,
				'latency'  => 1400,
				'pii'      => 46,
				'pii_types' => array( 'EMAIL', 'PHONE' ),
				'repeat'   => 28,
			),
			'plugin:shop-copy-ai'      => array(
				'label'    => 'Shop Copy AI',
				'provider' => 'anthropic',
				'models'   => array( 'claude-sonnet-5-5' ),
				'weight'   => 14,
				'in'       => 3200,
				'out'      => 1400,
				'latency'  => 6200,
				'pii'      => 2,
				'pii_types' => array( 'EMAIL' ),
				'repeat'   => 3,
			),
			'plugin:seo-insights'      => array(
				'label'    => 'SEO Insights',
				'provider' => 'openai',
				'models'   => array( 'gpt-5-mini', 'gpt-5.5' ),
				'weight'   => 12,
				'in'       => 5200,
				'out'      => 700,
				'latency'  => 3800,
				'pii'      => 1,
				'pii_types' => array( 'EMAIL' ),
				'repeat'   => 12,
			),
			'plugin:alt-text-helper'      => array(
				'label'    => 'Alt Text Helper',
				'provider' => 'google',
				'models'   => array( 'gemini-3.8-flash' ),
				'weight'   => 16,
				'in'       => 1800,
				'out'      => 60,
				'latency'  => 900,
				'pii'      => 0,
				'pii_types' => array( 'EMAIL' ),
				'repeat'   => 6,
			),
			'plugin:form-guard'        => array(
				'label'    => 'Form Guard',
				'provider' => 'openai',
				'models'   => array( 'gpt-5-nano' ),
				'weight'   => 20,
				'in'       => 700,
				'out'      => 20,
				'latency'  => 450,
				'pii'      => 71,
				'pii_types' => array( 'EMAIL', 'IP' ),
				'repeat'   => 38,
			),
			'theme:aurora'             => array(
				'label'    => 'Aurora',
				'provider' => 'anthropic',
				'models'   => array( 'claude-opus-5-5' ),
				'weight'   => 2,
				'in'       => 9000,
				'out'      => 2600,
				'latency'  => 14000,
				'pii'      => 0,
				'pii_types' => array( 'EMAIL' ),
				'repeat'   => 0,
			),
			'plugin:newsletter-studio' => array(
				'label'    => 'Newsletter Studio',
				'provider' => 'ollama',
				'models'   => array( 'llama-4-scout' ),
				'weight'   => 3,
				'in'       => 2400,
				'out'      => 900,
				'latency'  => 5200,
				'pii'      => 12,
				'pii_types' => array( 'EMAIL', 'TERM' ),
				'repeat'   => 4,
			),
		);
	}
}
