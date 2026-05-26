<?php
/**
 * REST API controller for user search — powers the per-bar audience "specific
 * users" picker in the Customizer SPA (Pro feature).
 *
 * Namespace: notibar/v1
 * Routes:
 *   GET /users          — paginated search, returns { items, hasMore }
 *   GET /users/by-ids   — hydrate selected chips by user IDs
 *
 * Permission: edit_theme_options (matches the Customizer capability). Admin-
 * only; the endpoint exposes only id + display name, which such users can
 * already see in wp-admin → Users.
 *
 * @package NjtNotificationBar\NotificationBar
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class RestUsersController {

	const NAMESPACE = 'notibar/v1';
	const PER_PAGE  = 20;

	/**
	 * Register REST routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/users',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_search' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'q'    => [
						'default'           => '',
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
			'/users/by-ids',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_by_ids' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'ids' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * GET /users — paginated search.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		$q     = (string) $request['q'];
		$paged = max( 1, (int) $request['page'] );

		$args = [
			'number'  => self::PER_PAGE,
			'paged'   => $paged,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => [ 'ID', 'display_name', 'user_login' ],
		];

		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = [ 'user_login', 'display_name', 'user_email', 'user_nicename' ];
		}

		$query = new \WP_User_Query( $args );
		$items = array_map( [ self::class, 'map_user' ], $query->get_results() );

		$total    = (int) $query->get_total();
		$has_more = ( $paged * self::PER_PAGE ) < $total;

		return rest_ensure_response( [
			'items'   => $items,
			'hasMore' => $has_more,
		] );
	}

	/**
	 * GET /users/by-ids — hydrate selected IDs for chip rendering.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_by_ids( \WP_REST_Request $request ): \WP_REST_Response {
		$raw = (string) $request['ids'];
		if ( '' === $raw ) {
			return rest_ensure_response( [ 'items' => [] ] );
		}

		$ids = array_slice(
			array_values( array_filter(
				array_map( 'intval', explode( ',', $raw ) ),
				fn( $v ) => $v > 0
			) ),
			0,
			self::PER_PAGE
		);

		if ( empty( $ids ) ) {
			return rest_ensure_response( [ 'items' => [] ] );
		}

		$query = new \WP_User_Query( [
			'include' => $ids,
			'number'  => count( $ids ),
			'fields'  => [ 'ID', 'display_name', 'user_login' ],
		] );

		return rest_ensure_response( [
			'items' => array_map( [ self::class, 'map_user' ], $query->get_results() ),
		] );
	}

	/**
	 * Shape a user row into the REST item dict.
	 *
	 * @param object $u User row (ID, display_name, user_login).
	 * @return array{id:int,title:string}
	 */
	private static function map_user( $u ): array {
		$name = isset( $u->display_name ) && '' !== $u->display_name
			? $u->display_name
			: ( $u->user_login ?? '' );
		return [
			'id'    => (int) $u->ID,
			'title' => $name . ' (#' . (int) $u->ID . ')',
		];
	}
}
