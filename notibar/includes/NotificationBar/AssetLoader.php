<?php
/**
 * Asset enqueue helper — registers and enqueues all Notibar v3 JS/CSS bundles.
 *
 * Reads build/*.asset.php manifests produced by @wordpress/scripts for
 * dependency arrays and cache-busting version hashes.
 *
 * Hooks registered here:
 *   - customize_controls_enqueue_scripts → customizer controls SPA
 *   - customize_preview_init             → customizer preview script
 *   - wp_enqueue_scripts                 → frontend runtime (gated in phase-07)
 *   - admin_enqueue_scripts              → settings-app on ?page=notibar-settings
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class AssetLoader
 *
 * Singleton. Registers WP action hooks on instantiation.
 * Call AssetLoader::get_instance() once from the plugin bootstrap.
 */
class AssetLoader {

	/**
	 * Hook suffix produced by WP for the Settings submenu.
	 *
	 * Pattern: '{sanitize_title(parent_menu_title)}_page_{submenu_slug}'.
	 * For our menu titled "Notibar" with submenu slug "notibar-settings",
	 * WP populates $admin_page_hooks['notibar-customize'] = sanitize_title('Notibar') = 'notibar',
	 * then get_plugin_page_hookname concatenates → 'notibar_page_notibar-settings'.
	 *
	 * Keep in sync if either the menu TITLE (not the slug) or the submenu
	 * slug changes in NotificationBarHandleAdmin::njt_nofi_showMenu().
	 */
	const SETTINGS_HOOK_SUFFIX = 'notibar_page_notibar-settings';

