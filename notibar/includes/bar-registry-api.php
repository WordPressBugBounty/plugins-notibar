<?php
/**
 * Public procedural API for 3rd-party notification-bar registration.
 *
 * Loaded at plugin bootstrap so the helper is defined before integrators run
 * (e.g. on `init`). Thin wrapper over BarRegistry — see that class for the
 * additive-only / native-protection / Lite-Pro-gating guarantees.
 *
 * @package NjtNotificationBar
 * @since   3.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'njt_nofi_register_bar' ) ) {
	/**
	 * Declare a notification bar from a 3rd-party plugin or theme.
	 *
	 * The bar joins Notibar's render pipeline and inherits its rendering,
	 * scheduling, display rules, dismissal, rotation, and arrows. Declared bars
	 * are code-owned: they are NOT editable in the Customizer and NOT included in
	 * export/import. They can never remove or alter the admin's native bars.
	 *
	 * @param array $bar Bar definition. May be partial — missing fields are
	 *                   filled from Schema::defaultBar(). MUST include a stable,
	 *                   unique string `id` ([A-Za-z0-9_-], e.g. 'acme-sale-2026');
	 *                   bars without a usable id are skipped. Minimal example:
	 *                   [ 'id' => 'acme-sale', 'content' => [ 'text' => 'Sale!' ] ].
	 * @return void
	 */
	function njt_nofi_register_bar( array $bar ): void {
		\NjtNotificationBar\NotificationBar\BarRegistry::add( $bar );
	}
}
