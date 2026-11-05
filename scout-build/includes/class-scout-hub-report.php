<?php
/**
 * The monthly update to the client, drafted from Site history.
 *
 * Off by default. An operator turns it on per site (Scout tools, Site
 * history, Monthly update to the client): monthly, or quarterly for The
 * Partnership. Early in the month the site drafts last month's update from
 * the lines marked "Client sees": what was done (each line's client wording,
 * or its title when it has none, plus the measure), then "Waiting on your OK"
 * and "Coming up". Nothing sends by itself. The draft waits on Site history
 * for an operator to read it, change the opening if they want, and press Send
 * or Skip. The team address (Scout_Hub_Requests::recipient()) hears when a
 * draft is ready, and once more after three days if nobody has sent or
 * skipped it. Sending writes a Site history line.
 *
 * Clients who read texts, not email, get the same update as a short text an
 * operator copies and sends by hand, then marks with "I texted it".
 *
 * The client's address is the one typed in the box's Settings, else Business
 * info's email (Scout Core). The email goes out in the site's branded card
 * (Scout_Hub_Mail). On white-label sites (SCOUT_HUB_BRAND false) nothing in
 * it names Scout, and the draft warns about any line that does.
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

	const META_PERIOD  = '_scout_report_period';  // "2026-10" or "2026-Q3".
	const META_STATE   = '_scout_report_state';   // draft | sent | skipped.
	const META_INTRO   = '_scout_report_intro';
	const META_NUDGED  = '_scout_report_nudged';
	const META_SENT    = '_scout_report_sent';    // Who sent or skipped it (display name).
	const META_SENT_AT = '_scout_report_sent_at'; // When (Unix time).
	const META_HOW     = '_scout_report_how';     // email | text | skip.

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
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
				'supports'     => array( 'title' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	/** The daily run exists only while the update is on. */
	public static function schedule(): void {
		$on = 'off' !== self::settings()['cadence'];
		if ( $on && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! $on && wp_next_scheduled( self::HOOK ) ) {
			self::unschedule();
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/* ---------- settings ---------- */

	/** @return array{cadence:string, channel:string, to:string, name:string} */
	public static function settings(): array {
		$s = get_option( self::SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'cadence' => in_array( $s['cadence'] ?? '', array( 'monthly', 'quarterly', 'off' ), true ) ? $s['cadence'] : 'off',
			'channel' => in_array( $s['channel'] ?? '', array( 'email', 'text' ), true ) ? $s['channel'] : 'email',
			'to'      => is_email( (string) ( $s['to'] ?? '' ) ) ? (string) $s['to'] : '',
			'name'    => (string) ( $s['name'] ?? '' ),
		);
	}

	/** Business info's email (Scout Core), or '' when there is none. */
	public static function business_email(): string {
		$b = array();
		if ( function_exists( 'scout_core_business' ) ) {
			$b = (array) scout_core_business();
		} elseif ( class_exists( 'Scout_Core_Business' ) && method_exists( 'Scout_Core_Business', 'get' ) ) {
			$b = (array) Scout_Core_Business::get();
		}
		$email = trim( (string) ( $b['email'] ?? '' ) );
		return '' !== $email && is_email( $email ) ? $email : '';
	}

	/**
	 * Who the email goes to: the address typed in Settings, else Business
	 * info's email. Pure, for the tests.
	 *
	 * @return array{to:string, from:string} from is "field", "business" or "".
	 */
	public static function pick_address( string $typed, string $business ): array {
		if ( '' !== $typed ) {
			return array( 'to' => $typed, 'from' => 'field' );
		}
		if ( '' !== $business ) {
			return array( 'to' => $business, 'from' => 'business' );
		}
		return array( 'to' => '', 'from' => '' );
	}

	/** @return array{to:string, from:string} */
	public static function address(): array {
		return self::pick_address( self::settings()['to'], self::business_email() );
	}

	/* ---------- periods ---------- */

	/**
	 * The period a draft made on $today covers, or '' when none is due.
	 * Monthly: last month. Quarterly: the quarter just ended, in January,
	 * April, July and October. Pure, for the tests.
	 */
	public static function period_for( string $today, string $cadence ): string {
		if ( 'off' === $cadence || ! preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $today, $m ) ) {
			return '';
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		if ( 'quarterly' === $cadence ) {
			if ( ! in_array( $mo, array( 1, 4, 7, 10 ), true ) ) {
				return '';
			}
			$q = intdiv( $mo - 1, 3 ); // The quarter just ended; 0 means Q4 of last year.
			return 0 === $q ? ( $y - 1 ) . '-Q4' : $y . '-Q' . $q;
		}
		if ( 'monthly' !== $cadence ) {
			return '';
		}
		return 1 === $mo ? sprintf( '%04d-12', $y - 1 ) : sprintf( '%04d-%02d', $y, $mo - 1 );
	}

	/** @return string[] The months ("2026-07") a period covers. */
	public static function months( string $period ): array {
		if ( preg_match( '/^(\d{4})-Q([1-4])$/', $period, $m ) ) {
			$start = ( (int) $m[2] - 1 ) * 3 + 1;
			return array_map( static fn( $i ) => sprintf( '%s-%02d', $m[1], $start + $i ), array( 0, 1, 2 ) );
		}
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $period ) ? array( $period ) : array();
	}

	public static function label( string $period ): string {
		$months = self::months( $period );
		if ( ! $months ) {
			return '';
		}
		if ( 3 === count( $months ) ) {
			return date_i18n( 'F', strtotime( $months[0] . '-01' ) ) . ' to ' . date_i18n( 'F Y', strtotime( $months[2] . '-01' ) );
		}
		return date_i18n( 'F Y', strtotime( $period . '-01' ) );
	}

	/** "in October 2026" or "from July to September 2026". Pure apart from label(). */
	private static function span( string $label ): string {
		return ( false !== strpos( $label, ' to ' ) ? 'from ' : 'in ' ) . $label;
	}

	/* ---------- content ---------- */

	/**
	 * What goes in the update: client lines done in the period, then what is
	 * open now (waiting on the client, then planned).
	 *
	 * @return array{done: array[], waiting: array[], coming: array[]}
	 */
	public static function lines( string $period ): array {
		$out = array( 'done' => array(), 'waiting' => array(), 'coming' => array() );
		foreach ( self::months( $period ) as $month ) {
			$page = Scout_Hub_Log::query( array( 'client' => true, 'status' => 'done', 'month' => $month, 'per_page' => 200 ) );
			foreach ( array_reverse( $page['items'] ) as $post ) { // Oldest first reads like a story.
				$out['done'][] = Scout_Hub_Log::to_array( $post );
			}
		}
		foreach ( array( 'waiting' => 'waiting', 'planned' => 'coming' ) as $status => $key ) {
			$page = Scout_Hub_Log::query( array( 'client' => true, 'status' => $status, 'per_page' => 50 ) );
			foreach ( $page['items'] as $post ) {
				$out[ $key ][] = Scout_Hub_Log::to_array( $post );
			}
		}
		return $out;
	}

	/** One line as the client reads it: client wording (else the title), plus the measure. Pure. */
	public static function line_text( array $l ): string {
		$t = '' !== trim( (string) ( $l['client_text'] ?? '' ) ) ? (string) $l['client_text'] : (string) ( $l['title'] ?? '' );
		$t = rtrim( trim( $t ), '. ' );
		$m = trim( (string) ( $l['measure'] ?? '' ) );
		return $t . ( '' !== $m ? ' (' . $m . ')' : '' ) . '.';
	}

	/**
	 * What the draft should warn about before it goes. Pure.
	 *
	 * @return array{unworded:int, names_scout:int} Lines with no client wording, and
	 *         (white-label sites only) lines or an opening that name Scout.
	 */
	public static function checks( array $lines, bool $branded, string $intro = '' ): array {
		$out = array( 'unworded' => 0, 'names_scout' => 0 );
		foreach ( array( 'done', 'waiting', 'coming' ) as $k ) {
			foreach ( (array) ( $lines[ $k ] ?? array() ) as $l ) {
				$out['unworded'] += '' === trim( (string) ( $l['client_text'] ?? '' ) ) ? 1 : 0;
				if ( ! $branded && preg_match( '/\bscout\b/i', self::line_text( $l ) ) ) {
					++$out['names_scout'];
				}
			}
		}
		if ( ! $branded && preg_match( '/\bscout\b/i', $intro ) ) {
			++$out['names_scout'];
		}
		return $out;
	}

	/** The email's headline: "Your website in October 2026". Pure apart from label(). */
	public static function heading( string $period ): string {
		return 'Your website ' . self::span( self::label( $period ) );
	}

	/**
	 * The email body, as HTML for the branded card. Pure apart from esc_html.
	 *
	 * @param array{done: array[], waiting: array[], coming: array[]} $lines
	 */
	public static function body_html( array $lines, string $label, string $site, string $name, string $intro ): string {
		$p  = static fn( string $t ): string => '<p style="margin:0 0 14px">' . esc_html( $t ) . '</p>';
		$h3 = static fn( string $t ): string => '<p style="margin:8px 0 6px"><strong>' . esc_html( $t ) . '</strong></p>';
		$ul = static function ( array $rows ): string {
			$h = '<ul style="margin:0 0 16px;padding-left:20px">';
			foreach ( $rows as $l ) {
				$h .= '<li style="margin:0 0 6px">' . esc_html( self::line_text( $l ) ) . '</li>';
			}
			return $h . '</ul>';
		};
		$name = trim( $name );
		$h    = $p( '' !== $name ? 'Hi ' . $name . ',' : 'Hi,' );
		$h   .= $p( '' !== trim( $intro ) ? trim( $intro ) : "Here's what changed on " . $site . ' ' . self::span( $label ) . '.' );
		$h   .= $lines['done'] ? $ul( $lines['done'] ) : $p( 'There were no changes to the site this time.' );
		if ( $lines['waiting'] ) {
			$h .= $h3( 'Waiting on your OK' ) . $ul( $lines['waiting'] ) . $p( "You can approve these in Site history on your website's dashboard." );
		}
		if ( $lines['coming'] ) {
			$h .= $h3( 'Coming up' ) . $ul( $lines['coming'] );
		}
		$h .= $p( 'Reply to this email with anything you want changed.' );
		return $h;
	}

	/** The same update as a short text, for clients who read texts. Pure. */
	public static function text( array $lines, string $label, string $name ): string {
		$name = trim( $name );
		$n    = count( $lines['done'] );
		$out  = ( '' !== $name ? 'Hi ' . $name . ', your' : 'Your' ) . ' website update for ' . $label . ': ';
		if ( ! $n ) {
			$out .= 'no changes this time.';
		} else {
			$out .= implode( ' ', array_map( array( __CLASS__, 'line_text' ), array_slice( $lines['done'], 0, 2 ) ) );
			if ( $n > 2 ) {
				$out .= ' Plus ' . ( $n - 2 ) . ' more.';
			}
		}
		$w = count( $lines['waiting'] );
		if ( $w ) {
			$out .= ' ' . $w . ( 1 === $w ? ' thing needs' : ' things need' ) . ' your OK in Site history on your dashboard.';
		}
		return $out . ' Text back with anything you want changed.';
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

	/** @return WP_Post[] Drafts waiting to be sent or skipped, newest first. */
	public static function drafts(): array {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 6,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'draft', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/** The newest update that was sent or skipped, for the "Last update" line. */
	private static function last_done(): ?WP_Post {
		$found = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => array( 'sent', 'skipped' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare'   => 'IN',
			)
		);
		return $found ? $found[0] : null;
	}

	/**
	 * Make the draft for a period. A skipped one opens again as a draft; a sent
	 * one is never drafted twice.
	 *
	 * @return array{0:int, 1:string} The post ID, and "new", "draft", "reopened" or "sent".
	 */
	public static function make_draft( string $period ): array {
		$existing = self::find( $period );
		if ( $existing ) {
			$state = (string) get_post_meta( $existing->ID, self::META_STATE, true );
			if ( 'skipped' === $state ) {
				update_post_meta( $existing->ID, self::META_STATE, 'draft' );
				delete_post_meta( $existing->ID, self::META_NUDGED );
				return array( (int) $existing->ID, 'reopened' );
			}
			return array( (int) $existing->ID, 'sent' === $state ? 'sent' : 'draft' );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				'post_title'  => self::heading( $period ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return array( 0, '' );
		}
		update_post_meta( $id, self::META_PERIOD, $period );
		update_post_meta( $id, self::META_STATE, 'draft' );
		return array( (int) $id, 'new' );
	}

	/** Daily: draft what is due, and remind the team once about a draft left three days. */
	public static function daily(): void {
		$s = self::settings();
		if ( 'off' === $s['cadence'] ) {
			return;
		}
		$period = self::period_for( current_time( 'Y-m-d' ), $s['cadence'] );
		if ( '' !== $period && ! self::find( $period ) ) {
			list( $id ) = self::make_draft( $period );
			if ( $id ) {
				self::tell_team( $id, false );
			}
		}
		foreach ( self::drafts() as $draft ) {
			if ( get_post_meta( $draft->ID, self::META_NUDGED, true ) ) {
				continue;
			}
			if ( time() - (int) get_post_time( 'U', true, $draft ) >= self::NUDGE ) {
				update_post_meta( $draft->ID, self::META_NUDGED, time() );
				self::tell_team( (int) $draft->ID, true );
			}
		}
	}

	/** A plain note to the team address (SCOUT_HUB_REQUEST_EMAIL, else team@scoutraleigh.com). */
	private static function tell_team( int $id, bool $nudge ): void {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$label = self::label( (string) get_post_meta( $id, self::META_PERIOD, true ) );
		wp_mail(
			Scout_Hub_Requests::recipient(),
			$nudge
				/* translators: 1: site, 2: period */
				? sprintf( __( '[%1$s] Monthly update for %2$s is still waiting', 'scout-build' ), $site, $label )
				/* translators: 1: site, 2: period */
				: sprintf( __( '[%1$s] Monthly update for %2$s is ready to read', 'scout-build' ), $site, $label ),
			sprintf(
				/* translators: 1: period, 2: admin URL */
				__( "The update for %1\$s is drafted from Site history. Nothing goes to the client until someone sends it.\n\nRead it, then Send or Skip:\n%2\$s", 'scout-build' ),
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
			self::schedule();
			self::back( __( 'Settings saved.', 'scout-build' ) );
		}

		if ( 'draft' === $do ) {
			$period = sanitize_text_field( wp_unslash( (string) ( $_POST['period'] ?? '' ) ) );
			if ( ! self::months( $period ) ) {
				self::back( __( 'Pick a month first.', 'scout-build' ), 'error' );
			}
			list( $id, $how ) = self::make_draft( $period );
			if ( ! $id ) {
				self::back( __( 'The draft could not be saved. Try again.', 'scout-build' ), 'error' );
			}
			$label = self::label( $period );
			$msgs  = array(
				/* translators: %s: period */
				'new'      => sprintf( __( 'Draft made for %s. It’s below.', 'scout-build' ), $label ),
				/* translators: %s: period */
				'reopened' => sprintf( __( 'The skipped update for %s is a draft again.', 'scout-build' ), $label ),
				/* translators: %s: period */
				'draft'    => sprintf( __( 'There’s already a draft for %s. It’s below.', 'scout-build' ), $label ),
				/* translators: %s: period */
				'sent'     => sprintf( __( 'The update for %s was already sent.', 'scout-build' ), $label ),
			);
			self::back( $msgs[ $how ] ?? $msgs['new'], 'sent' === $how ? 'error' : 'success' );
		}

		$id = (int) ( $_POST['report'] ?? 0 );
		if ( self::TYPE !== get_post_type( $id ) || 'draft' !== get_post_meta( $id, self::META_STATE, true ) ) {
			self::back( __( 'That update isn’t a draft any more.', 'scout-build' ), 'error' );
		}
		if ( isset( $_POST['intro'] ) ) {
			update_post_meta( $id, self::META_INTRO, sanitize_textarea_field( wp_unslash( (string) $_POST['intro'] ) ) );
		}

		if ( 'save' === $do ) {
			self::back( __( 'Opening saved.', 'scout-build' ) );
		}
		if ( 'skip' === $do ) {
			self::finish( $id, 'skip' );
			self::back( __( 'Skipped. Nothing was sent.', 'scout-build' ) );
		}
		if ( 'send' === $do ) {
			if ( ! self::send( $id ) ) {
				self::back( __( 'The email didn’t go. Check Email under Scout tools, then try again.', 'scout-build' ), 'error' );
			}
			self::finish( $id, 'email' );
			self::back( __( 'Sent.', 'scout-build' ) );
		}
		if ( 'texted' === $do ) {
			self::finish( $id, 'text' );
			self::back( __( 'Marked as texted.', 'scout-build' ) );
		}
		self::back( __( 'Nothing to do.', 'scout-build' ), 'error' );
	}

	/** Mark a draft sent, texted or skipped, and write the Site history line. */
	private static function finish( int $id, string $how ): void {
		update_post_meta( $id, self::META_STATE, 'skip' === $how ? 'skipped' : 'sent' );
		update_post_meta( $id, self::META_HOW, $how );
		update_post_meta( $id, self::META_SENT, wp_get_current_user()->display_name );
		update_post_meta( $id, self::META_SENT_AT, time() );
		wp_update_post( array( 'ID' => $id ) ); // Touch the modified date, so "Last update" finds it.
		$label  = self::label( (string) get_post_meta( $id, self::META_PERIOD, true ) );
		$titles = array(
			/* translators: %s: period */
			'email' => sprintf( __( 'Monthly update for %s emailed to the client', 'scout-build' ), $label ),
			/* translators: %s: period */
			'text'  => sprintf( __( 'Monthly update for %s texted to the client', 'scout-build' ), $label ),
			/* translators: %s: period */
			'skip'  => sprintf( __( 'Monthly update for %s skipped', 'scout-build' ), $label ),
		);
		Scout_Hub_Log::add(
			array(
				'title'       => $titles[ $how ],
				'kind'        => 'note',
				'topic'       => 'other',
				'show_client' => false,
			)
		);
	}

	private static function send( int $id ): bool {
		$s  = self::settings();
		$to = self::address()['to'];
		if ( '' === $to ) {
			return false;
		}
		$period  = (string) get_post_meta( $id, self::META_PERIOD, true );
		$label   = self::label( $period );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body    = self::body_html( self::lines( $period ), $label, $site, $s['name'], (string) get_post_meta( $id, self::META_INTRO, true ) );
		$footer  = Scout_Hub_Admin::branded() ? __( 'Built and looked after by Scout Media & Consulting.', 'scout-build' ) : $site;
		$html    = method_exists( 'Scout_Hub_Mail', 'branded_card' )
			? Scout_Hub_Mail::branded_card( $site, self::heading( $period ), $body, array( array( __( 'Visit your site', 'scout-build' ), home_url( '/' ) ) ), $footer )
			: '<!doctype html><html><body>' . $body . '</body></html>';
		return (bool) wp_mail(
			$to,
			/* translators: 1: site, 2: "your website in October 2026" */
			sprintf( __( '%1$s: %2$s', 'scout-build' ), $site, lcfirst( self::heading( $period ) ) ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . Scout_Hub_Requests::recipient() )
		);
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'log' ) . '#sh-report' );
		exit;
	}

	/* ---------- the box on Site history (operators only) ---------- */

	/** A form that posts to this class, with the nonce. $inner is already escaped. */
	private static function form( string $do, string $inner, string $class = 'sh-form' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		if ( '' !== $do ) {
			echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		}
		wp_nonce_field( self::ACTION );
		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller.
		echo '</form>';
	}

	public static function panel(): void {
		if ( ! Scout_Hub_Access::is_operator() ) {
			return;
		}
		$s       = self::settings();
		$on      = 'off' !== $s['cadence'];
		$address = self::address();
		$drafts  = self::drafts();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$branded = Scout_Hub_Admin::branded();
		?>
		<section class="sh-box sh-report" id="sh-report">
			<h2 class="sh-title"><?php esc_html_e( 'Monthly update to the client', 'scout-build' ); ?></h2>
			<p class="sh-status <?php echo $on ? 'is-good' : 'is-off'; ?>"><span class="sh-dot" aria-hidden="true"></span>
				<strong><?php echo $on ? esc_html__( 'On', 'scout-build' ) : esc_html__( 'Off', 'scout-build' ); ?></strong>
				<span>
				<?php
				if ( ! $on ) {
					esc_html_e( 'Turn it on in Settings below.', 'scout-build' );
				} else {
					$how = 'quarterly' === $s['cadence'] ? __( 'Quarterly', 'scout-build' ) : __( 'Monthly', 'scout-build' );
					if ( 'text' === $s['channel'] ) {
						/* translators: %s: Monthly or Quarterly */
						echo esc_html( sprintf( __( '%s, by text you send by hand.', 'scout-build' ), $how ) );
					} elseif ( '' !== $address['to'] ) {
						/* translators: 1: Monthly or Quarterly, 2: email */
						echo esc_html( sprintf( __( '%1$s, by email to %2$s.', 'scout-build' ), $how, $address['to'] ) );
					} else {
						/* translators: %s: Monthly or Quarterly */
						echo esc_html( sprintf( __( '%s, by email. Add the client’s email in Settings.', 'scout-build' ), $how ) );
					}
				}
				?>
				</span>
			</p>
			<p class="sh-note"><?php esc_html_e( 'Drafted from lines marked Client sees. Nothing sends until you press Send.', 'scout-build' ); ?></p>

			<?php foreach ( $drafts as $draft ) : ?>
				<?php
				$id     = (int) $draft->ID;
				$period = (string) get_post_meta( $id, self::META_PERIOD, true );
				$label  = self::label( $period );
				$lines  = self::lines( $period );
				$intro  = (string) get_post_meta( $id, self::META_INTRO, true );
				$warn   = self::checks( $lines, $branded, $intro );
				?>
				<div class="sh-report-draft">
					<h3><?php echo esc_html( sprintf( /* translators: %s: period */ __( 'Draft: %s', 'scout-build' ), $label ) ); ?></h3>
					<?php if ( $warn['unworded'] ) : ?>
						<p class="sh-note sh-log-warn"><?php echo esc_html( sprintf( /* translators: %d: lines */ _n( '%d line has no client wording, so the client reads its title as written. Add wording in the list above.', '%d lines have no client wording, so the client reads their titles as written. Add wording in the list above.', $warn['unworded'], 'scout-build' ), $warn['unworded'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( $warn['names_scout'] ) : ?>
						<p class="sh-note sh-log-warn"><?php echo esc_html( sprintf( /* translators: %d: lines */ _n( '%d line names Scout, and this is a white-label site. Reword it before sending.', '%d lines name Scout, and this is a white-label site. Reword them before sending.', $warn['names_scout'], 'scout-build' ), $warn['names_scout'] ) ); ?></p>
					<?php endif; ?>

					<?php if ( 'text' === $s['channel'] ) : ?>
						<label class="sh-note" for="sh-rep-text-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>"><?php esc_html_e( 'The text to send', 'scout-build' ); ?></label>
						<textarea class="sh-report-text" id="sh-rep-text-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>" readonly rows="5"><?php echo esc_textarea( self::text( $lines, $label, $s['name'] ) ); ?></textarea>
						<p class="sh-report-actions"><button type="button" class="button" data-sh-copy="sh-rep-text-<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an integer. ?>"><?php esc_html_e( 'Copy the text', 'scout-build' ); ?></button></p>
						<?php
						$inner  = '<input type="hidden" name="report" value="' . $id . '">';
						$inner .= '<p class="sh-report-actions"><button type="submit" name="do" value="texted" class="button button-primary">' . esc_html__( 'I texted it', 'scout-build' ) . '</button>';
						$inner .= '<button type="submit" name="do" value="skip" class="button sh-quiet">' . esc_html__( 'Skip this one', 'scout-build' ) . '</button></p>';
						self::form( '', $inner, 'sh-inline' );
						?>
					<?php else : ?>
						<div class="sh-report-preview">
							<?php echo self::body_html( $lines, $label, $site, $s['name'], $intro ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						</div>
						<?php
						$inner  = '<input type="hidden" name="report" value="' . $id . '">';
						$inner .= '<label for="sh-rep-intro-' . $id . '">' . esc_html__( 'Opening line (blank uses the standard one)', 'scout-build' ) . '</label>';
						$inner .= '<textarea id="sh-rep-intro-' . $id . '" name="intro" rows="2">' . esc_textarea( $intro ) . '</textarea>';
						$inner .= '<p class="sh-report-actions">';
						$inner .= '' !== $address['to']
							/* translators: %s: email */
							? '<button type="submit" name="do" value="send" class="button button-primary">' . esc_html( sprintf( __( 'Send to %s', 'scout-build' ), $address['to'] ) ) . '</button>'
							: '<button type="button" class="button button-primary" disabled>' . esc_html__( 'Add the client’s email in Settings first', 'scout-build' ) . '</button>';
						$inner .= '<button type="submit" name="do" value="save" class="button">' . esc_html__( 'Save the opening', 'scout-build' ) . '</button>';
						$inner .= '<button type="submit" name="do" value="skip" class="button sh-quiet">' . esc_html__( 'Skip this one', 'scout-build' ) . '</button></p>';
						self::form( '', $inner );
						?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php
			$last = self::last_done();
			if ( $last ) {
				$how   = (string) get_post_meta( $last->ID, self::META_HOW, true );
				$who   = (string) get_post_meta( $last->ID, self::META_SENT, true );
				$when  = (int) get_post_meta( $last->ID, self::META_SENT_AT, true );
				$label = self::label( (string) get_post_meta( $last->ID, self::META_PERIOD, true ) );
				$verbs = array(
					'email' => __( 'emailed', 'scout-build' ),
					'text'  => __( 'texted', 'scout-build' ),
					'skip'  => __( 'skipped', 'scout-build' ),
				);
				/* translators: 1: period, 2: emailed, texted or skipped, 3: person, 4: date */
				$line = sprintf( __( 'Last update: %1$s, %2$s by %3$s on %4$s.', 'scout-build' ), $label, $verbs[ $how ] ?? $verbs['email'], '' !== $who ? $who : __( 'someone', 'scout-build' ), $when ? wp_date( 'M j, Y', $when ) : '' );
				echo '<p class="sh-note sh-report-last">' . esc_html( $line ) . '</p>';
			}
			?>

			<details class="sh-report-more"<?php echo $on ? '' : ' open'; ?>>
				<summary><?php esc_html_e( 'Settings', 'scout-build' ); ?></summary>
				<?php
				$business = self::business_email();
				$inner    = '<label for="sh-rep-cad">' . esc_html__( 'How often', 'scout-build' ) . '</label><select id="sh-rep-cad" name="cadence">';
				foreach ( array( 'off' => __( 'Off', 'scout-build' ), 'monthly' => __( 'Monthly', 'scout-build' ), 'quarterly' => __( 'Quarterly (The Partnership)', 'scout-build' ) ) as $k => $v ) {
					$inner .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['cadence'], $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				$inner .= '</select><label for="sh-rep-ch">' . esc_html__( 'How the client reads it', 'scout-build' ) . '</label><select id="sh-rep-ch" name="channel">';
				foreach ( array( 'email' => __( 'Email', 'scout-build' ), 'text' => __( 'Text, sent by hand', 'scout-build' ) ) as $k => $v ) {
					$inner .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['channel'], $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				$inner .= '</select><label for="sh-rep-to">' . esc_html__( 'Client’s email', 'scout-build' ) . '</label>';
				$inner .= '<input id="sh-rep-to" type="email" name="to" value="' . esc_attr( $s['to'] ) . '" aria-describedby="sh-rep-to-note">';
				$inner .= '<span class="sh-note" id="sh-rep-to-note">' . esc_html(
					'' !== $business
						/* translators: %s: email */
						? sprintf( __( 'Blank uses Business info’s email: %s.', 'scout-build' ), $business )
						: __( 'Blank uses Business info’s email, which isn’t set yet.', 'scout-build' )
				) . '</span>';
				$inner .= '<label for="sh-rep-name">' . esc_html__( 'First name for “Hi …”', 'scout-build' ) . '</label>';
				$inner .= '<input id="sh-rep-name" type="text" name="name" value="' . esc_attr( $s['name'] ) . '">';
				$inner .= '<p><button type="submit" class="button">' . esc_html__( 'Save', 'scout-build' ) . '</button></p>';
				self::form( 'settings', $inner );

				$months = '';
				try {
					$base = new DateTimeImmutable( current_time( 'Y-m-01' ) );
					for ( $i = 1; $i <= 6; $i++ ) {
						$m       = $base->modify( "-$i months" )->format( 'Y-m' );
						$months .= '<option value="' . esc_attr( $m ) . '">' . esc_html( self::label( $m ) ) . '</option>';
					}
				} catch ( Exception $e ) {
					$months = '';
				}
				if ( '' !== $months ) {
					$inner  = '<label for="sh-rep-period">' . esc_html__( 'Make a draft now for a recent month', 'scout-build' ) . '</label>';
					$inner .= '<select id="sh-rep-period" name="period">' . $months . '</select>';
					$inner .= '<p><button type="submit" class="button">' . esc_html__( 'Make a draft', 'scout-build' ) . '</button></p>';
					self::form( 'draft', $inner );
				}
				?>
			</details>
		</section>
		<script>
			document.querySelectorAll('[data-sh-copy]').forEach(function (b) {
				b.addEventListener('click', function () {
					var t = document.getElementById(b.getAttribute('data-sh-copy'));
					if (!t) { return; }
					var done = function () { b.textContent = <?php echo wp_json_encode( __( 'Copied', 'scout-build' ) ); ?>; };
					if (navigator.clipboard) {
						navigator.clipboard.writeText(t.value).then(done, function () { t.select(); });
					} else {
						t.select();
						document.execCommand('copy');
						done();
					}
				});
			});
		</script>
		<?php
	}
}
