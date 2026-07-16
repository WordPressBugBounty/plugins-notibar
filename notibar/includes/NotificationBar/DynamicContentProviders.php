<?php
/**
 * DynamicContentProviders — built-in token value providers (Pro).
 *
 * Trait consumed by DynamicContent. Each provider returns the RAW value for one
 * built-in token, or '' when unavailable (→ the resolver applies the token's
 * fallback). Escaping is handled centrally by DynamicContent::resolve() per
 * field, so providers never escape. Signatures are uniform — `( array $ctx ): string`
 * — so every provider is a valid `njt_nofi_dynamic_tokens` callable; most ignore
 * $ctx (they read WP/query state directly on the 'wp' hook where apply() runs).
 *
 * Kept in a trait (mirrors SchemaSanitizers) so DynamicContent.php stays the lean
 * engine (grammar + registry + escaping) and the data sources live here.
 *
 * MIRROR: DynamicContent::BUILTIN_NAMES + src/customizer-app/components/fields/
 * DynamicTagPicker.jsx DYNAMIC_TAG_GROUPS — keep token names in lockstep.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.2.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Trait DynamicContentProviders
 */
trait DynamicContentProviders {

	// -------------------------------------------------------------------------
	// Visitor / user — per-visitor (cache-sensitive). Empty when logged out.
	// -------------------------------------------------------------------------

	/**
	 * Current user's first name (display name fallback).
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenUserFirstName( array $ctx ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user = wp_get_current_user();
		$name = trim( (string) $user->first_name );
		return '' !== $name ? $name : (string) $user->display_name;
	}

	/**
	 * Current user's last name.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenUserLastName( array $ctx ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return trim( (string) wp_get_current_user()->last_name );
	}

	/**
	 * Current user's display name.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenUserDisplayName( array $ctx ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		return (string) wp_get_current_user()->display_name;
	}

	/**
	 * Current user's primary role, as its translated display label (e.g. "Editor").
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenUserRole( array $ctx ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$roles = (array) wp_get_current_user()->roles;
		if ( empty( $roles ) ) {
			return '';
		}
		$slug  = (string) reset( $roles );
		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : [];
		$label = isset( $names[ $slug ] ) ? $names[ $slug ] : $slug;
		return function_exists( 'translate_user_role' ) ? translate_user_role( $label ) : $label;
	}

	/**
	 * Visitor's country (ISO 3166-1 alpha-2 code, e.g. "US"). Reuses the Pro geo
	 * resolver used by country targeting. Empty when geo is unavailable/unknown.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenVisitorCountry( array $ctx ): string {
		// Load-bearing guard: VisitorCountry is a Pro-only file (stripped from the
		// Lite build via pro-manifest.json), so this class_exists() check is what
		// keeps the symbol reference below safe in Lite. Do not remove it.
		if ( ! class_exists( __NAMESPACE__ . '\\VisitorCountry' ) ) {
			return '';
		}
		return (string) VisitorCountry::get();
	}

	// -------------------------------------------------------------------------
	// Date / time — site timezone + localized formats. Cache-safe.
	// -------------------------------------------------------------------------

	/**
	 * Today's date in the site's configured, localized date format.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenCurrentDate( array $ctx ): string {
		return (string) date_i18n( get_option( 'date_format' ) );
	}

	/**
	 * Current time in the site's configured, localized time format.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenCurrentTime( array $ctx ): string {
		return (string) date_i18n( get_option( 'time_format' ) );
	}

	/**
	 * Current weekday name, localized (e.g. "Monday").
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenCurrentDay( array $ctx ): string {
		return (string) date_i18n( 'l' );
	}

	/**
	 * Current month name, localized (e.g. "June").
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenCurrentMonth( array $ctx ): string {
		return (string) date_i18n( 'F' );
	}

	/**
	 * Current year (e.g. "2026").
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenCurrentYear( array $ctx ): string {
		return (string) date_i18n( 'Y' );
	}

	// -------------------------------------------------------------------------
	// Site / social proof — cache-safe.
	// -------------------------------------------------------------------------

	/**
	 * Site title.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenSiteName( array $ctx ): string {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Site tagline / description.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenSiteTagline( array $ctx ): string {
		return (string) get_bloginfo( 'description' );
	}

	/**
	 * Total registered users, locale-formatted (social proof). Opt-in cost:
	 * count_users() only runs when a bar actually uses this token.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenUsersCount( array $ctx ): string {
		$counts = count_users();
		$total  = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
		return (string) number_format_i18n( $total );
	}

	/**
	 * Published posts count, locale-formatted.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenPostsCount( array $ctx ): string {
		$counts = wp_count_posts();
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		return (string) number_format_i18n( $total );
	}

	// -------------------------------------------------------------------------
	// Current post / page context — cache-safe per URL. Empty off singular views.
	// -------------------------------------------------------------------------

	/**
	 * Title of the current post/page.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenPostTitle( array $ctx ): string {
		if ( ! is_singular() ) {
			return '';
		}
		return (string) get_the_title( get_queried_object_id() );
	}

	/**
	 * Display name of the current post's author.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenPostAuthor( array $ctx ): string {
		if ( ! is_singular() ) {
			return '';
		}
		$post = get_post( get_queried_object_id() );
		return $post ? (string) get_the_author_meta( 'display_name', $post->post_author ) : '';
	}

	/**
	 * First category name of the current post.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenPostCategory( array $ctx ): string {
		if ( ! is_singular( 'post' ) ) {
			return '';
		}
		$cats = get_the_category( get_queried_object_id() );
		return ! empty( $cats ) ? (string) $cats[0]->name : '';
	}

	/**
	 * Publish date of the current post/page, in the site's date format.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenPostDate( array $ctx ): string {
		if ( ! is_singular() ) {
			return '';
		}
		return (string) get_the_date( '', get_queried_object_id() );
	}

	// -------------------------------------------------------------------------
	// WooCommerce — needs WC. Empty otherwise.
	// -------------------------------------------------------------------------

	/**
	 * Name of the visitor's most-recently-viewed WooCommerce product.
	 *
	 * Reads WooCommerce's own `woocommerce_recently_viewed` cookie (pipe-separated
	 * product IDs, most-recent last). Empty when WC is inactive, the cookie is
	 * absent, or the product no longer exists.
	 *
	 * @param  array $ctx Render context (unused).
	 * @return string
	 */
	private static function tokenRecentlyViewedProduct( array $ctx ): string {
		// Read-only display token sourced from WooCommerce's own cookie; value is
		// parsed to ints (wp_parse_id_list) and never used for any state change, so
		// no nonce applies (WC core ignores the same sniff on this cookie).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! function_exists( 'wc_get_product' ) || empty( $_COOKIE['woocommerce_recently_viewed'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids = wp_parse_id_list( explode( '|', wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) );
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return '';
		}
		// WooCommerce appends the most-recent id to the end of the list.
		$product = wc_get_product( (int) end( $ids ) );
		return $product ? (string) $product->get_name() : '';
	}
}
