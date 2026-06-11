<?php
/**
 * REST API for Notibar Settings page — Export / Import.
 *
 * Routes (namespace: notibar/v1):
 *   GET  /export?include=bars,global,tracking — admin-only; returns JSON payload.
 *   POST /import                              — admin-only; replaces option(s) from JSON body.
 *
 * All endpoints require manage_options (stricter than Customizer's
 * edit_theme_options) — Export/Import is global site state, not theme state.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.2
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class RestSettingsController {

	/** @var string Shared REST namespace (matches RestPostsController / TrackingRestController). */
	const NAMESPACE = 'notibar/v1';

	/** @var int Current export-payload schema version. Bumped on shape-breaking change. */
	const CURRENT_EXPORT_VERSION = 1;

	/** @var int 10 MB request-body cap on /import. */
	const MAX_IMPORT_BYTES = 10485760;

	/** @var string[] Whitelist of section keys the include= param accepts. */
	const SECTIONS = [
		'bars',
		'global',
	];

	/**
	 * Register routes on rest_api_init.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/export',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_export' ],
				'permission_callback' => [ $this, 'admin_only' ],
				'args'                => [
					'include' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_import' ],
				'permission_callback' => [ $this, 'admin_only' ],
			]
		);
	}

	public function admin_only(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /export
	 *
	 * Body shape:
	 *   {
	 *     "_notibar_export_version": 1,
	 *     "_exported_at": "2026-05-21T08:41:08+00:00",
	 *     "bars":     [...]   // when 'bars' in include
	 *     "global":   {...}   // when 'global' in include
	 *     "tracking": {...}   // when 'tracking' in include  (counters map)
	 *   }
	 */
	public function handle_export( \WP_REST_Request $request ): \WP_REST_Response {
		$include = $this->parse_include( $request['include'] );

		$payload = [
			'_notibar_export_version' => self::CURRENT_EXPORT_VERSION,
			'_exported_at'            => gmdate( 'c' ),
		];

		if ( in_array( 'bars', $include, true ) ) {
			$bars = json_decode( get_option( 'njt_nofi_bars', '[]' ), true );
			$payload['bars'] = is_array( $bars ) ? $bars : [];
		}
		if ( in_array( 'global', $include, true ) ) {
			$global = json_decode( get_option( 'njt_nofi_global', '{}' ), true );
			$payload['global'] = is_array( $global ) ? $global : new \stdClass();
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * POST /import
	 *
	 * Returns: { "replaced": { "bars": int, "global": bool, "tracking": int } }
	 */
	public function handle_import( \WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( strlen( $body ) > self::MAX_IMPORT_BYTES ) {
			return new \WP_Error(
				'notibar_import_too_large',
				__( 'Import file exceeds 10 MB limit.', 'notibar' ),
				[ 'status' => 413 ]
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'notibar_import_invalid_json',
				__( 'Request body is not valid JSON.', 'notibar' ),
				[ 'status' => 400 ]
			);
		}

		$version = isset( $data['_notibar_export_version'] )
			? (int) $data['_notibar_export_version']
			: 0;

		if ( $version < 1 || $version > self::CURRENT_EXPORT_VERSION ) {
			return new \WP_Error(
				'notibar_import_unsupported_version',
				sprintf(
					/* translators: %d: highest supported export version */
					__( 'Unsupported export version. This plugin supports up to version %d.', 'notibar' ),
					self::CURRENT_EXPORT_VERSION
				),
				[ 'status' => 400 ]
			);
		}

		$counts = [ 'bars' => 0, 'global' => false, 'tracking' => 0 ];

		// Bars — re-encode then run Schema::sanitizeBars so the import write
		// goes through the same defense-in-depth gate the Customizer save
		// uses. Mismatched enums, malformed colors, etc. are stripped/clamped.
		if ( isset( $data['bars'] ) && is_array( $data['bars'] ) ) {
			$raw = wp_json_encode( $data['bars'] );
			if ( false === $raw ) {
				return new \WP_Error( 'notibar_import_encode_failed', __( 'Failed to encode bars payload (invalid UTF-8?).', 'notibar' ), [ 'status' => 500 ] );
			}
			$sanitized = Schema::sanitizeBars( $raw );
			update_option( 'njt_nofi_bars', $sanitized );
			set_theme_mod( 'njt_nofi_bars', $sanitized );
			$decoded        = json_decode( $sanitized, true );
			$counts['bars'] = is_array( $decoded ) ? count( $decoded ) : 0;
		}

		// Global — same sanitizer-on-import treatment.
		if ( isset( $data['global'] ) && is_array( $data['global'] ) ) {
			$raw = wp_json_encode( $data['global'] );
			if ( false === $raw ) {
				return new \WP_Error( 'notibar_import_encode_failed', __( 'Failed to encode global payload (invalid UTF-8?).', 'notibar' ), [ 'status' => 500 ] );
			}
			$sanitized = Schema::sanitizeGlobal( $raw );
			update_option( 'njt_nofi_global', $sanitized );
			set_theme_mod( 'njt_nofi_global', $sanitized );
			$counts['global'] = true;
		}


		return new \WP_REST_Response( [ 'replaced' => $counts ], 200 );
	}

	/**
	 * Parse + whitelist the include= query param.
	 * Empty / missing → all sections.
	 *
	 * @param mixed $raw Raw query param.
	 * @return string[]
	 */
	private function parse_include( $raw ): array {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return self::SECTIONS;
		}
		$parts = array_map( 'trim', explode( ',', $raw ) );
		$out   = array_values( array_intersect( self::SECTIONS, $parts ) );
		return empty( $out ) ? self::SECTIONS : $out;
	}
}
