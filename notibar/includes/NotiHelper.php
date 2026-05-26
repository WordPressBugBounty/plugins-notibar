<?php
/**
 * NotiHelper — legacy v2 helper class.
 *
 * This class was used in v2.x to check cookie-based dismissal state against
 * legacy flat theme_mods. In v3.0 all dismissal logic is handled client-side
 * by the frontend JS bundle. This file is retained for autoloader compatibility
 * and will be removed in v3.1.
 *
 * @package NjtNotificationBar
 * @deprecated 3.0.0 Dismissal is now client-side. No methods should be called.
 */

namespace NjtNotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class NotiHelper
 *
 * @deprecated 3.0.0
 */
class NotiHelper {

	/** @var NotiHelper|null */
	protected static $instance = null;

	/** @return NotiHelper */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @deprecated 3.0.0 Dismissal is handled client-side in v3.
	 * @return bool Always false in v3.
	 */
	public static function is_hide_notibar_with_cookie(): bool {
		return false;
	}
}
