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
 * open() also prints the section nav: one pill per screen in the same sidebar
 * menu (Features, Site health, Business info, SEO, AI search, ...), read live
 * from WordPress's own menu, so every Scout screen shows the same row in the
 * same order and a new plugin appears in it without anyone editing a list.
 * Plugins add a short live hint to their pill with the scout_admin_nav_hint
 * filter (slug, e.g. "5 on" or "5 of 7"). A screen hidden from the sidebar
 * passes the slug it belongs under as $nav, so its pill still lights up.
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

		const VERSION = '1.1.0';

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
		 * @param string $nav     Sidebar slug to highlight, for a screen not in the sidebar itself.
		 */
		public static function open( string $title, string $lede = '', string $section = '', string $nav = '' ): void {
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
			self::nav( $nav );
			if ( '' !== $lede ) {
				echo '<p class="sh-lede">' . esc_html( $lede ) . '</p>';
			}
			// WordPress prints admin notices right after the first heading in .wrap.
			echo '<hr class="wp-header-end" style="display:none">';
		}

		/**
		 * The section nav: every screen in this screen's sidebar menu, in sidebar order.
		 *
		 * @param string $current Sidebar slug to highlight; defaults to the screen being viewed.
		 */
		public static function nav( string $current = '' ): void {
			global $submenu;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			$want   = '' !== $current ? $current : $page;
			$parent = '';
			foreach ( (array) $submenu as $slug => $items ) {
				foreach ( (array) $items as $item ) {
					if ( strtok( (string) ( $item[2] ?? '' ), '&' ) === strtok( $want, '&' ) ) {
						$parent = (string) $slug;
						break 2;
					}
				}
			}
			$items = array_values(
				array_filter(
					(array) ( $submenu[ $parent ] ?? array() ),
					static fn( $item ) => current_user_can( (string) ( $item[1] ?? 'manage_options' ) )
				)
			);
			if ( count( $items ) < 2 ) {
				return;
			}
			// The pill that matches the address best: same page and tab, else the page's plain entry.
			$active = '';
			$target = '' !== $current ? $current : $page . ( '' !== $tab ? '&tab=' . $tab : '' );
			foreach ( $items as $item ) {
				if ( $item[2] === $target ) {
					$active = $item[2];
				}
			}
			if ( '' === $active ) {
				foreach ( $items as $item ) {
					if ( $item[2] === strtok( $target, '&' ) ) {
						$active = $item[2];
					}
				}
			}
			echo '<nav class="sh-nav" aria-label="' . esc_attr( self::branded() ? 'Scout' : 'Site Build' ) . '">';
			foreach ( $items as $item ) {
				$slug  = (string) $item[2];
				$url   = false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug );
				$label = trim( wp_strip_all_tags( preg_replace( '#<span[^>]*class="[^"]*(update-plugins|awaiting-mod|count)[^"]*"[^>]*>.*?</span>#s', '', (string) $item[0] ) ) );
				$hint  = (string) apply_filters( 'scout_admin_nav_hint', '', $slug );
				$on    = $slug === $active;
				printf(
					'<a href="%1$s"%2$s>%3$s%4$s</a>',
					esc_url( $url ),
					$on ? ' class="is-active" aria-current="page"' : '',
					esc_html( $label ),
					'' !== $hint ? '<span class="sh-nav-hint">' . esc_html( $hint ) . '</span>' : ''
				);
			}
			echo '</nav>';
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
