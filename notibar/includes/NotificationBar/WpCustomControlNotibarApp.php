<?php
/**
 * Custom Customizer control that renders the React SPA mount node.
 *
 * The SPA itself is enqueued by AssetLoader::enqueue_customizer_controls()
 * on the customize_controls_enqueue_scripts hook. This control only outputs
 * the #njt-notibar-app div so the SPA has a stable mount target.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class WpCustomControlNotibarApp
 *
 * Bound to the njt_nofi_bars setting; reads/writes njt_nofi_global from JS
 * via wp.customize('njt_nofi_global').set(...).
 */
class WpCustomControlNotibarApp extends \WP_Customize_Control {

	/** @var string Control type identifier. */
	public $type = 'njt-notibar-app';

	/**
	 * Output the control's HTML content — only the SPA mount node.
	 *
	 * Scripts are enqueued separately via AssetLoader, not here.
	 *
	 * @return void
	 */
	public function render_content(): void {
		echo '<div id="njt-notibar-app"></div>';
	}
}
