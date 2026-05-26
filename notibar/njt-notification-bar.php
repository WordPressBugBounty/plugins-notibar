<?php
/**
 * Plugin Name: Notibar - WordPress Notification Bar
 * Plugin URI: https://ninjateam.org/notibar-wordpress-notification-bar
 * Description: Multiple notification bars with React-powered Customizer editor, live preview, rotation mode, and per-bar display rules.
 * Version: 3.1.0
 * Author: Ninja Team
 * Author URI: https://ninjateam.org
 * Text Domain: notibar
 * Domain Path: /i18n/languages/
 *
 * @package NjtNotificationBar
 */

namespace NjtNotificationBar;

defined('ABSPATH') || exit;

define('NJT_NOFI_PREFIX', 'njt_nofi');
define('NJT_NOFI_VERSION', '3.1.0');

define('NJT_NOFI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NJT_NOFI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('NJT_NOFI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('NJT_NOFI_SITE_URL', site_url());

// Single source for the Pro upgrade destination (Go Pro page, badges, notices).
// Mirrored to the React apps via boot data (AssetLoader).
define('NJT_NOFI_UPGRADE_URL', 'https://ninjateam.org/notibar-wordpress-notification-bar/');

// Edition flag (Pro vs Lite). Defines NJT_NOFI_IS_PRO; Lite build swaps this
// file via build-tools/pro-manifest.json. Drives locked/badged Pro UI in Lite.
require_once __DIR__ . '/includes/edition.php';

spl_autoload_register(function ($class) {
  $prefix = __NAMESPACE__; // project-specific namespace prefix
  $base_dir = __DIR__ . '/includes'; // base directory for the namespace prefix

  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) { // does the class use the namespace prefix?
    return; // no, move to the next registered autoloader
  }

  $relative_class_name = substr($class, $len);

  // replace the namespace prefix with the base directory, replace namespace
  // separators with directory separators in the relative class name, append
  // with .php
  $file = $base_dir . str_replace('\\', '/', $relative_class_name) . '.php';

  if (file_exists($file)) {
    require $file;
  }
});

// Recommeneded-modules module loader (gracefully no-op if recommeneded-modules/ hasn't been synced yet).
if ( file_exists( __DIR__ . '/recommended-modules/loader.php' ) ) {
	require_once __DIR__ . '/recommended-modules/loader.php';
}

$GLOBALS['yay_reviews_plugins'][] = [
	'slug' => 'notibar',
	'name' => 'Notibar - WordPress Notification Bar',
	'option_name' => 'notibar_review',
	'textdomain' => 'notibar',
	'display_time' => 1, // after 3 days
	'review_link' => 'https://wordpress.org/support/plugin/notibar/reviews/#new-post',
	'display_pages' => function (){
    if ( ! function_exists('get_current_screen') ) {
      return false;
    }
		$include_pages = ["notibar_page_notibar-settings", 'notibar_page_notibar-customize'];
		$current_screen_id = get_current_screen()->id;
		
		return in_array($current_screen_id, $include_pages);
	},
];

function init() {
  Plugin::getInstance();
  I18n::loadPluginTextdomain();


  // v3.0 asset enqueue helper.
  NotificationBar\AssetLoader::get_instance();

  // v3.0 Customizer panel/section/settings/control registration.
  NotificationBar\WpCustomNotification::getInstance();

  // v3.0 front-end render gating + admin menu.
  NotificationBar\NotificationBarHandle::getInstance();

  // Phase 08: WPML String Translation bridge — registers/unregisters per-bar
  // strings on save and resolves translations at render via filter hooks.
  // Silent no-op when WPML + ST addon are not active (resolved decision #4).
  // Note: WpmlBridge instantiation point — do not remove this line (phase-08 marker).
  NotificationBar\WpmlBridge::getInstance();
}
add_action('plugins_loaded', 'NjtNotificationBar\\init');

// v3.0 REST API — posts/page search endpoint for Customizer SPA.
add_action( 'rest_api_init', function () {
  ( new NotificationBar\RestPostsController() )->register();
} );

// REST API — user search for the per-bar audience picker (Pro UI; admin-only).
add_action( 'rest_api_init', function () {
  ( new NotificationBar\RestUsersController() )->register();
} );


// v3.2 REST API — Settings page Export/Import (GET /export, POST /import).
add_action( 'rest_api_init', function () {
  ( new NotificationBar\RestSettingsController() )->register();
} );


// Migrations — runs at priority 5, BEFORE the main init at priority 10.
// ORDER MATTERS: maybeRun() (v2→v3 legacy) must execute BEFORE
// maybeMigrateThemeModToOption() (v3.1→v3.2 storage flip) so v2 data lands
// in theme_mod first and is then copied into wp_options.
add_action('plugins_loaded', function () {
  $migration = NotificationBar\Migration::getInstance();
  $migration->maybeRun();
  $migration->maybeMigrateThemeModToOption();
}, 5);

register_activation_hook(__FILE__, array('NjtNotificationBar\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('NjtNotificationBar\\Plugin', 'deactivate'));
