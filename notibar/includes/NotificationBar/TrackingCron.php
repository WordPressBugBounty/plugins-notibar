<?php
/**
 * Daily retention prune for the raw event log.
 *
 * Raw events ({prefix}notibar_events) only grow; this cron is the load-bearing
 * guardrail that keeps the table bounded. It deletes events older than the
 * configured retention window (TrackingSettings) once per day. Aggregate
 * counters are NEVER pruned — lifetime totals stay intact.
 *
 * Wiring:
 *   - registerHook(): bind the action callback on EVERY load (a scheduled
 *     event with no registered callback does nothing).
 *   - schedule(): idempotently schedule the daily event (on activation, and
 *     self-healing on upgrade since activation does not re-fire on auto-update).
 *   - clear(): unschedule on deactivation.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.2
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class TrackingCron {

	/** @var string Cron hook name. */
	const HOOK = 'njt_nofi_prune_events';

	/**
	 * Bind the prune callback to the cron hook. Must run on every load.
	 */
	public static function registerHook(): void {
		add_action( self::HOOK, [ self::class, 'runPrune' ] );
	}

	/**
	 * Schedule the daily prune if not already scheduled. Idempotent — safe to
	 * call on activation AND on every load (upgrade self-heal). First run is
	 * offset an hour out to avoid piling onto activation-time work.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule the daily prune (deactivation).
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback: prune events past the retention window. Self-heals the
	 * table first so a missing table never fatals (maybeInstall is cheap when
	 * the marker already matches).
	 */
	public static function runPrune(): void {
		global $wpdb;

		EventLog::maybeInstall();
		$deleted = EventLog::prune( TrackingSettings::getRetentionDays() );

		// prune() returns 0 both for "nothing to delete" and on DB error, so
		// disambiguate via last_error — otherwise a failing prune looks like a
		// successful no-op and the table keeps growing unnoticed.
		if ( 0 === $deleted && ! empty( $wpdb->last_error ) ) {
			error_log( 'Notibar: event prune failed — ' . $wpdb->last_error );
		}
	}
}
