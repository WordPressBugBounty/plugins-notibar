<?php
/**
 * DynamicContent — server-side merge tags for bar text (Pro).
 *
 * Replaces {token} / {token|fallback} placeholders in bar string fields with
 * live values right before the bars are inlined into the page (after WPML
 * string resolution). Runs in BOTH editions so a token never reaches a visitor
 * raw; only the built-in token VALUES are Pro-gated (NJT_NOFI_IS_PRO) — in Lite
 * every built-in token resolves to its fallback or empty string.
 *
 * Grammar:  {name}  or  {name|fallback}   — name = [a-z0-9_]+
 *   - Registered/built-in name, value present  → value.
 *   - Registered/built-in name, value empty     → fallback (or '' if none).
 *   - Unknown name (not built-in, not filtered)  → left verbatim (so unrelated
 *                                                  "{...}" text is never eaten).
 *
 * Escaping is per field: HTML fields (rendered as innerHTML) get esc_html'd
 * values; plain fields (button labels, escaped client-side at render) get the
 * raw value to avoid double-encoding.
 *
 * Extend with the `njt_nofi_dynamic_tokens` filter: add `name => callable( $ctx )`.
 * Built-in value providers live in the DynamicContentProviders trait.
 *
 * MIRROR: src/customizer-app/components/fields/DynamicTagPicker.jsx
 * DYNAMIC_TAG_GROUPS — keep the built-in token names in sync with the picker.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.2.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/DynamicContentProviders.php';

/**
 * Class DynamicContent
 */
class DynamicContent {

	use DynamicContentProviders;

	/**
	 * Bar string fields the resolver runs on, mapped to their escaping context.
	 * true  = HTML field (rendered via innerHTML) → esc_html the resolved value.
	 * false = plain field (escaped client-side at render) → insert value raw.
	 * Keys are dotted paths into a bar array.
	 */
	const FIELDS = [
		'content.text'              => true,
		'content.textMobile'        => true,
		'content.button.text'       => false,
		'content.buttonMobile.text' => false,
	];

	/**
	 * Built-in token names. Always "known" (both editions) so they resolve to a
	 * value/fallback and never render as a raw "{token}" — even in Lite, where
	 * the value providers are gated off and every built-in resolves to fallback.
	 */
	const BUILTIN_NAMES = [
		// Visitor / user (per-visitor).
		'user_first_name',
		'user_last_name',
		'user_display_name',
		'user_role',
		'visitor_country',
		// Date / time.
		'current_date',
		'current_time',
		'current_day',
		'current_month',
		'current_year',
		// Site / social proof.
		'site_name',
		'site_tagline',
		'users_count',
		'posts_count',
		// Current post / page context.
		'post_title',
		'post_author',
		'post_category',
		'post_date',
		// WooCommerce.
		'recently_viewed_product',
	];

	/**
	 * Resolve dynamic tokens across all bars' string fields, in place.
	 *
	 * @param  array $bars Bars (post audience/country filter).
	 * @param  array $ctx  Render context from NotificationBarHandle.
	 * @return array Bars with tokens resolved.
	 */
	public static function apply( array $bars, array $ctx ): array {
		foreach ( $bars as &$bar ) {
			if ( ! is_array( $bar ) ) {
				continue;
			}
			foreach ( self::FIELDS as $path => $is_html ) {
				$val = self::getPath( $bar, $path );
				// Skip non-strings and strings that can't contain a token.
				if ( ! is_string( $val ) || false === strpos( $val, '{' ) ) {
					continue;
				}
				self::setPath( $bar, $path, self::resolve( $val, $ctx, $is_html ) );
			}
		}
		unset( $bar );

		return $bars;
	}

