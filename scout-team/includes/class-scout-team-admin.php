<?php
/**
 * Scout -> People: everyone the public sees, where each person shows, and who
 * needs a look. Plus Groups (WordPress's own screen for the group list) and
 * People settings, the few site-wide choices.
 *
 * The plugin folder, the scout_team post type, the stored profile and every
 * /team/ address stay as they were; only the names on screen changed.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	/** People settings. The slug stays scout-team so saved links keep working. */
	const SLUG = 'scout-team';

	/** The People list. */
	const PEOPLE = 'scout-people';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'screen_nav' ), 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_TEAM_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Scout menu; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	private static function in_scout_menu(): bool {
		return 'options-general.php' !== self::parent();
	}

	/** People settings. */
	public static function url(): string {
		return self::in_scout_menu() ? admin_url( 'admin.php?page=' . self::SLUG ) : admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/** The People list. */
	public static function people_url( array $args = array() ): string {
		$base = self::in_scout_menu() ? admin_url( 'admin.php?page=' . self::PEOPLE ) : admin_url( 'edit.php?post_type=' . Scout_Team::POST_TYPE . '&page=' . self::PEOPLE );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** The Groups screen (WordPress's own list for the taxonomy). */
	public static function groups_slug(): string {
		return 'edit-tags.php?taxonomy=' . Scout_Team::TAX . '&post_type=' . Scout_Team::POST_TYPE;
	}

	public static function menu(): void {
		$list = 'edit.php?post_type=' . Scout_Team::POST_TYPE;
		$tax  = get_taxonomy( Scout_Team::TAX );
		$cap  = $tax ? (string) $tax->cap->manage_terms : 'manage_categories';
		if ( self::in_scout_menu() ) {
			// One Scout section in the sidebar: the post type's own menu folds into it.
			global $submenu;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::parent(), __( 'People', 'scout-team' ), __( 'People', 'scout-team' ), 'edit_posts', self::PEOPLE, array( __CLASS__, 'render_people' ) );
			add_submenu_page( self::parent(), __( 'Groups', 'scout-team' ), __( 'Groups', 'scout-team' ), $cap, self::groups_slug() );
			add_submenu_page( self::parent(), __( 'People settings', 'scout-team' ), __( 'People settings', 'scout-team' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
			return;
		}
		// No Scout menu: People sits at the top of the post type's own menu, settings under Settings.
		add_submenu_page( $list, __( 'People', 'scout-team' ), __( 'People', 'scout-team' ), 'edit_posts', self::PEOPLE, array( __CLASS__, 'render_people' ), 0 );
		add_submenu_page( 'options-general.php', __( 'People settings', 'scout-team' ), __( 'People settings', 'scout-team' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Keep the Scout menu open on WordPress's own person and group screens. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::in_scout_menu() && $screen && Scout_Team::POST_TYPE === $screen->post_type && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = Scout_Team::TAX === $screen->taxonomy ? self::groups_slug() : self::PEOPLE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	/** Is this one of WordPress's own person or group screens? */
	private static function own_screen(): bool {
		global $pagenow;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && Scout_Team::POST_TYPE === $screen->post_type && empty( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php', 'edit-tags.php', 'term.php' ), true );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) || false !== strpos( $hook, self::PEOPLE ) ) {
			Scout_Admin_UI::enqueue( SCOUT_TEAM_URL . 'assets/scout-admin.css' );
		}
		if ( false !== strpos( $hook, self::PEOPLE ) ) {
			wp_enqueue_style( 'scout-people', SCOUT_TEAM_URL . 'assets/scout-people.css', array( 'scout-admin' ), SCOUT_TEAM_VERSION );
		}
		if ( self::in_scout_menu() && self::own_screen() ) {
			Scout_Admin_UI::enqueue( SCOUT_TEAM_URL . 'assets/scout-admin.css' );
		}
	}

	/**
	 * The Scout bar and section list on WordPress's own person and group
	 * screens, so none of them is a dead end. The People list left the sidebar's
	 * post type slot, so the kit no longer prints it there by itself.
	 */
	public static function screen_nav(): void {
		if ( ! self::in_scout_menu() || ! self::own_screen() || ! method_exists( 'Scout_Admin_UI', 'nav' ) ) {
			return;
		}
		$screen = get_current_screen();
		echo '<div class="sh sh-screen-nav">';
		Scout_Admin_UI::nav( $screen && Scout_Team::TAX === $screen->taxonomy ? self::groups_slug() : self::PEOPLE );
		echo '</div>';
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-team' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'scout_team_group', Scout_Team::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$law = (string) ( $in['law'] ?? 'auto' );
		return array(
			'law'     => in_array( $law, array( 'auto', 'on', 'off' ), true ) ? $law : 'auto',
			'contact' => ! empty( $in['contact'] ) ? 1 : 0,
			'columns' => min( 4, max( 2, absint( $in['columns'] ?? 3 ) ) ),
			'bylines' => ! empty( $in['bylines'] ) ? 1 : 0,
		);
	}

	/* ---------- the People list ---------- */

	/** "DL" for Dana Lee. */
	public static function initials( string $name ): string {
		$words = preg_split( '/\s+/', trim( wp_strip_all_tags( $name ) ) );
		$words = array_values( array_filter( (array) $words, 'strlen' ) );
		if ( ! $words ) {
			return '?';
		}
		$first = $words[0];
		$last  = count( $words ) > 1 ? $words[ count( $words ) - 1 ] : '';
		$pick  = static fn( string $w ): string => function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 );
		$out   = $pick( $first ) . ( '' !== $last ? $pick( $last ) : '' );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $out ) : strtoupper( $out );
	}

	/** A linked login that can run the site: an owner, admin or editor. */
	private static function runs_site( int $user ): bool {
		return $user > 0 && ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_others_posts' ) );
	}

	public static function render_people(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$posts = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 500,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$terms = get_terms(
			array(
				'taxonomy'   => Scout_Team::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$terms = is_wp_error( $terms ) ? array() : $terms;

		// One record per person, in team page order.
		$people = array();
		$counts = array(
			'team' => 0,
			'card' => 0,
			'look' => 0,
		);
		$in_group = array();
		foreach ( $posts as $post ) {
			$id     = (int) $post->ID;
			$p      = Scout_Team::profile( $id );
			$groups = get_the_terms( $id, Scout_Team::TAX );
			$groups = is_array( $groups ) ? $groups : array();
			$user   = (int) $p['user'];
			$login  = $user > 0 && get_userdata( $user );
			$row    = array(
				'post'   => $post,
				'p'      => $p,
				'groups' => $groups,
				'slugs'  => wp_list_pluck( $groups, 'slug' ),
				'team'   => Scout_Team::on_team_page( $id ),
				'look'   => Scout_Team::needs_look( $id ),
				'login'  => (bool) $login,
				'runs'   => $login && self::runs_site( $user ),
			);
			$counts['team'] += $row['team'] ? 1 : 0;
			$counts['card'] += Scout_Team::shows( $p, 'card' ) ? 1 : 0;
			$counts['look'] += $row['look'] ? 1 : 0;
			foreach ( $groups as $g ) {
				$in_group[ $g->slug ] = ( $in_group[ $g->slug ] ?? 0 ) + 1;
			}
			$people[] = $row;
		}
		$total = count( $people );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';
		$look  = ! empty( $_GET['look'] );
		// phpcs:enable
		$known = wp_list_pluck( $terms, 'slug' );
		if ( '' !== $group && ! in_array( $group, $known, true ) ) {
			$group = '';
		}

		$add_url = admin_url( 'post-new.php?post_type=' . Scout_Team::POST_TYPE );
		Scout_Admin_UI::open(
			__( 'People', 'scout-team' ),
			__( 'Everyone the public sees on your site: your team, speakers, writers and members who want to be listed. One profile each, and you choose where each person shows.', 'scout-team' )
		);
		echo '<div class="st-people">';

		$go = '<a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add a person', 'scout-team' ) . '</a>';
		if ( ! $total ) {
			self::ui_status( 'off', __( 'No one yet.', 'scout-team' ), __( 'Add the first person to start the team page.', 'scout-team' ), $go );
		} else {
			/* translators: %d: number of people. */
			$headline = sprintf( _n( '%d person.', '%d people.', $total, 'scout-team' ), $total );
			$detail   = sprintf(
				/* translators: 1: people on the team page, 2: business cards on, 3: people who need a look. */
				__( '%1$d on the team page, %2$d cards on, %3$d need a look.', 'scout-team' ),
				$counts['team'],
				$counts['card'],
				$counts['look']
			);
			self::ui_status( $counts['look'] ? 'warn' : 'good', $headline, $detail, $go );
		}

		if ( $total ) {
			// Filter chips: Everyone, each group, Needs a look.
			echo '<nav class="st-chips" aria-label="' . esc_attr__( 'Show', 'scout-team' ) . '">';
			self::chip( __( 'Everyone', 'scout-team' ), $total, self::people_url(), '' === $group && ! $look );
			foreach ( $terms as $term ) {
				self::chip( $term->name, (int) ( $in_group[ $term->slug ] ?? 0 ), self::people_url( array( 'group' => $term->slug ) ), $group === $term->slug && ! $look );
			}
			self::chip( __( 'Needs a look', 'scout-team' ), $counts['look'], self::people_url( array( 'look' => 1 ) ), $look, 'is-look' );
			echo '</nav>';
		}

		echo '<section class="sh-box">';
		if ( $total ) {
			echo '<p class="sh-note st-hint">' . esc_html__( 'Listed in team page order: the Order number on each person (in the Page Attributes box), lowest first, then by name.', 'scout-team' ) . '</p>';
		}

		if ( $look ) {
			$rows = array_values( array_filter( $people, static fn( $r ) => (bool) $r['look'] ) );
			self::rows( $rows, __( 'No one needs a look. Every person has a photo with alt text, a source on every credential, and is live.', 'scout-team' ) );
		} elseif ( '' !== $group ) {
			$rows = array_values( array_filter( $people, static fn( $r ) => in_array( $group, $r['slugs'], true ) ) );
			self::rows( $rows, __( 'No one in this group yet.', 'scout-team' ) );
		} elseif ( $total ) {
			// Everyone: the people who run the site first, then each group. A person shows once.
			$sections = array(
				'runs'     => array( __( 'Runs the site', 'scout-team' ), array() ),
				'team'     => array( __( 'Team', 'scout-team' ), array() ),
				'speakers' => array( __( 'Speakers', 'scout-team' ), array() ),
				'writers'  => array( __( 'Writers', 'scout-team' ), array() ),
				'members'  => array( __( 'Members (listed)', 'scout-team' ), array() ),
				'other'    => array( __( 'Everyone else', 'scout-team' ), array() ),
			);
			foreach ( $people as $r ) {
				$key = 'other';
				if ( $r['runs'] ) {
					$key = 'runs';
				} else {
					foreach ( array( 'team', 'speakers', 'writers', 'members' ) as $slug ) {
						if ( in_array( $slug, $r['slugs'], true ) ) {
							$key = $slug;
							break;
						}
					}
				}
				$sections[ $key ][1][] = $r;
			}
			foreach ( $sections as $key => $section ) {
				if ( ! $section[1] ) {
					continue;
				}
				echo '<h2 class="st-section" id="' . esc_attr( 'st-section-' . $key ) . '">' . esc_html( $section[0] ) . ' <span>' . esc_html( (string) count( $section[1] ) ) . '</span></h2>';
				self::rows( $section[1], '' );
			}
		} else {
			echo '<p>' . esc_html__( 'Add a person to give them a profile. You choose where they show: their own page, the team page, a byline on their posts, or a business card.', 'scout-team' ) . '</p>';
		}

		$archive = get_post_type_archive_link( Scout_Team::POST_TYPE );
		echo '<p class="sh-actions st-more">';
		if ( $total && $archive ) {
			echo '<a class="button sh-quiet" href="' . esc_url( $archive ) . '">' . esc_html__( 'View the team page', 'scout-team' ) . '</a>';
		}
		echo '<a class="button sh-quiet" href="' . esc_url( admin_url( 'edit.php?post_type=' . Scout_Team::POST_TYPE ) ) . '">' . esc_html__( 'Full list (bulk edit and trash)', 'scout-team' ) . '</a>';
		echo '</p>';
		echo '</section>';

		echo '</div>';
		Scout_Admin_UI::close();
	}

	/** One filter chip. */
	private static function chip( string $label, int $count, string $url, bool $on, string $class = '' ): void {
		echo '<a class="st-chip' . ( $on ? ' is-on' : '' ) . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" href="' . esc_url( $url ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="st-chip-count">' . esc_html( (string) $count ) . '</span></a>';
	}

	/**
	 * A list of people, one row each.
	 *
	 * @param array  $rows  Person records from render_people().
	 * @param string $empty What to say when there is no one.
	 */
	private static function rows( array $rows, string $empty ): void {
		if ( ! $rows ) {
			if ( '' !== $empty ) {
				echo '<p class="st-empty">' . esc_html( $empty ) . '</p>';
			}
			return;
		}
		echo '<ul class="sh-rows st-rows">';
		foreach ( $rows as $r ) {
			self::row( $r );
		}
		echo '</ul>';
	}

	private static function row( array $r ): void {
		$post = $r['post'];
		$p    = $r['p'];
		$id   = (int) $post->ID;
		$name = '' !== $post->post_title ? wp_strip_all_tags( $post->post_title ) : __( '(no name)', 'scout-team' );
		$sub  = array();
		if ( '' !== $p['role'] ) {
			$sub[] = $p['role'];
		}
		if ( $r['groups'] ) {
			$sub[] = implode( ', ', wp_list_pluck( $r['groups'], 'name' ) );
		}

		// Where they show.
		$tags = array();
		if ( Scout_Team::shows( $p, 'bio' ) ) {
			$tags[] = __( 'Bio', 'scout-team' );
		}
		if ( $r['team'] ) {
			$tags[] = __( 'Team page', 'scout-team' );
		}
		if ( Scout_Team::shows( $p, 'card' ) ) {
			$tags[] = __( 'Card', 'scout-team' );
		}
		if ( Scout_Team::shows( $p, 'byline' ) && $r['login'] ) {
			$n = (int) count_user_posts( (int) $p['user'], 'post', true );
			/* translators: %d: number of published posts. */
			$tags[] = sprintf( _n( 'Byline, %d post', 'Byline, %d posts', $n, 'scout-team' ), $n );
		}
		if ( Scout_Team::shows( $p, 'speaker' ) ) {
			$tags[] = __( 'Speaker page', 'scout-team' );
		}

		$edit = get_edit_post_link( $id );
		echo '<li class="sh-row st-person' . ( $r['look'] ? ' is-look' : '' ) . '">';
		echo '<div class="st-person-main">';
		if ( has_post_thumbnail( $id ) ) {
			echo '<span class="st-avatar">' . get_the_post_thumbnail( $id, array( 96, 96 ), array( 'alt' => '' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		} else {
			echo '<span class="st-avatar is-initials" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
		}
		echo '<div class="st-person-text">';
		echo '<p class="sh-row-name">' . esc_html( $name ) . '</p>';
		if ( $sub ) {
			echo '<p class="sh-row-sub">' . esc_html( implode( ' · ', $sub ) ) . '</p>';
		}
		if ( $r['look'] ) {
			echo '<p class="st-look"><strong>' . esc_html__( 'Needs a look:', 'scout-team' ) . '</strong> ' . esc_html( implode( ', ', $r['look'] ) ) . '</p>';
		}
		echo '</div></div>';

		echo '<div class="sh-row-facts">';
		if ( $r['login'] ) {
			echo '<span class="sh-tag st-signin">' . esc_html__( 'Can sign in', 'scout-team' ) . '</span>';
		}
		if ( $tags ) {
			echo '<span class="screen-reader-text">' . esc_html__( 'Shows on:', 'scout-team' ) . '</span>';
			foreach ( $tags as $tag ) {
				echo '<span class="sh-tag">' . esc_html( $tag ) . '</span>';
			}
		} else {
			echo '<span class="sh-tag">' . esc_html__( 'Not shown anywhere', 'scout-team' ) . '</span>';
		}
		echo '</div>';

		echo '<div class="sh-row-go">';
		if ( $edit ) {
			echo '<a class="button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'scout-team' ) . '<span class="screen-reader-text"> ' . esc_html( $name ) . '</span></a>';
		}
		echo '</div>';
		echo '</li>';
	}

	/* ---------- People settings ---------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Scout_Team::settings();
		$o = Scout_Team::OPTION;

		Scout_Admin_UI::open(
			__( 'People settings', 'scout-team' ),
			__( 'Choices for everyone at once: law firm details, email and phone, bylines and the team grid. Each person is on the People screen.', 'scout-team' )
		);
		settings_errors( $o );
		?>
		<section class="sh-box">
			<form method="post" action="options.php">
				<h2 class="sh-title"><?php esc_html_e( 'Profiles, bylines and the grid', 'scout-team' ); ?></h2>
				<?php settings_fields( 'scout_team_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Law firm details', 'scout-team' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Law firm details', 'scout-team' ); ?></legend>
								<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="auto" <?php checked( 'auto', $s['law'] ); ?>> <?php esc_html_e( 'Decide from the business type in Scout Core (LegalService or Attorney turns it on)', 'scout-team' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="on" <?php checked( 'on', $s['law'] ); ?>> <?php esc_html_e( 'On: show bar admissions for attorneys', 'scout-team' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="off" <?php checked( 'off', $s['law'] ); ?>> <?php esc_html_e( 'Off', 'scout-team' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email and phone', 'scout-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[contact]" value="1" <?php checked( $s['contact'] ); ?>> <?php esc_html_e( 'Show each person\'s email and phone on their profile page', 'scout-team' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off: they stay in the schema only, and visitors use the main contact details. Card only phone and email never show here either way.', 'scout-team' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bylines', 'scout-team' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[bylines]" value="1" <?php checked( $s['bylines'] ); ?>> <?php esc_html_e( 'Author names on blog posts link to the writer\'s profile page', 'scout-team' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="st-columns"><?php esc_html_e( 'Grid columns on wide screens', 'scout-team' ); ?></label></th>
						<td><select id="st-columns" name="<?php echo esc_attr( $o ); ?>[columns]">
							<?php foreach ( array( 2, 3, 4 ) as $n ) : ?>
								<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( (int) $s['columns'], $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
							<?php endforeach; ?>
						</select>
							<p class="description"><?php esc_html_e( 'Phones always show one column.', 'scout-team' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'scout-team' ) ); ?>
			</form>
		</section>

		<?php self::ui_fold_open( __( 'Show the team on a page', 'scout-team' ) ); ?>
			<p><?php esc_html_e( 'Paste this shortcode where the team grid should go, for example on the About page. Each card links to that person\'s profile page.', 'scout-team' ); ?></p>
			<table class="sh-table">
				<tr><td>[scout_team]</td><td><?php esc_html_e( 'The team page: everyone live in the Team group with "The team page" ticked, in order.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team columns="2"]</td><td><?php esc_html_e( 'A different number of columns for this grid.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team ids="12,15"]</td><td><?php esc_html_e( 'Only these people, in this order, whatever their group. The ID is in the address bar when you edit a person.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team bio="no"]</td><td><?php esc_html_e( 'Photo, name and role only.', 'scout-team' ); ?></td></tr>
			</table>
			<p class="description"><?php esc_html_e( 'With Scout Analytics on, profile opens, calls and emails report as team_profile_click, team_profile_view, team_call and team_email.', 'scout-team' ); ?></p>
		<?php self::ui_fold_close(); ?>
		<?php
		Scout_Admin_UI::close();
	}
}
