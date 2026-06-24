<?php
/**
 * BarRegistry — runtime registry for 3rd-party declared notification bars.
 *
 * Lets other plugins/themes inject bars into Notibar's render pipeline WITHOUT
 * touching the admin's native bars. Guarantees:
 *
 *  - Additive-only: the `njt_nofi_register_bars` filter is seeded with an EMPTY
 *    array, so 3rd parties can only APPEND. Native bars are never passed to any
 *    3rd-party callback — they cannot be removed, shadowed, or mutated.
 *  - Native precedence: an injected bar whose id collides with a native bar id
 *    is dropped (the system bar always wins). Injected bars render AFTER native.
 *  - Identical Lite/Pro gating: injected bars are normalized through Schema and
 *    rendered by the same Pro-gated runtime as native bars, so Pro-only fields
 *    are inert in Lite exactly as for native bars.
 *
 * CORE (no Pro-only build markers): ships in both Lite and Pro editions.
 *
 * @package NjtNotificationBar\NotificationBar
 * @since   3.2.0
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

/**
 * Class BarRegistry
 *
 * Static store + load/normalize/merge for runtime-declared bars.
 */
class BarRegistry {

	/**
	 * Bars declared via njt_nofi_register_bar().
	 *
	 * @var array<int,array>
	 */
	private static $registered = [];

	/**
	 * Declare a 3rd-party bar. Held until frontend render.
	 *
	 * @param  array $bar Bar array (may be partial; requires a stable string id).
	 * @return void
	 */
	public static function add( array $bar ): void {
		self::$registered[] = $bar;
	}

	/**
	 * Append normalized injected bars to the native set.
	 *
	 * Native bars are authoritative and never exposed to 3rd-party code. An
	 * injected bar whose id collides with a native id is dropped (native wins).
	 *
	 * @param  array $native Native (admin) bars — already sanitized.
	 * @return array         Native bars followed by surviving injected bars.
	 */
	public static function merge_external( array $native ): array {
		$injected = self::collect();
		if ( empty( $injected ) ) {
			return $native;
		}

		$native_ids = [];
		foreach ( $native as $bar ) {
			if ( is_array( $bar ) && isset( $bar['id'] ) ) {
				$native_ids[ (string) $bar['id'] ] = true;
			}
		}

		$additions = [];
		foreach ( $injected as $id => $bar ) {
			if ( ! isset( $native_ids[ $id ] ) ) {
				$additions[] = $bar;
			}
		}

		return array_merge( $native, $additions );
	}

	/**
	 * Collect + normalize all injected bars (registry + additive filter),
	 * deduped among themselves by id (last declaration wins).
	 *
	 * @return array<string,array> Normalized injected bars keyed by id.
	 */
	private static function collect(): array {
		// Additive-only: seed with an EMPTY array so 3rd parties can only add —
		// native bars are never handed to any callback.
		$filtered = apply_filters( 'njt_nofi_register_bars', [] );
		$injected = array_merge(
			self::$registered,
			is_array( $filtered ) ? $filtered : []
		);

		$out = [];
		foreach ( $injected as $bar ) {
			if ( ! is_array( $bar ) ) {
				continue;
			}
			$normal = self::normalize( $bar );
			if ( null !== $normal ) {
				$out[ $normal['id'] ] = $normal; // dedupe among injected (last wins).
			}
		}

		return $out;
	}

	/**
	 * Normalize one injected bar: sanitize via Schema (fills defaults, validates,
	 * wp_kses text, preserves the caller id). sanitizeExternalBar returns an empty
	 * id when none is usable — that is the signal to DROP the bar.
	 *
	 * @param  array $bar Raw injected bar.
	 * @return array|null Normalized bar, or null to drop.
	 */
	private static function normalize( array $bar ): ?array {
		$clean = Schema::sanitizeExternalBar( $bar );

		if ( '' === $clean['id'] ) {
			self::warn( 'Notibar: skipped a registered bar with a missing or invalid "id".' );
			return null;
		}

		return $clean;
	}

	/**
	 * Debug-only notice for misconfigured registrations.
	 *
	 * @param  string $message
	 * @return void
	 */
	private static function warn( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}
	}
}
