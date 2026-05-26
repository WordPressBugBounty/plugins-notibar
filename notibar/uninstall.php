<?php
/**
 * Notibar — uninstall handler.
 *
 * Runs only when the plugin is fully deleted via wp-admin (NOT on deactivate).
 * Cleans up every theme_mod, option, user-meta, and cron event the plugin
 * owns so the WP database is left as the user found it.
 *
 * @since 3.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ---------------------------------------------------------------------------
// theme_mods (current theme only — theme_mods are per-theme by design).
// ---------------------------------------------------------------------------
remove_theme_mod( 'njt_nofi_bars' );
remove_theme_mod( 'njt_nofi_global' );

// ---------------------------------------------------------------------------
// wp_options (site-wide).
// ---------------------------------------------------------------------------
$options = [
	'njt_nofi_v2_backup',           // legacy-snapshot backup written during v2→v3 migration
	'njt_nofi_migrated_to_v3',      // migration idempotency flag
	'njt_nofi_wpml_string_map',     // WpmlBridge diff cache
];
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// ---------------------------------------------------------------------------
// Multisite — repeat per blog if running in a network install.
// ---------------------------------------------------------------------------
if ( is_multisite() ) {
	foreach ( $options as $opt ) {
		delete_site_option( $opt );
	}
}

// ---------------------------------------------------------------------------
// Cron — drop the v2-backup prune we scheduled during migration.
// ---------------------------------------------------------------------------
$timestamp = wp_next_scheduled( 'njt_nofi_prune_v2_backup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'njt_nofi_prune_v2_backup' );
}
wp_clear_scheduled_hook( 'njt_nofi_prune_v2_backup' );

// ---------------------------------------------------------------------------
// User meta — clear the dismissed-migration-notice flag we set per admin.
// ---------------------------------------------------------------------------
delete_metadata( 'user', 0, 'njt_nofi_migration_notice_dismissed', '', true );
