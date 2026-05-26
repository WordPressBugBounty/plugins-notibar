<?php
/**
 * GoProPage — Lite-only "Go Pro" admin page.
 *
 * Renders a Free vs Pro comparison table plus an upgrade CTA. Registered only
 * in the Lite edition (see NotificationBarHandleAdmin::njt_nofi_showMenu); Pro
 * users never see it. Pure static content — no React, no REST.
 *
 * @package NjtNotificationBar\NotificationBar
 */

namespace NjtNotificationBar\NotificationBar;

defined( 'ABSPATH' ) || exit;

class GoProPage {

	const PAGE_SLUG = 'notibar-go-pro';

	/**
	 * Feature rows for the comparison table. Each: [ label, free, pro ] where
	 * a value is true (✓), false (✗) or 'soon' (Coming soon badge).
	 * Mirrors https://ninjateam.gitbook.io/notibar/getting-started/free-vs-pro
	 *
	 * @return array<int,array{0:string,1:bool,2:bool|string}>
	 */
	private static function features(): array {
		return [
			// Shared (Free + Pro).
			[ __( 'Multiple bars / messages', 'notibar' ), true, true ],
			[ __( 'Scheduling start & end date/time', 'notibar' ), true, true ],
			[ __( 'Re-display after a specified period', 'notibar' ), true, true ],
			[ __( 'Absolute & fixed display', 'notibar' ), true, true ],
			[ __( 'Different content for mobile', 'notibar' ), true, true ],
			[ __( 'Hide or display on specific pages/posts', 'notibar' ), true, true ],
			[ __( 'Auto ON/OFF daily schedule', 'notibar' ), true, true ],
			[ __( 'One-click to duplicate', 'notibar' ), true, true ],
			[ __( 'Drag and drop to reorder bars', 'notibar' ), true, true ],
			[ __( 'Compatible with cache & lazyload', 'notibar' ), true, true ],
			[ __( '100% mobile-responsive', 'notibar' ), true, true ],
			[ __( 'Import / Export set of bars', 'notibar' ), true, true ],
			[ __( 'HTML & CSS supported', 'notibar' ), true, true ],
			// Pro-only.
			[ __( 'Hide/display on WooCommerce products & custom post types', 'notibar' ), false, true ],
			[ __( 'A/B testing (Rotation mode)', 'notibar' ), false, true ],
			[ __( 'Rotation interval in seconds', 'notibar' ), false, true ],
			[ __( 'Pause rotation on hover', 'notibar' ), false, true ],
			[ __( 'Rotate bars by sequence or random', 'notibar' ), false, true ],
			[ __( 'Advanced reports (Click tracking)', 'notibar' ), false, true ],
			[ __( 'Display bar at bottom', 'notibar' ), false, true ],
			[ __( 'Conditional display by role or user', 'notibar' ), false, true ],
			// Coming soon (Pro).
			[ __( 'Multilingual support', 'notibar' ), false, 'soon' ],
		];
	}

	/**
	 * Pro-only shipped features (free=false, pro=true) for the hero checklist.
	 * Derived from features() so the card and the table never drift.
	 *
	 * @return string[]
	 */
	private static function proHighlights(): array {
		$out = [];
		foreach ( self::features() as $row ) {
			if ( false === $row[1] && true === $row[2] ) {
				$out[] = $row[0];
			}
		}
		return $out;
	}

