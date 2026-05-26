<?php
/**
 * MigrationMapper — legacy v2.1.9 → v3.0 value mapping helpers.
 *
 * Used exclusively by Migration::buildBarFromLegacy().
 * Extracted here to keep Migration.php under 200 LOC.
 *
 * All logic is verified against NotificationBarHandle::njt_nofi_isDisplayNotification
 * (lines 240–272 of that file) — do not change without cross-checking.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Trait MigrationMapper
 *
 * Provides value-mapping methods consumed by Migration.
 */
trait MigrationMapper {

	/**
	 * Map a legacy page/post logic string to the v3 4-state enum.
	 *
	 * Legacy values (from NotificationBarHandle lines 249–271):
	 *   dis_all_page  / dis_all_post        → "all"
	 *   hide_all_page / hide_all_post       → "none"
	 *   dis_selected_page  / dis_selected_post  → "include"
	 *   hide_selected_page / hide_selected_post → "exclude"
	 *
	 * @param  string $raw Legacy value (e.g. "dis_all_page").
	 * @return string      v3 enum value: "all"|"none"|"include"|"exclude".
	 */
	private function mapLogic( string $raw ): string {
		if ( strpos( $raw, 'dis_all_' ) === 0 ) {
			return 'all';
		}
		if ( strpos( $raw, 'hide_all_' ) === 0 ) {
			return 'none';
		}
		if ( strpos( $raw, 'dis_selected_' ) === 0 ) {
			return 'include';
		}
		if ( strpos( $raw, 'hide_selected_' ) === 0 ) {
			return 'exclude';
		}
		return 'all'; // unrecognised → safe default (show everywhere)
	}

	/**
	 * Map a legacy hideCloseButton string to the v3 3-state enum.
	 *
	 * Legacy values (WpCustomNotification default 'close_button'):
	 *   close_button   → "close"   (render × button)
	 *   toggle_button  → "toggle"  (render collapse/expand arrow) — actual legacy DB value
	 *   toggle         → "toggle"  (defensive — newer variant if any)
	 *   disable        → "disable" (no dismissal control)
	 *
	 * @param  string $raw Legacy value.
	 * @return string      v3 enum value: "close"|"toggle"|"disable".
	 */
	private function mapHideCloseButton( string $raw ): string {
		switch ( $raw ) {
			case 'toggle_button':
			case 'toggle':
				return 'toggle';
			case 'disable':
				return 'disable';
			case 'close_button':
			default:
				return 'close';
		}
	}

