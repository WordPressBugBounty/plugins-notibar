<?php
/**
 * Polylang support — STUB in Notibar v3.0.
 *
 * Per-bar string registration via pll_register_string() is documented here
 * as a design sketch but NOT auto-wired. Polylang does not provide a
 * per-string unregister API, and its string resolution model (pll__() /
 * pll_e()) differs significantly from WPML ST. Full integration is
 * deferred to v3.1+ if user demand emerges.
 *
 * Readme caveat: see readme.txt "Multilingual support" section (added in
 * phase-09).
 *
 * HOW A PRODUCTION IMPLEMENTATION WOULD WORK
 * -------------------------------------------
 * 1. Hook customize_save_after.
 * 2. Iterate bars → build the same 6-string-per-bar map as WpmlBridge.
 * 3. Call pll_register_string( $name, $value, 'Notibar' ) for each entry.
 *    NOTE: Polylang has no unregister API; removed strings leak in the
 *    Polylang string table until manually cleared. Document this caveat.
 * 4. Hook njt_nofi_resolve_strings filter.
 * 5. For each bar field, resolve via pll__( $value ) — but pll__() does
 *    not accept a string name key; it looks up by value in the registered
 *    table. This is lossy when the same text appears in multiple bars.
 *    A keyed approach would require pll_translate_string( $name, pll_current_language() )
 *    if available in the installed Polylang version.
 * 6. Return the mutated bars array.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 * @todo    v3.1+ — implement if user demand warrants it.
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class PolylangBridge
 *
 * Documented stub — no hooks registered, no behaviour.
 * Instantiating this class is a deliberate no-op.
 */
class PolylangBridge {

	/** @var PolylangBridge|null */
	private static $instance = null;

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
	 * Constructor — short-circuits immediately when Polylang is not active.
	 * Even if Polylang IS active, no hooks are registered (stub in v3.0).
	 */
	private function __construct() {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return; // Polylang not active — nothing to do.
		}

		// TODO (v3.1+): register hooks here when implementing full Polylang support.
		// See file-level docblock for the integration design sketch.
	}
}
