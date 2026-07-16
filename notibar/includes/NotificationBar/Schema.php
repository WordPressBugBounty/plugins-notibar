<?php
/**
 * Schema — default values and sanitize callbacks for Notibar v3 JSON settings.
 *
 * MIRROR: src/customizer-app/utils/defaults.js — keep in sync.
 *
 * Two theme_mod keys own the v3 state:
 *   njt_nofi_bars   — JSON-encoded array of bar objects.
 *   njt_nofi_global — JSON-encoded global config object.
 *
 * Sanitize methods are registered as Customizer setting callbacks in phase-03.
 * Per-field logic lives in SchemaSanitizers trait to keep this file under 200 LOC.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/SchemaSanitizers.php';

/**
 * Class Schema
 *
 * Exposes static methods for default values and top-level sanitization.
 * All per-field sanitize helpers live in SchemaSanitizers trait.
 */
class Schema {

	use SchemaSanitizers;

	// ------------------------------------------------------------------
	// Enum allowed values (referenced by SchemaSanitizers trait too)
	// ------------------------------------------------------------------

	const ALLOWED_LAYOUT    = [ 'centered', 'text-left', 'three-zone', 'hero', 'split', 'content-left', 'content-right' ];

	// Maps the removed `alignment` enum to its closest `layout` so bars saved
	// before the layout picker keep their original look on first re-sanitize.
	const LEGACY_ALIGNMENT_LAYOUT = [
		'center'       => 'centered',
		'left'         => 'content-left',
		'right'        => 'content-right',
		'space-around' => 'three-zone',
	];
	const ALLOWED_POSITION  = [ 'fixed', 'absolute' ];
	const ALLOWED_PLACEMENT = [ 'top', 'bottom' ];
	const ALLOWED_DEVICES   = [ 'desktop', 'mobile' ];
	const ALLOWED_LOGIC     = [ 'all', 'none', 'include', 'exclude' ];
	const ALLOWED_CLOSE_BTN = [ 'close', 'toggle', 'disable' ];
	const ALLOWED_DISP_MODE     = [ 'single', 'rotation', 'stack' ];
	const ALLOWED_ROTATION_ORDER = [ 'sequential', 'random' ];
	const ALLOWED_AUDIENCE      = [ 'all', 'loggedin', 'loggedout', 'roles', 'users' ];
	const ALLOWED_COUNTRY_LOGIC = [ 'all', 'include', 'exclude' ];
	// CTA button animation presets (Pro). Token == CSS class suffix == UI value.
	const ALLOWED_BTN_ATTENTION = [ 'none', 'wobble', 'shake', 'bounce', 'pulse', 'swing', 'jello', 'tada', 'rubber-band', 'heartbeat', 'flash', 'blink', 'vibrate', 'pop', 'bounce-in' ];
	const ALLOWED_BTN_HOVER     = [ 'none', 'grow', 'shrink', 'lift', 'glow', 'press', 'shadow', 'color-shift', 'slide-fill' ];
	// Display trigger types (Pro). Defers bar reveal until a runtime condition fires.
	// none = show immediately; scroll = after N% scrolled; time = after N seconds; click = after N document clicks.
	const ALLOWED_TRIGGER_TYPE  = [ 'none', 'scroll', 'time', 'click' ];
	// Countdown timer (Pro). type: date = count to a fixed instant; evergreen = per-visitor duration window.
	// ui = visual style; unit = which time units the timer displays. Tokens == CSS suffixes == UI values.
	const ALLOWED_CD_TYPE = [ 'date', 'evergreen', 'schedule' ];
	const ALLOWED_CD_UI   = [ 'boxes', 'flip', 'circular', 'text' ];
	const ALLOWED_CD_UNIT = [ 'days', 'hours', 'minutes', 'seconds' ];

	// ------------------------------------------------------------------
	// Default values
	// ------------------------------------------------------------------

