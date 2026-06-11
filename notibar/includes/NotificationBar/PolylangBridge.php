<?php
/**
 * Polylang String Translation bridge — Notibar v3.1.4.
 *
 * Mirrors WpmlBridge but adapts to Polylang's different string model:
 *
 *   WPML ST                          Polylang
 *   ---------------------------      ---------------------------------------
 *   persisted registry, register     registry rebuilt every request — must
 *   once on save                     re-register on each load (we hook init)
 *   has unregister API               NO unregister API — stale strings simply
 *                                    stop being registered and drop from the UI
 *   resolve by (domain, name)        resolve by VALUE via pll__()
 *
 * Because Polylang resolves by string value (not by name), two bars that share
 * the exact same text also share a single translation entry. This is an
 * inherent Polylang limitation, documented in readme.txt "Multilingual
 * support". The string NAME (bar-{id}-…) is still passed to pll_register_string
 * so translators get a meaningful label in Languages → Strings translations.
 *
 * Registration runs in admin only — that is where the Strings-translations UI
 * lives. Front-end pll__() lookups resolve from Polylang's stored translations
 * and do not require the string to be re-registered in the same request, so we
 * avoid the per-request registration cost on every visitor page view.
 *
 * Silent no-op when Polylang is not active — all hooks short-circuit via
 * isPolylangActive(). No admin notices (parity with WpmlBridge).
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.4
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class PolylangBridge
 *
 * Singleton. Registers its own hooks in the constructor.
 *
 * Hook surface:
 *   init                      → registerStrings() — declare bar strings (admin).
 *   njt_nofi_resolve_strings  → resolveStrings()  — substitute translated values.
 */
class PolylangBridge {

	// Polylang string-translation group (the label under Languages → Strings).
	const GROUP = 'Notibar';

	/** @var PolylangBridge|null */
	private static $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * @return PolylangBridge
	 */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — registers hooks.
	 *
	 * Bails before hooking when the Polylang string API is unavailable, so the
	 * bridge is a true no-op on non-Polylang sites.
	 */
	private function __construct() {
		if ( ! $this->isPolylangActive() ) {
			return; // Polylang not active — nothing to do.
		}

		// Re-declare strings each admin load — Polylang rebuilds its registry per
		// request. pll_register_string is dedupe-safe (keyed by value+group).
		add_action( 'init', [ $this, 'registerStrings' ] );

		// Resolve translations at render (front-end + preview).
		add_filter( 'njt_nofi_resolve_strings', [ $this, 'resolveStrings' ] );
	}

	// -------------------------------------------------------------------------
	// Hook: init — register translatable strings for the admin Strings UI
	// -------------------------------------------------------------------------

	/**
	 * Register every bar's translatable strings with Polylang.
	 *
	 * Admin-only: the registry is consumed by the Strings-translations screen.
	 * Reads the live bars option each load, so deleted bars naturally fall out
	 * of the list (Polylang has no unregister API — this is how stale strings
	 * are pruned from the UI).
	 *
	 * @return void
	 */
	public function registerStrings(): void {
		if ( ! is_admin() || ! $this->isPolylangActive() ) {
			return;
		}

		// WPML wins when both plugins are active (see resolveStrings) — don't
		// pollute Polylang's Strings UI with entries that won't be used.
		if ( $this->isWpmlActive() ) {
			return;
		}

		$bars = $this->readBars();

		foreach ( BarTranslatableStrings::collect( $bars ) as $name => $value ) {
			// URLs single-line; text/labels multiline so the UI shows a textarea.
			$multiline = ! preg_match( '/Url(Mobile)?$/', $name );
			// pll_register_string is idempotent per (name+value+group); re-running
			// each load with the current values is a no-op, and an edited value
			// simply replaces the prior entry for that name.
			pll_register_string( $name, $value, self::GROUP, $multiline );
		}
	}

	// -------------------------------------------------------------------------
	// Hook: njt_nofi_resolve_strings filter
	// -------------------------------------------------------------------------

	/**
	 * Replace each bar's translatable string fields with the Polylang
	 * translation for the current language.
	 *
	 * Returns $bars unchanged when Polylang is not active.
	 *
	 * @param  array $bars Decoded bars array from the bars option.
	 * @return array       Bars array with translated string fields substituted.
	 */
	public function resolveStrings( array $bars ): array {
		if ( ! $this->isPolylangActive() ) {
			return $bars;
		}

		// When both WPML and Polylang are installed, WpmlBridge already resolved
		// these fields via its own filter callback. Re-running pll__() on the
		// WPML-translated values would mis-resolve them — let WPML win.
		if ( $this->isWpmlActive() ) {
			return $bars;
		}

		// No active language yet (e.g. WP-Cron, or a request Polylang hasn't
		// language-resolved). pll__() would just echo the source string, so skip
		// the walk entirely.
		if ( ! function_exists( 'pll_current_language' ) || ! pll_current_language() ) {
			return $bars;
		}

		return BarTranslatableStrings::apply(
			$bars,
			[ $this, 'translate' ]
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve one string via Polylang for the current language.
	 *
	 * Polylang resolves by VALUE (pll__), not by name, so $name is ignored here
	 * — it is meaningful only at registration time. pll__() returns the original
	 * string when no translation exists, so the fallback is safe.
	 *
	 * Public because BarTranslatableStrings::apply() invokes it as a callback.
	 *
	 * @param  string $name     String name (unused by Polylang resolution).
	 * @param  string $original Original (source-language) string value.
	 * @return string           Translated value, or $original if untranslated.
	 */
	public function translate( string $name, string $original ): string {
		return (string) pll__( $original );
	}

	/**
	 * Read and decode the bars option into an array.
	 *
	 * @return array Decoded bars, or [] when missing/invalid.
	 */
	private function readBars(): array {
		$bars = json_decode( get_option( 'njt_nofi_bars', '[]' ), true );
		return is_array( $bars ) ? $bars : [];
	}

	/**
	 * Detect Polylang presence via its string-translation API.
	 *
	 * Both functions ship with Polylang core (free + Pro); checking the
	 * functions rather than a version constant keeps this resilient across
	 * Polylang releases.
	 *
	 * @return bool
	 */
	private function isPolylangActive(): bool {
		return function_exists( 'pll_register_string' )
			&& function_exists( 'pll__' );
	}

	/**
	 * Detect WPML core + String Translation addon presence.
	 *
	 * Used for mutual exclusion: when both engines are active WpmlBridge takes
	 * precedence and Polylang stands down to avoid double-translating. Matches
	 * the detection in WpmlBridge::isWpmlActive().
	 *
	 * @return bool
	 */
	private function isWpmlActive(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' )
			&& defined( 'WPML_ST_VERSION' );
	}
}
