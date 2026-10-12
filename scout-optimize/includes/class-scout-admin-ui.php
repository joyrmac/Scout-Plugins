<?php
/**
 * Scout admin kit: the shared frame for every Scout screen in the dashboard.
 *
 * Every Scout plugin (and the Scout theme) ships an identical copy of this
 * file and of assets/scout-admin.css. The class sits inside a conditional so
 * only the first copy is declared, the same way as the shared updater; CI
 * checks the copies match tools/admin-kit/.
 *
 *   Scout_Admin_UI::enqueue( plugins_url( 'assets/scout-admin.css', __FILE__ ) );
 *   Scout_Admin_UI::open( 'AI search', 'One-line description of the screen.' );
 *   ... screen content in .sh-box sections ...
 *   Scout_Admin_UI::close();
 *
 * Branding follows the Site Build hub: the Scout bolt and sign-off show unless
 * a site defines SCOUT_HUB_BRAND as false in wp-config.php (white-label).
 *
 * @package Scout_Admin_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Scout_Admin_UI', false ) ) :

	final class Scout_Admin_UI {

		const VERSION = '1.0.0';

		/** Show the Scout mark and sign-off. */
		public static function branded(): bool {
			return ! defined( 'SCOUT_HUB_BRAND' ) || SCOUT_HUB_BRAND;
		}

		/** Load the kit stylesheet once, whichever copy asks first. */
		public static function enqueue( string $url ): void {
			if ( ! wp_style_is( 'scout-admin', 'enqueued' ) ) {
				wp_enqueue_style( 'scout-admin', $url, array(), self::VERSION );
			}
		}

		/**
		 * Open a screen: eyebrow, title, Scout mark, short description.
		 *
		 * @param string $title   Screen name, e.g. "AI search".
		 * @param string $lede    One or two sentences on what the screen is for.
		 * @param string $section Eyebrow after the brand, defaults to the title.
		 */
		public static function open( string $title, string $lede = '', string $section = '' ): void {
			$brand = self::branded() ? 'Scout' : 'Site Build';
			echo '<div class="wrap sh">';
			echo '<header class="sh-head"><div>';
			echo '<p class="sh-eyebrow">' . esc_html( $brand . ' · ' . ( '' !== $section ? $section : $title ) ) . '</p>';
			echo '<h1>' . esc_html( $title ) . '</h1>';
			echo '</div>';
			if ( self::branded() ) {
				echo '<p class="sh-brand"><span class="sh-bolt" aria-hidden="true"></span>Scout</p>';
			}
			echo '</header>';
			if ( '' !== $lede ) {
				echo '<p class="sh-lede">' . esc_html( $lede ) . '</p>';
			}
			// WordPress prints admin notices right after the first heading in .wrap.
			echo '<hr class="wp-header-end" style="display:none">';
		}

		/** Close a screen with the Scout sign-off. */
		public static function close(): void {
			if ( self::branded() ) {
				echo '<footer class="sh-foot"><span class="sh-bolt" aria-hidden="true"></span><p>Built and looked after by Scout Media &amp; Consulting. <a href="mailto:team@scoutraleigh.com">team@scoutraleigh.com</a> · <a href="tel:+19843259661">(984) 325-9661</a></p></footer>';
			}
			echo '</div>';
		}
	}

endif;
