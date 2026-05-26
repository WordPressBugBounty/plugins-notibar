<?php
/**
 * Notification Bar front-end handler — v3.0 rewrite.
 *
 * Replaces legacy display_notification() + njt_nofi_rederInput() with a
 * single-slot model driven by inline JSON + the new frontend JS bundle.
 *
 * Responsibilities:
 *  - Read njt_nofi_bars / njt_nofi_global theme_mods.
 *  - Apply filter njt_nofi_resolve_strings (phase 08 WPML bridge hooks here).
 *  - Server-side pre-filter to detect "nothing to show" → skip asset enqueue.
 *  - On wp_footer: emit #njt-notibar-slot shell + inline window.njtNotibarData.
 *  - Admin features (menu, review, action links) delegated to trait.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/NotificationBarHandleAdmin.php';

/**
 * Class NotificationBarHandle
 */
class NotificationBarHandle {

	use NotificationBarHandleAdmin;

	/** @var NotificationBarHandle|null */
	protected static $instance = null;

	/** @var bool Whether shouldRender() returned true on this request. */
	private $should_render = false;

	/** @var array All bars (post-filter passthrough to JS). */
	private $all_bars = [];

	/** @var array Global config. */
	private $global_config = [];

	/** @var array Computed render context. */
	private $render_context = [];

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * @return NotificationBarHandle
	 */
	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — registers hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'njt_nofi_showMenu' ] );

		// Evaluate render eligibility after WP query is set up.
		add_action( 'wp', [ $this, 'maybeRender' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'njt_nofi_homeRegisterEnqueue' ] );

		// Folder-agnostic — works whether the plugin ships as notibar/ (Lite)
		// or notibar-pro/ (Pro). NJT_NOFI_PLUGIN_BASENAME is defined in the
		// main plugin file via plugin_basename(__FILE__).
		add_filter(
			'plugin_action_links_' . NJT_NOFI_PLUGIN_BASENAME,
			[ $this, 'addActionLinks' ]
		);
	}

	// -------------------------------------------------------------------------
	// Public gate — AssetLoader::enqueue_frontend() reads this.
	// -------------------------------------------------------------------------

	/**
	 * Returns true when at least one enabled bar passes server-side pre-filter.
	 *
	 * @return bool
	 */
	public function shouldRender(): bool {
		return $this->should_render;
	}

	// -------------------------------------------------------------------------
	// Core render decision — runs on 'wp' hook.
	// -------------------------------------------------------------------------

	/**
	 * Evaluate bars and schedule footer output when applicable.
	 *
	 * @return void
	 */
	public function maybeRender(): void {
		// Skip in admin, AJAX, and Customizer preview (preview uses its own JS).
		if ( is_admin() || wp_doing_ajax() || is_customize_preview() ) {
			return;
		}

		$bars   = json_decode( get_option( 'njt_nofi_bars', '[]' ), true );
		$global = json_decode( get_option( 'njt_nofi_global', '{}' ), true );

		$bars   = is_array( $bars )   ? $bars   : [];
		$global = is_array( $global ) ? $global : [];

		if ( empty( $bars ) ) {
			return;
		}

		// Phase 08 WPML bridge hooks here to translate per-bar string fields.
		$bars = apply_filters( 'njt_nofi_resolve_strings', $bars );


		$context = $this->getRenderContext();

		// Server-side pre-filter: skip enqueue entirely if no bar can show.
		if ( empty( $this->filterBarsServer( $bars ) ) ) {
			return;
		}

		$this->should_render  = true;
		$this->all_bars       = $bars;
		$this->global_config  = $global;
		$this->render_context = $context;

		add_action( 'wp_footer', [ $this, 'renderFooterOutput' ], 5 );
	}

	/**
	 * Echo slot shell + wire inline JSON at wp_footer priority 5.
	 *
	 * @return void
	 */
	public function renderFooterOutput(): void {
		echo '<div id="njt-notibar-slot" role="status" aria-live="polite"></div>' . "\n";

		wp_add_inline_script(
			'njt-notibar-frontend',
			'window.njtNotibarData = ' . wp_json_encode( [
				'bars'   => $this->all_bars,
				'global' => $this->global_config,
				'ctx'    => $this->render_context,
			] ) . ';',
			'before'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the render context from the current WP query.
	 *
	 * @return array{pageId:int,postId:int,isHome:bool,isSingleProduct:bool,theme:string,serverNow:int,serverWeekday:int,serverHHMM:string,currentCptType:string,currentObjectId:int}
	 */
	private function getRenderContext(): array {
		$object_id = (int) get_queried_object_id();

		$page_id = is_singular( 'page' ) ? $object_id : 0;

		// CPT branch context: populated only on single CPT instances (not page,
		// not post). Powers the "Other post types" display gate in filter-bars.js.
		// Empty string when not a single CPT instance — filter-bars treats that
		// as "branch inert" so legacy bars (cptTypes=[]) never enter the branch.
		$current_cpt_type = '';
		if ( is_singular() && ! is_singular( 'page' ) && ! is_singular( 'post' ) ) {
			$pt = get_post_type();
			if ( is_string( $pt ) && '' !== $pt ) {
				$current_cpt_type = $pt;
			}
		}

		// "Blog" Page assigned as the WP Posts page (Settings → Reading →
		// "Posts page"). On /blog/ (or whatever URL the assigned Page has)
		// is_home() is true but is_singular('page') is false because WP
		// renders the posts loop, not the Page itself. Emit the assigned
		// Page's real ID so admins can target /blog/ via the regular page
		// picker. Guarded on `!is_front_page()` to avoid matching twice on
		// sites where show_on_front=posts (where / is both home and front).
		if ( 0 === $page_id && is_home() && ! is_front_page() ) {
			$blog_id = (int) get_option( 'page_for_posts' );
			if ( $blog_id > 0 ) {
				$page_id = $blog_id;
			}
		}

		// "Shop" picker entry also covers all WC product archive templates
		// (product category, product tag, product attribute taxonomies) so
		// admins can target the entire catalog browsing surface with one
		// picker selection. is_product_taxonomy() covers all of them in one
		// call (product_cat, product_tag, and custom pa_* attributes).
		if ( 0 === $page_id
			&& function_exists( 'wc_get_page_id' )
			&& (
				( function_exists( 'is_shop' ) && is_shop() )
				|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
			)
		) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$page_id = $shop_id;
			}
		}

		// Synthetic flag for "Single Product page" picker token. Mirrors
		// the existing isHome / home_page convention — when admin adds the
		// 'wc_single_product' token to pageIds, the bar's logic matches
		// on every single-product permalink.
		$is_single_product = function_exists( 'is_product' ) && is_product();

		return [
			'pageId'           => $page_id,
			'postId'           => is_singular( 'post' ) ? $object_id : 0,
			'isHome'           => is_home() || is_front_page(),
			'isSingleProduct'  => $is_single_product,
			// Match legacy 1:1 — theme-compat fixes are keyed by display Name
			// ("Divi", "Salient", "Twenty Twenty-Two"), not stylesheet slug.
			'theme'            => wp_get_theme()->get( 'Name' ),
			// Server-emitted "now" so JS-side schedule filter agrees with PHP
			// even on cached pages. Site timezone (current_time honours it).
			// Kept even when some bars opt into useClientTime — JS branches
			// per-bar and ignores these fields for client-time bars.
			'serverNow'        => (int) current_time( 'timestamp' ),
			'serverWeekday'    => (int) current_time( 'w' ),
			'serverHHMM'       => current_time( 'H:i' ),
			'currentCptType'   => $current_cpt_type,
			'currentObjectId'  => $object_id,
		];
	}


	/**
	 * Server-side bar pre-filter (enabled + non-empty devices).
	 *
	 * Dismissal is cookie-based (client-only). Page/post 4-state logic is
	 * handled entirely client-side by filterBars.js. This is purely an
	 * early-exit optimisation to avoid enqueuing assets when all bars are
	 * disabled.
	 *
	 * @param array $bars All bars decoded from theme_mod.
	 * @return array Bars that pass the lightweight server check.
	 */
	private function filterBarsServer( array $bars ): array {
		$now      = current_time( 'timestamp' );
		$weekday  = (int) current_time( 'w' );           // 0 (Sun) .. 6 (Sat)
		$hhmm_now = current_time( 'H:i' );               // "HH:MM"

		return array_values( array_filter( $bars, function ( $bar ) use ( $now, $weekday, $hhmm_now ) {
			if ( ! is_array( $bar ) ) {
				return false;
			}
			if ( ! ( $bar['enabled'] ?? false ) ) {
				return false;
			}
			if ( empty( $bar['display']['devices'] ?? [] ) ) {
				return false;
			}
			if ( ! $this->passesSchedule( $bar, $now, $weekday, $hhmm_now ) ) {
				return false;
			}
			return true;
		} ) );
	}

	/**
	 * Schedule filter — date range + day-of-week + daily window.
	 *
	 * Mirrors src/shared/filter-bars.js#passesSchedule. Site-TZ branch
	 * (driven by current_time()) is bypassed when schedule.useClientTime
	 * is true — the JS filter re-evaluates against visitor browser-local
	 * time in that case.
	 *
	 * @param array  $bar      Bar object.
	 * @param int    $now      Current unix timestamp (site TZ).
	 * @param int    $weekday  0..6 (Sun..Sat) — site TZ.
	 * @param string $hhmm_now "HH:MM" site-local time.
	 * @return bool true = passes (bar may render).
	 */
	private function passesSchedule( array $bar, int $now, int $weekday, string $hhmm_now ): bool {
		$sched = $bar['schedule'] ?? [];
		if ( empty( $sched['enabled'] ) ) {
			return true;
		}


		// Date range — startAt / endAt are "YYYY-MM-DDTHH:MM" site-local.
		if ( ! empty( $sched['startAt'] ) ) {
			$start = strtotime( str_replace( 'T', ' ', $sched['startAt'] ) . ':00' );
			if ( $start && $start > $now ) {
				return false;
			}
		}
		if ( ! empty( $sched['endAt'] ) ) {
			$end = strtotime( str_replace( 'T', ' ', $sched['endAt'] ) . ':00' );
			if ( $end && $end <= $now ) {
				return false;
			}
		}

		// Day-of-week — array of allowed weekday ints. Empty = all days.
		$dow = $sched['daysOfWeek'] ?? [];
		if ( is_array( $dow ) && ! empty( $dow ) && ! in_array( $weekday, $dow, true ) ) {
			return false;
		}

		// Daily window — "HH:MM" inclusive on start, exclusive on end. Wrapped
		// windows (e.g. 22:00–02:00) are supported by inverting the test.
		$dw = $sched['dailyWindow'] ?? [];
		if ( ! empty( $dw['enabled'] ) ) {
			$start = $dw['start'] ?? '';
			$end   = $dw['end'] ?? '';
			if ( '' !== $start || '' !== $end ) {
				$in = $this->inDailyWindow( $hhmm_now, $start, $end );
				if ( ! $in ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Test whether $now ("HH:MM") falls inside [start, end).
	 * Empty bounds = unbounded on that side. Wrap-around supported
	 * (e.g. start=22:00 end=02:00 → true at 23:00 and 01:00).
	 *
	 * @param string $now   "HH:MM".
	 * @param string $start "HH:MM" or empty.
	 * @param string $end   "HH:MM" or empty.
	 * @return bool
	 */
	private function inDailyWindow( string $now, string $start, string $end ): bool {
		if ( '' === $start && '' === $end ) {
			return true;
		}
		if ( '' === $start ) {
			return $now < $end;
		}
		if ( '' === $end ) {
			return $now >= $start;
		}
		// Lexicographic compare works because HH:MM is zero-padded.
		if ( $start <= $end ) {
			return $now >= $start && $now < $end;
		}
		// Wrapped window: e.g. 22:00–02:00.
		return $now >= $start || $now < $end;
	}

	// -------------------------------------------------------------------------
	// Compatibility shim — hook exists from v2; now intentionally empty.
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function njt_nofi_homeRegisterEnqueue(): void {
		// AssetLoader::enqueue_frontend() reads shouldRender() directly.
	}
}
