<?php
/**
 * Shared translatable-string schema for the multilingual bridges — Notibar v3.1.4.
 *
 * Single source of truth for WHICH bar fields are translatable and HOW their
 * string names are derived. Both WpmlBridge and PolylangBridge consume this so
 * the 6-field-per-bar contract lives in exactly one place (DRY).
 *
 * String name pattern (stable, used as the translation key in both engines):
 *   bar-{id}-text | bar-{id}-textMobile | bar-{id}-buttonText |
 *   bar-{id}-buttonUrl | bar-{id}-buttonTextMobile | bar-{id}-buttonUrlMobile
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.1.4
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class BarTranslatableStrings
 *
 * Stateless helper. Maps the translatable fields of a decoded bars array to
 * named strings and writes resolved translations back into the same shape.
 */
class BarTranslatableStrings {

	/**
	 * Translatable fields: name-suffix => path within a single bar array.
	 *
	 * The suffix is appended to "bar-{id}-" to form the full string name.
	 */
	const FIELDS = [
		'text'             => [ 'content', 'text' ],
		'textMobile'       => [ 'content', 'textMobile' ],
		'buttonText'       => [ 'content', 'button', 'text' ],
		'buttonUrl'        => [ 'content', 'button', 'url' ],
		'buttonTextMobile' => [ 'content', 'buttonMobile', 'text' ],
		'buttonUrlMobile'  => [ 'content', 'buttonMobile', 'url' ],
	];

	/**
	 * Build the canonical string map for a bars array.
	 *
	 * Returns an associative array: string_name => string_value.
	 * Empty values are omitted — no point registering blank strings.
	 *
	 * @param  array $bars Decoded bars array.
	 * @return array       Map of name => value for all non-empty translatable fields.
	 */
	public static function collect( array $bars ): array {
		$map = [];

		foreach ( $bars as $bar ) {
			if ( ! is_array( $bar ) || empty( $bar['id'] ) ) {
				continue;
			}

			$id = $bar['id'];

			foreach ( self::FIELDS as $suffix => $path ) {
				$value = self::dig( $bar, $path );
				// Strict !== '' (not !empty) so the literal "0" is kept — it is a
				// legitimate user-content value.
				if ( is_string( $value ) && '' !== $value ) {
					$map[ "bar-{$id}-{$suffix}" ] = $value;
				}
			}
		}

		return $map;
	}

	/**
	 * Walk each bar's translatable fields and write back the resolved value.
	 *
	 * For every non-empty field, calls $resolver( string $name, string $original )
	 * and stores the (string-cast) return value back at the same path. The
	 * resolver is expected to fall back to $original when no translation exists.
	 *
	 * @param  array    $bars     Decoded bars array.
	 * @param  callable $resolver fn( string $name, string $original ): string
	 * @return array              Bars array with translated string fields substituted.
	 */
	public static function apply( array $bars, callable $resolver ): array {
		foreach ( $bars as &$bar ) {
			if ( ! is_array( $bar ) || empty( $bar['id'] ) ) {
				continue;
			}

			$id = $bar['id'];

			foreach ( self::FIELDS as $suffix => $path ) {
				$value = self::dig( $bar, $path );
				if ( is_string( $value ) && '' !== $value ) {
					$translated   = (string) $resolver( "bar-{$id}-{$suffix}", $value );
					self::set( $bar, $path, $translated );
				}
			}
		}
		unset( $bar ); // break reference from foreach.

		return $bars;
	}

	/**
	 * Read a nested value by path, or null if any segment is missing.
	 *
	 * @param  array $arr  Source array.
	 * @param  array $path Ordered list of keys.
	 * @return mixed       The value, or null if the path does not resolve.
	 */
	private static function dig( array $arr, array $path ) {
		$cursor = $arr;
		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! isset( $cursor[ $key ] ) ) {
				return null;
			}
			$cursor = $cursor[ $key ];
		}
		return $cursor;
	}

	/**
	 * Write a value at a nested path. Assumes the path already exists (callers
	 * only set fields that dig() confirmed were present non-empty strings).
	 *
	 * @param  array $arr   Target array (by reference).
	 * @param  array $path  Ordered list of keys.
	 * @param  mixed $value Value to store at the leaf.
	 * @return void
	 */
	private static function set( array &$arr, array $path, $value ): void {
		$ref = &$arr;
		foreach ( $path as $key ) {
			if ( ! is_array( $ref ) ) {
				return;
			}
			$ref = &$ref[ $key ];
		}
		$ref = $value;
	}
}
