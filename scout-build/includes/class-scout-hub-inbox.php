<?php
/**
 * The Inbox: one list of everything people send or that waits on a person
 * (dashboard redesign, Joy 2026-11-04).
 *
 * Chat questions, new reviews, change requests with their status, site check
 * requests and booking requests show as single items, newest first, with
 * filter chips by kind. Each item opens the screen where it gets handled.
 * Plugins add their own items with the scout_hub_inbox_items filter; the
 * counts for Home and the sidebar still come from scout_hub_inbox.
 *
 * Each person chooses how often they get an email about it: once a day, for
 * every new item, or never (the default, so nobody gets mail they did not ask for).
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Inbox {

	const PREF   = 'scout_hub_inbox_email';      // User meta: daily | every | never.
	const SENT   = 'scout_hub_inbox_email_sent'; // User meta: time of the last email.
	const HOOK   = 'scout_hub_inbox_digest';
	const ACTION = 'scout_hub_inbox_pref';

	/** User meta: item id => time it comes back ("Remind me tomorrow"). */
	const LATER = 'scout_hub_inbox_later';

	/** admin-post action for Send reply, Mark done and Remind me tomorrow. */
	const DO = 'scout_hub_inbox_do';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save_pref' ) );
		add_action( 'admin_post_' . self::DO, array( __CLASS__, 'act' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'digest' ) );
	}

	/** The kinds, in chip order. */
	public static function kinds(): array {
		return array(
			'chat'      => __( 'Chat', 'scout-build' ),
			'reviews'   => __( 'Reviews', 'scout-build' ),
			'requests'  => __( 'Requests', 'scout-build' ),
			'bookings'  => __( 'Bookings', 'scout-build' ),
			'sitecheck' => __( 'Check requests', 'scout-build' ),
			'other'     => __( 'Other', 'scout-build' ),
		);
	}

	/** A short preview: plain text, one or two lines. */
	private static function preview( string $text ): string {
		return wp_trim_words( wp_strip_all_tags( $text ), 24, '…' );
	}

	/**
	 * Every item waiting on a person, newest first. Done change requests from
	 * the last two weeks are included so the client sees them finished; they
	 * never count as waiting.
	 *
	 * Item: id, kind, label (the small line above), from, preview, when (timestamp), url, waiting (bool).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function items(): array {
		$items = array();

		foreach ( Scout_Hub_Requests::all() as $post ) {
			$status = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_STATUS, true );
			$status = isset( Scout_Hub_Requests::STATUSES[ $status ] ) ? $status : 'received';
			$when   = (int) get_post_time( 'U', true, $post );
			if ( 'done' === $status && $when < time() - 14 * DAY_IN_SECONDS ) {
				continue;
			}
			$items[] = array(
				'id'      => 'request-' . $post->ID,
				'kind'    => 'requests',
				/* translators: %s: request status, for example "In progress" */
				'label'   => sprintf( __( 'Your request · %s', 'scout-build' ), __( Scout_Hub_Requests::STATUSES[ $status ], 'scout-build' ) ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- fixed status words.
				'from'    => get_the_title( $post ),
				'preview' => self::preview( (string) $post->post_content ),
				'when'    => $when,
				'url'     => Scout_Hub_Admin::url( 'requests' ),
				'waiting' => 'done' !== $status,
				'body'    => wp_strip_all_tags( (string) $post->post_content ),
			);
		}

		if ( class_exists( 'Scout_Chat' ) && post_type_exists( Scout_Chat::CPT ) ) {
			$posts = get_posts(
				array(
					'post_type'      => Scout_Chat::CPT,
					'post_status'    => 'private',
					// Unanswered, leaving out chat change requests: those show once, as the request above.
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_scout_chat_answered',
							'value' => '0',
						),
						array(
							'key'     => '_scout_chat_request',
							'compare' => 'NOT EXISTS',
						),
					),
					'posts_per_page' => 50,
				)
			);
			foreach ( $posts as $post ) {
				$name    = trim( (string) get_post_meta( $post->ID, '_scout_chat_name', true ) );
				$email   = trim( (string) get_post_meta( $post->ID, '_scout_chat_email', true ) );
				$items[] = array(
					'id'      => 'chat-' . $post->ID,
					'kind'    => 'chat',
					'label'   => __( 'Chat message', 'scout-build' ),
					'from'    => '' !== $name ? $name : ( '' !== $email ? $email : __( 'A visitor', 'scout-build' ) ),
					'preview' => self::preview( '' !== $post->post_content ? (string) $post->post_content : get_the_title( $post ) ),
					'when'    => (int) get_post_time( 'U', true, $post ),
					'url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'waiting' => true,
					'body'    => wp_strip_all_tags( '' !== $post->post_content ? (string) $post->post_content : get_the_title( $post ) ),
					'email'   => $email,
					'done'    => true,
				);
			}
		}

		if ( class_exists( 'Scout_Reviews_Post_Type' ) && class_exists( 'Scout_Reviews_Google_Sync' ) && defined( 'Scout_Reviews_Google_Sync::META_NEW' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => Scout_Reviews_Post_Type::TYPE,
					'post_status'    => 'draft',
					'meta_key'       => Scout_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
					'posts_per_page' => 50,
				)
			);
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'      => 'review-' . $post->ID,
					'kind'    => 'reviews',
					'label'   => __( 'New review', 'scout-build' ),
					'from'    => get_the_title( $post ),
					'preview' => self::preview( (string) $post->post_content ),
					'when'    => (int) get_post_time( 'U', true, $post ),
					'url'     => admin_url( 'admin.php?page=scout-reviews-inbox' ),
					'waiting' => true,
					'body'    => wp_strip_all_tags( (string) $post->post_content ),
				);
			}
		}

		if ( class_exists( 'Scout_Sitecheck' ) && post_type_exists( Scout_Sitecheck::CPT ) ) {
			$posts = get_posts(
				array(
					'post_type'      => Scout_Sitecheck::CPT,
					'post_status'    => 'private',
					'meta_key'       => '_ssc_followed', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
					'posts_per_page' => 50,
				)
			);
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'      => 'sitecheck-' . $post->ID,
					'kind'    => 'sitecheck',
					'label'   => __( 'Check request', 'scout-build' ),
					'from'    => get_the_title( $post ),
					'preview' => self::preview( (string) $post->post_content ),
					'when'    => (int) get_post_time( 'U', true, $post ),
					'url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'waiting' => true,
					'body'    => wp_strip_all_tags( (string) $post->post_content ),
					'email'   => sanitize_email( (string) get_post_meta( $post->ID, '_ssc_email', true ) ),
					'done'    => true,
				);
			}
		}

		if ( class_exists( 'Scout_Booking' ) && method_exists( 'Scout_Booking', 'with_status' ) ) {
			foreach ( Scout_Booking::with_status( 'requested', 50 ) as $row ) {
				$name    = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
				$start   = strtotime( (string) ( $row['start_at'] ?? '' ) );
				$items[] = array(
					'id'      => 'booking-' . (int) ( $row['id'] ?? 0 ),
					'kind'    => 'bookings',
					'label'   => __( 'Booking request', 'scout-build' ),
					'from'    => '' !== $name ? $name : __( 'Someone', 'scout-build' ),
					'preview' => trim( (string) ( $row['type_name'] ?? '' ) . ( $start ? ' · ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '' ) ),
					'when'    => (int) strtotime( (string) ( $row['created'] ?? 'now' ) ),
					'url'     => admin_url( 'admin.php?page=scout-booking' ),
					'waiting' => true,
					'body'    => trim( (string) ( $row['note'] ?? '' ) ),
					'email'   => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				);
			}
		}

		// Queues other plugins add as count rows (scout_hub_inbox), such as law-firm approvals,
		// show as one item each, so nothing that waits on a person drops out of the list.
		$built_in = array( __( 'Change requests', 'scout-build' ), __( 'Chat messages', 'scout-build' ), __( 'New reviews', 'scout-build' ) );
		foreach ( Scout_Hub_Admin::waiting_on_you() as $row ) {
			$count = (int) ( $row['count'] ?? 0 );
			if ( ! $count || in_array( (string) $row['label'], $built_in, true ) ) {
				continue;
			}
			$items[] = array(
				'id'      => 'queue-' . sanitize_title( (string) $row['label'] ),
				'kind'    => 'other',
				'label'   => (string) $row['label'],
				/* translators: %d: how many are waiting */
				'from'    => sprintf( _n( '%d waiting', '%d waiting', $count, 'scout-build' ), $count ),
				'preview' => (string) ( $row['note'] ?? '' ),
				'when'    => time(),
				'url'     => (string) $row['url'],
				'waiting' => true,
				'count'   => $count,
			);
		}

		/**
		 * Filters the Inbox items, so any plugin can add its own: a law-firm approval,
		 * a membership application, a volunteer sign-up.
		 *
		 * @param array $items Items of id, kind, label, from, preview, when, url, waiting.
		 */
		$items = (array) apply_filters( 'scout_hub_inbox_items', $items );
		$kinds = self::kinds();
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$kind    = (string) ( $item['kind'] ?? 'other' );
			$clean[] = array(
				'id'      => sanitize_key( (string) $item['id'] ),
				'kind'    => isset( $kinds[ $kind ] ) ? $kind : 'other',
				'label'   => (string) ( $item['label'] ?? '' ),
				'from'    => (string) ( $item['from'] ?? '' ),
				'preview' => (string) ( $item['preview'] ?? '' ),
				'when'    => (int) ( $item['when'] ?? 0 ),
				'url'     => (string) $item['url'],
				'waiting' => ! isset( $item['waiting'] ) || (bool) $item['waiting'],
				'count'   => max( 1, (int) ( $item['count'] ?? 1 ) ),
				'body'    => (string) ( $item['body'] ?? $item['preview'] ?? '' ),
				'email'   => is_email( (string) ( $item['email'] ?? '' ) ) ? (string) $item['email'] : '',
				'done'    => ! empty( $item['done'] ),
			);
		}
		usort( $clean, static fn( $a, $b ) => $b['when'] <=> $a['when'] );
		// "Remind me tomorrow" hides an item from that person until then. The digest (no one signed in) still sees it.
		$later = function_exists( 'get_current_user_id' ) && get_current_user_id() ? (array) get_user_meta( get_current_user_id(), self::LATER, true ) : array();
		if ( $later ) {
			$clean = array_values( array_filter( $clean, static fn( $i ) => (int) ( $later[ $i['id'] ] ?? 0 ) <= time() ) );
		}
		return $clean;
	}

	/**
	 * How many things wait on a person: the number on the sidebar bubble, the
	 * menu hint and the All chip. It is counted from the same list the
	 * Inbox shows, so the list and the bubble always agree. A count row from
	 * another plugin counts as its own number.
	 *
	 * @param array $items Items from items().
	 */
	public static function total( array $items ): int {
		return (int) array_sum( array_column( self::waiting( $items ), 'count' ) );
	}

	/**
	 * The waiting items grouped for Today on Home: one row per kind (label,
	 * count, url to that kind in the Inbox, note), and each Other item on its own.
	 *
	 * @param array $items Items from items().
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $items ): array {
		$names = array(
			'chat'      => array( __( 'Chat messages', 'scout-build' ), __( 'Chat messages waiting for a reply.', 'scout-build' ) ),
			'reviews'   => array( __( 'New reviews', 'scout-build' ), __( 'Reviews that arrived since you last looked. Pick the ones to show.', 'scout-build' ) ),
			'requests'  => array( __( 'Change requests', 'scout-build' ), __( 'Things you asked Scout for, with where each one stands.', 'scout-build' ) ),
			'bookings'  => array( __( 'Booking requests', 'scout-build' ), __( 'People waiting to hear if their time works.', 'scout-build' ) ),
			'sitecheck' => array( __( 'Check requests', 'scout-build' ), __( 'People waiting on a reply about their site check.', 'scout-build' ) ),
		);
		$rows = array();
		foreach ( self::waiting( $items ) as $item ) {
			$kind = $item['kind'];
			if ( ! isset( $names[ $kind ] ) ) {
				$rows[] = array( 'label' => $item['label'], 'count' => $item['count'], 'url' => $item['url'], 'note' => $item['preview'] );
				continue;
			}
			if ( ! isset( $rows[ $kind ] ) ) {
				$rows[ $kind ] = array( 'label' => $names[ $kind ][0], 'count' => 0, 'url' => Scout_Hub_Admin::url( 'inbox', array( 'show' => $kind ) ), 'note' => $names[ $kind ][1] );
			}
			$rows[ $kind ]['count'] += $item['count'];
		}
		return array_values( $rows );
	}

	/** Items still waiting on a person. */
	public static function waiting( array $items ): array {
		return array_values( array_filter( $items, static fn( $i ) => $i['waiting'] ) );
	}

	/* ---- Email ------------------------------------------------------- */

	public static function pref( int $user ): string {
		$p = (string) get_user_meta( $user, self::PREF, true );
		return in_array( $p, array( 'daily', 'every', 'never' ), true ) ? $p : 'never';
	}

	public static function save_pref(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-build' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$p = sanitize_key( (string) ( $_POST['pref'] ?? 'never' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		update_user_meta( get_current_user_id(), self::PREF, in_array( $p, array( 'daily', 'every', 'never' ), true ) ? $p : 'never' );
		update_user_meta( get_current_user_id(), self::SENT, time() ); // Start counting from now, so the first email is never a backlog.
		wp_safe_redirect( add_query_arg( 'saved', 'email', Scout_Hub_Admin::url( 'inbox' ) ) );
		exit;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/** Deactivation: stop the emails. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Hourly: "every" people get new items since their last email; "daily"
	 * people get one email a day, after 8am, when anything waits.
	 */
	public static function digest(): void {
		$users = get_users(
			array(
				'meta_key'     => self::PREF, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => array( 'daily', 'every' ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'IN',
				'fields'       => array( 'ID', 'user_email' ),
				'number'       => 50,
			)
		);
		if ( ! $users ) {
			return;
		}
		$hour = (int) wp_date( 'G' );
		foreach ( $users as $u ) {
			$id   = (int) $u->ID;
			$last = (int) get_user_meta( $id, self::SENT, true );
			$pref = self::pref( $id );
			if ( 'daily' === $pref && ( $hour < 8 || wp_date( 'Ymd', $last ) === wp_date( 'Ymd' ) ) ) {
				continue;
			}
			wp_set_current_user( $id ); // Each person only hears about what they can see.
			$items = self::waiting( self::items() );
			if ( 'every' === $pref ) {
				$items = array_values( array_filter( $items, static fn( $i ) => $i['when'] > $last ) );
			}
			if ( $items ) {
				self::send( (string) $u->user_email, $items );
			}
			update_user_meta( $id, self::SENT, time() );
		}
		wp_set_current_user( 0 );
	}

	/** One plain-text email listing the items. No brand names, so it suits white-label sites. */
	private static function send( string $to, array $items ): void {
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: how many */
		$subject = sprintf( _n( '%1$s: %2$d thing is waiting in To do', '%1$s: %2$d things are waiting in To do', count( $items ), 'scout-build' ), $site, count( $items ) );
		$lines   = array();
		foreach ( array_slice( $items, 0, 20 ) as $i ) {
			$lines[] = '- ' . $i['label'] . ': ' . $i['from'] . ( '' !== $i['preview'] ? ' (' . $i['preview'] . ')' : '' );
		}
		$body = implode( "\n", $lines ) . "\n\n" . __( 'Open To do:', 'scout-build' ) . ' ' . Scout_Hub_Admin::url( 'inbox' ) . "\n\n" . __( 'Change how often you get these emails on the To do screen.', 'scout-build' );
		wp_mail( $to, $subject, $body );
	}

	/* ---- The screen --------------------------------------------------- */

	/** Send reply, Mark done or Remind me tomorrow, from the open message. */
	public static function act(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-build' ) );
		}
		check_admin_referer( self::DO );
		$id   = sanitize_key( (string) wp_unslash( $_POST['item'] ?? '' ) );
		$do   = sanitize_key( (string) wp_unslash( $_POST['do'] ?? '' ) );
		$item = null;
		foreach ( self::items() as $i ) {
			if ( $i['id'] === $id ) {
				$item = $i;
			}
		}
		$msg  = 'gone';
		$next = $id;
		if ( $item && 'later' === $do ) {
			$later        = array_filter( (array) get_user_meta( get_current_user_id(), self::LATER, true ), static fn( $t ) => (int) $t > time() );
			$later[ $id ] = ( new DateTimeImmutable( 'tomorrow 08:00', wp_timezone() ) )->getTimestamp();
			update_user_meta( get_current_user_id(), self::LATER, $later );
			$msg  = 'later';
			$next = '';
		} elseif ( $item && 'done' === $do && $item['done'] ) {
			self::mark_done( $id );
			$msg  = 'done';
			$next = '';
		} elseif ( $item && 'reply' === $do && '' !== $item['email'] ) {
			$text = trim( sanitize_textarea_field( (string) wp_unslash( $_POST['reply'] ?? '' ) ) );
			if ( '' === $text ) {
				$msg = 'empty';
			} else {
				$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$me   = wp_get_current_user();
				/* translators: %s: site name */
				$sent = wp_mail( $item['email'], sprintf( __( 'Re: your message to %s', 'scout-build' ), $site ), $text, is_email( $me->user_email ) ? array( 'Reply-To: ' . $me->display_name . ' <' . $me->user_email . '>' ) : array() );
				if ( $sent && $item['done'] ) {
					self::mark_done( $id );
				}
				$msg  = $sent ? 'sent' : 'failed';
				$next = $sent ? '' : $id;
			}
		}
		$args = array( 'msg' => $msg );
		if ( '' !== $next ) {
			$args['item'] = $next;
		}
		wp_safe_redirect( Scout_Hub_Admin::url( 'inbox', $args ) );
		exit;
	}

	/** Mark a chat question answered or a site check request followed up. */
	private static function mark_done( string $id ): void {
		if ( preg_match( '/^chat-(\d+)$/', $id, $m ) ) {
			update_post_meta( (int) $m[1], '_scout_chat_answered', '1' );
		} elseif ( preg_match( '/^sitecheck-(\d+)$/', $id, $m ) ) {
			update_post_meta( (int) $m[1], '_ssc_followed', '1' );
		}
		do_action( 'scout_hub_inbox_done', $id );
	}

	/**
	 * The Inbox, as the dashboard canvas draws it (Joy, 2026-11-04): filter chips and
	 * Email me on top, the list on the left, the open message on the right with
	 * Send reply, Mark done and Remind me tomorrow.
	 */
	public static function render(): void {
		$items   = self::items();
		$waiting = self::waiting( $items );
		$kinds   = self::kinds();
		$counts  = array();
		foreach ( $waiting as $w ) {
			$counts[ $w['kind'] ] = ( $counts[ $w['kind'] ] ?? 0 ) + $w['count'];
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters and message flags only.
		$show = isset( $_GET['show'] ) ? sanitize_key( (string) $_GET['show'] ) : 'all';
		$want = isset( $_GET['item'] ) ? sanitize_key( (string) $_GET['item'] ) : '';
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( (string) $_GET['msg'] ) : '';
		// phpcs:enable
		$show = 'all' === $show || isset( $kinds[ $show ] ) ? $show : 'all';
		$list = 'all' === $show ? $items : array_values( array_filter( $items, static fn( $i ) => $i['kind'] === $show ) );
		$sel  = null;
		foreach ( $list as $i ) {
			if ( $i['id'] === $want ) {
				$sel = $i;
			}
		}
		if ( ! $sel ) {
			$open = array_values( array_filter( $list, static fn( $i ) => $i['waiting'] ) );
			$sel  = $open[0] ?? ( $list[0] ?? null );
		}
		$pref  = self::pref( get_current_user_id() );
		$dots  = array(
			'chat'      => 'border-radius:50%;background:var(--sh-data-1)',
			'reviews'   => 'border-radius:2px;background:var(--sh-data-4);transform:rotate(45deg)',
			'requests'  => 'border-radius:50%;background:var(--sh-data-2)',
			'bookings'  => 'border-radius:2px;background:var(--sh-data-5)',
			'sitecheck' => 'border-radius:50%;border:2px solid var(--sh-data-1);box-sizing:border-box',
			'other'     => 'border-radius:50%;background:var(--sh-text-3)',
		);
		$notes = array(
			'sent'   => array( 'success', __( 'Sent. Your reply went to the email they left, and the message is marked done.', 'scout-build' ) ),
			'done'   => array( 'success', __( 'Marked done.', 'scout-build' ) ),
			'later'  => array( 'success', __( "Moved to tomorrow morning. It comes back on your list then.", 'scout-build' ) ),
			'empty'  => array( 'warning', __( 'Write a reply first, then send it.', 'scout-build' ) ),
			'failed' => array( 'error', __( "The reply didn't send. Check Scout tools, Email, then try again.", 'scout-build' ) ),
			'gone'   => array( 'warning', __( 'That message was already handled.', 'scout-build' ) ),
		);
		$ago   = static fn( int $t ) => $t ? sprintf( /* translators: %s: time, like "3 hours" */ __( '%s ago', 'scout-build' ), human_time_diff( $t ) ) : '';
		$base  = 'all' === $show ? array() : array( 'show' => $show );
		?>
		<style>
			.sh-inbox-top{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:8px}
			.sh-chips{display:flex;flex-wrap:wrap;gap:8px;flex:1 1 auto}
			.sh .sh-chips a{display:inline-flex;align-items:center;min-height:var(--sh-tap);padding:0 16px;border-radius:var(--sh-r-pill);border:1px solid var(--sh-border-strong);background:var(--sh-surface);text-decoration:none;color:var(--sh-text);font-size:15px;font-weight:500}
			.sh .sh-chips a[aria-current="page"]{background:var(--sh-accent-fill);border-color:var(--sh-accent-fill);color:var(--sh-on-accent)}
			.sh-inbox-pref{display:flex;align-items:center;gap:8px;font-size:15px;color:var(--sh-text-2)}
			.sh-panes{display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start;margin-top:18px}
			.sh-inbox-list{list-style:none;margin:0;padding:0;flex:1 1 340px;min-width:0;background:var(--sh-surface);border:1px solid var(--sh-border);border-radius:var(--sh-r-card);overflow:hidden}
			.sh-inbox-list li{border-bottom:1px solid var(--sh-border);margin:0}
			.sh .sh-inbox-list a{display:flex;flex-direction:column;gap:6px;padding:16px 20px;text-decoration:none;color:var(--sh-text)}
			.sh .sh-inbox-list a:hover{background:var(--sh-bg)}
			.sh .sh-inbox-list a[aria-current="true"]{background:rgba(14,143,230,.06);box-shadow:inset 3px 0 0 var(--sh-accent)}
			.sh-inbox-list a.is-done{opacity:.7}
			.sh-inbox-meta{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--sh-text-2)}
			.sh-inbox-meta .sh-k{font-family:var(--sh-mono);font-size:11px;font-weight:500;letter-spacing:1.2px;text-transform:uppercase}
			.sh-inbox-meta .sh-w{margin-left:auto}
			.sh-inbox-dot{width:8px;height:8px;flex:none}
			.sh-inbox-list b{font-size:16px;font-weight:600}
			.sh-inbox-list .sh-p{font-size:15px;color:var(--sh-text-2);line-height:1.45;overflow-wrap:anywhere}
			.sh-inbox-foot{padding:14px 20px;font-size:14px;color:var(--sh-text-2)}
			.sh-open{flex:2 1 460px;min-width:0;background:var(--sh-surface);border:1px solid var(--sh-border);border-radius:var(--sh-r-card);padding:28px;display:flex;flex-direction:column;gap:18px}
			.sh-open-top{display:flex;flex-wrap:wrap;align-items:center;gap:10px;color:var(--sh-text-2)}
			.sh-open-top .sh-k{font-family:var(--sh-mono);font-size:12px;font-weight:500;letter-spacing:1.4px;text-transform:uppercase}
			.sh-open-top .sh-w{margin-left:auto;font-size:14px}
			.sh .sh-open h2{margin:0;font-size:26px;font-weight:600;letter-spacing:-.02em;line-height:1.2}
			.sh-open .sh-body-text{margin:0;font-size:18px;line-height:1.55;overflow-wrap:anywhere}
			.sh-open .sh-meta-line{margin:0;font-size:14px;color:var(--sh-text-2)}
			.sh-open label{font-size:15px;font-weight:600}
			.sh .sh-open textarea{width:100%;font-size:16px}
			.sh-open-acts{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
			.sh-open-acts form{margin:0}
			.sh-calm{padding:40px 32px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px}
		</style>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only. ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Your email setting is updated.', 'scout-build' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $notes[ $msg ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notes[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notes[ $msg ][1] ); ?></p></div>
		<?php endif; ?>
		<div class="sh-inbox-top">
			<nav class="sh-chips" aria-label="<?php esc_attr_e( 'Show', 'scout-build' ); ?>">
				<a href="<?php echo esc_url( Scout_Hub_Admin::url( 'inbox' ) ); ?>"<?php echo 'all' === $show ? ' aria-current="page"' : ''; ?>><?php echo esc_html( sprintf( /* translators: %d: waiting */ __( 'All · %d', 'scout-build' ), self::total( $items ) ) ); ?></a>
				<?php foreach ( $kinds as $key => $label ) : ?>
					<?php
					if ( ! array_filter( $items, static fn( $i ) => $i['kind'] === $key ) ) {
						continue; // Only kinds that have something show a chip.
					}
					?>
					<a href="<?php echo esc_url( Scout_Hub_Admin::url( 'inbox', array( 'show' => $key ) ) ); ?>"<?php echo $show === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label . ' · ' . (int) ( $counts[ $key ] ?? 0 ) ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inbox-pref">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label for="sh-inbox-pref"><?php esc_html_e( 'Email me', 'scout-build' ); ?></label>
				<select id="sh-inbox-pref" name="pref" onchange="this.form.submit()">
					<option value="daily" <?php selected( $pref, 'daily' ); ?>><?php esc_html_e( 'Once a day', 'scout-build' ); ?></option>
					<option value="every" <?php selected( $pref, 'every' ); ?>><?php esc_html_e( 'For every message', 'scout-build' ); ?></option>
					<option value="never" <?php selected( $pref, 'never' ); ?>><?php esc_html_e( 'Never', 'scout-build' ); ?></option>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Save', 'scout-build' ); ?></button></noscript>
			</form>
		</div>

		<?php if ( ! $list || ! $sel ) : ?>
			<div class="sh-box sh-calm">
				<p style="margin:0;font-size:22px;font-weight:600"><?php esc_html_e( 'Nothing is waiting', 'scout-build' ); ?></p>
				<p class="sh-note" style="margin:0;font-size:16px"><?php esc_html_e( 'Chat messages, reviews, bookings and your requests show up here as they come in.', 'scout-build' ); ?></p>
			</div>
		<?php else : ?>
			<div class="sh-panes">
				<ul class="sh-inbox-list" aria-label="<?php esc_attr_e( 'Messages', 'scout-build' ); ?>">
					<?php foreach ( $list as $i ) : ?>
						<li>
							<a href="<?php echo esc_url( Scout_Hub_Admin::url( 'inbox', $base + array( 'item' => $i['id'] ) ) ); ?>" class="<?php echo $i['waiting'] ? '' : 'is-done'; ?>"<?php echo $i['id'] === $sel['id'] ? ' aria-current="true"' : ''; ?>>
								<span class="sh-inbox-meta"><span class="sh-inbox-dot" style="<?php echo esc_attr( $dots[ $i['kind'] ] ); ?>" aria-hidden="true"></span><span class="sh-k"><?php echo esc_html( $i['label'] ); ?></span><span class="sh-w"><?php echo esc_html( $ago( $i['when'] ) ); ?></span></span>
								<b><?php echo esc_html( $i['from'] ); ?></b>
								<?php if ( '' !== $i['preview'] ) : ?><span class="sh-p"><?php echo esc_html( $i['preview'] ); ?></span><?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
					<li class="sh-inbox-foot">
						<?php
						$left = count( self::waiting( $list ) );
						/* translators: %d: items still waiting */
						echo esc_html( $left ? sprintf( _n( '%d still waiting.', '%d still waiting.', $left, 'scout-build' ), $left ) : __( 'Everything here is handled.', 'scout-build' ) );
						?>
					</li>
				</ul>

				<section class="sh-open" aria-label="<?php esc_attr_e( 'Open message', 'scout-build' ); ?>">
					<div class="sh-open-top">
						<span class="sh-inbox-dot" style="width:10px;height:10px;<?php echo esc_attr( $dots[ $sel['kind'] ] ); ?>" aria-hidden="true"></span>
						<span class="sh-k"><?php echo esc_html( $sel['label'] ); ?></span>
						<span class="sh-w"><?php echo esc_html( $ago( $sel['when'] ) ); ?></span>
					</div>
					<h2><?php echo esc_html( $sel['from'] ); ?></h2>
					<?php if ( '' !== $sel['body'] ) : ?>
						<p class="sh-body-text"><?php echo nl2br( esc_html( $sel['body'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped first. ?></p>
					<?php endif; ?>
					<?php if ( '' !== $sel['email'] ) : ?>
						<p class="sh-meta-line"><?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'They left %s for a reply.', 'scout-build' ), $sel['email'] ) ); ?></p>
					<?php endif; ?>

					<?php if ( $sel['waiting'] && '' !== $sel['email'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-direction:column;gap:8px">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::DO ); ?>">
							<input type="hidden" name="item" value="<?php echo esc_attr( $sel['id'] ); ?>">
							<?php wp_nonce_field( self::DO ); ?>
							<label for="sh-reply"><?php esc_html_e( 'Your reply', 'scout-build' ); ?></label>
							<textarea id="sh-reply" name="reply" rows="4" placeholder="<?php esc_attr_e( 'Write your reply. It goes to the email they left.', 'scout-build' ); ?>"></textarea>
							<div class="sh-open-acts">
								<button type="submit" name="do" value="reply" class="button button-primary"><?php esc_html_e( 'Send reply', 'scout-build' ); ?></button>
								<?php if ( $sel['done'] ) : ?><button type="submit" name="do" value="done" class="button"><?php esc_html_e( 'Mark done', 'scout-build' ); ?></button><?php endif; ?>
								<button type="submit" name="do" value="later" class="button sh-quiet"><?php esc_html_e( 'Remind me tomorrow', 'scout-build' ); ?></button>
							</div>
						</form>
					<?php elseif ( $sel['waiting'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-open-acts">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::DO ); ?>">
							<input type="hidden" name="item" value="<?php echo esc_attr( $sel['id'] ); ?>">
							<?php wp_nonce_field( self::DO ); ?>
							<a class="button button-primary" href="<?php echo esc_url( $sel['url'] ); ?>"><?php esc_html_e( 'Open', 'scout-build' ); ?></a>
							<?php if ( $sel['done'] ) : ?><button type="submit" name="do" value="done" class="button"><?php esc_html_e( 'Mark done', 'scout-build' ); ?></button><?php endif; ?>
							<button type="submit" name="do" value="later" class="button sh-quiet"><?php esc_html_e( 'Remind me tomorrow', 'scout-build' ); ?></button>
						</form>
					<?php else : ?>
						<p class="sh-meta-line"><?php esc_html_e( 'This one is done. It leaves the list after two weeks.', 'scout-build' ); ?></p>
					<?php endif; ?>
					<p class="sh-note" style="margin:0"><a href="<?php echo esc_url( $sel['url'] ); ?>"><?php esc_html_e( 'Open it on its own screen', 'scout-build' ); ?> &rsaquo;</a></p>
				</section>
			</div>
		<?php endif; ?>
		<?php if ( class_exists( 'Scout_Reviews_Requests' ) ) : ?>
			<p class="sh-note" style="margin-top:14px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=scout-reviews-ask' ) ); ?>"><?php esc_html_e( 'Ask a client for a review', 'scout-build' ); ?> &rsaquo;</a></p>
		<?php endif; ?>
		<?php
	}
}
