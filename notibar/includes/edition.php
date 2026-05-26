<?php
/**
 * Notibar edition flag — LITE variant.
 *
 * Swapped in for includes/edition.php by `release.sh --lite` via the
 * pro-manifest.json "replace" map. Do NOT load directly from the source tree.
 *
 * @package NjtNotificationBar
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NJT_NOFI_IS_PRO' ) ) {
	define( 'NJT_NOFI_IS_PRO', false );
}
