<?php
/**
 * Tracking settings store.
 *
 * Holds operator-tunable tracking options in a single small autoloaded
 * `notibar_tracking` option. Currently just retention_days — how long raw
 * events in {prefix}notibar_events are kept before the daily prune cron
 * (TrackingCron) deletes them.
 *
 * setRetentionDays() is the sanitize chokepoint: a future Settings UI must
 * route writes through it (with manage_options + nonce at the REST/form layer).
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.2
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class TrackingSettings {

	/** @var string Option name (autoloaded; tiny row). */
	const OPTION = 'notibar_tracking';

	/** @var int Default retention window in days. */
	const DEFAULT_RETENTION = 90;

	/** @var int Clamp bounds for retention_days. */
	const MIN_RETENTION = 1;
	const MAX_RETENTION = 3650;

	/**
	 * Retention window in days, clamped to [MIN, MAX]. Falls back to default
	 * when unset or invalid.
	 */
	public static function getRetentionDays(): int {
		$opt = get_option( self::OPTION );
		$raw = is_array( $opt ) && isset( $opt['retention_days'] )
			? $opt['retention_days']
			: self::DEFAULT_RETENTION;
		return self::sanitizeDays( $raw );
	}

	/**
	 * Persist retention_days (sanitized + clamped). Merges into the option so
	 * future keys are preserved.
	 *
	 * @return int The clamped value actually stored.
	 */
	public static function setRetentionDays( $days ): int {
		$clamped = self::sanitizeDays( $days );
		$opt     = get_option( self::OPTION );
		if ( ! is_array( $opt ) ) {
			$opt = [];
		}
		$opt['retention_days'] = $clamped;
		// autoload=yes (unlike EventCounter's counters option which is autoload=no):
		// this row is a single int read on every request by the cron-wiring path,
		// so keeping it autoloaded is cheaper than a separate query.
		update_option( self::OPTION, $opt, true );
		return $clamped;
	}

	/**
	 * Coerce to int. Values below MIN_RETENTION (0, negative, garbage) are
	 * treated as unset and fall back to DEFAULT_RETENTION rather than clamping
	 * to 1 — avoids an accidental near-zero retention nuking all history.
	 * Values above MAX_RETENTION clamp down to MAX_RETENTION.
	 */
	public static function sanitizeDays( $days ): int {
		$n = (int) $days;
		if ( $n < self::MIN_RETENTION ) {
			return self::DEFAULT_RETENTION;
		}
		return min( $n, self::MAX_RETENTION );
	}
}
