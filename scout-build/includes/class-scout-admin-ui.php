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
 * open() also prints the section nav, read live from WordPress's own menu: a
 * short row of groups (Home, Search, Website, Business, Reviews, Chat) with the open
 * group's screens as tabs underneath. Every Scout screen shows the same nav,
 * and a new plugin lands in the right group without anyone editing a list
 * (scout_admin_nav_group moves a screen to another group).
 *
 * A long Scout sidebar menu (more than six screens) gets the same treatment:
 * one sidebar link per group, labelled with the group's name. The other
 * screens stay registered and reachable and are hidden from the sidebar only.
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

		const VERSION = '1.5.1';

		/** Show the Scout mark and sign-off. */
		public static function branded(): bool {
			return ! defined( 'SCOUT_HUB_BRAND' ) || SCOUT_HUB_BRAND;
		}

		/**
		 * Load the kit stylesheet once. When this class came from a plugin, use the
		 * stylesheet beside it, so the CSS always matches the markup even if an
		 * older copy (in a theme, say) is the one asking.
		 */
		public static function enqueue( string $url ): void {
			self::$kit = true;
			if ( wp_style_is( 'scout-admin', 'enqueued' ) ) {
				return;
			}
			$own = dirname( __DIR__ ) . '/assets/scout-admin.css';
			if ( defined( 'WP_PLUGIN_DIR' ) && is_readable( $own ) && 0 === strpos( wp_normalize_path( $own ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
				$url = plugins_url( 'assets/scout-admin.css', __DIR__ );
			}
			wp_enqueue_style( 'scout-admin', $url, array(), self::VERSION );
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
			self::lockup();
			echo '</header>';
			if ( ! self::$nav_printed ) {
				self::nav( $nav );
			}
			if ( '' !== $lede ) {
				echo '<p class="sh-lede">' . esc_html( $lede ) . '</p>';
			}
			// WordPress prints admin notices right after the first heading in .wrap.
			echo '<hr class="wp-header-end" style="display:none">';
		}

		/**
		 * The client's logo beside the Scout mark, top right of every screen,
		 * so it is plain whose site this is. Logo order: the scout_admin_client_logo
		 * filter, the theme's custom logo, the Site Icon; with none, the site name.
		 */
		private static function lockup(): void {
			$name = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
			$logo = (string) apply_filters( 'scout_admin_client_logo', '' );
			if ( '' === $logo ) {
				$id   = (int) get_theme_mod( 'custom_logo' );
				$logo = $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
			}
			if ( '' === $logo && function_exists( 'get_site_icon_url' ) ) {
				$logo = (string) get_site_icon_url( 128 );
			}
			echo '<div class="sh-lockup">';
			echo '<a class="sh-client" href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener" title="' . esc_attr( $name ) . '">';
			if ( '' !== $logo ) {
				echo '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">';
			}
			echo '<span class="sh-client-name">' . esc_html( $name ) . '</span></a>';
			if ( self::branded() ) {
				echo '<span class="sh-lockup-x" aria-hidden="true"></span>';
				echo '<p class="sh-brand"><span class="sh-bolt" aria-hidden="true"></span>Scout</p>';
			}
			echo '</div>';
		}

		/** The nav groups, in the order they show. */
		const GROUPS = array(
			'home'     => 'Home',
			'search'   => 'Search',
			'website'  => 'Website',
			'business' => 'Business',
			'reviews'  => 'Reviews',
			'chat'     => 'Chat',
		);

		/** Scout sidebar menus the kit tidies. */
		const MENUS = array( 'scout-build', 'recon-build', 'site-build', 'scout' );

		/** A Scout menu longer than this shows one sidebar link per group instead. */
		const COMPACT_AFTER = 6;

		/** @var array<string,string> Original sidebar label per slug, for the section nav. */
		private static $labels = array();

		/** @var array<string,string> Slug => the slug of its group's sidebar link. */
		private static $leaders = array();

		/** @var bool A Scout screen loaded the kit styles (it prints its own nav). */
		private static $kit = false;

		/** @var bool The section nav already printed on this screen. */
		private static $nav_printed = false;

		/** @var string Sidebar slug of a plain WordPress screen that gets the nav on top. */
		private static $screen_slug = '';

		/**
		 * Shorten a long Scout sidebar menu to one link per group (Home,
		 * Search, Website, Business, Reviews, Chat). Every screen stays registered and
		 * reachable; the others are hidden from the sidebar only, and the
		 * section nav on each screen leads to them.
		 */
		public static function compact_menu(): void {
			global $submenu;
			foreach ( self::MENUS as $parent ) {
				if ( empty( $submenu[ $parent ] ) || count( $submenu[ $parent ] ) <= self::COMPACT_AFTER ) {
					continue;
				}
				// Same order as the section nav: group by group, sidebar order inside each.
				$order = array_flip( array_keys( self::GROUPS ) );
				$keyed = array();
				foreach ( array_values( $submenu[ $parent ] ) as $i => $item ) {
					$keyed[] = array( $order[ self::group( (string) ( $item[2] ?? '' ), self::clean( (string) ( $item[0] ?? '' ) ) ) ], $i, $item );
				}
				usort( $keyed, static fn( $x, $y ) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1] );
				$submenu[ $parent ] = array_column( $keyed, 2 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
				$lead   = array(); // group => index of its sidebar link.
				$bubble = array(); // group => first count bubble in it.
				foreach ( $submenu[ $parent ] as $i => $item ) {
					$slug  = (string) ( $item[2] ?? '' );
					$raw   = (string) ( $item[0] ?? '' );
					$label = self::clean( $raw );
					$group = self::group( $slug, $label );
					self::$labels[ $slug ] = $label;
					if ( ! isset( $bubble[ $group ] ) && preg_match( '#<span[^>]*class="[^"]*(update-plugins|awaiting-mod|count)[^"]*"[^>]*>.*?</span>(\s*</span>)?#s', $raw, $m ) ) {
						$bubble[ $group ] = ' ' . $m[0];
					}
					if ( ! isset( $lead[ $group ] ) && current_user_can( (string) ( $item[1] ?? 'manage_options' ) ) ) {
						$lead[ $group ] = $i;
					}
				}
				foreach ( $submenu[ $parent ] as $i => $item ) {
					$slug  = (string) ( $item[2] ?? '' );
					$group = self::group( $slug, self::$labels[ $slug ] );
					if ( ! isset( $lead[ $group ] ) ) {
						continue;
					}
					$item[3] = $item[3] ?? self::$labels[ $slug ];
					if ( $lead[ $group ] === $i ) {
						$item[0] = esc_html( self::GROUPS[ $group ] ) . ( $bubble[ $group ] ?? '' );
					} else {
						$item[4] = trim( ( $item[4] ?? '' ) . ' sh-menu-hidden' );
					}
					self::$leaders[ $slug ] = (string) $submenu[ $parent ][ $lead[ $group ] ][2];
					$submenu[ $parent ][ $i ] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- relabel and hide only.
				}
			}
		}

		/** On a hidden screen, light up its group's sidebar link. */
		public static function current_link( $file ) {
			if ( ! self::$leaders ) {
				return $file;
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			foreach ( array( (string) $file, $page . '&tab=' . $tab, $page ) as $slug ) {
				if ( '' !== $slug && isset( self::$leaders[ $slug ] ) ) {
					return self::$leaders[ $slug ];
				}
			}
			return $file;
		}

		/** Hide the grouped screens' sidebar links. */
		public static function menu_css(): void {
			if ( self::$leaders ) {
				echo '<style>#adminmenu .sh-menu-hidden{display:none}</style>';
			}
		}

		/** A sidebar label without its count bubble. */
		private static function clean( string $raw ): string {
			return trim( wp_strip_all_tags( preg_replace( '#<span[^>]*class="[^"]*(update-plugins|awaiting-mod|count)[^"]*"[^>]*>.*?</span>#s', '', $raw ) ) );
		}

		/**
		 * WordPress's own screens inside a shortened Scout menu (a review list,
		 * Chat questions, team members) do not print the section nav, and their
		 * sibling screens are hidden from the sidebar. Give those screens the
		 * same nav on top, so no screen is a dead end.
		 */
		public static function screen_nav_prepare(): void {
			// WordPress's own screens (a post list, an edit screen) load the kit styles
			// but never print the kit nav; only admin.php?page= screens do.
			if ( ! self::$leaders || ( self::$kit && ! empty( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
				return;
			}
			global $typenow, $pagenow;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			$slug = '';
			foreach ( array( '' !== $tab ? $page . '&tab=' . $tab : '', $page ) as $try ) {
				if ( '' !== $try && isset( self::$leaders[ $try ] ) ) {
					$slug = $try;
					break;
				}
			}
			if ( '' === $slug && '' === $page && $typenow && in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php', 'edit-tags.php', 'term.php' ), true ) ) {
				$list = 'edit.php?post_type=' . $typenow;
				$slug = isset( self::$leaders[ $list ] ) ? $list : '';
			}
			if ( '' === $slug ) {
				return;
			}
			self::$screen_slug = $slug;
			$own = dirname( __DIR__ ) . '/assets/scout-admin.css';
			if ( is_readable( $own ) && defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( $own ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
				wp_enqueue_style( 'scout-admin', plugins_url( 'assets/scout-admin.css', __DIR__ ), array(), self::VERSION );
			}
		}

		/** Print the nav at the top of a plain WordPress screen picked above. */
		public static function screen_nav(): void {
			if ( '' === self::$screen_slug || self::$nav_printed ) {
				return;
			}
			echo '<div class="sh sh-screen-nav">';
			self::nav( self::$screen_slug );
			echo '</div>';
		}

		/**
		 * Which nav group a sidebar screen belongs to. Plugins can move a screen
		 * with the scout_admin_nav_group filter (group key, slug, label).
		 */
		public static function group( string $slug, string $label ): string {
			$s = strtolower( $slug );
			$l = strtolower( $label );
			if ( false !== strpos( $s . ' ' . $l, 'review' ) ) {
				$group = 'reviews';
			} elseif ( false !== strpos( $s . ' ' . $l, 'chat' ) ) {
				$group = 'chat';
			} elseif ( 0 === strpos( $s, 'site-build-features' ) || ( 'site-build' === strtok( $s, '&' ) && ( 'features' === $l || false !== strpos( $s, '&tab=' ) ) ) ) {
				$group = 'home';
			} elseif ( 'scout' === strtok( $s, '&' ) || preg_match( '/ai-search|analytics|robots/', $s ) ) {
				$group = 'search';
			} elseif ( preg_match( '/hours|team|results|cards|contact|booking|proposal/', $s . ' ' . $l ) ) {
				$group = 'business';
			} else {
				$group = 'website';
			}
			$group = (string) apply_filters( 'scout_admin_nav_group', $group, $slug, $label );
			return isset( self::GROUPS[ $group ] ) ? $group : 'website';
		}

		/**
		 * The section nav: every screen in this screen's sidebar menu, sorted into a
		 * short row of groups (Home, Search, Website, Business, Reviews, Chat). The open
		 * group's screens show as tabs underneath. Empty groups never show.
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
			// The entry that matches the address best: same page and tab, else the page's plain entry.
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
			// Sort into groups, keeping sidebar order inside each.
			$groups = array_fill_keys( array_keys( self::GROUPS ), array() );
			$open   = '';
			foreach ( $items as $item ) {
				$slug  = (string) $item[2];
				$label = self::$labels[ $slug ] ?? self::clean( (string) $item[0] );
				$key   = self::group( $slug, $label );
				$groups[ $key ][] = array(
					'url'   => false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug ),
					'label' => $label,
					'hint'  => (string) apply_filters( 'scout_admin_nav_hint', '', $slug ),
					'on'    => $slug === $active,
				);
				if ( $slug === $active ) {
					$open = $key;
				}
			}
			$groups = array_filter( $groups );
			if ( '' === $open ) {
				$open = (string) array_key_first( $groups );
			}
			$link = static function ( array $entry, string $text, bool $on, string $hint ): void {
				printf(
					'<a href="%1$s"%2$s>%3$s%4$s</a>',
					esc_url( $entry['url'] ),
					$on ? ' class="is-active" aria-current="page"' : '',
					esc_html( $text ),
					'' !== $hint ? '<span class="sh-nav-hint">' . esc_html( $hint ) . '</span>' : ''
				);
			};
			self::$nav_printed = true;
			echo '<nav class="sh-nav" aria-label="' . esc_attr( self::branded() ? 'Scout' : 'Site Build' ) . '">';
			if ( count( $groups ) > 1 ) {
				echo '<div class="sh-nav-groups">';
				foreach ( $groups as $key => $entries ) {
					// A one-screen group links straight to it and carries its hint.
					$solo = 1 === count( $entries );
					$link( $entries[0], self::GROUPS[ $key ], $key === $open, $solo ? $entries[0]['hint'] : '' );
				}
				echo '</div>';
			}
			if ( count( $groups[ $open ] ?? array() ) > 1 || 1 === count( $groups ) ) {
				echo '<div class="sh-nav-sub">';
				foreach ( $groups[ $open ] as $entry ) {
					$link( $entry, $entry['label'], $entry['on'], $entry['hint'] );
				}
				echo '</div>';
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

	// After every plugin and the hub's own ordering (999) have built the menu.
	add_action( 'admin_menu', array( 'Scout_Admin_UI', 'compact_menu' ), 1000 );
	add_filter( 'submenu_file', array( 'Scout_Admin_UI', 'current_link' ), 20 );
	add_action( 'admin_head', array( 'Scout_Admin_UI', 'menu_css' ) );
	add_action( 'admin_enqueue_scripts', array( 'Scout_Admin_UI', 'screen_nav_prepare' ), 99 );
	add_action( 'all_admin_notices', array( 'Scout_Admin_UI', 'screen_nav' ), 1 );

endif;
