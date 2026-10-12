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
	 * Inside a theme's Build section, put Features first, so clicking the
	 * section opens the hub instead of the theme's setup tools.
	 */
	public static function first(): void {
		global $submenu;
		$parent = self::parent();
		if ( 'site-build' === $parent || empty( $submenu[ $parent ] ) ) {
			return;
		}
		$mine = array();
		$rest = array();
		foreach ( $submenu[ $parent ] as $item ) {
			if ( 0 === strpos( (string) ( $item[2] ?? '' ), self::PAGE ) ) {
				$mine[] = $item;
			} else {
				$rest[] = $item;
			}
		}
		if ( $mine ) {
			$submenu[ $parent ] = array_merge( $mine, $rest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
		}
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
		?>
		<div class="wrap sh">
			<header class="sh-head">
				<div>
					<p class="sh-eyebrow"><?php echo esc_html( ( self::branded() ? 'Scout' : 'Site Build' ) . ' · ' . $tabs[ $tab ] ); ?></p>
					<h1><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></h1>
				</div>
				<?php if ( self::branded() ) : ?>
					<p class="sh-brand"><span class="sh-bolt" aria-hidden="true"></span><?php esc_html_e( 'Scout', 'scout-build' ); ?></p>
				<?php endif; ?>
			</header>
			<?php
			$on   = 0;
			foreach ( array_keys( Scout_Hub_Catalog::features() ) as $slug ) {
				$on += 'on' === Scout_Hub_Features::status( $slug )['state'] ? 1 : 0;
			}
			$open = count(
				array_filter(
					Scout_Hub_Requests::all(),
					static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true )
				)
			);
			$ai    = Scout_Hub_Health::ai_checks();
			$ready = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
			$hints = array(
				/* translators: %d: features on */
				'features' => sprintf( _n( '%d feature on', '%d features on', $on, 'scout-build' ), $on ),
				/* translators: %d: open requests */
				'requests' => $open ? sprintf( _n( '%d open request', '%d open requests', $open, 'scout-build' ), $open ) : __( 'Ask us for a change', 'scout-build' ),
				/* translators: 1: checks passed, 2: total */
				'health'   => sprintf( __( 'AI search %1$d of %2$d', 'scout-build' ), $ready, count( $ai ) ),
			);
			?>
			<nav class="sh-tabs" aria-label="<?php esc_attr_e( 'Site Build sections', 'scout-build' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $key === $tab ? 'is-active' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<span class="sh-tab-label"><?php echo esc_html( $label ); ?></span>
						<span class="sh-tab-hint"><?php echo esc_html( $hints[ $key ] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
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
			<?php if ( self::branded() ) : ?>
				<footer class="sh-foot">
					<span class="sh-bolt" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Built and looked after by Scout Media & Consulting.', 'scout-build' ); ?>
						<a href="mailto:team@scoutraleigh.com">team@scoutraleigh.com</a> · <a href="tel:+19843259661">(984) 325-9661</a></p>
				</footer>
			<?php endif; ?>
		</div>
		<?php
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
					<p class="sh-num"><?php echo (int) count( $groups['ready'] ); ?></p>
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
				</div>
				<?php if ( ! Scout_Hub_Access::can_install() ) : ?>
					<p class="sh-note"><?php esc_html_e( 'This site blocks plugin installs from the dashboard, so features that are not installed yet need to be uploaded by hand.', 'scout-build' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php
		$titles = array(
			'on'    => __( 'On this site', 'scout-build' ),
			'ready' => $operator ? __( 'Ready to turn on', 'scout-build' ) : __( 'Available to add', 'scout-build' ),
		);
		foreach ( $titles as $key => $title ) :
			if ( ! $groups[ $key ] ) {
				continue;
			}
			?>
			<div class="sh-section-head">
				<p class="sh-eyebrow"><?php echo esc_html( $title . ' · ' . count( $groups[ $key ] ) ); ?></p>
			</div>
			<div class="sh-grid">
				<?php foreach ( $groups[ $key ] as $slug => $row ) : ?>
					<?php self::card( $slug, $row['f'], $row['s'], $operator ); ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

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
	private static function card( string $slug, array $f, array $s, bool $operator ): void {
		$states   = array(
			'missing' => __( 'Not installed', 'scout-build' ),
			'off'     => __( 'Off', 'scout-build' ),
			'on'      => __( 'On', 'scout-build' ),
		);
		$included = Scout_Hub_Access::included( $f['tier'] );
		?>
		<article class="sh-card is-<?php echo esc_attr( $s['state'] ); ?>">
			<div class="sh-cardhead">
				<h2><?php echo esc_html( $f['name'] ); ?></h2>
				<span class="sh-pill is-<?php echo esc_attr( $s['state'] ); ?>"><?php echo esc_html( $states[ $s['state'] ] ); ?></span>
			</div>
			<p class="sh-summary"><?php echo esc_html( $f['summary'] ); ?></p>
			<p class="sh-meta">
				<span class="sh-tier<?php echo $included ? ' is-included' : ''; ?>"><?php echo esc_html( Scout_Hub_Access::tier_label( $f['tier'] ) ); ?></span>
				<?php if ( $f['law'] ) : ?><span class="sh-tag"><?php esc_html_e( 'Built for law firms', 'scout-build' ); ?></span><?php endif; ?>
				<?php if ( $s['version'] ) : ?><span class="sh-ver"><?php echo esc_html( 'v' . $s['version'] ); ?></span><?php endif; ?>
				<?php if ( $s['update'] ) : ?><span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update to v%s ready', 'scout-build' ), $s['update'] ) ); ?></span><?php endif; ?>
				<?php if ( Scout_Hub_Features::held( $slug ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Updates paused', 'scout-build' ); ?></span><?php endif; ?>
			</p>
			<div class="sh-actions">
				<?php
				if ( $operator ) {
					if ( 'on' === $s['state'] ) {
						echo Scout_Hub_Features::button( $slug, 'off', __( 'Turn off', 'scout-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( $s['previous'] ) {
							echo Scout_Hub_Features::button( $slug, 'back', sprintf( /* translators: %s: version */ __( 'Go back to v%s', 'scout-build' ), $s['previous'] ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					} else {
						echo Scout_Hub_Features::button( $slug, 'on', __( 'Turn on', 'scout-build' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
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
		$ai    = Scout_Hub_Health::ai_checks();
		$score = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		?>
		<p class="sh-lede"><?php esc_html_e( 'A quick look at the basics, read from WordPress itself. Nothing here is sent anywhere.', 'scout-build' ); ?></p>
		<?php self::check_grid( Scout_Hub_Health::checks() ); ?>
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