	/**
	 * Return one default bar array (used for clean installs and migration base).
	 *
	 * @return array
	 */
	public static function defaultBar(): array {
		return [
			'id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallbackUuid(),
			'name'    => 'My notification bar',
			'enabled' => true,
			'order'   => 0,
			'content' => [
				'text'           => 'This is default text for notification bar',
				'textMobile'     => 'This is default text for notification bar',
				'mobileSeparate' => false,
				'button'         => self::defaultButton(),
				'buttonMobile'   => self::defaultButton(),
			],
			'style' => [
				'bgColor'      => '#9af4cf',
				'textColor'    => '#1919cf',
				'btnBgColor'   => '#1919cf',
				'btnTextColor' => '#ffffff',
				'fontSize'     => 15,
				'layout'       => 'centered',
				'contentWidth' => 900,
				'positionType' => 'fixed',
				'placement'    => 'top',
				// Overall bar opacity, percent (10–100). Fades the whole bar via CSS
				// opacity on the un-animated container. MIRROR: defaults.js style.opacity.
				'opacity'      => 100,
				// Snapshot of the colour preset the user last applied to this
				// bar, or null. Drives the "reset to preset" behaviour of the
				// per-colour Reset buttons. Shape: { bg, text, btnBg, btnText, name? }.
				'activePreset' => null,
			],
			'display' => [
				'devices'   => [ 'desktop', 'mobile' ],
				'pageLogic' => 'all',
				'pageIds'   => [],
				'postLogic' => 'all',
				'postIds'   => [],
				'cptTypes'  => [],
				'cptLogic'  => 'all',
				'cptIds'    => [],
				'audience'  => 'all',
				'roles'     => [],
				'userIds'   => [],
				// Country targeting (Pro). countryLogic: all|include|exclude.
				// countries: ISO 3166-1 alpha-2 codes. Default = no restriction.
				'countryLogic' => 'all',
				'countries'    => [],
			],
			'behavior' => [
				'hideCloseButton' => 'close',
				'reopenAfterDays' => 1,
				// Display trigger (Pro). Defers reveal until a runtime condition
				// fires. value meaning per type: scroll=% (1–100), time=seconds
				// (0–3600), click=count (1–100); none ignores value.
				'trigger'         => [ 'type' => 'none', 'value' => 0 ],
			],
			'schedule' => self::defaultSchedule(),
			// Countdown timer (Pro). Disabled by default; render + ticker are
			// Pro-only. MIRROR: defaults.js DEFAULT_BAR.countdown.
			'countdown' => self::defaultCountdown(),
		];
	}

	/**
	 * Return the default per-bar countdown timer config (Pro).
	 *
	 * type:     'date' counts down to a fixed instant (endAt, resolved in site TZ
	 *           to an absolute epoch at render); 'evergreen' counts down a
	 *           per-visitor duration window persisted client-side.
	 * endAt:    "YYYY-MM-DDTHH:MM" datetime-local (type = date). Empty = inert.
	 * duration: total seconds for the evergreen window (type = evergreen).
	 * ui:       visual style — boxes | flip | circular.
	 * units:    which time units to display, canonical order days..seconds.
	 *
	 * @return array
	 */
	public static function defaultCountdown(): array {
		return [
			'enabled'    => false,
			'type'       => 'date',
			'endAt'      => '',
			'duration'   => 0,
			'ui'         => 'boxes',
			'units'      => [ 'days', 'hours', 'minutes', 'seconds' ],
			// When false (default), leading units that are zero at display time
			// are hidden (e.g. "00 days"). When true, all selected units show.
			'showAllUnits' => false,
			// Bumped by the "Reset visitors' timers" admin action; a change
			// re-seeds every visitor's evergreen window on their next view.
			'resetToken' => 0,
		];
	}

	/**
	 * Return the default per-bar schedule.
	 *
	 * Three independent filters layered when `enabled = true`:
	 *   - Date range: startAt / endAt (datetime-local strings, evaluated in
	 *     site timezone by default; in visitor browser-local time when
	 *     useClientTime = true).
	 *   - Daily window: dailyWindow.start/end ("HH:MM"); window may wrap midnight.
	 *   - Day of week: daysOfWeek array, 0=Sun..6=Sat. Default = all 7 days.
	 *
	 * useClientTime toggle: when true, PHP schedule gate is skipped and all
	 * four fields above are evaluated client-side against the visitor's
	 * browser clock instead of the WP site timezone.
	 *
	 * @return array
	 */
	public static function defaultSchedule(): array {
		return [
			'enabled'       => false,
			'useClientTime' => false,
			'startAt'       => '',
			'endAt'         => '',
			'dailyWindow'   => [
				'enabled' => false,
				'start'   => '',
				'end'     => '',
			],
			'daysOfWeek'    => [ 0, 1, 2, 3, 4, 5, 6 ],
		];
	}

