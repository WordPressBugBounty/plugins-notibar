<?php
/**
 * CountdownResolver — resolve fixed-date countdowns to an absolute epoch (Pro).
 *
 * Runs right before bars are inlined into the page. For each enabled `date`-type
 * countdown it converts the stored `endAt` datetime-local string — interpreted
 * in the WordPress site timezone — into an absolute Unix-ms epoch and writes it
 * to `countdown.endEpoch`. The frontend ticker counts to that instant, so every
 * visitor sees the same remaining time regardless of their browser timezone.
 *
 * Evergreen countdowns are left untouched (the client seeds a per-visitor window
 * from `countdown.duration`). Pro-only: in Lite nothing renders a countdown, so
 * the whole pass is skipped via the NJT_NOFI_IS_PRO gate. The added `endEpoch`
 * lives only on the inlined copy of the bars — it is never persisted to the DB.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.2.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class CountdownResolver
 */
class CountdownResolver {

	/**
	 * Resolve fixed-date countdown targets to absolute epochs, in place.
	 *
	 * @param  array $bars Bars (after dynamic-content resolution).
	 * @return array Bars with `countdown.endEpoch` set on enabled date timers.
	 */
	public static function apply( array $bars ): array {
		if ( ! ( defined( 'NJT_NOFI_IS_PRO' ) && NJT_NOFI_IS_PRO ) ) {
			return $bars;
		}

		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		foreach ( $bars as &$bar ) {
			if ( ! is_array( $bar ) || empty( $bar['countdown'] ) || ! is_array( $bar['countdown'] ) ) {
				continue;
			}

			$cd = $bar['countdown'];
			if ( empty( $cd['enabled'] ) ) {
				continue;
			}
			$type = isset( $cd['type'] ) ? $cd['type'] : 'date';

			if ( 'date' === $type ) {
				// Fixed date — interpret the countdown's own datetime-local.
				$epoch = self::dateTimeLocalEpoch( isset( $cd['endAt'] ) ? (string) $cd['endAt'] : '', $tz );
				if ( $epoch > 0 ) {
					$bar['countdown']['endEpoch'] = $epoch;
				}
			} elseif ( 'schedule' === $type ) {
				// Count to the soonest moment the bar's own schedule closes it
				// (End date, daily-window end, or a day-of-week boundary).
				$schedule = isset( $bar['schedule'] ) && is_array( $bar['schedule'] ) ? $bar['schedule'] : [];

				// Visitor-local schedules ("use visitor's local time") are resolved
				// CLIENT-SIDE: only the browser knows the visitor's clock + weekday,
				// and future site-local times can't be derived there. Leave the type
				// as 'schedule' so the frontend computes it. Site-TZ schedules are
				// resolved here (real timezone DB) and normalized to a fixed 'date'
				// instant, so the renderer/ticker need no schedule awareness.
				if ( empty( $schedule['useClientTime'] ) ) {
					$bar['countdown']['type'] = 'date';
					$epoch = self::scheduleCloseEpoch( $schedule, $tz );
					if ( $epoch > 0 ) {
						$bar['countdown']['endEpoch'] = $epoch;
					}
				}
			}
			// 'evergreen' → untouched (the client seeds the window from duration).
		}
		unset( $bar );

		return $bars;
	}

