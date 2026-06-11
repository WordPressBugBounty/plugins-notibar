<?php
/**
 * Raw per-event tracking log.
 *
 * Storage: custom table `{prefix}notibar_events`, one row per interaction.
 * Complements EventCounter (aggregate JSON): counters serve the instant
 * Tracking table view; this log adds a time dimension for trend charts.
 *
 * Captured per event: bar_id, event_type (TINYINT enum), is_logged_in
 * (boolean flag — NOT a user identifier), created_at. No PII, no IP, no
 * external calls. wordpress.org-compliant (local storage only).
 *
 * Input validation: callers writing to this table MUST validate `bar_id`
 * against the canonical EventCounter::BAR_ID_REGEX before insert. The regex
 * has one home (EventCounter) — not duplicated here. The Phase 02 write path
 * runs that check before the dual-write.
 *
 * Install: dbDelta on activation + self-healing maybeInstall() on upgrade
 * (activation hook does not re-fire on auto-update). Version-stamped marker
 * re-runs dbDelta when SCHEMA_VERSION bumps.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.2
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class EventLog {

	/** @var string Unprefixed table name. */
	const TABLE = 'notibar_events';

	/** @var string Autoloaded marker option; holds the installed schema version. */
	const MARKER = 'notibar_events_v';

	/** @var string Bump to force dbDelta re-run on upgrade. */
	const SCHEMA_VERSION = '1';

	/**
	 * Event input => TINYINT enum stored in `event_type`. Whitelist.
	 * Keys mirror EventCounter::EVENT_MAP; values are the TINYINT stored
	 * in `event_type` (EventCounter stores string labels instead).
	 *
	 * @var array<string,int>
	 */
	const EVENT_MAP = [
		'click'   => 1,
		'dismiss' => 2,
		'engage'  => 3,
	];

	/**
	 * Fully-qualified table name (with $wpdb prefix).
	 */
	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the events table. Idempotent — dbDelta diffs against the
	 * live schema and ALTERs only when needed, so safe to call on every upgrade.
	 */
	public static function install(): void {
		global $wpdb;

		$table           = self::tableName();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase
		// `key`, one field per line. Do not reformat without testing.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  bar_id varchar(64) NOT NULL,
  event_type tinyint(3) unsigned NOT NULL,
  is_logged_in tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY bar_created (bar_id, created_at),
  KEY created (created_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Dev guard (install/upgrade only — not on the hot path): the event key
		// set MUST match EventCounter's whitelist. If a new event is added to one
		// map but not the other, track() would accept it (EventCounter validates)
		// yet insert() would silently drop it. Fail loud here instead.
		if ( array_keys( self::EVENT_MAP ) !== array_keys( EventCounter::EVENT_MAP ) ) {
			error_log( 'Notibar: EventLog::EVENT_MAP keys diverge from EventCounter::EVENT_MAP — raw event logging is incomplete.' );
		}

		update_option( self::MARKER, self::SCHEMA_VERSION, true );
	}

	/**
	 * Self-healing install hook — safe to call on every load.
	 *
	 * Version-stamped: re-runs install() when the stored marker differs from
	 * SCHEMA_VERSION (fresh install OR schema bump). Steady-state cost is one
	 * autoloaded option read.
	 */
	public static function maybeInstall(): void {
		if ( self::SCHEMA_VERSION === get_option( self::MARKER ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Append one raw event row. Best-effort: callers treat failure as non-fatal
	 * (the aggregate counter is the authoritative store for the existing UI).
	 *
	 * Caller MUST have validated $bar_id (EventCounter::BAR_ID_REGEX) and bar
	 * existence before calling. $event is whitelisted here via EVENT_MAP.
	 * Single prepared INSERT (no SELECT) to keep write amplification minimal.
	 *
	 * @param string $bar_id    Validated bar id.
	 * @param string $event     Event input key (click|dismiss|engage).
	 * @param bool   $logged_in Whether the visitor is authenticated.
	 * @return bool True when one row inserted.
	 */
	public static function insert( string $bar_id, string $event, bool $logged_in ): bool {
		$type = self::EVENT_MAP[ $event ] ?? 0;
		if ( 0 === $type ) {
			return false;
		}

		global $wpdb;

		// UTC timestamp so Phase 04 groups events on a single, stable basis.
		$inserted = $wpdb->insert(
			self::tableName(),
			[
				'bar_id'       => $bar_id,
				'event_type'   => $type,
				'is_logged_in' => $logged_in ? 1 : 0,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%d', '%s' ]
		);

		return (bool) $inserted;
	}

	/**
	 * Delete events older than $days. The `created` index (Phase 01) bounds the
	 * scan. Cutoff computed in UTC to match insert()'s UTC created_at.
	 *
	 * Single DELETE for now — fine while retention caps the table size. If a
	 * very large/legacy table ever reports lock contention, switch to a
	 * batched `LIMIT` delete loop (noted as a follow-up, not needed yet).
	 *
	 * @param int $days Retention window; rows strictly older are removed.
	 * @return int Rows deleted (0 if none / on error).
	 */
	public static function prune( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::tableName();

		// Table name comes from $wpdb->prefix (wp-config, trusted) — not user
		// input. The %i identifier placeholder needs WP 6.2+; this plugin
		// supports WP 4.0, so interpolate and suppress the static-analysis flag.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Daily grouped counts for charts. Read-only.
	 *
	 * $start / $end_exclusive are UTC 'Y-m-d H:i:s' bounds ($end_exclusive is
	 * exclusive — caller passes the day AFTER the requested `to` so the whole
	 * `to` date is included). Same UTC basis as insert()/prune(). Uses the
	 * (bar_id, created_at) index when a bar is given, else (created_at).
	 *
	 * @param string      $start         Inclusive lower bound, UTC datetime.
	 * @param string      $end_exclusive Exclusive upper bound, UTC datetime.
	 * @param string|null $bar_id        Optional bar filter (pre-validated).
	 * @return array<int,array{date:string,event:string,is_logged_in:int,count:int}>
	 */
	public static function timeseries( string $start, string $end_exclusive, ?string $bar_id = null ): array {
		global $wpdb;

		$table = self::tableName();
		$has_bar = ( null !== $bar_id && '' !== $bar_id );

		// Table name from $wpdb->prefix (trusted). %i needs WP 6.2+; plugin
		// supports WP 4.0, so interpolate + suppress. All values are prepared.
		if ( $has_bar ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT DATE(created_at) AS d, event_type, is_logged_in, COUNT(*) AS c
				 FROM {$table}
				 WHERE created_at >= %s AND created_at < %s AND bar_id = %s
				 GROUP BY d, event_type, is_logged_in
				 ORDER BY d ASC",
				$start,
				$end_exclusive,
				$bar_id
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT DATE(created_at) AS d, event_type, is_logged_in, COUNT(*) AS c
				 FROM {$table}
				 WHERE created_at >= %s AND created_at < %s
				 GROUP BY d, event_type, is_logged_in
				 ORDER BY d ASC",
				$start,
				$end_exclusive
			);
		}

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built with $wpdb->prepare(); the only interpolated token is the $wpdb->prefix table name (trusted, never user input).
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$series = [];
		foreach ( $rows as $row ) {
			$series[] = [
				'date'         => (string) $row['d'],
				'event'        => self::eventLabel( (int) $row['event_type'] ),
				'is_logged_in' => (int) $row['is_logged_in'],
				'count'        => (int) $row['c'],
			];
		}
		return $series;
	}

	/**
	 * Per-bar grouped counts over a window (for the comparison chart). Read-only.
	 * Groups by bar_id + event_type + is_logged_in (no date) across ALL bars.
	 * Same UTC bounds contract as timeseries(); $end_exclusive is exclusive.
	 *
	 * @param string $start         Inclusive lower bound, UTC datetime.
	 * @param string $end_exclusive Exclusive upper bound, UTC datetime.
	 * @return array<int,array{bar_id:string,event:string,is_logged_in:int,count:int}>
	 */
	public static function byBar( string $start, string $end_exclusive ): array {
		global $wpdb;

		$table = self::tableName();

		// Table name from $wpdb->prefix (trusted); values are prepared. Uses the
		// created index for the range scan, retention-bounded.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT bar_id, event_type, is_logged_in, COUNT(*) AS c
			 FROM {$table}
			 WHERE created_at >= %s AND created_at < %s
			 GROUP BY bar_id, event_type, is_logged_in
			 ORDER BY bar_id ASC",
			$start,
			$end_exclusive
		);

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built with $wpdb->prepare(); the only interpolated token is the $wpdb->prefix table name (trusted, never user input).
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'bar_id'       => (string) $row['bar_id'],
				'event'        => self::eventLabel( (int) $row['event_type'] ),
				'is_logged_in' => (int) $row['is_logged_in'],
				'count'        => (int) $row['c'],
			];
		}
		return $out;
	}

	/**
	 * Reverse EVENT_MAP: TINYINT enum => readable label. Unknown => 'unknown'
	 * so consumers never receive a raw magic number.
	 */
	public static function eventLabel( int $type ): string {
		static $labels = null;
		if ( null === $labels ) {
			$labels = array_flip( self::EVENT_MAP );
		}
		return $labels[ $type ] ?? 'unknown';
	}
}