	/**
	 * Return the default global config array.
	 *
	 * @return array
	 */
	public static function defaultGlobal(): array {
		return [
			'displayMode'             => 'single',
			'rotationIntervalSeconds' => 5,
			'rotationPauseOnHover'    => true,
			'rotationOrder'           => 'sequential',
			'rotationShowArrows'      => true,
			'stackPositionType'       => 'fixed',
		];
	}

	// ------------------------------------------------------------------
	// Public sanitize entrypoints (Customizer setting sanitize_callback)
	// ------------------------------------------------------------------

	/**
	 * Sanitize the njt_nofi_bars JSON string.
	 *
	 * Malformed or non-array JSON → returns '[]'.
	 * Each bar element passes through per-field validation (SchemaSanitizers).
	 *
	 * @param  string $json Raw JSON from Customizer or DB.
	 * @return string       Sanitized JSON string.
	 */
	public static function sanitizeBars( string $json ): string {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return '[]';
		}

		$sanitized = [];
		foreach ( $decoded as $bar ) {
			if ( is_array( $bar ) ) {
				$sanitized[] = self::sanitizeBar( $bar );
			}
		}

		return wp_json_encode( $sanitized );
	}

	/**
	 * Sanitize the njt_nofi_global JSON string.
	 *
	 * Malformed JSON → returns encoded default global config.
	 *
	 * @param  string $json Raw JSON.
	 * @return string       Sanitized JSON string.
	 */
	public static function sanitizeGlobal( string $json ): string {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return wp_json_encode( self::defaultGlobal() );
		}

		return wp_json_encode( self::sanitizeGlobalFields( $decoded ) );
	}

	/**
	 * Sanitize a bar declared by a 3rd party (njt_nofi_register_bar / the
	 * njt_nofi_register_bars filter).
	 *
	 * Runs the same per-field validation as native bars (fills defaults from
	 * defaultBar(), validates enums, sanitizes colors, wp_kses_post on text) so
	 * injected bars obey identical Lite/Pro field handling downstream. The one
	 * difference: the integrator's stable `id` is PRESERVED — sanitizeBar()
	 * regenerates any non-UUID id, which would break dismissal/tracking for a
	 * declared bar. The id is restricted to [A-Za-z0-9_-] because it lands in
	 * the data-bar-id attribute and the dismissal cookie key.
	 *
	 * Single source of truth for external-id handling: when the caller's id is
	 * missing or sanitizes to empty, the returned `id` is an empty string —
	 * BarRegistry::normalize() treats that as the drop signal.
	 *
	 * @param  array $bar Raw external bar (may be partial).
	 * @return array      Sanitized bar; `id` is the cleaned caller id, or '' when invalid.
	 */
	public static function sanitizeExternalBar( array $bar ): array {
		$clean       = self::sanitizeBar( $bar );
		$clean['id'] = isset( $bar['id'] )
			? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $bar['id'] )
			: '';

		return $clean;
	}

	// ------------------------------------------------------------------
	// Private helpers shared with SchemaSanitizers trait
	// ------------------------------------------------------------------

	/**
	 * Return a default button definition.
	 *
	 * action: 'link' (open url) | 'close' (dismiss the bar). 'link' keeps the
	 * historical behaviour and is the backward-compatible default for data that
	 * predates this field.
	 *
	 * attention / hover: Pro CTA animation presets, both default 'none' so bars
	 * predating these fields render unchanged.
	 *
	 * @return array
	 */
	private static function defaultButton(): array {
		return [
			'enabled'    => true,
			'text'       => 'Learn more',
			'url'        => '',
			'fontWeight' => 500,
			'newWindow'  => true,
			'action'     => 'link',
			'attention'  => 'none',
			'hover'      => 'none',
			// Reopen-after-days for a button whose action is 'close' — its own
			// dismissal cookie TTL, independent of behavior.reopenAfterDays (which
			// governs the × close control). 0 = never auto-reopen.
			'reopenAfterDays' => 1,
		];
	}

	/**
	 * Fallback UUID v4 generator for environments where wp_generate_uuid4 is absent.
	 *
	 * @return string
	 */
	private static function fallbackUuid(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0x4000, 0x4fff ),
			wp_rand( 0x8000, 0xbfff ),
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}
}