	/**
	 * Convert a datetime-local string, interpreted in the site timezone, to a
	 * Unix-ms epoch. Returns 0 when empty or an invalid calendar date (which
	 * createFromFormat would otherwise silently roll forward).
	 *
	 * @param  string        $value datetime-local "YYYY-MM-DDTHH:MM".
	 * @param  \DateTimeZone $tz    Site timezone.
	 * @return int Epoch in milliseconds, or 0.
	 */
	private static function dateTimeLocalEpoch( string $value, \DateTimeZone $tz ): int {
		if ( '' === $value ) {
			return 0;
		}
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, $tz );
		if ( false === $dt || $dt->format( 'Y-m-d\TH:i' ) !== $value ) {
			return 0;
		}
		return $dt->getTimestamp() * 1000;
	}

	/**
	 * Soonest upcoming moment the bar's schedule will close it, in the site
	 * timezone. Mirrors NotificationBarHandle::passesSchedule: the bar is open
	 * while it is inside the date range, on an allowed weekday, and inside the
	 * daily window. The close is the first future boundary — End date, a daily-
	 * window end, or a day-of-week midnight — where it flips closed.
	 *
	 * Returns 0 when the schedule is disabled, the bar is not open now, or there
	 * is no close within a 15-day horizon (e.g. an always-on schedule).
	 *
	 * @param  array         $schedule Bar schedule sub-object.
	 * @param  \DateTimeZone $tz       Site timezone.
	 * @return int Epoch in milliseconds, or 0.
	 */
	private static function scheduleCloseEpoch( array $schedule, \DateTimeZone $tz ): int {
		if ( empty( $schedule['enabled'] ) ) {
			return 0;
		}

		$start_ts = self::localToTs( isset( $schedule['startAt'] ) ? (string) $schedule['startAt'] : '', $tz );
		$end_ts   = self::localToTs( isset( $schedule['endAt'] ) ? (string) $schedule['endAt'] : '', $tz );
		$dow      = isset( $schedule['daysOfWeek'] ) && is_array( $schedule['daysOfWeek'] )
			? array_map( 'intval', $schedule['daysOfWeek'] ) : [];
		$dw       = isset( $schedule['dailyWindow'] ) && is_array( $schedule['dailyWindow'] ) ? $schedule['dailyWindow'] : [];
		$dw_start = isset( $dw['start'] ) ? (string) $dw['start'] : '';
		$dw_end   = isset( $dw['end'] ) ? (string) $dw['end'] : '';
		$dw_on    = ! empty( $dw['enabled'] ) && ( '' !== $dw_start || '' !== $dw_end );

		// True when the bar is visible at the given instant (site-local).
		$is_open = static function ( \DateTimeImmutable $dt ) use ( $start_ts, $end_ts, $dow, $dw_on, $dw_start, $dw_end ) {
			$t = $dt->getTimestamp();
			if ( null !== $start_ts && $t < $start_ts ) {
				return false;
			}
			if ( null !== $end_ts && $t >= $end_ts ) {
				return false;
			}
			if ( ! empty( $dow ) && ! in_array( (int) $dt->format( 'w' ), $dow, true ) ) {
				return false;
			}
			if ( $dw_on && ! self::inDailyWindow( $dt->format( 'H:i' ), $dw_start, $dw_end ) ) {
				return false;
			}
			return true;
		};

		$now = new \DateTimeImmutable( 'now', $tz );
		if ( ! $is_open( $now ) ) {
			return 0; // Not visible now — nothing to count to.
		}

		$horizon = $now->getTimestamp() + 15 * 86400;
		$cursor  = $now;

		// Walk the boundaries where the state can flip closed; the first such
		// boundary that is actually closed is the soonest close.
		for ( $i = 0; $i < 200; $i++ ) {
			$cursor_ts  = $cursor->getTimestamp();
			$candidates = [];
			if ( null !== $end_ts && $end_ts > $cursor_ts ) {
				$candidates[] = $end_ts;
			}
			// Next site-local midnight (day-of-week boundary).
			$candidates[] = $cursor->modify( 'tomorrow midnight' )->getTimestamp();
			// Next daily-window end occurrence.
			if ( $dw_on && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $dw_end ) ) {
				$candidates[] = self::nextHhmm( $cursor, $dw_end );
			}
			$candidates = array_filter( $candidates, static function ( $t ) use ( $cursor_ts ) {
				return $t > $cursor_ts;
			} );
			if ( empty( $candidates ) ) {
				return 0;
			}
			$next = min( $candidates );
			if ( $next > $horizon ) {
				return 0;
			}
			$next_dt = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $tz );
			if ( ! $is_open( $next_dt ) ) {
				return $next * 1000;
			}
			$cursor = $next_dt;
		}

		return 0;
	}

	/**
	 * Parse a datetime-local string in the site timezone to a Unix-seconds
	 * timestamp, or null when empty/invalid.
	 *
	 * @param  string        $value datetime-local "YYYY-MM-DDTHH:MM".
	 * @param  \DateTimeZone $tz    Site timezone.
	 * @return int|null
	 */
	private static function localToTs( string $value, \DateTimeZone $tz ) {
		$ms = self::dateTimeLocalEpoch( $value, $tz );
		return $ms > 0 ? (int) ( $ms / 1000 ) : null;
	}

	/**
	 * Next occurrence (Unix seconds) of an "HH:MM" wall-clock time strictly after
	 * the cursor, in the cursor's timezone.
	 *
	 * @param  \DateTimeImmutable $from Cursor.
	 * @param  string             $hhmm "HH:MM".
	 * @return int
	 */
	private static function nextHhmm( \DateTimeImmutable $from, string $hhmm ): int {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
		$occ = $from->setTime( $h, $m, 0 );
		if ( $occ->getTimestamp() <= $from->getTimestamp() ) {
			$occ = $occ->modify( '+1 day' );
		}
		return $occ->getTimestamp();
	}

	/**
	 * Whether an "HH:MM" time is inside [start, end) — empty bounds unbounded,
	 * midnight wrap supported. Mirrors NotificationBarHandle::inDailyWindow and
	 * filter-bars.js#inDailyWindow.
	 *
	 * @param  string $now   "HH:MM".
	 * @param  string $start "HH:MM" or empty.
	 * @param  string $end   "HH:MM" or empty.
	 * @return bool
	 */
	private static function inDailyWindow( string $now, string $start, string $end ): bool {
		if ( '' === $start && '' === $end ) {
			return true;
		}
		if ( '' === $start ) {
			return $now < $end;
		}
		if ( '' === $end ) {
			return $now >= $start;
		}
		if ( $start <= $end ) {
			return $now >= $start && $now < $end;
		}
		return $now >= $start || $now < $end;
	}

	/**
	 * Localized countdown labels for the active language.
	 *
	 * The client renderer (render-bar.js) is vanilla JS with no i18n, so the unit
	 * labels and accessible name are translated here — under the `notibar`
	 * textdomain, which WPML/Polylang switch per request — and inlined into
	 * window.njtNotibarData for the renderer to read (it falls back to English
	 * when absent). `cdUnits` = full labels (boxes/flip/circular); `cdUnitsShort`
	 * = compact labels (text style).
	 *
	 * @return array{cdUnits:array,cdUnitsShort:array,cdAria:string}
	 */
	public static function labels(): array {
		return [
			'cdUnits'      => [
				'days'    => __( 'Days', 'notibar' ),
				'hours'   => __( 'Hours', 'notibar' ),
				'minutes' => __( 'Minutes', 'notibar' ),
				'seconds' => __( 'Seconds', 'notibar' ),
			],
			'cdUnitsShort' => [
				'days'    => __( 'days', 'notibar' ),
				'hours'   => __( 'hrs', 'notibar' ),
				'minutes' => __( 'mins', 'notibar' ),
				'seconds' => __( 'secs', 'notibar' ),
			],
			'cdAria'       => __( 'Countdown timer', 'notibar' ),
		];
	}
}
