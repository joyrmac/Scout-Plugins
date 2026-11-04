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

		const VERSION = '1.9.1';

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
		 * Open a screen the way the dashboard canvas draws it (Joy, 2026-11-04):
		 * one white bar (breadcrumb, Find anything, one button), then the
		 * section's screens on the left when it has more than one, then the
		 * title and one line under it. The WordPress sidebar is the only menu.
		 *
		 * @param string $title   Screen name, e.g. "AI search". Ends the breadcrumb, and is the h1 unless page_head() set a headline.
		 * @param string $lede    One or two sentences on what the screen is for.
		 * @param string $section Kept for older callers; the breadcrumb now names the section.
		 * @param string $nav     Sidebar slug to highlight, for a screen not in the sidebar itself.
		 */
		public static function open( string $title, string $lede = '', string $section = '', string $nav = '' ): void {
			unset( $section );
			$d = self::nav_data( $nav );
			echo '<div class="wrap sh">';
			self::bar( $d, $title );
			self::$nav_printed = true;
			$local = ! self::$no_local && ( count( $d['entries'] ) > 1 || self::looking( $d ) );
			echo '<div class="sh-page' . ( '' !== self::$headline ? ' is-home' : '' ) . '">';
			echo '<div class="sh-body' . ( $local ? ' has-local' : '' ) . '">';
			if ( $local ) {
				self::local( $d, false );
			}
			echo '<div class="sh-main">';
			echo '<div class="sh-pagehead">';
			if ( '' !== self::$eyebrow ) {
				echo '<p class="sh-eyebrow">' . esc_html( self::$eyebrow ) . '</p>';
			}
			echo '<h1>' . esc_html( '' !== self::$headline ? self::$headline : $title ) . '</h1>';
			if ( '' !== $lede ) {
				echo '<p class="sh-lede">' . esc_html( $lede ) . '</p>';
			}
			echo '</div>';
			// WordPress prints admin notices right after the first heading in .wrap.
			echo '<hr class="wp-header-end" style="display:none">';
		}

		/**
		 * A sentence headline instead of the screen name, with a small line above it.
		 * Home uses it: "Two things need you today." Call before open().
		 */
		public static function page_head( string $headline, string $eyebrow = '' ): void {
			self::$headline = $headline;
			self::$eyebrow  = $eyebrow;
		}

		/** The one button on the right of the bar. Call before open(). Default: View site. */
		public static function bar_action( string $label, string $url ): void {
			self::$action = array( $label, $url );
		}

		/** Leave out the section's screen list on this screen (Home and the Inbox). Call before open(). */
		public static function no_local(): void {
			self::$no_local = true;
		}

		/**
		 * Plus sections sit right under the Scout menu, in a fixed order (Joy,
		 * 2026-11-04), whatever position each plugin asked for.
		 */
		public static function plus_order(): void {
			global $menu;
			$plus  = (array) apply_filters( 'scout_admin_plus_menus', self::PLUS_MENUS );
			$scout = null;
			$found = array();
			foreach ( (array) $menu as $pos => $m ) {
				$slug = (string) ( $m[2] ?? '' );
				if ( null === $scout && in_array( $slug, self::MENUS, true ) ) {
					$scout = (string) $pos;
				}
				$i = array_search( $slug, $plus, true );
				if ( false !== $i ) {
					$found[ $i ] = $m;
					unset( $menu[ $pos ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- moved below.
				}
			}
			if ( null === $scout || ! $found ) {
				foreach ( $found as $m ) {
					$menu[] = $m; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back as they were.
				}
				return;
			}
			ksort( $found );
			$n = 1;
			foreach ( $found as $m ) {
				do {
					$key = $scout . ( false === strpos( $scout, '.' ) ? '.' : '' ) . str_pad( (string) $n, 2, '0', STR_PAD_LEFT );
					++$n;
				} while ( isset( $menu[ $key ] ) );
				$menu[ $key ] = $m; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
			}
		}

		/** @var string A sentence headline set by page_head(). */
		private static $headline = '';

		/** @var string The small line above the headline. */
		private static $eyebrow = '';

		/** @var array{0?:string,1?:string} The bar's button: label, url. */
		private static $action = array();

		/** @var bool Leave out the section's screen list. */
		private static $no_local = false;

		/** @var bool Find anything already printed on this screen. */
		private static $finder_printed = false;

		/** The white bar: breadcrumb, Find anything, one button. */
		private static function bar( array $d, string $title ): void {
			$brand = self::branded() ? 'Scout' : 'Site Build';
			$crumb = array( $brand );
			if ( '' !== $d['section'] && strtolower( $d['section'] ) !== strtolower( $title ) ) {
				$crumb[] = $d['section'];
			}
			echo '<header class="sh-bar">';
			echo '<p class="sh-crumb">';
			foreach ( $crumb as $c ) {
				echo esc_html( $c ) . ' <span aria-hidden="true">&rsaquo;</span> ';
			}
			echo '<strong>' . esc_html( $title ) . '</strong></p>';
			if ( $d['finder'] ) {
				echo '<button type="button" class="sh-find" data-sh-find aria-haspopup="dialog"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg><span>' . esc_html__( 'Find anything', 'scout-build' ) . '</span><kbd data-sh-keys>Ctrl K</kbd></button>';
			}
			$action = self::$action;
			if ( ! $action ) {
				// Request a change on every screen (Joy, 2026-11-04); its status shows in the Inbox.
				foreach ( $d['all'] as $entries ) {
					foreach ( $entries as $e ) {
						if ( false !== strpos( $e['url'], 'tab=requests' ) && empty( $e['on'] ) ) {
							$action = array( __( 'Request a change', 'scout-build' ), $e['url'] );
						}
					}
				}
			}
			if ( $action ) {
				echo '<a class="button" href="' . esc_url( $action[1] ) . '">' . esc_html( $action[0] ) . '</a>';
			}
			echo '</header>';
			if ( $d['finder'] ) {
				self::finder( $d );
			}
		}

		/**
		 * The section's screens: a list on the left of a Scout screen, or a row of
		 * links on top of a plain WordPress screen (a post list) we cannot wrap.
		 */
		private static function local( array $d, bool $row ): void {
			echo '<nav class="sh-local' . ( $row ? ' is-row' : '' ) . '" aria-label="' . esc_attr( $d['section'] ) . '">';
			if ( ! $row ) {
				echo '<p class="sh-eyebrow">' . esc_html( $d['section'] ) . '</p>';
			}
			foreach ( $d['entries'] as $e ) {
				printf(
					'<a href="%1$s"%2$s>%3$s%4$s</a>',
					esc_url( $e['url'] ),
					( $e['on'] ? ' aria-current="page"' : '' ) . ( ! empty( $e['off'] ) ? ' class="is-off"' : '' ),
					esc_html( $e['label'] ),
					'' !== $e['hint'] ? '<span class="sh-nav-hint">' . esc_html( $e['hint'] ) . '</span>' : ''
				);
			}
			foreach ( self::looking( $d ) as $line ) {
				// One link for the whole line, the last words kept together so it never breaks before the group name.
				echo '<a class="sh-looking" href="' . esc_url( $line[2] ) . '">' . esc_html( $line[0] ) . ' <span class="sh-nowrap">' . esc_html( $line[1] ) . '&nbsp;&rsaquo;</span></a>';
			}
			echo '</nav>';
		}

		/**
		 * "Looking for" links (Joy, 2026-11-04): a screen lives in exactly one group.
		 * Where it also fits another, that group's list ends with one quiet line that
		 * opens it in its home group. At most two per group. Each entry: the target's
		 * slug (a piece of it), the line, and whether only Scout sees it. A Plus
		 * section is named by its top-level menu slug.
		 */
		const LOOKING = array(
			'build'    => array( array( 'scout-chat', "Looking for chat settings? They're in", false ), array( 'site-build-robots', "Looking for robots.txt? It's in", false ) ),
			'policies' => array( array( 'scout-analytics', "Looking for the cookie notice? It's in", false ), array( 'scout-accessibility-checks', "Looking for the accessibility checks? They're in", false ) ),
			'health'   => array( array( 'scout-accessibility', "Looking for the accessibility statement? It's in", false ), array( 'tab=updates', 'Scout:', true ) ),
			'website'  => array( array( '@scout-writing', 'Looking for the Writing room? It has', false ) ),
			'business' => array( array( '@scout-rvpark', 'Looking for the RV park? It has', false ) ),
		);

		/** The "Looking for" lines this screen's group shows: line, link text, url. */
		private static function looking( array $d ): array {
			global $menu;
			$out = array();
			foreach ( (array) apply_filters( 'scout_admin_looking_for', self::LOOKING[ $d['key'] ?? '' ] ?? array(), $d['key'] ?? '' ) as $l ) {
				if ( count( $out ) >= 2 ) {
					break;
				}
				list( $want, $line, $scout_only ) = array_pad( (array) $l, 3, false );
				if ( $scout_only && ! self::is_operator() ) {
					continue;
				}
				if ( 0 === strpos( (string) $want, '@' ) ) {
					foreach ( (array) $menu as $m ) {
						if ( (string) ( $m[2] ?? '' ) === substr( (string) $want, 1 ) && current_user_can( (string) ( $m[1] ?? 'manage_options' ) ) ) {
							$out[] = array( (string) $line, __( 'its own section', 'scout-build' ), admin_url( 'admin.php?page=' . $m[2] ) );
						}
					}
					continue;
				}
				foreach ( $d['all'] as $group => $entries ) {
					foreach ( $entries as $e ) {
						$slug = (string) wp_parse_url( $e['url'], PHP_URL_QUERY );
						if ( ! empty( $e['off'] ) || false === strpos( $slug, (string) $want ) || $group === $d['section'] ) {
							continue;
						}
						if ( 'tab=updates' === $want ) {
							if ( '' !== $e['hint'] ) {
								$out[] = array( (string) $line, __( 'Updates waiting', 'scout-build' ), $e['url'] );
							}
						} else {
							$out[] = array( (string) $line, (string) $group, $e['url'] );
						}
						continue 3;
					}
				}
			}
			return $out;
		}

		/**
		 * Find anything (Ctrl or Cmd + K): every Scout screen this person can open,
		 * plus things to do now (the scout_admin_finder_items filter: label, url, note).
		 */
		private static function finder( array $d ): void {
			if ( self::$finder_printed ) {
				return;
			}
			self::$finder_printed = true;
			$go = array();
			foreach ( $d['all'] as $group => $entries ) {
				foreach ( $entries as $e ) {
					if ( ! empty( $e['off'] ) ) {
						continue;
					}
					$go[] = array( 'label' => $e['label'], 'note' => $group, 'url' => $e['url'] );
				}
			}
			$do = array();
			foreach ( (array) apply_filters( 'scout_admin_finder_items', array() ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['label'] ) && ! empty( $item['url'] ) ) {
					$do[] = array( 'label' => (string) $item['label'], 'note' => (string) ( $item['note'] ?? '' ), 'url' => (string) $item['url'] );
				}
			}
			$data = wp_json_encode( array( 'go' => $go, 'do' => $do, 'goLabel' => __( 'Go to', 'scout-build' ), 'doLabel' => __( 'Do it now', 'scout-build' ), 'none' => __( 'Nothing matches. Try another word.', 'scout-build' ) ) );
			?>
			<div class="sh-finder" data-sh-finder hidden>
				<div class="sh-finder-scrim" data-sh-close></div>
				<div class="sh-finder-box" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Find anything', 'scout-build' ); ?>">
					<div class="sh-finder-search">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
						<label for="sh-finder-q" class="screen-reader-text"><?php esc_html_e( 'Find a setting, page or task', 'scout-build' ); ?></label>
						<input id="sh-finder-q" type="search" autocomplete="off" placeholder="<?php esc_attr_e( 'Find a setting, page or task', 'scout-build' ); ?>">
						<kbd>Esc</kbd>
					</div>
					<div class="sh-finder-list" data-sh-list role="listbox" aria-label="<?php esc_attr_e( 'Results', 'scout-build' ); ?>"></div>
				</div>
			</div>
			<script>
			(function () {
				var D = <?php echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode. ?>;
				var root = document.querySelector('[data-sh-finder]'); if (!root) { return; }
				var q = root.querySelector('input'), list = root.querySelector('[data-sh-list]'), last = null, pick = 0;
				var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
				document.querySelectorAll('[data-sh-keys]').forEach(function (k) { k.textContent = mac ? '⌘ K' : 'Ctrl K'; });
				function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
				function draw() {
					var w = q.value.trim().toLowerCase(), html = '', n = 0;
					[['do', D.doLabel], ['go', D.goLabel]].forEach(function (g) {
						var rows = D[g[0]].filter(function (r) { return !w || (r.label + ' ' + r.note).toLowerCase().indexOf(w) !== -1; }).slice(0, 8);
						if (!rows.length) { return; }
						html += '<p class="sh-finder-head">' + esc(g[1]) + '</p>';
						rows.forEach(function (r) { html += '<a role="option" href="' + esc(r.url) + '" data-i="' + (n++) + '"><span>' + esc(r.label) + '</span>' + (r.note ? '<em>' + esc(r.note) + '</em>' : '') + '</a>'; });
					});
					list.innerHTML = html || '<p class="sh-finder-none">' + esc(D.none) + '</p>';
					pick = 0; mark();
				}
				function mark() { list.querySelectorAll('a').forEach(function (a, i) { a.setAttribute('aria-selected', i === pick ? 'true' : 'false'); }); }
				function open() { last = document.activeElement; root.hidden = false; q.value = ''; draw(); q.focus(); }
				function close() { root.hidden = true; if (last) { last.focus(); } }
				document.querySelectorAll('[data-sh-find]').forEach(function (b) { b.addEventListener('click', open); });
				root.addEventListener('click', function (e) { if (e.target.hasAttribute('data-sh-close')) { close(); } });
				q.addEventListener('input', draw);
				document.addEventListener('keydown', function (e) {
					if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); e.stopPropagation(); root.hidden ? open() : close(); return; }
					if (root.hidden) { return; }
					var a = list.querySelectorAll('a');
					if (e.key === 'Escape') { e.preventDefault(); close(); }
					else if (e.key === 'ArrowDown') { e.preventDefault(); pick = Math.min(pick + 1, a.length - 1); mark(); }
					else if (e.key === 'ArrowUp') { e.preventDefault(); pick = Math.max(pick - 1, 0); mark(); }
					else if (e.key === 'Enter' && a[pick] && document.activeElement === q) { e.preventDefault(); window.location = a[pick].href; }
				}, true);
			}());
			</script>
			<?php
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
						if ( 'build' === $group ) {
							$item[4] = trim( ( $item[4] ?? '' ) . ' sh-menu-team' ); // "Scout team" label above it (operators only see it).
						}
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
				$team = self::branded() ? 'Scout team' : 'Web team';
				$menus = implode( ',', array_map( static fn( $m ) => '#adminmenu #toplevel_page_' . $m, self::MENUS ) );
				echo '<style>#adminmenu .sh-menu-hidden{display:none}#adminmenu .sh-menu-off a{opacity:.55}'
					. '#adminmenu li.sh-menu-team{margin-top:8px;padding-top:6px;border-top:1px solid #3c434a}'
					. '#adminmenu li.sh-menu-team::before{content:"' . esc_attr( $team ) . '";display:block;padding:4px 12px 2px;font:500 11px/1.4 ui-monospace,"SF Mono",Menlo,Consolas,monospace;letter-spacing:1.2px;text-transform:uppercase;color:#a7aaad}'
					. str_replace( ',', ' .awaiting-mod,', $menus ) . ' .awaiting-mod,' . str_replace( ',', ' .update-plugins,', $menus ) . ' .update-plugins{background:#FBB024;color:#1D1D1F;font-weight:600}'
					. '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed CSS, $team escaped.
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
			} elseif ( $hub && preg_match( '/tab=(features|updates)\b/', $s ) ) {
				$group = 'build';
			} elseif ( $hub && preg_match( '/tab=log\b/', $s ) ) {
				$group = self::is_operator() ? 'build' : 'overview'; // The client's "What's new on your site" sits under Home (Joy, 2026-11-04).
			} elseif ( $hub && preg_match( '/tab=(inbox|requests)\b/', $s ) ) {
				$group = 'inbox';
			} elseif ( $hub && false !== strpos( $s, 'tab=health' ) ) {
				$group = 'health';
			} elseif ( 'search health' === $l ) {
				$group = 'health';
			} elseif ( preg_match( '/tab=(changes|sync)\b/', $s ) || 'scout-mail' === $page || false !== strpos( $l, 'theme' ) ) {
				$group = 'build'; // Operator tools: Email, Chat settings, Site Sync, the Change log, a theme's own screen.
			} elseif ( preg_match( '/scout-reviews-inbox|post_type=scout_chat_q|post_type=scout_hub_request|post_type=scout_sitecheck/', $s ) || preg_match( '/^(review inbox|chat questions|request a change|inbox|site checks)$/', $l ) ) {
				$group = 'inbox';
			} elseif ( preg_match( '/tab=protect\b/', $s ) || preg_match( '/scout-optimize|scout-accessibility-checks/', $page ) ) {
				$group = 'health'; // Spam protection and the image optimizer report on whether things are working.
			} elseif ( preg_match( '/scout-(accessibility|policies|disclaimers|privacy)/', $page ) ) {
				$group = 'policies';
			} elseif ( preg_match( '/tab=seo\b/', $s ) || preg_match( '/scout-(ai-search|analytics|search|sitemap)|site-build-robots/', $page ) ) {
				$group = 'search';
			} elseif ( preg_match( '/scout-recon/', $page ) || 'reports' === $l ) {
				$group = 'reports';
			} elseif ( preg_match( '/scout-(contact|redirects|cards|newsletter|sitecheck|reviews-settings|reviews-ask|chat)\b|post_type=scout_review\b/', $s ) ) {
				$group = 'website'; // Call buttons, Redirects, Cards & Links, Newsletter, Site check settings, Reviews, Ask for reviews, Chat.
			} else {
				$group = 'business'; // Business info, Hours, Team, Case results, a companion's own types, and each Plus section's own screens.
			}
			$group = (string) apply_filters( 'scout_admin_nav_group', $group, $slug, $label );
			$group = self::ALIASES[ $group ] ?? $group;
			return isset( self::GROUPS[ $group ] ) ? $group : 'business';
		}

		/**
		 * Where this screen sits: its section's name and screens, every group's
		 * screens (for Find anything), and the Scout menu it belongs to. A Scout
		 * menu is sorted into the groups (Home, Inbox, Your business...); any other
		 * menu, like a Plus section, is one section of its own.
		 *
		 * @param string $current Sidebar slug to highlight; defaults to the screen being viewed.
		 * @return array{section:string,entries:array,all:array,finder:bool}
		 */
		public static function nav_data( string $current = '' ): array {
			global $menu;
			$none = array( 'section' => '', 'key' => '', 'entries' => array(), 'all' => array(), 'finder' => false );
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
			if ( ! $items ) {
				return $none;
			}
			$title = '';
			foreach ( (array) $menu as $m ) {
				if ( (string) ( $m[2] ?? '' ) === $parent ) {
					$title = self::clean( preg_replace( '#<span class="sh-plus-tag">.*?</span>#', '', (string) $m[0] ) );
				}
			}
			$scout = in_array( $parent, self::MENUS, true );
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
			$all = array();
			foreach ( $groups as $key => $entries ) {
				$all[ self::label( $key ) ] = $entries;
			}
			if ( ! $scout ) {
				// A Plus section or another plugin's own menu: one section, every screen in sidebar order.
				$flat = array();
				foreach ( $groups as $entries ) {
					$flat = array_merge( $flat, $entries );
				}
				return array( 'section' => '' !== $title ? $title : self::label( $open ), 'key' => '', 'entries' => $flat, 'all' => array( ( '' !== $title ? $title : self::label( $open ) ) => $flat ), 'finder' => true );
			}
			return array( 'section' => self::label( $open ), 'key' => $open, 'entries' => $groups[ $open ] ?? array(), 'all' => $all, 'finder' => true );
		}

		/**
		 * The bar and the section's screens on a plain WordPress screen (a review
		 * list, Chat questions), so no screen is a dead end. Scout's own screens get
		 * both from open().
		 *
		 * @param string $current Sidebar slug to highlight; defaults to the screen being viewed.
		 */
		public static function nav( string $current = '' ): void {
			$d = self::nav_data( $current );
			if ( ! $d['entries'] ) {
				return;
			}
			self::$nav_printed = true;
			$on = '';
			foreach ( $d['entries'] as $e ) {
				$on = $e['on'] ? $e['label'] : $on;
			}
			self::bar( $d, '' !== $on ? $on : $d['section'] );
			if ( count( $d['entries'] ) > 1 || self::looking( $d ) ) {
				self::local( $d, true );
			}
		}

		/** Close a screen with the Scout sign-off. */
		public static function close(): void {
			// Preview on a phone (Joy, 2026-11-04): the live site in a phone-sized window.
			$home = esc_url( home_url( '/' ) );
			echo '<p class="sh-note sh-phone-preview"><a href="' . $home . '" target="scout-phone" rel="noopener" onclick="window.open(this.href,\'scout-phone\',\'width=390,height=844\');return false;">' . esc_html__( 'Preview the site on a phone', 'scout-build' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $home is escaped above.
			if ( self::branded() ) {
				echo '<footer class="sh-foot"><span class="sh-bolt" aria-hidden="true"></span><p>Built and looked after by Scout Media &amp; Consulting. <a href="mailto:team@scoutraleigh.com">team@scoutraleigh.com</a> · <a href="tel:+19843259661">(984) 325-9661</a></p></footer>';
			}
			echo '</div></div></div></div>'; // .sh-main, .sh-body, .sh-page, .wrap
			self::$headline = '';
			self::$eyebrow  = '';
			self::$action   = array();
			self::$no_local = false;
		}
	}

	// After every plugin and the hub's own ordering (999) have built the menu.
	add_action( 'admin_menu', array( 'Scout_Admin_UI', 'compact_menu' ), 1000 );
	add_action( 'admin_menu', array( 'Scout_Admin_UI', 'plus_tags' ), 1001 );
	add_action( 'admin_menu', array( 'Scout_Admin_UI', 'plus_order' ), 1002 );
	add_filter( 'admin_body_class', array( 'Scout_Admin_UI', 'body_class' ) );
	add_action( 'show_user_profile', array( 'Scout_Admin_UI', 'profile_fields' ) );
	add_action( 'personal_options_update', array( 'Scout_Admin_UI', 'save_profile' ) );
	add_filter( 'submenu_file', array( 'Scout_Admin_UI', 'current_link' ), 20 );
	add_action( 'admin_head', array( 'Scout_Admin_UI', 'menu_css' ) );
	add_action( 'admin_enqueue_scripts', array( 'Scout_Admin_UI', 'screen_nav_prepare' ), 99 );
	add_action( 'all_admin_notices', array( 'Scout_Admin_UI', 'screen_nav' ), 1 );

endif;
