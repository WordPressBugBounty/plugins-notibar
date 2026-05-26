<?php
/**
 * Customizer registration — Notibar v3 thin shell.
 *
 * Hosts the v3 Customizer panel/section/setting/control registration.
 * Will be renamed to CustomizerRegistrar in v3.1.
 *
 * Registers:
 *   - Section: njt_nofi_bars_section ("Notibar", top-level, priority 160)
 *   - Setting: njt_nofi_bars   (theme_mod, postMessage, Schema::sanitizeBars)
 *   - Setting: njt_nofi_global (theme_mod, postMessage, Schema::sanitizeGlobal)
 *   - Control: WpCustomControlNotibarApp (SPA mount div)
 *
 * NOTE: v3.0 wrapped this in a Panel; v3.1 flattened to a single section
 * to eliminate a wasted nav-hop (panel hosted only one section).
 *
 * Legacy v2 panel/section/settings/controls and all 12 WpCustomControl* class
 * files have been removed in v3.0.0 (phase-09 cleanup).
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class WpCustomNotification
 *
 * Singleton. Hooks into customize_register to register the v3 panel/section/settings/control.
 */
class WpCustomNotification {

	/** @var WpCustomNotification|null */
	protected static $instance = null;

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return WpCustomNotification
	 */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — wires Customizer hooks.
	 */
	private function __construct() {
		add_action( 'customize_register', [ $this, 'register' ], 10 );
	}

	/**
	 * Register the v3 Customizer panel, section, settings, and App control.
	 *
	 * @param \WP_Customize_Manager $wp_customize WP Customizer manager instance.
	 * @return void
	 */
	public function register( \WP_Customize_Manager $wp_customize ): void {

		// -----------------------------------------------------------------
		// Section (v3.1 — flattened; the prior panel hosted only one
		// section so the panel-level click was a wasted hop. Section pinned
		// to the very top of the Customizer sidebar — WP core's topmost
		// "Site Identity" section sits at priority 20, so priority 1 sorts
		// above every built-in section.)
		// -----------------------------------------------------------------
		$wp_customize->add_section( 'njt_nofi_bars_section', [
			'title'    => __( 'Notibar', 'notibar' ),
			'priority' => 1,
		] );

		// -----------------------------------------------------------------
		// Settings
		// -----------------------------------------------------------------
		// v3.2 — type=option (was theme_mod) so settings persist across theme switches.
		// Migration::maybeMigrateThemeModToOption() copies prior theme_mod data on upgrade.
		$wp_customize->add_setting( 'njt_nofi_bars', [
			'type'              => 'option',
			'default'           => wp_json_encode( [ Schema::defaultBar() ] ),
			'sanitize_callback' => [ Schema::class, 'sanitizeBars' ],
			'transport'         => 'postMessage',
			'capability'        => 'edit_theme_options',
		] );

		$wp_customize->add_setting( 'njt_nofi_global', [
			'type'              => 'option',
			'default'           => wp_json_encode( Schema::defaultGlobal() ),
			'sanitize_callback' => [ Schema::class, 'sanitizeGlobal' ],
			'transport'         => 'postMessage',
			'capability'        => 'edit_theme_options',
		] );

		// -----------------------------------------------------------------
		// Control — SPA mount point (both settings managed from JS)
		// -----------------------------------------------------------------
		require_once __DIR__ . '/WpCustomControlNotibarApp.php';

		$wp_customize->add_control( new WpCustomControlNotibarApp(
			$wp_customize,
			'njt_notibar_app',
			[
				'section'  => 'njt_nofi_bars_section',
				'settings' => [ 'njt_nofi_bars', 'njt_nofi_global' ],
			]
		) );
	}

}
