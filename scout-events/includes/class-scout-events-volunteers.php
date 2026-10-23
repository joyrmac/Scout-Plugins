<?php
/**
 * Volunteer shifts. The owner lists the shifts an event needs (what the job
 * is, when, how many people); volunteers pick shifts on the site; the owner
 * sees the roster and the gaps. Volunteers get a confirmation, a link to see
 * or drop their shifts, and a reminder the day before.
 *
 *   [scout_volunteer event="summit-2027"]   the shifts and the sign-up form
 *
 * Shifts live on the event; volunteers in their own table, so a large event
 * with many shifts stays fast.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Volunteers {

	const META        = '_scout_event_shifts';
	const DB_VERSION  = '1';
	const DB_OPTION   = 'scout_events_volunteers_db';
	const NONCE       = '_se_shifts_box';
	const MIN_SECONDS = 4;
	const BLANK_ROWS  = 3;

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 5 );
		add_shortcode( 'scout_volunteer', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_scout_volunteer', array( __CLASS__, 'signup' ) );
		add_action( 'admin_post_scout_volunteer', array( __CLASS__, 'signup' ) );
		add_action( 'admin_post_nopriv_scout_volunteer_drop', array( __CLASS__, 'drop' ) );
		add_action( 'admin_post_scout_volunteer_drop', array( __CLASS__, 'drop' ) );
		add_action( 'admin_post_scout_volunteer_admin', array( __CLASS__, 'admin_act' ) );
		add_action( 'admin_post_scout_volunteers_csv', array( __CLASS__, 'csv' ) );
		add_action( 'add_meta_boxes_' . Scout_Events::EVENT, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Scout_Events::EVENT, array( __CLASS__, 'save_box' ) );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
		add_action( Scout_Events_RSVP::CRON, array( __CLASS__, 'send_reminders' ) );
		add_action( 'send_headers', array( __CLASS__, 'no_cache' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/* ---------- the table ---------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_event_volunteers';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				shift varchar(20) NOT NULL DEFAULT '',
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				code varchar(40) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'going',
				created datetime NOT NULL,
				reminded datetime NULL,
				PRIMARY KEY  (id),
				KEY event_shift (event_id,shift),
				KEY code (code),
				KEY email (email)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ---------- shifts ---------- */

	/**
	 * An event's shifts, in time order.
	 *
	 * @return array<int,array{key:string,role:string,date:string,start:string,end:string,spots:int,note:string}>
	 */
	public static function shifts( int $event_id ): array {
		$raw = get_post_meta( $event_id, self::META, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $s ) {
			$out[] = array(
				'key'   => (string) ( $s['key'] ?? '' ),
				'role'  => (string) ( $s['role'] ?? '' ),
				'date'  => (string) ( $s['date'] ?? '' ),
				'start' => (string) ( $s['start'] ?? '' ),
				'end'   => (string) ( $s['end'] ?? '' ),
				'spots' => (int) ( $s['spots'] ?? 0 ),
				'note'  => (string) ( $s['note'] ?? '' ),
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['date'] . $a['start'] . $a['role'], $b['date'] . $b['start'] . $b['role'] ) );
		return $out;
	}

	public static function shift( int $event_id, string $key ): ?array {
		foreach ( self::shifts( $event_id ) as $s ) {
			if ( $s['key'] === $key ) {
				return $s;
			}
		}
		return null;
	}

	/** "Saturday, May 16 · 9:00 am to 1:00 pm". */
	public static function when( array $s ): string {
		$parts = array();
		if ( '' !== $s['date'] ) {
			$parts[] = Scout_Events::day_label( $s['date'] );
		}
		$t = Scout_Events::session_time_label( $s );
		if ( '' !== $t ) {
			$parts[] = $t;
		}
		return implode( ' · ', $parts );
	}

	/** @return array<string,int> shift key => volunteers signed up */
	public static function taken( int $event_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT shift, COUNT(*) AS n FROM ' . self::table() . " WHERE event_id = %d AND status = 'going' GROUP BY shift", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_column( array_map( static fn( $r ) => array( $r['shift'], (int) $r['n'] ), $rows ), 1, 0 );
	}

	/** @return array<int,array<string,string>> */
	public static function roster( int $event_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE event_id = %d AND status = 'going' ORDER BY created ASC", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function by_code( string $code ): array {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{12,40}$/', $code ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s ORDER BY id ASC', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* ---------- the owner's side: shifts on the event ---------- */

	public static function box(): void {
		add_meta_box( 'scout-event-shifts', __( 'Volunteer shifts', 'scout-events' ), array( __CLASS__, 'render_box' ), Scout_Events::EVENT, 'normal', 'default' );
	}

	public static function render_box( $post ): void {
		$shifts = self::shifts( (int) $post->ID );
		$taken  = 'auto-draft' === $post->post_status ? array() : self::taken( (int) $post->ID );
		wp_nonce_field( 'scout_shifts_save', self::NONCE );
		?>
		<p class="se-help"><?php esc_html_e( 'List the jobs this event needs. Put [scout_volunteer event="this event\'s address word"] on a page and volunteers pick shifts there. To remove a shift, clear its job name.', 'scout-events' ); ?></p>
		<div class="se-shifts-wrap">
			<table class="se-shifts">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Job', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Day', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'From', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'People', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Note for volunteers', 'scout-events' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Signed up', 'scout-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$rows = array_merge( $shifts, array_fill( 0, self::BLANK_ROWS, array( 'key' => '', 'role' => '', 'date' => '', 'start' => '', 'end' => '', 'spots' => 0, 'note' => '' ) ) );
					foreach ( $rows as $i => $s ) :
						$n = 'scout_shifts[' . $i . ']';
						?>
						<tr>
							<td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $s['key'] ); ?>"><label class="screen-reader-text" for="se-sh-<?php echo (int) $i; ?>-role"><?php esc_html_e( 'Job', 'scout-events' ); ?></label><input type="text" id="se-sh-<?php echo (int) $i; ?>-role" name="<?php echo esc_attr( $n ); ?>[role]" value="<?php echo esc_attr( $s['role'] ); ?>" placeholder="<?php esc_attr_e( 'Front gate', 'scout-events' ); ?>"></td>
							<td><input type="date" name="<?php echo esc_attr( $n ); ?>[date]" value="<?php echo esc_attr( $s['date'] ); ?>" aria-label="<?php esc_attr_e( 'Day', 'scout-events' ); ?>"></td>
							<td><input type="time" name="<?php echo esc_attr( $n ); ?>[start]" value="<?php echo esc_attr( $s['start'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'scout-events' ); ?>"></td>
							<td><input type="time" name="<?php echo esc_attr( $n ); ?>[end]" value="<?php echo esc_attr( $s['end'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'scout-events' ); ?>"></td>
							<td><input type="number" min="1" step="1" class="se-short-input" name="<?php echo esc_attr( $n ); ?>[spots]" value="<?php echo $s['spots'] ? (int) $s['spots'] : ''; ?>" aria-label="<?php esc_attr_e( 'People', 'scout-events' ); ?>"></td>
							<td><input type="text" name="<?php echo esc_attr( $n ); ?>[note]" value="<?php echo esc_attr( $s['note'] ); ?>" aria-label="<?php esc_attr_e( 'Note for volunteers', 'scout-events' ); ?>"></td>
							<td class="se-shift-taken"><?php echo '' !== $s['key'] ? esc_html( ( $taken[ $s['key'] ] ?? 0 ) . ' / ' . $s['spots'] ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $shifts ) : ?>
			<p><a href="<?php echo esc_url( Scout_Events_Admin::url( array( 'view' => 'volunteers', 'event' => (int) $post->ID ) ) ); ?>"><?php esc_html_e( 'See the roster', 'scout-events' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	public static function save_box( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'scout_shifts_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
		$rows = isset( $_POST['scout_shifts'] ) ? (array) wp_unslash( $_POST['scout_shifts'] ) : array();
		$out  = array();
		foreach ( $rows as $r ) {
			$role = sanitize_text_field( (string) ( $r['role'] ?? '' ) );
			if ( '' === $role ) {
				continue;
			}
			$key   = preg_match( '/^[a-z0-9]{8}$/', (string) ( $r['key'] ?? '' ) ) ? (string) $r['key'] : strtolower( wp_generate_password( 8, false, false ) );
			$out[] = array(
				'key'   => $key,
				'role'  => $role,
				'date'  => Scout_Events::clean_date( (string) ( $r['date'] ?? '' ) ),
				'start' => Scout_Events::clean_time( (string) ( $r['start'] ?? '' ) ),
				'end'   => Scout_Events::clean_time( (string) ( $r['end'] ?? '' ) ),
				'spots' => max( 1, absint( $r['spots'] ?? 1 ) ),
				'note'  => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
			);
		}
		update_post_meta( (int) $post_id, self::META, $out );
	}

	/* ---------- the volunteer's side ---------- */

	private static function event_from( string $word ): int {
		if ( '' === $word ) {
			$next = Scout_Events::event_ids( 'upcoming' );
			foreach ( $next as $id ) {
				if ( self::shifts( $id ) ) {
					return $id;
				}
			}
			return 0;
		}
		if ( ctype_digit( $word ) ) {
			return Scout_Events::EVENT === get_post_type( (int) $word ) ? (int) $word : 0;
		}
		$p = get_page_by_path( sanitize_title( $word ), OBJECT, Scout_Events::EVENT );
		return $p ? (int) $p->ID : 0;
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts( array( 'event' => '' ), (array) $atts, 'scout_volunteer' );
		wp_enqueue_style( 'scout-events' );
		$code = isset( $_GET['se_vol'] ) ? sanitize_text_field( wp_unslash( $_GET['se_vol'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the code is the key.
		if ( '' !== $code ) {
			return self::mine( $code );
		}
		$event = self::event_from( (string) $a['event'] );
		if ( ! $event ) {
			return '<p class="se-empty">' . esc_html__( 'Volunteer shifts for the next event are on the way.', 'scout-events' ) . '</p>';
		}
		$e      = Scout_Events::event( $event );
		$shifts = self::shifts( $event );
		if ( ! $shifts || Scout_Events::is_past( $e ) ) {
			return '<p class="se-empty">' . esc_html__( 'Volunteer shifts for the next event are on the way.', 'scout-events' ) . '</p>';
		}
		$taken = self::taken( $event );
		$msg   = isset( $_GET['volunteer'] ) ? sanitize_key( wp_unslash( $_GET['volunteer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notes = array(
			'missing'  => __( 'Please add your name, a working email, and at least one shift with room left.', 'scout-events' ),
			'tooquick' => __( 'That was very fast. Please check your choices and send it again.', 'scout-events' ),
		);
		$user  = wp_get_current_user();
		$out   = '<div class="se-portal se-volunteer" id="se-volunteer">';
		if ( isset( $notes[ $msg ] ) ) {
			$out .= '<p class="se-portal-note is-bad" role="status">' . esc_html( $notes[ $msg ] ) . '</p>';
		}
		$out .= '<form class="se-p-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="scout_volunteer">'
			. '<input type="hidden" name="se_event" value="' . (int) $event . '">'
			. wp_nonce_field( 'scout_volunteer_' . $event, '_se_vol', true, false )
			. '<input type="hidden" name="se_t" value="' . esc_attr( (string) time() ) . '">'
			. '<p class="se-p-hp" aria-hidden="true"><label for="se-vol-website2">' . esc_html__( 'Leave this empty', 'scout-events' ) . '</label><input type="text" id="se-vol-website2" name="se_website2" tabindex="-1" autocomplete="off"></p>'
			/* translators: %s: event name. */
			. '<fieldset class="se-p-group"><legend>' . esc_html( sprintf( __( 'Pick your shifts for %s', 'scout-events' ), get_the_title( $event ) ) ) . '</legend><ul class="se-shift-list">';
		$day = null;
		foreach ( $shifts as $s ) {
			$left = max( 0, $s['spots'] - ( $taken[ $s['key'] ] ?? 0 ) );
			if ( $s['date'] !== $day ) {
				$day  = $s['date'];
				$out .= '<li class="se-shift-day">' . esc_html( '' !== $day ? Scout_Events::day_label( $day ) : __( 'Day to be set', 'scout-events' ) ) . '</li>';
			}
			$id   = 'se-vol-' . $s['key'];
			$out .= '<li class="se-shift' . ( $left ? '' : ' is-full' ) . '"><label for="' . esc_attr( $id ) . '">'
				. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="se_shifts[]" value="' . esc_attr( $s['key'] ) . '"' . ( $left ? '' : ' disabled' ) . '>'
				. '<span class="se-shift-what"><strong>' . esc_html( $s['role'] ) . '</strong>'
				. '<span class="se-shift-time">' . esc_html( Scout_Events::session_time_label( $s ) ) . '</span>'
				. ( '' !== $s['note'] ? '<span class="se-shift-note">' . esc_html( $s['note'] ) . '</span>' : '' ) . '</span>'
				/* translators: %d: places left. */
				. '<span class="se-shift-left">' . esc_html( $left ? sprintf( _n( '%d spot left', '%d spots left', $left, 'scout-events' ), $left ) : __( 'Full', 'scout-events' ) ) . '</span>'
				. '</label></li>';
		}
		$out .= '</ul></fieldset>'
			. '<fieldset class="se-p-group"><legend>' . esc_html__( 'About you', 'scout-events' ) . '</legend>'
			. '<p class="se-p-field"><label for="se-vol-name">' . esc_html__( 'Your name', 'scout-events' ) . ' <span aria-hidden="true">*</span></label><input type="text" id="se-vol-name" name="se_name" autocomplete="name" required value="' . esc_attr( $user->exists() ? $user->display_name : '' ) . '"></p>'
			. '<p class="se-p-field"><label for="se-vol-email">' . esc_html__( 'Email', 'scout-events' ) . ' <span aria-hidden="true">*</span></label><input type="email" id="se-vol-email" name="se_email" autocomplete="email" required value="' . esc_attr( $user->exists() ? $user->user_email : '' ) . '"></p>'
			. '<p class="se-p-field"><label for="se-vol-phone">' . esc_html__( 'Phone', 'scout-events' ) . '</label><input type="tel" id="se-vol-phone" name="se_phone" autocomplete="tel"><span class="se-p-help">' . esc_html__( 'So the shift lead can reach you on the day. Never shown on the site.', 'scout-events' ) . '</span></p>'
			. '</fieldset>'
			. '<p><button type="submit" class="se-button wp-element-button">' . esc_html__( 'Sign me up', 'scout-events' ) . '</button></p>'
			. '</form></div>';
		return $out;
	}

	/** A volunteer's own shifts, from the link in their email. */
	private static function mine( string $code ): string {
		$rows = self::by_code( $code );
		if ( ! $rows ) {
			return '<p class="se-empty">' . esc_html__( 'That link has expired. Sign up again below, or reply to your confirmation email.', 'scout-events' ) . '</p>';
		}
		$event = (int) $rows[0]['event_id'];
		$out   = '<div class="se-portal se-volunteer" id="se-volunteer">';
		if ( isset( $_GET['volunteer'] ) && 'thanks' === $_GET['volunteer'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$out .= '<p class="se-portal-note is-good" role="status">' . esc_html__( 'Thank you. You are on the roster, and a confirmation is on its way by email.', 'scout-events' ) . '</p>';
		}
		/* translators: 1: name, 2: event name. */
		$out .= '<h3>' . esc_html( sprintf( __( '%1$s, your shifts for %2$s', 'scout-events' ), $rows[0]['name'], get_the_title( $event ) ) ) . '</h3><ul class="se-shift-list is-mine">';
		foreach ( $rows as $r ) {
			$s = self::shift( $event, $r['shift'] );
			if ( ! $s ) {
				continue;
			}
			$out .= '<li class="se-shift' . ( 'going' === $r['status'] ? '' : ' is-dropped' ) . '"><span class="se-shift-what"><strong>' . esc_html( $s['role'] ) . '</strong><span class="se-shift-time">' . esc_html( self::when( $s ) ) . '</span></span>';
			if ( 'going' === $r['status'] ) {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="scout_volunteer_drop"><input type="hidden" name="se_code" value="' . esc_attr( $code ) . '"><input type="hidden" name="se_row" value="' . (int) $r['id'] . '">' . wp_nonce_field( 'scout_volunteer_drop_' . $r['id'], '_se_drop', false, false ) . '<button type="submit" class="se-linkbutton">' . esc_html__( 'I can no longer do this shift', 'scout-events' ) . '</button></form>';
			} else {
				$out .= '<span class="se-shift-left">' . esc_html__( 'Dropped', 'scout-events' ) . '</span>';
			}
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}

	public static function no_cache(): void {
		if ( isset( $_GET['se_vol'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function signup(): void {
		$event = isset( $_POST['se_event'] ) ? absint( $_POST['se_event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$nonce = isset( $_POST['_se_vol'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_vol'] ) ) : '';
		if ( ! $event || ! wp_verify_nonce( $nonce, 'scout_volunteer_' . $event ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and send it again.', 'scout-events' ), 400 );
		}
		$ref  = wp_get_referer();
		$page = $ref ? remove_query_arg( array( 'volunteer', 'se_vol' ), $ref ) : home_url( '/' );
		$back = static function ( string $msg ) use ( $page ): void {
			wp_safe_redirect( add_query_arg( 'volunteer', $msg, $page ) . '#se-volunteer' );
			exit;
		};
		if ( ! empty( $_POST['se_website2'] ) ) {
			wp_safe_redirect( $page );
			exit;
		}
		$t = isset( $_POST['se_t'] ) ? (int) $_POST['se_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SECONDS ) {
			$back( 'tooquick' );
		}
		$name   = sanitize_text_field( wp_unslash( $_POST['se_name'] ?? '' ) );
		$email  = strtolower( sanitize_email( wp_unslash( $_POST['se_email'] ?? '' ) ) );
		$phone  = sanitize_text_field( wp_unslash( $_POST['se_phone'] ?? '' ) );
		$picked = array_map( 'sanitize_key', (array) wp_unslash( $_POST['se_shifts'] ?? array() ) );
		$taken  = self::taken( $event );
		$open   = array();
		foreach ( $picked as $key ) {
			$s = self::shift( $event, $key );
			if ( $s && $s['spots'] > ( $taken[ $key ] ?? 0 ) ) {
				$open[] = $key;
			}
		}
		if ( '' === $name || ! is_email( $email ) || ! $open ) {
			$back( 'missing' );
		}
		global $wpdb;
		// One code per volunteer per event, so one link shows all their shifts.
		$code = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . self::table() . ' WHERE event_id = %d AND email = %s LIMIT 1', $event, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$code = '' !== $code ? $code : wp_generate_password( 24, false, false );
		$mine = array_column( self::by_code( $code ), 'status', 'shift' );
		foreach ( $open as $key ) {
			if ( 'going' === ( $mine[ $key ] ?? '' ) ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'event_id' => $event,
					'shift'    => $key,
					'name'     => mb_substr( $name, 0, 190 ),
					'email'    => mb_substr( $email, 0, 190 ),
					'phone'    => mb_substr( $phone, 0, 40 ),
					'code'     => $code,
					'status'   => 'going',
					'created'  => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		self::email( $code );
		wp_safe_redirect( add_query_arg( array( 'se_vol' => $code, 'volunteer' => 'thanks' ), $page ) . '#se-volunteer' );
		exit;
	}

	public static function drop(): void {
		$code = isset( $_POST['se_code'] ) ? sanitize_text_field( wp_unslash( $_POST['se_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$row  = isset( $_POST['se_row'] ) ? absint( $_POST['se_row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok   = isset( $_POST['_se_drop'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_se_drop'] ) ), 'scout_volunteer_drop_' . $row );
		$mine = array_column( self::by_code( $code ), null, 'id' );
		if ( ! $ok || ! isset( $mine[ $row ] ) ) {
			wp_die( esc_html__( 'This link has expired. Reload the page and try again.', 'scout-events' ), 400 );
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'dropped' ), array( 'id' => $row ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ref = wp_get_referer();
		wp_safe_redirect( ( $ref ? preg_replace( '/#.*$/', '', remove_query_arg( 'volunteer', $ref ) ) : home_url( '/' ) ) . '#se-volunteer' );
		exit;
	}

	/** The confirmation (or, with $reminder, the day-before note) listing every shift on this code. */
	private static function email( string $code, bool $reminder = false, ?string $only_day = null ): void {
		$rows = array_values( array_filter( self::by_code( $code ), static fn( $r ) => 'going' === $r['status'] ) );
		if ( ! $rows ) {
			return;
		}
		$event = (int) $rows[0]['event_id'];
		$title = wp_specialchars_decode( get_the_title( $event ), ENT_QUOTES );
		$lines = array();
		foreach ( $rows as $r ) {
			$s = self::shift( $event, $r['shift'] );
			if ( $s && ( null === $only_day || $s['date'] === $only_day ) ) {
				$lines[] = '- ' . $s['role'] . ': ' . self::when( $s ) . ( '' !== $s['note'] ? "\n  " . $s['note'] : '' );
			}
		}
		if ( ! $lines ) {
			return;
		}
		$link = add_query_arg( 'se_vol', $code, self::page_for( $event ) );
		/* translators: %s: event name. */
		$subject = $reminder ? sprintf( __( 'Your volunteer shift tomorrow: %s', 'scout-events' ), $title ) : sprintf( __( 'You are volunteering at %s', 'scout-events' ), $title );
		$body    = sprintf( /* translators: %s: name. */ __( 'Hi %s,', 'scout-events' ), $rows[0]['name'] ) . "\n\n"
			. ( $reminder ? __( 'A reminder of your shift. Thank you for helping.', 'scout-events' ) : __( 'Thank you for volunteering. You are on the roster for:', 'scout-events' ) ) . "\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. __( 'See your shifts, or drop one if plans change:', 'scout-events' ) . "\n" . $link . "\n\n"
			. wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail( $rows[0]['email'], $subject, $body );
	}

	/** The page that shows the volunteer form for this event: where they signed up, else the event page. */
	private static function page_for( int $event ): string {
		$page = (int) get_option( 'scout_events_volunteer_page_' . $event );
		if ( ! $page ) {
			$ref = wp_get_referer();
			if ( $ref ) {
				$page = url_to_postid( $ref );
				if ( $page ) {
					update_option( 'scout_events_volunteer_page_' . $event, $page, false );
				}
			}
		}
		return $page ? (string) get_permalink( $page ) : (string) get_permalink( $event );
	}

	/** The day before each shift day, one reminder per volunteer. */
	public static function send_reminders(): void {
		global $wpdb;
		$tomorrow = Scout_Events::now()->modify( '+1 day' )->format( 'Y-m-d' );
		$events   = Scout_Events::event_ids( 'upcoming' );
		foreach ( $events as $event ) {
			$keys = array_column( array_filter( self::shifts( $event ), static fn( $s ) => $s['date'] === $tomorrow ), 'key' );
			if ( ! $keys ) {
				continue;
			}
			$codes = array();
			foreach ( self::roster( $event ) as $r ) {
				if ( in_array( $r['shift'], $keys, true ) && null === $r['reminded'] ) {
					$wpdb->update( self::table(), array( 'reminded' => current_time( 'mysql' ) ), array( 'id' => (int) $r['id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$codes[ $r['code'] ] = true;
				}
			}
			foreach ( array_keys( $codes ) as $code ) {
				self::email( (string) $code, true, $tomorrow );
			}
		}
	}

	/* ---------- the roster ---------- */

	/** Shifts with open spots in events starting in the next two weeks. */
	public static function needs_you( $items ): array {
		$items = (array) $items;
		$soon  = Scout_Events::now()->modify( '+14 days' )->format( 'Y-m-d H:i' );
		foreach ( Scout_Events::event_ids( 'upcoming' ) as $event ) {
			$shifts = self::shifts( $event );
			if ( ! $shifts || (string) get_post_meta( $event, '_scout_event_start', true ) > $soon ) {
				continue;
			}
			$taken = self::taken( $event );
			$open  = 0;
			foreach ( $shifts as $s ) {
				$open += max( 0, $s['spots'] - ( $taken[ $s['key'] ] ?? 0 ) );
			}
			if ( $open ) {
				$items[] = array(
					/* translators: 1: open spots, 2: event name. */
					'text'   => sprintf( _n( '%1$d volunteer spot is still open for %2$s.', '%1$d volunteer spots are still open for %2$s.', $open, 'scout-events' ), $open, get_the_title( $event ) ),
					'url'    => Scout_Events_Admin::url( array( 'view' => 'volunteers', 'event' => $event ) ),
					'action' => __( 'See the roster', 'scout-events' ),
				);
			}
		}
		return $items;
	}

	public static function admin_act(): void {
		$id = isset( $_POST['volunteer'] ) ? absint( $_POST['volunteer'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_volunteer_admin_' . $id );
		global $wpdb;
		$event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $event || ! current_user_can( 'edit_post', $event ) ) {
			wp_die( esc_html__( 'You do not have permission to change the roster.', 'scout-events' ), 403 );
		}
		$wpdb->update( self::table(), array( 'status' => 'dropped' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_safe_redirect( Scout_Events_Admin::url( array( 'view' => 'volunteers', 'event' => $event ) ) );
		exit;
	}

	public static function csv(): void {
		$event = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		check_admin_referer( 'scout_volunteers_csv_' . $event );
		if ( ! $event || ! current_user_can( 'edit_post', $event ) ) {
			wp_die( esc_html__( 'You do not have permission to download the roster.', 'scout-events' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_post_field( 'post_name', $event ) . '-volunteers.csv' ) . '"' );
		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		$safe = static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v;
		fputcsv( $out, array( 'Job', 'Day', 'From', 'To', 'Name', 'Email', 'Phone' ), ',', '"', '\\' );
		$roster = self::roster( $event );
		foreach ( self::shifts( $event ) as $s ) {
			foreach ( $roster as $r ) {
				if ( $r['shift'] === $s['key'] ) {
					fputcsv( $out, array( $s['role'], $s['date'], $s['start'], $s['end'], $safe( $r['name'] ), $safe( $r['email'] ), $safe( $r['phone'] ) ), ',', '"', '\\' );
				}
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Events, Volunteers: one event's shifts with who is on each and what is still open. */
	public static function view(): void {
		$events = array_values( array_filter( array_map( 'intval', get_posts( array( 'post_type' => Scout_Events::EVENT, 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => 200, 'fields' => 'ids' ) ) ), static fn( $id ) => (bool) self::shifts( $id ) ) );
		if ( ! $events ) {
			echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Volunteers', 'scout-events' ) . '</h2><p>' . esc_html__( 'No event has volunteer shifts yet. Open an event, fill in the Volunteer shifts box below the description, and update. Then put [scout_volunteer] on a page.', 'scout-events' ) . '</p></section>';
			return;
		}
		usort( $events, static fn( $a, $b ) => strcmp( (string) get_post_meta( $a, '_scout_event_start', true ), (string) get_post_meta( $b, '_scout_event_start', true ) ) );
		$event  = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a view.
		$event  = in_array( $event, $events, true ) ? $event : $events[0];
		$shifts = self::shifts( $event );
		$roster = self::roster( $event );
		$filled = 0;
		$total  = 0;
		foreach ( $shifts as $s ) {
			$total  += $s['spots'];
			$filled += min( $s['spots'], count( array_filter( $roster, static fn( $r ) => $r['shift'] === $s['key'] ) ) );
		}
		?>
		<section class="sh-box se-door">
			<form method="get" class="se-door-pick">
				<input type="hidden" name="page" value="<?php echo esc_attr( Scout_Events_Admin::SLUG . '-volunteers' ); ?>">
				<label for="se-vol-event" class="screen-reader-text"><?php esc_html_e( 'Event', 'scout-events' ); ?></label>
				<select id="se-vol-event" name="event" onchange="this.form.submit()">
					<?php foreach ( $events as $id ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $event, $id ); ?>><?php echo esc_html( get_the_title( $id ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'scout-events' ); ?></button></noscript>
			</form>
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) $filled; ?></p><p class="sh-note"><?php esc_html_e( 'spots filled', 'scout-events' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) max( 0, $total - $filled ); ?></p><p class="sh-note"><?php esc_html_e( 'still open', 'scout-events' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( array_unique( array_column( $roster, 'email' ) ) ); ?></p><p class="sh-note"><?php esc_html_e( 'volunteers', 'scout-events' ); ?></p></div>
			</div>
			<div class="se-door-tools">
				<span class="se-actions">
					<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $event ) ); ?>#scout-event-shifts"><?php esc_html_e( 'Edit shifts', 'scout-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=scout_volunteers_csv&event=' . $event ), 'scout_volunteers_csv_' . $event ) ); ?>"><?php esc_html_e( 'Download roster', 'scout-events' ); ?></a>
				</span>
			</div>
		</section>
		<?php foreach ( $shifts as $s ) : ?>
			<?php $people = array_values( array_filter( $roster, static fn( $r ) => $r['shift'] === $s['key'] ) ); ?>
			<section class="sh-box">
				<div class="se-box-head">
					<h2 class="sh-title"><?php echo esc_html( $s['role'] ); ?></h2>
					<span class="se-chip<?php echo count( $people ) >= $s['spots'] ? ' is-done' : ' is-warn'; ?>"><?php echo esc_html( count( $people ) . ' / ' . $s['spots'] ); ?></span>
				</div>
				<p class="sh-note"><?php echo esc_html( self::when( $s ) . ( '' !== $s['note'] ? ' · ' . $s['note'] : '' ) ); ?></p>
				<?php if ( $people ) : ?>
					<ul class="se-door-list">
						<?php foreach ( $people as $r ) : ?>
							<li class="se-door-row">
								<div class="se-door-who"><strong><?php echo esc_html( $r['name'] ); ?></strong><br><span class="sh-note"><?php echo esc_html( trim( $r['email'] . ' · ' . $r['phone'], ' ·' ) ); ?></span></div>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-door-act">
									<input type="hidden" name="action" value="scout_volunteer_admin">
									<input type="hidden" name="volunteer" value="<?php echo (int) $r['id']; ?>">
									<?php wp_nonce_field( 'scout_volunteer_admin_' . $r['id'] ); ?>
									<button type="submit" class="button se-quiet"><?php esc_html_e( 'Take off shift', 'scout-events' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="se-empty-admin"><?php esc_html_e( 'No one yet.', 'scout-events' ); ?></p>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
		<?php
	}

	/* ---------- privacy ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['scout-events-volunteers'] = array(
			'exporter_friendly_name' => __( 'Volunteer shifts', 'scout-events' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$data = array();
		foreach ( $rows as $r ) {
			$s      = self::shift( (int) $r['event_id'], $r['shift'] );
			$data[] = array(
				'group_id'    => 'scout-events-volunteers',
				'group_label' => __( 'Volunteer shifts', 'scout-events' ),
				'item_id'     => 'volunteer-' . $r['id'],
				'data'        => array(
					array(
						'name'  => __( 'Event', 'scout-events' ),
						'value' => get_the_title( (int) $r['event_id'] ),
					),
					array(
						'name'  => __( 'Shift', 'scout-events' ),
						'value' => $s ? $s['role'] . ', ' . self::when( $s ) : $r['shift'],
					),
					array(
						'name'  => __( 'Name', 'scout-events' ),
						'value' => $r['name'],
					),
					array(
						'name'  => __( 'Phone', 'scout-events' ),
						'value' => $r['phone'],
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
		$erasers['scout-events-volunteers'] = array(
			'eraser_friendly_name' => __( 'Volunteer shifts', 'scout-events' ),
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
