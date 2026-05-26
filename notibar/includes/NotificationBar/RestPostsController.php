<?php
/**
 * REST API controller for post/page/CPT search used by the Customizer SPA.
 *
 * Replaces the legacy wp_ajax_njt_nofi_query_page_post handler (WpPosts).
 * Namespace: notibar/v1
 * Routes:
 *   GET /posts          — paginated search, returns { items, hasMore }
 *   GET /posts/by-ids   — hydrate selected chips by IDs
 *   GET /cpts           — public CPT list (excludes page/post/attachment)
 *
 * Permission: edit_theme_options (matches WP Customizer cap).
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.0.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestPostsController
 */
class RestPostsController {

	/** @var string REST namespace. */
	const NAMESPACE = 'notibar/v1';

	/** @var int Results per page. */
	const PER_PAGE = 20;

	/** @var string[] CPTs that always belong to dedicated pickers, never CPT bucket. */
	const RESERVED_TYPES = [ 'page', 'post', 'attachment' ];

	/**
	 * Register REST routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_search' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'q'    => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type' => [
						'default'           => 'page',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'page' => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/by-ids',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_by_ids' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'ids'  => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type' => [
						'default'           => 'page',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/cpts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_cpts' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Permission callback — requires Customizer capability.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Handle GET /posts — paginated search.
	 *
	 * Includes a synthetic "Home Page" item when type=page and query is
	 * empty or matches the word "home".
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		$types = $this->resolve_types( $request['type'] );
		$q     = $request['q'];
		$paged = max( 1, (int) $request['page'] );

		// Singleton fast-path keeps WP_Query post_type as string (legacy behavior).
		$query_args = [
			'post_type'      => count( $types ) === 1 ? $types[0] : $types,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'no_found_rows'  => false,
		];

		if ( '' !== $q ) {
			$query_args['s'] = $q;
		}

		$wp_query = new \WP_Query( $query_args );

		// Per-item type reflects each post's actual post_type so the SPA
		// can label mixed-CPT results correctly.
		$items = array_map( [ self::class, 'map_post' ], $wp_query->posts );

		// Synthetic items (home_page, wc_single_product, tpl:*) are bound to
		// the single-page picker context only — they make no sense for posts
		// or for the multi-CPT bucket.
		$is_page_only = $types === [ 'page' ];

		// Prepend the synthetic "(Front page URL)" ONLY when WP is serving
		// the blog index at / (Settings → Reading: "Your latest posts") OR
		// when no static front page is set — i.e. when no real Page in
		// wp_posts represents the homepage URL and the picker would have no
		// other way to target it. In static-page mode, the real Page already
		// appears in the search results naturally, so prepending here would
		// create a duplicate row (one real, one synthetic).
		if (
			$is_page_only
			&& 1 === $paged
			&& ( 'posts' === get_option( 'show_on_front' )
				|| 0 === (int) get_option( 'page_on_front' ) )
		) {
			$show_home = '' === $q
				|| false !== stripos( $q, 'home' )
				|| false !== stripos( $q, 'front' );
			if ( $show_home ) {
				array_unshift( $items, [
					'id'    => 'home_page',
					'title' => __( 'Front Page', 'notibar' ),
					'type'  => 'page',
				] );
			}
		}

		// Synthetic "Single Product page" token — only offered when WC is
		// active and the search query is empty or matches "product"/"single".
		if (
			$is_page_only
			&& 1 === $paged
			&& function_exists( 'is_product' )
		) {
			$show_sp = '' === $q
				|| false !== stripos( $q, 'product' )
				|| false !== stripos( $q, 'single' );
			if ( $show_sp ) {
				$items[] = [
					'id'    => 'wc_single_product',
					'title' => __( 'Single Product page', 'notibar' ),
					'type'  => 'page',
				];
			}
		}

		// Theme page templates — fetched alongside pages so a single search
		// response covers both individual pages and the template categories
		// they belong to. Token format: "tpl:<filename>" (matches the
		// _wp_page_template meta value WP itself stores per page). Filtered
		// by the same search query the WP_Query received.
		if ( $is_page_only && 1 === $paged ) {
			$templates = wp_get_theme()->get_page_templates( null, 'page' );
			if ( is_array( $templates ) && ! empty( $templates ) ) {
				foreach ( $templates as $file => $name ) {
					if ( '' !== $q
						&& false === stripos( $name, $q )
						&& false === stripos( $file, $q )
					) {
						continue;
					}
					$items[] = [
						'id'    => 'tpl:' . $file,
						/* translators: %s: page template display name. */
						'title' => sprintf( __( 'Template: %s', 'notibar' ), $name ),
						'type'  => 'template',
					];
				}
			}
		}

		$total_pages = (int) $wp_query->max_num_pages;
		$has_more    = $paged < $total_pages;

		return rest_ensure_response( [
			'items'   => $items,
			'hasMore' => $has_more,
		] );
	}

	/**
	 * Handle GET /posts/by-ids — hydrate selected IDs for chip rendering.
	 *
	 * Accepts the special token "home_page" mixed with numeric IDs.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_by_ids( \WP_REST_Request $request ): \WP_REST_Response {
		$types   = $this->resolve_types( $request['type'] );
		$raw_ids = $request['ids'];

		if ( '' === $raw_ids ) {
			return rest_ensure_response( [ 'items' => [] ] );
		}

		$is_page_only       = $types === [ 'page' ];
		$parts              = array_filter( array_map( 'trim', explode( ',', $raw_ids ) ) );
		$items              = [];
		$numeric            = [];
		$template_files     = [];
		$has_home           = false;
		$has_single_product = false;

		foreach ( $parts as $part ) {
			if ( 'home_page' === $part ) {
				$has_home = true;
			} elseif ( 'wc_single_product' === $part ) {
				$has_single_product = true;
			} elseif ( 0 === strpos( $part, 'tpl:' ) ) {
				$template_files[] = substr( $part, 4 );
			} elseif ( ctype_digit( $part ) ) {
				$numeric[] = (int) $part;
			}
		}

		// Synthetic tokens belong to the single-page picker only.
		if ( ! empty( $template_files ) && $is_page_only ) {
			$templates = wp_get_theme()->get_page_templates( null, 'page' );
			foreach ( $template_files as $file ) {
				if ( isset( $templates[ $file ] ) ) {
					$items[] = [
						'id'    => 'tpl:' . $file,
						/* translators: %s: page template display name. */
						'title' => sprintf( __( 'Template: %s', 'notibar' ), $templates[ $file ] ),
						'type'  => 'template',
					];
				}
			}
		}

		if ( $has_single_product && $is_page_only ) {
			$items[] = [
				'id'    => 'wc_single_product',
				'title' => __( 'Single Product page', 'notibar' ),
				'type'  => 'page',
			];
		}

		if ( $has_home && $is_page_only ) {
			$items[] = [
				'id'    => 'home_page',
				'title' => __( 'Front Page', 'notibar' ),
				'type'  => 'page',
			];
		}

		// Cap to prevent unbounded WP_Query (DoS guard).
		$numeric = array_slice( $numeric, 0, self::PER_PAGE );

		if ( ! empty( $numeric ) ) {
			$wp_query = new \WP_Query( [
				'post_type'      => count( $types ) === 1 ? $types[0] : $types,
				'post_status'    => 'publish',
				'post__in'       => $numeric,
				'posts_per_page' => count( $numeric ),
				'orderby'        => 'post__in',
				'no_found_rows'  => true,
			] );

			foreach ( $wp_query->posts as $post ) {
				$items[] = self::map_post( $post );
			}
		}

		return rest_ensure_response( [ 'items' => $items ] );
	}

	/**
	 * Handle GET /cpts — list of public CPTs for the multi-CPT selector.
	 *
	 * Excludes page/post/attachment (page/post have dedicated pickers,
	 * attachment is never a target for a notification bar).
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_cpts(): \WP_REST_Response {
		$objects = get_post_types( [ 'public' => true ], 'objects' );
		$items   = [];

		foreach ( $objects as $slug => $obj ) {
			if ( in_array( $slug, self::RESERVED_TYPES, true ) ) {
				continue;
			}
			$label    = isset( $obj->labels->singular_name ) && '' !== $obj->labels->singular_name
				? $obj->labels->singular_name
				: $slug;
			$items[]  = [
				'slug'  => $slug,
				'label' => $label,
			];
		}

		usort( $items, static fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );

		return rest_ensure_response( [ 'items' => $items ] );
	}

	/**
	 * Parse the request `type` param into a whitelisted post-type list.
	 *
	 * Accepts comma-separated CPT slugs. Singletons 'page' / 'post' pass
	 * through fast for backward compat. Multi-type calls drop the legacy
	 * 'page'/'post' slugs (those have dedicated pickers) — only true CPTs
	 * survive. Unknown slugs are dropped; empty result falls back to
	 * ['page'] so legacy clients keep working.
	 *
	 * @param string $type Raw type value (possibly comma-separated).
	 * @return string[]     Whitelisted CPT slug list (always non-empty).
	 */
	private function resolve_types( string $type ): array {
		$parts = array_values( array_filter( array_map(
			'sanitize_key',
			array_map( 'trim', explode( ',', $type ) )
		) ) );

		if ( empty( $parts ) ) {
			return [ 'page' ];
		}

		// Backward-compat: single 'page' or 'post' bypasses CPT whitelist
		// so legacy callers never depend on get_post_types ordering.
		if ( 1 === count( $parts ) && in_array( $parts[0], [ 'page', 'post' ], true ) ) {
			return [ $parts[0] ];
		}

		// Multi-type context belongs to the CPT bucket — strip page/post
		// (they have their own pickers) so the trust boundary is explicit.
		$whitelist = array_diff( $this->public_type_whitelist(), [ 'page', 'post' ] );
		$valid     = array_values( array_intersect( $parts, $whitelist ) );

		return empty( $valid ) ? [ 'page' ] : $valid;
	}

	/**
	 * Whitelist of post types queryable through this controller.
	 *
	 * All public types minus attachment. `resolve_types()` further filters
	 * page/post out when multi-type context is detected.
	 *
	 * @return string[]
	 */
	private function public_type_whitelist(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Shape a WP_Post into the REST item dict used by both /posts endpoints.
	 *
	 * @param \WP_Post $post Post object from WP_Query.
	 * @return array{id:int,title:string,type:string}
	 */
	private static function map_post( \WP_Post $post ): array {
		return [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'type'  => $post->post_type,
		];
	}
}
