<?php
/**
 * Migration — one-shot v2.1.9 → v3.0 data migration.
 *
 * Converts ~30 flat njt_nofi_* theme_mods into one bar object stored under
 * the njt_nofi_bars theme_mod and a global config under njt_nofi_global.
 *
 * Safety guarantees:
 *   - Idempotent via add_option(FLAG, 1) atomic-add lock.
 *   - Backup stored in wp_options (NOT a theme_mod) → survives theme switches.
 *   - Backup auto-pruned after 30 days via wp_schedule_single_event.
 *   - Legacy keys removed only AFTER new keys are committed.
 *
 * Value-mapping helpers live in MigrationMapper trait (keeps this file <200 LOC).
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/MigrationMapper.php';
require_once __DIR__ . '/Schema.php';

/**
 * Class Migration
 */
class Migration {

	use MigrationMapper;

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	const FLAG                   = 'njt_nofi_migrated_to_v3';
	const FLAG_OPTIONS_MIGRATION = 'njt_nofi_migrated_to_options';
	const BACKUP_OPTION          = 'njt_nofi_v2_backup';
	const PRUNE_HOOK             = 'njt_nofi_prune_v2_backup';

	/** All legacy theme_mod keys owned by v2.1.9. */
	const LEGACY_KEYS = [
		'njt_nofi_enable_bar',         'njt_nofi_text',
		'njt_nofi_text_mobile',        'njt_nofi_content_mobile',
		'njt_nofi_handle_button',      'njt_nofi_lb_text',
		'njt_nofi_lb_url',             'njt_nofi_lb_font_weight',
		'njt_nofi_open_new_windown',   'njt_nofi_handle_button_mobile',
		'njt_nofi_lb_text_mobile',     'njt_nofi_lb_url_mobile',
		'njt_nofi_lb_font_weight_mobile', 'njt_nofi_open_new_windown_mobile',
		'njt_nofi_bg_color',           'njt_nofi_text_color',
		'njt_nofi_lb_color',           'njt_nofi_lb_text_color',
		'njt_nofi_font_size',          'njt_nofi_alignment',
		'njt_nofi_content_width',      'njt_nofi_position_type',
		'njt_nofi_devices_display',    'njt_nofi_logic_display_page',
		'njt_nofi_list_display_page',  'njt_nofi_logic_display_post',
		'njt_nofi_list_display_post',  'njt_nofi_hide_close_button',
		'njt_nofi_open_after_day',     'njt_nofi_preset_color',
	];

	// ------------------------------------------------------------------
	// Singleton
	// ------------------------------------------------------------------

	/** @var Migration|null */
	private static $instance = null;

	/** @return Migration */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Registers cron and admin-notice hooks. */
	private function __construct() {
		add_action( self::PRUNE_HOOK, [ $this, 'prune' ] );
		add_action( 'admin_notices', [ $this, 'showMigrationNotice' ] );
		add_action( 'wp_ajax_njt_nofi_dismiss_migration_notice', [ $this, 'dismissMigrationNotice' ] );
	}

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Entry point — called on plugins_loaded (priority 5, before main init).
	 *
	 * Short-circuits if FLAG already set or if v3 data already exists.
	 * Uses add_option() atomic-add as an idempotency lock.
	 *
	 * @return void
	 */
	public function maybeRun(): void {
		if ( get_option( self::FLAG ) ) {
			return;
		}
		// v3 data already present (in either v3.0/3.1 theme_mod storage OR
		// v3.2+ wp_options storage) = clean install or previous partial run.
		if ( '' !== get_theme_mod( 'njt_nofi_bars', '' ) ) {
			return;
		}
		if ( false !== get_option( 'njt_nofi_bars', false ) ) {
			return;
		}
		// Atomic lock — add_option returns false if option already exists.
		// autoload=false (boolean — string 'no' deprecated since WP 6.6).
		if ( ! add_option( self::FLAG, 1, '', false ) ) {
			return;
		}
		try {
			$this->runMigration();
		} catch ( \Throwable $e ) {
			// Release lock so a subsequent page load can retry.
			delete_option( self::FLAG );
			throw $e;
		}
	}

