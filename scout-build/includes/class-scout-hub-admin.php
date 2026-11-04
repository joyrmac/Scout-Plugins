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
			add_submenu_page( 'site-build', __( 'Overview', 'scout-build' ), __( 'Overview', 'scout-build' ), 'edit_posts', 'site-build', array( __CLASS__, 'render' ) );
		}
		// Site Standard §7 (v1.2): the first screen is the Overview, for everyone.
		add_submenu_page( $parent, __( 'Overview', 'scout-build' ), __( 'Overview', 'scout-build' ), 'edit_posts', self::PAGE, array( __CLASS__, 'render' ) );
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
		add_submenu_page( $parent, __( 'Site log', 'scout-build' ), __( 'Site log', 'scout-build' ), 'edit_posts', $base . '&tab=log', array( __CLASS__, 'render' ) );
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
		// Rule 11 again: every group shows on every site. A group with nothing on gets a
		// stand-in entry that opens the Features screen, which the admin kit greys out.
		if ( class_exists( 'Scout_Admin_UI' ) && defined( 'Scout_Admin_UI::GROUPS' ) ) {
			$base = 'site-build' === $parent ? 'site-build' : self::PAGE;
			$seen = array();
			foreach ( $submenu[ $parent ] as $item ) {
				$seen[ Scout_Admin_UI::group( (string) ( $item[2] ?? '' ), wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) ) ] = true;
			}
			foreach ( Scout_Admin_UI::GROUPS as $key => $label ) {
				if ( empty( $seen[ $key ] ) ) {
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
		$keyed = array();
		foreach ( $items as $i => $item ) {
			$keyed[] = array( $rank( $item, $i ), $i, $item );
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
			/* translators: %d: log lines this month */
			return $n ? sprintf( __( '%d this month', 'scout-build' ), $n ) : '';
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
			$total = (int) array_sum( array_column( self::waiting_on_you(), 'count' ) );
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
		}
	}

	public static function save_plan(): void {
		check_admin_referer( self::PLAN_ACTION );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can set its plan.', 'scout-build' ), 403 );
		}
		Scout_Hub_Access::set_plan( sanitize_key( wp_unslash( (string) ( $_POST['plan'] ?? '' ) ) ) );
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => __( 'Plan saved.', 'scout-build' ), 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( 'features' ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'overview' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview' => __( 'Overview', 'scout-build' ),
			'inbox'    => __( 'Inbox', 'scout-build' ),
			'features' => __( 'Features', 'scout-build' ),
			'updates'  => __( 'Updates', 'scout-build' ),
			'requests' => __( 'Request a change', 'scout-build' ),
			'health'   => __( 'Site health', 'scout-build' ),
			'log'      => __( 'Site log', 'scout-build' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$lede = 'updates' === $tab ? __( 'Everything that brings a new version or new content onto this site, in one place: the theme, the plugins, the setup file, Site Sync, imports and the change log.', 'scout-build' ) : '';
		// Site name in the eyebrow, trimmed at a " | " tagline so it fits a phone.
		$site = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
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
		$waiting  = self::waiting_on_you();
		$total    = (int) array_sum( array_column( $waiting, 'count' ) );
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

		<div class="sh-grid sh-grid-2">
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Needs you', 'scout-build' ); ?></h2>
				<?php if ( $total ) : ?>
					<div class="sh-ov-rows">
						<?php foreach ( $waiting as $row ) : ?>
							<?php if ( ! $row['count'] ) { continue; } ?>
							<div class="sh-ov-row"><p class="sh-num"><?php echo (int) $row['count']; ?></p><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['label'] ); ?> &rsaquo;</a></div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Nothing is waiting on you.', 'scout-build' ); ?></p>
				<?php endif; ?>
				<p class="sh-note" style="margin-top:10px"><a href="<?php echo esc_url( self::url( 'inbox' ) ); ?>"><?php esc_html_e( 'Open the Inbox', 'scout-build' ); ?> &rsaquo;</a></p>
			</section>
			<section class="sh-box">
				<h2 class="sh-title"><?php echo esc_html( self::branded() ? __( 'With Scout', 'scout-build' ) : __( 'Behind the scenes', 'scout-build' ) ); ?></h2>
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
					<p class="sh-note" style="margin-top:8px"><a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'The whole Site log', 'scout-build' ); ?> &rsaquo;</a></p>
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
		</section>
		<?php if ( $operator ) : ?>
			<p class="sh-note" style="margin-top:14px">
				<?php esc_html_e( 'Operators:', 'scout-build' ); ?>
				<a href="<?php echo esc_url( self::url( 'features' ) ); ?>"><?php esc_html_e( 'Features', 'scout-build' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( 'updates' ) ); ?>"><?php esc_html_e( 'Updates', 'scout-build' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Site log', 'scout-build' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/** Inbox: every queue that waits on a person, in one place, with one count. */
	private static function inbox(): void {
		$rows  = self::waiting_on_you();
		$total = (int) array_sum( array_column( $rows, 'count' ) );
		?>
		<section class="sh-box">
			<p class="sh-lede" style="margin:0">
				<?php
				echo esc_html(
					$total
						/* translators: %d: items waiting */
						? sprintf( _n( '%d thing is waiting on a person.', '%d things are waiting on a person.', $total, 'scout-build' ), $total )
						: __( 'Nothing is waiting. Every queue is empty.', 'scout-build' )
				);
				?>
			</p>
		</section>
		<div class="sh-grid">
			<?php foreach ( $rows as $row ) : ?>
				<article class="sh-card<?php echo $row['count'] ? '' : ' is-off'; ?>">
					<div class="sh-cardhead"><h2><?php echo esc_html( $row['label'] ); ?></h2><span class="sh-pill <?php echo $row['count'] ? 'is-on' : 'is-off'; ?>"><?php echo (int) $row['count']; ?></span></div>
					<p class="sh-summary"><?php echo esc_html( $row['note'] ); ?></p>
					<div class="sh-actions"><a class="button<?php echo $row['count'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $row['url'] ); ?>"><?php esc_html_e( 'Open', 'scout-build' ); ?></a></div>
				</article>
			<?php endforeach; ?>
			<?php if ( class_exists( 'Scout_Reviews_Requests' ) ) : ?>
				<article class="sh-card">
					<div class="sh-cardhead"><h2><?php esc_html_e( 'Ask for reviews', 'scout-build' ); ?></h2></div>
					<p class="sh-summary"><?php esc_html_e( 'Your review link and the card to hand out. Nothing queues here; it is the way to ask.', 'scout-build' ); ?></p>
					<div class="sh-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=scout-reviews-ask' ) ); ?>"><?php esc_html_e( 'Open', 'scout-build' ); ?></a></div>
				</article>
			<?php endif; ?>
		</div>
		<?php
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
		foreach ( $fplan['use'] as $slug ) {
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
			<div class="sh-grid<?php echo 'skip' === $key ? ' sh-skip' : ''; ?>">
				<?php foreach ( $groups[ $key ] as $slug => $row ) : ?>
					<?php self::card( $slug, $row['f'], $row['s'], $operator, 'picked' === $key ? ++$step : 0 ); ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<style>.sh-skip .sh-card{opacity:.55}.sh-skip .sh-card:hover,.sh-skip .sh-card:focus-within{opacity:1}.sh-order{font-weight:600}.sh-build-ver{margin-left:12px;white-space:nowrap}</style>

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

		Scout_Hub_Themes::panel( $operator );
		Scout_Hub_Rings::panel( $operator );
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
				'go'    => '' !== $changes ? '<a class="button" href="' . esc_url( $changes ) . '">' . esc_html__( 'Open the change log', 'scout-build' ) . '</a>' : '<span class="sh-note">' . esc_html__( 'Needs Scout Core 1.7.0 or newer.', 'scout-build' ) . '</span>',
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


	/** One feature card. */
	private static function card( string $slug, array $f, array $s, bool $operator, int $step = 0 ): void {
		$states   = array(
			'missing' => __( 'Not installed', 'scout-build' ),
			'off'     => __( 'Off', 'scout-build' ),
			'on'      => __( 'On', 'scout-build' ),
		);
		$included = Scout_Hub_Access::included( $f['tier'] );
		?>
		<article class="sh-card is-<?php echo esc_attr( $s['state'] ); ?>">
			<div class="sh-cardhead">
				<h2><?php if ( $step ) : ?><span class="sh-order"><?php echo esc_html( $step . '. ' ); ?></span><?php endif; ?><?php echo esc_html( $f['name'] ); ?></h2>
				<span class="sh-pill is-<?php echo esc_attr( $s['state'] ); ?>"><?php echo esc_html( $states[ $s['state'] ] ); ?></span>
			</div>
			<p class="sh-summary"><?php echo esc_html( $f['summary'] ); ?></p>
			<p class="sh-meta">
				<span class="sh-tier<?php echo $included ? ' is-included' : ''; ?>"><?php echo esc_html( Scout_Hub_Access::tier_label( $f['tier'] ) ); ?></span>
				<?php if ( $f['law'] ) : ?><span class="sh-tag"><?php esc_html_e( 'Built for law firms', 'scout-build' ); ?></span><?php endif; ?>
				<?php if ( $s['version'] ) : ?><span class="sh-ver"><?php echo esc_html( 'v' . $s['version'] ); ?></span><?php endif; ?>
				<?php if ( $s['update'] ) : ?>
					<span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update ready: v%s', 'scout-build' ), $s['update'] ) ); ?></span>
				<?php elseif ( $s['version'] && $s['checked'] ) : ?>
					<span class="sh-tag is-current"><?php esc_html_e( 'Up to date', 'scout-build' ); ?></span>
				<?php endif; ?>
				<?php if ( Scout_Hub_Features::held( $slug ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Updates paused', 'scout-build' ); ?></span><?php endif; ?>
			</p>
			<div class="sh-actions">
				<?php
				if ( $operator ) {
					if ( 'on' !== $s['state'] && $s['update'] ) {
						$update = Scout_Hub_Features::update_url( $slug );
						if ( '' !== $update ) {
							echo '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'scout-build' ) . '</a>';
						}
					}
					if ( 'on' === $s['state'] ) {
						$update = $s['update'] ? Scout_Hub_Features::update_url( $slug ) : '';
						if ( '' !== $update ) {
							echo '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'scout-build' ) . '</a>';
						}
						$settings = self::settings_url( $slug );
						if ( '' !== $settings ) {
							echo '<a class="button' . ( '' === $update ? ' button-primary' : '' ) . '" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'scout-build' ) . '</a>';
						}
						echo Scout_Hub_Features::button( $slug, 'off', __( 'Turn off', 'scout-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( $s['previous'] ) {
							echo Scout_Hub_Features::button( $slug, 'back', sprintf( /* translators: %s: version */ __( 'Go back to v%s', 'scout-build' ), $s['previous'] ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					} else {
						echo Scout_Hub_Features::button( $slug, 'on', __( 'Turn on', 'scout-build' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				} elseif ( 'on' === $s['state'] && '' !== self::settings_url( $slug ) ) {
					echo '<a class="button" href="' . esc_url( self::settings_url( $slug ) ) . '">' . esc_html__( 'Settings', 'scout-build' ) . '</a>';
				} elseif ( 'on' !== $s['state'] ) {
					$ask = sprintf( /* translators: %s: feature */ __( 'Please add %s to our site.', 'scout-build' ), $f['name'] );
					echo '<a class="button" href="' . esc_url( self::url( 'requests', array( 'ask' => rawurlencode( $ask ) ) ) ) . '">' . esc_html__( 'Ask us to add this', 'scout-build' ) . '</a>';
				}
				?>
			</div>
		</article>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function requests(): void {
		$operator = Scout_Hub_Access::is_operator();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- prefill only.
		$from = isset( $_GET['from'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['from'] ) ) ) : '';
		$ask  = isset( $_GET['ask'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['ask'] ) ) ) : '';
		// phpcs:enable
		?>
		<div class="sh-grid sh-grid-2">
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'New request', 'scout-build' ); ?></p>
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

			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Your requests', 'scout-build' ); ?></p>
				<?php $items = Scout_Hub_Requests::all(); ?>
				<?php if ( ! $items ) : ?>
					<p class="sh-note"><?php esc_html_e( 'No requests yet. Anything you send shows up here with its status.', 'scout-build' ); ?></p>
				<?php else : ?>
					<ul class="sh-requests">
						<?php foreach ( $items as $post ) : ?>
							<?php
							$status = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_STATUS, true );
							$status = isset( Scout_Hub_Requests::STATUSES[ $status ] ) ? $status : 'received';
							$page   = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_PAGE, true );
							?>
							<li>
								<div class="sh-cardhead">
									<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
									<span class="sh-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Scout_Hub_Requests::STATUSES[ $status ] ); ?></span>
								</div>
								<p class="sh-note"><?php echo esc_html( get_the_date( 'M j, Y', $post ) . ' · ' . get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?><?php echo $page ? ' · <a href="' . esc_url( $page ) . '">' . esc_html__( 'page', 'scout-build' ) . '</a>' : ''; ?></p>
								<?php if ( $operator ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Requests::ACTION ); ?>">
										<input type="hidden" name="do" value="status">
										<input type="hidden" name="request" value="<?php echo (int) $post->ID; ?>">
										<?php wp_nonce_field( Scout_Hub_Requests::ACTION ); ?>
										<select name="status" aria-label="<?php esc_attr_e( 'Status', 'scout-build' ); ?>">
											<?php foreach ( Scout_Hub_Requests::STATUSES as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button sh-quiet"><?php esc_html_e( 'Update', 'scout-build' ); ?></button>
									</form>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
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
			.sh-health .sh-grid-3{grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px}
			.sh-health .sh-box{padding:12px 14px; border-radius:12px}
			.sh-health .sh-label{font-size:10.5px}
			.sh-health .sh-check .sh-value{font-size:15px; margin-top:4px}
			.sh-health .sh-note{font-size:12.5px; line-height:1.4; margin-top:4px}
			.sh-health .sh-section-head{margin:18px 0 8px}
			.sh-health .sh-section-head h2{font-size:17px}
			.sh-health .sh-section-head .sh-note,.sh-health .sh-meter{display:none}
			.sh-copy{display:flex; flex-wrap:wrap; align-items:center; gap:10px 14px; margin:12px 0 14px}
			.sh-copy .sh-note{margin:0}
		</style>
		<div class="sh-health">
		<div class="sh-copy">
			<button type="button" class="button button-primary" id="sh-copy-report"><?php esc_html_e( 'Copy for Claude', 'scout-build' ); ?></button>
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
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . $c['note'];
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
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . $c['note'];
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
		// phpcs:enable
		$page    = Scout_Hub_Log::query( $filter + array( 'per_page' => 50 ) );
		$months  = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$m            = gmdate( 'Y-m', strtotime( "-$i months", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$months[ $m ] = date_i18n( 'F Y', strtotime( $m . '-01' ) );
		}
		$no_wording = 0;
		?>
		<style>
			.sh-log-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
			.sh-log-filters select{min-width:120px}
			.sh-log-filters .sh-note{margin:0 0 0 auto}
			.sh-log-table{width:100%;border-collapse:collapse;font-size:13.5px}
			.sh-log-table th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--sh-text-3);padding:6px 10px 6px 0;border-bottom:1px solid var(--sh-border)}
			.sh-log-table td{padding:10px 10px 10px 0;border-bottom:1px solid var(--sh-border);vertical-align:top}
			.sh-log-table td.sh-log-date{font-family:var(--sh-mono);font-size:12px;color:var(--sh-text-3);white-space:nowrap}
			.sh-log-table .sh-pill{font-size:11px;padding:2px 8px;margin-right:4px}
			.sh-log-table .sh-pill.is-planned{background:var(--sh-glow);color:var(--sh-accent-text)}
			.sh-log-table .sh-pill.is-waiting{background:rgba(251,176,36,.18);color:var(--sh-warn)}
			.sh-log-table .sh-pill.is-client{background:rgba(14,158,115,.14);color:var(--sh-good)}
			.sh-log-meta{font-size:12px;color:var(--sh-text-3);margin:2px 0 0}
			.sh-log-flag{font-size:12px;color:var(--sh-warn);margin:2px 0 0}
			.sh-log-edit{display:none;margin-top:8px;gap:6px}
			.sh-log-edit.is-open{display:grid}
			.sh-log-edit input[type="text"]{width:100%;border-radius:8px}
			.sh-log-row .sh-quiet{padding:0 6px;min-height:0;font-size:12px}
			.sh-log-note .sh-form{grid-template-columns:1fr 1fr;gap:6px 14px}
			.sh-log-note .sh-form .sh-wide{grid-column:1/-1}
			.sh-log-note .sh-form label{margin-top:4px}
			.sh-log-note .sh-form input[type="text"],.sh-log-note .sh-form input[type="date"]{width:100%;border-radius:8px;border-color:var(--sh-border-strong)}
			@media (max-width:700px){.sh-log-note .sh-form{grid-template-columns:1fr}.sh-log-table td.sh-log-date{white-space:normal}}
		</style>

		<section class="sh-box">
			<div class="sh-section-head">
				<div>
					<p class="sh-label"><?php esc_html_e( 'Every change to this site', 'scout-build' ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'Updates, features, copy, requests and notes, written by the plugins as they happen. Lines marked "Client sees" make up the monthly update.', 'scout-build' ); ?></p>
				</div>
			</div>
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
				<?php if ( $operator ) : ?>
					<p class="sh-note"><a href="#sh-log-note"><?php esc_html_e( 'Add a note', 'scout-build' ); ?></a> &middot; <a href="#sh-log-export"><?php esc_html_e( 'Export CSV', 'scout-build' ); ?></a></p>
				<?php endif; ?>
			</form>

			<?php if ( ! $page['items'] ) : ?>
				<p class="sh-note" style="margin-top:14px"><?php esc_html_e( 'Nothing logged yet for this view. Lines appear as plugins, the theme and copy update, when requests come in, and when someone adds a note.', 'scout-build' ); ?></p>
			<?php else : ?>
				<table class="sh-log-table" style="margin-top:14px">
					<thead><tr><th><?php esc_html_e( 'When', 'scout-build' ); ?></th><th><?php esc_html_e( 'What changed', 'scout-build' ); ?></th><th><?php esc_html_e( 'By', 'scout-build' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $page['items'] as $post ) : ?>
						<?php
						$row = Scout_Hub_Log::to_array( $post );
						if ( $row['show_client'] && '' === $row['client_text'] ) {
							++$no_wording;
						}
						?>
						<tr class="sh-log-row">
							<td class="sh-log-date"><?php echo esc_html( get_the_date( 'M j, Y', $post ) ); ?></td>
							<td>
								<strong><?php echo esc_html( $row['title'] ); ?></strong>
								<p class="sh-log-meta">
									<span class="sh-pill"><?php echo esc_html( Scout_Hub_Log::KINDS[ $row['kind'] ] ?? $row['kind'] ); ?></span>
									<span class="sh-pill"><?php echo esc_html( Scout_Hub_Log::TOPICS[ $row['topic'] ] ?? $row['topic'] ); ?></span>
									<?php if ( 'done' !== $row['status'] ) : ?>
										<span class="sh-pill is-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( Scout_Hub_Log::STATUSES[ $row['status'] ] ); ?></span>
									<?php endif; ?>
									<?php if ( $row['show_client'] ) : ?>
										<span class="sh-pill is-client"><?php esc_html_e( 'Client sees', 'scout-build' ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $row['version'] ) : ?>
										<?php echo esc_html( $row['version'] ); ?>
									<?php endif; ?>
									<?php if ( '' !== $row['measure'] ) : ?>
										&middot; <?php echo esc_html( $row['measure'] ); ?>
									<?php endif; ?>
								</p>
								<?php if ( '' !== $row['client_text'] ) : ?>
									<p class="sh-log-meta"><?php esc_html_e( 'Client wording:', 'scout-build' ); ?> <?php echo esc_html( $row['client_text'] ); ?></p>
								<?php elseif ( $row['show_client'] ) : ?>
									<p class="sh-log-flag"><?php esc_html_e( 'No client wording yet; the client sees the line above as written.', 'scout-build' ); ?></p>
								<?php endif; ?>
								<?php if ( $operator && '' !== $row['detail'] ) : ?>
									<p class="sh-log-meta"><?php echo esc_html( $row['detail'] ); ?></p>
								<?php endif; ?>
								<?php if ( $operator ) : ?>
									<button type="button" class="button sh-quiet" data-sh-edit="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Edit wording', 'scout-build' ); ?></button>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-log-edit" id="sh-log-edit-<?php echo (int) $row['id']; ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
										<input type="hidden" name="do" value="edit">
										<input type="hidden" name="line" value="<?php echo (int) $row['id']; ?>">
										<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
										<label for="sh-ct-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'What the client reads', 'scout-build' ); ?></label>
										<input type="text" id="sh-ct-<?php echo (int) $row['id']; ?>" name="client_text" value="<?php echo esc_attr( $row['client_text'] ); ?>" maxlength="400" placeholder="<?php esc_attr_e( 'One plain sentence. If it claims a result, put the number in.', 'scout-build' ); ?>">
										<label for="sh-ms-<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Measure (before and after)', 'scout-build' ); ?></label>
										<input type="text" id="sh-ms-<?php echo (int) $row['id']; ?>" name="measure" value="<?php echo esc_attr( $row['measure'] ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'PageSpeed phone 85 to 88', 'scout-build' ); ?>">
										<label><input type="checkbox" name="show_client" value="1" <?php checked( $row['show_client'] ); ?>> <?php esc_html_e( 'Client sees this line', 'scout-build' ); ?></label>
										<?php if ( 'done' !== $row['status'] ) : ?>
											<label><input type="checkbox" name="status" value="done"> <?php esc_html_e( 'Mark as done', 'scout-build' ); ?></label>
										<?php endif; ?>
										<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'scout-build' ); ?></button></p>
									</form>
								<?php endif; ?>
							</td>
							<td class="sh-log-date"><?php echo esc_html( $row['source'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $page['pages'] > 1 ) : ?>
					<p class="sh-note" style="margin-top:10px">
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
				<?php if ( $operator && $no_wording ) : ?>
					<p class="sh-note" style="margin-top:10px">
						<?php
						/* translators: %d: lines */
						echo esc_html( sprintf( _n( '%d line on this page is shown to the client without its own wording.', '%d lines on this page are shown to the client without their own wording.', $no_wording, 'scout-build' ), $no_wording ) );
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php if ( $operator ) : ?>
			<div class="sh-grid sh-grid-2">
				<section class="sh-box sh-log-note" id="sh-log-note">
					<p class="sh-label"><?php esc_html_e( 'Add a note', 'scout-build' ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'For work done by hand or outside the site: an edit in WordPress, a listing fix, a launch step.', 'scout-build' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
						<input type="hidden" name="do" value="note">
						<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
						<label class="sh-wide" for="sh-ln-title"><?php esc_html_e( 'What changed', 'scout-build' ); ?></label>
						<input class="sh-wide" type="text" id="sh-ln-title" name="title" required maxlength="200">
						<label class="sh-wide" for="sh-ln-client"><?php esc_html_e( 'What the client reads (optional)', 'scout-build' ); ?></label>
						<input class="sh-wide" type="text" id="sh-ln-client" name="client_text" maxlength="400">
						<label for="sh-ln-topic"><?php esc_html_e( 'Topic', 'scout-build' ); ?></label>
						<label for="sh-ln-status"><?php esc_html_e( 'Status', 'scout-build' ); ?></label>
						<select id="sh-ln-topic" name="topic">
							<?php foreach ( Scout_Hub_Log::TOPICS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<select id="sh-ln-status" name="status">
							<?php foreach ( Scout_Hub_Log::STATUSES as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label for="sh-ln-version"><?php esc_html_e( 'Version (optional)', 'scout-build' ); ?></label>
						<label for="sh-ln-measure"><?php esc_html_e( 'Measure (optional)', 'scout-build' ); ?></label>
						<input type="text" id="sh-ln-version" name="version" maxlength="60" placeholder="1.1.8">
						<input type="text" id="sh-ln-measure" name="measure" maxlength="200" placeholder="<?php esc_attr_e( 'largest paint 4.1s to 3.3s', 'scout-build' ); ?>">
						<label for="sh-ln-date"><?php esc_html_e( 'When (blank for today)', 'scout-build' ); ?></label>
						<label><input type="checkbox" name="show_client" value="1" checked> <?php esc_html_e( 'Client sees this line', 'scout-build' ); ?></label>
						<input type="date" id="sh-ln-date" name="date">
						<span></span>
						<label class="sh-wide" for="sh-ln-detail"><?php esc_html_e( 'Internal detail (optional, never shown to the client)', 'scout-build' ); ?></label>
						<textarea class="sh-wide" id="sh-ln-detail" name="detail" rows="3"></textarea>
						<p class="sh-wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'Add to the log', 'scout-build' ); ?></button></p>
					</form>
				</section>

				<div>
					<section class="sh-box" id="sh-log-export">
						<p class="sh-label"><?php esc_html_e( 'Export', 'scout-build' ); ?></p>
						<p class="sh-note"><?php esc_html_e( 'The current filter as a CSV file.', 'scout-build' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
							<input type="hidden" name="do" value="export">
							<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
							<?php foreach ( array( 'month', 'kind', 'topic', 'status' ) as $key ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $filter[ $key ] ); ?>">
							<?php endforeach; ?>
							<input type="hidden" name="client" value="<?php echo $filter['client'] ? '1' : ''; ?>">
							<p><button type="submit" class="button"><?php esc_html_e( 'Download CSV', 'scout-build' ); ?></button></p>
						</form>
					</section>

					<?php if ( $show_import ) : ?>
						<section class="sh-box" style="margin-top:14px">
							<p class="sh-label"><?php esc_html_e( 'Import history', 'scout-build' ); ?></p>
							<p class="sh-note"><?php esc_html_e( 'One time: the site\'s history file from Scout-Plugins, docs/site-log-import. Lines already in the log are skipped, so running it twice adds nothing.', 'scout-build' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
								<input type="hidden" name="action" value="<?php echo esc_attr( Scout_Hub_Log::ACTION ); ?>">
								<input type="hidden" name="do" value="import">
								<?php wp_nonce_field( Scout_Hub_Log::ACTION ); ?>
								<p><input type="file" name="history" accept=".json,application/json" required></p>
								<p><button type="submit" class="button"><?php esc_html_e( 'Import', 'scout-build' ); ?></button></p>
							</form>
						</section>
					<?php else : ?>
						<p class="sh-note" style="margin-top:10px"><a href="<?php echo esc_url( self::url( 'log', array( 'import' => '1' ) ) ); ?>"><?php esc_html_e( 'Import a history file again', 'scout-build' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
			<script>
				document.querySelectorAll('[data-sh-edit]').forEach(function (b) {
					b.addEventListener('click', function () {
						var f = document.getElementById('sh-log-edit-' + b.getAttribute('data-sh-edit'));
						if (f) { f.classList.toggle('is-open'); }
					});
				});
			</script>
		<?php endif; ?>
		<?php
	}
}
