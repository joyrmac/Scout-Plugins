<?php
/**
 * The Bookings hub: its own sidebar item with the Scout bolt, beside Members
 * and Events. The schedule by day or week, requests to approve, paid and
 * unpaid at a glance, client details and intake answers, blocked time, the
 * private calendar feed, and the settings.
 *
 * Bookings hold clients' personal details, so only people who can list users
 * (administrators) see any of it, the same rule as Scout Members.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Admin {

	const SLUG = 'scout-booking';
	const CAP  = 'list_users';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'decide', 'cancel', 'block', 'unblock', 'settings', 'feed_reset' ) as $a ) {
			add_action( 'admin_post_scout_booking_' . $a, array( __CLASS__, $a ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_BOOKING_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'scout_admin_nav_hint', array( __CLASS__, 'hint' ), 10, 2 );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
	}

	/** The hub, or one of its screens ('types', 'settings'), with an optional #anchor. */
	public static function url( array $args = array(), string $hash = '', string $view = '' ): string {
		$base = admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $view ? '-' . $view : '' ) );
		return ( $args ? add_query_arg( $args, $base ) : $base ) . ( '' !== $hash ? '#' . $hash : '' );
	}

	private static function icon(): string {
		if ( ! Scout_Admin_UI::branded() ) {
			return 'dashicons-calendar-alt';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#0E8FE6" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		$waiting = count( Scout_Booking::with_status( 'requested' ) );
		$badge   = $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '';
		add_menu_page( __( 'Scout Booking', 'scout-booking' ), __( 'Bookings', 'scout-booking' ) . $badge, self::CAP, self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.3' );
		add_submenu_page( self::SLUG, __( 'Bookings', 'scout-booking' ), __( 'Schedule', 'scout-booking' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Session types', 'scout-booking' ), __( 'Session types', 'scout-booking' ), self::CAP, self::SLUG . '-types', array( 'Scout_Booking_Types_Admin', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Booking settings', 'scout-booking' ), __( 'Settings', 'scout-booking' ), self::CAP, self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_BOOKING_URL . 'assets/scout-admin.css' );
		wp_enqueue_style( 'scout-booking-admin', SCOUT_BOOKING_URL . 'assets/booking-admin.css', array( 'scout-admin' ), SCOUT_BOOKING_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Bookings', 'scout-booking' ) . '</a>' );
		return $links;
	}

	public static function hint( $hint, $slug ) {
		if ( self::SLUG === $slug ) {
			$n = count( Scout_Booking::with_status( 'requested' ) );
			/* translators: %d: number of requests. */
			return $n ? sprintf( __( '%d to approve', 'scout-booking' ), $n ) : $hint;
		}
		return $hint;
	}

	/** Requests join the Events "Needs you" list, so one screen shows everything waiting. */
	public static function needs_you( $items ): array {
		$items = (array) $items;
		if ( ! current_user_can( self::CAP ) ) {
			return $items;
		}
		$n = count( Scout_Booking::with_status( 'requested' ) );
		if ( $n ) {
			$items[] = array(
				/* translators: %d: number of requests. */
				'text'   => sprintf( _n( '%d session request to approve.', '%d session requests to approve.', $n, 'scout-booking' ), $n ),
				'url'    => self::url( array(), 'sb-requests' ),
				'action' => __( 'See requests', 'scout-booking' ),
			);
		}
		return $items;
	}

	/* ---------- actions ---------- */

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage bookings.', 'scout-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( string $msg, string $hash = '' ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( 'sb-msg', $ref ) : self::url();
		$url = preg_replace( '/#.*$/', '', $url );
		wp_safe_redirect( add_query_arg( 'sb-msg', $msg, $url ) . ( '' !== $hash ? '#' . $hash : '' ) );
		exit;
	}

	private static function posted_row(): ?array {
		return Scout_Booking::get( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
	}

	/** Approve or decline a request. */
	public static function decide(): void {
		self::guard( 'scout_booking_decide' );
		$row = self::posted_row();
		$yes = 'approve' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( ! $row || 'requested' !== $row['status'] ) {
			self::back( 'gone' );
		}
		if ( ! $yes ) {
			$row = Scout_Booking::update( $row['id'], array( 'status' => 'declined' ) );
			Scout_Booking_Emails::declined( $row );
			self::back( 'declined', 'sb-requests' );
		}
		if ( $row['due'] > 0 && Scout_Booking::payments_on() ) {
			$row = Scout_Booking::update( $row['id'], array( 'status' => 'approved' ) );
			Scout_Booking_Emails::approved( $row );
			self::back( 'approved', 'sb-requests' );
		}
		$row = Scout_Booking::update( $row['id'], array( 'status' => 'confirmed' ) );
		Scout_Booking_Emails::confirmed( $row );
		self::back( 'confirmed', 'sb-requests' );
	}

	/** The owner cancels an appointment or request. The client is told by email. */
	public static function cancel(): void {
		self::guard( 'scout_booking_cancel' );
		$row = self::posted_row();
		if ( ! $row || ! in_array( $row['status'], array( 'requested', 'approved', 'pending', 'confirmed' ), true ) ) {
			self::back( 'gone' );
		}
		$row = Scout_Booking::update( $row['id'], array( 'status' => 'cancelled' ) );
		Scout_Booking_Emails::cancelled( $row, 'owner' );
		self::back( $row['paid'] > 0 ? 'cancelled-paid' : 'cancelled' );
	}

	public static function block(): void {
		self::guard( 'scout_booking_block' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$date = Scout_Booking::clean_date( sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ) );
		$last = Scout_Booking::clean_date( sanitize_text_field( wp_unslash( $_POST['last'] ?? '' ) ) );
		$all  = ! empty( $_POST['all_day'] );
		$from = Scout_Booking::clean_time( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
		$to   = Scout_Booking::clean_time( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		// phpcs:enable
		if ( '' === $date ) {
			self::back( 'nodate', 'sb-block' );
		}
		$last = '' !== $last && $last >= $date ? $last : $date;
		if ( $all ) {
			$start = $date . ' 00:00:00';
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $last, wp_timezone() )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		} else {
			if ( '' === $from || '' === $to || $to <= $from ) {
				self::back( 'notimes', 'sb-block' );
			}
			$start = $date . ' ' . $from . ':00';
			$end   = $last . ' ' . $to . ':00';
		}
		Scout_Booking::add(
			array(
				'status'    => 'blocked',
				'start_at'  => $start,
				'end_at'    => $end,
				'block_end' => $end,
				'note'      => $note,
			)
		);
		self::back( 'blocked', 'sb-block' );
	}

	public static function unblock(): void {
		self::guard( 'scout_booking_unblock' );
		$row = self::posted_row();
		if ( $row && 'blocked' === $row['status'] ) {
			Scout_Booking::delete( $row['id'] );
		}
		self::back( 'unblocked' );
	}

	public static function settings(): void {
		self::guard( 'scout_booking_settings' );
		$in = isset( $_POST['sb'] ) ? (array) wp_unslash( $_POST['sb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is cleaned by Scout_Booking::settings() on read and below.
		Scout_Booking::save_settings(
			array(
				'page_id'      => absint( $in['page_id'] ?? 0 ),
				'notify'       => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
				'cancel_hours' => absint( $in['cancel_hours'] ?? 24 ),
				'late'         => 'allow' === ( $in['late'] ?? '' ) ? 'allow' : 'block',
				'notice_hours' => absint( $in['notice_hours'] ?? 12 ),
				'days_ahead'   => absint( $in['days_ahead'] ?? 60 ),
				'step'         => absint( $in['step'] ?? 30 ),
				'hold_minutes' => absint( $in['hold_minutes'] ?? 30 ),
				'pay_hours'    => absint( $in['pay_hours'] ?? 48 ),
				'policy'       => sanitize_textarea_field( (string) ( $in['policy'] ?? '' ) ),
				'optin'        => empty( $in['optin'] ) ? 0 : 1,
				'optin_label'  => sanitize_text_field( (string) ( $in['optin_label'] ?? '' ) ),
				'reminders'    => empty( $in['reminders'] ) ? 0 : 1,
				'hours'        => Scout_Booking::clean_week( (array) ( $in['hours'] ?? array() ) ),
			)
		);
		self::back( 'saved' );
	}

	public static function feed_reset(): void {
		self::guard( 'scout_booking_feed_reset' );
		Scout_Booking::save_settings( array( 'feed_token' => Scout_Booking::new_token() ) );
		self::back( 'feed', 'sb-feed' );
	}

	/* ---------- screens ---------- */

	private static function message(): void {
		$msg   = isset( $_GET['sb-msg'] ) ? sanitize_key( wp_unslash( $_GET['sb-msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'approved'       => array( 'success', __( 'Approved. The client has an email with the link to pay and book.', 'scout-booking' ) ),
			'confirmed'      => array( 'success', __( 'Approved and booked. The client has their confirmation.', 'scout-booking' ) ),
			'declined'       => array( 'success', __( 'Declined. The client has a short, kind note.', 'scout-booking' ) ),
			'cancelled'      => array( 'success', __( 'Cancelled. The client has an email.', 'scout-booking' ) ),
			'cancelled-paid' => array( 'warning', __( 'Cancelled, and the client has an email. They paid online: refund it from the order in WooCommerce if the policy calls for it.', 'scout-booking' ) ),
			'blocked'        => array( 'success', __( 'Time blocked.', 'scout-booking' ) ),
			'unblocked'      => array( 'success', __( 'Blocked time removed.', 'scout-booking' ) ),
			'nodate'         => array( 'error', __( 'Pick the day to block.', 'scout-booking' ) ),
			'notimes'        => array( 'error', __( 'Pick a start and an end time, or tick All day.', 'scout-booking' ) ),
			'saved'          => array( 'success', __( 'Settings saved.', 'scout-booking' ) ),
			'feed'           => array( 'success', __( 'New calendar address made. The old one stops working, so subscribe again with the new one.', 'scout-booking' ) ),
			'gone'           => array( 'error', __( 'That booking has already changed. Here is the latest.', 'scout-booking' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	/** The setup notes that matter: payments, a page, a session type. */
	private static function setup_notes(): void {
		$notes = array();
		if ( ! Scout_Booking::payments_on() ) {
			$notes[] = __( 'Payments are off: WooCommerce is not active, so every booking comes in as a request for you to approve, and nothing is charged on the site.', 'scout-booking' );
		}
		if ( ! Scout_Booking::types( true ) ) {
			$notes[] = __( 'No session types are turned on yet. Add one under Session types.', 'scout-booking' );
		}
		if ( ! Scout_Booking::settings()['page_id'] ) {
			$notes[] = __( 'Put [scout_booking] on a page, then pick that page under Settings so email links lead there.', 'scout-booking' );
		}
		foreach ( $notes as $n ) {
			echo '<div class="sh-box sbk-note"><p>' . esc_html( $n ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing what to show.
		$view = isset( $_GET['view'] ) && 'day' === $_GET['view'] ? 'day' : 'week';
		$date = Scout_Booking::clean_date( isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '' );
		// phpcs:enable
		$now   = Scout_Booking::now();
		$day   = '' !== $date ? DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() ) : $now->setTime( 0, 0 );
		$from  = 'week' === $view ? $day->modify( '-' . ( (int) $day->format( 'N' ) - 1 ) . ' days' ) : $day;
		$to    = $from->modify( 'week' === $view ? '+7 days' : '+1 day' );
		$rows  = Scout_Booking::between( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), array( 'requested', 'approved', 'pending', 'confirmed', 'blocked', 'cancelled' ) );
		$rows  = array_values( array_filter( $rows, static fn( $r ) => 'pending' !== $r['status'] || Scout_Booking::holds( $r, $now ) || $r['order_id'] ) );
		$reqs  = Scout_Booking::with_status( 'requested' );
		$week  = Scout_Booking::between( $now->format( 'Y-m-d H:i:s' ), $now->modify( '+7 days' )->format( 'Y-m-d H:i:s' ), array( 'confirmed' ) );
		$await = array_merge( Scout_Booking::with_status( 'approved' ), array_filter( Scout_Booking::with_status( 'pending' ), static fn( $r ) => $r['order_id'] > 0 ) );

		Scout_Admin_UI::open(
			__( 'Bookings', 'scout-booking' ),
			__( 'Your sessions by day or week, requests waiting for you, and who has paid. Block time you are away, and add the calendar feed to your phone.', 'scout-booking' ),
			__( 'Bookings', 'scout-booking' )
		);
		self::message();
		// The status line: requests waiting first, else the next booking.
		$next = null;
		foreach ( $week as $w ) {
			if ( Scout_Booking::at( $w['start_at'] ) >= $now ) {
				$next = $w;
				break;
			}
		}
		$next_words = $next
			/* translators: 1: session name, 2: client name, 3: day and time. */
			? sprintf( __( 'Next: %1$s with %2$s, %3$s.', 'scout-booking' ), $next['type_name'], Scout_Booking::client_name( $next ), Scout_Booking::when_label( $next ) )
			: __( 'Nothing booked in the next 7 days.', 'scout-booking' );
		if ( $reqs ) {
			self::ui_status(
				'warn',
				/* translators: %d: number of requests. */
				sprintf( _n( '%d request is waiting for you.', '%d requests are waiting for you.', count( $reqs ), 'scout-booking' ), count( $reqs ) ),
				$next_words,
				'<a class="button" href="#sb-requests">' . esc_html__( 'Read requests', 'scout-booking' ) . '</a>'
			);
		} else {
			self::ui_status( 'good', __( 'No requests waiting.', 'scout-booking' ), $next_words );
		}
		self::setup_notes();
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $reqs ); ?></p><p class="sh-note"><a href="#sb-requests"><?php esc_html_e( 'Requests to approve', 'scout-booking' ); ?></a></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $week ); ?></p><p class="sh-note"><?php esc_html_e( 'Booked in the next 7 days', 'scout-booking' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $await ); ?></p><p class="sh-note"><?php esc_html_e( 'Waiting for payment', 'scout-booking' ); ?></p></div>
			</div>
		</section>

		<div class="sbk-layout">
			<div class="sbk-main">
				<section class="sh-box" id="sb-requests">
					<h2 class="sh-title"><?php esc_html_e( 'Requests to approve', 'scout-booking' ); ?></h2>
					<p><?php esc_html_e( 'Sessions marked "by request only" wait here. Approve to send the client a link to pay and book; decline to send a short, kind note. The time stays held until you decide.', 'scout-booking' ); ?></p>
					<?php if ( $reqs ) : ?>
						<ul class="sbk-list">
							<?php
							foreach ( $reqs as $r ) {
								self::row( $r, true );
							}
							?>
						</ul>
					<?php else : ?>
						<p class="sbk-empty"><?php esc_html_e( 'No requests waiting.', 'scout-booking' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="sh-box" id="sb-schedule">
					<div class="sbk-head">
						<h2 class="sh-title">
							<?php
							echo esc_html(
								'week' === $view
									/* translators: 1: first day, 2: last day. */
									? sprintf( __( 'Week of %1$s to %2$s', 'scout-booking' ), wp_date( 'F j', $from->setTime( 12, 0 )->getTimestamp() ), wp_date( 'F j', $to->modify( '-1 day' )->setTime( 12, 0 )->getTimestamp() ) )
									: wp_date( 'l, F j, Y', $from->setTime( 12, 0 )->getTimestamp() )
							);
							?>
						</h2>
						<nav class="sbk-nav" aria-label="<?php esc_attr_e( 'Schedule dates', 'scout-booking' ); ?>">
							<?php $step = 'week' === $view ? '7 days' : '1 day'; ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => $view, 'date' => $from->modify( '-' . $step )->format( 'Y-m-d' ) ), 'sb-schedule' ) ); ?>"><?php esc_html_e( 'Previous', 'scout-booking' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => $view ), 'sb-schedule' ) ); ?>"><?php esc_html_e( 'Today', 'scout-booking' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::url( array( 'view' => $view, 'date' => $from->modify( '+' . $step )->format( 'Y-m-d' ) ), 'sb-schedule' ) ); ?>"><?php esc_html_e( 'Next', 'scout-booking' ); ?></a>
							<span class="sbk-toggle" role="group" aria-label="<?php esc_attr_e( 'Show', 'scout-booking' ); ?>">
								<?php // The chosen view is outlined and marked current; the other is quiet. Links, so aria-current rather than aria-pressed. ?>
								<a class="button<?php echo 'day' === $view ? ' is-current' : ' sh-quiet'; ?>" href="<?php echo esc_url( self::url( array( 'view' => 'day', 'date' => $day->format( 'Y-m-d' ) ), 'sb-schedule' ) ); ?>"<?php echo 'day' === $view ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Day', 'scout-booking' ); ?></a>
								<a class="button<?php echo 'week' === $view ? ' is-current' : ' sh-quiet'; ?>" href="<?php echo esc_url( self::url( array( 'view' => 'week', 'date' => $day->format( 'Y-m-d' ) ), 'sb-schedule' ) ); ?>"<?php echo 'week' === $view ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Week', 'scout-booking' ); ?></a>
							</span>
						</nav>
					</div>
					<?php
					$by_day = array();
					foreach ( $rows as $r ) {
						$by_day[ substr( $r['start_at'], 0, 10 ) ][] = $r;
					}
					if ( ! $by_day ) {
						echo '<p class="sbk-empty">' . esc_html__( 'Nothing booked or blocked for these dates.', 'scout-booking' ) . '</p>';
					}
					foreach ( $by_day as $ymd => $list ) {
						$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() )->setTime( 12, 0 );
						echo '<h3 class="sbk-day">' . esc_html( wp_date( 'l, F j', $d->getTimestamp() ) ) . '</h3><ul class="sbk-list">';
						foreach ( $list as $r ) {
							self::row( $r, false );
						}
						echo '</ul>';
					}
					?>
				</section>
			</div>

			<div class="sbk-side">
				<section class="sh-box" id="sb-block">
					<h2 class="sh-title"><?php esc_html_e( 'Block time', 'scout-booking' ); ?></h2>
					<p><?php esc_html_e( 'Away, teaching, or keeping a day for yourself. Nobody can book blocked time.', 'scout-booking' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sbk-form">
						<input type="hidden" name="action" value="scout_booking_block">
						<?php wp_nonce_field( 'scout_booking_block' ); ?>
						<p class="sbk-field"><label for="sbk-b-date"><?php esc_html_e( 'Day', 'scout-booking' ); ?></label><input type="date" id="sbk-b-date" name="date" required min="<?php echo esc_attr( $now->format( 'Y-m-d' ) ); ?>"></p>
						<p class="sbk-field"><label for="sbk-b-last"><?php esc_html_e( 'Last day (if more than one)', 'scout-booking' ); ?></label><input type="date" id="sbk-b-last" name="last" min="<?php echo esc_attr( $now->format( 'Y-m-d' ) ); ?>"></p>
						<p class="sbk-check"><label><input type="checkbox" name="all_day" value="1" checked> <?php esc_html_e( 'All day', 'scout-booking' ); ?></label></p>
						<div class="sbk-pair">
							<p class="sbk-field"><label for="sbk-b-from"><?php esc_html_e( 'From', 'scout-booking' ); ?></label><input type="time" id="sbk-b-from" name="from"></p>
							<p class="sbk-field"><label for="sbk-b-to"><?php esc_html_e( 'To', 'scout-booking' ); ?></label><input type="time" id="sbk-b-to" name="to"></p>
						</div>
						<p class="description"><?php esc_html_e( 'Untick All day to block only the hours between From and To.', 'scout-booking' ); ?></p>
						<p class="sbk-field"><label for="sbk-b-note"><?php esc_html_e( 'Note for you (optional)', 'scout-booking' ); ?></label><input type="text" id="sbk-b-note" name="note"></p>
						<p><button type="submit" class="button"><?php esc_html_e( 'Block this time', 'scout-booking' ); ?></button></p>
					</form>
				</section>

				<?php self::feed_box(); ?>

				<?php self::ui_fold_open( __( 'Showing the booking form', 'scout-booking' ), false, 'sb-codes' ); ?>
					<dl class="sbk-codes">
						<dt><code>[scout_booking]</code></dt><dd><?php esc_html_e( 'Every session that is turned on.', 'scout-booking' ); ?></dd>
						<dt><code>[scout_booking types="id,id"]</code></dt><dd><?php esc_html_e( 'Only some sessions, for a page about one kind of work. Ids are on the Session types screen.', 'scout-booking' ); ?></dd>
						<dt><code>[scout_booking type="id"]</code></dt><dd><?php esc_html_e( 'One session, straight to picking a time.', 'scout-booking' ); ?></dd>
					</dl>
				<?php self::ui_fold_close(); ?>
			</div>
		</div>
		<?php
		Scout_Admin_UI::close();
	}

	/** One appointment, request or blocked time in a list. */
	private static function row( array $r, bool $deciding ): void {
		$status = $r['status'];
		$pill   = array(
			'requested' => 'is-received',
			'approved'  => 'is-progress',
			'pending'   => 'is-progress',
			'confirmed' => 'is-on',
		)[ $status ] ?? '';
		echo '<li class="sbk-item is-' . esc_attr( $status ) . '">';
		echo '<p class="sbk-time">' . esc_html( $deciding ? Scout_Booking::when_label( $r ) : Scout_Booking::time_range_label( $r ) ) . '</p>';
		echo '<div class="sbk-body">';
		if ( 'blocked' === $status ) {
			echo '<p class="sbk-what">' . esc_html__( 'Blocked', 'scout-booking' ) . ( '' !== (string) $r['note'] ? ' · ' . esc_html( $r['note'] ) : '' ) . '</p>';
			if ( substr( $r['start_at'], 0, 10 ) !== substr( $r['end_at'], 0, 10 ) ) {
				/* translators: %s: end date and time. */
				echo '<p class="sh-note">' . esc_html( sprintf( __( 'Until %s', 'scout-booking' ), wp_date( 'l, F j g:i a', Scout_Booking::at( $r['end_at'] )->getTimestamp() ) ) ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sbk-actions"><input type="hidden" name="action" value="scout_booking_unblock"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			wp_nonce_field( 'scout_booking_unblock' );
			echo '<button type="submit" class="button sh-quiet">' . esc_html__( 'Remove', 'scout-booking' ) . '</button></form></div></li>';
			return;
		}
		echo '<p class="sbk-what">' . esc_html( $r['type_name'] ) . ' <span class="sh-pill ' . esc_attr( $pill ) . '">' . esc_html( Scout_Booking::statuses()[ $status ] ?? $status ) . '</span></p>';
		echo '<p class="sbk-who"><strong>' . esc_html( Scout_Booking::client_name( $r ) ) . '</strong> · <a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>';
		if ( '' !== $r['phone'] ) {
			echo ' · <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['phone'] ) ) . '">' . esc_html( $r['phone'] ) . '</a>';
		}
		echo '</p><p class="sbk-money">' . esc_html( self::money_words( $r ) );
		if ( $r['order_id'] && class_exists( 'Scout_Booking_Woo' ) && Scout_Booking::payments_on() ) {
			$u = Scout_Booking_Woo::order_url( $r['order_id'] );
			if ( '' !== $u ) {
				/* translators: %d: order number. */
				echo ' · <a href="' . esc_url( $u ) . '">' . esc_html( sprintf( __( 'Order #%d', 'scout-booking' ), $r['order_id'] ) ) . '</a>';
			}
		}
		echo '</p>';
		if ( $r['answers'] ) {
			echo '<details class="sbk-answers"><summary>' . esc_html__( 'Intake answers', 'scout-booking' ) . '</summary><dl>';
			foreach ( $r['answers'] as $a ) {
				echo '<dt>' . esc_html( (string) ( $a['q'] ?? '' ) ) . '</dt><dd>' . ( '' !== (string) ( $a['a'] ?? '' ) ? nl2br( esc_html( (string) $a['a'] ) ) : '<span class="sh-note">' . esc_html__( 'No answer', 'scout-booking' ) . '</span>' ) . '</dd>';
			}
			echo '</dl></details>';
		}
		if ( $r['optin'] ) {
			echo '<p class="sh-note">' . esc_html__( 'Asked for the newsletter.', 'scout-booking' ) . '</p>';
		}
		if ( $deciding ) {
			echo '<div class="sbk-actions">';
			foreach ( array( 'approve' => __( 'Approve', 'scout-booking' ), 'decline' => __( 'Decline', 'scout-booking' ) ) as $k => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="scout_booking_decide"><input type="hidden" name="decision" value="' . esc_attr( $k ) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
				wp_nonce_field( 'scout_booking_decide' );
				echo '<button type="submit" class="button' . ( 'approve' === $k ? '' : ' sh-quiet' ) . '">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html( Scout_Booking::client_name( $r ) ) . '</span></button></form>';
			}
			echo '</div>';
		} elseif ( in_array( $status, array( 'approved', 'pending', 'confirmed' ), true ) && Scout_Booking::at( $r['end_at'] ) > Scout_Booking::now() ) {
			echo '<details class="sbk-more"><summary>' . esc_html__( 'Cancel this booking', 'scout-booking' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="scout_booking_cancel"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			wp_nonce_field( 'scout_booking_cancel' );
			echo '<p>' . esc_html__( 'The client gets an email saying it is cancelled. Refunds are made from the order in WooCommerce.', 'scout-booking' ) . '</p><button type="submit" class="button">' . esc_html__( 'Yes, cancel it', 'scout-booking' ) . '</button></form></details>';
		}
		echo '</div></li>';
	}

	/** "Deposit paid: $50 of $150" and friends. */
	public static function money_words( array $r ): string {
		if ( $r['price'] <= 0 ) {
			return __( 'No charge', 'scout-booking' );
		}
		if ( $r['paid'] > 0 ) {
			$left  = max( 0, $r['price'] - $r['due'] );
			$codes = $r['order_id'] && Scout_Booking::payments_on() ? Scout_Booking_Woo::coupons( $r['order_id'] ) : '';
			/* translators: %s: discount codes. */
			$codes = '' !== $codes ? ' ' . sprintf( __( 'Code %s.', 'scout-booking' ), $codes ) : '';
			return ( $left > 0
				/* translators: 1: paid, 2: price, 3: balance. */
				? sprintf( __( 'Deposit paid: %1$s of %2$s. %3$s due at the session.', 'scout-booking' ), Scout_Booking::money( $r['paid'] ), Scout_Booking::money( $r['price'] ), Scout_Booking::money( $left ) )
				/* translators: 1: paid, 2: price. */
				: sprintf( __( 'Paid online: %1$s (price %2$s).', 'scout-booking' ), Scout_Booking::money( $r['paid'] ), Scout_Booking::money( $r['price'] ) ) ) . $codes;
		}
		if ( $r['order_id'] && in_array( $r['status'], array( 'pending', 'approved' ), true ) ) {
			return __( 'Order placed, payment not received yet.', 'scout-booking' );
		}
		if ( 'confirmed' === $r['status'] ) {
			/* translators: %s: price. */
			return sprintf( __( 'Unpaid: %s at the session.', 'scout-booking' ), Scout_Booking::money( $r['price'] ) );
		}
		/* translators: 1: price, 2: amount to pay online. */
		return $r['due'] > 0 && Scout_Booking::payments_on() ? sprintf( __( 'Unpaid. Price %1$s, %2$s to pay online.', 'scout-booking' ), Scout_Booking::money( $r['price'] ), Scout_Booking::money( $r['due'] ) ) : sprintf( __( 'Unpaid. Price %s.', 'scout-booking' ), Scout_Booking::money( $r['price'] ) );
	}

	private static function feed_box(): void {
		$url = Scout_Booking_Front::feed_url();
		?>
		<section class="sh-box" id="sb-feed">
			<h2 class="sh-title"><?php esc_html_e( 'Your calendar feed', 'scout-booking' ); ?></h2>
			<p><?php esc_html_e( 'Subscribe to this private address in your phone calendar and every booking, request and blocked time shows up there, updated about once an hour.', 'scout-booking' ); ?></p>
			<p><label class="screen-reader-text" for="sbk-feed"><?php esc_html_e( 'Calendar feed address', 'scout-booking' ); ?></label><input type="text" id="sbk-feed" class="large-text code" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select()"></p>
			<p class="sh-note"><?php esc_html_e( 'iPhone: Settings, Calendar, Accounts, Add Account, Other, Add Subscribed Calendar, and paste it. Google Calendar: Other calendars, From URL. Anyone with the address can see your bookings, so keep it to yourself.', 'scout-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scout_booking_feed_reset">
				<?php wp_nonce_field( 'scout_booking_feed_reset' ); ?>
				<button type="submit" class="button sh-quiet"><?php esc_html_e( 'Make a new address (the old one stops working)', 'scout-booking' ); ?></button>
			</form>
		</section>
		<?php
	}

	public static function render_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Scout_Booking::settings();
		Scout_Admin_UI::open(
			__( 'Booking settings', 'scout-booking' ),
			__( 'How far ahead people can book, the cancellation window, and where booking emails go.', 'scout-booking' ),
			__( 'Bookings', 'scout-booking' )
		);
		self::message();
		$num = static function ( string $key, string $label, string $help, int $min, int $max, int $value ): void {
			echo '<p class="sbk-field"><label for="sbk-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="number" id="sbk-' . esc_attr( $key ) . '" name="sb[' . esc_attr( $key ) . ']" min="' . (int) $min . '" max="' . (int) $max . '" value="' . (int) $value . '" class="small-text"><span class="description">' . esc_html( $help ) . '</span></p>';
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sbk-settings">
			<input type="hidden" name="action" value="scout_booking_settings">
			<?php wp_nonce_field( 'scout_booking_settings' ); ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Page and emails', 'scout-booking' ); ?></h2>
				<p class="sbk-field">
					<label for="sbk-page"><?php esc_html_e( 'Booking page', 'scout-booking' ); ?></label>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'sb[page_id]',
							'id'                => 'sbk-page',
							'selected'          => (int) $s['page_id'],
							'show_option_none'  => esc_html__( 'Pick the page with [scout_booking]', 'scout-booking' ),
							'option_none_value' => '0',
						)
					);
					?>
					<span class="description"><?php esc_html_e( 'Links in booking emails lead here. Set by itself the first time the form is shown.', 'scout-booking' ); ?></span>
				</p>
				<p class="sbk-field"><label for="sbk-notify"><?php esc_html_e( 'Send booking emails for you to', 'scout-booking' ); ?></label><input type="email" id="sbk-notify" name="sb[notify]" class="regular-text" value="<?php echo esc_attr( $s['notify'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><span class="description"><?php esc_html_e( 'Empty uses the site email. Clients see this address when a change is too late to make online.', 'scout-booking' ); ?></span></p>
				<p class="sbk-check"><label><input type="checkbox" name="sb[reminders]" value="1" <?php checked( $s['reminders'] ); ?>> <?php esc_html_e( 'Email each client a reminder the day before their session.', 'scout-booking' ); ?></label></p>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Changes and cancellations', 'scout-booking' ); ?></h2>
				<?php $num( 'cancel_hours', __( 'Cancellation window, in hours', 'scout-booking' ), __( 'Clients can change or cancel online until this many hours before the session.', 'scout-booking' ), 0, 720, $s['cancel_hours'] ); ?>
				<fieldset class="sbk-field">
					<legend><?php esc_html_e( 'Inside the window', 'scout-booking' ); ?></legend>
					<label><input type="radio" name="sb[late]" value="block" <?php checked( 'block', $s['late'] ); ?>> <?php esc_html_e( 'Clients email you; nothing changes online', 'scout-booking' ); ?></label><br>
					<label><input type="radio" name="sb[late]" value="allow" <?php checked( 'allow', $s['late'] ); ?>> <?php esc_html_e( 'Clients can still cancel online, and the deposit is kept', 'scout-booking' ); ?></label>
				</fieldset>
				<p class="sbk-field"><label for="sbk-policy"><?php esc_html_e( 'Policy shown before booking and in every email', 'scout-booking' ); ?></label><textarea id="sbk-policy" name="sb[policy]" rows="2" class="large-text" placeholder="<?php echo esc_attr( Scout_Booking::policy() ); ?>"><?php echo esc_textarea( $s['policy'] ); ?></textarea><span class="description"><?php esc_html_e( 'Empty uses the line shown, worked out from the window.', 'scout-booking' ); ?></span></p>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'When people can book', 'scout-booking' ); ?></h2>
				<?php
				$num( 'notice_hours', __( 'Shortest notice, in hours', 'scout-booking' ), __( 'The soonest a session can start after someone books it.', 'scout-booking' ), 0, 720, $s['notice_hours'] );
				$num( 'days_ahead', __( 'Furthest ahead, in days', 'scout-booking' ), __( 'How far into the future the calendar opens.', 'scout-booking' ), 1, 365, $s['days_ahead'] );
				?>
				<p class="sbk-field"><label for="sbk-step"><?php esc_html_e( 'Start times every', 'scout-booking' ); ?></label>
					<select id="sbk-step" name="sb[step]">
						<?php foreach ( array( 15, 30, 60 ) as $m ) : ?>
							<option value="<?php echo (int) $m; ?>" <?php selected( $s['step'], $m ); ?>><?php echo esc_html( Scout_Booking::length_label( $m ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php
				$num( 'hold_minutes', __( 'Hold a time during checkout, in minutes', 'scout-booking' ), __( 'Nobody else can book it while the client pays.', 'scout-booking' ), 10, 240, $s['hold_minutes'] );
				$num( 'pay_hours', __( 'Approved requests stay held for, in hours', 'scout-booking' ), __( 'After this, an approved request that was not paid is released and the client is told.', 'scout-booking' ), 1, 336, $s['pay_hours'] );
				?>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Booking hours', 'scout-booking' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						Scout_Booking::scout_hours_on()
							? __( 'Sessions follow your business hours from Scout Hours, holiday closures included. These hours are used only if Scout Hours is switched off. A session type can also have its own hours.', 'scout-booking' )
							: __( 'The days and hours people can book. When Scout Hours is on and its hours are confirmed, sessions follow those instead. A session type can also have its own hours.', 'scout-booking' )
					);
					?>
				</p>
				<?php Scout_Booking_Types_Admin::week_fields( 'sb[hours]', $s['hours'], 'sbk-h' ); ?>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Newsletter', 'scout-booking' ); ?></h2>
				<p class="sbk-check"><label><input type="checkbox" name="sb[optin]" value="1" <?php checked( $s['optin'] ); ?>> <?php esc_html_e( 'Offer the newsletter on the booking form (the box starts unticked).', 'scout-booking' ); ?></label></p>
				<p class="sbk-field"><label for="sbk-optin-label"><?php esc_html_e( 'Words beside the box', 'scout-booking' ); ?></label><input type="text" id="sbk-optin-label" name="sb[optin_label]" class="regular-text" value="<?php echo esc_attr( $s['optin_label'] ); ?>" placeholder="<?php esc_attr_e( 'Send me the newsletter', 'scout-booking' ); ?>"></p>
			</section>

			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'scout-booking' ); ?></button></p>
		</form>
		<?php
		self::feed_box();
		Scout_Admin_UI::close();
	}

	/* ---------- kit wrappers ---------- */

	/**
	 * The kit's status line, or the same markup when an older kit (before
	 * 1.10.0, without status()) is the copy that loaded first.
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

	/** The kit's closed "More options" box, or the same markup on an older kit. */
	private static function ui_fold_open( string $title, bool $open = false, string $id = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title, $open, $id );
			return;
		}
		echo '<details class="sh-box sh-fold"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $open ? ' open' : '' ) . '><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	/** Closes ui_fold_open(). */
	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

}
