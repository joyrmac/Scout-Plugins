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
 * short row of groups (Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health, Scout tools) with the open
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

		const VERSION = '1.8.1';

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
			// Preview on a phone (Joy, 2026-11-04): the live site in a phone-sized window, from any Scout screen.
			$home = esc_url( home_url( '/' ) );
			echo '<p class="sh-note sh-phone-preview" style="margin:4px 0 0"><a href="' . $home . '" target="scout-phone" rel="noopener" onclick="window.open(this.href,\'scout-phone\',\'width=390,height=844\');return false;">Preview the site on a phone</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $home is escaped above.
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

		/**
		 * The nav groups, in the order they show (dashboard redesign, Joy 2026-11-04).
		 * Keys stay stable so filters keep working: overview is shown as Home, search
		 * as Get found, build as Scout tools. Plus modules (Bookings, Events, Members,
		 * Partners, Writing room) keep their own sidebar sections and are not listed here.
		 */
		const GROUPS = array(
			'overview' => 'Home',
			'inbox'    => 'Inbox',
			'reports'  => 'Reports',
			'business' => 'Your business',
			'website'  => 'Your website',
			'search'   => 'Get found',
			'policies' => 'Policies',
			'health'   => 'Site health',
			'build'    => 'Scout tools',
		);

		/** Retired group keys, mapped to where their screens live now. */
		const ALIASES = array( 'content' => 'website' );

		/** A group's label for this site: Scout tools reads Build tools on a white-label site. */
		public static function label( string $key ): string {
			if ( 'build' === $key && ! self::branded() ) {
				return 'Build tools';
			}
			return self::GROUPS[ $key ] ?? '';
		}

		/** Someone who looks after the site for Scout (SCOUT_HUB_OPERATORS). True when the hub is not installed. */
		public static function is_operator(): bool {
			return ! class_exists( 'Scout_Hub_Access', false ) || ! method_exists( 'Scout_Hub_Access', 'is_operator' ) || Scout_Hub_Access::is_operator();
		}

		/**
		 * Groups this person does not see. Scout tools is for operators only.
		 * Staff (anyone who cannot manage the site's settings) see Home, Inbox
		 * and Your business, plus any Plus section they work in. The
		 * scout_admin_hidden_groups filter adjusts it.
		 */
		public static function hidden(): array {
			$hidden = array();
			if ( ! self::is_operator() ) {
				$hidden[] = 'build';
			}
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				$hidden = array_merge( $hidden, array( 'reports', 'website', 'search', 'policies', 'health' ) );
			}
			return array_values( array_unique( (array) apply_filters( 'scout_admin_hidden_groups', $hidden ) ) );
		}

		/**
		 * Plus modules: paid modules with their own sidebar section. Each gets a small
		 * "Plus" tag beside its name (Joy, 2026-11-04). The scout_admin_plus_menus
		 * filter adds a client's own module.
		 */
		const PLUS_MENUS = array( 'scout-booking', 'scout-events', 'scout-members', 'scout-partners', 'scout-writing', 'scout-rvpark' );

		/** @var bool A Plus tag is in the sidebar on this screen. */
		private static $plus = false;

		/** Tag each Plus module's sidebar section. */
		public static function plus_tags(): void {
			global $menu;
			if ( ! is_array( $menu ) ) {
				return;
			}
			$plus = (array) apply_filters( 'scout_admin_plus_menus', self::PLUS_MENUS );
			foreach ( $menu as $i => $entry ) {
				$slug = (string) ( $entry[2] ?? '' );
				if ( '' === $slug || ! in_array( $slug, $plus, true ) || false !== strpos( (string) $entry[0], 'sh-plus-tag' ) ) {
					continue;
				}
				$menu[ $i ][0] = $entry[0] . ' <span class="sh-plus-tag">+ Plus</span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- relabel only.
				self::$plus    = true;
			}
		}

		/* ---- Comfort settings (Joy, 2026-11-04) ---------------------------- */

		const COMFORT = 'scout_ui_comfort'; // User meta: [ large => bool, calm => bool ].

		/** This person's comfort settings. */
		public static function comfort( int $user = 0 ): array {
			$c = function_exists( 'get_user_meta' ) ? get_user_meta( $user ? $user : get_current_user_id(), self::COMFORT, true ) : array();
			$c = is_array( $c ) ? $c : array();
			return array(
				'large' => ! empty( $c['large'] ),
				'calm'  => ! empty( $c['calm'] ),
			);
		}

		/** Larger text and calmer motion on Scout screens, as body classes. */
		public static function body_class( $classes ): string {
			$c = self::comfort();
			return trim( (string) $classes . ( $c['large'] ? ' sh-large-text' : '' ) . ( $c['calm'] ? ' sh-calm' : '' ) );
		}

		/** Users → Profile: the two comfort settings, for your own account. */
		public static function profile_fields( $user ): void {
			$c = self::comfort( (int) $user->ID );
			echo '<h2 id="scout-comfort">' . esc_html( self::branded() ? 'Scout screens' : 'Site Build screens' ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tr><th scope="row">Easier to read</th><td>';
			echo '<label><input type="checkbox" name="scout_comfort[large]" value="1" ' . checked( $c['large'], true, false ) . '> Larger text</label><br>';
			echo '<label><input type="checkbox" name="scout_comfort[calm]" value="1" ' . checked( $c['calm'], true, false ) . '> Less motion</label>';
			echo '<p class="description">Only changes how these screens look for you. Your computer\'s reduce-motion setting is followed either way.</p>';
			echo '</td></tr></table>';
		}

		/** Save the comfort settings with the profile (WordPress checks the profile form's nonce). */
		public static function save_profile( $user_id ): void {
			if ( ! current_user_can( 'edit_user', $user_id ) || get_current_user_id() !== (int) $user_id ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by WordPress before personal_options_update runs.
			$in = isset( $_POST['scout_comfort'] ) ? (array) wp_unslash( $_POST['scout_comfort'] ) : array();
			update_user_meta(
				(int) $user_id,
				self::COMFORT,
				array(
					'large' => ! empty( $in['large'] ),
					'calm'  => ! empty( $in['calm'] ),
				)
			);
		}

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
		 * Shorten a long Scout sidebar menu to one link per group (Home, Inbox,
		 * Reports, Your business, Your website, Get found, Policies, Site health,
		 * Scout tools). Every screen stays registered and
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
				$hidden = self::hidden();
				foreach ( $submenu[ $parent ] as $i => $item ) {
					$slug  = (string) ( $item[2] ?? '' );
					$raw   = (string) ( $item[0] ?? '' );
					$label = self::clean( $raw );
					$group = self::group( $slug, $label );
					self::$labels[ $slug ] = $label;
					if ( in_array( $group, $hidden, true ) ) {
						$item[4] = trim( ( $item[4] ?? '' ) . ' sh-menu-hidden' );
						$submenu[ $parent ][ $i ] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- hide only.
						continue;
					}
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
						$item[0] = esc_html( self::label( $group ) ) . ( $bubble[ $group ] ?? '' );
						if ( self::is_off( $slug ) ) {
							$item[4] = trim( ( $item[4] ?? '' ) . ' sh-menu-off' ); // Nothing on in this group yet: greyed, opens Turn on.
						}
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
				echo '<style>#adminmenu .sh-menu-hidden{display:none}#adminmenu .sh-menu-off a{opacity:.55}</style>';
			}
			if ( self::$plus ) {
				echo '<style>#adminmenu .sh-plus-tag{display:inline-block;margin-left:4px;padding:1px 6px;border-radius:4px;background:rgba(123,108,240,.28);color:#D9D4FF;font-size:10px;font-weight:600;letter-spacing:.6px;line-height:16px;vertical-align:1px;white-space:nowrap}</style>';
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

		/** A sidebar slug the hub registered as a stand-in for a group with nothing on (…&group=chat). */
		public static function is_off( string $slug ): bool {
			return (bool) preg_match( '/[?&]group=[a-z]+/', $slug );
		}

		/**
		 * Which nav group a sidebar screen belongs to. Plugins can move a screen
		 * with the scout_admin_nav_group filter (group key, slug, label).
		 */
		public static function group( string $slug, string $label ): string {
			$s = strtolower( $slug );
			$l = strtolower( $label );
			// A placeholder the hub registers for a group with nothing on yet (&group=inbox).
			if ( preg_match( '/[?&]group=([a-z]+)/', $s, $m ) && isset( self::GROUPS[ $m[1] ] ) ) {
				return $m[1];
			}
			$page = strtok( $s, '&' );
			$hub  = in_array( $page, array( 'site-build-features', 'site-build' ), true );
			if ( $hub && ! preg_match( '/tab=/', $s ) ) {
				$group = 'overview'; // Home, the hub's first screen.
			} elseif ( $hub && preg_match( '/tab=(features|updates|log)\b/', $s ) ) {
				$group = 'build';
			} elseif ( $hub && preg_match( '/tab=(inbox|requests)\b/', $s ) ) {
				$group = 'inbox';
			} elseif ( $hub && false !== strpos( $s, 'tab=health' ) ) {
				$group = 'health';
			} elseif ( 'search health' === $l ) {
				$group = 'health';
			} elseif ( preg_match( '/tab=(changes|sync)\b/', $s ) || preg_match( '/^(scout-mail|scout-chat)$/', $page ) || false !== strpos( $l, 'theme' ) ) {
				$group = 'build'; // Operator tools: Email, Chat settings, Site Sync, the Change log, a theme's own screen.
			} elseif ( preg_match( '/scout-reviews-(inbox|ask)|post_type=scout_chat_q|post_type=scout_hub_request|post_type=scout_sitecheck/', $s ) || preg_match( '/^(review inbox|ask for reviews|chat questions|request a change|inbox|site checks)$/', $l ) ) {
				$group = 'inbox';
			} elseif ( preg_match( '/tab=protect\b/', $s ) || preg_match( '/scout-optimize/', $page ) ) {
				$group = 'health'; // Spam protection and the image optimizer report on whether things are working.
			} elseif ( preg_match( '/scout-(accessibility|policies|disclaimers|privacy)/', $page ) ) {
				$group = 'policies';
			} elseif ( preg_match( '/tab=seo\b/', $s ) || preg_match( '/scout-(ai-search|analytics|search|sitemap)|site-build-robots/', $page ) ) {
				$group = 'search';
			} elseif ( preg_match( '/scout-recon/', $page ) || 'reports' === $l ) {
				$group = 'reports';
			} elseif ( preg_match( '/scout-(contact|redirects|cards|newsletter|sitecheck|reviews-settings)|post_type=scout_review\b/', $s ) || false !== strpos( $page, 'writing' ) ) {
				$group = 'website'; // Call buttons, Redirects, Cards & Links, Newsletter, Site check settings, Reviews.
			} else {
				$group = 'business'; // Business info, Hours, Team, Case results, a companion's own types, and each Plus section's own screens.
			}
			$group = (string) apply_filters( 'scout_admin_nav_group', $group, $slug, $label );
			$group = self::ALIASES[ $group ] ?? $group;
			return isset( self::GROUPS[ $group ] ) ? $group : 'business';
		}

		/**
		 * The section nav: every screen in this screen's sidebar menu, sorted into a
		 * short row of groups (Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health, Scout tools). The open
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
			$groups   = array_fill_keys( array_keys( self::GROUPS ), array() );
			$open     = '';
			$hidden   = self::hidden();
			$operator = self::is_operator();
			foreach ( $items as $item ) {
				$slug  = (string) $item[2];
				$label = self::$labels[ $slug ] ?? self::clean( (string) $item[0] );
				$key   = self::group( $slug, $label );
				if ( in_array( $key, $hidden, true ) ) {
					continue;
				}
				$off = self::is_off( $slug );
				if ( $off && ! $operator ) {
					continue; // A stand-in for a group with nothing on: operators only.
				}
				$groups[ $key ][] = array(
					'url'   => false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug ),
					'label' => $label,
					'hint'  => $off ? ( $operator ? __( 'Turn on', 'scout-build' ) : __( 'Not on', 'scout-build' ) ) : (string) apply_filters( 'scout_admin_nav_hint', '', $slug ),
					'on'    => $slug === $active,
					'off'   => $off,
				);
				if ( $slug === $active ) {
					$open = $key;
				}
			}
			// Operators see every group in the same place on every site: a group with nothing on is
			// greyed and opens the Features screen to turn something on. Clients only see groups that
			// have something in them (Joy, 2026-11-04: features that are off stay out of the way).
			$overview = '';
			foreach ( $groups['overview'] as $entry ) {
				if ( preg_match( '#page=(site-build-features|site-build)(&|$)#', $entry['url'] ) ) {
					$overview = $entry['url'];
					break;
				}
			}
			foreach ( $groups as $key => $entries ) {
				if ( in_array( $key, $hidden, true ) ) {
					unset( $groups[ $key ] );
				} elseif ( ! $entries && '' !== $overview && $operator ) {
					$url = add_query_arg( array( 'tab' => 'features', 'group' => $key ), $overview );
					$groups[ $key ][] = array( 'url' => $url, 'label' => self::label( $key ), 'hint' => __( 'Turn on', 'scout-build' ), 'on' => false, 'off' => true );
				}
			}
			$groups = array_filter( $groups );
			if ( '' === $open ) {
				$open = (string) array_key_first( $groups );
			}
			$link = static function ( array $entry, string $text, bool $on, string $hint ): void {
				$class = trim( ( $on ? 'is-active' : '' ) . ( ! empty( $entry['off'] ) ? ' is-off' : '' ) );
				printf(
					'<a href="%1$s"%2$s>%3$s%4$s</a>',
					esc_url( $entry['url'] ),
					( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ( $on ? ' aria-current="page"' : '' ),
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
					$link( $entries[0], self::label( $key ), $key === $open, $solo ? $entries[0]['hint'] : '' );
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
	add_action( 'admin_menu', array( 'Scout_Admin_UI', 'plus_tags' ), 1001 );
	add_filter( 'admin_body_class', array( 'Scout_Admin_UI', 'body_class' ) );
	add_action( 'show_user_profile', array( 'Scout_Admin_UI', 'profile_fields' ) );
	add_action( 'personal_options_update', array( 'Scout_Admin_UI', 'save_profile' ) );
	add_filter( 'submenu_file', array( 'Scout_Admin_UI', 'current_link' ), 20 );
	add_action( 'admin_head', array( 'Scout_Admin_UI', 'menu_css' ) );
	add_action( 'admin_enqueue_scripts', array( 'Scout_Admin_UI', 'screen_nav_prepare' ), 99 );
	add_action( 'all_admin_notices', array( 'Scout_Admin_UI', 'screen_nav' ), 1 );

endif;
