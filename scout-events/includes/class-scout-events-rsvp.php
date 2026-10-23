<?php
/**
 * Sign-ups and check-in, for events that take sign-ups on the site.
 *
 * A visitor signs up on the event page and gets an email with their ticket:
 * a QR code and a link to the ticket page. The QR code holds that same link.
 * At the door, anyone who can edit the site scans it with a phone camera; the
 * link opens the event page with the ticket and a Check in button. Everyone
 * else who opens it sees their own ticket and a way to cancel.
 *
 * When the event is full, new sign-ups join a waitlist; the owner moves people
 * in from the Sign-ups screen. Sign-ups live in their own table, so a large
 * event stays fast. Paid tickets will write into the same table later.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_RSVP {

	const DB_VERSION  = '2';
	const CRON        = 'scout_events_reminders';
	const DB_OPTION   = 'scout_events_db';
	const MIN_SECONDS = 3;

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 5 );
		add_filter( 'scout_events_ticket_html', array( __CLASS__, 'cta' ), 5, 3 );
		// After the event page is assembled (priority 20), so the ticket sits at the very top.
		add_filter( 'the_content', array( __CLASS__, 'ticket_on_page' ), 21 );
		add_action( 'send_headers', array( __CLASS__, 'no_cache' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		foreach ( array( 'scout_events_signup', 'scout_events_cancel' ) as $action ) {
			add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, 'nopriv_' . substr( $action, 13 ) ) );
			add_action( 'admin_post_' . $action, array( __CLASS__, 'nopriv_' . substr( $action, 13 ) ) );
		}
		add_action( 'admin_post_scout_events_checkin', array( __CLASS__, 'checkin_action' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'send_reminders' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/* ---------- the table ---------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_event_signups';
	}

	/** Create or update the table. Runs on activation and after an update that changes it. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				guests tinyint(3) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'going',
				code varchar(40) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				checked_in datetime NULL,
				checked_in_by bigint(20) unsigned NOT NULL DEFAULT 0,
				reminded datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY event_status (event_id,status),
				KEY email (email)
			) $charset;"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ---------- reminders ---------- */

	/** Check every hour for events starting within the next day. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Everyone going to an event that starts in the next 24 hours gets one
	 * reminder with their ticket. Sign-ups made inside that window already
	 * have their ticket, so they are skipped.
	 */
	public static function send_reminders(): void {
		global $wpdb;
		$now    = Scout_Events::now();
		$events = get_posts(
			array(
				'post_type'      => Scout_Events::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an hourly check of a short list.
					array(
						'key'     => '_scout_event_start',
						'value'   => array( $now->format( 'Y-m-d H:i' ), $now->modify( '+24 hours' )->format( 'Y-m-d H:i' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'CHAR',
					),
				),
			)
		);
		$cutoff = $now->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		foreach ( array_map( 'intval', $events ) as $id ) {
			$e = Scout_Events::event( $id );
			if ( empty( $e['signup'] ) || 'cancelled' === $e['status'] ) {
				continue;
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE event_id = %d AND status = 'going' AND reminded IS NULL AND created < %s", $id, $cutoff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $row ) {
				// Mark first, so a slow mail server never sends the same reminder twice.
				$wpdb->update( self::table(), array( 'reminded' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::email( $row, true );
			}
		}
	}

	/* ---------- reading ---------- */

	/** @return array<string,string> status => label */
	public static function statuses(): array {
		return array(
			'going'     => __( 'Going', 'scout-events' ),
			'waitlist'  => __( 'Waitlist', 'scout-events' ),
			'cancelled' => __( 'Cancelled', 'scout-events' ),
		);
	}

	public static function find( string $code ): ?array {
		global $wpdb;
		if ( '' === $code || ! preg_match( '/^[A-Za-z0-9]{12,40}$/', $code ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- own table, name built from the prefix.
		return $row ? $row : null;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * Everyone signed up for an event, waitlist and cancellations included,
	 * in the order they signed up.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function for_event( int $event_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE event_id = %d ORDER BY created ASC, id ASC', $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/** The live sign-up for this email at this event, if any. */
	public static function for_email( int $event_id, string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE event_id = %d AND email = %s AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", $event_id, strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * Headcounts for an event: people going (guests included), how many have
	 * checked in, and people waiting.
	 *
	 * @return array{going:int,signups:int,checked_in:int,waitlist:int}
	 */
	public static function counts( int $event_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT'
				. " COALESCE(SUM(CASE WHEN status = 'going' THEN 1 + guests ELSE 0 END), 0) AS going,"
				. " COALESCE(SUM(CASE WHEN status = 'going' THEN 1 ELSE 0 END), 0) AS signups,"
				. " COALESCE(SUM(CASE WHEN status = 'going' AND checked_in IS NOT NULL THEN 1 + guests ELSE 0 END), 0) AS checked_in,"
				. " COALESCE(SUM(CASE WHEN status = 'waitlist' THEN 1 + guests ELSE 0 END), 0) AS waitlist"
				. ' FROM ' . self::table() . ' WHERE event_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			),
			ARRAY_A
		);
		return array_map( 'intval', is_array( $row ) ? $row : array( 'going' => 0, 'signups' => 0, 'checked_in' => 0, 'waitlist' => 0 ) );
	}

	/** Places left, or null when the event has no capacity set. */
	public static function spots_left( int $event_id, ?array $e = null ): ?int {
		$e = $e ?? Scout_Events::event( $event_id );
		if ( $e['capacity'] <= 0 ) {
			return null;
		}
		return max( 0, $e['capacity'] - self::counts( $event_id )['going'] );
	}

	/** Can people sign up for this event right now? */
	public static function open( int $event_id, array $e ): bool {
		if ( empty( $e['signup'] ) || 'cancelled' === $e['status'] || Scout_Events::is_past( $e ) || in_array( $e['availability'], array( 'soldout', 'notyet' ), true ) ) {
			return false;
		}
		$today = Scout_Events::now()->format( 'Y-m-d' );
		if ( ( '' !== $e['sales_start'] && $e['sales_start'] > $today ) || ( '' !== $e['sales_end'] && $e['sales_end'] < $today ) ) {
			return false;
		}
		/** Scout Members closes sign-ups to non-members on members-only events. */
		return (bool) apply_filters( 'scout_events_signup_allowed', true, $event_id );
	}

	/** The ticket page: the event page with the code on it. */
	public static function ticket_url( array $row ): string {
		return add_query_arg( 'se_ticket', $row['code'], (string) get_permalink( (int) $row['event_id'] ) );
	}

	/* ---------- writing ---------- */

	/**
	 * Add a sign-up. Going while there is room for them and their guests,
	 * waitlisted after that.
	 */
	public static function add( int $event_id, string $name, string $email, int $guests, int $user_id = 0 ): ?array {
		global $wpdb;
		$e      = Scout_Events::event( $event_id );
		$guests = min( (int) $e['signup_guests'], max( 0, $guests ) );
		$left   = self::spots_left( $event_id, $e );
		$status = null === $left || $left >= 1 + $guests ? 'going' : 'waitlist';
		$code   = wp_generate_password( 24, false, false );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'event_id' => $event_id,
				'user_id'  => $user_id,
				'name'     => mb_substr( $name, 0, 190 ),
				'email'    => strtolower( mb_substr( $email, 0, 190 ) ),
				'guests'   => $guests,
				'status'   => $status,
				'code'     => $code,
				'created'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $ok ? self::find( $code ) : null;
	}

	public static function set_status( int $id, string $status ): void {
		global $wpdb;
		if ( isset( self::statuses()[ $status ] ) ) {
			$wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Check someone in, or undo it. */
	public static function check_in( int $id, bool $in = true ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'checked_in'    => $in ? current_time( 'mysql' ) : null,
				'checked_in_by' => $in ? get_current_user_id() : 0,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/* ---------- QR codes ---------- */

	/** The QR code's dark squares, as rows of booleans. Empty when the text is too long. */
	private static function matrix( string $text ): array {
		require_once SCOUT_EVENTS_DIR . 'lib/qrcode.php';
		$qr = \Scout_Events_QR\QRCode::getMinimumQRCode( $text, QR_ERROR_CORRECT_LEVEL_M );
		$n  = $qr->getModuleCount();
		$m  = array();
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				$m[ $r ][ $c ] = (bool) $qr->isDark( $r, $c );
			}
		}
		return $m;
	}

	/** A crisp SVG of the QR code, for the ticket page. */
	public static function qr_svg( string $text, string $label ): string {
		$m = self::matrix( $text );
		$n = count( $m );
		if ( ! $n ) {
			return '';
		}
		$q    = 4;
		$size = $n + 2 * $q;
		$path = '';
		foreach ( $m as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $c + $q ) . ' ' . ( $r + $q ) . 'h1v1h-1z';
				}
			}
		}
		return '<svg class="se-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . esc_attr( $label ) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . esc_attr( $path ) . '"/></svg>';
	}

	/** A PNG of the QR code in a temporary file, for the email. Empty when the server has no image library. */
	private static function qr_png_file( string $text ): string {
		if ( ! function_exists( 'imagecreate' ) ) {
			return '';
		}
		$m = self::matrix( $text );
		$n = count( $m );
		if ( ! $n ) {
			return '';
		}
		$q    = 4;
		$s    = 8;
		$size = ( $n + 2 * $q ) * $s;
		$im   = imagecreate( $size, $size );
		imagecolorallocate( $im, 255, 255, 255 );
		$black = imagecolorallocate( $im, 0, 0, 0 );
		foreach ( $m as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					imagefilledrectangle( $im, ( $c + $q ) * $s, ( $r + $q ) * $s, ( $c + $q + 1 ) * $s - 1, ( $r + $q + 1 ) * $s - 1, $black );
				}
			}
		}
		$file = trailingslashit( get_temp_dir() ) . 'ticket-' . wp_generate_password( 8, false, false ) . '.png';
		imagepng( $im, $file );
		imagedestroy( $im );
		return file_exists( $file ) ? $file : '';
	}

	/* ---------- the sign-up form, in place of the ticket button ---------- */

	public static function cta( $html, $id, $e ): string {
		$id = (int) $id;
		$e  = (array) $e;
		if ( empty( $e['signup'] ) ) {
			return (string) $html;
		}
		// Someone looking at their own ticket does not need the form again.
		$mine = is_singular( Scout_Events::EVENT ) ? self::current_ticket() : null;
		if ( $mine && 'cancelled' !== $mine['status'] ) {
			return '<p class="se-cta"><a href="#se-ticket">' . esc_html( 'waitlist' === $mine['status'] ? __( 'You are on the waitlist. See your place above.', 'scout-events' ) : __( 'You are signed up. Your ticket is above.', 'scout-events' ) ) . '</a></p>';
		}
		if ( ! self::open( $id, $e ) ) {
			// Closed sign-ups fall back to whatever the event says (sold out, not yet, nothing).
			return (string) $html;
		}
		return self::form( $id, $e );
	}

	private static function form( int $id, array $e ): string {
		$user  = wp_get_current_user();
		$left  = self::spots_left( $id, $e );
		$full  = null !== $left && $left < 1;
		$out   = '<div class="se-signup" id="se-signup">' . self::notice();
		if ( null !== $left && ! $full && $left <= 20 ) {
			/* translators: %d: places left. */
			$out .= '<p class="se-spots">' . esc_html( sprintf( _n( '%d place left.', '%d places left.', $left, 'scout-events' ), $left ) ) . '</p>';
		} elseif ( $full ) {
			$out .= '<p class="se-spots">' . esc_html__( 'This event is full. Sign up below to join the waitlist.', 'scout-events' ) . '</p>';
		}
		$out .= '<form class="se-signup-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="scout_events_signup">'
			. '<input type="hidden" name="se_event" value="' . (int) $id . '">'
			. wp_nonce_field( 'scout_events_signup_' . $id, '_se_signup', true, false )
			. '<input type="hidden" name="se_t" value="' . esc_attr( (string) time() ) . '">'
			. '<p class="se-p-hp" aria-hidden="true"><label for="se-s-website2">' . esc_html__( 'Leave this empty', 'scout-events' ) . '</label><input type="text" id="se-s-website2" name="se_website2" tabindex="-1" autocomplete="off"></p>'
			. '<p class="se-s-field"><label for="se-s-name">' . esc_html__( 'Your name', 'scout-events' ) . '</label><input type="text" id="se-s-name" name="se_name" autocomplete="name" required value="' . esc_attr( $user->exists() ? $user->display_name : '' ) . '"></p>'
			. '<p class="se-s-field"><label for="se-s-email">' . esc_html__( 'Email, for your ticket', 'scout-events' ) . '</label><input type="email" id="se-s-email" name="se_email" autocomplete="email" required value="' . esc_attr( $user->exists() ? $user->user_email : '' ) . '"></p>';
		if ( $e['signup_guests'] > 0 ) {
			$out .= '<p class="se-s-field"><label for="se-s-guests">' . esc_html__( 'Guests coming with you', 'scout-events' ) . '</label><select id="se-s-guests" name="se_guests">';
			for ( $g = 0; $g <= (int) $e['signup_guests']; $g++ ) {
				$out .= '<option value="' . (int) $g . '">' . (int) $g . '</option>';
			}
			$out .= '</select></p>';
		}
		$label = $full ? __( 'Join the waitlist', 'scout-events' ) : ( '' !== $e['ticket_label'] ? $e['ticket_label'] : __( 'Save my spot', 'scout-events' ) );
		$out  .= '<p class="se-s-submit"><button type="submit" class="se-button wp-element-button" data-scout-track="signup_submit" data-scout-event="' . esc_attr( get_the_title( $id ) ) . '">' . esc_html( $label ) . '</button></p>'
			. '<p class="se-s-help">' . esc_html__( 'Your ticket and its QR code come by email. Show it at the door.', 'scout-events' ) . '</p>'
			. '</form></div>';
		return $out;
	}

	private static function notice(): string {
		$msg   = isset( $_GET['signup'] ) ? sanitize_key( wp_unslash( $_GET['signup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'missing'  => __( 'Please add your name and a working email address.', 'scout-events' ),
			'tooquick' => __( 'That was very fast. Please check your details and send it again.', 'scout-events' ),
			'closed'   => __( 'Sign-ups for this event are closed.', 'scout-events' ),
		);
		return isset( $texts[ $msg ] ) ? '<p class="se-s-note is-bad" role="status">' . esc_html( $texts[ $msg ] ) . '</p>' : '';
	}

	private static function back_to( string $url, string $msg ): void {
		wp_safe_redirect( add_query_arg( 'signup', $msg, $url ) . '#se-signup' );
		exit;
	}

	/** admin-post: scout_events_signup. */
	public static function nopriv_signup(): void {
		$event_id = isset( $_POST['se_event'] ) ? absint( $_POST['se_event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$nonce    = isset( $_POST['_se_signup'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_signup'] ) ) : '';
		if ( ! $event_id || Scout_Events::EVENT !== get_post_type( $event_id ) || ! wp_verify_nonce( $nonce, 'scout_events_signup_' . $event_id ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and send it again.', 'scout-events' ), 400 );
		}
		$page = remove_query_arg( array( 'signup', 'se_ticket' ), (string) get_permalink( $event_id ) );
		if ( ! empty( $_POST['se_website2'] ) ) {
			wp_safe_redirect( $page );
			exit;
		}
		$t = isset( $_POST['se_t'] ) ? (int) $_POST['se_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SECONDS ) {
			self::back_to( $page, 'tooquick' );
		}
		$e = Scout_Events::event( $event_id );
		if ( ! self::open( $event_id, $e ) ) {
			self::back_to( $page, 'closed' );
		}
		$name   = sanitize_text_field( wp_unslash( $_POST['se_name'] ?? '' ) );
		$email  = sanitize_email( wp_unslash( $_POST['se_email'] ?? '' ) );
		$guests = isset( $_POST['se_guests'] ) ? absint( $_POST['se_guests'] ) : 0;
		if ( '' === $name || ! is_email( $email ) ) {
			self::back_to( $page, 'missing' );
		}
		// The same person signing up twice gets their ticket again, not a second place.
		$row = self::for_email( $event_id, $email );
		if ( ! $row ) {
			$row = self::add( $event_id, $name, $email, $guests, get_current_user_id() );
		}
		if ( ! $row ) {
			self::back_to( $page, 'missing' );
		}
		self::email( $row );
		wp_safe_redirect( add_query_arg( 'se_new', 1, self::ticket_url( $row ) ) . '#se-ticket' );
		exit;
	}

	/** admin-post: scout_events_cancel. The ticket code is the key. */
	public static function nopriv_cancel(): void {
		$code  = isset( $_POST['se_code'] ) ? sanitize_text_field( wp_unslash( $_POST['se_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$nonce = isset( $_POST['_se_cancel'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_cancel'] ) ) : '';
		$row   = self::find( $code );
		if ( ! $row || ! wp_verify_nonce( $nonce, 'scout_events_cancel_' . $code ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and try again.', 'scout-events' ), 400 );
		}
		self::set_status( (int) $row['id'], 'cancelled' );
		do_action( 'scout_events_signup_cancelled', $row );
		wp_safe_redirect( self::ticket_url( $row ) . '#se-ticket' );
		exit;
	}

	/** admin-post: scout_events_checkin. Door staff only. */
	public static function checkin_action(): void {
		$code = isset( $_POST['se_code'] ) ? sanitize_text_field( wp_unslash( $_POST['se_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_events_checkin_' . $code );
		$row = self::find( $code );
		if ( ! $row || ! current_user_can( 'edit_post', (int) $row['event_id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to check people in.', 'scout-events' ), 403 );
		}
		$in = empty( $_POST['undo'] );
		self::check_in( (int) $row['id'], $in );
		$back = isset( $_POST['se_back'] ) ? esc_url_raw( wp_unslash( $_POST['se_back'] ) ) : '';
		wp_safe_redirect( '' !== $back ? $back : self::ticket_url( $row ) . '#se-ticket' );
		exit;
	}

	/* ---------- the ticket on the event page ---------- */

	private static function current_ticket(): ?array {
		$code = isset( $_GET['se_ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['se_ticket'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the code itself is the key.
		$row  = '' !== $code ? self::find( $code ) : null;
		return $row && (int) $row['event_id'] === (int) get_queried_object_id() ? $row : null;
	}

	/** Ticket pages are personal: never cached, never indexed. */
	public static function no_cache(): void {
		if ( isset( $_GET['se_ticket'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function robots( array $robots ): array {
		if ( isset( $_GET['se_ticket'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$robots['noindex'] = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function ticket_on_page( $content ) {
		if ( ! is_singular( Scout_Events::EVENT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$row = self::current_ticket();
		if ( ! $row ) {
			return $content;
		}
		return self::ticket( $row ) . $content;
	}

	public static function ticket( array $row ): string {
		wp_enqueue_style( 'scout-events' );
		$id     = (int) $row['event_id'];
		$e      = Scout_Events::event( $id );
		$staff  = current_user_can( 'edit_post', $id );
		$in     = ! empty( $row['checked_in'] );
		$status = $row['status'];
		$who    = $row['name'] . ( (int) $row['guests'] > 0 ? ' + ' . sprintf( /* translators: %d: number of guests. */ _n( '%d guest', '%d guests', (int) $row['guests'], 'scout-events' ), (int) $row['guests'] ) : '' );
		$titles = array(
			'going'     => $in ? __( 'Checked in', 'scout-events' ) : __( 'You are going', 'scout-events' ),
			'waitlist'  => __( 'You are on the waitlist', 'scout-events' ),
			'cancelled' => __( 'This sign-up is cancelled', 'scout-events' ),
		);
		$out = '<section class="se-ticket is-' . esc_attr( $in ? 'in' : $status ) . '" id="se-ticket" aria-labelledby="se-ticket-title">';
		$out .= '<p class="se-ticket-kicker">' . esc_html( $staff ? __( 'Door check-in', 'scout-events' ) : __( 'Your ticket', 'scout-events' ) ) . '</p>';
		$out .= '<h2 class="se-ticket-title" id="se-ticket-title">' . esc_html( $titles[ $status ] ?? '' ) . '</h2>';
		$out .= '<p class="se-ticket-who">' . esc_html( $who ) . '</p>';
		$when = trim( Scout_Events::dates_label( $e ) . ' · ' . Scout_Events::times_label( $e ), ' ·' );
		$out .= '<p class="se-ticket-when">' . esc_html( get_the_title( $id ) . ( '' !== $when ? ' · ' . $when : '' ) ) . '</p>';

		if ( $staff ) {
			$out .= self::staff_controls( $row );
		} elseif ( 'going' === $status ) {
			if ( isset( $_GET['se_new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				$out .= '<p class="se-ticket-note">' . esc_html__( 'Done. Your ticket is also on its way by email. Show this code at the door.', 'scout-events' ) . '</p>';
			}
			$out .= '<div class="se-ticket-qr">' . self::qr_svg( self::ticket_url( $row ), __( 'Ticket QR code', 'scout-events' ) ) . '</div>';
			if ( $in ) {
				/* translators: %s: time. */
				$out .= '<p class="se-ticket-note">' . esc_html( sprintf( __( 'Checked in at %s.', 'scout-events' ), mysql2date( (string) get_option( 'time_format' ), (string) $row['checked_in'] ) ) ) . '</p>';
			}
		} elseif ( 'waitlist' === $status ) {
			$out .= '<p class="se-ticket-note">' . esc_html__( 'The event is full right now. If a place opens up, we will email you a ticket.', 'scout-events' ) . '</p>';
		}

		if ( ! $staff && 'cancelled' !== $status && ! $in && ! Scout_Events::is_past( $e ) ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="se-ticket-cancel">'
				. '<input type="hidden" name="action" value="scout_events_cancel">'
				. '<input type="hidden" name="se_code" value="' . esc_attr( $row['code'] ) . '">'
				. wp_nonce_field( 'scout_events_cancel_' . $row['code'], '_se_cancel', false, false )
				. '<button type="submit" class="se-linkbutton">' . esc_html__( 'I can no longer come', 'scout-events' ) . '</button></form>';
		}
		if ( 'cancelled' === $status && self::open( $id, $e ) ) {
			$out .= '<p class="se-ticket-note"><a href="' . esc_url( (string) get_permalink( $id ) . '#se-signup' ) . '">' . esc_html__( 'Sign up again', 'scout-events' ) . '</a></p>';
		}
		if ( ! is_user_logged_in() ) {
			$out .= '<p class="se-ticket-staff"><a href="' . esc_url( wp_login_url( self::ticket_url( $row ) ) ) . '">' . esc_html__( 'Door staff: sign in to check this ticket in', 'scout-events' ) . '</a></p>';
		}
		return $out . '</section>';
	}

	/** The one big button at the door. */
	private static function staff_controls( array $row ): string {
		$in   = ! empty( $row['checked_in'] );
		$out  = '';
		if ( 'going' !== $row['status'] ) {
			$out .= '<p class="se-ticket-warn">' . esc_html( 'waitlist' === $row['status'] ? __( 'Not checked in: this person is on the waitlist. Move them in from Sign-ups first.', 'scout-events' ) : __( 'Not checked in: this sign-up was cancelled.', 'scout-events' ) ) . '</p>';
		} elseif ( $in ) {
			/* translators: %s: time. */
			$out .= '<p class="se-ticket-ok">' . esc_html( sprintf( __( 'Checked in at %s.', 'scout-events' ), mysql2date( (string) get_option( 'time_format' ), (string) $row['checked_in'] ) ) ) . '</p>';
		}
		if ( 'going' === $row['status'] ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="se-ticket-door">'
				. '<input type="hidden" name="action" value="scout_events_checkin">'
				. '<input type="hidden" name="se_code" value="' . esc_attr( $row['code'] ) . '">'
				. wp_nonce_field( 'scout_events_checkin_' . $row['code'], '_wpnonce', false, false )
				. ( $in
					? '<button type="submit" name="undo" value="1" class="se-linkbutton">' . esc_html__( 'Undo check-in', 'scout-events' ) . '</button>'
					: '<button type="submit" class="se-button se-checkin wp-element-button">' . esc_html__( 'Check in', 'scout-events' ) . '</button>' )
				. '</form>';
		}
		$c    = self::counts( (int) $row['event_id'] );
		/* translators: 1: people checked in, 2: people going. */
		$out .= '<p class="se-ticket-count">' . esc_html( sprintf( __( '%1$d of %2$d people checked in', 'scout-events' ), $c['checked_in'], $c['going'] ) ) . ' · <a href="' . esc_url( Scout_Events_Admin::signups_url( (int) $row['event_id'] ) ) . '">' . esc_html__( 'Door list', 'scout-events' ) . '</a></p>';
		return $out;
	}

	/* ---------- email ---------- */

	/** The ticket email, the waitlist one, or (with $reminder) the day-before reminder. */
	public static function email( array $row, bool $reminder = false ): void {
		$id    = (int) $row['event_id'];
		$e     = Scout_Events::event( $id );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title = wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES );
		$when  = trim( Scout_Events::dates_label( $e ) . "\n" . Scout_Events::times_label( $e ) );
		$where = trim( $e['venue'] . "\n" . Scout_Events::address_label( $e ) );
		$files = array();
		if ( 'waitlist' === $row['status'] ) {
			/* translators: %s: event name. */
			$subject = sprintf( __( 'You are on the waitlist for %s', 'scout-events' ), $title );
			$body    = sprintf( /* translators: %s: name. */ __( 'Hi %s,', 'scout-events' ), $row['name'] ) . "\n\n"
				. __( 'The event is full right now, so you are on the waitlist. If a place opens up, we will email you a ticket.', 'scout-events' ) . "\n\n"
				. __( 'Your place in line:', 'scout-events' ) . "\n" . self::ticket_url( $row ) . "\n";
		} else {
			$subject = $reminder
				/* translators: %s: event name. */
				? sprintf( __( 'See you soon: %s', 'scout-events' ), $title )
				/* translators: %s: event name. */
				: sprintf( __( 'Your ticket for %s', 'scout-events' ), $title );
			$body    = sprintf( /* translators: %s: name. */ __( 'Hi %s,', 'scout-events' ), $row['name'] ) . "\n\n"
				. ( $reminder
					? __( 'A reminder that you are signed up, and we look forward to seeing you. Show the QR code at the door, from this email or from your ticket page:', 'scout-events' )
					: __( 'You are going. Show the QR code at the door, from this email or from your ticket page:', 'scout-events' ) ) . "\n"
				. self::ticket_url( $row ) . "\n\n"
				. $title . "\n" . ( '' !== $when ? $when . "\n" : '' ) . ( '' !== $where ? $where . "\n" : '' );
			if ( (int) $row['guests'] > 0 ) {
				/* translators: %d: number of guests. */
				$body .= "\n" . sprintf( _n( 'This ticket covers you and %d guest.', 'This ticket covers you and %d guests.', (int) $row['guests'], 'scout-events' ), (int) $row['guests'] ) . "\n";
			}
			$body .= "\n" . __( 'Plans changed? Open your ticket page and choose "I can no longer come", so someone else can have your place.', 'scout-events' ) . "\n";
			$png   = self::qr_png_file( self::ticket_url( $row ) );
			if ( '' !== $png ) {
				$files[] = $png;
			}
			if ( ! $reminder ) {
				$ics = Scout_Events_Calendar::ics_file( $id );
				if ( '' !== $ics ) {
					$files[] = $ics;
				}
			}
		}
		$body .= "\n" . $site . "\n" . home_url( '/' );
		wp_mail( $row['email'], $subject, $body, array(), $files );
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
	}

	/* ---------- privacy ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['scout-events-signups'] = array(
			'exporter_friendly_name' => __( 'Event sign-ups', 'scout-events' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'scout-events-signups',
				'group_label' => __( 'Event sign-ups', 'scout-events' ),
				'item_id'     => 'signup-' . $r['id'],
				'data'        => array(
					array(
						'name'  => __( 'Event', 'scout-events' ),
						'value' => get_the_title( (int) $r['event_id'] ),
					),
					array(
						'name'  => __( 'Name', 'scout-events' ),
						'value' => $r['name'],
					),
					array(
						'name'  => __( 'Guests', 'scout-events' ),
						'value' => $r['guests'],
					),
					array(
						'name'  => __( 'Status', 'scout-events' ),
						'value' => self::statuses()[ $r['status'] ] ?? $r['status'],
					),
					array(
						'name'  => __( 'Signed up', 'scout-events' ),
						'value' => $r['created'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['scout-events-signups'] = array(
			'eraser_friendly_name' => __( 'Event sign-ups', 'scout-events' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( string $email ): array {
		global $wpdb;
		$n = (int) $wpdb->delete( self::table(), array( 'email' => strtolower( $email ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
