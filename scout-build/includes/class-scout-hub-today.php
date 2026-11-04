<?php
/**
 * Today: the numbered list at the top of Home (dashboard redesign, Joy 2026-11-04).
 *
 * One list of what needs this person today, each with about how long it
 * takes and one button. It is built from the Inbox rows, a failing health
 * check, a site hidden from Google, and anything a plugin adds with the
 * scout_hub_today filter. Progress ("1 of 4 done") counts what was on the
 * list earlier today and is gone now. "Not today" hides an item until
 * tomorrow. When nothing is left, Home says so calmly.
 *
 * New sites also get the first-week setup list: five facts the site needs
 * from its owner, in order, until they are all done or an operator hides it.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Today {

	const SNOOZE  = 'scout_hub_today_snoozed'; // User meta: id => Ymd it comes back.
	const SEEN    = 'scout_hub_today_seen';    // User meta: [ day => Ymd, ids => [ id => label ] ].
	const ACTION  = 'scout_hub_today';
	const SETUP   = 'scout_hub_setup_hidden';  // Option: an operator hid the first-week list.

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Everything that could be on Today, before "Not today" and progress.
	 *
	 * Each item: id (stable), label, why (one plain sentence), minutes, url,
	 * button, priority (lower comes first), cap (who sees it).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$items = array();
		if ( class_exists( 'Scout_Hub_Watch' ) && Scout_Hub_Watch::hidden() ) {
			$items[] = array(
				'id'       => 'hidden-from-google',
				'label'    => __( 'Let Google see the site again', 'scout-build' ),
				'why'      => __( 'A WordPress setting is hiding the live site from search engines.', 'scout-build' ),
				'minutes'  => 1,
				'url'      => admin_url( 'options-reading.php' ),
				'button'   => __( 'Fix it now', 'scout-build' ),
				'priority' => 0,
				'cap'      => 'manage_options',
			);
		}
		$minutes = array(
			'Change requests'     => 2,
			'Chat questions'      => 3,
			'New reviews'         => 2,
			'Booking requests'    => 3,
			'Site check requests' => 5,
		);
		// The same list the Inbox shows, so Today, the bubble and the Inbox agree.
		$inbox = class_exists( 'Scout_Hub_Inbox' ) ? Scout_Hub_Inbox::rows( Scout_Hub_Inbox::items() ) : Scout_Hub_Admin::waiting_on_you();
		foreach ( $inbox as $row ) {
			$count = (int) ( $row['count'] ?? 0 );
			if ( ! $count ) {
				continue;
			}
			$label   = (string) $row['label'];
			$items[] = array(
				'id'       => 'inbox-' . sanitize_title( $label ),
				/* translators: 1: what (for example "Chat questions"), 2: how many */
				'label'    => sprintf( __( '%1$s (%2$d)', 'scout-build' ), $label, $count ),
				'why'      => (string) ( $row['note'] ?? '' ),
				'minutes'  => max( 1, (int) ( $minutes[ $label ] ?? 3 ) ) * min( $count, 5 ),
				'url'      => (string) $row['url'],
				'button'   => __( 'Open', 'scout-build' ),
				'priority' => 20,
				'cap'      => 'edit_posts',
			);
		}
		foreach ( Scout_Hub_Health::checks() as $check ) {
			if ( 'bad' !== ( $check['state'] ?? '' ) ) {
				continue;
			}
			$items[] = array(
				'id'       => 'health-' . sanitize_title( (string) $check['label'] ),
				/* translators: %s: the health check that failed */
				'label'    => sprintf( __( 'Fix: %s', 'scout-build' ), (string) $check['label'] ),
				'why'      => (string) ( $check['note'] ?? '' ),
				'minutes'  => 5,
				'url'      => Scout_Hub_Admin::url( 'health' ),
				'button'   => __( 'See what to do', 'scout-build' ),
				'priority' => 10,
				'cap'      => 'manage_options',
			);
		}
		/**
		 * Filters the Today list, so any plugin can add an item: law-firm approvals,
		 * pages due for review, a booking to confirm.
		 *
		 * @param array $items Items of id, label, why, minutes, url, button, priority, cap.
		 */
		$items = (array) apply_filters( 'scout_hub_today', $items );
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['label'] ) || empty( $item['url'] ) ) {
				continue;
			}
			if ( ! current_user_can( (string) ( $item['cap'] ?? 'edit_posts' ) ) ) {
				continue;
			}
			$clean[] = array(
				'id'       => sanitize_key( (string) $item['id'] ),
				'label'    => (string) $item['label'],
				'why'      => (string) ( $item['why'] ?? '' ),
				'minutes'  => max( 1, (int) ( $item['minutes'] ?? 3 ) ),
				'url'      => (string) $item['url'],
				'button'   => (string) ( $item['button'] ?? __( 'Open', 'scout-build' ) ),
				'priority' => (int) ( $item['priority'] ?? 50 ),
			);
		}
		usort( $clean, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		return $clean;
	}

	/** Today's date for this site, as Ymd. */
	private static function day(): string {
		return wp_date( 'Ymd' );
	}

	/** Items hidden with "Not today" that have not come back yet. */
	private static function snoozed( int $user ): array {
		$s   = get_user_meta( $user, self::SNOOZE, true );
		$s   = is_array( $s ) ? $s : array();
		$now = self::day();
		return array_filter( $s, static fn( $until ) => (string) $until > $now );
	}

	/**
	 * The list for this person now, plus what they finished earlier today.
	 *
	 * @return array{items:array,done:array,snoozed:int}
	 */
	public static function for_user( int $user ): array {
		$all     = self::all();
		$snoozed = self::snoozed( $user );
		$items   = array_values( array_filter( $all, static fn( $i ) => ! isset( $snoozed[ $i['id'] ] ) ) );

		// What was on the list earlier today and is gone now counts as done.
		$seen = get_user_meta( $user, self::SEEN, true );
		$seen = is_array( $seen ) && ( $seen['day'] ?? '' ) === self::day() ? (array) ( $seen['ids'] ?? array() ) : array();
		$now  = array_column( $all, 'label', 'id' );
		$done = array_diff_key( $seen, $now, $snoozed );
		update_user_meta( $user, self::SEEN, array( 'day' => self::day(), 'ids' => $seen + $now ) );

		return array(
			'items'   => $items,
			'done'    => $done,
			'snoozed' => count( $snoozed ),
		);
	}

	/* ---- First-week setup --------------------------------------------- */

	/**
	 * The five facts a new site needs from its owner, in order. Each step: id,
	 * label, done, minutes, url. Steps for features the site does not run are left out.
	 */
	public static function setup_steps(): array {
		$steps = array();
		if ( class_exists( 'Scout_Core_Business' ) ) {
			$b       = Scout_Core_Business::get();
			$steps[] = array(
				'id'      => 'business',
				'label'   => __( 'Check your business name, address and phone', 'scout-build' ),
				'done'    => '' !== trim( (string) $b['name'] ) && '' !== trim( (string) $b['phone'] ),
				'minutes' => 3,
				'url'     => admin_url( 'admin.php?page=scout&tab=business' ),
			);
		}
		if ( class_exists( 'Scout_Hours' ) ) {
			$steps[] = array(
				'id'      => 'hours',
				'label'   => __( 'Add your weekly hours', 'scout-build' ),
				'done'    => Scout_Hours::is_public(),
				'minutes' => 2,
				'url'     => admin_url( 'admin.php?page=scout-hours' ),
			);
		}
		$steps[] = array(
			'id'      => 'logo',
			'label'   => __( 'Upload your logo and site icon', 'scout-build' ),
			'done'    => (bool) get_theme_mod( 'custom_logo' ) || (bool) get_option( 'site_icon' ),
			'minutes' => 5,
			'url'     => admin_url( 'customize.php?autofocus[section]=title_tagline' ),
		);
		if ( post_type_exists( 'scout_team' ) ) {
			$count   = wp_count_posts( 'scout_team' );
			$steps[] = array(
				'id'      => 'team',
				'label'   => __( 'Add your team', 'scout-build' ),
				'done'    => (int) ( $count->publish ?? 0 ) > 0,
				'minutes' => 10,
				'url'     => admin_url( 'edit.php?post_type=scout_team' ),
			);
		}
		if ( class_exists( 'Scout_Core_Business' ) ) {
			$same    = (string) Scout_Core_Business::get()['same_as'];
			$steps[] = array(
				'id'      => 'google',
				'label'   => __( 'Link your Google Business Profile', 'scout-build' ),
				'done'    => (bool) preg_match( '#(g\.page|google\.com/maps|maps\.app\.goo\.gl|business\.google\.com)#i', $same ),
				'minutes' => 3,
				'url'     => admin_url( 'admin.php?page=scout&tab=business' ),
			);
		}
		/** Filters the first-week setup steps. */
		return (array) apply_filters( 'scout_hub_setup_steps', $steps );
	}

	/** Show the first-week list: owners only, until every step is done or an operator hides it. */
	public static function show_setup(): bool {
		if ( ! current_user_can( 'manage_options' ) || get_option( self::SETUP ) ) {
			return false;
		}
		foreach ( self::setup_steps() as $step ) {
			if ( empty( $step['done'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---- Actions ------------------------------------------------------ */

	/** "Not today" on an item, or an operator hiding the setup list. */
	public static function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-build' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$id = sanitize_key( (string) ( $_POST['id'] ?? '' ) );
		// phpcs:enable
		$user = get_current_user_id();
		if ( 'snooze' === $do && '' !== $id ) {
			$s        = self::snoozed( $user );
			$s[ $id ] = wp_date( 'Ymd', time() + DAY_IN_SECONDS );
			update_user_meta( $user, self::SNOOZE, $s );
		} elseif ( 'undo' === $do ) {
			delete_user_meta( $user, self::SNOOZE );
		} elseif ( 'hide-setup' === $do && Scout_Hub_Access::is_operator() ) {
			update_option( self::SETUP, 1, false );
		}
		wp_safe_redirect( Scout_Hub_Admin::url( 'overview' ) );
		exit;
	}

	/* ---- The cards ---------------------------------------------------- */

	/** A small form button that posts one action. */
	private static function button( string $do, string $id, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<button type="submit" class="button-link">' . esc_html( $label ) . '</button></form>';
	}

	/** About how long something takes, in plain words. */
	private static function minutes( int $n ): string {
		/* translators: %d: minutes */
		return sprintf( _n( 'About %d min', 'About %d min', $n, 'scout-build' ), $n );
	}

	/**
	 * The Today card.
	 *
	 * @param bool $primary Give the first item the screen's one primary button (false when the setup list shows above it).
	 */
	public static function render( bool $primary = true ): void {
		$user  = get_current_user_id();
		$today = self::for_user( $user );
		$items = $today['items'];
		$done  = $today['done'];
		$total = count( $items ) + count( $done );
		?>
		<style>
			.sh-today{padding:0;overflow:hidden}
			.sh-today-head{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;padding:18px 22px;border-bottom:1px solid var(--sh-border)}
			.sh-today-head h2{margin:0;flex:1 1 auto}
			.sh-today-bar{width:120px;height:8px;border-radius:980px;background:var(--sh-surface-2);overflow:hidden;display:block}
			.sh-today-bar span{display:block;height:100%;background:var(--sh-accent);border-radius:980px}
			.sh-today-item{display:flex;flex-wrap:wrap;align-items:center;gap:12px 18px;padding:18px 22px;border-bottom:1px solid var(--sh-border)}
			.sh-today-item.is-first{background:color-mix(in srgb,var(--sh-accent) 6%,transparent)}
			.sh-today-num{font:500 26px/1 var(--sh-mono);color:var(--sh-text-3);width:40px}
			.sh-today-item.is-first .sh-today-num{color:var(--sh-accent)}
			.sh-today-text{flex:1 1 300px;display:grid;gap:3px}
			.sh-today-text b{font-size:16px}
			.sh-today-time{font:500 12px var(--sh-mono);color:var(--sh-text-2);background:var(--sh-surface-2);border-radius:8px;padding:5px 9px;white-space:nowrap}
			.sh-today-done{padding:12px 22px;color:var(--sh-text-2);font-size:14px}
			.sh-today-done s{color:var(--sh-text-3)}
			.sh-today-calm{padding:34px 22px;text-align:center;display:grid;gap:8px;justify-items:center}
			.sh-today-calm .sh-check-big{width:48px;height:48px;border-radius:50%;border:2px solid var(--sh-good);display:grid;place-items:center;color:var(--sh-good);font-size:24px}
		</style>
		<section class="sh-box sh-today" aria-labelledby="sh-today-title">
			<div class="sh-today-head">
				<h2 class="sh-title" id="sh-today-title"><?php esc_html_e( 'Today', 'scout-build' ); ?></h2>
				<?php if ( $total ) : ?>
					<?php
					/* translators: 1: done, 2: total */
					$progress = sprintf( __( '%1$d of %2$d done', 'scout-build' ), count( $done ), $total );
					?>
					<span class="sh-note"><?php echo esc_html( $progress ); ?></span>
					<span class="sh-today-bar" role="img" aria-label="<?php echo esc_attr( $progress ); ?>"><span style="width:<?php echo (int) round( 100 * count( $done ) / $total ); ?>%"></span></span>
				<?php endif; ?>
			</div>
			<?php if ( ! $items ) : ?>
				<div class="sh-today-calm">
					<span class="sh-check-big" aria-hidden="true">&#10003;</span>
					<p style="margin:0;font-size:19px;font-weight:600"><?php esc_html_e( 'Nothing needs you today', 'scout-build' ); ?></p>
					<p class="sh-note" style="margin:0"><?php esc_html_e( 'Anything new shows up right here. The site is running on its own.', 'scout-build' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $items as $i => $item ) : ?>
					<div class="sh-today-item<?php echo 0 === $i ? ' is-first' : ''; ?>">
						<span class="sh-today-num"><?php echo esc_html( str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
						<div class="sh-today-text">
							<?php if ( 0 === $i ) : ?><span class="sh-eyebrow" style="margin:0"><?php esc_html_e( 'Start here', 'scout-build' ); ?></span><?php endif; ?>
							<b><?php echo esc_html( $item['label'] ); ?></b>
							<?php if ( '' !== $item['why'] ) : ?><span class="sh-note"><?php echo esc_html( $item['why'] ); ?></span><?php endif; ?>
						</div>
						<span class="sh-today-time"><?php echo esc_html( self::minutes( $item['minutes'] ) ); ?></span>
						<a class="button <?php echo 0 === $i && $primary ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['button'] ); ?></a>
						<?php echo self::button( 'snooze', $item['id'], __( 'Not today', 'scout-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button(). ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $done ) : ?>
				<div class="sh-today-done">&#10003; <s><?php echo esc_html( implode( ', ', array_map( 'strval', $done ) ) ); ?></s> <?php esc_html_e( 'done earlier today', 'scout-build' ); ?></div>
			<?php endif; ?>
			<?php if ( $today['snoozed'] ) : ?>
				<div class="sh-today-done">
					<?php
					/* translators: %d: items hidden until tomorrow */
					echo esc_html( sprintf( _n( '%d item comes back tomorrow.', '%d items come back tomorrow.', $today['snoozed'], 'scout-build' ), $today['snoozed'] ) );
					?>
					<?php echo self::button( 'undo', '', __( 'Show them now', 'scout-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button(). ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/** The first-week setup card. Returns whether it showed. */
	public static function render_setup(): bool {
		if ( ! self::show_setup() ) {
			return false;
		}
		$steps = self::setup_steps();
		$done  = count( array_filter( $steps, static fn( $s ) => ! empty( $s['done'] ) ) );
		$next  = null;
		foreach ( $steps as $s ) {
			if ( empty( $s['done'] ) ) {
				$next = $s['id'];
				break;
			}
		}
		/* translators: 1: done, 2: total */
		$progress = sprintf( __( '%1$d of %2$d done', 'scout-build' ), $done, count( $steps ) );
		?>
		<section class="sh-box sh-today" aria-labelledby="sh-setup-title">
			<div class="sh-today-head">
				<h2 class="sh-title" id="sh-setup-title"><?php esc_html_e( 'Set up your site', 'scout-build' ); ?></h2>
				<span class="sh-note"><?php echo esc_html( $progress ); ?></span>
				<span class="sh-today-bar" role="img" aria-label="<?php echo esc_attr( $progress ); ?>"><span style="width:<?php echo (int) round( 100 * $done / max( 1, count( $steps ) ) ); ?>%"></span></span>
			</div>
			<?php foreach ( $steps as $i => $s ) : ?>
				<div class="sh-today-item<?php echo $s['id'] === $next ? ' is-first' : ''; ?>">
					<span class="sh-today-num"><?php echo ! empty( $s['done'] ) ? '&#10003;' : esc_html( str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
					<div class="sh-today-text">
						<?php if ( $s['id'] === $next ) : ?><span class="sh-eyebrow" style="margin:0"><?php esc_html_e( 'Next', 'scout-build' ); ?></span><?php endif; ?>
						<b><?php echo ! empty( $s['done'] ) ? '<s>' . esc_html( $s['label'] ) . '</s>' : esc_html( $s['label'] ); ?></b>
					</div>
					<?php if ( empty( $s['done'] ) ) : ?>
						<span class="sh-today-time"><?php echo esc_html( self::minutes( (int) $s['minutes'] ) ); ?></span>
						<a class="button <?php echo $s['id'] === $next ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $s['url'] ); ?>"><?php esc_html_e( 'Open', 'scout-build' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( Scout_Hub_Access::is_operator() ) : ?>
				<div class="sh-today-done"><?php echo self::button( 'hide-setup', '', __( 'Hide this list on this site', 'scout-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button(). ?></div>
			<?php endif; ?>
		</section>
		<?php
		return true;
	}
}
