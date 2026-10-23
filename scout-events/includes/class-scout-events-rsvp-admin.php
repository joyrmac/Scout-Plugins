<?php
/**
 * The Sign-ups screen: one event's door list. Headcounts at the top, then
 * everyone signed up with a Check in button for people without their phone,
 * the waitlist with Move in, and a spreadsheet download. Built to work on a
 * phone at the door as well as on a laptop.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_RSVP_Admin {

	public static function boot(): void {
		add_action( 'admin_post_scout_events_signup_admin', array( __CLASS__, 'act' ) );
		add_action( 'admin_post_scout_events_signups_csv', array( __CLASS__, 'csv' ) );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
	}

	/** Events that take sign-ups, upcoming first, then past. */
	public static function events(): array {
		$ids = get_posts(
			array(
				'post_type'      => Scout_Events::EVENT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_scout_event_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		$ids      = array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => ! empty( Scout_Events::event( $id )['signup'] ) ) );
		$upcoming = array_values( array_filter( $ids, static fn( $id ) => ! Scout_Events::is_past( Scout_Events::event( $id ) ) ) );
		$past     = array_reverse( array_values( array_diff( $ids, $upcoming ) ) );
		return array_merge( $upcoming, $past );
	}

	/** Waitlisted people while there is room again. */
	public static function needs_you( $items ): array {
		$items = (array) $items;
		foreach ( self::events() as $id ) {
			$e = Scout_Events::event( $id );
			if ( Scout_Events::is_past( $e ) ) {
				continue;
			}
			$c    = Scout_Events_RSVP::counts( $id );
			$left = Scout_Events_RSVP::spots_left( $id, $e );
			if ( $c['waitlist'] > 0 && ( null === $left || $left > 0 ) ) {
				$items[] = array(
					/* translators: 1: number of people, 2: event name. */
					'text'   => sprintf( _n( '%1$d person is on the waitlist for %2$s, and a place is open.', '%1$d people are on the waitlist for %2$s, and places are open.', $c['waitlist'], 'scout-events' ), $c['waitlist'], get_the_title( $id ) ),
					'url'    => Scout_Events_Admin::signups_url( $id ),
					'action' => __( 'Move people in', 'scout-events' ),
				);
			}
		}
		return $items;
	}

	/** Check in, undo, move in from the waitlist, or cancel, from the door list. */
	public static function act(): void {
		$id = isset( $_POST['signup'] ) ? absint( $_POST['signup'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_events_signup_admin_' . $id );
		$row = Scout_Events_RSVP::get( $id );
		if ( ! $row || ! current_user_can( 'edit_post', (int) $row['event_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to change sign-ups.', 'scout-events' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'in' === $op || 'undo' === $op ) {
			Scout_Events_RSVP::check_in( $id, 'in' === $op );
		} elseif ( 'move' === $op && 'waitlist' === $row['status'] ) {
			Scout_Events_RSVP::set_status( $id, 'going' );
			Scout_Events_RSVP::email( Scout_Events_RSVP::get( $id ) );
		} elseif ( 'cancel' === $op ) {
			Scout_Events_RSVP::set_status( $id, 'cancelled' );
		} elseif ( 'restore' === $op && 'cancelled' === $row['status'] ) {
			Scout_Events_RSVP::set_status( $id, 'going' );
		}
		$ref = wp_get_referer();
		wp_safe_redirect( ( $ref ? preg_replace( '/#.*$/', '', $ref ) : Scout_Events_Admin::signups_url( (int) $row['event_id'] ) ) . '#se-s-' . $id );
		exit;
	}

	/** Everyone signed up for one event, as a spreadsheet. */
	public static function csv(): void {
		$event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'scout_events_signups_csv_' . $event );
		if ( ! $event || ! current_user_can( 'edit_post', $event ) ) {
			wp_die( esc_html__( 'You do not have permission to download sign-ups.', 'scout-events' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_post_field( 'post_name', $event ) . '-signups.csv' ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		fputcsv( $out, array( 'Name', 'Email', 'Guests', 'Status', 'Signed up', 'Checked in' ), ',', '"', '\\' );
		foreach ( Scout_Events_RSVP::for_event( $event ) as $r ) {
			// A leading = + - @ would run as a formula in a spreadsheet.
			$safe = static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v;
			fputcsv( $out, array( $safe( $r['name'] ), $safe( $r['email'] ), $r['guests'], Scout_Events_RSVP::statuses()[ $r['status'] ] ?? $r['status'], $r['created'], (string) $r['checked_in'] ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------- the screen ---------- */

	public static function view(): void {
		$events = self::events();
		if ( ! $events ) {
			?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Sign-ups', 'scout-events' ); ?></h2>
				<p><?php esc_html_e( 'No event takes sign-ups yet. Open an event, tick "Take sign-ups on this site" under Tickets, and save. People then sign up on the event page and get a ticket with a QR code.', 'scout-events' ); ?></p>
			</section>
			<?php
			return;
		}
		$event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a view.
		$event = in_array( $event, $events, true ) ? $event : $events[0];
		$e     = Scout_Events::event( $event );
		$c     = Scout_Events_RSVP::counts( $event );
		$left  = Scout_Events_RSVP::spots_left( $event, $e );
		$rows  = Scout_Events_RSVP::for_event( $event );
		$find  = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a search box.
		if ( '' !== $find ) {
			$rows = array_values( array_filter( $rows, static fn( $r ) => false !== stripos( $r['name'] . ' ' . $r['email'], $find ) ) );
		}
		$going   = array_values( array_filter( $rows, static fn( $r ) => 'going' === $r['status'] ) );
		$waiting = array_values( array_filter( $rows, static fn( $r ) => 'waitlist' === $r['status'] ) );
		$gone    = array_values( array_filter( $rows, static fn( $r ) => 'cancelled' === $r['status'] ) );
		?>
		<section class="sh-box se-door">
			<form method="get" class="se-door-pick">
				<input type="hidden" name="page" value="<?php echo esc_attr( Scout_Events_Admin::SLUG . '-signups' ); ?>">
				<label for="se-door-event" class="screen-reader-text"><?php esc_html_e( 'Event', 'scout-events' ); ?></label>
				<select id="se-door-event" name="event" onchange="this.form.submit()">
					<?php foreach ( $events as $id ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $event, $id ); ?>><?php $sd = Scout_Events::start( Scout_Events::event( $id ) ); ?><?php echo esc_html( get_the_title( $id ) . ( $sd ? ' · ' . wp_date( 'M j, Y', $sd->getTimestamp() ) : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'scout-events' ); ?></button></noscript>
			</form>
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) $c['going']; ?></p><p class="sh-note"><?php esc_html_e( 'people going', 'scout-events' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) $c['checked_in']; ?></p><p class="sh-note"><?php esc_html_e( 'checked in', 'scout-events' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo null === $left ? '&ndash;' : (int) $left; ?></p><p class="sh-note"><?php echo esc_html( null === $left ? __( 'no capacity set', 'scout-events' ) : __( 'places left', 'scout-events' ) ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) $c['waitlist']; ?></p><p class="sh-note"><?php esc_html_e( 'on the waitlist', 'scout-events' ); ?></p></div>
			</div>
			<div class="se-door-tools">
				<form method="get" class="se-door-find" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( Scout_Events_Admin::SLUG . '-signups' ); ?>">
					<input type="hidden" name="event" value="<?php echo (int) $event; ?>">
					<label for="se-door-find" class="screen-reader-text"><?php esc_html_e( 'Find a name or email', 'scout-events' ); ?></label>
					<input type="search" id="se-door-find" name="find" value="<?php echo esc_attr( $find ); ?>" placeholder="<?php esc_attr_e( 'Find a name or email', 'scout-events' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Find', 'scout-events' ); ?></button>
				</form>
				<span class="se-actions">
					<a class="button" href="<?php echo esc_url( (string) get_permalink( $event ) ); ?>"><?php esc_html_e( 'Event page', 'scout-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scout_events_signups_csv&event=' . $event ), 'scout_events_signups_csv_' . $event ) ); ?>"><?php esc_html_e( 'Download list', 'scout-events' ); ?></a>
				</span>
			</div>
			<p class="sh-note"><?php esc_html_e( 'At the door, scan each ticket with your phone camera while signed in here, and tap Check in. Use this list for anyone without their ticket.', 'scout-events' ); ?></p>
		</section>

		<?php
		self::block( __( 'Going', 'scout-events' ), $going, '' !== $find ? __( 'No one going matches that search.', 'scout-events' ) : __( 'No sign-ups yet.', 'scout-events' ) );
		if ( $waiting ) {
			self::block( __( 'Waitlist', 'scout-events' ), $waiting, '' );
		}
		if ( $gone ) {
			self::block( __( 'Cancelled', 'scout-events' ), $gone, '', true );
		}
	}

	private static function block( string $title, array $rows, string $empty, bool $closed = false ): void {
		?>
		<section class="sh-box">
			<?php if ( $closed ) : ?>
				<details><summary class="sh-title"><?php echo esc_html( $title . ' (' . count( $rows ) . ')' ); ?></summary>
			<?php else : ?>
				<h2 class="sh-title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<?php if ( ! $rows ) : ?>
				<p class="se-empty-admin"><?php echo esc_html( $empty ); ?></p>
			<?php else : ?>
				<ul class="se-door-list">
					<?php foreach ( $rows as $r ) : ?>
						<?php self::row( $r ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $closed ) : ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function row( array $r ): void {
		$in = ! empty( $r['checked_in'] );
		?>
		<li class="se-door-row<?php echo $in ? ' is-in' : ''; ?>" id="se-s-<?php echo (int) $r['id']; ?>">
			<div class="se-door-who">
				<strong><?php echo esc_html( $r['name'] ); ?></strong>
				<?php if ( (int) $r['guests'] > 0 ) : ?>
					<?php /* translators: %d: number of guests. */ ?>
					<span class="se-chip"><?php echo esc_html( sprintf( _n( '+%d guest', '+%d guests', (int) $r['guests'], 'scout-events' ), (int) $r['guests'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( $in ) : ?>
					<?php /* translators: %s: time. */ ?>
					<span class="se-chip is-done"><?php echo esc_html( sprintf( __( 'In at %s', 'scout-events' ), mysql2date( (string) get_option( 'time_format' ), (string) $r['checked_in'] ) ) ); ?></span>
				<?php endif; ?>
				<br><span class="sh-note"><?php echo esc_html( $r['email'] ); ?></span>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-door-act">
				<input type="hidden" name="action" value="scout_events_signup_admin">
				<input type="hidden" name="signup" value="<?php echo (int) $r['id']; ?>">
				<?php wp_nonce_field( 'scout_events_signup_admin_' . $r['id'] ); ?>
				<?php if ( 'going' === $r['status'] ) : ?>
					<?php if ( $in ) : ?>
						<button type="submit" name="op" value="undo" class="button"><?php esc_html_e( 'Undo', 'scout-events' ); ?></button>
					<?php else : ?>
						<button type="submit" name="op" value="in" class="button button-primary"><?php esc_html_e( 'Check in', 'scout-events' ); ?></button>
						<button type="submit" name="op" value="cancel" class="button se-quiet"><?php esc_html_e( 'Cancel', 'scout-events' ); ?></button>
					<?php endif; ?>
				<?php elseif ( 'waitlist' === $r['status'] ) : ?>
					<button type="submit" name="op" value="move" class="button button-primary"><?php esc_html_e( 'Move in and email ticket', 'scout-events' ); ?></button>
					<button type="submit" name="op" value="cancel" class="button se-quiet"><?php esc_html_e( 'Remove', 'scout-events' ); ?></button>
				<?php else : ?>
					<button type="submit" name="op" value="restore" class="button"><?php esc_html_e( 'Put back', 'scout-events' ); ?></button>
				<?php endif; ?>
			</form>
		</li>
		<?php
	}
}