	/**
	 * Parse a legacy comma-separated ID list into a v3 array.
	 *
	 * Each token is either:
	 *   "home_page" — kept as literal string (runtime resolves to is_home()|is_front_page()).
	 *   numeric     — converted with intval(), dropped if ≤ 0.
	 *
	 * @param  mixed $raw Comma-separated string or falsy value.
	 * @return array      Array of int|"home_page" values.
	 */
	private function parseIdList( $raw ): array {
		if ( ! $raw ) {
			return [];
		}

		$out = [];
		foreach ( explode( ',', (string) $raw ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			if ( $token === 'home_page' ) {
				$out[] = 'home_page';
			} else {
				$int = intval( $token );
				if ( $int > 0 ) {
					$out[] = $int;
				}
			}
		}

		return $out;
	}

	/**
	 * Apply the full legacy → v3 field mapping for content sub-objects.
	 *
	 * @param  array $bar Bar array to mutate (passed by reference).
	 * @param  array $l   Legacy theme_mod snapshot.
	 * @return void
	 */
	private function applyContentMapping( array &$bar, array $l ): void {
		if ( false !== $l['njt_nofi_text'] ) {
			$bar['content']['text'] = wp_kses_post( $l['njt_nofi_text'] );
		}
		if ( false !== $l['njt_nofi_text_mobile'] ) {
			$bar['content']['textMobile'] = wp_kses_post( $l['njt_nofi_text_mobile'] );
		}
		if ( false !== $l['njt_nofi_content_mobile'] ) {
			$bar['content']['mobileSeparate'] = (bool) $l['njt_nofi_content_mobile'];
		}
		$this->applyButtonMapping( $bar['content']['button'], $l, '' );
		$this->applyButtonMapping( $bar['content']['buttonMobile'], $l, '_mobile' );
	}

	/**
	 * Apply legacy → v3 field mapping for one button (desktop or mobile).
	 *
	 * Suffix '' = desktop keys, '_mobile' = mobile keys.
	 *
	 * @param  array  $btn    Button sub-array to mutate (by reference).
	 * @param  array  $l      Legacy theme_mod snapshot.
	 * @param  string $suffix '_mobile' or ''.
	 * @return void
	 */
	private function applyButtonMapping( array &$btn, array $l, string $suffix ): void {
		$handle_key = 'njt_nofi_handle_button' . $suffix;
		$text_key   = 'njt_nofi_lb_text' . $suffix;
		$url_key    = 'njt_nofi_lb_url' . $suffix;
		$fw_key     = 'njt_nofi_lb_font_weight' . $suffix;
		$win_key    = 'njt_nofi_open_new_windown' . $suffix;

		if ( false !== $l[ $handle_key ] ) {
			$btn['enabled'] = (bool) $l[ $handle_key ];
		}
		if ( false !== $l[ $text_key ] ) {
			// Decode HTML entities first — legacy v2.x stored some labels via
			// kses-family filters which entity-encoded "&" to "&amp;". Store
			// the raw text in v3 (render layer escapes on output, mirroring
			// SchemaSanitizers::sanitizeButton). Length-capped defensively.
			$raw = html_entity_decode(
				(string) $l[ $text_key ],
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
			$raw = wp_check_invalid_utf8( $raw );
			$btn['text'] = function_exists( 'mb_substr' )
				? mb_substr( $raw, 0, 200 )
				: substr( $raw, 0, 200 );
		}
		if ( false !== $l[ $url_key ] ) {
			$btn['url'] = esc_url_raw( $l[ $url_key ] );
		}
		if ( false !== $l[ $fw_key ] ) {
			$fw = intval( $l[ $fw_key ] );
			$btn['fontWeight'] = in_array( $fw, [ 100,200,300,400,500,600,700,800,900 ], true ) ? $fw : 400;
		}
		if ( false !== $l[ $win_key ] ) {
			$btn['newWindow'] = (bool) $l[ $win_key ];
		}
	}

	/**
	 * Apply legacy → v3 field mapping for style sub-object.
	 *
	 * @param  array $bar Bar array to mutate (by reference).
	 * @param  array $l   Legacy theme_mod snapshot.
	 * @return void
	 */
	private function applyStyleMapping( array &$bar, array $l ): void {
		$color_map = [
			'njt_nofi_bg_color'      => 'bgColor',
			'njt_nofi_text_color'    => 'textColor',
			'njt_nofi_lb_color'      => 'btnBgColor',
			'njt_nofi_lb_text_color' => 'btnTextColor',
		];
		foreach ( $color_map as $legacy_key => $v3_key ) {
			if ( false !== $l[ $legacy_key ] ) {
				$hex = sanitize_hex_color( $l[ $legacy_key ] );
				if ( $hex ) {
					$bar['style'][ $v3_key ] = $hex;
				}
			}
		}

		if ( false !== $l['njt_nofi_font_size'] ) {
			$bar['style']['fontSize'] = max( 8, min( 72, intval( $l['njt_nofi_font_size'] ) ) );
		}

		if ( false !== $l['njt_nofi_alignment'] ) {
			// Legacy used 'space_around'; v3 normalises to 'space-around'.
			$al = $l['njt_nofi_alignment'] === 'space_around' ? 'space-around' : $l['njt_nofi_alignment'];
			if ( in_array( $al, Schema::ALLOWED_ALIGNMENT, true ) ) {
				$bar['style']['alignment'] = $al;
			}
		}

		if ( false !== $l['njt_nofi_content_width'] ) {
			$bar['style']['contentWidth'] = max( 100, min( 3000, intval( $l['njt_nofi_content_width'] ) ) );
		}

		if ( false !== $l['njt_nofi_position_type'] ) {
			$pt = $l['njt_nofi_position_type'];
			if ( in_array( $pt, Schema::ALLOWED_POSITION, true ) ) {
				$bar['style']['positionType'] = $pt;
			}
		}
	}

	/**
	 * Apply legacy → v3 field mapping for display sub-object.
	 *
	 * @param  array $bar Bar array to mutate (by reference).
	 * @param  array $l   Legacy theme_mod snapshot.
	 * @return void
	 */
	private function applyDisplayMapping( array &$bar, array $l ): void {
		// Devices
		$dev = false !== $l['njt_nofi_devices_display'] ? $l['njt_nofi_devices_display'] : 'all_devices';
		switch ( $dev ) {
			case 'desktop':
				$bar['display']['devices'] = [ 'desktop' ];
				break;
			case 'mobile':
				$bar['display']['devices'] = [ 'mobile' ];
				break;
			default:
				$bar['display']['devices'] = [ 'desktop', 'mobile' ];
				break;
		}

		// Page logic + IDs
		$pl_raw = false !== $l['njt_nofi_logic_display_page'] ? $l['njt_nofi_logic_display_page'] : 'dis_all_page';
		$bar['display']['pageLogic'] = $this->mapLogic( $pl_raw );
		$bar['display']['pageIds']   = $this->parseIdList(
			false !== $l['njt_nofi_list_display_page'] ? $l['njt_nofi_list_display_page'] : ''
		);

		// Post logic + IDs
		$post_raw = false !== $l['njt_nofi_logic_display_post'] ? $l['njt_nofi_logic_display_post'] : 'dis_all_post';
		$bar['display']['postLogic'] = $this->mapLogic( $post_raw );
		$bar['display']['postIds']   = $this->parseIdList(
			false !== $l['njt_nofi_list_display_post'] ? $l['njt_nofi_list_display_post'] : ''
		);
	}

	/**
	 * Apply legacy → v3 field mapping for behavior sub-object.
	 *
	 * @param  array $bar Bar array to mutate (by reference).
	 * @param  array $l   Legacy theme_mod snapshot.
	 * @return void
	 */
	private function applyBehaviorMapping( array &$bar, array $l ): void {
		$hcb_raw = false !== $l['njt_nofi_hide_close_button'] ? $l['njt_nofi_hide_close_button'] : 'close_button';
		$bar['behavior']['hideCloseButton'] = $this->mapHideCloseButton( $hcb_raw );

		if ( false !== $l['njt_nofi_open_after_day'] ) {
			$bar['behavior']['reopenAfterDays'] = max( 0, min( 365, intval( $l['njt_nofi_open_after_day'] ) ) );
		}
	}
}
