<?php
/**
 * WPML String Translation bridge — Notibar v3.0.
 *
 * Replaces the static wpml-config.xml (6 hard-coded option keys) with a
 * dynamic per-bar registration strategy using WPML String Translation API:
 *   - do_action('wpml_register_single_string', ...)   — register / update
 *   - do_action('wpml_unregister_single_string', ...) — remove stale strings
 *   - apply_filters('wpml_translate_single_string', ...) — resolve at render
 *
 * Per resolved decision #4 (plan.md): WPML ST addon absent → silent no-op.
 * No admin notices are emitted. All hooks short-circuit via isWpmlActive().
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class WpmlBridge
 *
 * Singleton. Registers its own hooks in the constructor.
 *
 * Hook surface:
 *   customize_save_after  → onSave()        — diff + register/unregister strings.
 *   njt_nofi_resolve_strings → resolveStrings() — substitute translated values.
 */
class WpmlBridge {

	// WPML String Translation domain.
	const DOMAIN = 'notibar';

	// wp_options key for the persisted string map (used for diff on next save).
	const MAP_OPTION = 'njt_nofi_wpml_string_map';

	/** @var WpmlBridge|null */
	private static $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * @return WpmlBridge
	 */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — registers hooks.
	 */
	private function __construct() {
		add_action( 'customize_save_after', [ $this, 'onSave' ] );
		add_filter( 'njt_nofi_resolve_strings', [ $this, 'resolveStrings' ] );
	}

	// -------------------------------------------------------------------------
	// Hook: customize_save_after
	// -------------------------------------------------------------------------

	/**
	 * Diff bar strings against previously registered map; register new/changed
	 * strings, unregister strings whose bars were deleted, persist new map.
	 *
	 * Silent no-op when WPML + ST addon are not active.
	 *
	 * @param \WP_Customize_Manager $manager WP Customizer manager (unused, but
	 *                                        required by hook signature).
	 * @return void
	 */
	public function onSave( \WP_Customize_Manager $manager ): void {
		if ( ! $this->isWpmlActive() ) {
			return;
		}

		$raw  = get_option( 'njt_nofi_bars', '[]' );
		$bars = json_decode( $raw, true );
		$bars = is_array( $bars ) ? $bars : [];

		$newMap = BarTranslatableStrings::collect( $bars );
		$oldMap = get_option( self::MAP_OPTION, [] );
		if ( ! is_array( $oldMap ) ) {
			$oldMap = [];
		}

		$this->syncStrings( $newMap, $oldMap );

		// Persist new map for next diff (autoload=false — can grow with many bars).
		update_option( self::MAP_OPTION, $newMap, false );
	}

	// -------------------------------------------------------------------------
	// Hook: njt_nofi_resolve_strings filter
	// -------------------------------------------------------------------------

	/**
	 * Replace each bar's translatable string fields with WPML-translated values
	 * for the current locale.
	 *
	 * Falls back to the original string when no translation exists — safe.
	 * Returns $bars unchanged (same shape) when WPML is not active.
	 *
	 * @param  array $bars Decoded bars array from theme_mod.
	 * @return array Bars array with translated string fields substituted.
	 */
	public function resolveStrings( array $bars ): array {
		if ( ! $this->isWpmlActive() ) {
			return $bars;
		}

		// Field schema + walk live in BarTranslatableStrings (shared with
		// PolylangBridge). translate() falls back to the original when WPML has
		// no translation for the current locale — safe.
		return BarTranslatableStrings::apply(
			$bars,
			[ $this, 'translate' ]
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Register new / changed strings and unregister removed ones.
	 *
	 * Diff strategy:
	 *   - Present in newMap → register (WPML dedupes identical value → noop).
	 *   - Present in oldMap but absent in newMap → unregister (bar was deleted).
	 *
	 * @param  array $newMap Current string map (name => value).
	 * @param  array $oldMap Previously persisted map (name => value).
	 * @return void
	 */
	private function syncStrings( array $newMap, array $oldMap ): void {
		// Register new or changed strings.
		foreach ( $newMap as $name => $value ) {
			if ( ! isset( $oldMap[ $name ] ) || $oldMap[ $name ] !== $value ) {
				do_action( 'wpml_register_single_string', self::DOMAIN, $name, $value );
			}
		}

		// Unregister strings whose bars were deleted.
		foreach ( $oldMap as $name => $value ) {
			if ( ! isset( $newMap[ $name ] ) ) {
				do_action( 'wpml_unregister_single_string', self::DOMAIN, $name );
			}
		}
	}

	/**
	 * Resolve one string via WPML for the current locale.
	 *
	 * Falls back to $original if no translation is available.
	 *
	 * Public because BarTranslatableStrings::apply() invokes it as a callback.
	 *
	 * @param  string $name     WPML string name (e.g. "bar-{id}-text").
	 * @param  string $original Original (source-language) string value.
	 * @return string           Translated value, or $original if untranslated.
	 */
	public function translate( string $name, string $original ): string {
		return (string) apply_filters(
			'wpml_translate_single_string',
			$original,
			self::DOMAIN,
			$name
		);
	}

	/**
	 * Detect WPML core + String Translation addon presence.
	 *
	 * Both must be active:
	 *   - ICL_SITEPRESS_VERSION — defined by WPML core plugin.
	 *   - WPML_ST_VERSION       — defined by WPML String Translation addon.
	 *
	 * `defined(WPML_ST_VERSION)` is more reliable than the legacy
	 * `has_action('wpml_register_single_string')` probe because the constant
	 * is set unconditionally on plugin load, while the action callback may
	 * register at different priorities across WPML versions.
	 *
	 * Returns false silently when either is missing (no admin notice per
	 * resolved decision #4 in plan.md).
	 *
	 * @return bool
	 */
	private function isWpmlActive(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' )
			&& defined( 'WPML_ST_VERSION' );
	}
}
