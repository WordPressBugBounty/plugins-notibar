<?php

defined( 'ABSPATH' ) || exit;

/**
 * Ads Toggle — orchestrator.
 *
 * ONE TOGGLE PER CONSUMER: each consumer plugin registers which ad-module slugs it bundles via
 * \YayRecommendedModules\Registry::register_ads_consumer( $consumer_slug, $ad_slug ) (called
 * after requiring recommended-modules/loader.php), but the on/off STATE is stored per
 * consumer_slug only — a single switch controls every ad module that consumer registered
 * together. A different consumer bundling the same ad_slug has its own independent switch.
 * njt_ads_toggle_is_enabled( $ad_slug ) (functions.php) ORs across every registered consumer's
 * switch — the ad only fully disappears once ALL of its registered consumers have toggled off.
 *
 * AJAX validates consumer_slug against Registry::is_ads_consumer() (never trust an arbitrary
 * slug from the client — same convention as edd-license-manager's AJAX handler validating
 * against its own registered config array).
 */
if ( ! class_exists( 'NjtAdsToggle' ) ) {
	class NjtAdsToggle {

		const NONCE     = 'njt_ads_toggle_nonce';
		const ASSET_VER = '1.5.1'; // asset cache-buster; bump with register.php

		private static $instance = null;

		/** @var bool Guards init() so hooks register exactly once. */
		private $booted = false;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/** Register WordPress hooks. Idempotent — safe to call more than once. */
		public function init() {
			if ( $this->booted ) {
				return;
			}
			$this->booted = true;
			add_action( 'wp_ajax_njt_ads_toggle_update', [ $this, 'handle_update' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}

		/**
		 * AJAX: flip a consumer's single ads switch on/off.
		 * Verify nonce → capability → resolve consumer_slug against Registry::is_ads_consumer()
		 * (rejects arbitrary slugs so a caller can't write arbitrary njt_ads_toggle_* options).
		 */
		public function handle_update() {
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'filebird' ) ], 403 );
			}

			$consumer_slug = isset( $_POST['consumer_slug'] ) ? sanitize_key( wp_unslash( $_POST['consumer_slug'] ) ) : '';

			if ( '' === $consumer_slug || ! \YayRecommendedModules\Registry::is_ads_consumer( $consumer_slug ) ) {
				wp_send_json_error( [ 'message' => __( 'Unknown consumer.', 'filebird' ) ] );
			}

			$enabled = filter_var( wp_unslash( $_POST['enabled'] ?? '' ), FILTER_VALIDATE_BOOLEAN );
			// Store '1'/'0' (never a raw boolean) — see functions.php for why: update_option()
			// treats a new value of `false` as equal to its own "not found" sentinel and silently
			// skips the write on the very first toggle-off.
			update_option( njt_ads_toggle_option_name( $consumer_slug ), $enabled ? '1' : '0', false );

			wp_send_json_success( [
				'consumer_slug' => $consumer_slug,
				'enabled'       => $enabled,
			] );
		}

		/** Enqueue the toggle-widget script on every admin page (module doesn't know in advance
		 *  which page a caller will render the toggle from). */
		public function enqueue_assets() {
			$url = plugin_dir_url( __FILE__ );

			wp_register_script( 'njt-ads-toggle', $url . 'assets/js/ads-toggle.js', [ 'jquery' ], self::ASSET_VER, true );
			wp_localize_script( 'njt-ads-toggle', 'NjtAdsToggle', [
				'nonce' => wp_create_nonce( self::NONCE ),
			] );
			wp_enqueue_script( 'njt-ads-toggle' );

			wp_enqueue_style( 'njt-ads-toggle', $url . 'assets/css/ads-toggle.css', [], self::ASSET_VER );
		}
	}
}
