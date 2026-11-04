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

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save_pref' ) );
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
			'sitecheck' => __( 'Site checks', 'scout-build' ),
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
			);
		}

		if ( class_exists( 'Scout_Chat' ) && post_type_exists( Scout_Chat::CPT ) ) {
			$posts = get_posts(
				array(
					'post_type'      => Scout_Chat::CPT,
					'post_status'    => 'private',
					'meta_key'       => '_scout_chat_answered', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
					'posts_per_page' => 50,
				)
			);
			foreach ( $posts as $post ) {
				$name    = trim( (string) get_post_meta( $post->ID, '_scout_chat_name', true ) );
				$email   = trim( (string) get_post_meta( $post->ID, '_scout_chat_email', true ) );
				$items[] = array(
					'id'      => 'chat-' . $post->ID,
					'kind'    => 'chat',
					'label'   => __( 'Chat question', 'scout-build' ),
					'from'    => '' !== $name ? $name : ( '' !== $email ? $email : __( 'A visitor', 'scout-build' ) ),
					'preview' => self::preview( '' !== $post->post_content ? (string) $post->post_content : get_the_title( $post ) ),
					'when'    => (int) get_post_time( 'U', true, $post ),
					'url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'waiting' => true,
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
					'label'   => __( 'Site check request', 'scout-build' ),
					'from'    => get_the_title( $post ),
					'preview' => self::preview( (string) $post->post_content ),
					'when'    => (int) get_post_time( 'U', true, $post ),
					'url'     => (string) get_edit_post_link( $post->ID, 'raw' ),
					'waiting' => true,
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
				);
			}
		}

		// Queues other plugins add as count rows (scout_hub_inbox), such as law-firm approvals,
		// show as one item each, so nothing that waits on a person drops out of the list.
		$built_in = array( __( 'Change requests', 'scout-build' ), __( 'Chat questions', 'scout-build' ), __( 'New reviews', 'scout-build' ) );
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
			);
		}
		usort( $clean, static fn( $a, $b ) => $b['when'] <=> $a['when'] );
		return $clean;
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
		$subject = sprintf( _n( '%1$s: %2$d thing is waiting in your Inbox', '%1$s: %2$d things are waiting in your Inbox', count( $items ), 'scout-build' ), $site, count( $items ) );
		$lines   = array();
		foreach ( array_slice( $items, 0, 20 ) as $i ) {
			$lines[] = '- ' . $i['label'] . ': ' . $i['from'] . ( '' !== $i['preview'] ? ' (' . $i['preview'] . ')' : '' );
		}
		$body = implode( "\n", $lines ) . "\n\n" . __( 'Open your Inbox:', 'scout-build' ) . ' ' . Scout_Hub_Admin::url( 'inbox' ) . "\n\n" . __( 'Change how often you get these emails on the Inbox screen.', 'scout-build' );
		wp_mail( $to, $subject, $body );
	}

	/* ---- The screen --------------------------------------------------- */

	public static function render(): void {
		$items   = self::items();
		$waiting = self::waiting( $items );
		$kinds   = self::kinds();
		$counts  = array_count_values( array_column( $waiting, 'kind' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter only.
		$show = isset( $_GET['show'] ) ? sanitize_key( (string) $_GET['show'] ) : 'all';
		$show = 'all' === $show || isset( $kinds[ $show ] ) ? $show : 'all';
		$list = 'all' === $show ? $items : array_values( array_filter( $items, static fn( $i ) => $i['kind'] === $show ) );
		$pref = self::pref( get_current_user_id() );
		$dots = array(
			'chat'      => 'border-radius:50%;background:#0E8FE6',
			'reviews'   => 'border-radius:2px;background:#FBB024;transform:rotate(45deg)',
			'requests'  => 'border-radius:50%;background:#12B5A6',
			'bookings'  => 'border-radius:2px;background:#7B6CF0',
			'sitecheck' => 'border-radius:50%;border:2px solid #0E8FE6;box-sizing:border-box',
			'other'     => 'border-radius:50%;background:#86868B',
		);
		?>
		<style>
			.sh-inbox-top{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px}
			.sh-chips{display:flex;flex-wrap:wrap;gap:8px;flex:1 1 auto}
			.sh-chips a{display:inline-flex;align-items:center;min-height:40px;padding:0 14px;border-radius:980px;border:1px solid var(--sh-border);text-decoration:none;color:var(--sh-text);font-weight:500}
			.sh-chips a[aria-current="page"]{background:var(--sh-accent-fill,#0A78C2);border-color:var(--sh-accent-fill,#0A78C2);color:#fff}
			.sh-inbox-list{list-style:none;margin:12px 0 0;padding:0;border:1px solid var(--sh-border);border-radius:18px;overflow:hidden;background:var(--sh-surface)}
			.sh-inbox-item{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;padding:16px 20px;border-bottom:1px solid var(--sh-border)}
			.sh-inbox-item:last-child{border-bottom:0}
			.sh-inbox-item.is-done{opacity:.7}
			.sh-inbox-text{flex:1 1 320px;display:grid;gap:3px;min-width:0}
			.sh-inbox-meta{display:flex;align-items:center;gap:8px;font:500 11px var(--sh-mono);letter-spacing:1.1px;text-transform:uppercase;color:var(--sh-text-2)}
			.sh-inbox-dot{width:9px;height:9px;flex:none}
			.sh-inbox-text b{font-size:16px}
			.sh-inbox-text span.sh-note{overflow-wrap:anywhere}
			.sh-inbox-when{font-size:13px;color:var(--sh-text-2);white-space:nowrap}
			.sh-inbox-calm{padding:34px 22px;text-align:center}
		</style>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only. ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Your email setting is updated.', 'scout-build' ); ?></p></div>
		<?php endif; ?>
		<div class="sh-inbox-top">
			<nav class="sh-chips" aria-label="<?php esc_attr_e( 'Show', 'scout-build' ); ?>">
				<a href="<?php echo esc_url( Scout_Hub_Admin::url( 'inbox' ) ); ?>"<?php echo 'all' === $show ? ' aria-current="page"' : ''; ?>><?php echo esc_html( sprintf( /* translators: %d: waiting */ __( 'All · %d', 'scout-build' ), count( $waiting ) ) ); ?></a>
				<?php foreach ( $kinds as $key => $label ) : ?>
					<?php
					$has = (bool) array_filter( $items, static fn( $i ) => $i['kind'] === $key );
					if ( ! $has ) {
						continue; // Only kinds that have something show a chip.
					}
					?>
					<a href="<?php echo esc_url( Scout_Hub_Admin::url( 'inbox', array( 'show' => $key ) ) ); ?>"<?php echo $show === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label . ' · ' . (int) ( $counts[ $key ] ?? 0 ) ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label for="sh-inbox-pref"><?php esc_html_e( 'Email me', 'scout-build' ); ?></label>
				<select id="sh-inbox-pref" name="pref">
					<option value="never" <?php selected( $pref, 'never' ); ?>><?php esc_html_e( 'Never', 'scout-build' ); ?></option>
					<option value="daily" <?php selected( $pref, 'daily' ); ?>><?php esc_html_e( 'Once a day', 'scout-build' ); ?></option>
					<option value="every" <?php selected( $pref, 'every' ); ?>><?php esc_html_e( 'For every new item', 'scout-build' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'scout-build' ); ?></button>
			</form>
		</div>

		<?php if ( ! $list ) : ?>
			<div class="sh-box sh-inbox-calm">
				<p style="margin:0;font-size:19px;font-weight:600"><?php esc_html_e( 'Nothing is waiting', 'scout-build' ); ?></p>
				<p class="sh-note" style="margin:6px 0 0"><?php esc_html_e( 'Chat questions, reviews, bookings and your change requests show up here as they come in.', 'scout-build' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="sh-inbox-list" aria-label="<?php esc_attr_e( 'Inbox', 'scout-build' ); ?>">
				<?php foreach ( $list as $i ) : ?>
					<li class="sh-inbox-item<?php echo $i['waiting'] ? '' : ' is-done'; ?>">
						<div class="sh-inbox-text">
							<span class="sh-inbox-meta"><span class="sh-inbox-dot" style="<?php echo esc_attr( $dots[ $i['kind'] ] ); ?>" aria-hidden="true"></span><?php echo esc_html( $i['label'] ); ?></span>
							<b><?php echo esc_html( $i['from'] ); ?></b>
							<?php if ( '' !== $i['preview'] ) : ?><span class="sh-note"><?php echo esc_html( $i['preview'] ); ?></span><?php endif; ?>
						</div>
						<?php if ( $i['when'] ) : ?>
							<span class="sh-inbox-when">
								<?php
								/* translators: %s: time, like "3 hours" */
								echo esc_html( sprintf( __( '%s ago', 'scout-build' ), human_time_diff( $i['when'] ) ) );
								?>
							</span>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( $i['url'] ); ?>"><?php esc_html_e( 'Open', 'scout-build' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( class_exists( 'Scout_Reviews_Requests' ) ) : ?>
			<p class="sh-note" style="margin-top:12px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=scout-reviews-ask' ) ); ?>"><?php esc_html_e( 'Ask a client for a review', 'scout-build' ); ?> &rsaquo;</a></p>
		<?php endif; ?>
		<?php
	}
}