	/**
	 * v3.2 — copy the active theme's bars + global theme_mod values into
	 * wp_options on first load after upgrade. Required because v3.2 flipped
	 * the Customizer setting type from `theme_mod` → `option`, otherwise
	 * existing users would see empty settings after the version bump.
	 *
	 * Idempotent + concurrent-safe via `add_option(FLAG, 1)` atomic-add lock
	 * (same pattern as maybeRun above). Theme_mod copies are LEFT IN PLACE
	 * as a rollback path (per locked decision D3).
	 *
	 * ORDERING: this method MUST run AFTER maybeRun() within the same
	 * plugins_loaded closure so v2→v3 data lands in theme_mod first and is
	 * then copied to options here. Wired correctly in njt-notification-bar.php.
	 *
	 * @return void
	 */
	public function maybeMigrateThemeModToOption(): void {
		if ( get_option( self::FLAG_OPTIONS_MIGRATION ) ) {
			return;
		}

		// Concurrent-load guard (v2→v3.2 direct upgrade): defer this migration
		// until the v2→v3 migration has VISIBLY committed FLAG_v3. Without this
		// gate, a concurrent request could claim FLAG_OPTIONS_MIGRATION before
		// the v3 set_theme_mod writes are visible to our read, copying empty
		// data and locking the migration forever.
		if ( ! get_option( self::FLAG ) ) {
			return;
		}

		if ( ! add_option( self::FLAG_OPTIONS_MIGRATION, 1, '', false ) ) {
			return;
		}

		$bars   = get_theme_mod( 'njt_nofi_bars',   '' );
		$global = get_theme_mod( 'njt_nofi_global', '' );

		// autoload=true for the data options — frontend reads them on every
		// page render in NotificationBarHandle::shouldRender(). Matches the
		// pre-existing theme_mods autoload behavior (theme_mods blob was
		// always autoloaded as a single row).
		if ( '' !== $bars && false === get_option( 'njt_nofi_bars', false ) ) {
			add_option( 'njt_nofi_bars', $bars, '', true );
		}
		if ( '' !== $global && false === get_option( 'njt_nofi_global', false ) ) {
			add_option( 'njt_nofi_global', $global, '', true );
		}
	}

	/** Cron callback — deletes the backup option after 30 days. */
	public function prune(): void {
		delete_option( self::BACKUP_OPTION );
	}

