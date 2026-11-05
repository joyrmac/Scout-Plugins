<?php
/**
 * The dashboard screens: Overview, Inbox, Site health, Features, Updates, Requests and the Site log.
 *
 * Placement follows the dashboard standard (one Build section per site): the
 * screens go inside the site's existing Build menu when there is one (the
 * Scout Media theme's "Scout Build", Recon's "Recon Build", or Scout Core's
 * "Scout" menu), and only otherwise create a top-level "Site Build" section.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Admin {

	const PAGE        = 'site-build-features';
	const PLAN_ACTION = 'scout_hub_plan';

	/** Existing Build menus, in the order we prefer them. */
	const PARENTS = array( 'scout-build', 'recon-build', 'scout' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_menu', array( __CLASS__, 'first' ), 999 );
		add_filter( 'scout_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::PLAN_ACTION, array( __CLASS__, 'save_plan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_HUB_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( self::PARENTS as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'site-build';
	}

	/**
	 * The admin page slug the screen actually lives at: "site-build" when the
	 * hub made its own section, otherwise PAGE inside another Build menu.
	 * Saved when the menu is built, because admin-post.php redirects run
	 * before any menu exists.
	 */
	public static function page_slug(): string {
		$slug = (string) get_option( 'scout_hub_page', self::PAGE );
		return in_array( $slug, array( self::PAGE, 'site-build' ), true ) ? $slug : self::PAGE;
	}

	public static function url( string $tab = 'overview', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::page_slug(), 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu(): void {
		$parent = self::parent();
		if ( 'site-build' === $parent ) {
			$label = self::branded() ? __( 'Scout', 'scout-build' ) : __( 'Site Build', 'scout-build' );
			$icon  = self::branded()
				? 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>' ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				: 'dashicons-admin-generic';
			add_menu_page( $label, $label, 'edit_posts', 'site-build', array( __CLASS__, 'render' ), $icon, 3 );
			add_submenu_page( 'site-build', __( 'Home', 'scout-build' ), __( 'Home', 'scout-build' ), 'edit_posts', 'site-build', array( __CLASS__, 'render' ) );
		}
		// Site Standard §7 (v1.2): the first screen is the Overview, for everyone.
		add_submenu_page( $parent, __( 'Home', 'scout-build' ), __( 'Home', 'scout-build' ), 'edit_posts', self::PAGE, array( __CLASS__, 'render' ) );
		if ( 'site-build' === $parent ) {
			remove_submenu_page( 'site-build', self::PAGE ); // The section's first item already opens this screen.
		}
		// Each tab gets its own sidebar entry, so nobody has to find the tabs first.
		$base = 'site-build' === $parent ? 'site-build' : self::PAGE;
		add_submenu_page( $parent, __( 'Inbox', 'scout-build' ), __( 'Inbox', 'scout-build' ) . self::bubble( self::waiting_total() ), 'edit_posts', $base . '&tab=inbox', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Request a change', 'scout-build' ), __( 'Request a change', 'scout-build' ), 'edit_posts', $base . '&tab=requests', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Site health', 'scout-build' ), __( 'Site health', 'scout-build' ), 'edit_posts', $base . '&tab=health', array( __CLASS__, 'render' ) );
		// Build (operators): the features, every update (rule 12), and the Site log. The admin kit hides the group from everyone else.
		add_submenu_page( $parent, __( 'Features', 'scout-build' ), __( 'Features', 'scout-build' ), 'edit_posts', $base . '&tab=features', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Updates', 'scout-build' ), __( 'Updates', 'scout-build' ), 'edit_posts', $base . '&tab=updates', array( __CLASS__, 'render' ) );
		// Operators see the full Site log under Build; everyone else sees it as "Your site" under Overview.
		$log_label = Scout_Hub_Access::is_operator() ? __( 'Site log', 'scout-build' ) : __( 'Your site', 'scout-build' );
		add_submenu_page( $parent, $log_label, $log_label, 'edit_posts', $base . '&tab=log', array( __CLASS__, 'render' ) );
		$slug = 'site-build' === $parent ? 'site-build' : self::PAGE;
		if ( get_option( 'scout_hub_page' ) !== $slug ) {
			update_option( 'scout_hub_page', $slug, false );
		}
	}

	/**
	 * One order for the Scout menu on every site, which the shared section nav
	 * follows too: Overview, Inbox and Site health first, then the settings screens
	 * (Business info, SEO, AI search, images), then the rest, Request a change last.
	 */
	public static function first(): void {
		global $submenu, $menu;
		$parent = self::parent();
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		// Site Standard rule 11: one menu, named Scout (Site Build on partner sites), whatever
		// registered it. A theme's own Build menu keeps its slug until its conversion, but
		// shows the standard name so every site reads the same.
		if ( in_array( $parent, array( 'scout-build', 'recon-build' ), true ) && is_array( $menu ) ) {
			$label = self::branded() ? __( 'Scout', 'scout-build' ) : __( 'Site Build', 'scout-build' );
			foreach ( $menu as $i => $entry ) {
				if ( ( $entry[2] ?? '' ) === $parent ) {
					$menu[ $i ][0] = $label; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- relabel only.
				}
			}
		}
		// Operators see every group on every site: a group with nothing on gets a stand-in entry
		// that opens the Features screen, which the admin kit greys out. Clients only see groups
		// that have something in them (dashboard redesign, Joy 2026-11-04).
		if ( class_exists( 'Scout_Admin_UI' ) && defined( 'Scout_Admin_UI::GROUPS' ) && Scout_Hub_Access::is_operator() ) {
			$base = 'site-build' === $parent ? 'site-build' : self::PAGE;
			$seen = array();
			foreach ( $submenu[ $parent ] as $item ) {
				$seen[ Scout_Admin_UI::group( (string) ( $item[2] ?? '' ), wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) ) ] = true;
			}
			foreach ( Scout_Admin_UI::GROUPS as $key => $label ) {
				if ( empty( $seen[ $key ] ) ) {
					$label = method_exists( 'Scout_Admin_UI', 'label' ) ? Scout_Admin_UI::label( $key ) : $label;
					add_submenu_page( $parent, $label, $label, 'edit_posts', $base . '&tab=features&group=' . $key, array( __CLASS__, 'render' ) );
				}
			}
		}
		$items = array_values( $submenu[ $parent ] );
		$rank  = static function ( array $item, int $i ): int {
			$slug = (string) ( $item[2] ?? '' );
			$page = strtok( $slug, '&' );
			if ( false !== strpos( $slug, 'tab=requests' ) ) {
				return 9000;
			}
			if ( false !== strpos( $slug, '&group=' ) ) {
				return 8000 + $i; // Stand-ins for groups with nothing on, before Request a change.
			}
			if ( false !== strpos( $slug, 'tab=inbox' ) ) {
				return 50;
			}
			if ( false !== strpos( $slug, 'tab=health' ) ) {
				return 100;
			}
			if ( false !== strpos( $slug, 'tab=features' ) ) {
				return 140;
			}
			if ( false !== strpos( $slug, 'tab=updates' ) ) {
				return 150;
			}
			if ( false !== strpos( $slug, 'tab=log' ) ) {
				return 160;
			}
			if ( self::PAGE === $page || 'site-build' === $page ) {
				return 0;
			}
			$order = array( 'scout' => 200, 'scout-ai-search' => 400, 'scout-optimize' => 500 );
			if ( 'scout' === $page && false !== strpos( $slug, 'tab=' ) ) {
				return 300 + $i; // SEO and any companion settings, after Business info.
			}
			return ( $order[ $page ] ?? 1000 ) + $i;
		};
		// Group order first (Home, Inbox, Reports, Your business, Your website, Get found, Policies,
		// Site health, Scout tools), then the order above inside each group.
		$groups = class_exists( 'Scout_Admin_UI' ) && defined( 'Scout_Admin_UI::GROUPS' ) ? array_flip( array_keys( Scout_Admin_UI::GROUPS ) ) : array();
		$keyed  = array();
		foreach ( $items as $i => $item ) {
			$pos     = $groups ? (int) ( $groups[ Scout_Admin_UI::group( (string) ( $item[2] ?? '' ), wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) ) ] ?? 0 ) : 0;
			$keyed[] = array( $pos * 100000 + $rank( $item, $i ), $i, $item );
		}
		usort( $keyed, static fn( $x, $y ) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1] );
		$submenu[ $parent ] = array_column( $keyed, 2 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
	}

	/** Live hints on the hub's own pills in the shared section nav. */
	public static function nav_hint( string $hint, string $slug ): string {
		if ( ! in_array( strtok( $slug, '&' ), array( self::PAGE, 'site-build' ), true ) ) {
			return $hint;
		}
		if ( false !== strpos( $slug, 'tab=health' ) ) {
			$ai = Scout_Hub_Health::ai_checks();
			/* translators: 1: checks passed, 2: total */
			return sprintf( __( 'AI %1$d/%2$d', 'scout-build' ), count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) ), count( $ai ) );
		}
		if ( false !== strpos( $slug, 'tab=log' ) ) {
			$n = Scout_Hub_Log::count_month();
			// A short number: the list has little room, and the screen says what it counts.
			return $n ? (string) $n : '';
		}
		if ( false !== strpos( $slug, 'tab=requests' ) ) {
			$open = count( array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) ) );
			/* translators: %d: open requests */
			return $open ? sprintf( __( '%d open', 'scout-build' ), $open ) : '';
		}
		if ( false !== strpos( $slug, 'tab=updates' ) ) {
			$waiting = self::waiting();
			/* translators: %d: plugins with an update */
			return $waiting ? sprintf( __( '%d ready', 'scout-build' ), $waiting ) : '';
		}
		if ( false !== strpos( $slug, 'tab=inbox' ) ) {
			$n = self::waiting_total();
			return $n ? (string) $n : '';
		}
		if ( false === strpos( $slug, 'tab=features' ) || false !== strpos( $slug, '&group=' ) ) {
			return $hint; // Only the Features entry itself carries the "on" count.
		}
		$on = 0;
		foreach ( array_keys( Scout_Hub_Catalog::features() ) as $f ) {
			$on += 'on' === Scout_Hub_Features::status( $f )['state'] ? 1 : 0;
		}
		/* translators: %d: features on */
		return sprintf( __( '%d on', 'scout-build' ), $on );
	}

	/**
	 * Everything waiting on a person, one list for the Inbox, the Overview and
	 * the sidebar count: open change requests, unanswered chat questions, new
	 * reviews. Each row: label, count, url, note.
	 *
	 * @return array<int,array{label:string,count:int,url:string,note:string}>
	 */
	public static function waiting_on_you(): array {
		$rows = array();
		$open = count( array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) ) );
		$rows[] = array( 'label' => __( 'Change requests', 'scout-build' ), 'count' => $open, 'url' => self::url( 'requests' ), 'note' => __( 'Things you asked Scout for, with where each one stands.', 'scout-build' ) );
		if ( class_exists( 'Scout_Chat_Admin' ) && method_exists( 'Scout_Chat_Admin', 'unanswered' ) && class_exists( 'Scout_Chat' ) ) {
			$rows[] = array( 'label' => __( 'Chat questions', 'scout-build' ), 'count' => (int) Scout_Chat_Admin::unanswered(), 'url' => admin_url( 'edit.php?post_type=' . Scout_Chat::CPT ), 'note' => __( 'Questions visitors asked the chat that it could not answer.', 'scout-build' ) );
		}
		if ( class_exists( 'Scout_Reviews_Google_Sync' ) && method_exists( 'Scout_Reviews_Google_Sync', 'new_count' ) ) {
			$rows[] = array( 'label' => __( 'New reviews', 'scout-build' ), 'count' => (int) Scout_Reviews_Google_Sync::new_count(), 'url' => admin_url( 'admin.php?page=scout-reviews-inbox' ), 'note' => __( 'Reviews that arrived since you last looked. Pick the ones to show.', 'scout-build' ) );
		}
		/**
		 * Filters the Inbox rows, so a plugin can add its own queue.
		 *
		 * @param array $rows Rows of label, count, url, note.
		 */
		return (array) apply_filters( 'scout_hub_inbox', $rows );
	}

	/** The Inbox total, for the sidebar bubble and the nav hint. */
	public static function waiting_total(): int {
		static $total = null;
		if ( null === $total ) {
			// Counted from the Inbox list itself, so the bubble always matches what the Inbox shows.
			$total = class_exists( 'Scout_Hub_Inbox' ) ? Scout_Hub_Inbox::total( Scout_Hub_Inbox::items() ) : (int) array_sum( array_column( self::waiting_on_you(), 'count' ) );
		}
		return $total;
	}

	/** A WordPress count bubble for a sidebar label. */
	private static function bubble( int $n ): string {
		return $n ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>' : '';
	}

	/** Installed Scout features, slug => status, newest check included. */
	private static function installed(): array {
		$rows = array();
		foreach ( array_keys( Scout_Hub_Catalog::features() ) as $slug ) {
			$st = Scout_Hub_Features::status( $slug );
			if ( '' !== $st['version'] ) {
				$rows[ $slug ] = $st;
			}
		}
		return $rows;
	}

	/** How many installed features have a newer release waiting. */
	private static function waiting(): int {
		return count( array_filter( self::installed(), static fn( $st ) => '' !== $st['update'] ) );
	}

	/** Where each feature's own settings live, when it has a screen. */
	private static function settings_url( string $slug ): string {
		$pages = array(
			'scout-core'      => 'scout',
			'scout-aeo'       => 'scout-ai-search',
			'scout-optimize'  => 'scout-optimize',
			'scout-analytics' => 'scout-analytics',
			'scout-chat'      => 'scout-chat',
			'scout-cards'     => 'scout-cards',
			'scout-reviews'   => 'scout-reviews-settings',
		);
		global $_registered_pages;
		$page = $pages[ $slug ] ?? '';
		if ( '' === $page || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		foreach ( array_keys( (array) $_registered_pages ) as $hook ) {
			if ( substr( (string) $hook, -strlen( '_page_' . $page ) ) === '_page_' . $page ) {
				return admin_url( 'admin.php?page=' . $page );
			}
		}
		return '';
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Scout', 'scout-build' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) || 'toplevel_page_site-build' === $hook ) {
			Scout_Admin_UI::enqueue( SCOUT_HUB_URL . 'assets/scout-admin.css' );
			wp_enqueue_style( 'scout-build-admin', SCOUT_HUB_URL . 'assets/admin.css', array( 'scout-admin' ), SCOUT_HUB_VERSION );
		}
	}

	/**
	 * The kit's status line, fold_open() and fold_close() (kit 1.10.0). The first
	 * plugin to load declares the kit, so an older copy elsewhere may not have
	 * them yet; these print the same markup in that case.
	 *
	 * @param string $go Optional button HTML, already escaped.
	 */
	public static function status_line( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		echo '' !== $detail ? ' <span>' . esc_html( $detail ) . '</span>' : '';
		echo '' !== $go ? ' <span class="sh-status-go">' . $go . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		echo '</p></section>';
	}

	public static function fold_open( string $title, bool $open = false, string $id = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title, $open, $id );
			return;
		}
		echo '<details class="sh-box sh-fold"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $open ? ' open' : '' ) . '><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	public static function fold_close(): void {
		echo '</details>';
	}

	public static function save_plan(): void {
		check_admin_referer( self::PLAN_ACTION );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can set its plan.', 'scout-build' ), 403 );
		}
		$do   = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? 'plan' ) ) );
		$slug = sanitize_key( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) );
		$msg  = __( 'Plan saved.', 'scout-build' );
		if ( 'plan' === $do ) {
			Scout_Hub_Access::set_plan( sanitize_key( wp_unslash( (string) ( $_POST['plan'] ?? '' ) ) ) );
		} elseif ( '' !== $slug && isset( Scout_Hub_Catalog::features()[ $slug ] ) ) {
			if ( in_array( $do, array( 'pick', 'unpick' ), true ) ) {
				Scout_Hub_Access::pick( $slug, 'pick' === $do );
				$msg = 'pick' === $do ? __( 'Added to this site\'s plan.', 'scout-build' ) : __( 'Taken out of this site\'s plan.', 'scout-build' );
			} elseif ( in_array( $do, array( 'recommend', 'unrecommend' ), true ) ) {
				Scout_Hub_Access::recommend( $slug, 'recommend' === $do );
				$msg = 'recommend' === $do ? __( 'Recommended. The client sees it under More features.', 'scout-build' ) : __( 'No longer recommended.', 'scout-build' );
			}
		}
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( 'features' ) . ( '' !== $slug ? '#feature-' . $slug : '' ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview' => __( 'Home', 'scout-build' ),
			'inbox'    => __( 'Inbox', 'scout-build' ),
			'features' => __( 'Features', 'scout-build' ),
			'updates'  => __( 'Updates', 'scout-build' ),
			'requests' => __( 'Request a change', 'scout-build' ),
			'health'   => __( 'Site health', 'scout-build' ),
			'log'      => Scout_Hub_Access::is_operator() && empty( $_GET['view'] ) ? __( 'Site log', 'scout-build' ) : __( 'Your site', 'scout-build' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$lede = 'updates' === $tab ? __( 'Everything that brings a new version or new content onto this site, in one place: the theme, the plugins, the setup file, Site Sync, imports and the change log.', 'scout-build' ) : '';
		if ( 'log' === $tab && __( 'Your site', 'scout-build' ) === $tabs['log'] ) {
			$lede = __( 'What Scout has done on your website, and anything you have asked for.', 'scout-build' );
		}
		// Site name in the eyebrow, trimmed at a " | " tagline so it fits a phone.
		$site = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
		// The canvas layout (Joy, 2026-11-04): Home leads with a sentence, Home and the
		// Inbox have no section list, and the Inbox bar carries Request a change.
		if ( in_array( $tab, array( 'overview', 'inbox' ), true ) && method_exists( 'Scout_Admin_UI', 'no_local' ) ) {
			Scout_Admin_UI::no_local();
			if ( 'overview' === $tab ) {
				$head = self::home_head();
				Scout_Admin_UI::page_head( $head[0], $head[1] );
				$lede = $head[2];
			} else {
				Scout_Admin_UI::bar_action( __( 'Request a change', 'scout-build' ), self::url( 'requests' ) );
				$lede = __( 'Chat questions, reviews, bookings and your change requests all land here. Handle one, mark it done, and it moves out of the way.', 'scout-build' );
			}
		}
		Scout_Admin_UI::open( $tabs[ $tab ], $lede, $site );
		?>
			<?php self::notice(); ?>
			<?php
			if ( 'requests' === $tab ) {
				self::requests();
			} elseif ( 'health' === $tab ) {
				self::health();
			} elseif ( 'updates' === $tab ) {
				self::updates();
			} elseif ( 'inbox' === $tab ) {
				self::inbox();
			} elseif ( 'overview' === $tab ) {
				self::overview();
			} elseif ( 'log' === $tab ) {
				self::log();
			} else {
				self::features();
			}
			?>
		<?php
		Scout_Admin_UI::close();
	}

	/**
	 * Home's headline: a sentence that answers "what needs me?" (dashboard canvas,
	 * Joy 2026-11-04), the small line above it, and the line under it.
	 *
	 * @return array{0:string,1:string,2:string} Headline, eyebrow, lede.
	 */
	public static function home_head(): array {
		$n     = count( Scout_Hub_Today::for_user( get_current_user_id() )['items'] );
		$words = array( '', __( 'One thing', 'scout-build' ), __( 'Two things', 'scout-build' ), __( 'Three things', 'scout-build' ), __( 'Four things', 'scout-build' ), __( 'Five things', 'scout-build' ), __( 'Six things', 'scout-build' ), __( 'Seven things', 'scout-build' ), __( 'Eight things', 'scout-build' ), __( 'Nine things', 'scout-build' ) );
		/* translators: %d: how many things */
		$count = $words[ $n ] ?? sprintf( __( '%d things', 'scout-build' ), $n );
		if ( Scout_Hub_Access::is_operator() ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$site = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
			$head = $n
				/* translators: %s: "Three things" */
				? sprintf( 1 === $n ? __( '%s on this site is waiting.', 'scout-build' ) : __( '%s on this site are waiting.', 'scout-build' ), $count )
				: __( 'Nothing on this site is waiting.', 'scout-build' );
			// Scout's own sites (scout-setup.json "scout_own_site": true) have no separate client.
			$own  = ! empty( Scout_Hub_Setup::data()['scout_own_site'] );
			$lede = $own ? __( "Everything waiting on Scout's own site. Each one says about how long it takes.", 'scout-build' ) : __( 'The client sees their own list. Scout tools stay in the sidebar for you and Sean.', 'scout-build' );
			return array( $head, $site . ( '' !== $host ? ' · ' . $host : '' ), $lede );
		}
		$hour  = (int) current_time( 'G' );
		$hello = $hour < 12 ? __( 'Good morning.', 'scout-build' ) : ( $hour < 17 ? __( 'Good afternoon.', 'scout-build' ) : __( 'Good evening.', 'scout-build' ) );
		$date  = wp_date( 'l, F j' );
		$steps = Scout_Hub_Today::show_setup() ? array_filter( Scout_Hub_Today::setup_steps(), static fn( $s ) => empty( $s['done'] ) ) : array();
		if ( $steps ) {
			return array( __( "Welcome. Let's finish setting up your site.", 'scout-build' ), $date, __( 'A few short steps, in order. You can stop anytime and pick up where you left off.', 'scout-build' ) );
		}
		if ( ! $n ) {
			return array( $hello . ' ' . __( "You're all caught up.", 'scout-build' ), $date, __( 'Your site is running on its own today. Enjoy the quiet.', 'scout-build' ) );
		}
		/* translators: 1: "Good morning.", 2: "Two things" */
		$head = sprintf( 1 === $n ? __( '%1$s %2$s needs you today.', 'scout-build' ) : __( '%1$s %2$s need you today.', 'scout-build' ), $hello, $count );
		return array( $head, $date, __( 'Everything else is running on its own. Each one takes a few minutes, and you can stop after any of them.', 'scout-build' ) );
	}

	/**
	 * Show the Scout mark and sign-off. On by default; partner-side or
	 * white-label sites turn it off in wp-config.php:
	 *   define( 'SCOUT_HUB_BRAND', false );
	 */
	public static function branded(): bool {
		return ! defined( 'SCOUT_HUB_BRAND' ) || (bool) SCOUT_HUB_BRAND;
	}

	private static function notice(): void {
		$key    = 'scout_hub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Overview (Site Standard §7, v1.2): the first screen for everyone. Three
	 * answers: is the site all right, what needs you, what Scout is doing. Then
	 * the things a client actually comes here to do, and one line to ask for more.
	 */
	private static function overview(): void {
		$operator = Scout_Hub_Access::is_operator();
		$basics   = Scout_Hub_Health::checks();
		$ai       = Scout_Hub_Health::ai_checks();
		$bad      = array_filter( $basics, static fn( $c ) => 'bad' === $c['state'] );
		$warn     = array_filter( $basics, static fn( $c ) => 'warn' === $c['state'] );
		$ready    = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		$updates  = self::waiting();
		$theme    = wp_get_theme();
		$log      = class_exists( 'Scout_Hub_Log' ) ? Scout_Hub_Log::query( array( 'per_page' => 3, 'client' => ! $operator ) ) : array( 'items' => array() );
		$site     = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
		if ( $bad ) {
			$state = 'bad';
			/* translators: %d: failing checks */
			$line = sprintf( _n( '%d thing on the site needs fixing.', '%d things on the site need fixing.', count( $bad ), 'scout-build' ), count( $bad ) );
		} elseif ( $warn ) {
			$state = 'warn';
			/* translators: %d: checks that want a look */
			$line = sprintf( _n( 'The site is fine. %d thing wants a look.', 'The site is fine. %d things want a look.', count( $warn ), 'scout-build' ), count( $warn ) );
		} else {
			$state = 'good';
			$line  = __( 'The site is in good shape.', 'scout-build' );
		}
		?>
		<style>
			.sh-ov-status{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px}
			.sh-ov-status .sh-dot{width:10px;height:10px;border-radius:50%;flex:none}
			.sh-ov-status .is-good{background:var(--sh-good)}.sh-ov-status .is-warn{background:var(--sh-warn)}.sh-ov-status .is-bad{background:var(--sh-bad,#F2553D)}
			.sh-ov-status p{margin:0;font-size:17px;font-weight:600}
			.sh-ov-rows{display:grid;gap:6px;margin-top:10px}
			.sh-ov-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 14px;padding:10px 12px;border-radius:10px;background:var(--sh-surface-2)}
			.sh-ov-row .sh-num{font:600 20px/1 var(--sh-mono);margin:0;min-width:28px}
			.sh-ov-row .sh-note{margin:0}
			.sh-ov-do{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px}
			.sh-ov-do a{display:grid;gap:4px;padding:12px 14px;border-radius:12px;border:1px solid var(--sh-border);text-decoration:none;color:inherit}
			.sh-ov-do a:hover{border-color:var(--sh-accent)}
			.sh-ov-do b{color:var(--sh-text)}
			.sh-ov-do span{font-size:13px;color:var(--sh-text-2)}
			.sh-ov-log{margin:8px 0 0;padding:0;list-style:none;display:grid;gap:6px}
			.sh-ov-log li{display:grid;grid-template-columns:auto 1fr;gap:10px;color:var(--sh-text-2)}
			.sh-ov-log .sh-when{font-family:var(--sh-mono);font-size:12px;color:var(--sh-text-3);padding-top:2px;white-space:nowrap}
		</style>
		<?php
		// The agreed order (Joy, 2026-11-04): the sentence headline, then Today, then health.
		// The setup list comes first only while it shows (the first week); the Inbox rows feed Today.
		$setup = Scout_Hub_Today::render_setup();
		Scout_Hub_Today::render( ! $setup );
		?>
		<p class="sh-note" style="margin:8px 0 0"><a href="<?php echo esc_url( self::url( 'inbox' ) ); ?>"><?php esc_html_e( 'Open the Inbox', 'scout-build' ); ?> &rsaquo;</a></p>

		<section class="sh-box">
			<div class="sh-ov-status">
				<span class="sh-dot is-<?php echo esc_attr( $state ); ?>" aria-hidden="true"></span>
				<p><?php echo esc_html( $line ); ?></p>
				<a href="<?php echo esc_url( self::url( 'health' ) ); ?>"><?php esc_html_e( 'See the full report', 'scout-build' ); ?> &rsaquo;</a>
			</div>
			<p class="sh-note" style="margin-top:8px">
				<?php
				/* translators: 1: site name, 2: checks ready for AI search, 3: total AI checks */
				echo esc_html( sprintf( __( '%1$s is ready for AI search on %2$d of %3$d checks.', 'scout-build' ), $site, $ready, count( $ai ) ) );
				?>
			</p>
		</section>


		<div>
			<section class="sh-box">
				<?php
				// Clients see "What's new on your site" with this month's count (Joy, 2026-11-04).
				$month_new = $operator ? 0 : (int) Scout_Hub_Log::query( array( 'month' => current_time( 'Y-m' ), 'per_page' => 1, 'client' => true ) )['total'];
				if ( $operator ) {
					$card_title = self::branded() ? __( 'With Scout', 'scout-build' ) : __( 'Behind the scenes', 'scout-build' );
				} elseif ( $month_new ) {
					/* translators: %d: changes this month */
					$card_title = sprintf( _n( 'What\'s new on your site: %d change this month', 'What\'s new on your site: %d changes this month', $month_new, 'scout-build' ), $month_new );
				} else {
					$card_title = __( 'What\'s new on your site', 'scout-build' );
				}
				?>
				<h2 class="sh-title"><?php echo esc_html( $card_title ); ?></h2>
				<p class="sh-note">
					<?php
					echo esc_html(
						$updates
							/* translators: %d: plugin updates waiting */
							? sprintf( _n( '%d update is ready and installs itself within a few hours.', '%d updates are ready and install themselves within a few hours.', $updates, 'scout-build' ), $updates )
							: __( 'Everything is up to date. Updates install themselves.', 'scout-build' )
					);
					?>
					<?php echo ' ' . esc_html( sprintf( /* translators: 1: theme name, 2: version */ __( 'Theme: %1$s %2$s.', 'scout-build' ), (string) $theme->get( 'Name' ), (string) $theme->get( 'Version' ) ) ); ?>
				</p>
				<?php if ( ! empty( $log['items'] ) ) : ?>
					<ul class="sh-ov-log">
						<?php foreach ( $log['items'] as $post ) : ?>
							<?php $row = Scout_Hub_Log::to_array( $post, ! $operator ); ?>
							<li><span class="sh-when"><?php echo esc_html( wp_date( 'M j', strtotime( $row['date'] ) ) ); ?></span><span><?php echo esc_html( ( ! $operator && '' !== $row['client_text'] ) ? $row['client_text'] : $row['title'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="sh-note" style="margin-top:8px"><a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php echo esc_html( $operator ? __( 'The whole Site log', 'scout-build' ) : __( 'Everything Scout has done on your site', 'scout-build' ) ); ?> &rsaquo;</a></p>
				<?php endif; ?>
			</section>
		</div>

		<?php
		$do = array();
		$do[] = array( admin_url( 'edit.php?post_type=page' ), __( 'Change a page', 'scout-build' ), __( 'Your pages, with who edited each one last.', 'scout-build' ) );
		$do[] = array( admin_url( 'post-new.php' ), __( 'Write a post', 'scout-build' ), __( 'A new post, in your own words.', 'scout-build' ) );
		if ( class_exists( 'Scout_Hours_Admin' ) ) {
			$do[] = array( admin_url( 'admin.php?page=scout-hours' ), __( 'Update your hours', 'scout-build' ), __( 'Holiday closures and new hours, everywhere at once.', 'scout-build' ) );
		} elseif ( class_exists( 'Scout_Core_Admin' ) ) {
			$do[] = array( admin_url( 'admin.php?page=scout&tab=business' ), __( 'Your business details', 'scout-build' ), __( 'Name, phone, address and profiles, typed once.', 'scout-build' ) );
		}
		if ( class_exists( 'Scout_Reviews_Requests' ) ) {
			$do[] = array( admin_url( 'admin.php?page=scout-reviews-ask' ), __( 'Ask for a review', 'scout-build' ), __( 'Your review link, ready to send.', 'scout-build' ) );
		} elseif ( class_exists( 'Scout_Core_Admin' ) && class_exists( 'Scout_Hours_Admin' ) ) {
			$do[] = array( admin_url( 'admin.php?page=scout&tab=business' ), __( 'Your business details', 'scout-build' ), __( 'Name, phone, address and profiles, typed once.', 'scout-build' ) );
		}
		?>
		<div class="sh-section-head"><p class="sh-eyebrow"><?php esc_html_e( 'Things you can do here', 'scout-build' ); ?></p></div>
		<div class="sh-ov-do">
			<?php foreach ( $do as list( $url, $title, $text ) ) : ?>
				<a href="<?php echo esc_url( $url ); ?>"><b><?php echo esc_html( $title ); ?></b><span><?php echo esc_html( $text ); ?></span></a>
			<?php endforeach; ?>
		</div>
		<section class="sh-box" style="margin-top:16px">
			<p>
				<?php echo esc_html( self::branded() ? __( 'Want something added or changed? Ask Scout.', 'scout-build' ) : __( 'Want something added or changed? Ask us.', 'scout-build' ) ); ?>
				<a class="button" style="margin-left:8px" href="<?php echo esc_url( self::url( 'requests' ) ); ?>"><?php esc_html_e( 'Request a change', 'scout-build' ); ?></a>
			</p>
			<p class="sh-note" style="margin:10px 0 0"><a href="<?php echo esc_url( admin_url( 'profile.php#scout-comfort' ) ); ?>"><?php esc_html_e( 'Make these screens easier to read: larger text, less motion', 'scout-build' ); ?> &rsaquo;</a></p>
		</section>
		<?php if ( $operator ) : ?>
			<p class="sh-note" style="margin-top:14px">
				<?php esc_html_e( 'Operators:', 'scout-build' ); ?>
				<a href="<?php echo esc_url( self::url( 'features' ) ); ?>"><?php esc_html_e( 'Features', 'scout-build' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( 'updates' ) ); ?>"><?php esc_html_e( 'Updates', 'scout-build' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Site log', 'scout-build' ); ?></a>
			</p>
		<?php endif; ?>
		<?php self::more_features(); ?>
		<?php
	}

	/**
	 * More features: a closed section at the bottom of Home listing the
	 * features Scout recommends for this site that aren't on yet, each with
	 * "Ask us to add this". Site owners only. Everything else stays hidden
	 * (Joy, 2026-11-04).
	 */
	private static function more_features(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$features = Scout_Hub_Catalog::features();
		$list     = array();
		foreach ( Scout_Hub_Access::recommended() as $slug ) {
			if ( ! isset( $features[ $slug ] ) || $features[ $slug ]['blocked'] || 'on' === Scout_Hub_Features::status( $slug )['state'] ) {
				continue;
			}
			$list[ $slug ] = $features[ $slug ];
		}
		if ( ! $list ) {
			return;
		}
		?>
		<details class="sh-box" style="margin-top:16px">
			<summary style="cursor:pointer;font-weight:600;min-height:44px;display:flex;align-items:center">
				<?php
				/* translators: %d: recommended features */
				echo esc_html( sprintf( _n( 'More features: %d recommended for you', 'More features: %d recommended for you', count( $list ), 'scout-build' ), count( $list ) ) );
				?>
			</summary>
			<ul class="sh-list" style="margin-top:8px">
				<?php foreach ( $list as $slug => $f ) : ?>
					<?php $ask = sprintf( /* translators: %s: feature */ __( 'Please add %s to our site.', 'scout-build' ), $f['name'] ); ?>
					<li style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:8px 0">
						<span style="flex:1 1 280px"><strong><?php echo esc_html( $f['name'] ); ?></strong> <span class="sh-note"><?php echo esc_html( Scout_Hub_Access::tier_label( $f['tier'], $slug ) ); ?></span><br><span class="sh-note"><?php echo esc_html( $f['summary'] ); ?></span></span>
						<a class="button" href="<?php echo esc_url( self::url( 'requests', array( 'ask' => rawurlencode( $ask ) ) ) ); ?>"><?php esc_html_e( 'Ask us to add this', 'scout-build' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	/** The Inbox: one list of everything waiting on a person (Scout_Hub_Inbox). */
	private static function inbox(): void {
		Scout_Hub_Inbox::render();
	}

	private static function features(): void {
		$operator = Scout_Hub_Access::is_operator();
		$features = Scout_Hub_Catalog::features();
		$plan     = Scout_Hub_Access::plan();
		// Opened from a greyed group in the nav: say so, then show the features as usual.
		$group = sanitize_key( (string) ( $_GET['group'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $group && class_exists( 'Scout_Admin_UI' ) && isset( Scout_Admin_UI::GROUPS[ $group ] ) ) {
			/* translators: %s: nav group name, e.g. Reviews */
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( sprintf( __( 'Nothing in %s is on yet. Turn on a feature below and the group fills in.', 'scout-build' ), Scout_Admin_UI::GROUPS[ $group ] ) ) );
		}
		$states   = array(
			'missing' => __( 'Not installed', 'scout-build' ),
			'off'     => __( 'Off', 'scout-build' ),
			'on'      => __( 'On', 'scout-build' ),
		);
		?>
		<?php
		// Sort every feature into one of three groups.
		$groups = array( 'on' => array(), 'ready' => array(), 'unused' => array() );
		foreach ( $features as $slug => $f ) {
			$st = Scout_Hub_Features::status( $slug );
			if ( $f['hidden'] && 'missing' === $st['state'] ) {
				continue; // One client's own plugin: only listed where it is installed.
			}
			$key                    = 'on' === $st['state'] ? 'on' : ( $f['blocked'] ? 'unused' : 'ready' );
			$groups[ $key ][ $slug ] = array( 'f' => $f, 's' => $st );
		}
		// The theme's setup file can say which features this client needs, in install
		// order, and which it doesn't. Everything else ready to add sorts by name.
		$fplan   = Scout_Hub_Setup::feature_plan();
		$picked  = array();
		$skipped = array();
		foreach ( Scout_Hub_Access::recommended() as $slug ) {
			if ( isset( $groups['ready'][ $slug ] ) ) {
				$picked[ $slug ] = $groups['ready'][ $slug ];
				unset( $groups['ready'][ $slug ] );
			}
		}
		foreach ( $fplan['skip'] as $slug ) {
			if ( isset( $groups['ready'][ $slug ] ) ) {
				$skipped[ $slug ] = $groups['ready'][ $slug ];
				unset( $groups['ready'][ $slug ] );
			}
		}
		uasort( $groups['ready'], static fn( $a, $b ) => strcasecmp( $a['f']['name'], $b['f']['name'] ) );
		uasort( $skipped, static fn( $a, $b ) => strcasecmp( $a['f']['name'], $b['f']['name'] ) );
		$groups = array( 'on' => $groups['on'], 'picked' => $picked, 'ready' => $groups['ready'], 'skip' => $skipped, 'unused' => $groups['unused'] );
		if ( ! $operator ) {
			// Clients see what is on and what Scout recommends; everything else stays out of the way (Joy, 2026-11-04).
			$groups['ready']  = array();
			$groups['skip']   = array();
			$groups['unused'] = array();
		}
		$ai    = Scout_Hub_Health::ai_checks();
		$ready = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		?>
		<section class="sh-box sh-overview">
			<div class="sh-stats">
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $groups['on'] ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'features on', 'scout-build' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) ( count( $groups['picked'] ) + count( $groups['ready'] ) ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'ready to add', 'scout-build' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo esc_html( $ready . '/' . count( $ai ) ); ?></p>
					<p class="sh-note"><a href="<?php echo esc_url( self::url( 'health' ) . '#ai-search' ); ?>"><?php esc_html_e( 'ready for AI search', 'scout-build' ); ?> &rsaquo;</a></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num sh-num-text"><?php echo esc_html( $plan ? Scout_Hub_Access::PLANS[ $plan ] ?? __( 'Not set', 'scout-build' ) : __( 'Not set', 'scout-build' ) ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'this site\'s plan', 'scout-build' ); ?></p>
				</div>
			</div>
			<?php if ( $operator ) : ?>
				<div class="sh-ops">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>">
						<input type="hidden" name="do" value="plan">
						<?php wp_nonce_field( self::PLAN_ACTION ); ?>
						<label for="sh-plan" class="screen-reader-text"><?php esc_html_e( 'This site\'s plan', 'scout-build' ); ?></label>
						<select id="sh-plan" name="plan">
							<option value=""><?php esc_html_e( 'Plan not set', 'scout-build' ); ?></option>
							<?php foreach ( Scout_Hub_Access::PLANS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $plan, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Save plan', 'scout-build' ); ?></button>
					</form>
				</div>
				<p class="sh-note"><?php
					echo esc_html(
						'ff' === $plan
							? __( 'Friends and family includes the core features plus anything you add to the plan below. Plus modules are added one at a time on any plan. Clients only see what is on and what you recommend.', 'scout-build' )
							: __( 'Everything listed without a label is included in this plan. Plus modules are added one at a time on any plan, from each feature\'s More menu. Clients only see what is on and what you recommend.', 'scout-build' )
					);
				?></p>
				<?php if ( ! Scout_Hub_Access::can_install() ) : ?>
					<p class="sh-note"><?php esc_html_e( 'This site blocks plugin installs from the dashboard, so features that are not installed yet need to be uploaded by hand.', 'scout-build' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php
		$titles = array(
			'on'     => __( 'On this site', 'scout-build' ),
			'picked' => __( 'Recommended for this site, in install order', 'scout-build' ),
			'ready'  => $groups['picked'] ? __( 'Also available', 'scout-build' ) : ( $operator ? __( 'Ready to turn on', 'scout-build' ) : __( 'Available to add', 'scout-build' ) ),
			'skip'   => __( 'Not needed for this site', 'scout-build' ),
		);
		foreach ( $titles as $key => $title ) :
			if ( ! $groups[ $key ] ) {
				continue;
			}
			$step = 0;
			?>
			<div class="sh-section-head">
				<p class="sh-eyebrow"><?php echo esc_html( $title . ' · ' . count( $groups[ $key ] ) ); ?></p>
			</div>
			<section class="sh-box sh-flist-box<?php echo 'skip' === $key ? ' sh-skip' : ''; ?>">
				<ul class="sh-flist">
					<?php foreach ( $groups[ $key ] as $slug => $row ) : ?>
						<?php self::card( $slug, $row['f'], $row['s'], $operator, 'picked' === $key ? ++$step : 0 ); ?>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
		<style>
		.sh-flist-box{padding-top:4px;padding-bottom:4px}
		.sh-flist{list-style:none;margin:0;padding:0}
		.sh-frow{display:grid;grid-template-columns:minmax(0,1fr) 220px 190px;gap:6px 24px;align-items:center;padding:14px 0;border-top:1px solid var(--sh-border);margin:0}
		.sh-frow:first-child{border-top:0}
		.sh-fname{margin:0;font-weight:600;color:var(--sh-text)}
		.sh-fsum{margin:2px 0 0;color:var(--sh-text-2);font-size:var(--sh-fs-note);display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
		.sh-frow:hover .sh-fsum,.sh-frow:focus-within .sh-fsum{-webkit-line-clamp:unset}
		.sh-fmeta{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;justify-content:flex-start;font-size:var(--sh-fs-note);color:var(--sh-text-3)}
		.sh-fmeta .sh-ver{font-family:var(--sh-mono)}
		.sh-fact{display:flex;gap:8px;align-items:center;justify-content:flex-end}
		.sh-fact .button{min-height:36px;line-height:34px;padding:0 14px}
		.sh-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--sh-border-strong);margin-right:10px;vertical-align:2px}
		.sh-frow.is-on .sh-dot{background:var(--sh-good)}
		.sh-more{position:relative}
		.sh-more>summary{list-style:none;cursor:pointer;color:var(--sh-accent-text);padding:6px 10px;border-radius:var(--sh-r-control)}
		.sh-more>summary::-webkit-details-marker{display:none}
		.sh-more>summary:hover,.sh-more[open]>summary{background:var(--sh-surface-2)}
		.sh-more>div{position:absolute;right:0;top:calc(100% + 4px);z-index:20;display:flex;flex-direction:column;gap:2px;min-width:240px;padding:6px;background:var(--sh-surface);border:1px solid var(--sh-border);border-radius:var(--sh-r-control);box-shadow:var(--sh-shadow-hover)}
		.sh-more form{margin:0;display:block}
		.sh-more .button,.sh-more .button-link{display:block;width:100%;text-align:left;border:0;background:none;box-shadow:none;min-height:36px;line-height:36px;padding:0 10px;color:var(--sh-text);font-size:var(--sh-fs-note);text-decoration:none;border-radius:var(--sh-r-chip)}
		.sh-more .button:hover,.sh-more .button-link:hover{background:var(--sh-surface-2)}
		.sh-skip .sh-frow{opacity:.55}.sh-skip .sh-frow:hover,.sh-skip .sh-frow:focus-within{opacity:1}
		.sh-order{font-weight:600}.sh-build-ver{margin-left:12px;white-space:nowrap}
		@media (max-width:782px){.sh-frow{grid-template-columns:minmax(0,1fr)}.sh-fmeta,.sh-fact{justify-content:flex-start;flex-wrap:wrap}.sh-more{flex-basis:100%}.sh-more>div{position:static;margin-top:6px;box-shadow:none}}
		</style>

		<?php if ( $groups['unused'] ) : ?>
			<section class="sh-box sh-unused">
				<p class="sh-label"><?php esc_html_e( 'Not used on this site', 'scout-build' ); ?></p>
				<ul>
					<?php foreach ( $groups['unused'] as $row ) : ?>
						<li><strong><?php echo esc_html( $row['f']['name'] ); ?></strong> <span><?php echo esc_html( $row['f']['block_note'] ? $row['f']['block_note'] : __( 'The theme already does this.', 'scout-build' ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Scout → Updates (Site Standard rule 12): the theme, the plugins, the
	 * setup file, Site Sync, the post importer, site settings and the change
	 * log. Nothing elsewhere brings a new version or new content onto the site.
	 */
	private static function updates(): void {
		$operator  = Scout_Hub_Access::is_operator();
		$features  = Scout_Hub_Catalog::features();
		$installed = self::installed();
		uksort( $installed, static fn( $a, $b ) => strcasecmp( $features[ $a ]['name'] ?? $a, $features[ $b ]['name'] ?? $b ) );
		$waiting = count( array_filter( $installed, static fn( $st ) => '' !== $st['update'] ) );
		$checked = $installed ? max( array_column( $installed, 'checked' ) ) : 0;
		Scout_Hub_Features::refresh_if_stale( $checked );
		$offline = ! $waiting && Scout_Hub_Catalog::unreachable();
		// Scout Build updates itself from the Plugins screen, so its own version sits first.
		$updates   = get_site_transient( 'update_plugins' );
		$build_new = $updates->response[ plugin_basename( SCOUT_HUB_FILE ) ]->new_version ?? '';
		$build_new = $build_new && version_compare( $build_new, SCOUT_HUB_VERSION, '>' ) ? $build_new : '';

		// Most used first (Joy, 2026-11-04): the plugin list, then when updates install, then the theme.
		?>
		<section class="sh-box" id="sh-plugins">
			<h2 class="sh-title"><?php esc_html_e( 'Plugins', 'scout-build' ); ?></h2>
			<p class="sh-inline sh-note">
				<span class="sh-tag <?php echo ( $waiting || $build_new || $offline ) ? 'is-update' : 'is-current'; ?>"><?php
					$ready = $waiting + ( $build_new ? 1 : 0 );
					echo esc_html(
						$ready
							/* translators: %d: plugins with an update */
							? sprintf( _n( '%d update ready', '%d updates ready', $ready, 'scout-build' ), $ready )
							: ( $offline
								// The check failed, so "up to date" would be a guess.
								? __( 'Could not reach GitHub. Try again in 15 minutes.', 'scout-build' )
								: __( 'Everything is up to date', 'scout-build' ) )
					);
				?></span>
				<?php if ( $checked ) : ?>
					<?php
					/* translators: %s: time since, e.g. "5 minutes" */
					echo esc_html( sprintf( __( 'Checked %s ago', 'scout-build' ), human_time_diff( $checked ) ) );
					?>
				<?php endif; ?>
				<?php if ( $operator ) : ?>
					<?php echo Scout_Hub_Features::button( '', 'refresh', __( 'Check for new versions', 'scout-build' ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<?php endif; ?>
			</p>
			<table class="sh-table sh-table-plain">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'scout-build' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Version', 'scout-build' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'scout-build' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'scout-build' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Scout Build', 'scout-build' ); ?></td>
						<td><?php echo esc_html( 'v' . SCOUT_HUB_VERSION ); ?></td>
						<td>
							<?php if ( $build_new ) : ?>
								<span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update ready: v%s', 'scout-build' ), $build_new ) ); ?></span>
							<?php else : ?>
								<span class="sh-tag is-current"><?php esc_html_e( 'Up to date', 'scout-build' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $build_new && current_user_can( 'update_plugins' ) ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>"><?php esc_html_e( 'Update now', 'scout-build' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<?php foreach ( $installed as $slug => $st ) : ?>
						<tr>
							<td><?php echo esc_html( $features[ $slug ]['name'] ?? $slug ); ?><?php if ( 'on' !== $st['state'] ) : ?> <span class="sh-pill is-off"><?php esc_html_e( 'Off', 'scout-build' ); ?></span><?php endif; ?></td>
							<td><?php echo esc_html( 'v' . $st['version'] ); ?></td>
							<td>
								<?php if ( $st['update'] ) : ?>
									<span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update ready: v%s', 'scout-build' ), $st['update'] ) ); ?></span>
									<?php $wait = Scout_Hub_Rings::wait_reason( $slug, (string) $st['update'] ); ?>
									<?php if ( '' !== $wait ) : ?><span class="sh-note" style="display:block;margin-top:4px"><?php echo esc_html( $wait ); ?></span><?php endif; ?>
								<?php elseif ( $st['checked'] ) : ?>
									<span class="sh-tag is-current"><?php esc_html_e( 'Up to date', 'scout-build' ); ?></span>
								<?php endif; ?>
								<?php if ( Scout_Hub_Features::held( $slug ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Updates paused', 'scout-build' ); ?></span><?php endif; ?>
							</td>
							<td>
								<?php
								$update = $operator && $st['update'] ? Scout_Hub_Features::update_url( $slug ) : '';
								if ( '' !== $update ) {
									echo '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'scout-build' ) . '</a>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="sh-note"><?php esc_html_e( 'Scout plugins update themselves within a few hours of each release. Turning a feature on, off or back a version happens on the Features screen.', 'scout-build' ); ?></p>
		</section>
		<?php
		Scout_Hub_Approval::panel( $operator );
		Scout_Hub_Rings::panel( $operator );
		Scout_Hub_Themes::panel( $operator );
		?>

		<?php
		// The other ways content and settings reach the site. Each lands here and nowhere
		// else; a card says plainly when its tool is not on this site yet.
		$sync    = class_exists( 'Scout_Sync_Admin' ) ? admin_url( 'admin.php?page=scout&tab=sync' ) : '';
		$changes = class_exists( 'Scout_Changelog_Admin' ) ? admin_url( 'admin.php?page=scout&tab=changes' ) : '';
		$settings = (string) apply_filters( 'scout_hub_site_settings_url', '' );
		$cards   = array(
			array(
				'title' => __( 'Fill from theme', 'scout-build' ),
				'text'  => __( 'Copies the business facts in the theme\'s setup file into the features that use them. Fills blanks only; anything already set stays as it is.', 'scout-build' ),
				'go'    => Scout_Hub_Setup::data() ? ( $operator ? Scout_Hub_Features::button( '', 'refill', __( 'Fill from theme again', 'scout-build' ), 'button' ) : '' ) : '<span class="sh-note">' . esc_html__( 'This theme has no setup file.', 'scout-build' ) . '</span>',
			),
			array(
				'title' => __( 'Site Sync', 'scout-build' ),
				'text'  => __( 'Pulls page copy from the theme\'s bundle, the way it left the repo. Untouched pages update on their own; anything edited here waits for review.', 'scout-build' ),
				'go'    => '' !== $sync ? '<a class="button" href="' . esc_url( $sync ) . '">' . esc_html__( 'Open Site Sync', 'scout-build' ) . '</a>' : '<span class="sh-note">' . esc_html__( 'Needs Scout Core.', 'scout-build' ) . '</span>',
			),
			array(
				'title' => __( 'Import posts (XML)', 'scout-build' ),
				'text'  => __( 'Brings posts in from a WordPress export file, through WordPress\'s own importer.', 'scout-build' ),
				'go'    => current_user_can( 'import' ) ? '<a class="button" href="' . esc_url( admin_url( 'import.php' ) ) . '">' . esc_html__( 'Open the importer', 'scout-build' ) . '</a>' : '',
			),
			array(
				'title' => __( 'Site settings', 'scout-build' ),
				'text'  => __( 'Export this site\'s Scout settings to a file, or import one from another site.', 'scout-build' ),
				'go'    => '' !== $settings ? '<a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Export or import', 'scout-build' ) . '</a>' : '<span class="sh-note">' . esc_html__( 'Not available on this site yet.', 'scout-build' ) . '</span>',
			),
			array(
				'title' => __( 'Change log', 'scout-build' ),
				'text'  => __( 'What was changed on this site, by whom and when, with a copy you can hand to Scout so the repo catches up.', 'scout-build' ),
				'go'    => '' !== $changes ? '<a class="button" href="' . esc_url( $changes ) . '">' . esc_html__( 'Open the change log', 'scout-build' ) . '</a>' : '<span class="sh-note">' . esc_html__( 'Coming in a later Scout Core release.', 'scout-build' ) . '</span>',
			),
		);
		?>
		<div class="sh-section-head"><p class="sh-eyebrow"><?php esc_html_e( 'Content and settings', 'scout-build' ); ?></p></div>
		<div class="sh-grid">
			<?php foreach ( $cards as $card ) : ?>
				<article class="sh-card">
					<div class="sh-cardhead"><h2><?php echo esc_html( $card['title'] ); ?></h2></div>
					<p class="sh-summary"><?php echo esc_html( $card['text'] ); ?></p>
					<div class="sh-actions"><?php echo $card['go']; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php
		$log = Scout_Hub_Features::recent_log();
		if ( $operator && $log ) :
			$verbs = array( 'on' => __( 'Turned on', 'scout-build' ), 'off' => __( 'Turned off', 'scout-build' ), 'back' => __( 'Went back a version', 'scout-build' ) );
			?>
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Recent changes', 'scout-build' ); ?></p>
				<table class="sh-table">
					<tbody>
					<?php foreach ( array_slice( $log, 0, 10 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['who'] ); ?></td>
							<td><?php echo esc_html( ( $verbs[ $row['do'] ] ?? $row['do'] ) . ': ' . ( $features[ $row['slug'] ]['name'] ?? $row['slug'] ) ); ?></td>
							<td><?php echo $row['ok'] ? esc_html__( 'Done', 'scout-build' ) : esc_html( __( 'Failed: ', 'scout-build' ) . $row['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
	}


	/** One feature, as a row: the name and what it does, its plan and version, then one main action and a More menu. */
	private static function card( string $slug, array $f, array $s, bool $operator, int $step = 0 ): void {
		$states   = array(
			'missing' => __( 'Not installed', 'scout-build' ),
			'off'     => __( 'Off', 'scout-build' ),
			'on'      => __( 'On', 'scout-build' ),
		);
		$included = Scout_Hub_Access::included( $f['tier'], $slug );
		$mark     = array(
			'bonus' => '&#9670; ', // A diamond for Bonus.
			'addon' => '&#9670; ',
			'plus'  => '+ ',       // A plus sign for Plus.
		);
		$main = '';
		$more = array();
		if ( $operator ) {
			$update   = $s['update'] ? Scout_Hub_Features::update_url( $slug ) : '';
			$settings = 'on' === $s['state'] ? self::settings_url( $slug ) : '';
			if ( '' !== $update ) {
				$main = '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'scout-build' ) . '</a>';
			}
			if ( 'on' === $s['state'] ) {
				if ( '' !== $settings ) {
					$link = '<a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'scout-build' ) . '</a>';
					if ( '' === $main ) {
						$main = $link;
					} else {
						$more[] = $link; // One main button a row: Update now leads, Settings moves into More.
					}
				}
				$more[] = Scout_Hub_Features::button( $slug, 'off', __( 'Turn off', 'scout-build' ) );
				if ( $s['previous'] ) {
					/* translators: %s: version */
					$more[] = Scout_Hub_Features::button( $slug, 'back', sprintf( __( 'Go back to v%s', 'scout-build' ), $s['previous'] ) );
				}
			} else {
				$main .= Scout_Hub_Features::button( $slug, 'on', __( 'Turn on', 'scout-build' ), 'button button-primary' );
			}
			$picked_now = in_array( $slug, Scout_Hub_Access::picks(), true );
			// Only offer "add to the plan" when the plan does not already include it.
			if ( $picked_now || ! $included ) {
				ob_start();
				self::plan_button( $slug, $picked_now ? 'unpick' : 'pick', $picked_now ? __( 'Take out of this site\'s plan', 'scout-build' ) : __( 'Add to this site\'s plan', 'scout-build' ) );
				$more[] = (string) ob_get_clean();
			}
			if ( 'on' !== $s['state'] ) {
				$recommends = in_array( $slug, Scout_Hub_Access::recommended(), true );
				ob_start();
				self::plan_button( $slug, $recommends ? 'unrecommend' : 'recommend', $recommends ? __( 'Stop recommending', 'scout-build' ) : __( 'Recommend to the client', 'scout-build' ) );
				$more[] = (string) ob_get_clean();
			}
		} elseif ( 'on' === $s['state'] && '' !== self::settings_url( $slug ) ) {
			$main = '<a class="button" href="' . esc_url( self::settings_url( $slug ) ) . '">' . esc_html__( 'Settings', 'scout-build' ) . '</a>';
		} elseif ( 'on' !== $s['state'] ) {
			/* translators: %s: feature */
			$ask  = sprintf( __( 'Please add %s to our site.', 'scout-build' ), $f['name'] );
			$main = '<a class="button" href="' . esc_url( self::url( 'requests', array( 'ask' => rawurlencode( $ask ) ) ) ) . '">' . esc_html__( 'Ask us to add this', 'scout-build' ) . '</a>';
		}
		?>
		<li class="sh-frow is-<?php echo esc_attr( $s['state'] ); ?>" id="feature-<?php echo esc_attr( $slug ); ?>">
			<div>
				<p class="sh-fname"><span class="sh-dot" aria-hidden="true"></span><?php if ( $step ) : ?><span class="sh-order"><?php echo esc_html( $step . '. ' ); ?></span><?php endif; ?><?php echo esc_html( $f['name'] ); ?><span class="screen-reader-text"><?php echo esc_html( ', ' . $states[ $s['state'] ] ); ?></span></p>
				<p class="sh-fsum"><?php echo esc_html( $f['summary'] ); ?></p>
			</div>
			<div class="sh-fmeta">
				<?php if ( 'on' !== $s['state'] ) : ?><span><?php echo esc_html( $states[ $s['state'] ] ); ?></span><?php endif; ?>
				<?php if ( ! $included || in_array( $slug, Scout_Hub_Access::picks(), true ) ) : // "Included in your plan" on every row is noise; say the plan only when it is something else. ?>
					<span class="sh-tier<?php echo $included ? ' is-included' : ''; ?>"><?php echo isset( $mark[ $f['tier'] ] ) ? $mark[ $f['tier'] ] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entities. ?><?php echo esc_html( Scout_Hub_Access::tier_label( $f['tier'], $slug ) ); ?></span>
				<?php endif; ?>
				<?php if ( $f['law'] ) : ?><span class="sh-tag"><?php esc_html_e( 'Law firms', 'scout-build' ); ?></span><?php endif; ?>
				<?php if ( $s['version'] ) : ?><span class="sh-ver"><?php echo esc_html( 'v' . $s['version'] ); ?></span><?php endif; ?>
				<?php if ( $s['update'] ) : ?>
					<span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update ready: v%s', 'scout-build' ), $s['update'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( Scout_Hub_Features::held( $slug ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Updates paused', 'scout-build' ); ?></span><?php endif; ?>
			</div>
			<div class="sh-fact">
				<?php echo $main; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<?php if ( $more ) : ?>
					<details class="sh-more">
						<summary><?php esc_html_e( 'More', 'scout-build' ); ?><span class="screen-reader-text"><?php echo esc_html( ': ' . $f['name'] ); ?></span></summary>
						<div><?php echo implode( '', $more ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></div>
					</details>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/** A small operator button that adds a feature to the plan or recommends it. */
	private static function plan_button( string $slug, string $do, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
			<?php wp_nonce_field( self::PLAN_ACTION ); ?>
			<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function requests(): void {
		$operator = Scout_Hub_Access::is_operator();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- prefill only.
		$from = isset( $_GET['from'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['from'] ) ) ) : '';
		$ask  = isset( $_GET['ask'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['ask'] ) ) ) : '';
		// phpcs:enable
		$items  = Scout_Hub_Requests::all();
		$status = static function ( WP_Post $post ): string {
			$s = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_STATUS, true );
			return isset( Scout_Hub_Requests::STATUSES[ $s ] ) ? $s : 'received';
		};
		$open = count( array_filter( $items, static fn( $p ) => 'done' !== $status( $p ) ) );

		// One status line: how many are open, and where the newest one stands.
		if ( ! $items ) {
			self::status_line( 'off', __( 'No requests yet.', 'scout-build' ), __( 'Anything you send shows up below with its status.', 'scout-build' ) );
		} else {
			$newest = $items[0];
			/* translators: 1: request title, 2: its status */
			$detail = sprintf( __( 'Newest: %1$s (%2$s).', 'scout-build' ), get_the_title( $newest ), Scout_Hub_Requests::STATUSES[ $status( $newest ) ] );
			$head   = $open
				/* translators: %d: number of open requests */
				? sprintf( _n( '%d open request.', '%d open requests.', $open, 'scout-build' ), $open )
				: __( 'All requests are done.', 'scout-build' );
			self::status_line( $open ? 'warn' : 'good', $head, $detail );
		}
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'New request', 'scout-build' ); ?></h2>
			<p><?php esc_html_e( 'Tell us what you would like changed. It shows up in the list below with its status.', 'scout-build' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sh-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Requests::ACTION ); ?>">
				<input type="hidden" name="do" value="new">
				<?php wp_nonce_field( Scout_Hub_Requests::ACTION ); ?>
				<label for="sh-what"><?php esc_html_e( 'What would you like changed?', 'scout-build' ); ?></label>
				<textarea id="sh-what" name="what" rows="5" required><?php echo esc_textarea( $ask ); ?></textarea>
				<label for="sh-page"><?php esc_html_e( 'Which page? (optional)', 'scout-build' ); ?></label>
				<input id="sh-page" name="page" type="url" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" value="<?php echo esc_attr( $from ); ?>">
				<label for="sh-when"><?php esc_html_e( 'How soon?', 'scout-build' ); ?></label>
				<select id="sh-when" name="when">
					<?php foreach ( Scout_Hub_Requests::WHEN as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( current_user_can( 'upload_files' ) ) : ?>
					<label for="sh-file"><?php esc_html_e( 'A screenshot or file (optional)', 'scout-build' ); ?></label>
					<input id="sh-file" name="file" type="file">
				<?php endif; ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send request', 'scout-build' ); ?></button></p>
			</form>
		</section>

		<?php if ( $items ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Your requests', 'scout-build' ); ?></h2>
				<ul class="sh-rows">
					<?php foreach ( $items as $post ) : ?>
						<?php
						$state = $status( $post );
						$page  = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_PAGE, true );
						?>
						<li class="sh-row">
							<div>
								<p class="sh-row-name"><?php echo esc_html( get_the_title( $post ) ); ?></p>
								<p class="sh-row-sub"><?php echo esc_html( get_the_date( 'M j, Y', $post ) . ' · ' . get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?><?php echo $page ? ' · <a href="' . esc_url( $page ) . '">' . esc_html__( 'page', 'scout-build' ) . '</a>' : ''; ?></p>
							</div>
							<div class="sh-row-facts">
								<span class="sh-pill is-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( Scout_Hub_Requests::STATUSES[ $state ] ); ?></span>
							</div>
							<div class="sh-row-go">
								<?php if ( $operator ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Requests::ACTION ); ?>">
										<input type="hidden" name="do" value="status">
										<input type="hidden" name="request" value="<?php echo (int) $post->ID; ?>">
										<?php wp_nonce_field( Scout_Hub_Requests::ACTION ); ?>
										<select name="status" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: request title */ __( 'Status of %s', 'scout-build' ), get_the_title( $post ) ) ); ?>">
											<?php foreach ( Scout_Hub_Requests::STATUSES as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button"><?php esc_html_e( 'Update', 'scout-build' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function health(): void {
		$ai     = Scout_Hub_Health::ai_checks();
		$score  = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		$basics = Scout_Hub_Health::checks();
		$search = array();
		if ( class_exists( 'Scout_Core_Admin' ) && method_exists( 'Scout_Core_Admin', 'render_checks' ) ) {
			// Scout Core's search checks, minus the two the AI search list below already covers.
			$search = array_values(
				array_filter(
					Scout_Core_Admin::checks(),
					static fn( $c ) => ! in_array( $c['label'], array( 'Business details', 'Search listing (schema)' ), true )
				)
			);
		}
		?>
		<style>
			/* Compact, so the whole report fits on one screen (and one screenshot). */
			/* At most three across (the map's rule), and never smaller than the dashboard's type floor. */
			.sh-health .sh-grid-3{grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px}
			.sh-health .sh-box{padding:16px 18px; border-radius:var(--sh-r-control)}
			.sh-health .sh-label{font-size:var(--sh-fs-label); margin-bottom:6px}
			.sh-health .sh-check .sh-value{font-size:var(--sh-fs-card); margin-top:2px}
			.sh-health .sh-note{font-size:var(--sh-fs-note); line-height:1.45; margin-top:4px}
			.sh-which{margin-top:8px; font-size:var(--sh-fs-note)}
			.sh-which summary{cursor:pointer; color:var(--sh-accent-text); font-weight:500; min-height:32px; display:flex; align-items:center}
			.sh-which ul{list-style:none; margin:6px 0 0; padding:0; display:flex; flex-wrap:wrap; gap:6px}
			.sh-which li{margin:0; padding:3px 10px; border-radius:var(--sh-r-pill); background:var(--sh-surface-2); color:var(--sh-text-2); font-size:13px}
			.sh-health .sh-section-head{margin:18px 0 8px}
			.sh-health .sh-section-head h2{font-size:17px}
			.sh-health .sh-section-head .sh-note,.sh-health .sh-meter{display:none}
			.sh-copy{display:flex; flex-wrap:wrap; align-items:center; gap:10px 14px; margin:12px 0 14px}
			.sh-copy .sh-note{margin:0}
		</style>
		<div class="sh-health">
		<div class="sh-copy">
			<button type="button" class="button button-primary" id="sh-copy-report"><?php esc_html_e( 'Copy the report', 'scout-build' ); ?></button>
			<p class="sh-note" id="sh-copy-note"><?php esc_html_e( 'Copies every check on this page, plus each Scout plugin and its version, as plain text to paste into a chat.', 'scout-build' ); ?></p>
			<textarea id="sh-copy-text" hidden readonly><?php echo esc_textarea( self::health_report( $basics, $search, $ai ) ); ?></textarea>
		</div>
		<script>
		document.getElementById( 'sh-copy-report' ).addEventListener( 'click', function () {
			var text = document.getElementById( 'sh-copy-text' ).value, note = document.getElementById( 'sh-copy-note' );
			var done = function () { note.textContent = <?php echo wp_json_encode( __( 'Copied. Paste it into the chat.', 'scout-build' ) ); ?>; };
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var t = document.getElementById( 'sh-copy-text' ); t.hidden = false; t.select(); document.execCommand( 'copy' ); t.hidden = true; done();
			}
		} );
		</script>
		<?php self::check_grid( $basics ); ?>
		<?php
		if ( $search ) {
			$good = count( array_filter( $search, static fn( $c ) => 'good' === $c['status'] ) );
			?>
			<div class="sh-section-head" id="search">
				<p class="sh-eyebrow"><?php esc_html_e( 'Search listing', 'scout-build' ); ?></p>
				<h2><?php
					/* translators: 1: checks passed, 2: total checks */
					echo esc_html( sprintf( __( '%1$d of %2$d set', 'scout-build' ), $good, count( $search ) ) );
				?></h2>
				<p class="sh-note"><?php esc_html_e( 'How the site shows up in Google results and when someone shares a page.', 'scout-build' ); ?></p>
			</div>
			<?php
			Scout_Core_Admin::render_checks( $search );
		}
		?>
		<div class="sh-section-head" id="ai-search">
			<p class="sh-eyebrow"><?php esc_html_e( 'Ready for AI search', 'scout-build' ); ?></p>
			<h2><?php
				/* translators: 1: checks passed, 2: total checks */
				echo esc_html( sprintf( __( '%1$d of %2$d ready', 'scout-build' ), $score, count( $ai ) ) );
			?></h2>
			<p class="sh-note"><?php esc_html_e( 'More people ask ChatGPT, Google\'s AI answers, Perplexity or Claude instead of searching. These are the things those tools need to find this site, trust it, and name it in their answers.', 'scout-build' ); ?></p>
			<div class="sh-meter" role="img" aria-label="<?php echo esc_attr( sprintf( '%d / %d', $score, count( $ai ) ) ); ?>"><span style="width:<?php echo esc_attr( (string) round( 100 * $score / max( 1, count( $ai ) ) ) ); ?>%"></span></div>
		</div>
		<?php
		self::check_grid( $ai );
		echo '</div>';
	}

	/**
	 * The whole Site health page as plain text: one line per check, then the
	 * active Scout plugins with versions. Built for pasting into a chat.
	 */
	private static function health_report( array $basics, array $search, array $ai ): string {
		$mark  = static fn( string $state ): string => 'good' === $state ? '[ok]  ' : ( 'bad' === $state ? '[fix] ' : '[look]' );
		$lines = array(
			sprintf( 'Scout Build site health: %s (%s)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url( '/' ) ),
			'Checked ' . wp_date( 'Y-m-d H:i' ) . ', plan: ' . (string) get_option( Scout_Hub_Access::PLAN_OPTION, '' ),
			'',
			'BASICS',
		);
		foreach ( $basics as $c ) {
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . ( $c['details'] ?? $c['note'] );
		}
		if ( $search ) {
			$lines[] = '';
			$lines[] = 'SEARCH LISTING';
			foreach ( $search as $c ) {
				$state   = 'good' === $c['status'] ? 'good' : ( 'bad' === $c['status'] ? 'bad' : 'warn' );
				$items   = ! empty( $c['items'] ) ? ' (' . implode( ', ', array_map( 'get_the_title', (array) $c['items'] ) ) . ')' : '';
				$lines[] = $mark( $state ) . ' ' . $c['label'] . ': ' . $c['message'] . $items;
			}
		}
		$lines[] = '';
		$lines[] = 'READY FOR AI SEARCH';
		foreach ( $ai as $c ) {
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . ( $c['details'] ?? $c['note'] );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$lines[] = '';
		$lines[] = 'PLUGINS';
		foreach ( get_plugins() as $file => $data ) {
			$on      = is_plugin_active( $file );
			$lines[] = ( $on ? 'on   ' : 'off  ' ) . $data['Name'] . ' ' . $data['Version'];
		}
		$lines[] = '';
		$lines[] = 'Theme: ' . wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ) . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION;
		return implode( "\n", $lines );
	}

	/** @param array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}> $checks */
	private static function check_grid( array $checks ): void {
		?>
		<div class="sh-grid sh-grid-3">
			<?php foreach ( $checks as $c ) : ?>
				<section class="sh-box sh-check is-<?php echo esc_attr( $c['state'] ); ?>">
					<p class="sh-label"><?php echo esc_html( $c['label'] ); ?></p>
					<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $c['value'] ); ?></p>
					<p class="sh-note"><?php echo esc_html( $c['note'] ); ?></p>
					<?php if ( ! empty( $c['items'] ) ) : ?>
						<details class="sh-which"><summary><?php esc_html_e( 'Show which', 'scout-build' ); ?></summary>
							<ul><?php foreach ( (array) $c['items'] as $item ) : ?><li><?php echo esc_html( (string) $item ); ?></li><?php endforeach; ?></ul>
						</details>
					<?php endif; ?>
					<?php if ( ! empty( $c['link'] ) && Scout_Hub_Access::is_operator() ) : ?>
						<p class="sh-note"><a href="<?php echo esc_url( $c['link'] ); ?>"><?php echo esc_html( $c['link_text'] ?? __( 'Fix this', 'scout-build' ) ); ?> &rsaquo;</a></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Site log: every change to this site, newest first. Operators add notes,
	 * set the client wording and the measure on a line, import the history
	 * file once, and export the current view as CSV.
	 */
	private static function log(): void {
		$operator = Scout_Hub_Access::is_operator();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view switch only.
		if ( ! $operator || ! empty( $_GET['view'] ) ) {
			self::your_site( $operator );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters only.
		$filter = array(
			'month'  => isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['month'] ) ) : '',
			'kind'   => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( (string) $_GET['kind'] ) ) : '',
			'topic'  => isset( $_GET['topic'] ) ? sanitize_key( wp_unslash( (string) $_GET['topic'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '',
			'client' => ! empty( $_GET['client'] ),
			'page'   => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
		);
		$show_import = $operator && ( ! get_option( Scout_Hub_Log::IMPORTED ) || ! empty( $_GET['import'] ) );
		$open_more   = ! empty( $_GET['import'] );
		// phpcs:enable
		$page    = Scout_Hub_Log::query( $filter + array( 'per_page' => 50 ) );
		$months  = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$m            = gmdate( 'Y-m', strtotime( "-$i months", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$months[ $m ] = date_i18n( 'F Y', strtotime( $m . '-01' ) );
		}
		$no_wording = 0;
		$fresh      = get_transient( 'scout_hub_keys_show_' . get_current_user_id() );
		$source     = Scout_Hub_Log_API::keys_source();
		if ( is_array( $fresh ) ) {
			delete_transient( 'scout_hub_keys_show_' . get_current_user_id() );
			$open_more = true; // New keys are shown once, so the box opens on its own.
		}

		// One status line: lines this month, and what is waiting on the client.
		$this_month = Scout_Hub_Log::query( array( 'month' => current_time( 'Y-m' ), 'per_page' => 1 ) )['total'];
		$waiting    = Scout_Hub_Log::query( array( 'status' => 'waiting', 'client' => true, 'per_page' => 1 ) )['total'];
		self::status_line(
			$waiting ? 'warn' : 'good',
			/* translators: %d: number of log lines */
			sprintf( _n( '%d line this month.', '%d lines this month.', $this_month, 'scout-build' ), $this_month ),
			$waiting
				/* translators: %d: number of lines */
				? sprintf( _n( '%d is waiting on the client.', '%d are waiting on the client.', $waiting, 'scout-build' ), $waiting )
				: __( 'Nothing is waiting on the client.', 'scout-build' ),
			'<a class="button sh-quiet" href="' . esc_url( self::url( 'log', array( 'view' => 'client' ) ) ) . '">' . esc_html__( 'See it as the client', 'scout-build' ) . ' &rsaquo;</a>'
		);
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Every change to this site', 'scout-build' ); ?></h2>
			<p><?php esc_html_e( 'Updates, features, copy, requests and notes, written by the plugins as they happen. Lines marked "Client sees" make up the monthly update.', 'scout-build' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sh-log-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::page_slug() ); ?>">
				<input type="hidden" name="tab" value="log">
				<select name="month" aria-label="<?php esc_attr_e( 'Month', 'scout-build' ); ?>">
					<option value=""><?php esc_html_e( 'Any month', 'scout-build' ); ?></option>
					<?php foreach ( $months as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter['month'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="kind" aria-label="<?php esc_attr_e( 'Kind', 'scout-build' ); ?>">
					<option value=""><?php esc_html_e( 'Any kind', 'scout-build' ); ?></option>
					<?php foreach ( Scout_Hub_Log::KINDS as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter['kind'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="topic" aria-label="<?php esc_attr_e( 'Topic', 'scout-build' ); ?>">
					<option value=""><?php esc_html_e( 'Any topic', 'scout-build' ); ?></option>
					<?php foreach ( Scout_Hub_Log::TOPICS as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter['topic'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status" aria-label="<?php esc_attr_e( 'Status', 'scout-build' ); ?>">
					<option value=""><?php esc_html_e( 'Any status', 'scout-build' ); ?></option>
					<?php foreach ( Scout_Hub_Log::STATUSES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="client" value="1" <?php checked( $filter['client'] ); ?>> <?php esc_html_e( 'Client sees', 'scout-build' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'scout-build' ); ?></button>
			</form>
			<p class="sh-note sh-log-links"><a href="#sh-log-note"><?php esc_html_e( 'Add a note', 'scout-build' ); ?></a> &middot; <a href="#sh-log-export" data-sh-open><?php esc_html_e( 'Export CSV', 'scout-build' ); ?></a></p>

			<?php if ( ! $page['items'] ) : ?>
				<p class="sh-note"><?php esc_html_e( 'Nothing logged yet for this view. Lines appear as plugins, the theme and copy update, when requests come in, and when someone adds a note.', 'scout-build' ); ?></p>
			<?php else : ?>
				<ul class="sh-rows sh-log-list">
					<?php foreach ( $page['items'] as $post ) : ?>
						<?php
						$row = Scout_Hub_Log::to_array( $post );
						if ( $row['show_client'] && '' === $row['client_text'] ) {
							++$no_wording;
						}
						$id = (int) $row['id'];
						?>
						<li class="sh-row sh-log-row">
							<div>
								<p class="sh-row-name"><?php echo esc_html( $row['title'] ); ?></p>
								<p class="sh-row-sub"><span class="sh-log-date"><?php echo esc_html( get_the_date( 'M j, Y', $post ) ); ?></span><?php echo '' !== $row['source'] ? ' &middot; ' . esc_html( $row['source'] ) : ''; ?></p>
								<?php if ( '' !== $row['client_text'] ) : ?>
									<p class="sh-row-sub"><?php esc_html_e( 'Client wording:', 'scout-build' ); ?> <?php echo esc_html( $row['client_text'] ); ?></p>
								<?php elseif ( $row['show_client'] ) : ?>
									<p class="sh-row-sub sh-log-flag"><?php esc_html_e( 'No client wording yet, so the client sees the line above as written.', 'scout-build' ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $row['detail'] ) : ?>
									<p class="sh-row-sub"><?php echo esc_html( $row['detail'] ); ?></p>
								<?php endif; ?>
							</div>
							<div class="sh-row-facts">
								<span class="sh-tag"><?php echo esc_html( Scout_Hub_Log::KINDS[ $row['kind'] ] ?? $row['kind'] ); ?></span>
								<span class="sh-tag"><?php echo esc_html( Scout_Hub_Log::TOPICS[ $row['topic'] ] ?? $row['topic'] ); ?></span>
								<?php if ( 'done' !== $row['status'] ) : ?>
									<span class="sh-pill <?php echo 'waiting' === $row['status'] ? 'is-received' : 'is-progress'; ?>"><?php echo esc_html( Scout_Hub_Log::STATUSES[ $row['status'] ] ?? $row['status'] ); ?></span>
								<?php endif; ?>
								<?php if ( $row['show_client'] ) : ?>
									<span class="sh-tag is-current"><?php esc_html_e( 'Client sees', 'scout-build' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $row['version'] ) : ?>
									<span class="sh-ver"><?php echo esc_html( $row['version'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $row['measure'] ) : ?>
									<span><?php echo esc_html( $row['measure'] ); ?></span>
								<?php endif; ?>
							</div>
							<div class="sh-row-go">
								<button type="button" class="button" data-sh-edit="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>" aria-expanded="false" aria-controls="sh-log-edit-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>"><?php esc_html_e( 'Edit wording', 'scout-build' ); ?></button>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-log-edit" id="sh-log-edit-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
								<input type="hidden" name="do" value="edit">
								<input type="hidden" name="line" value="<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>">
								<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
								<label for="sh-ct-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>"><?php esc_html_e( 'What the client reads', 'scout-build' ); ?></label>
								<input type="text" id="sh-ct-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>" name="client_text" value="<?php echo esc_attr( $row['client_text'] ); ?>" maxlength="400" placeholder="<?php esc_attr_e( 'One plain sentence. If it claims a result, put the number in.', 'scout-build' ); ?>">
								<label for="sh-ms-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>"><?php esc_html_e( 'Measure (before and after)', 'scout-build' ); ?></label>
								<input type="text" id="sh-ms-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>" name="measure" value="<?php echo esc_attr( $row['measure'] ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'PageSpeed phone 85 to 88', 'scout-build' ); ?>">
								<label><input type="checkbox" name="show_client" value="1" <?php checked( $row['show_client'] ); ?>> <?php esc_html_e( 'Client sees this line', 'scout-build' ); ?></label>
								<?php if ( 'done' !== $row['status'] ) : ?>
									<label><input type="checkbox" name="status" value="done"> <?php esc_html_e( 'Mark as done', 'scout-build' ); ?></label>
								<?php endif; ?>
								<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'scout-build' ); ?></button></p>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $page['pages'] > 1 ) : ?>
					<p class="sh-note sh-log-pager">
						<?php
						$base = self::url( 'log', array_filter( array( 'month' => $filter['month'], 'kind' => $filter['kind'], 'topic' => $filter['topic'], 'status' => $filter['status'], 'client' => $filter['client'] ? '1' : '' ) ) );
						if ( $filter['page'] > 1 ) {
							echo '<a href="' . esc_url( add_query_arg( 'paged', $filter['page'] - 1, $base ) ) . '">&lsaquo; ' . esc_html__( 'Newer', 'scout-build' ) . '</a> &middot; ';
						}
						/* translators: 1: page, 2: pages */
						echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'scout-build' ), $filter['page'], $page['pages'] ) );
						if ( $filter['page'] < $page['pages'] ) {
							echo ' &middot; <a href="' . esc_url( add_query_arg( 'paged', $filter['page'] + 1, $base ) ) . '">' . esc_html__( 'Older', 'scout-build' ) . ' &rsaquo;</a>';
						}
						?>
					</p>
				<?php endif; ?>
				<?php if ( $no_wording ) : ?>
					<p class="sh-note sh-log-pager">
						<?php
						/* translators: %d: lines */
						echo esc_html( sprintf( _n( '%d line on this page is shown to the client without its own wording.', '%d lines on this page are shown to the client without their own wording.', $no_wording, 'scout-build' ), $no_wording ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section class="sh-box sh-log-note" id="sh-log-note">
			<h2 class="sh-title"><?php esc_html_e( 'Add a note', 'scout-build' ); ?></h2>
			<p><?php esc_html_e( 'For work done by hand or outside the site: an edit in WordPress, a listing fix, a launch step.', 'scout-build' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
				<input type="hidden" name="do" value="note">
				<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
				<label for="sh-ln-title"><?php esc_html_e( 'What changed', 'scout-build' ); ?></label>
				<input type="text" id="sh-ln-title" name="title" required maxlength="200">
				<label for="sh-ln-client"><?php esc_html_e( 'What the client reads (optional)', 'scout-build' ); ?></label>
				<input type="text" id="sh-ln-client" name="client_text" maxlength="400">
				<div class="sh-log-pair">
					<div>
						<label for="sh-ln-topic"><?php esc_html_e( 'Topic', 'scout-build' ); ?></label>
						<select id="sh-ln-topic" name="topic">
							<?php foreach ( Scout_Hub_Log::TOPICS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="sh-ln-status"><?php esc_html_e( 'Status', 'scout-build' ); ?></label>
						<select id="sh-ln-status" name="status">
							<?php foreach ( Scout_Hub_Log::STATUSES as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="sh-ln-version"><?php esc_html_e( 'Version (optional)', 'scout-build' ); ?></label>
						<input type="text" id="sh-ln-version" name="version" maxlength="60" placeholder="1.1.8">
					</div>
					<div>
						<label for="sh-ln-measure"><?php esc_html_e( 'Measure (optional)', 'scout-build' ); ?></label>
						<input type="text" id="sh-ln-measure" name="measure" maxlength="200" placeholder="<?php esc_attr_e( 'largest paint 4.1s to 3.3s', 'scout-build' ); ?>">
					</div>
					<div>
						<label for="sh-ln-date"><?php esc_html_e( 'When (blank for today)', 'scout-build' ); ?></label>
						<input type="date" id="sh-ln-date" name="date">
					</div>
					<div>
						<label><input type="checkbox" name="show_client" value="1" checked> <?php esc_html_e( 'Client sees this line', 'scout-build' ); ?></label>
					</div>
				</div>
				<label for="sh-ln-detail"><?php esc_html_e( 'Internal detail (optional, never shown to the client)', 'scout-build' ); ?></label>
				<textarea id="sh-ln-detail" name="detail" rows="3"></textarea>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add to the log', 'scout-build' ); ?></button></p>
			</form>
		</section>

		<?php self::fold_open( __( 'More options', 'scout-build' ), $open_more, 'sh-log-more' ); ?>
			<div class="sh-log-part" id="sh-log-export">
				<h3><?php esc_html_e( 'Export', 'scout-build' ); ?></h3>
				<p class="sh-note"><?php esc_html_e( 'The current filter as a CSV file.', 'scout-build' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
					<input type="hidden" name="do" value="export">
					<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
					<?php foreach ( array( 'month', 'kind', 'topic', 'status' ) as $key ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $filter[ $key ] ); ?>">
					<?php endforeach; ?>
					<input type="hidden" name="client" value="<?php echo $filter['client'] ? '1' : ''; ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Download CSV', 'scout-build' ); ?></button>
				</form>
			</div>

			<div class="sh-log-part" id="sh-hub-keys">
				<h3><?php esc_html_e( 'Hub keys', 'scout-build' ); ?></h3>
				<p class="sh-note"><?php esc_html_e( 'Two keys let the Scout Client Hub read this site\'s log, requests and versions. The read key goes in the Sheet. The write key lets Scout\'s tools add note lines. They are shown once and kept encrypted.', 'scout-build' ); ?></p>
				<?php if ( is_array( $fresh ) ) : ?>
					<p class="sh-note sh-log-warn"><strong><?php esc_html_e( 'Copy both into the password manager now. They are not shown again.', 'scout-build' ); ?></strong></p>
					<p class="sh-note"><?php esc_html_e( 'Read key (for the Sheet):', 'scout-build' ); ?></p>
					<p><code class="sh-log-key"><?php echo esc_html( $fresh['read'] ); ?></code></p>
					<p class="sh-note"><?php esc_html_e( 'Write key (for Scout\'s tools):', 'scout-build' ); ?></p>
					<p><code class="sh-log-key"><?php echo esc_html( $fresh['write'] ); ?></code></p>
				<?php elseif ( 'config' === $source ) : ?>
					<p class="sh-note"><?php esc_html_e( 'Set in wp-config.php. Those win over any keys made here.', 'scout-build' ); ?></p>
				<?php elseif ( 'site' === $source ) : ?>
					<p class="sh-note">
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Keys made here on %s. The endpoint is on.', 'scout-build' ), date_i18n( get_option( 'date_format' ), Scout_Hub_Log_API::keys_made() ) ) );
						?>
					</p>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'No keys yet, so the endpoint is off.', 'scout-build' ); ?></p>
				<?php endif; ?>
				<?php if ( 'config' !== $source ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
						<input type="hidden" name="do" value="keys">
						<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
						<button type="submit" class="button"><?php echo 'site' === $source ? esc_html__( 'Make new keys', 'scout-build' ) : esc_html__( 'Make keys', 'scout-build' ); ?></button>
						<?php if ( 'site' === $source ) : ?>
							<span class="sh-note"><?php esc_html_e( 'New keys replace the old ones, and the Sheet will need the new read key.', 'scout-build' ); ?></span>
						<?php endif; ?>
					</form>
					<?php if ( 'site' === $source ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
							<input type="hidden" name="do" value="forget_keys">
							<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
							<button type="submit" class="button sh-quiet"><?php esc_html_e( 'Forget the keys', 'scout-build' ); ?></button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="sh-log-part">
				<h3><?php esc_html_e( 'Import history', 'scout-build' ); ?></h3>
				<?php if ( $show_import ) : ?>
					<p class="sh-note"><?php esc_html_e( 'One time: the site\'s history file from Scout-Plugins, docs/site-log-import. Lines already in the log are skipped, so running it twice adds nothing.', 'scout-build' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
						<input type="hidden" name="do" value="import">
						<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
						<p><input type="file" name="history" accept=".json,application/json" required></p>
						<p><button type="submit" class="button"><?php esc_html_e( 'Import', 'scout-build' ); ?></button></p>
					</form>
				<?php else : ?>
					<p class="sh-note"><a href="<?php echo esc_url( self::url( 'log', array( 'import' => '1' ) ) ); ?>"><?php esc_html_e( 'Import a history file again', 'scout-build' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php self::fold_close(); ?>
		<script>
			document.querySelectorAll('[data-sh-edit]').forEach(function (b) {
				b.addEventListener('click', function () {
					var f = document.getElementById('sh-log-edit-' + b.getAttribute('data-sh-edit'));
					if (f) { b.setAttribute('aria-expanded', f.classList.toggle('is-open') ? 'true' : 'false'); }
				});
			});
			// A link into More options opens it first.
			document.querySelectorAll('[data-sh-open]').forEach(function (a) {
				a.addEventListener('click', function () {
					var t = document.querySelector(a.getAttribute('href'));
					var d = t && t.closest('details');
					if (d) { d.open = true; }
				});
			});
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Your site: the client's view of the Site log. Only lines marked for the
	 * client, in their client wording, with no versions or internal detail.
	 * What is waiting on them comes first, with an Approve button.
	 *
	 * @param bool $preview An operator looking at it through "See it as the client".
	 */
	private static function your_site( bool $preview ): void {
		$v    = Scout_Hub_Log::client_view();
		$open = array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) );
		$row  = static function ( array $l, bool $approve = false ): void {
			?>
			<li class="sh-row">
				<div>
					<p class="sh-row-name"><?php echo esc_html( $l['text'] ); ?></p>
					<p class="sh-row-sub"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $l['date'] ) ) ); ?><?php echo '' !== $l['measure'] ? ' &middot; ' . esc_html( $l['measure'] ) : ''; ?></p>
				</div>
				<div class="sh-row-facts">
					<?php if ( 'planned' === $l['status'] ) : ?>
						<span class="sh-pill is-progress"><?php esc_html_e( 'Planned', 'scout-build' ); ?></span>
					<?php elseif ( 'waiting' === $l['status'] ) : ?>
						<span class="sh-pill is-received"><?php esc_html_e( 'Waiting on you', 'scout-build' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="sh-row-go">
					<?php if ( $approve ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
							<input type="hidden" name="do" value="approve">
							<input type="hidden" name="line" value="<?php echo (int) $l['id']; ?>">
							<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Approve', 'scout-build' ); ?><span class="screen-reader-text"><?php echo esc_html( ': ' . $l['text'] ); ?></span></button>
						</form>
						<a class="button sh-quiet" href="<?php echo esc_url( Scout_Hub_Admin::url( 'requests', array( 'ask' => rawurlencode( __( 'About: ', 'scout-build' ) . $l['text'] ) ) ) ); ?>"><?php esc_html_e( 'Ask for a change first', 'scout-build' ); ?></a>
					<?php endif; ?>
				</div>
			</li>
			<?php
		};
		?>
		<?php if ( $preview ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'This is the client\'s view. Only lines marked "Client sees" appear, in their client wording.', 'scout-build' ); ?> <a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Back to the Site log', 'scout-build' ); ?> &rsaquo;</a></p></div>
		<?php endif; ?>

		<?php
		$need = count( $v['waiting'] );
		$done = count( $v['month'] );
		/* translators: %d: number of updates */
		$month_line = sprintf( _n( '%d update so far this month.', '%d updates so far this month.', $done, 'scout-build' ), $done );
		if ( $need ) {
			self::status_line(
				'warn',
				/* translators: %d: number of items */
				sprintf( _n( '%d thing needs your OK.', '%d things need your OK.', $need, 'scout-build' ), $need ),
				__( 'Approve below, or ask for a change first.', 'scout-build' ) . ' ' . $month_line
			);
		} else {
			self::status_line( 'good', __( 'Nothing needs you right now.', 'scout-build' ), $month_line );
		}
		?>

		<?php if ( $v['waiting'] ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Waiting for your OK', 'scout-build' ); ?></h2>
				<p><?php esc_html_e( 'Scout needs your OK on these before they go live. Approve, or ask for a change first.', 'scout-build' ); ?></p>
				<ul class="sh-rows"><?php foreach ( $v['waiting'] as $l ) { $row( $l, true ); } ?></ul>
			</section>
		<?php endif; ?>

		<section class="sh-box">
			<h2 class="sh-title"><?php echo esc_html( sprintf( /* translators: %s: month name */ __( 'This month: %s', 'scout-build' ), date_i18n( 'F' ) ) ); ?></h2>
			<?php if ( $v['month'] ) : ?>
				<ul class="sh-rows"><?php foreach ( $v['month'] as $l ) { $row( $l ); } ?></ul>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Nothing to report yet this month. Updates appear here as they happen.', 'scout-build' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Your requests', 'scout-build' ); ?></h2>
			<?php if ( $open ) : ?>
				<ul class="sh-rows">
					<?php foreach ( $open as $post ) : ?>
						<?php $status = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_STATUS, true ); ?>
						<li class="sh-row">
							<div>
								<p class="sh-row-name"><?php echo esc_html( get_the_title( $post ) ); ?></p>
								<p class="sh-row-sub"><?php echo esc_html( get_the_date( 'M j, Y', $post ) ); ?></p>
							</div>
							<div class="sh-row-facts"><span class="sh-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Scout_Hub_Requests::STATUSES[ $status ] ?? $status ); ?></span></div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Nothing open right now.', 'scout-build' ); ?></p>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( self::url( 'requests' ) ); ?>"><?php esc_html_e( 'Request a change', 'scout-build' ); ?></a></p>
		</section>

		<?php if ( $v['coming'] ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Coming up', 'scout-build' ); ?></h2>
				<ul class="sh-rows"><?php foreach ( $v['coming'] as $l ) { $row( $l ); } ?></ul>
			</section>
		<?php endif; ?>

		<?php if ( $v['earlier'] ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Earlier this year', 'scout-build' ); ?></h2>
				<ul class="sh-rows"><?php foreach ( $v['earlier'] as $l ) { $row( $l ); } ?></ul>
			</section>
		<?php endif; ?>
		<?php
	}
}
