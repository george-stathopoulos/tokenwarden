<?php
/**
 * Budget alert emails.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emails the site owner once per month when a budget passes the alert threshold and when it is reached.
 */
final class Tokenwarden_Alerts {

	/**
	 * Hook into the ledger.
	 */
	const SPIKE_HOOK = 'tokenwarden_spike_check';

	public static function init() {
		add_action( 'tokenwarden_recorded', array( __CLASS__, 'check' ), 10, 2 );
		add_action( self::SPIKE_HOOK, array( __CLASS__, 'check_spikes' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::SPIKE_HOOK ) ) {
					wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::SPIKE_HOOK );
				}
			}
		);
	}

	/**
	 * Hourly: email once a day per source and kind when its activity is far above its normal level.
	 */
	public static function check_spikes() {
		$alerts = Tokenwarden_Settings::get( 'alerts' );
		if ( empty( $alerts['enabled'] ) || ! is_email( $alerts['email'] ) ) {
			return;
		}
		$sent  = (array) get_option( 'tokenwarden_alerts_spike', array() );
		$today = current_time( 'Y-m-d' );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		foreach ( Tokenwarden_Stats::spikes() as $spike ) {
			$key = $spike['source'] . '|' . $spike['kind'];
			if ( ( $sent[ $key ] ?? '' ) === $today ) {
				continue;
			}
			$sent[ $key ] = $today;
			if ( 'calls' === $spike['kind'] ) {
				/* translators: 1: site name, 2: plugin or theme name. */
				$subject = sprintf( __( '[%1$s] Unusual AI activity: %2$s', 'tokenwarden' ), $site, $spike['label'] );
				/* translators: 1: plugin or theme name, 2: calls in the last hour, 3: usual calls per hour. */
				$message = sprintf( __( "%1\$s made %2\$d AI calls in the last hour. It usually makes about %3\$s an hour.\n\nThis can be a busy moment, or a loop, bug or wave of spam. Tokenwarden is not blocking it. To cap it, set an hourly call limit for it on the Sources page.", 'tokenwarden' ), $spike['label'], $spike['now'], number_format_i18n( $spike['normal'], 1 ) );
			} else {
				/* translators: 1: site name, 2: plugin or theme name. */
				$subject = sprintf( __( '[%1$s] Unusual AI spending: %2$s', 'tokenwarden' ), $site, $spike['label'] );
				/* translators: 1: plugin or theme name, 2: spend in the last 24 hours, 3: usual daily spend. */
				$message = sprintf( __( "%1\$s spent %2\$s on AI in the last 24 hours. It usually spends about %3\$s a day.\n\nTokenwarden is not blocking it. To cap it, set a monthly budget or an hourly call limit for it on the Sources page.", 'tokenwarden' ), $spike['label'], self::money( $spike['now'] ), self::money( $spike['normal'] ) );
			}
			$message .= "\n\n" . admin_url( 'admin.php?page=tokenwarden#/sources' );
			wp_mail( $alerts['email'], $subject, $message );
		}
		update_option( 'tokenwarden_alerts_spike', $sent, false );
	}

	/**
	 * Check the budgets touched by a newly recorded call.
	 *
	 * @param int   $id  Row id.
	 * @param array $row Row values.
	 */
	public static function check( $id, $row ) {
		if ( (float) $row['cost'] <= 0 ) {
			return;
		}
		$alerts = Tokenwarden_Settings::get( 'alerts' );
		if ( empty( $alerts['enabled'] ) || ! is_email( $alerts['email'] ) ) {
			return;
		}

		$month  = substr( $row['created_at'], 0, 7 );
		$budget = (float) Tokenwarden_Settings::source( $row['source'] )['budget'];
		if ( $budget > 0 ) {
			self::maybe_send( $row['source'], Tokenwarden_Ledger::month_spend( $row['source'], $month ), $budget, $month, (int) $alerts['threshold'], $alerts['email'] );
		}

		$global = (float) Tokenwarden_Settings::get( 'global_budget' );
		if ( $global > 0 ) {
			self::maybe_send( '__total', Tokenwarden_Ledger::month_spend( '__total', $month ), $global, $month, (int) $alerts['threshold'], $alerts['email'] );
		}
	}

	/**
	 * Send the threshold or limit email when it has not been sent this month.
	 *
	 * @param string $source    Source id or `__total`.
	 * @param float  $spend     Month-to-date spend.
	 * @param float  $budget    Budget.
	 * @param string $month     Month as `YYYY-MM`.
	 * @param int    $threshold Alert threshold percent.
	 * @param string $email     Recipient.
	 */
	private static function maybe_send( $source, $spend, $budget, $month, $threshold, $email ) {
		$pct = $spend / $budget * 100;
		if ( $pct >= 100 ) {
			$level = 'limit';
		} elseif ( $pct >= $threshold ) {
			$level = 'threshold';
		} else {
			return;
		}

		$option = 'tokenwarden_alerts_' . str_replace( '-', '', $month );
		$sent   = (array) get_option( $option, array() );
		$key    = $source . '|' . $level;
		if ( isset( $sent[ $key ] ) ) {
			return;
		}
		$sent[ $key ] = time();
		update_option( $option, $sent, false );

		$name = '__total' === $source ? __( 'Your whole site', 'tokenwarden' ) : Tokenwarden_Attribution::label( $source );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( 'limit' === $level ) {
			/* translators: 1: site name, 2: plugin or theme name. */
			$subject = sprintf( __( '[%1$s] AI budget reached: %2$s', 'tokenwarden' ), $site, $name );
			/* translators: 1: plugin or theme name, 2: amount spent, 3: monthly budget. */
			$message = sprintf( __( "%1\$s has spent %2\$s of its %3\$s monthly AI budget.\n\nTokenwarden is now blocking its AI requests until next month, or until you raise the budget.", 'tokenwarden' ), $name, self::money( $spend ), self::money( $budget ) );
		} else {
			/* translators: 1: site name, 2: plugin or theme name, 3: percent used. */
			$subject = sprintf( __( '[%1$s] %2$s has used %3$d%% of its AI budget', 'tokenwarden' ), $site, $name, (int) $pct );
			/* translators: 1: plugin or theme name, 2: amount spent, 3: monthly budget. */
			$message = sprintf( __( "%1\$s has spent %2\$s of its %3\$s monthly AI budget.\n\nRequests will be blocked when it reaches 100%%.", 'tokenwarden' ), $name, self::money( $spend ), self::money( $budget ) );
		}

		$message .= "\n\n" . admin_url( 'admin.php?page=tokenwarden#/sources' );
		wp_mail( $email, $subject, $message );
	}

	/**
	 * Format a USD amount.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	/**
	 * Email once a day per source when it hits its hourly call limit: often a sign of a loop.
	 *
	 * @param string $source Source id.
	 * @param int    $limit  Calls per hour.
	 */
	public static function rate_limited( $source, $limit ) {
		$alerts = Tokenwarden_Settings::get( 'alerts' );
		if ( empty( $alerts['enabled'] ) || ! is_email( $alerts['email'] ) || Tokenwarden_Demo::active() ) {
			return;
		}
		$sent  = (array) get_option( 'tokenwarden_alerts_rate', array() );
		$today = current_time( 'Y-m-d' );
		if ( ( $sent[ $source ] ?? '' ) === $today ) {
			return;
		}
		$sent[ $source ] = $today;
		update_option( 'tokenwarden_alerts_rate', $sent, false );

		$name = Tokenwarden_Attribution::label( $source );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: plugin or theme name. */
		$subject = sprintf( __( '[%1$s] %2$s hit its hourly AI limit', 'tokenwarden' ), $site, $name );
		/* translators: 1: plugin or theme name, 2: calls per hour. */
		$message = sprintf( __( "%1\$s made %2\$d AI calls in the last hour, its limit. Tokenwarden is blocking its further calls until the hourly count drops.\n\nThis can mean a loop, a bug or a wave of spam. If the volume is expected, raise the limit for it on the Sources page.", 'tokenwarden' ), $name, $limit );
		$message .= "\n\n" . admin_url( 'admin.php?page=tokenwarden#/sources' );
		wp_mail( $alerts['email'], $subject, $message );
	}

	private static function money( $amount ) {
		return '$' . number_format_i18n( $amount, 2 );
	}

	/**
	 * Delete sent-alert markers (uninstall).
	 */
	public static function uninstall() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'tokenwarden_alerts_' ) . '%' ) );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		delete_option( 'tokenwarden_alerts_rate' );
		delete_option( 'tokenwarden_alerts_spike' );
		wp_clear_scheduled_hook( self::SPIKE_HOOK );
	}
}
