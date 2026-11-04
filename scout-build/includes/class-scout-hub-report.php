<?php
/**
 * The monthly update: "Your website this month", built from the Site log.
 *
 * On the first days of a month the site drafts last month's update from the
 * lines marked "Client sees": what was done, what is still open, what waits
 * on the client. Nothing sends by itself. The draft waits on Scout → Build →
 * Site log for an operator to read it, change the opening if they want, and
 * press Send. Team@ hears when a draft is ready, and again after three days
 * if nobody has sent or skipped it. Silence means it never goes.
 *
 * Clients who read texts, not email (Patrick), get the same update as a short
 * text to copy and send by hand (Joy, 2026-11-04, decision three).
 *
 * Quarterly sites (The Partnership, timed with the sit-down) draft in January,
 * April, July and October for the three months before.
 *
 * Spec: docs/site-log-spec.md, step 4.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Report {

	const TYPE     = 'scout_hub_report';
	const ACTION   = 'scout_hub_report';
	const SETTINGS = 'scout_hub_report_settings';
	const HOOK     = 'scout_hub_report_daily';
	const NUDGE    = 3 * DAY_IN_SECONDS;

	const META_PERIOD = '_scout_report_period'; // "2026-10" or "2026-Q3".
	const META_STATE  = '_scout_report_state';  // draft | sent | skipped.
	const META_INTRO  = '_scout_report_intro';
	const META_NUDGED = '_scout_report_nudged';
	const META_SENT   = '_scout_report_sent';   // "Joy McNamara, 2026-11-04 10:12".

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array( 'name' => __( 'Monthly updates', 'scout-build' ) ),
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'supports'     => array( 'title', 'editor' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/* ---------- settings ---------- */

	/** @return array{cadence:string, channel:string, to:string, name:string} */
	public static function settings(): array {
		$s = (array) get_option( self::SETTINGS, array() );
		return array(
			'cadence' => in_array( $s['cadence'] ?? '', array( 'monthly', 'quarterly', 'off' ), true ) ? $s['cadence'] : 'off',
			'channel' => in_array( $s['channel'] ?? '', array( 'email', 'text' ), true ) ? $s['channel'] : 'email',
			'to'      => is_email( (string) ( $s['to'] ?? '' ) ) ? (string) $s['to'] : '',
			'name'    => (string) ( $s['name'] ?? '' ),
		);
	}

	/* ---------- periods ---------- */

	/**
	 * The period a draft made on $today covers, or '' when none is due.
	 * Monthly: last month. Quarterly: the last quarter, only in its following
	 * month's first week... in practice, on any day of Jan, Apr, Jul or Oct.
	 * Pure, for the tests.
	 */
	public static function period_for( string $today, string $cadence ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $today, $m ) || 'off' === $cadence ) {
			return '';
		}
		$y = (int) $m[1];
		$mo = (int) $m[2];
		if ( 'quarterly' === $cadence ) {
			if ( ! in_array( $mo, array( 1, 4, 7, 10 ), true ) ) {
				return '';
			}
			$q = intdiv( $mo - 1, 3 ); // The quarter just ended: 0 means Q4 of last year.
			return 0 === $q ? ( $y - 1 ) . '-Q4' : $y . '-Q' . $q;
		}
		$prev = 1 === $mo ? array( $y - 1, 12 ) : array( $y, $mo - 1 );
		return sprintf( '%04d-%02d', $prev[0], $prev[1] );
	}

	/** @return string[] The months ("2026-07") a period covers. */
	public static function months( string $period ): array {
		if ( preg_match( '/^(\d{4})-Q([1-4])$/', $period, $m ) ) {
			$start = ( (int) $m[2] - 1 ) * 3 + 1;
			return array_map( static fn( $i ) => sprintf( '%s-%02d', $m[1], $start + $i ), array( 0, 1, 2 ) );
		}
		return preg_match( '/^\d{4}-\d{2}$/', $period ) ? array( $period ) : array();
	}

	public static function label( string $period ): string {
		if ( preg_match( '/^(\d{4})-Q([1-4])$/', $period, $m ) ) {
			$months = self::months( $period );
			return date_i18n( 'F', strtotime( $months[0] . '-01' ) ) . ' to ' . date_i18n( 'F Y', strtotime( $months[2] . '-01' ) );
		}
		return date_i18n( 'F Y', strtotime( $period . '-01' ) );
	}

	/* ---------- content ---------- */

	/**
	 * What goes in the update: client lines done in the period, plus what is
	 * open now (waiting on the client, then planned).
	 *
	 * @return array{done: array[], waiting: array[], coming: array[], unworded: int}
	 */
	public static function lines( string $period ): array {
		$out = array( 'done' => array(), 'waiting' => array(), 'coming' => array(), 'unworded' => 0 );
		foreach ( self::months( $period ) as $month ) {
			$page = Scout_Hub_Log::query( array( 'client' => true, 'status' => 'done', 'month' => $month, 'per_page' => 200 ) );
			foreach ( $page['items'] as $post ) {
				$out['done'][] = Scout_Hub_Log::to_array( $post );
			}
		}
		foreach ( array( 'waiting', 'planned' ) as $status ) {
			$page = Scout_Hub_Log::query( array( 'client' => true, 'status' => $status, 'per_page' => 50 ) );
			foreach ( $page['items'] as $post ) {
				$out[ 'planned' === $status ? 'coming' : 'waiting' ][] = Scout_Hub_Log::to_array( $post );
			}
		}
		foreach ( array( 'done', 'waiting', 'coming' ) as $k ) {
			foreach ( $out[ $k ] as $l ) {
				$out['unworded'] += '' === $l['client_text'] ? 1 : 0;
			}
		}
		return $out;
	}

	/** One line as the client reads it: client wording (else the title), plus the measure. Pure. */
	public static function line_text( array $l ): string {
		$t = '' !== (string) ( $l['client_text'] ?? '' ) ? (string) $l['client_text'] : (string) ( $l['title'] ?? '' );
		$t = rtrim( $t, '. ' );
		return $t . ( '' !== (string) ( $l['measure'] ?? '' ) ? ' (' . $l['measure'] . ')' : '' ) . '.';
	}

	/**
	 * The email body, as HTML for the branded card. Pure apart from esc_html.
	 *
	 * @param array{done: array[], waiting: array[], coming: array[]} $lines
	 */
	public static function body_html( array $lines, string $label, string $site, string $name, string $intro ): string {
		$p   = static fn( string $t ): string => '<p style="margin:0 0 14px">' . esc_html( $t ) . '</p>';
		$ul  = static function ( array $rows ): string {
			$h = '<ul style="margin:0 0 16px;padding-left:20px">';
			foreach ( $rows as $l ) {
				$h .= '<li style="margin:0 0 6px">' . esc_html( self::line_text( $l ) ) . '</li>';
			}
			return $h . '</ul>';
		};
		$h  = $p( '' !== $name ? 'Hi ' . $name . ',' : 'Hi,' );
		$h .= $p( '' !== trim( $intro ) ? trim( $intro ) : 'Here is what changed on ' . $site . ' in ' . $label . '.' );
		$h .= $lines['done'] ? $ul( $lines['done'] ) : $p( 'Nothing changed on the site this time. Everything kept running and updating on its own.' );
		if ( $lines['waiting'] ) {
			$h .= '<p style="margin:8px 0 6px"><strong>Waiting on your OK</strong></p>' . $ul( $lines['waiting'] );
		}
		if ( $lines['coming'] ) {
			$h .= '<p style="margin:8px 0 6px"><strong>Coming up</strong></p>' . $ul( $lines['coming'] );
		}
		$h .= $p( 'Reply to this email with anything you want changed.' );
		return $h;
	}

	/** The same update as a short text for clients who read texts. Pure. */
	public static function text( array $lines, string $label, string $name ): string {
		$n    = count( $lines['done'] );
		$head = ( '' !== $name ? 'Hi ' . $name . ', ' : '' ) . 'your website update for ' . $label . ': ';
		if ( ! $n ) {
			$head .= 'no changes this time; everything kept running.';
		} else {
			$first = array_slice( $lines['done'], 0, 2 );
			$head .= implode( ' ', array_map( array( __CLASS__, 'line_text' ), $first ) );
			if ( $n > 2 ) {
				$head .= ' Plus ' . ( $n - 2 ) . ' more.';
			}
		}
		if ( $lines['waiting'] ) {
			$head .= ' ' . count( $lines['waiting'] ) . ' thing' . ( 1 === count( $lines['waiting'] ) ? ' needs' : 's need' ) . ' your OK when you log in.';
		}
		return $head . ' Text back with anything you want changed.';
	}

	/* ---------- drafts ---------- */

	public static function find( string $period ): ?WP_Post {
		$found = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'meta_key'       => self::META_PERIOD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $period, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $found ? $found[0] : null;
	}

	/** The newest draft or sent update, for the screen. */
	public static function latest(): ?WP_Post {
		$found = get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
		return $found ? $found[0] : null;
	}

	public static function make_draft( string $period ): int {
		$existing = self::find( $period );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				/* translators: %s: period, e.g. October 2026 */
				'post_title'  => sprintf( __( 'Your website: %s', 'scout-build' ), self::label( $period ) ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, self::META_PERIOD, $period );
		update_post_meta( $id, self::META_STATE, 'draft' );
		return (int) $id;
	}

	/** Daily: draft what is due, and nudge team@ about drafts left three days. */
	public static function daily(): void {
		$s = self::settings();
		if ( 'off' === $s['cadence'] ) {
			return;
		}
		$period = self::period_for( current_time( 'Y-m-d' ), $s['cadence'] );
		if ( '' !== $period && ! self::find( $period ) ) {
			$id = self::make_draft( $period );
			if ( $id ) {
				self::tell_team( $id, false );
			}
		}
		$latest = self::latest();
		if ( $latest && 'draft' === get_post_meta( $latest->ID, self::META_STATE, true ) && ! get_post_meta( $latest->ID, self::META_NUDGED, true )
			&& time() - (int) get_post_time( 'U', true, $latest ) >= self::NUDGE ) {
			update_post_meta( $latest->ID, self::META_NUDGED, time() );
			self::tell_team( (int) $latest->ID, true );
		}
	}

	private static function tell_team( int $id, bool $nudge ): void {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$label = self::label( (string) get_post_meta( $id, self::META_PERIOD, true ) );
		wp_mail(
			Scout_Hub_Requests::recipient(),
			$nudge
				/* translators: 1: site, 2: period */
				? sprintf( __( '[%1$s] Monthly update for %2$s still waiting', 'scout-build' ), $site, $label )
				/* translators: 1: site, 2: period */
				: sprintf( __( '[%1$s] Monthly update for %2$s is ready to read', 'scout-build' ), $site, $label ),
			sprintf(
				/* translators: 1: period, 2: admin URL */
				__( "The update for %1\$s is drafted from the Site log. Nothing goes to the client until someone presses Send.\n\nRead it, change the opening if you like, then Send or Skip:\n%2\$s", 'scout-build' ),
				$label,
				Scout_Hub_Admin::url( 'log' ) . '#sh-report'
			)
		);
	}

	/* ---------- actions ---------- */

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can send the monthly update.', 'scout-build' ), 403 );
		}
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );

		if ( 'settings' === $do ) {
			update_option(
				self::SETTINGS,
				array(
					'cadence' => sanitize_key( wp_unslash( (string) ( $_POST['cadence'] ?? 'off' ) ) ),
					'channel' => sanitize_key( wp_unslash( (string) ( $_POST['channel'] ?? 'email' ) ) ),
					'to'      => sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) ),
					'name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
				),
				false
			);
			self::back( __( 'Monthly update settings saved.', 'scout-build' ) );
		}

		if ( 'draft' === $do ) {
			$period = sanitize_text_field( wp_unslash( (string) ( $_POST['period'] ?? '' ) ) );
			if ( ! self::months( $period ) ) {
				self::back( __( 'Pick a month first.', 'scout-build' ), 'error' );
			}
			self::make_draft( $period );
			self::back( __( 'Draft made. Read it below.', 'scout-build' ) );
		}

		$id = (int) ( $_POST['report'] ?? 0 );
		if ( self::TYPE !== get_post_type( $id ) || 'draft' !== get_post_meta( $id, self::META_STATE, true ) ) {
			self::back( __( 'That update is not a draft any more.', 'scout-build' ), 'error' );
		}
		update_post_meta( $id, self::META_INTRO, sanitize_textarea_field( wp_unslash( (string) ( $_POST['intro'] ?? '' ) ) ) );

		if ( 'save' === $do ) {
			self::back( __( 'Opening saved.', 'scout-build' ) );
		}
		if ( 'skip' === $do ) {
			update_post_meta( $id, self::META_STATE, 'skipped' );
			self::back( __( 'Skipped. Nothing was sent.', 'scout-build' ) );
		}
		if ( 'send' === $do || 'texted' === $do ) {
			$ok = 'texted' === $do ? true : self::send( $id );
			if ( ! $ok ) {
				self::back( __( 'The email did not go. Check Scout Email on Site health, then try again.', 'scout-build' ), 'error' );
			}
			$who = wp_get_current_user()->display_name . ', ' . current_time( 'Y-m-d H:i' );
			update_post_meta( $id, self::META_STATE, 'sent' );
			update_post_meta( $id, self::META_SENT, $who );
			Scout_Hub_Log::add(
				array(
					/* translators: 1: period, 2: how */
					'title'       => sprintf( __( 'Monthly update for %1$s %2$s', 'scout-build' ), self::label( (string) get_post_meta( $id, self::META_PERIOD, true ) ), 'texted' === $do ? __( 'texted to the client', 'scout-build' ) : __( 'emailed to the client', 'scout-build' ) ),
					'kind'        => 'note',
					'topic'       => 'other',
					'show_client' => false,
				)
			);
			self::back( 'texted' === $do ? __( 'Marked as texted.', 'scout-build' ) : __( 'Sent.', 'scout-build' ) );
		}
		self::back( __( 'Nothing to do.', 'scout-build' ), 'error' );
	}

	private static function send( int $id ): bool {
		$s = self::settings();
		if ( '' === $s['to'] ) {
			return false;
		}
		$period = (string) get_post_meta( $id, self::META_PERIOD, true );
		$label  = self::label( $period );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body   = self::body_html( self::lines( $period ), $label, $site, $s['name'], (string) get_post_meta( $id, self::META_INTRO, true ) );
		$html   = class_exists( 'Scout_Hub_Mail' ) && method_exists( 'Scout_Hub_Mail', 'branded_card' )
			? Scout_Hub_Mail::branded_card( $site, __( 'Your website this month', 'scout-build' ), $body, array( array( __( 'Visit your site', 'scout-build' ), home_url( '/' ) ) ), Scout_Hub_Admin::branded() ? 'Scout Media & Consulting' : $site )
			: '<html><body>' . $body . '</body></html>';
		return (bool) wp_mail(
			$s['to'],
			/* translators: 1: site, 2: period */
			sprintf( __( '%1$s: your website in %2$s', 'scout-build' ), $site, $label ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . Scout_Hub_Requests::recipient() )
		);
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'log' ) . '#sh-report' );
		exit;
	}

	/* ---------- the box on the Site log screen ---------- */

	public static function panel(): void {
		$s      = self::settings();
		$latest = self::latest();
		$state  = $latest ? (string) get_post_meta( $latest->ID, self::META_STATE, true ) : '';
		$form   = static function ( string $do, string $inner, string $class = 'sh-inline' ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
			wp_nonce_field( self::ACTION );
			echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below.
			echo '</form>';
		};
		?>
		<section class="sh-box" id="sh-report">
			<p class="sh-label"><?php esc_html_e( 'Monthly update to the client', 'scout-build' ); ?></p>
			<p class="sh-note"><?php esc_html_e( 'Drafted from the lines marked "Client sees" at the start of each month (or quarter). Nothing goes until someone here presses Send; team@ is reminded after three days.', 'scout-build' ); ?></p>

			<?php if ( $latest && 'draft' === $state ) : ?>
				<?php
				$period = (string) get_post_meta( $latest->ID, self::META_PERIOD, true );
				$label  = self::label( $period );
				$lines  = self::lines( $period );
				$intro  = (string) get_post_meta( $latest->ID, self::META_INTRO, true );
				$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				?>
				<h3 style="margin:14px 0 6px"><?php echo esc_html( sprintf( /* translators: %s: period */ __( 'Draft: %s', 'scout-build' ), $label ) ); ?></h3>
				<?php if ( $lines['unworded'] ) : ?>
					<p class="sh-note" style="color:var(--sh-warn)"><?php echo esc_html( sprintf( /* translators: %d: lines */ _n( '%d line has no client wording yet, so the client would read its internal title. Fix it in the log above first.', '%d lines have no client wording yet, so the client would read their internal titles. Fix them in the log above first.', $lines['unworded'], 'scout-build' ), $lines['unworded'] ) ); ?></p>
				<?php endif; ?>
				<div style="border:1px solid var(--sh-border);border-radius:12px;padding:14px 16px;margin:8px 0;background:var(--sh-surface)">
					<?php echo self::body_html( $lines, $label, $site, $s['name'], $intro ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>
				<?php
				$inner  = '<input type="hidden" name="report" value="' . (int) $latest->ID . '">';
				$inner .= '<label for="sh-rep-intro" class="sh-note">' . esc_html__( 'Opening line (optional; blank uses the standard one)', 'scout-build' ) . '</label>';
				$inner .= '<textarea id="sh-rep-intro" name="intro" rows="2" style="width:100%">' . esc_textarea( $intro ) . '</textarea>';
				$inner .= '<p style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">';
				if ( 'email' === $s['channel'] ) {
					$inner .= '<button type="submit" name="do" value="send" class="button button-primary"' . ( '' === $s['to'] ? ' disabled' : '' ) . '>' . esc_html( '' !== $s['to'] ? sprintf( /* translators: %s: email */ __( 'Send to %s', 'scout-build' ), $s['to'] ) : __( 'Add the client\'s email below first', 'scout-build' ) ) . '</button>';
				} else {
					$inner .= '<button type="submit" name="do" value="texted" class="button button-primary">' . esc_html__( 'I texted it', 'scout-build' ) . '</button>';
				}
				$inner .= '<button type="submit" name="do" value="save" class="button">' . esc_html__( 'Save the opening', 'scout-build' ) . '</button>';
				$inner .= '<button type="submit" name="do" value="skip" class="button sh-quiet">' . esc_html__( 'Skip this one', 'scout-build' ) . '</button></p>';
				$form( 'save', $inner, 'sh-form' );
				?>
				<?php if ( 'text' === $s['channel'] ) : ?>
					<p class="sh-note" style="margin-top:10px"><?php esc_html_e( 'The text to send (select it all and copy):', 'scout-build' ); ?></p>
					<textarea readonly rows="4" style="width:100%" onclick="this.select()"><?php echo esc_textarea( self::text( $lines, $label, $s['name'] ) ); ?></textarea>
				<?php endif; ?>
			<?php elseif ( $latest ) : ?>
				<p class="sh-note" style="margin-top:10px">
					<?php
					$label = self::label( (string) get_post_meta( $latest->ID, self::META_PERIOD, true ) );
					echo esc_html(
						'sent' === $state
							/* translators: 1: period, 2: who and when */
							? sprintf( __( 'Last update: %1$s, sent by %2$s.', 'scout-build' ), $label, (string) get_post_meta( $latest->ID, self::META_SENT, true ) )
							/* translators: %s: period */
							: sprintf( __( 'Last update: %s, skipped.', 'scout-build' ), $label )
					);
					?>
				</p>
			<?php endif; ?>

			<details style="margin-top:12px">
				<summary class="sh-note"><?php esc_html_e( 'Who gets it and how often', 'scout-build' ); ?></summary>
				<?php
				$inner  = '<label for="sh-rep-cad">' . esc_html__( 'How often', 'scout-build' ) . '</label><select id="sh-rep-cad" name="cadence">';
				foreach ( array( 'off' => __( 'Off', 'scout-build' ), 'monthly' => __( 'Monthly', 'scout-build' ), 'quarterly' => __( 'Quarterly (The Partnership)', 'scout-build' ) ) as $k => $v ) {
					$inner .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['cadence'], $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				$inner .= '</select><label for="sh-rep-ch">' . esc_html__( 'How the client reads it', 'scout-build' ) . '</label><select id="sh-rep-ch" name="channel">';
				foreach ( array( 'email' => __( 'Email', 'scout-build' ), 'text' => __( 'Text, sent by hand', 'scout-build' ) ) as $k => $v ) {
					$inner .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['channel'], $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				$inner .= '</select><label for="sh-rep-to">' . esc_html__( 'Client\'s email', 'scout-build' ) . '</label><input id="sh-rep-to" type="email" name="to" value="' . esc_attr( $s['to'] ) . '" style="width:100%">';
				$inner .= '<label for="sh-rep-name">' . esc_html__( 'First name for "Hi …"', 'scout-build' ) . '</label><input id="sh-rep-name" type="text" name="name" value="' . esc_attr( $s['name'] ) . '" style="width:100%">';
				$inner .= '<p><button type="submit" class="button">' . esc_html__( 'Save', 'scout-build' ) . '</button></p>';
				$form( 'settings', $inner, 'sh-form' );

				$months = '';
				for ( $i = 1; $i <= 6; $i++ ) {
					$m       = gmdate( 'Y-m', strtotime( "first day of -$i months", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
					$months .= '<option value="' . esc_attr( $m ) . '">' . esc_html( self::label( $m ) ) . '</option>';
				}
				$form( 'draft', '<p class="sh-note" style="margin-top:12px">' . esc_html__( 'Draft one now, for any recent month:', 'scout-build' ) . '</p><select name="period">' . $months . '</select> <button type="submit" class="button">' . esc_html__( 'Make a draft', 'scout-build' ) . '</button>' );
				?>
			</details>
		</section>
		<?php
	}
}
