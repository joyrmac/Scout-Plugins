<?php
/**
 * Scout Build -> Events: what's coming up, what each event still needs, how
 * to place the lists on a page, and the few site-wide settings.
 *
 * The Events, Sessions and Speakers lists move into the Build menu, so the
 * sidebar keeps one Scout section. Anyone who can edit posts sees the
 * overview; only administrators change the settings.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Admin {

	const SLUG = 'scout-events';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_scout_events_settings', array( __CLASS__, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_EVENTS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
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

	public static function url( array $args = array() ): string {
		$base = 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Events', 'scout-events' ), __( 'Events', 'scout-events' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ) );
		if ( 'options-general.php' === self::parent() ) {
			return;
		}
		global $submenu;
		$lists = array(
			Scout_Events::EVENT   => __( 'All events', 'scout-events' ),
			Scout_Events::SESSION => __( 'Sessions', 'scout-events' ),
			Scout_Events::SPEAKER => __( 'Speakers', 'scout-events' ),
		);
		foreach ( $lists as $type => $label ) {
			$list = 'edit.php?post_type=' . $type;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::parent(), $label, $label, 'edit_posts', $list );
		}
	}

	/** Keep the Build menu open while editing an event, session or speaker. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'options-general.php' !== self::parent() && $screen && in_array( $screen->post_type, Scout_Events::post_types(), true ) && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . $screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	/** Events and its lists sit with the other business screens. */
	public static function nav_group( $group, $slug ) {
		$slug = (string) $slug;
		if ( self::SLUG === $slug || false !== strpos( $slug, 'post_type=scout_event' ) || false !== strpos( $slug, 'post_type=scout_session' ) || false !== strpos( $slug, 'post_type=scout_speaker' ) ) {
			return 'business';
		}
		return $group;
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_EVENTS_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-events-admin', SCOUT_EVENTS_URL . 'assets/events-admin.css', array( 'scout-admin' ), SCOUT_EVENTS_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Events', 'scout-events' ) . '</a>' );
		return $links;
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change event settings.', 'scout-events' ), 403 );
		}
		check_admin_referer( 'scout_events_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is cleaned below.
		$in  = isset( $_POST['scout_events'] ) ? (array) wp_unslash( $_POST['scout_events'] ) : array();
		$old = Scout_Events::settings();
		$new = array(
			'event_slug'   => sanitize_title( (string) ( $in['event_slug'] ?? '' ) ),
			'session_slug' => sanitize_title( (string) ( $in['session_slug'] ?? '' ) ),
			'speaker_slug' => sanitize_title( (string) ( $in['speaker_slug'] ?? '' ) ),
			'currency'     => strtoupper( sanitize_text_field( (string) ( $in['currency'] ?? '' ) ) ),
			'schema'       => ! empty( $in['schema'] ) ? 1 : 0,
		);
		foreach ( array( 'event_slug', 'session_slug', 'speaker_slug' ) as $key ) {
			if ( '' === $new[ $key ] ) {
				$new[ $key ] = $old[ $key ];
			}
		}
		// Three types cannot share one address.
		$slugs = array( $new['event_slug'], $new['session_slug'], $new['speaker_slug'] );
		if ( count( array_unique( $slugs ) ) < 3 ) {
			wp_safe_redirect( self::url( array( 'scout-events' => 'sameslug' ) ) );
			exit;
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $new['currency'] ) ) {
			$new['currency'] = $old['currency'];
		}
		update_option( Scout_Events::OPTION, $new );
		wp_safe_redirect( self::url( array( 'scout-events' => 'saved' ) ) );
		exit;
	}

	private static function message(): void {
		$msg   = isset( $_GET['scout-events'] ) ? sanitize_key( wp_unslash( $_GET['scout-events'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'saved'    => array( 'success', __( 'Settings saved. Page addresses update on the next visit.', 'scout-events' ) ),
			'sameslug' => array( 'error', __( 'Events, sessions and speakers each need their own address word. Nothing was saved.', 'scout-events' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$upcoming = Scout_Events::event_ids( 'upcoming' );
		$drafts   = get_posts(
			array(
				'post_type'      => Scout_Events::EVENT,
				'post_status'    => array( 'draft', 'pending', 'future' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
			)
		);
		$s        = Scout_Events::settings();
		$add      = admin_url( 'post-new.php?post_type=' . Scout_Events::EVENT );

		Scout_Admin_UI::open(
			__( 'Your events', 'scout-events' ),
			__( 'Add an event once and its page, schedule, speakers and Google listing stay in step. Edit a date or mark it sold out here, and the site follows.', 'scout-events' ),
			__( 'Events', 'scout-events' )
		);
		self::message();
		?>
		<div class="se-admin">
			<section class="sh-box">
				<div class="se-box-head">
					<h2 class="sh-title"><?php esc_html_e( 'Coming up', 'scout-events' ); ?></h2>
					<a class="button button-primary" href="<?php echo esc_url( $add ); ?>"><?php esc_html_e( 'Add event', 'scout-events' ); ?></a>
				</div>
				<?php self::table( array_merge( $upcoming, array_map( 'intval', $drafts ) ) ); ?>
			</section>

			<div class="se-admin-cols">
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Showing events on the site', 'scout-events' ); ?></h2>
					<p><?php esc_html_e( 'Each event already has its own page with the details, schedule and speakers. Paste these on any page to list them there.', 'scout-events' ); ?></p>
					<table class="sh-table">
						<tr><td>[scout_events]</td><td><?php esc_html_e( 'Upcoming events, soonest first.', 'scout-events' ); ?></td></tr>
						<tr><td>[scout_events show="past" limit="6"]</td><td><?php esc_html_e( 'The six most recent past events.', 'scout-events' ); ?></td></tr>
						<tr><td>[scout_event id="12"]</td><td><?php esc_html_e( 'One event’s details and ticket button. Without an id, the next event.', 'scout-events' ); ?></td></tr>
						<tr><td>[scout_schedule event="12"]</td><td><?php esc_html_e( 'The schedule, day by day. Add layout="grid" for rooms side by side.', 'scout-events' ); ?></td></tr>
						<tr><td>[scout_speakers event="12"]</td><td><?php esc_html_e( 'Speaker cards for one event. Without event, every speaker.', 'scout-events' ); ?></td></tr>
					</table>
					<p class="sh-note"><?php esc_html_e( 'The event id is the number in the address bar when you edit the event (post=12).', 'scout-events' ); ?></p>
				</section>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<section class="sh-box">
						<h2 class="sh-title"><?php esc_html_e( 'Settings', 'scout-events' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-settings">
							<input type="hidden" name="action" value="scout_events_settings">
							<?php wp_nonce_field( 'scout_events_settings' ); ?>
							<p class="se-field">
								<label for="se-set-event"><?php esc_html_e( 'Event page address', 'scout-events' ); ?></label>
								<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-event" name="scout_events[event_slug]" value="<?php echo esc_attr( $s['event_slug'] ); ?>">/name</span>
							</p>
							<p class="se-field">
								<label for="se-set-session"><?php esc_html_e( 'Session page address', 'scout-events' ); ?></label>
								<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-session" name="scout_events[session_slug]" value="<?php echo esc_attr( $s['session_slug'] ); ?>">/name</span>
							</p>
							<p class="se-field">
								<label for="se-set-speaker"><?php esc_html_e( 'Speaker page address', 'scout-events' ); ?></label>
								<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-speaker" name="scout_events[speaker_slug]" value="<?php echo esc_attr( $s['speaker_slug'] ); ?>">/name</span>
							</p>
							<p class="se-field">
								<label for="se-set-currency"><?php esc_html_e( 'Currency code', 'scout-events' ); ?></label>
								<input type="text" id="se-set-currency" name="scout_events[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>" maxlength="3" class="se-short-input">
							</p>
							<p class="se-field">
								<label for="se-set-schema"><input type="checkbox" id="se-set-schema" name="scout_events[schema]" value="1" <?php checked( $s['schema'] ); ?>> <?php esc_html_e( 'Add event schema for Google', 'scout-events' ); ?></label>
								<span class="sh-note"><?php esc_html_e( 'Turn off only if the theme already prints its own event schema.', 'scout-events' ); ?></span>
							</p>
							<p><button type="submit" class="button"><?php esc_html_e( 'Save settings', 'scout-events' ); ?></button></p>
						</form>
					</section>
				<?php endif; ?>
			</div>
		</div>
		<?php
		Scout_Admin_UI::close();
	}

	/** The upcoming and draft events with what each still needs. */
	private static function table( array $ids ): void {
		if ( ! $ids ) {
			echo '<p class="se-empty-admin">' . esc_html__( 'No upcoming events yet. Add the first one and it gets a page of its own.', 'scout-events' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped se-overview">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Event', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'When', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tickets', 'scout-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Still needed', 'scout-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$e      = Scout_Events::event( $id );
					$todo   = array();
					foreach ( Scout_Events::checks( $id ) as $c ) {
						if ( ! $c[1] ) {
							$todo[] = $c[0];
						}
					}
					$status = get_post_status( $id );
					?>
					<tr>
						<td>
							<a class="se-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
							<?php if ( 'publish' !== $status ) : ?>
								<span class="se-chip"><?php echo esc_html( 'future' === $status ? __( 'Scheduled', 'scout-events' ) : __( 'Draft', 'scout-events' ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'scheduled' !== $e['status'] ) : ?>
								<span class="se-chip is-warn"><?php echo esc_html( Scout_Events::statuses()[ $e['status'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Scout_Events::dates_label( $e ) ); ?></td>
						<td>
							<?php echo esc_html( Scout_Events::availabilities()[ $e['availability'] ] ); ?>
							<?php $price = Scout_Events::price_label( $e ); ?>
							<?php if ( '' !== $price ) : ?>
								<br><span class="sh-note"><?php echo esc_html( $price ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $todo ) : ?>
								<?php echo esc_html( implode( ', ', $todo ) ); ?>
							<?php else : ?>
								<span class="se-chip is-done"><?php esc_html_e( 'Ready', 'scout-events' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
