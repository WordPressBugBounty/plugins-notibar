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

	const ALLOWED_ALIGNMENT = [ 'center', 'left', 'right', 'space-around' ];
	const ALLOWED_POSITION  = [ 'fixed', 'absolute' ];
	const ALLOWED_PLACEMENT = [ 'top', 'bottom' ];
	const ALLOWED_DEVICES   = [ 'desktop', 'mobile' ];
	const ALLOWED_LOGIC     = [ 'all', 'none', 'include', 'exclude' ];
	const ALLOWED_CLOSE_BTN = [ 'close', 'toggle', 'disable' ];
	const ALLOWED_DISP_MODE     = [ 'single', 'rotation' ];
	const ALLOWED_ROTATION_ORDER = [ 'sequential', 'random' ];
	const ALLOWED_AUDIENCE      = [ 'all', 'loggedin', 'loggedout', 'roles', 'users' ];

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
				'alignment'    => 'center',
				'contentWidth' => 900,
				'positionType' => 'fixed',
				'placement'    => 'top',
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
			],
			'behavior' => [
				'hideCloseButton' => 'close',
				'reopenAfterDays' => 1,
			],
			'schedule' => self::defaultSchedule(),
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
	 * @return array
	 */
	private static function defaultButton(): array {
		return [
			'enabled'    => true,
			'text'       => 'Learn more',
			'url'        => '',
			'fontWeight' => 500,
			'newWindow'  => true,
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