	/** @var AssetLoader|null */
	protected static $instance = null;

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return AssetLoader
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — registers action hooks.
	 */
	private function __construct() {
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_customizer_controls' ) );
		add_action( 'customize_preview_init',             array( $this, 'enqueue_customizer_preview' ) );
		add_action( 'wp_enqueue_scripts',                 array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts',              array( $this, 'enqueue_settings_app' ) );
	}

	// -------------------------------------------------------------------------
	// Enqueue methods
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the Customizer controls SPA bundle.
	 *
	 * Loaded in the Customizer controls pane (wp-admin side).
	 * The SPA mounts into #njt-notibar-app (rendered by WpCustomControlNotibarApp).
	 * Boot data is inlined before the bundle via wp_add_inline_script.
	 *
	 * @return void
	 */
	public function enqueue_customizer_controls(): void {
		$asset = $this->load_asset_manifest( 'customizer-app' );
		if ( null === $asset ) {
			return;
		}

		wp_enqueue_script(
			'njt-notibar-customizer-app',
			NJT_NOFI_PLUGIN_URL . 'build/customizer-app.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Inline boot data before the bundle so window.njtNotibarBoot is available.
		$boot_data = $this->get_boot_data();
		wp_add_inline_script(
			'njt-notibar-customizer-app',
			'window.njtNotibarBoot = ' . wp_json_encode( $boot_data ) . ';',
			'before'
		);

		// Enqueue companion stylesheet if the build produced one.
		$css_path = NJT_NOFI_PLUGIN_PATH . 'build/customizer-app.css';
		if ( file_exists( $css_path ) ) {
			// Declare wp-components as a style dependency. WP 7.0+ stopped
			// auto-loading the wp-components stylesheet in the Customizer
			// controls pane (it now only auto-loads in the block editor),
			// so without this dependency our ToggleControl / DropdownMenu
			// / DateTimePicker / CheckboxControl render unstyled.
			wp_enqueue_style(
				'njt-notibar-customizer-app',
				NJT_NOFI_PLUGIN_URL . 'build/customizer-app.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}

	/**
	 * Build the SPA boot data array localized before the bundle.
	 *
	 * colorPresets: ported from legacy WpCustomControlColorPreset data-value attributes.
	 * Format per entry: { bg, btnBg, text, btnText, name? } — same order as legacy
	 * arrColor[0..3]. The optional `name` is shown as the swatch tooltip; legacy
	 * presets without one fall back to a numbered "Preset N" label in the UI.
	 *
	 * @return array
	 */
	private function get_boot_data(): array {
		// Role list for the per-bar audience picker (Pro). [{slug,label}].
		$role_names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : [];
		$roles      = [];
		foreach ( $role_names as $slug => $label ) {
			$roles[] = [
				'slug'  => $slug,
				'label' => function_exists( 'translate_user_role' ) ? translate_user_role( $label ) : $label,
			];
		}

		return [
			'restRoot'     => esc_url_raw( rest_url( 'notibar/v1' ) ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'isPro'        => defined( 'NJT_NOFI_IS_PRO' ) ? (bool) NJT_NOFI_IS_PRO : true,
			'upgradeUrl'   => defined( 'NJT_NOFI_UPGRADE_URL' ) ? NJT_NOFI_UPGRADE_URL : '',
			'roles'        => $roles,
			'defaultBar'   => Schema::defaultBar(),
			'defaultGlobal' => Schema::defaultGlobal(),
			'colorPresets' => [
				[ 'bg' => '#9af4cf', 'btnBg' => '#1919cf', 'text' => '#1919cf', 'btnText' => '#ffffff' ],
				[ 'bg' => '#fff799', 'btnBg' => '#1919cf', 'text' => '#e65100', 'btnText' => '#ffffff' ],
				[ 'bg' => '#212121', 'btnBg' => '#dd2c00', 'text' => '#ffffff', 'btnText' => '#ffffff' ],
				[ 'bg' => '#ffffff', 'btnBg' => '#212121', 'text' => '#212121', 'btnText' => '#ffffff' ],
				[ 'bg' => '#d50000', 'btnBg' => '#43a047', 'text' => '#ffffff', 'btnText' => '#ffffff' ],
				[ 'bg' => '#2962ff', 'btnBg' => '#ffffff', 'text' => '#ffffff', 'btnText' => '#0288D1' ],
				[ 'bg' => '#18ffff', 'btnBg' => '#ffffff', 'text' => '#1919cf', 'btnText' => '#1976D2' ],
				[ 'bg' => '#78909c', 'btnBg' => '#ff5722', 'text' => '#ffffff', 'btnText' => '#ffffff' ],
				// Curated tone palette (fashion, cosmetics, footwear, jewelry, tech).
				[ 'bg' => '#EFE7DD', 'btnBg' => '#6F5846', 'text' => '#4A3B2F', 'btnText' => '#F6F0E8', 'name' => 'Coffee' ],
				[ 'bg' => '#F3E4DA', 'btnBg' => '#C16E4E', 'text' => '#6B3A2A', 'btnText' => '#FBF2EC', 'name' => 'Terracotta' ],
				[ 'bg' => '#E3D5C8', 'btnBg' => '#8B6F58', 'text' => '#3E2F25', 'btnText' => '#F4EDE4', 'name' => 'Mocha' ],
				[ 'bg' => '#F4ECDD', 'btnBg' => '#C2A66A', 'text' => '#5A4A2E', 'btnText' => '#3A2E18', 'name' => 'Champagne' ],
				[ 'bg' => '#F7E9E7', 'btnBg' => '#D9A7A2', 'text' => '#6E4A4A', 'btnText' => '#4A2E2E', 'name' => 'Blush' ],
				[ 'bg' => '#E6E8DD', 'btnBg' => '#8A9275', 'text' => '#43483A', 'btnText' => '#F2F3EC', 'name' => 'Sage' ],
				[ 'bg' => '#EFE3E4', 'btnBg' => '#B08A9A', 'text' => '#5A4450', 'btnText' => '#F5EEF0', 'name' => 'Rosé' ],
				[ 'bg' => '#EDE3D3', 'btnBg' => '#B79B76', 'text' => '#5C4A33', 'btnText' => '#F4EDE0', 'name' => 'Nude Sand' ],
				[ 'bg' => '#E4E9EC', 'btnBg' => '#93AAB5', 'text' => '#3C4A52', 'btnText' => '#F1F5F6', 'name' => 'Powder Blue' ],
				[ 'bg' => '#F0E2D0', 'btnBg' => '#B97E47', 'text' => '#5E3D22', 'btnText' => '#FBF1E6', 'name' => 'Caramel' ],
				[ 'bg' => '#EAE6F0', 'btnBg' => '#A498BE', 'text' => '#4A4256', 'btnText' => '#F4F1F8', 'name' => 'Lavender' ],
				[ 'bg' => '#E8E3D6', 'btnBg' => '#7E7A5F', 'text' => '#4A4636', 'btnText' => '#F3F1E8', 'name' => 'Olive Taupe' ],
				[ 'bg' => '#F0E4E2', 'btnBg' => '#7A2E3A', 'text' => '#5A2230', 'btnText' => '#F6EAEA', 'name' => 'Burgundy' ],
				[ 'bg' => '#E3EAE5', 'btnBg' => '#2F6B52', 'text' => '#1F3A2E', 'btnText' => '#EFF5F1', 'name' => 'Emerald' ],
				[ 'bg' => '#E9E0D6', 'btnBg' => '#4A3422', 'text' => '#2E2017', 'btnText' => '#F2EBE2', 'name' => 'Espresso' ],
				[ 'bg' => '#ECEAE6', 'btnBg' => '#232220', 'text' => '#1C1C1A', 'btnText' => '#F3F1EC', 'name' => 'Onyx' ],
				[ 'bg' => '#E6E6E8', 'btnBg' => '#3C3C42', 'text' => '#2A2A2E', 'btnText' => '#F0F0F2', 'name' => 'Graphite' ],
				[ 'bg' => '#E5E8EE', 'btnBg' => '#2B3A57', 'text' => '#232B3A', 'btnText' => '#EEF1F6', 'name' => 'Midnight' ],
				[ 'bg' => '#E8EAEC', 'btnBg' => '#4A535C', 'text' => '#2C3137', 'btnText' => '#F1F3F4', 'name' => 'Slate' ],
				[ 'bg' => '#E2ECF2', 'btnBg' => '#3E84A8', 'text' => '#234454', 'btnText' => '#EFF6FA', 'name' => 'Tech Blue' ],
				[ 'bg' => '#FBE8E2', 'btnBg' => '#E07856', 'text' => '#7A3B2C', 'btnText' => '#FFF3EE', 'name' => 'Coral' ],
				[ 'bg' => '#FCE7EE', 'btnBg' => '#D96E97', 'text' => '#7A3252', 'btnText' => '#FFF0F5', 'name' => 'Peony' ],
				[ 'bg' => '#E1EFE9', 'btnBg' => '#4FA089', 'text' => '#1F4A3C', 'btnText' => '#EFF8F4', 'name' => 'Mint' ],
				[ 'bg' => '#EBE3EC', 'btnBg' => '#7C4E86', 'text' => '#4A2E52', 'btnText' => '#F5EEF6', 'name' => 'Plum' ],
				[ 'bg' => '#FCE3F0', 'btnBg' => '#E5267E', 'text' => '#9E1458', 'btnText' => '#FFFFFF', 'name' => 'Fuchsia' ],
				[ 'bg' => '#FFE9D6', 'btnBg' => '#FF6D1F', 'text' => '#9A3D00', 'btnText' => '#FFFFFF', 'name' => 'Tangerine' ],
				[ 'bg' => '#E2E9FF', 'btnBg' => '#2A4BE0', 'text' => '#1A2C8A', 'btnText' => '#FFFFFF', 'name' => 'Cobalt' ],
				[ 'bg' => '#EEF6D4', 'btnBg' => '#7FB800', 'text' => '#3F5400', 'btnText' => '#1E2A00', 'name' => 'Lime' ],
				[ 'bg' => '#FFE3E1', 'btnBg' => '#E5252B', 'text' => '#9E1414', 'btnText' => '#FFFFFF', 'name' => 'Cherry' ],
				[ 'bg' => '#EEE4FF', 'btnBg' => '#7B2FF0', 'text' => '#4A1C9E', 'btnText' => '#FFFFFF', 'name' => 'Violet Pop' ],
				[ 'bg' => '#D8F3EE', 'btnBg' => '#10A99A', 'text' => '#0B5A4F', 'btnText' => '#FFFFFF', 'name' => 'Turquoise' ],
				[ 'bg' => '#FFF3CF', 'btnBg' => '#FFC21F', 'text' => '#7A5400', 'btnText' => '#3A2700', 'name' => 'Sunshine' ],
			],
		];
	}

	/**
	 * Enqueue the Customizer preview script.
	 *
	 * Loaded inside the preview iframe. Depends on the built-in
	 * `customize-preview` script so postMessage transport is ready.
	 *
	 * @return void
	 */
	public function enqueue_customizer_preview(): void {
		$asset = $this->load_asset_manifest( 'customizer-preview' );
		if ( null === $asset ) {
			return;
		}

		// Ensure customize-preview is in the dep list so the iframe transport is ready.
		$dependencies = array_unique(
			array_merge( array( 'customize-preview' ), $asset['dependencies'] )
		);

		wp_enqueue_script(
			'njt-notibar-customizer-preview',
			NJT_NOFI_PLUGIN_URL . 'build/customizer-preview.js',
			$dependencies,
			$asset['version'],
			true
		);

		// NotificationBarHandle::maybeRender() bails inside the preview
		// (is_customize_preview guard), so window.njtNotibarData is never
		// inlined here. Emit the minimal ctx the preview JS needs for
		// theme-compat (registry is keyed by theme display Name), plus the
		// localized countdown labels (Pro) so the preview matches the live site.
		$preview_data = array( 'ctx' => array( 'theme' => wp_get_theme()->get( 'Name' ) ) );
		if ( defined( 'NJT_NOFI_IS_PRO' ) && NJT_NOFI_IS_PRO ) {
			$preview_data['i18n'] = CountdownResolver::labels();
		}
		wp_add_inline_script(
			'njt-notibar-customizer-preview',
			'window.njtNotibarData = window.njtNotibarData || ' . wp_json_encode( $preview_data ) . ';',
			'before'
		);

		// Enqueue the same frontend stylesheet inside the preview iframe so
		// the bar renders identically to the live site. enqueue_frontend()
		// gates on shouldRender() which can return false in the preview
		// (e.g. previewing a page with no matching bar), leaving the iframe
		// without styles — explicit enqueue here guarantees parity.
		wp_enqueue_style(
			'njt-notibar-frontend',
			NJT_NOFI_PLUGIN_URL . 'assets/frontend/css/notibar.css',
			array(),
			$asset['version']
		);
	}

	/**
	 * Enqueue the frontend runtime bundle.
	 *
	 * Gated: only enqueues when NotificationBarHandle::shouldRender() returns
	 * true (i.e. at least one enabled bar passes server-side pre-filter).
	 * The handler also wires wp_add_inline_script('njt-notibar-frontend', ...)
	 * with window.njtNotibarData, so the script handle MUST be registered here
	 * before the footer fires.
	 *
	 * @return void
	 */
	public function enqueue_frontend(): void {
		// Gate: skip if no bars will render on this page request.
		$handle = NotificationBarHandle::getInstance();
		if ( ! $handle->shouldRender() ) {
			return;
		}

		$asset = $this->load_asset_manifest( 'frontend' );
		if ( null === $asset ) {
			return;
		}

		// Register + enqueue the JS bundle (no jQuery dependency — vanilla only).
		wp_register_script(
			'njt-notibar-frontend',
			NJT_NOFI_PLUGIN_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_script( 'njt-notibar-frontend' );

		// Enqueue companion CSS.
		wp_enqueue_style(
			'njt-notibar-frontend',
			NJT_NOFI_PLUGIN_URL . 'assets/frontend/css/notibar.css',
			[],
			$asset['version']
		);

	}

	/**
	 * Enqueue the Settings page SPA bundle.
	 *
	 * Gated on the exact hook suffix produced by add_submenu_page for the
	 * Settings page registered in NotificationBarHandleAdmin::njt_nofi_showMenu()
	 * (parent slug "notibar-customize", submenu slug "notibar-settings").
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_settings_app( string $hook_suffix ): void {
		if ( self::SETTINGS_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset = $this->load_asset_manifest( 'settings-app' );
		if ( null === $asset ) {
			return;
		}

		wp_enqueue_script(
			'njt-notibar-settings-app',
			NJT_NOFI_PLUGIN_URL . 'build/settings-app.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Inline boot data before the bundle so window.njtNotibarSettingsBoot
		// is available when index.js reads it at module top-level.
		$boot_data = [
			'restRoot'  => esc_url_raw( rest_url( 'notibar/v1' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'isPro'     => defined( 'NJT_NOFI_IS_PRO' ) ? (bool) NJT_NOFI_IS_PRO : true,
			'upgradeUrl' => defined( 'NJT_NOFI_UPGRADE_URL' ) ? NJT_NOFI_UPGRADE_URL : '',
			'bars'      => json_decode( get_option( 'njt_nofi_bars', '[]' ), true ) ?: [],
			'siteHost'  => wp_parse_url( home_url(), PHP_URL_HOST ),
		];
		wp_add_inline_script(
			'njt-notibar-settings-app',
			'window.njtNotibarSettingsBoot = ' . wp_json_encode( $boot_data ) . ';',
			'before'
		);

		// Companion stylesheet with explicit wp-components dep (same rationale
		// as enqueue_customizer_controls — WP no longer auto-loads the
		// wp-components stylesheet in classic admin pages).
		$css_path = NJT_NOFI_PLUGIN_PATH . 'build/settings-app.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'njt-notibar-settings-app',
				NJT_NOFI_PLUGIN_URL . 'build/settings-app.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Load and return a build asset manifest array.
	 *
	 * wp-scripts (named-entry mode) generates `build/{entry-name}.asset.php` containing:
	 *   [ 'dependencies' => string[], 'version' => string ]
	 *
	 * e.g. entry 'customizer-app' → build/customizer-app.asset.php
	 *
	 * @param string $entry_name  Webpack entry name, e.g. 'customizer-app'.
	 * @return array{dependencies: string[], version: string}|null
	 *         Returns null if the manifest file does not exist (build not run yet).
	 */
	private function load_asset_manifest( string $entry_name ): ?array {
		$manifest_path = NJT_NOFI_PLUGIN_PATH . 'build/' . $entry_name . '.asset.php';

		if ( ! file_exists( $manifest_path ) ) {
			// Build has not been run — silently skip so legacy plugin still loads.
			return null;
		}

		$asset = require $manifest_path;

		if ( ! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
		) {
			return null;
		}

		return $asset;
	}
}