	/**
	 * Green circular check SVG (shared by the checklist + the table cells).
	 *
	 * @return string
	 */
	private static function proBadge(): string {
		return '<svg class="njt-gopro-badge-svg" width="52" height="20" viewBox="0 0 52 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Pro">'
			. '<rect width="52" height="20" rx="5" fill="url(#njt_gopro_badge_grad)"/>'
			. '<g clip-path="url(#njt_gopro_badge_clip)">'
			. '<path d="M13.5537 4.0127C13.5702 4.02099 13.5845 4.03313 13.5957 4.04785L16.3955 7.77441L19.3145 5.97949C19.4013 5.92713 19.5108 5.99748 19.499 6.09766L18.3525 14.79C18.3375 14.9098 18.2343 15 18.1143 15H8.8877C8.76619 15 8.66396 14.9083 8.64746 14.79L7.50098 6.09766C7.48771 5.99749 7.59914 5.92713 7.68457 5.97949L10.6045 7.77441L13.4023 4.04785C13.4136 4.03293 13.4286 4.02105 13.4453 4.0127C13.462 4.00438 13.4804 4.00005 13.499 4C13.5178 4 13.5369 4.00431 13.5537 4.0127ZM13.5 9C12.3744 9.00003 11.459 9.897 11.459 11C11.459 12.103 12.3744 13 13.5 13C14.6257 13 15.541 12.103 15.541 11C15.541 9.89699 14.6257 9 13.5 9ZM13.5 10.1426C13.9822 10.1426 14.374 10.5258 14.374 11C14.374 11.4725 13.9822 11.8574 13.5 11.8574C13.0179 11.8574 12.626 11.4742 12.626 11C12.626 10.5275 13.0179 10.1426 13.5 10.1426Z" fill="white"/>'
			. '</g>'
			. '<path d="M25.2988 11.2051V9.98633H27.5488C28.0918 9.98633 28.5137 9.84766 28.8145 9.57031C29.1152 9.28906 29.2656 8.89453 29.2656 8.38672V8.375C29.2656 7.86328 29.1152 7.46875 28.8145 7.19141C28.5137 6.91406 28.0918 6.77539 27.5488 6.77539H25.2988V5.54492H27.918C28.4922 5.54492 28.9941 5.66211 29.4238 5.89648C29.8535 6.13086 30.1895 6.46094 30.4316 6.88672C30.6738 7.30859 30.7949 7.80273 30.7949 8.36914V8.38086C30.7949 8.94336 30.6738 9.4375 30.4316 9.86328C30.1895 10.2852 29.8535 10.6152 29.4238 10.8535C28.9941 11.0879 28.4922 11.2051 27.918 11.2051H25.2988ZM24.543 14V5.54492H26.0547V14H24.543ZM32.3125 14V7.5957H33.7715V8.58008H33.8711C33.9805 8.23633 34.1758 7.96875 34.457 7.77734C34.7422 7.58594 35.0957 7.49023 35.5176 7.49023C35.627 7.49023 35.7344 7.49805 35.8398 7.51367C35.9492 7.52539 36.0391 7.54102 36.1094 7.56055V8.86719C35.9922 8.83984 35.873 8.82031 35.752 8.80859C35.6348 8.79297 35.5117 8.78516 35.3828 8.78516C35.0586 8.78516 34.7754 8.8457 34.5332 8.9668C34.291 9.08789 34.1035 9.26172 33.9707 9.48828C33.8379 9.71094 33.7715 9.97461 33.7715 10.2793V14H32.3125ZM39.9062 14.1289C39.2695 14.1289 38.7207 13.9961 38.2598 13.7305C37.7988 13.4609 37.4434 13.0781 37.1934 12.582C36.9473 12.0859 36.8242 11.4922 36.8242 10.8008V10.7891C36.8242 10.1055 36.9492 9.51562 37.1992 9.01953C37.4492 8.51953 37.8027 8.13672 38.2598 7.87109C38.7207 7.60547 39.2695 7.47266 39.9062 7.47266C40.543 7.47266 41.0898 7.60547 41.5469 7.87109C42.0078 8.13672 42.3633 8.51758 42.6133 9.01367C42.8633 9.50977 42.9883 10.1016 42.9883 10.7891V10.8008C42.9883 11.4922 42.8633 12.0859 42.6133 12.582C42.3672 13.0781 42.0137 13.4609 41.5527 13.7305C41.0957 13.9961 40.5469 14.1289 39.9062 14.1289ZM39.9062 12.9453C40.2422 12.9453 40.5273 12.8613 40.7617 12.6934C41 12.5215 41.1816 12.2773 41.3066 11.9609C41.4316 11.6406 41.4941 11.2559 41.4941 10.8066V10.7949C41.4941 10.3418 41.4316 9.95703 41.3066 9.64062C41.1816 9.32031 41 9.07617 40.7617 8.9082C40.5273 8.73633 40.2422 8.65039 39.9062 8.65039C39.5703 8.65039 39.2832 8.73633 39.0449 8.9082C38.8066 9.07617 38.625 9.32031 38.5 9.64062C38.375 9.95703 38.3125 10.3418 38.3125 10.7949V10.8066C38.3125 11.2559 38.375 11.6406 38.5 11.9609C38.625 12.2812 38.8047 12.5254 39.0391 12.6934C39.2773 12.8613 39.5664 12.9453 39.9062 12.9453Z" fill="white"/>'
			. '<defs>'
			. '<linearGradient id="njt_gopro_badge_grad" x1="-3.05882" y1="1.11111" x2="63.4284" y2="-8.05333" gradientUnits="userSpaceOnUse">'
			. '<stop stop-color="#FECA88"/>'
			. '<stop offset="0.500405" stop-color="#E463D8"/>'
			. '<stop offset="1" stop-color="#7890FC"/>'
			. '</linearGradient>'
			. '<clipPath id="njt_gopro_badge_clip"><rect width="12" height="12" fill="white" transform="translate(7.5 4)"/></clipPath>'
			. '</defs>'
			. '</svg>';
	}

