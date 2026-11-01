<?php

defined( 'ABSPATH' ) || exit;

/**
 * Public ads-toggle helper functions (global) for ad-rendering modules and consumer settings
 * pages.
 *
 * Self-contained: reads Registry registrations + stored options DIRECTLY, so it works the
 * instant this file loads (plugins_loaded:0) — with no dependency on the module's boot/hooks.
 * That is why an ad module can gate its own rendering during `plugins_loaded`.
 *
 * ONE TOGGLE PER CONSUMER (not per ad): a consumer plugin that registers 3 ad-module slugs gets
 * a single on/off switch that controls all 3 together. Each consumer plugin registers which
 * ad-module slugs it bundles via \YayRecommendedModules\Registry::register_ads_consumer(), e.g.:
 *
 *   \YayRecommendedModules\Registry::register_ads_consumer( 'whatsapp-wp', 'filebird-dashboard-widget' );
 *   \YayRecommendedModules\Registry::register_ads_consumer( 'whatsapp-wp', 'filebird-sidebar-popup' );
 *
 * (called AFTER requiring recommended-modules/loader.php, since the Registry class must exist.)
 *
 * PER-AD-MODULE, AND-aggregated across consumers: njt_ads_toggle_is_enabled( $ad_slug ) ORs
 * every consumer registered against that ad — the ad only fully disappears once ALL of its
 * registered consumers have switched their own single toggle off. Matching "if Notibar turns
 * its ads off but WP Duplicate hasn't, the ad they share still shows (via WP Duplicate)".
 *
 * Two different questions, two different functions — don't mix them up:
 *   - njt_ads_toggle_is_enabled( $ad_slug ): AGGREGATE across every registered consumer (OR).
 *     Use this to gate whether an ad module renders at all.
 *   - njt_ads_toggle_consumer_is_enabled( $consumer_slug ): ONE consumer's own single switch.
 *     Use this to populate that consumer's toggle's initial `checked` value on its own
 *     settings page — it must reflect what THAT switch controls, not the site-wide aggregate.
 *
 * FAIL-OPEN: when this module is not bundled the functions don't exist, so callers MUST guard:
 *   if ( ! function_exists( 'njt_ads_toggle_is_enabled' ) || njt_ads_toggle_is_enabled( 'filebird-sidebar-popup' ) ) {
 *       // ads enabled OR module absent → render
 *   }
 * Also fail-open when NO consumer registered $ad_slug at all (nothing to turn off).
 */

if ( ! function_exists( 'njt_ads_toggle_option_name' ) ) {
	/**
	 * @internal Shared option-key builder — keep in sync between the two public functions below.
	 */
	function njt_ads_toggle_option_name( $consumer_slug ) {
		return 'njt_ads_toggle_' . sanitize_key( $consumer_slug );
	}
}

if ( ! function_exists( 'njt_ads_toggle_consumer_is_enabled' ) ) {
	/**
	 * Is THIS SPECIFIC consumer's single ads switch on? Controls every ad module that consumer
	 * registered together — use this to populate that consumer's own toggle switch.
	 *
	 * @param string $consumer_slug This consumer's own registered slug (e.g. 'whatsapp-wp').
	 * @return bool
	 */
	function njt_ads_toggle_consumer_is_enabled( $consumer_slug ) {
		// Stored as the string '0'/'1', never a raw boolean: update_option() treats a new value
		// of `false` as equal to its own "option not found" sentinel (also `false`), so writing
		// boolean false on the very first toggle-off silently no-ops and the option never gets
		// created. Strings sidestep that WP core footgun.
		return '0' !== get_option( njt_ads_toggle_option_name( $consumer_slug ), '1' );
	}
}

if ( ! function_exists( 'njt_ads_toggle_is_enabled' ) ) {
	/**
	 * Is the given ad module currently enabled (per at least one registered consumer)?
	 * Use this to gate whether an ad module renders — NOT to populate a toggle switch's
	 * checked state (that's a single consumer's own state; see
	 * njt_ads_toggle_consumer_is_enabled()).
	 *
	 * @param string $ad_slug Ad module's own Registry name (e.g. 'filebird-sidebar-popup').
	 * @return bool
	 */
	function njt_ads_toggle_is_enabled( $ad_slug ) {
		$ad_slug   = sanitize_key( $ad_slug );
		
		if ( ! method_exists( '\YayRecommendedModules\Registry', 'get_ads_consumers' ) ) {
			return true;
		}

		$consumers = \YayRecommendedModules\Registry::get_ads_consumers( $ad_slug );

		if ( empty( $consumers ) ) {
			return true; // fail-open — nobody registered this ad at all
		}

		foreach ( $consumers as $consumer_slug ) {
			if ( njt_ads_toggle_consumer_is_enabled( $consumer_slug ) ) {
				return true; // at least one owning consumer still wants its ads on
			}
		}

		return false; // every registered consumer turned its ads off
	}
}
