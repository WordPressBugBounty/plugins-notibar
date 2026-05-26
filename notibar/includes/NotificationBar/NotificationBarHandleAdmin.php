<?php
/**
 * Admin-side features for NotificationBarHandle.
 *
 * Contains: admin menu wiring, review notice, save-review AJAX,
 * review JS enqueue, action-links filter, notification-settings stub.
 *
 * Extracted from NotificationBarHandle to keep each file ≤ 200 LOC.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Trait NotificationBarHandleAdmin
 */
trait NotificationBarHandleAdmin {

	/** @var string[] */
	private $hook_suffix = [];

	/**
	 * Register the Notibar top-level admin menu with two submenus.
	 *
	 *   Notibar
	 *     ├── Customize → URL-hacked to customize.php?autofocus[section]=njt_nofi_bars_section
	 *     └── Settings  → admin.php?page=notibar-settings (renders settings-app React mount)
	 *
	 * First submenu slug equals parent slug so WP collapses the duplicate label —
	 * the standard pattern for top-level menus.
	 *
	 * @return void
	 */
	public function njt_nofi_showMenu(): void {
		global $submenu;

		add_menu_page(
			__( 'Notibar', 'notibar' ),
			__( 'Notibar', 'notibar' ),
			'manage_options',
			'notibar-customize',
			[ $this, 'njt_nofi_renderCustomizeStub' ],
			'dashicons-bell',
			25
		);

		add_submenu_page(
			'notibar-customize',
			__( 'Customize', 'notibar' ),
			__( 'Customize', 'notibar' ),
			'manage_options',
			'notibar-customize',
			[ $this, 'njt_nofi_renderCustomizeStub' ]
		);

		$settings_suffix = add_submenu_page(
			'notibar-customize',
			__( 'Settings', 'notibar' ),
			__( 'Settings', 'notibar' ),
			'manage_options',
			'notibar-settings',
			[ $this, 'njt_nofi_renderSettings' ]
		);

		// Lite only — "Go Pro" upsell submenu (Free vs Pro table + CTA). Pro
		// users never see it. Gated on the edition flag, not stripped, so the
		// page code can live in both builds.
		if ( ! ( defined( 'NJT_NOFI_IS_PRO' ) && NJT_NOFI_IS_PRO ) ) {
			add_submenu_page(
				'notibar-customize',
				__( 'Go Pro', 'notibar' ),
				__( 'Go Pro', 'notibar' ),
				'manage_options',
				GoProPage::PAGE_SLUG,
				[ GoProPage::class, 'render' ]
			);
			add_action( 'admin_head', [ GoProPage::class, 'menuHighlightCss' ] );
		}

		// URL-hack the Customize submenu entry to deep-link into Customizer
		// with the Notibar panel auto-focused. WP follows the entry's index-2
		// URL when present, so the renderCustomizeStub callback never fires.
		$url_encode = urlencode( 'autofocus[section]' );
		$link       = esc_url( admin_url( '/customize.php?' . $url_encode . '=njt_nofi_bars_section' ) );

		if ( isset( $submenu['notibar-customize'] ) ) {
			foreach ( $submenu['notibar-customize'] as $k => $item ) {
				if ( $item[2] === 'notibar-customize' ) {
					$submenu['notibar-customize'][ $k ][2] = $link; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				}
			}
		}

		// add_submenu_page returns false when current user lacks capability —
		// guard so the hook_suffix consumers (review notice / Phase 02 enqueue
		// gate) never see a literal false in the array.
		if ( $settings_suffix ) {
			$this->hook_suffix = [ $settings_suffix ];
		}
	}

	/**
	 * @param array $links
	 * @return array
	 */
	public function addActionLinks( array $links ): array {
		$url_encode = urlencode( 'autofocus[section]' );
		$link_url   = esc_url( admin_url( '/customize.php?' . $url_encode . '=njt_nofi_bars_section' ) );

		$prepend = [ '<a href="' . $link_url . '">' . __( 'Settings', 'notibar' ) . '</a>' ];

		// Lite-only "Go Pro" action link (green). Runtime-gated on the edition
		// flag — the link ships in both builds but only renders in Lite (Pro
		// users have no upsell).
		if ( ! ( defined( 'NJT_NOFI_IS_PRO' ) && NJT_NOFI_IS_PRO ) ) {
			$upgrade   = defined( 'NJT_NOFI_UPGRADE_URL' ) ? NJT_NOFI_UPGRADE_URL : '';
			$prepend[] = '<a href="' . esc_url( $upgrade ) . '" target="_blank" rel="noopener noreferrer" style="color:#46b450;font-weight:700;">'
				. __( 'Go Pro', 'notibar' ) . '</a>';
		}

		return array_merge( $prepend, $links );
	}

	/**
	 * Customize submenu callback — never reached at runtime because the
	 * URL hack in njt_nofi_showMenu() redirects clicks to customize.php.
	 * Defensive stub kept so direct hits to admin.php?page=notibar-customize
	 * (e.g. before the $submenu hack runs) terminate cleanly rather than
	 * rendering an empty admin shell.
	 *
	 * @return void
	 */
	public function njt_nofi_renderCustomizeStub(): void {
		$url_encode = urlencode( 'autofocus[section]' );
		wp_safe_redirect( admin_url( 'customize.php?' . $url_encode . '=njt_nofi_bars_section' ) );
		exit;
	}

	/**
	 * Settings submenu callback — prints the React mount node. The
	 * settings-app bundle is enqueued by AssetLoader::enqueue_settings_app()
	 * (phase 02) on this page's hook suffix.
	 *
	 * @return void
	 */
	public function njt_nofi_renderSettings(): void {
		echo '<div class="wrap"><div id="njt-notibar-settings-app"></div></div>';
	}
}
