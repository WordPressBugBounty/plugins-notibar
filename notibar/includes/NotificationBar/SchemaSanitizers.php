<?php
/**
 * SchemaSanitizers — per-field sanitize logic for Notibar v3 JSON schemas.
 *
 * Used exclusively by Schema::sanitizeBars() and Schema::sanitizeGlobal().
 * Each method matches one sub-object in the bar/global JSON shapes.
 *
 * MIRROR: src/customizer-app/utils/defaults.js — keep in sync.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Trait SchemaSanitizers
 *
 * Provides internal sanitize methods consumed by Schema.
 */
trait SchemaSanitizers {

	/**
	 * Sanitize a single bar array.
	 *
	 * @param  array $bar Raw bar data.
	 * @return array      Sanitized bar.
	 */
	private static function sanitizeBar( array $bar ): array {
		$default = self::defaultBar();

		// id — must match UUID v4 pattern or we regenerate.
		$id = isset( $bar['id'] ) ? (string) $bar['id'] : '';
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id ) ) {
			$id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallbackUuid();
		}

		return [
			'id'      => $id,
			'name'    => isset( $bar['name'] )
				? substr( sanitize_text_field( $bar['name'] ), 0, 100 )
				: $default['name'],
			'enabled' => isset( $bar['enabled'] ) ? (bool) $bar['enabled'] : true,
			'order'   => isset( $bar['order'] ) ? intval( $bar['order'] ) : 0,
			'content' => self::sanitizeContent(
				isset( $bar['content'] ) && is_array( $bar['content'] ) ? $bar['content'] : [],
				$default['content']
			),
			'style'   => self::sanitizeStyle(
				isset( $bar['style'] ) && is_array( $bar['style'] ) ? $bar['style'] : [],
				$default['style']
			),
			'display' => self::sanitizeDisplay(
				isset( $bar['display'] ) && is_array( $bar['display'] ) ? $bar['display'] : [],
				$default['display']
			),
			'behavior' => self::sanitizeBehavior(
				isset( $bar['behavior'] ) && is_array( $bar['behavior'] ) ? $bar['behavior'] : [],
				$default['behavior']
			),
			'schedule' => self::sanitizeSchedule(
				isset( $bar['schedule'] ) && is_array( $bar['schedule'] ) ? $bar['schedule'] : [],
				$default['schedule']
			),
			'countdown' => self::sanitizeCountdown(
				isset( $bar['countdown'] ) && is_array( $bar['countdown'] ) ? $bar['countdown'] : [],
				$default['countdown']
			),
		];
	}

	/**
	 * Sanitize the schedule sub-object.
	 *
	 * Field formats:
	 *   startAt / endAt          — "YYYY-MM-DDTHH:MM" (datetime-local input format).
	 *                              Empty string = unbounded on that side.
	 *   dailyWindow.start / end  — "HH:MM" (24h). Empty = unbounded on that side.
	 *   daysOfWeek               — array of ints 0..6 (Sun..Sat). Default = 0..6.
	 *
	 * @param  array $s       Raw schedule data.
	 * @param  array $default Default schedule values.
	 * @return array
	 */
	private static function sanitizeSchedule( array $s, array $default ): array {
		$dw_raw   = isset( $s['dailyWindow'] ) && is_array( $s['dailyWindow'] )
			? $s['dailyWindow']
			: [];
		$dow_raw  = isset( $s['daysOfWeek'] ) && is_array( $s['daysOfWeek'] )
			? $s['daysOfWeek']
			: $default['daysOfWeek'];
		$dow      = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $dow_raw ),
					fn( $v ) => $v >= 0 && $v <= 6
				)
			)
		);
		if ( empty( $dow ) ) {
			$dow = $default['daysOfWeek'];
		}

		return [
			'enabled'       => isset( $s['enabled'] ) ? (bool) $s['enabled'] : false,
			'useClientTime' => isset( $s['useClientTime'] ) ? (bool) $s['useClientTime'] : false,
			'startAt'       => self::sanitizeDateTimeLocal( $s['startAt'] ?? '' ),
			'endAt'         => self::sanitizeDateTimeLocal( $s['endAt'] ?? '' ),
			'dailyWindow'   => [
				'enabled' => isset( $dw_raw['enabled'] ) ? (bool) $dw_raw['enabled'] : false,
				'start'   => self::sanitizeTimeOfDay( $dw_raw['start'] ?? '' ),
				'end'     => self::sanitizeTimeOfDay( $dw_raw['end'] ?? '' ),
			],
			'daysOfWeek'    => $dow,
		];
	}

	/**
	 * Sanitize a datetime-local string ("YYYY-MM-DDTHH:MM"). Empty = unbounded.
	 *
	 * @param  mixed $raw Raw value.
	 * @return string     Validated string or '' if invalid.
	 */
	private static function sanitizeDateTimeLocal( $raw ): string {
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return '';
		}
		// Accept "YYYY-MM-DDTHH:MM" or "YYYY-MM-DDTHH:MM:SS" (browser variants).
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $raw ) ) {
			// Normalise to "YYYY-MM-DDTHH:MM" (drop seconds).
			return substr( $raw, 0, 16 );
		}
		return '';
	}

	/**
	 * Sanitize an "HH:MM" time-of-day string. Empty = unbounded.
	 *
	 * @param  mixed $raw Raw value.
	 * @return string     Validated string or '' if invalid.
	 */
	private static function sanitizeTimeOfDay( $raw ): string {
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $raw ) ) {
			return $raw;
		}
		return '';
	}

	/**
	 * Sanitize the content sub-object.
	 *
	 * @param  array $c       Raw content.
	 * @param  array $default Default content.
	 * @return array
	 */
	private static function sanitizeContent( array $c, array $default ): array {
		return [
			'text'           => isset( $c['text'] )
				? wp_kses_post( $c['text'] )
				: $default['text'],
			'textMobile'     => isset( $c['textMobile'] )
				? wp_kses_post( $c['textMobile'] )
				: $default['textMobile'],
			'mobileSeparate' => isset( $c['mobileSeparate'] ) ? (bool) $c['mobileSeparate'] : false,
			'button'         => self::sanitizeButton(
				isset( $c['button'] ) && is_array( $c['button'] ) ? $c['button'] : [],
				$default['button']
			),
			'buttonMobile'   => self::sanitizeButton(
				isset( $c['buttonMobile'] ) && is_array( $c['buttonMobile'] ) ? $c['buttonMobile'] : [],
				$default['buttonMobile']
			),
		];
	}

	/**
	 * Sanitize a button sub-object (desktop or mobile).
	 *
	 * fontWeight must be one of: 100, 200, … 900 (steps of 100).
	 *
	 * @param  array $b       Raw button data.
	 * @param  array $default Default button values.
	 * @return array
	 */
	private static function sanitizeButton( array $b, array $default ): array {
		$fw_raw = isset( $b['fontWeight'] ) ? intval( $b['fontWeight'] ) : $default['fontWeight'];
		$fw     = in_array( $fw_raw, [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ], true )
			? $fw_raw
			: $default['fontWeight'];

		// Button text: NO HTML-tag stripping at the server. WP's kses-based
		// sanitizers (wp_filter_nohtml_kses, strip_tags) treat short strings
		// like "<3" as malformed tags and eat them. Safety is enforced at the
		// render layer instead — render-bar.js wraps button text in
		// escapeText() which converts <, >, & to entities, so any stored
		// value is rendered as literal text (XSS-safe by escape-on-output).
		// We still cap length defensively to bound storage.
		$text_raw  = isset( $b['text'] ) ? (string) $b['text'] : $default['text'];
		$text_norm = wp_check_invalid_utf8( $text_raw );
		$text      = function_exists( 'mb_substr' )
			? mb_substr( $text_norm, 0, 200 )
			: substr( $text_norm, 0, 200 );

		// action: only 'link' or 'close'. Unknown/missing falls back to the
		// default ('link'), keeping pre-existing data backward-compatible.
		$action = ( isset( $b['action'] ) && in_array( $b['action'], [ 'link', 'close' ], true ) )
			? $b['action']
			: $default['action'];

		// attention / hover: Pro animation presets, validated against the
		// whitelist; unknown/missing coerces to the default ('none'). Stored in
		// both editions (harmless); the Pro gate lives in render + UI.
		$attention = ( isset( $b['attention'] ) && in_array( $b['attention'], self::ALLOWED_BTN_ATTENTION, true ) )
			? $b['attention']
			: $default['attention'];
		$hover = ( isset( $b['hover'] ) && in_array( $b['hover'], self::ALLOWED_BTN_HOVER, true ) )
			? $b['hover']
			: $default['hover'];

		// Per-button reopen-after-days (used when action is 'close'). Clamped
		// 0–365 to mirror behavior.reopenAfterDays.
		$reopen = max( 0, min( 365,
			isset( $b['reopenAfterDays'] ) ? intval( $b['reopenAfterDays'] ) : $default['reopenAfterDays']
		) );

		return [
			'enabled'    => isset( $b['enabled'] ) ? (bool) $b['enabled'] : $default['enabled'],
			'text'       => $text,
			'url'        => isset( $b['url'] )
				? esc_url_raw( $b['url'] )
				: $default['url'],
			'fontWeight' => $fw,
			'newWindow'  => isset( $b['newWindow'] ) ? (bool) $b['newWindow'] : $default['newWindow'],
			'action'     => $action,
			'attention'  => $attention,
			'hover'      => $hover,
			'reopenAfterDays' => $reopen,
		];
	}

	/**
	 * Sanitize the style sub-object.
	 *
	 * Clamps: fontSize (8–72), contentWidth (100–3000).
	 *
	 * @param  array $s       Raw style data.
	 * @param  array $default Default style values.
	 * @return array
	 */
	private static function sanitizeStyle( array $s, array $default ): array {
		// Layout replaces the old `alignment` field. A valid `layout` wins; when
		// it is absent (a bar saved before this change) the legacy `alignment`
		// value is mapped to its closest layout, so old bars keep their look with
		// no separate migration step. Anything unrecognised falls to the default.
		if ( isset( $s['layout'] ) && in_array( $s['layout'], self::ALLOWED_LAYOUT, true ) ) {
			$layout = $s['layout'];
		} elseif ( isset( $s['alignment'] ) && isset( self::LEGACY_ALIGNMENT_LAYOUT[ $s['alignment'] ] ) ) {
			$layout = self::LEGACY_ALIGNMENT_LAYOUT[ $s['alignment'] ];
		} else {
			$layout = $default['layout'];
		}

		$position = isset( $s['positionType'] ) && in_array( $s['positionType'], self::ALLOWED_POSITION, true )
			? $s['positionType']
			: $default['positionType'];

		$placement = isset( $s['placement'] ) && in_array( $s['placement'], self::ALLOWED_PLACEMENT, true )
			? $s['placement']
			: $default['placement'];

		$font_size = max( 8, min( 72, isset( $s['fontSize'] ) ? intval( $s['fontSize'] ) : $default['fontSize'] ) );

		$content_width = max( 100, min( 3000,
			isset( $s['contentWidth'] ) ? intval( $s['contentWidth'] ) : $default['contentWidth']
		) );

		return [
			// Background fills accept an alpha channel (8-digit hex) so the
			// alpha-enabled colour picker can make them semi-transparent.
			'bgColor'      => self::sanitizeHexColorMaybeAlpha(
				$s['bgColor'] ?? null,
				$default['bgColor']
			),
			'textColor'    => isset( $s['textColor'] )
				? ( sanitize_hex_color( $s['textColor'] ) ?: $default['textColor'] )
				: $default['textColor'],
			'btnBgColor'   => self::sanitizeHexColorMaybeAlpha(
				$s['btnBgColor'] ?? null,
				$default['btnBgColor']
			),
			'btnTextColor' => isset( $s['btnTextColor'] )
				? ( sanitize_hex_color( $s['btnTextColor'] ) ?: $default['btnTextColor'] )
				: $default['btnTextColor'],
			'fontSize'     => $font_size,
			'layout'       => $layout,
			'contentWidth' => $content_width,
			'positionType' => $position,
			'placement'    => $placement,
			// Overall bar opacity, percent. Clamped 10–100 (floor of 10 keeps the
			// bar visible/clickable). MIRROR: defaults.js DEFAULT_BAR.style.opacity.
			'opacity'      => max( 10, min( 100,
				isset( $s['opacity'] ) ? intval( $s['opacity'] ) : $default['opacity']
			) ),
			'activePreset' => self::sanitizeActivePreset(
				isset( $s['activePreset'] ) ? $s['activePreset'] : null
			),
		];
	}

	/**
	 * Sanitize a hex colour that MAY carry an alpha channel.
	 *
	 * Accepts 3-, 6-, or 8-digit hex (#rgb, #rrggbb, #rrggbbaa). The alpha-enabled
	 * colour picker emits 8-digit hex (#rrggbbaa) for transparent fills and
	 * collapses to 6-digit when fully opaque (colord .toHex()). Unlike core
	 * sanitize_hex_color() — which rejects the 8-digit form — this keeps the alpha
	 * byte. The anchored pattern is the security boundary: the value lands in an
	 * inline style attribute, so only a strict #hex shape is allowed (escapeAttr
	 * at render is a second layer). Anything else falls back to the default.
	 *
	 * @param  mixed  $value    Raw colour value.
	 * @param  string $fallback Default to use when invalid.
	 * @return string           Validated #hex string or the fallback.
	 */
	private static function sanitizeHexColorMaybeAlpha( $value, string $fallback ): string {
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $trimmed ) ) {
				return $trimmed;
			}
		}
		return $fallback;
	}

	/**
	 * Sanitize the active colour-preset snapshot.
	 *
	 * Returns a clean { bg, text, btnBg, btnText, name? } array when the four
	 * colour channels are all present and valid hex; otherwise null (no preset).
	 * An incomplete or malformed snapshot collapses to null so the per-colour
	 * Reset buttons safely fall back to the global defaults.
	 *
	 * @param  mixed $raw Raw activePreset value.
	 * @return array|null
	 */
	private static function sanitizeActivePreset( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$out = [];
		foreach ( [ 'bg', 'text', 'btnBg', 'btnText' ] as $channel ) {
			if ( ! isset( $raw[ $channel ] ) ) {
				return null;
			}
			$hex = sanitize_hex_color( $raw[ $channel ] );
			if ( ! $hex ) {
				return null;
			}
			$out[ $channel ] = $hex;
		}

		if ( isset( $raw['name'] ) ) {
			$out['name'] = sanitize_text_field( $raw['name'] );
		}

		return $out;
	}

	/**
	 * Sanitize the display sub-object.
	 *
	 * pageIds / postIds may contain integer IDs or the literal "home_page" token.
	 *
	 * @param  array $d       Raw display data.
	 * @param  array $default Default display values.
	 * @return array
	 */
	private static function sanitizeDisplay( array $d, array $default ): array {
		$raw_devices = isset( $d['devices'] ) && is_array( $d['devices'] ) ? $d['devices'] : $default['devices'];
		$devices     = array_values(
			array_filter( $raw_devices, fn( $v ) => in_array( $v, self::ALLOWED_DEVICES, true ) )
		);
		if ( empty( $devices ) ) {
			$devices = $default['devices'];
		}

		$page_logic = isset( $d['pageLogic'] ) && in_array( $d['pageLogic'], self::ALLOWED_LOGIC, true )
			? $d['pageLogic']
			: $default['pageLogic'];

		$post_logic = isset( $d['postLogic'] ) && in_array( $d['postLogic'], self::ALLOWED_LOGIC, true )
			? $d['postLogic']
			: $default['postLogic'];

		$cpt_logic = isset( $d['cptLogic'] ) && in_array( $d['cptLogic'], self::ALLOWED_LOGIC, true )
			? $d['cptLogic']
			: $default['cptLogic'];

		// CPT slug list — strings only, sanitize_key matches WP CPT slug rules
		// ([a-z0-9_-]). Unregistered slugs kept in storage (filtered at runtime)
		// so re-activating a CPT-providing plugin restores admin intent.
		$raw_cpt_types = isset( $d['cptTypes'] ) && is_array( $d['cptTypes'] )
			? $d['cptTypes']
			: $default['cptTypes'];
		$cpt_types = array_values( array_unique( array_filter(
			array_map( fn( $v ) => is_string( $v ) ? sanitize_key( $v ) : '', $raw_cpt_types ),
			fn( $v ) => '' !== $v
		) ) );

		// Audience (role/login/user targeting). Role slugs sanitised like CPT
		// slugs (unregistered kept, filtered at runtime). userIds reuse the
		// positive-int list sanitizer.
		$audience = isset( $d['audience'] ) && in_array( $d['audience'], self::ALLOWED_AUDIENCE, true )
			? $d['audience']
			: $default['audience'];

		$raw_roles = isset( $d['roles'] ) && is_array( $d['roles'] ) ? $d['roles'] : $default['roles'];
		$roles     = array_values( array_unique( array_filter(
			array_map( fn( $v ) => is_string( $v ) ? sanitize_key( $v ) : '', $raw_roles ),
			fn( $v ) => '' !== $v
		) ) );

		// Country targeting (Pro). countryLogic enum like pageLogic but with its
		// own allowed set (no 'none'). Codes normalised to uppercase ISO 3166-1
		// alpha-2; anything not matching ^[A-Z]{2}$ is dropped. List deduped.
		$country_logic = isset( $d['countryLogic'] ) && in_array( $d['countryLogic'], self::ALLOWED_COUNTRY_LOGIC, true )
			? $d['countryLogic']
			: $default['countryLogic'];

		$raw_countries = isset( $d['countries'] ) && is_array( $d['countries'] ) ? $d['countries'] : $default['countries'];
		$countries     = array_values( array_unique( array_filter(
			array_map(
				fn( $v ) => is_string( $v ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', $v ) ) : '',
				$raw_countries
			),
			fn( $v ) => 1 === preg_match( '/^[A-Z]{2}$/', $v )
		) ) );

		return [
			'devices'   => $devices,
			'pageLogic' => $page_logic,
			'pageIds'   => self::sanitizeIdList( $d['pageIds'] ?? $default['pageIds'] ),
			'postLogic' => $post_logic,
			'postIds'   => self::sanitizeIdList( $d['postIds'] ?? $default['postIds'] ),
			'cptTypes'  => $cpt_types,
			'cptLogic'  => $cpt_logic,
			'cptIds'    => self::sanitizeIdList( $d['cptIds'] ?? $default['cptIds'] ),
			'audience'  => $audience,
			'roles'     => $roles,
			'userIds'   => self::sanitizeIdList( $d['userIds'] ?? $default['userIds'] ),
			'countryLogic' => $country_logic,
			'countries'    => $countries,
		];
	}

	/**
	 * Sanitize the behavior sub-object.
	 *
	 * hideCloseButton is a 3-state enum: "close" | "toggle" | "disable".
	 * reopenAfterDays clamped 0–365.
	 * trigger is { type, value } (Pro display trigger): type whitelisted via
	 * ALLOWED_TRIGGER_TYPE; value clamped per type (scroll 1–100, time 0–3600,
	 * click 1–100; none → 0). Missing/invalid → default { none, 0 }.
	 *
	 * @param  array $b       Raw behavior data.
	 * @param  array $default Default behavior values.
	 * @return array
	 */
	private static function sanitizeBehavior( array $b, array $default ): array {
		$hcb    = isset( $b['hideCloseButton'] ) && in_array( $b['hideCloseButton'], self::ALLOWED_CLOSE_BTN, true )
			? $b['hideCloseButton']
			: $default['hideCloseButton'];

		$reopen = max( 0, min( 365,
			isset( $b['reopenAfterDays'] ) ? intval( $b['reopenAfterDays'] ) : $default['reopenAfterDays']
		) );

		$rawTrigger = isset( $b['trigger'] ) && is_array( $b['trigger'] ) ? $b['trigger'] : [];
		$defTrigger = isset( $default['trigger'] ) && is_array( $default['trigger'] )
			? $default['trigger'] : [ 'type' => 'none', 'value' => 0 ];

		$tType = ( isset( $rawTrigger['type'] ) && in_array( $rawTrigger['type'], self::ALLOWED_TRIGGER_TYPE, true ) )
			? $rawTrigger['type'] : $defTrigger['type'];

		$tVal = isset( $rawTrigger['value'] ) ? intval( $rawTrigger['value'] ) : 0;
		switch ( $tType ) {
			case 'scroll': $tVal = max( 1, min( 100, $tVal ) ); break;   // %
			case 'time':   $tVal = max( 0, min( 3600, $tVal ) ); break;  // seconds
			case 'click':  $tVal = max( 1, min( 100, $tVal ) ); break;   // clicks
			default:       $tVal = 0;                                    // none
		}

		return [
			'hideCloseButton' => $hcb,
			'reopenAfterDays' => $reopen,
			'trigger'         => [ 'type' => $tType, 'value' => $tVal ],
		];
	}

	/**
	 * Sanitize the countdown timer sub-object (Pro).
	 *
	 * Runs in both editions (harmless in Lite, which never renders it). Enum
	 * fields fall back to defaults; units are whitelisted, de-duped and forced
	 * into canonical order, never empty; duration clamps to 0..30 days.
	 *
	 * MIRROR: src/customizer-app/utils/defaults.js DEFAULT_BAR.countdown.
	 *
	 * @param  array $c       Raw countdown data.
	 * @param  array $default Default countdown values.
	 * @return array
	 */
	private static function sanitizeCountdown( array $c, array $default ): array {
		$type = ( isset( $c['type'] ) && in_array( $c['type'], Schema::ALLOWED_CD_TYPE, true ) )
			? $c['type'] : $default['type'];

		$ui = ( isset( $c['ui'] ) && in_array( $c['ui'], Schema::ALLOWED_CD_UI, true ) )
			? $c['ui'] : $default['ui'];

		// Whitelist units, drop dupes, re-order to the canonical days..seconds order.
		$raw_units = isset( $c['units'] ) && is_array( $c['units'] ) ? $c['units'] : [];
		$units     = array_values( array_filter(
			Schema::ALLOWED_CD_UNIT,
			static function ( $u ) use ( $raw_units ) {
				return in_array( $u, $raw_units, true );
			}
		) );
		if ( empty( $units ) ) {
			$units = $default['units'];
		}

		// 0..2_592_000 seconds (30 days).
		$duration = max( 0, min( 2592000,
			isset( $c['duration'] ) ? intval( $c['duration'] ) : $default['duration']
		) );

		return [
			'enabled'    => isset( $c['enabled'] ) ? (bool) $c['enabled'] : $default['enabled'],
			'type'       => $type,
			'endAt'      => self::sanitizeDateTimeLocal( isset( $c['endAt'] ) ? $c['endAt'] : '' ),
			'duration'   => $duration,
			'ui'         => $ui,
			'units'      => $units,
			'showAllUnits' => isset( $c['showAllUnits'] ) ? (bool) $c['showAllUnits'] : $default['showAllUnits'],
			'resetToken' => max( 0, isset( $c['resetToken'] ) ? intval( $c['resetToken'] ) : $default['resetToken'] ),
		];
	}

	/**
	 * Sanitize a global config array.
	 *
	 * @param  array $g Raw global data.
	 * @return array
	 */
	private static function sanitizeGlobalFields( array $g ): array {
		$default = self::defaultGlobal();

		$display_mode = isset( $g['displayMode'] ) && in_array( $g['displayMode'], self::ALLOWED_DISP_MODE, true )
			? $g['displayMode']
			: $default['displayMode'];

		$interval = max( 2, min( 60,
			isset( $g['rotationIntervalSeconds'] ) ? intval( $g['rotationIntervalSeconds'] ) : $default['rotationIntervalSeconds']
		) );

		$rotation_order = isset( $g['rotationOrder'] ) && in_array( $g['rotationOrder'], Schema::ALLOWED_ROTATION_ORDER, true )
			? $g['rotationOrder']
			: $default['rotationOrder'];

		$stack_position = isset( $g['stackPositionType'] ) && in_array( $g['stackPositionType'], self::ALLOWED_POSITION, true )
			? $g['stackPositionType']
			: $default['stackPositionType'];

		return [
			'displayMode'             => $display_mode,
			'rotationIntervalSeconds' => $interval,
			'rotationPauseOnHover'    => isset( $g['rotationPauseOnHover'] )
				? (bool) $g['rotationPauseOnHover']
				: $default['rotationPauseOnHover'],
			'rotationOrder'           => $rotation_order,
			'rotationShowArrows'      => isset( $g['rotationShowArrows'] )
				? (bool) $g['rotationShowArrows']
				: $default['rotationShowArrows'],
			'stackPositionType'       => $stack_position,
		];
	}

	/**
	 * Sanitize an ID list: positive integers OR a whitelisted string token.
	 *
	 * Allowed tokens (extend this set when adding more synthetic picker
	 * entries — see RestPostsController::handle_search / handle_by_ids and
	 * AsyncPostPicker's HOME_PAGE_OPTION / SINGLE_PRODUCT_OPTION):
	 *   - 'home_page'         → matches the front-page URL
	 *   - 'wc_single_product' → matches every single-product permalink
	 *   - 'tpl:<filename>'    → matches any page using the given page template
	 *                           (filename matches _wp_page_template meta value)
	 *
	 * Drops negative integers and zero. Drops unknown string tokens (they
	 * would never match anything anyway and only clutter storage).
	 *
	 * @param  mixed $raw Raw list value.
	 * @return array
	 */
	private static function sanitizeIdList( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$allowed_tokens = [ 'home_page', 'wc_single_product' ];

		$out = [];
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) {
				if ( in_array( $item, $allowed_tokens, true ) ) {
					$out[] = $item;
				} elseif ( 0 === strpos( $item, 'tpl:' ) && strlen( $item ) > 4 ) {
					// Length-cap defensively to bound storage; full validation
					// happens at match time (PageRules compares against the
					// current request's _wp_page_template meta).
					$out[] = substr( $item, 0, 200 );
				}
			} else {
				$int = intval( $item );
				if ( $int > 0 ) {
					$out[] = $int;
				}
			}
		}
		return $out;
	}
}