	private static function tick(): string {
		return '<svg class="njt-gopro-tick" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<circle cx="10" cy="10" r="10" fill="#3BA759"/>'
			. '<path d="M7 11.125L8.63636 13L13 8" fill="#3BA759"/>'
			. '<path d="M7 11.125L8.63636 13L13 8" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Grey circular cross SVG for unavailable (Free) cells.
	 *
	 * @return string
	 */
	private static function cross(): string {
		return '<svg class="njt-gopro-cross" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<path d="M10 20C15.5228 20 20 15.5228 20 10C20 4.47715 15.5228 0 10 0C4.47715 0 0 4.47715 0 10C0 15.5228 4.47715 20 10 20Z" fill="#C1C3D0"/>'
			. '<path d="M12.5 7.5L7.5 12.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<path d="M7.5 7.5L12.5 12.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Green "verified" badge SVG shown beside the testimonial name.
	 *
	 * @return string
	 */
	private static function verifiedBadge(): string {
		return '<svg class="njt-gopro-verified" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Verified">'
			. '<path d="M15.6348 7.00811C15.1496 7.58704 15.1496 8.41296 15.6348 8.99189C16.38 9.88069 15.9296 11.2113 14.7834 11.5099C14.0364 11.7042 13.5318 12.372 13.5701 13.1147C13.629 14.2544 12.4505 15.0769 11.3409 14.6708C10.618 14.4062 9.80029 14.6611 9.37756 15.2837C8.72843 16.2388 7.27157 16.2388 6.62244 15.2837C6.19912 14.6611 5.38196 14.4062 4.65913 14.6708C3.54954 15.0769 2.37096 14.2544 2.42992 13.1147C2.46824 12.372 1.96356 11.7042 1.21656 11.5099C0.0704104 11.2119 -0.380031 9.88069 0.365202 8.99189C0.850428 8.41296 0.850428 7.58704 0.365202 7.00811C-0.380031 6.11931 0.0704104 4.78867 1.21656 4.49014C1.96238 4.29584 2.46765 3.62797 2.42933 2.88532C2.36978 1.74558 3.54836 0.923066 4.65854 1.32923C5.38137 1.59377 6.19912 1.33886 6.62185 0.716304C7.27098 -0.238768 8.72784 -0.238768 9.37697 0.716304C9.80029 1.33886 10.6175 1.59377 11.3403 1.32923C12.4499 0.923066 13.6284 1.74558 13.5695 2.88532C13.5312 3.62797 14.0358 4.29584 14.7829 4.49014C15.9296 4.7881 16.3794 6.11931 15.6348 7.00811Z" fill="#3BA759"/>'
			. '<g filter="url(#njt_gopro_verified_shadow)">'
			. '<path d="M6.78942 11C6.58446 11 6.38809 10.9185 6.24267 10.7732L4.22629 8.7507C3.92457 8.44815 3.92457 7.9573 4.22629 7.65475C4.528 7.35221 5.0175 7.35221 5.31921 7.65475L6.78942 9.12903L10.6808 5.22691C10.9825 4.92436 11.472 4.92436 11.7737 5.22691C12.0754 5.52946 12.0754 6.02031 11.7737 6.32286L7.33617 10.7727C7.19075 10.9185 6.99438 11 6.78942 11Z" fill="white"/>'
			. '</g>'
			. '<defs>'
			. '<filter id="njt_gopro_verified_shadow" x="4" y="5" width="8" height="6.5" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">'
			. '<feFlood flood-opacity="0" result="BackgroundImageFix"/>'
			. '<feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha"/>'
			. '<feOffset dy="0.5"/>'
			. '<feComposite in2="hardAlpha" operator="out"/>'
			. '<feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.15 0"/>'
			. '<feBlend mode="normal" in2="BackgroundImageFix" result="njt_gopro_verified_fx"/>'
			. '<feBlend mode="normal" in="SourceGraphic" in2="njt_gopro_verified_fx" result="shape"/>'
			. '</filter>'
			. '</defs>'
			. '</svg>';
	}

	/**
	 * Render one Free/Pro cell.
	 *
	 * @param bool|string $value true | false | 'soon'.
	 * @return string HTML.
	 */
	private static function cell( $value ): string {
		if ( 'soon' === $value ) {
			return '<span class="njt-gopro-soon">' . esc_html__( 'Coming soon', 'notibar' ) . '</span>';
		}
		if ( $value ) {
			return '<span class="njt-gopro-yes" aria-label="' . esc_attr__( 'Included', 'notibar' ) . '">' . self::tick() . '</span>';
		}
		return '<span class="njt-gopro-no" aria-label="' . esc_attr__( 'Not included', 'notibar' ) . '">' . self::cross() . '</span>';
	}

	/**
	 * Admin page callback.
	 *
	 * @return void
	 */
	public static function render(): void {
		$url     = defined( 'NJT_NOFI_UPGRADE_URL' ) ? NJT_NOFI_UPGRADE_URL : '';
		$support = 'https://ninjateam.org/support/';
		$assets  = defined( 'NJT_NOFI_PLUGIN_URL' ) ? NJT_NOFI_PLUGIN_URL . 'assets/admin/gopro/' : '';
		$check   = self::tick();
		$attrs   = 'href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"';
		$label   = esc_html__( 'Get Notibar Pro', 'notibar' );
		$cta_lg  = '<a class="njt-gopro-btn njt-gopro-btn--primary njt-gopro-btn--lg" ' . $attrs . '>' . $label . '</a>';
		$cta     = '<a class="njt-gopro-btn njt-gopro-btn--primary" ' . $attrs . '>' . $label . '</a>';
		?>
		<div class="wrap njt-gopro">

			<?php /* Hero — pitch + feature card */ ?>
			<div class="njt-gopro-hero">
				<div class="njt-gopro-hero__left">
					<h1><?php esc_html_e( 'Unlock Notibar Pro', 'notibar' ); ?></h1>
					<p class="njt-gopro-hero__lead">
						<?php esc_html_e( 'Upgrade to Notibar Pro to rotate and A/B test bars, target by audience and product, and measure clicks, everything you need to turn announcements into conversions.', 'notibar' ); ?>
					</p>
					<div><?php echo $cta_lg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_html ?></div>
				</div>

				<div class="njt-gopro-hero__card">
					<div class="njt-gopro-hero__card-head">
						<h2><?php esc_html_e( 'Notibar Pro', 'notibar' ); ?></h2>
						<?php echo self::proBadge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
					</div>
					<p class="njt-gopro-hero__card-sub">
						<?php esc_html_e( 'Get all features when you upgrade to Notibar Pro.', 'notibar' ); ?>
					</p>
					<hr class="njt-gopro-sep" />
					<ul class="njt-gopro-checklist">
						<?php foreach ( self::proHighlights() as $label ) : ?>
							<li><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?><span><?php echo esc_html( $label ); ?></span></li>
						<?php endforeach; ?>
						<li><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?><span><?php esc_html_e( 'Fast updates', 'notibar' ); ?></span></li>
						<li><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?><span><?php esc_html_e( '1-1 Live Chat VIP support', 'notibar' ); ?></span></li>
					</ul>
				</div>
			</div>

			<?php /* Comparison table */ ?>
			<div class="njt-gopro-compare">
				<h2><?php esc_html_e( 'Compare Plans and Features', 'notibar' ); ?></h2>
				<p class="njt-gopro-compare__sub">
					<?php esc_html_e( 'Check the comparison table to see what each plan offers.', 'notibar' ); ?>
				</p>

				<table class="njt-gopro-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Features', 'notibar' ); ?></th>
							<th class="njt-gopro-col"><?php esc_html_e( 'Notibar Free', 'notibar' ); ?></th>
							<th class="njt-gopro-col"><?php esc_html_e( 'Notibar Pro', 'notibar' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::features() as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row[0] ); ?></td>
								<td class="njt-gopro-col"><?php echo self::cell( $row[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns escaped HTML ?></td>
								<td class="njt-gopro-col"><?php echo self::cell( $row[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() returns escaped HTML ?></td>
							</tr>
						<?php endforeach; ?>
						<tr class="njt-gopro-table__cta-row">
							<td></td>
							<td class="njt-gopro-col"><span class="njt-gopro-current"><?php esc_html_e( 'Your current plan', 'notibar' ); ?></span></td>
							<td class="njt-gopro-col"><?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_html ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php
			/* Testimonial — replace the quote/attribution with a real customer
			   review when available (kept generic + unattributed by default). */
			?>
			<div class="njt-gopro-testimonial">
				<div class="njt-gopro-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
				<p class="njt-gopro-quote">
					<?php esc_html_e( 'Join the website owners using Notibar Pro to put the right message in front of the right visitors, and see exactly what converts.', 'notibar' ); ?>
				</p>
				<div class="njt-gopro-author">
					<img class="njt-gopro-author__avatar" src="<?php echo esc_url( $assets . 'tes-avatar.png' ); ?>" alt="" width="50" height="50" loading="lazy" />
					<div class="njt-gopro-author__meta">
						<span class="njt-gopro-author__name">Markus Schmidt<?php echo self::verifiedBadge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?></span>
						<span class="njt-gopro-author__role"><?php esc_html_e( 'Ecommerce Project Lead, Germany', 'notibar' ); ?></span>
					</div>
				</div>
			</div>

			<?php /* Trust cards */ ?>
			<div class="njt-gopro-cards">
				<div class="njt-gopro-card">
					<img class="njt-gopro-card__img" src="<?php echo esc_url( $assets . 'money-back.svg' ); ?>" alt="" width="96" height="96" />
					<h3><?php esc_html_e( '30-day Money Back Guarantee', 'notibar' ); ?></h3>
					<div class="njt-gopro-pay">
						<img src="<?php echo esc_url( $assets . 'paypal.svg' ); ?>" alt="PayPal" />
						<img src="<?php echo esc_url( $assets . 'stripe.svg' ); ?>" alt="Stripe" />
						<img src="<?php echo esc_url( $assets . 'visa.svg' ); ?>" alt="Visa" />
						<img src="<?php echo esc_url( $assets . 'amex.svg' ); ?>" alt="American Express" />
						<img src="<?php echo esc_url( $assets . 'credit-card.svg' ); ?>" alt="Credit card" />
					</div>
				</div>
				<div class="njt-gopro-card">
					<img class="njt-gopro-card__img njt-gopro-card__img--avatars" src="<?php echo esc_url( $assets . 'avatars.webp' ); ?>" alt="" width="180" />
					<h3><?php esc_html_e( 'Need help?', 'notibar' ); ?></h3>
					<a class="njt-gopro-btn njt-gopro-btn--outline" href="<?php echo esc_url( $support ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Chat now', 'notibar' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
		self::styles();
	}

	/**
	 * Scoped inline styles for the page — one static admin page does not justify
	 * a build entry or an enqueued stylesheet.
	 *
	 * @return void
	 */
	private static function styles(): void {
		?>
		<style>
			.njt-gopro { max-width: 1044px; margin: 24px auto 0; }
			.njt-gopro h1, .njt-gopro h2, .njt-gopro h3 { padding: 0; }

			/* Hero — two columns: pitch (tinted) + feature card */
			.njt-gopro-hero {
				display: grid; grid-template-columns: 1fr 1fr;
				background: #fff; border: 1px solid #e9eaf0; border-radius: 10px; overflow: hidden;
			}
			.njt-gopro-hero__left {
				display: flex; flex-direction: column; justify-content: center;
				gap: 18px; padding: 48px; text-align: left;
				background: url( <?php echo esc_url( NJT_NOFI_PLUGIN_URL . '/assets/admin/gopro/bg-1.png' ); ?> ) center/cover no-repeat;
			}
			.njt-gopro-hero__left h1 { margin: 0; font-size: 30px; font-weight: 800; line-height: 1.2; }
			.njt-gopro-hero__lead { margin: 0; margin-bottom: 16px; font-size: 14px; color: #50575e; max-width: 440px; line-height: 1.6; }
			.njt-gopro-hero__card { padding: 48px; }
			.njt-gopro-hero__card-head { display: flex; align-items: center; gap: 10px; }
			.njt-gopro-hero__card-head h2 { margin: 0; font-size: 24px; font-weight: 700; }
			.njt-gopro-hero__card-sub { font-size: 13px; color: #86889a; margin: 8px 0 0; }
			.njt-gopro-sep { border: 0; border-top: 1px solid #f3f4fa; margin: 18px 0; }
			.njt-gopro-badge-svg { display: block; flex: 0 0 auto; }
			.njt-gopro-checklist { list-style: none; margin: 0; padding: 0; }
			.njt-gopro-checklist li { display: flex; align-items: center; gap: 12px; padding: 7px 0; font-size: 14px; color: #1d2327; }
			.njt-gopro-tick { flex: 0 0 auto; vertical-align: middle; }

			/* Comparison */
			.njt-gopro-compare { text-align: center; margin-top: 72px; }
			.njt-gopro-compare h2 { font-size: 30px; font-weight: 800; line-height: 1; margin: 0 0 12px; }
			.njt-gopro-compare__sub { color: #50575e; margin: 0 0 28px; font-size: 14px; }
			.njt-gopro-table {
				width: 100%; border-collapse: separate; border-spacing: 0;
				background: #fff; border: 1px solid #e9eaf0; border-radius: 10px; overflow: hidden; text-align: left;
			}
			.njt-gopro-table th, .njt-gopro-table td {
				padding: 14px 18px; border-bottom: 1px solid #fafafa;
			}
			.njt-gopro-table thead th { background: #333333; color: #fff; font-weight: 600; }
			.njt-gopro-table tbody tr:hover td { background: #fbfbfd; }
			.njt-gopro-table tbody tr:last-child td { border-bottom: 0; }
			.njt-gopro-col { width: 170px; text-align: center; }
			.njt-gopro-table th.njt-gopro-col { text-align: center; }
			.njt-gopro-yes, .njt-gopro-no { line-height: 0; }
			.njt-gopro-tick, .njt-gopro-cross { vertical-align: middle; }
			.njt-gopro-soon { font-size: 12px; font-style: italic; color: #86889a; }
			.njt-gopro-table__cta-row td { background: #fafafa; }
			.njt-gopro-table__cta-row:hover td { background: #fafafa; }
			.njt-gopro-current {
				display: inline-flex; align-items: center; height: 38px; padding: 0 14px; font-size: 13px;
				color: #86889a; background: #fff; border: 1px solid #e2e4e7; border-radius: 4px;
			}

			/* Testimonial */
			.njt-gopro-testimonial {
				text-align: center; margin: 30px auto 0; max-width: 1044px;
				background: #fff; border: 1px solid #e9eaf0; border-radius: 10px; padding: 44px;
			}
			.njt-gopro-stars { color: #fec900; font-size: 22px; letter-spacing: 3px; }
			.njt-gopro-quote { font-size: 17px; color: #1d2327; margin: 14px auto 0; max-width: 640px; line-height: 1.6; }
			.njt-gopro-author { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 20px; }
			.njt-gopro-author__avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; object-position: center; }
			.njt-gopro-author__meta { display: flex; flex-direction: column; text-align: left; }
			.njt-gopro-author__name { display: inline-flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 600; color: #1d2327; margin-bottom: 6px; }
			.njt-gopro-verified { flex: 0 0 auto; vertical-align: middle; }
			.njt-gopro-author__role { font-size: 13px; color: #86889a; }

			/* Trust cards */
			.njt-gopro-cards { display: flex; gap: 30px; margin-top: 30px; flex-wrap: wrap; }
			.njt-gopro-card {
				flex: 1 1 300px; display: flex; flex-direction: column; align-items: center;
				justify-content: center; gap: 14px; text-align: center;
				background: #fff; border: 1px solid #e9eaf0; border-radius: 10px; padding: 48px;
			}
			.njt-gopro-card__img { height: 96px; width: auto; }
			.njt-gopro-card__img--avatars { height: auto; max-width: 200px; }
			.njt-gopro-card h3 { margin: 12px 0; font-size: 22px; font-weight: 600; line-height: 1; }
			.njt-gopro-card__sub { color: #86889a; margin: 0; font-size: 14px; }
			.njt-gopro-pay { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; margin: 0; }
			.njt-gopro-pay img { height: 26px; width: auto; }

			/* Custom buttons — matched to the YaySwatches shadcn button look */
			.njt-gopro-btn {
				display: inline-flex; align-items: center; justify-content: center; gap: 8px;
				height: 36px; padding: 0 16px; border-radius: 6px;
				font-size: 13px; font-weight: 500; line-height: 1; text-decoration: none;
				border: 1px solid transparent; cursor: pointer;
				box-shadow: 0 1px 2px rgba( 0, 0, 0, 0.05 );
				transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
			}
			.njt-gopro-btn:focus { outline: 2px solid rgba( 56, 88, 233, 0.5 ); outline-offset: 1px; }
			.njt-gopro-btn--lg { height: 44px; padding: 0 24px; font-size: 14px; }
			.njt-gopro-btn--primary { background: #3858e9; color: #fff; }
			.njt-gopro-btn--primary:hover,
			.njt-gopro-btn--primary:focus { background: #2a45d4; color: #fff; }
			.njt-gopro-btn--outline { background: #fff; color: #1d2327; border-color: #e2e4e7; }
			.njt-gopro-btn--outline:hover,
			.njt-gopro-btn--outline:focus { background: #f2f5f9; color: #1d2327; }

			/* Responsive — stack the hero + cards on narrow admin widths */
			@media ( max-width: 782px ) {
				.njt-gopro-hero { grid-template-columns: 1fr; }
				.njt-gopro-hero__left, .njt-gopro-hero__card { padding: 28px; }
				.njt-gopro-compare { margin-top: 48px; }
			}
		</style>
		<?php
	}

	/**
	 * Highlight the "Go Pro" menu item. Hooked on admin_head only in Lite.
	 *
	 * @return void
	 */
	public static function menuHighlightCss(): void {
		echo '<style>#adminmenu a[href*="page=' . esc_attr( self::PAGE_SLUG )
			. '"]{color:#ffb900;font-weight:600;}</style>';
	}
}
