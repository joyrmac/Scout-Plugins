<?php
/**
 * Scout Build -> Team: who is on the site, how complete each profile is, and
 * the few site-wide choices.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Admin {

	const SLUG = 'scout-team';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_TEAM_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Team', 'scout-team' ), __( 'Team', 'scout-team' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_TEAM_URL . 'assets/scout-admin.css' );
		}
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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Scout_Team::settings();
		$o      = Scout_Team::OPTION;
		$people = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$complete = 0;
		foreach ( $people as $person ) {
			$checks = Scout_Team::checks( (int) $person->ID );
			if ( count( array_filter( wp_list_pluck( $checks, 2 ) ) ) === count( $checks ) ) {
				++$complete;
			}
		}
		$law     = Scout_Team::is_law();
		$archive = get_post_type_archive_link( Scout_Team::POST_TYPE );
		$add_url = admin_url( 'post-new.php?post_type=' . Scout_Team::POST_TYPE );

		Scout_Admin_UI::open(
			__( 'Team', 'scout-team' ),
			__( 'A profile page for each person, with their credentials, plus a grid for the About page. Real people with real credentials are what visitors and search engines look for first.', 'scout-team' ),
			__( 'Team', 'scout-team' )
		);
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $people ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'People', 'scout-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( (string) count( $people ) ); ?></p>
				<p class="sh-note"><?php echo $people ? esc_html__( 'Each one has a profile page under /team/.', 'scout-team' ) : esc_html__( 'Add the first person to start the team page.', 'scout-team' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo ( $people && count( $people ) === $complete ) ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Complete profiles', 'scout-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>
					<?php
					/* translators: 1: complete profiles, 2: all profiles. */
					echo esc_html( sprintf( __( '%1$d of %2$d', 'scout-team' ), $complete, count( $people ) ) );
					?>
				</p>
				<p class="sh-note"><?php esc_html_e( 'Complete means a real photo, a role and bio, a byline, education and profile links.', 'scout-team' ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'Law firm details', 'scout-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $law ? __( 'On', 'scout-team' ) : __( 'Off', 'scout-team' ) ); ?></p>
				<p class="sh-note"><?php echo esc_html( $law ? __( 'Bar admissions show for anyone marked as an attorney.', 'scout-team' ) : __( 'Bar admissions are hidden. Turn this on below for a law firm.', 'scout-team' ) ); ?></p>
			</section>
		</div>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'The team', 'scout-team' ); ?></h2>
			<p><?php esc_html_e( 'People show in order of the Order number on each profile, lowest first, then by name. Open a person to fill in their credentials.', 'scout-team' ); ?></p>
			<?php if ( $people ) : ?>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'scout-team' ); ?></th><th scope="col"><?php esc_html_e( 'Role', 'scout-team' ); ?></th><th scope="col"><?php esc_html_e( 'Trust signals', 'scout-team' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'scout-team' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $people as $person ) : ?>
						<?php
						$checks = Scout_Team::checks( (int) $person->ID );
						$done   = count( array_filter( wp_list_pluck( $checks, 2 ) ) );
						$todo   = array();
						foreach ( $checks as $check ) {
							if ( ! $check[2] ) {
								$todo[] = $check[0];
							}
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $person->ID ) ); ?>"><strong><?php echo esc_html( '' !== $person->post_title ? $person->post_title : __( '(no name)', 'scout-team' ) ); ?></strong></a></td>
							<td><?php echo esc_html( Scout_Team::profile( (int) $person->ID )['role'] ); ?></td>
							<td>
								<span class="sh-pill <?php echo $done === count( $checks ) ? 'is-done' : 'is-received'; ?>">
									<?php
									/* translators: 1: checks done, 2: total. */
									echo esc_html( sprintf( __( '%1$d of %2$d', 'scout-team' ), $done, count( $checks ) ) );
									?>
								</span>
								<?php if ( $todo ) : ?>
									<span class="description"><?php /* translators: %s: list of missing items. */ echo esc_html( sprintf( __( 'Add: %s', 'scout-team' ), strtolower( implode( ', ', $todo ) ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'publish' === $person->post_status ? __( 'Live', 'scout-team' ) : __( 'Draft', 'scout-team' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="sh-actions" style="margin-top:14px">
				<a class="button button-primary" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add a team member', 'scout-team' ); ?></a>
				<?php if ( $people && $archive ) : ?>
					<a class="button sh-quiet" href="<?php echo esc_url( $archive ); ?>"><?php esc_html_e( 'View the team page', 'scout-team' ); ?></a>
				<?php endif; ?>
			</p>
		</section>

		<form method="post" action="options.php" class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Settings', 'scout-team' ); ?></h2>
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
						<p class="description"><?php esc_html_e( 'Off: they stay in the schema only, and visitors use the main contact details.', 'scout-team' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bylines', 'scout-team' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[bylines]" value="1" <?php checked( $s['bylines'] ); ?>> <?php esc_html_e( 'Author names on blog posts link to the writer\'s team profile', 'scout-team' ); ?></label></td>
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
			<?php submit_button(); ?>
		</form>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Show the team on a page', 'scout-team' ); ?></h2>
			<p><?php esc_html_e( 'Paste this shortcode where the team grid should go, for example on the About page. Each card links to that person\'s profile.', 'scout-team' ); ?></p>
			<table class="sh-table">
				<tr><td>[scout_team]</td><td><?php esc_html_e( 'Everyone who is live, in order.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team columns="2"]</td><td><?php esc_html_e( 'A different number of columns for this grid.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team ids="12,15"]</td><td><?php esc_html_e( 'Only these people, in this order. The ID is in the address bar when you edit a person.', 'scout-team' ); ?></td></tr>
				<tr><td>[scout_team bio="no"]</td><td><?php esc_html_e( 'Photo, name and role only.', 'scout-team' ); ?></td></tr>
			</table>
			<p class="description"><?php esc_html_e( 'With Scout Analytics on, profile opens, calls and emails report as team_profile_click, team_profile_view, team_call and team_email.', 'scout-team' ); ?></p>
		</section>
		<?php
		Scout_Admin_UI::close();
	}
}
