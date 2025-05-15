<?php
namespace NjtNotificationBar;
use NjtNotificationBar\NotificationBar\WpCustomNotification;

defined('ABSPATH') || exit;
/**
 * NotiHelper Logic 
 */
class NotiHelper {
  protected static $instance = null;

  public static function getInstance() {
    if (null == self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  public static function is_hide_notibar_with_cookie() {
    $WpCustomNotification = WpCustomNotification::getInstance();
    $valueDefault = $WpCustomNotification->valueDefault;
    $cookie_value =  $_COOKIE['njt-close-notibar'] ?? null;
    $hide_close_button = get_theme_mod( 'njt_nofi_hide_close_button',$valueDefault['hide_close_button']);

    if ($cookie_value == 'true' && !is_customize_preview() && $hide_close_button == 'close_button') {
      return true;
    }
  }
}