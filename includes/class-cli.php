<?php
/**
 * WP-CLI commands.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and manage Tokenwarden from the command line.
 */
final class Tokenwarden_CLI {



	/**
	 * Show month-to-date spend per source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenwarden status
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$items = array();
		foreach ( Tokenwarden_Ledger::month_totals() as $source => $spend ) {
			if ( '__total' === $source ) {
				continue;
			}
			$policy  = Tokenwarden_Settings::source( $source );
			$items[] = array(
				'source' => $source,
				'name'   => Tokenwarden_Attribution::label( $source ),
				'spend'  => number_format( $spend, 4 ),
				'budget' => $policy['budget'] ? number_format( $policy['budget'], 2 ) : '-',
				'paused' => $policy['paused'] ? 'yes' : 'no',
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'source', 'name', 'spend', 'budget', 'paused' ) );
		WP_CLI::log( sprintf( 'Month to date: $%s', number_format( Tokenwarden_Ledger::month_spend(), 4 ) ) );
	}

	/**
	 * Fill the log with realistic sample traffic so the dashboard can be explored.
	 *
	 * Sample sources are fictional. By default this writes to the live log (for development and
	 * screenshots); remove it with `wp tokenwarden reset`. Site owners should use the dashboard's
	 * "Demo data" switch instead, which keeps sample data in a separate sandbox.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Days of history.
	 * ---
	 * default: 45
	 * ---
	 *
	 * [--per-day=<count>]
	 * : Average requests per day at the end of the period.
	 * ---
	 * default: 140
	 * ---
	 *
	 * [--seed=<seed>]
	 * : Random seed for repeatable data.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--demo]
	 * : Write to the demo sandbox (what the dashboard's "Demo data" switch shows) instead of the live log.
	 *
	 * [--cache-days=<days>]
	 * : Simulate response caching for the last N days: repeated prompts from
	 * Helpdesk Bot and Form Guard are marked as answered from the cache.
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tokenwarden seed --days=60
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function seed( $args, $assoc_args ) {
		$days     = max( 1, (int) $assoc_args['days'] );
		$progress = WP_CLI\Utils\make_progress_bar( 'Generating sample traffic', $days );
		$run      = static function () use ( $assoc_args, $days, $progress ) {
			Tokenwarden_Ledger::maybe_install();
			return ( new Tokenwarden_Sample() )->generate(
				array(
					'days'       => $days,
					'per_day'    => (int) $assoc_args['per-day'],
					'seed'       => (int) $assoc_args['seed'],
					'cache_days' => (int) $assoc_args['cache-days'],
				),
				array( $progress, 'tick' )
			);
		};
		$inserted = ! empty( $assoc_args['demo'] ) ? Tokenwarden_Demo::run( $run ) : $run();
		$progress->finish();
		WP_CLI::success( sprintf( 'Added %d sample requests over %d days%s.', $inserted, $days, ! empty( $assoc_args['demo'] ) ? ' to the demo sandbox' : '' ) );
	}

	/**
	 * Delete every logged request and cached total. Settings are kept.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete every logged AI request?', $assoc_args );
		Tokenwarden_Ledger::clear();
		WP_CLI::success( 'Request log cleared.' );
	}



}
