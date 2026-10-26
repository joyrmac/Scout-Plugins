<?php
/**
 * The dashboard screens: Features, Requests and Health.
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

	public static function url( string $tab = 'features', array $args = array() ): string {
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
			add_submenu_page( 'site-build', __( 'Features', 'scout-build' ), __( 'Features', 'scout-build' ), 'edit_posts', 'site-build', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, __( 'Features', 'scout-build' ), __( 'Features', 'scout-build' ), 'edit_posts', self::PAGE, array( __CLASS__, 'render' ) );
		if ( 'site-build' === $parent ) {
			remove_submenu_page( 'site-build', self::PAGE ); // The section's first item already opens this screen.
		}
		// The other two tabs get their own sidebar entries, so nobody has to find the tabs first.
		$base = 'site-build' === $parent ? 'site-build' : self::PAGE;
		add_submenu_page( $parent, __( 'Request a change', 'scout-build' ), __( 'Request a change', 'scout-build' ), 'edit_posts', $base . '&tab=requests', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Site health', 'scout-build' ), __( 'Site health', 'scout-build' ), 'edit_posts', $base . '&tab=health', array( __CLASS__, 'render' ) );
		$slug = 'site-build' === $parent ? 'site-build' : self::PAGE;
		if ( get_option( 'scout_hub_page' ) !== $slug ) {
			update_option( 'scout_hub_page', $slug, false );
		}
	}

	/**
	 * One order for the Scout menu on every site, which the shared section nav
	 * follows too: Features and Site health first, then the settings screens
	 * (Business info, SEO, AI search, images), then the rest, Request a change last.
	 */
	public static function first(): void {
		global $submenu;
		$parent = self::parent();
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$items = array_values( $submenu[ $parent ] );
		$rank  = static function ( array $item, int $i ): int {
			$slug = (string) ( $item[2] ?? '' );
			$page = strtok( $slug, '&' );
			if ( false !== strpos( $slug, 'tab=requests' ) ) {
				return 9000;
			}
			if ( false !== strpos( $slug, 'tab=health' ) ) {
				return 100;
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
		if ( false !== strpos( $slug, 'tab=requests' ) ) {
			$open = count( array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) ) );
			/* translators: %d: open requests */
			return $open ? sprintf( __( '%d open', 'scout-build' ), $open ) : '';
		}
		$on = 0;
		foreach ( array_keys( Scout_Hub_Catalog::features() ) as $f ) {
			$on += 'on' === Scout_Hub_Features::status( $f )['state'] ? 1 : 0;
		}
		/* translators: %d: features on */
		return sprintf( __( '%d on', 'scout-build' ), $on );
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
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Features', 'scout-build' ) . '</a>' );
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
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'features' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'features' => __( 'Your features', 'scout-build' ),
			'requests' => __( 'Request a change', 'scout-build' ),
			'health'   => __( 'Site health', 'scout-build' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'features';
		// Site name in the eyebrow, trimmed at a " | " tagline so it fits a phone.
		$site = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
		Scout_Admin_UI::open( $tabs[ $tab ], '', $site );
		?>
			<?php self::notice(); ?>
			<?php
			if ( 'requests' === $tab ) {
				self::requests();
			} elseif ( 'health' === $tab ) {
				self::health();
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

	private static function features(): void {
		$operator = Scout_Hub_Access::is_operator();
		$features = Scout_Hub_Catalog::features();
		$plan     = Scout_Hub_Access::plan();
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
					<?php echo Scout_Hub_Features::button( '', 'refresh', __( 'Check for new versions', 'scout-build' ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
					<?php if ( Scout_Hub_Setup::data() ) : ?>
						<?php echo Scout_Hub_Features::button( '', 'refill', __( 'Fill from theme again', 'scout-build' ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
					<?php endif; ?>
					<?php
					$installed = array_filter( array_map( array( 'Scout_Hub_Features', 'status' ), array_keys( Scout_Hub_Catalog::features() ) ), static fn( $st ) => '' !== $st['version'] );
					$waiting   = count( array_filter( $installed, static fn( $st ) => '' !== $st['update'] ) );
					$checked   = $installed ? max( array_column( $installed, 'checked' ) ) : 0;
					Scout_Hub_Features::refresh_if_stale( $checked );
					?>
					<p class="sh-inline sh-note">
						<?php $offline = ! $waiting && Scout_Hub_Catalog::unreachable(); ?>
						<span class="sh-tag <?php echo ( $waiting || $offline ) ? 'is-update' : 'is-current'; ?>"><?php
							echo esc_html(
								$waiting
									/* translators: %d: features with an update */
									? sprintf( _n( '%d update ready', '%d updates ready', $waiting, 'scout-build' ), $waiting )
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
						<?php
						// Scout Build updates itself from the Plugins screen, so its own version sits here.
						$updates   = get_site_transient( 'update_plugins' );
						$build_new = $updates->response[ plugin_basename( SCOUT_HUB_FILE ) ]->new_version ?? '';
						?>
						<span class="sh-build-ver">
							<?php
							/* translators: %s: Scout Build version */
							echo esc_html( sprintf( __( 'Scout Build %s', 'scout-build' ), SCOUT_HUB_VERSION ) );
							?>
							<?php if ( $build_new && version_compare( $build_new, SCOUT_HUB_VERSION, '>' ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">
									<?php
									/* translators: %s: newer Scout Build version */
									echo esc_html( sprintf( __( '%s ready on the Plugins screen', 'scout-build' ), $build_new ) );
									?>
								</a>
							<?php endif; ?>
						</span>
					</p>
				</div>
				<?php if ( ! Scout_Hub_Access::can_install() ) : ?>
					<p class="sh-note"><?php esc_html_e( 'This site blocks plugin installs from the dashboard, so features that are not installed yet need to be uploaded by hand.', 'scout-build' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php Scout_Hub_Themes::panel( $operator ); ?>

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
		<style>.sh-skip .sh-card{opacity:.55}.sh-skip .sh-card:hover,.sh-skip .sh-card:focus-within{opacity:1}.sh-step{font-weight:600}.sh-build-ver{margin-left:12px;white-space:nowrap}</style>

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
				<h2><?php if ( $step ) : ?><span class="sh-step"><?php echo esc_html( $step . '. ' ); ?></span><?php endif; ?><?php echo esc_html( $f['name'] ); ?></h2>
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
}