	/**
	 * Replace {token}/{token|fallback} in one string.
	 *
	 * @param  string $text    Source string.
	 * @param  array  $ctx     Render context.
	 * @param  bool   $is_html Whether the field is HTML (esc_html the value).
	 * @return string
	 */
	public static function resolve( string $text, array $ctx, bool $is_html ): string {
		$tokens = self::tokens( $ctx );

		return preg_replace_callback(
			'/\{([a-z0-9_]+)(?:\|([^}]*))?\}/',
			static function ( $m ) use ( $tokens, $ctx, $is_html ) {
				$name       = $m[1];
				$registered = isset( $tokens[ $name ] );
				$is_builtin = in_array( $name, self::BUILTIN_NAMES, true );

				// Genuinely unknown token → leave the literal text untouched.
				if ( ! $registered && ! $is_builtin ) {
					return $m[0];
				}

				$value = $registered ? (string) call_user_func( $tokens[ $name ], $ctx ) : '';
				if ( '' === $value ) {
					// Empty value → fallback when one was given, else nothing.
					$value = isset( $m[2] ) ? $m[2] : '';
				}

				return $is_html ? esc_html( $value ) : $value;
			},
			$text
		);
	}

	/**
	 * Token registry: name => callable( array $ctx ): string.
	 *
	 * Built-ins are only registered in Pro (NJT_NOFI_IS_PRO). The
	 * `njt_nofi_dynamic_tokens` filter lets 3rd parties add tokens in any edition.
	 *
	 * @param  array $ctx Render context.
	 * @return array
	 */
	private static function tokens( array $ctx ): array {
		$builtins = [];

		if ( defined( 'NJT_NOFI_IS_PRO' ) && NJT_NOFI_IS_PRO ) {
			$builtins = [
				'user_first_name'         => [ self::class, 'tokenUserFirstName' ],
				'user_last_name'          => [ self::class, 'tokenUserLastName' ],
				'user_display_name'       => [ self::class, 'tokenUserDisplayName' ],
				'user_role'               => [ self::class, 'tokenUserRole' ],
				'visitor_country'         => [ self::class, 'tokenVisitorCountry' ],
				'current_date'            => [ self::class, 'tokenCurrentDate' ],
				'current_time'            => [ self::class, 'tokenCurrentTime' ],
				'current_day'             => [ self::class, 'tokenCurrentDay' ],
				'current_month'           => [ self::class, 'tokenCurrentMonth' ],
				'current_year'            => [ self::class, 'tokenCurrentYear' ],
				'site_name'               => [ self::class, 'tokenSiteName' ],
				'site_tagline'            => [ self::class, 'tokenSiteTagline' ],
				'users_count'             => [ self::class, 'tokenUsersCount' ],
				'posts_count'             => [ self::class, 'tokenPostsCount' ],
				'post_title'              => [ self::class, 'tokenPostTitle' ],
				'post_author'             => [ self::class, 'tokenPostAuthor' ],
				'post_category'           => [ self::class, 'tokenPostCategory' ],
				'post_date'               => [ self::class, 'tokenPostDate' ],
				'recently_viewed_product' => [ self::class, 'tokenRecentlyViewedProduct' ],
			];
		}

		/**
		 * Filter the dynamic-token registry.
		 *
		 * @param array $builtins name => callable( array $ctx ): string.
		 * @param array $ctx      Render context.
		 */
		return apply_filters( 'njt_nofi_dynamic_tokens', $builtins, $ctx );
	}

	// -------------------------------------------------------------------------
	// Dotted-path helpers (read/write nested bar fields without creating keys).
	// -------------------------------------------------------------------------

	/**
	 * Read a dotted path from a bar array. Returns null when any segment is absent.
	 *
	 * @param  array  $bar  Bar array.
	 * @param  string $path Dotted path (e.g. "content.button.text").
	 * @return mixed|null
	 */
	private static function getPath( array $bar, string $path ) {
		$ref = $bar;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) {
				return null;
			}
			$ref = $ref[ $key ];
		}
		return $ref;
	}

	/**
	 * Write a value at a dotted path, only when the full path already exists
	 * (never fabricates structure). Caller reads via getPath() first.
	 *
	 * @param  array  $bar   Bar array (by reference).
	 * @param  string $path  Dotted path.
	 * @param  mixed  $value Value to set.
	 * @return void
	 */
	private static function setPath( array &$bar, string $path, $value ): void {
		$ref = &$bar;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $key, $ref ) ) {
				return;
			}
			$ref = &$ref[ $key ];
		}
		$ref = $value;
	}
}