	/**
	 * Show a dismissible admin notice once after migration completes.
	 * Dismissal stored in user meta so it doesn't re-appear.
	 *
	 * @return void
	 */
	public function showMigrationNotice(): void {
		if ( ! get_option( self::FLAG ) ) {
			return;
		}
		$backup = get_option( self::BACKUP_OPTION );
		if ( ! $backup ) {
			return;
		}
		// Defense for installs that ran the OLD snapshotLegacy (pre-fix),
		// which wrote a backup even when all legacy keys were absent. If
		// the persisted backup's theme_mods are all `false`, the store
		// never actually used v2 — suppress the notice.
		if ( is_array( $backup ) && isset( $backup['theme_mods'] ) && is_array( $backup['theme_mods'] ) ) {
			$has_real_data = false;
			foreach ( $backup['theme_mods'] as $v ) {
				if ( false !== $v ) {
					$has_real_data = true;
					break;
				}
			}
			if ( ! $has_real_data ) {
				return;
			}
		}

		$user_id = get_current_user_id();
		if ( ! $user_id
			|| ! current_user_can( 'manage_options' )
			|| get_user_meta( $user_id, 'njt_nofi_migration_notice_dismissed', true )
		) {
			return;
		}
		$nonce = wp_create_nonce( 'njt_nofi_dismiss_migration_notice' );
		?>
		<div class="notice notice-info is-dismissible" id="njt-nofi-migration-notice">
			<p><?php
				echo wp_kses_post( sprintf(
					/* translators: %s: wp_options key name */
					__( '<strong>Notibar v3</strong> migrated your settings. A backup is kept for 30 days (option: <code>%s</code>).', 'notibar' ),
					esc_html( self::BACKUP_OPTION )
				) );
			?></p>
		</div>
		<script>
		(function(){
			var el=document.getElementById('njt-nofi-migration-notice');
			if(!el)return;
			el.querySelector('.notice-dismiss,button')?.addEventListener('click',function(){
				fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
					body:'action=njt_nofi_dismiss_migration_notice&nonce=<?php echo esc_js( $nonce ); ?>'});
				el.remove();
			});
		})();
		</script>
		<?php
	}

	/** AJAX handler — stores dismissal flag in user meta. */
	public function dismissMigrationNotice(): void {
		check_ajax_referer( 'njt_nofi_dismiss_migration_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$uid = get_current_user_id();
		if ( $uid ) {
			update_user_meta( $uid, 'njt_nofi_migration_notice_dismissed', 1 );
		}
		wp_send_json_success();
	}

	// ------------------------------------------------------------------
	// Private migration steps
	// ------------------------------------------------------------------

	/** Orchestrates the full migration sequence. */
	private function runMigration(): void {
		$legacy = $this->snapshotLegacy();
		$bar    = $this->buildBarFromLegacy( $legacy );
		$global = Schema::defaultGlobal();

		set_theme_mod( 'njt_nofi_bars',   wp_json_encode( [ $bar ] ) );
		set_theme_mod( 'njt_nofi_global', wp_json_encode( $global ) );

		$this->deleteLegacyKeys( self::LEGACY_KEYS );
		$this->schedulePrune();
	}

	/**
	 * Read all legacy theme_mod keys, persist backup, return snapshot.
	 *
	 * @return array Map of legacy_key => value (false when not set).
	 */
	private function snapshotLegacy(): array {
		// Use raw get_theme_mods() so we can distinguish "key was set" from
		// "key returns its default" — a fresh install where v2 was never
		// touched has zero of these keys present.
		$all_mods = get_theme_mods();
		if ( ! is_array( $all_mods ) ) {
			$all_mods = [];
		}

		$legacy   = [];
		$has_data = false;
		foreach ( self::LEGACY_KEYS as $key ) {
			if ( array_key_exists( $key, $all_mods ) ) {
				$legacy[ $key ] = $all_mods[ $key ];
				$has_data       = true;
			} else {
				$legacy[ $key ] = false;
			}
		}

		// Only persist the backup option (and trigger the post-migration
		// admin notice) when at least one legacy theme_mod actually existed.
		// Fresh installs that never used v2 skip both — no false-positive
		// "Notibar v3 migrated your settings" notice on brand new stores.
		if ( $has_data ) {
			// autoload=false — backup is large, only read on demand.
			update_option( self::BACKUP_OPTION, [ 'migrated_at' => time(), 'theme_mods' => $legacy ], false );
		}
		return $legacy;
	}

	/**
	 * Build a v3 bar from the legacy snapshot using MigrationMapper helpers.
	 *
	 * @param  array $l Legacy snapshot.
	 * @return array    Sanitized v3 bar.
	 */
	private function buildBarFromLegacy( array $l ): array {
		$bar = Schema::defaultBar();

		if ( false !== $l['njt_nofi_enable_bar'] ) {
			$bar['enabled'] = (bool) $l['njt_nofi_enable_bar'];
		}

		$this->applyContentMapping( $bar, $l );
		$this->applyStyleMapping( $bar, $l );
		$this->applyDisplayMapping( $bar, $l );
		$this->applyBehaviorMapping( $bar, $l );
		// njt_nofi_preset_color is intentionally discarded (colors already migrated).

		return $bar;
	}

	/** Remove all legacy theme_mod keys. */
	private function deleteLegacyKeys( array $keys ): void {
		foreach ( $keys as $key ) {
			remove_theme_mod( $key );
		}
	}

	/** Schedule backup prune 30 days from now (once). */
	private function schedulePrune(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_single_event( time() + ( 30 * DAY_IN_SECONDS ), self::PRUNE_HOOK );
		}
	}
}
