<?php
/**
 * What visitors see.
 *
 *   [scout_events]                          upcoming events as a list
 *   [scout_events show="past" limit="6"]    past events, newest first
 *   [scout_events group="month"]            under month headings (the default for lists over 8)
 *   [scout_event id="12"]                   one event's details panel
 *   [scout_schedule event="12"]             the schedule, day by day
 *   [scout_schedule event="12" layout="grid"]  rooms across, times down
 *   [scout_speakers event="12"]             speaker cards (all speakers without event)
 *   event and id also take the event's address word: event="summit-2027"
 *
 * Single event, session and speaker pages fill themselves in: the event page
 * gets its details panel above the description and the schedule, speakers and
 * "What to know" below it. Turn that off with the scout_events_auto_content
 * filter when a theme template places the pieces itself.
 *
 * Session descriptions open in place with <details>, so the schedule needs no
 * script. Styles load only on pages that show events.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Front {

	public static function boot(): void {
		add_shortcode( 'scout_events', array( __CLASS__, 'events_shortcode' ) );
		add_shortcode( 'scout_event', array( __CLASS__, 'event_shortcode' ) );
		add_shortcode( 'scout_schedule', array( __CLASS__, 'schedule_shortcode' ) );
		add_shortcode( 'scout_speakers', array( __CLASS__, 'speakers_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_filter( 'the_content', array( __CLASS__, 'single' ), 20 );
	}

	public static function register(): void {
		wp_register_style( 'scout-events', SCOUT_EVENTS_URL . 'assets/scout-events.css', array(), SCOUT_EVENTS_VERSION );
		if ( is_singular( Scout_Events::post_types() ) ) {
			wp_enqueue_style( 'scout-events' );
			return;
		}
		$post = get_post();
		if ( $post ) {
			foreach ( array( 'scout_events', 'scout_event', 'scout_schedule', 'scout_speakers' ) as $tag ) {
				if ( has_shortcode( (string) $post->post_content, $tag ) ) {
					wp_enqueue_style( 'scout-events' );
					return;
				}
			}
		}
	}

	private static function styles(): void {
		if ( ! wp_style_is( 'scout-events', 'registered' ) ) {
			wp_register_style( 'scout-events', SCOUT_EVENTS_URL . 'assets/scout-events.css', array(), SCOUT_EVENTS_VERSION );
		}
		wp_enqueue_style( 'scout-events' );
	}

	/* ---------- single pages ---------- */

	public static function single( $content ) {
		if ( ! is_singular( Scout_Events::post_types() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( ! apply_filters( 'scout_events_auto_content', true, $id ) ) {
			return $content;
		}
		$type = get_post_type( $id );
		if ( Scout_Events::EVENT === $type ) {
			return self::event_page( $id, (string) $content );
		}
		if ( Scout_Events::SESSION === $type ) {
			return self::session_page( $id, (string) $content );
		}
		if ( Scout_Events::SPEAKER === $type ) {
			return self::speaker_page( $id, (string) $content );
		}
		return $content;
	}

	private static function event_page( int $id, string $content ): string {
		$e     = Scout_Events::event( $id );
		$out   = self::details( $id );
		$out  .= '' !== trim( $content ) ? '<div class="se-about">' . $content . '</div>' : '';
		$know  = Scout_Events::know_lines( $e );
		if ( $know ) {
			$out .= '<section class="se-know" aria-labelledby="se-know-' . $id . '"><h2 id="se-know-' . $id . '">' . esc_html__( 'What to know', 'scout-events' ) . '</h2><ul>';
			foreach ( $know as $line ) {
				$out .= '<li>' . esc_html( $line ) . '</li>';
			}
			$out .= '</ul></section>';
		}
		if ( Scout_Events::session_ids( $id ) ) {
			$out .= '<section class="se-block" aria-labelledby="se-sched-' . $id . '"><h2 id="se-sched-' . $id . '">' . esc_html__( 'Schedule', 'scout-events' ) . '</h2>' . self::schedule( $id, 'list' ) . '</section>';
		}
		if ( Scout_Events::event_speaker_ids( $id ) ) {
			$out .= '<section class="se-block" aria-labelledby="se-spk-' . $id . '"><h2 id="se-spk-' . $id . '">' . esc_html__( 'Speakers', 'scout-events' ) . '</h2>' . self::speakers( Scout_Events::event_speaker_ids( $id ) ) . '</section>';
		}
		return $out;
	}

	private static function session_page( int $id, string $content ): string {
		$s    = Scout_Events::session( $id );
		$rows = array();
		if ( $s['event'] && 'publish' === get_post_status( $s['event'] ) ) {
			$rows[] = array( __( 'Part of', 'scout-events' ), '<a href="' . esc_url( get_permalink( $s['event'] ) ) . '">' . esc_html( get_the_title( $s['event'] ) ) . '</a>' );
		}
		$when = trim( Scout_Events::day_label( $s['date'] ) . ', ' . Scout_Events::session_time_label( $s ), ', ' );
		if ( '' !== $when ) {
			$rows[] = array( __( 'When', 'scout-events' ), esc_html( $when ) );
		}
		if ( '' !== $s['room'] ) {
			$rows[] = array( __( 'Where', 'scout-events' ), esc_html( $s['room'] ) );
		}
		$people = self::speaker_links( $s['speakers'] );
		if ( '' !== $people ) {
			$rows[] = array( __( 'Led by', 'scout-events' ), $people );
		}
		return self::facts( $rows ) . $content;
	}

	private static function speaker_page( int $id, string $content ): string {
		$p   = Scout_Events::speaker( $id );
		$out = '<div class="se-speaker-head">';
		if ( '' !== $p['role'] ) {
			$out .= '<p class="se-role">' . esc_html( $p['role'] ) . '</p>';
		}
		if ( '' !== $p['short'] && ! self::repeats( $p['short'], $content ) ) {
			$out .= '<p class="se-short">' . esc_html( $p['short'] ) . '</p>';
		}
		$links = array();
		if ( '' !== $p['website'] ) {
			$links[] = '<a href="' . esc_url( $p['website'] ) . '" rel="noopener">' . esc_html__( 'Website', 'scout-events' ) . '</a>';
		}
		foreach ( $p['links'] as $url ) {
			$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
			$links[] = '<a href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( preg_replace( '/^www\./', '', $host ) ) . '</a>';
		}
		if ( $links ) {
			$out .= '<p class="se-links">' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</p>';
		}
		$out .= '</div>' . $content . Scout_Events_Photos::strip( $id );

		$sessions = Scout_Events::sessions_for_speaker( $id );
		if ( $sessions ) {
			$out .= '<section class="se-block" aria-labelledby="se-ses-' . $id . '"><h2 id="se-ses-' . $id . '">' . esc_html__( 'Sessions', 'scout-events' ) . '</h2><ul class="se-session-list">';
			foreach ( $sessions as $sid ) {
				$s     = Scout_Events::session( $sid );
				$when  = trim( Scout_Events::day_label( $s['date'] ) . ', ' . Scout_Events::session_time_label( $s ), ', ' );
				$event = $s['event'] && 'publish' === get_post_status( $s['event'] ) ? get_the_title( $s['event'] ) : '';
				$out  .= '<li><a href="' . esc_url( get_permalink( $sid ) ) . '">' . esc_html( get_the_title( $sid ) ) . '</a>';
				$meta  = array_filter( array( $event, $when, $s['room'] ) );
				if ( $meta ) {
					$out .= '<span class="se-meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
				}
				$out .= '</li>';
			}
			$out .= '</ul></section>';
		}
		return $out;
	}

	/**
	 * True when the full bio opens with the short bio (often word for word, or
	 * with only the name changed), so the page does not say it twice. Compares
	 * words three to twelve of each.
	 */
	private static function repeats( string $short, string $bio ): bool {
		$words = static function ( string $t ): array {
			preg_match_all( "/[\\p{L}']+/u", mb_strtolower( wp_strip_all_tags( $t ) ), $m );
			return array_slice( $m[0], 2, 10 );
		};
		$a = $words( $short );
		$b = $words( $bio );
		return count( $a ) >= 6 && count( array_intersect( $a, $b ) ) >= 7;
	}

	/* ---------- event details panel ---------- */

	/** The details panel: status, when, where, price and the ticket button. */
	public static function details( int $id ): string {
		self::styles();
		$e    = Scout_Events::event( $id );
		$out  = '<div class="se-details">';
		$past = Scout_Events::is_past( $e );

		if ( 'scheduled' !== $e['status'] ) {
			$out .= '<p class="se-banner is-' . esc_attr( $e['status'] ) . '">' . esc_html( Scout_Events::statuses()[ $e['status'] ] ) . '</p>';
		} elseif ( $past ) {
			$out .= '<p class="se-banner is-past">' . esc_html__( 'This event has ended.', 'scout-events' ) . '</p>';
		}

		$rows  = array();
		$dates = Scout_Events::dates_label( $e );
		if ( '' !== $dates ) {
			$times  = Scout_Events::times_label( $e );
			$rows[] = array( __( 'When', 'scout-events' ), esc_html( $dates ) . ( '' !== $times ? '<br>' . esc_html( $times ) : '' ) );
		}
		$where = array();
		if ( 'online' !== $e['mode'] ) {
			if ( '' !== $e['venue'] ) {
				$where[] = esc_html( $e['venue'] );
			}
			$addr = Scout_Events::address_label( $e );
			if ( '' !== $addr ) {
				$map     = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( trim( $e['venue'] . ' ' . $addr ) );
				$where[] = esc_html( $addr ) . ' <a class="se-map" href="' . esc_url( $map ) . '" rel="noopener" data-scout-track="directions_click">' . esc_html__( 'Directions', 'scout-events' ) . '</a>';
			}
		}
		if ( 'offline' !== $e['mode'] ) {
			$where[] = esc_html__( 'Online', 'scout-events' );
		}
		if ( $where ) {
			$rows[] = array( __( 'Where', 'scout-events' ), implode( '<br>', $where ) );
		}
		$price = Scout_Events::price_label( $e );
		if ( '' !== $price ) {
			$rows[] = array( __( 'Tickets', 'scout-events' ), esc_html( $price ) );
		}
		if ( $e['min_age'] > 0 ) {
			/* translators: %d: minimum age. */
			$rows[] = array( __( 'Ages', 'scout-events' ), esc_html( sprintf( __( '%d and over', 'scout-events' ), $e['min_age'] ) ) );
		}
		/**
		 * Rows of the details panel, as [label, escaped html] pairs. Scout
		 * Members adds "Members only" here.
		 */
		$rows = (array) apply_filters( 'scout_events_detail_rows', $rows, $id, $e );
		$out .= self::facts( $rows );

		if ( ! $past && 'cancelled' !== $e['status'] ) {
			$cta = '';
			if ( Scout_Events::ticket_open( $e ) ) {
				$cta = '<p class="se-cta"><a class="se-button wp-element-button" href="' . esc_url( $e['ticket_url'] ) . '" data-scout-track="ticket_click" data-scout-event="' . esc_attr( get_the_title( $id ) ) . '">' . esc_html( Scout_Events::ticket_label( $e ) ) . '</a></p>';
			} elseif ( 'soldout' === $e['availability'] ) {
				$cta = '<p class="se-cta"><span class="se-soldout">' . esc_html__( 'Sold out', 'scout-events' ) . '</span></p>';
			} elseif ( 'notyet' === $e['availability'] ) {
				$opens = '' !== $e['sales_start'] ? Scout_Events::day_label( $e['sales_start'] ) : '';
				/* translators: %s: date tickets go on sale. */
				$text  = '' !== $opens ? sprintf( __( 'Tickets go on sale %s.', 'scout-events' ), $opens ) : __( 'Tickets are not on sale yet.', 'scout-events' );
				$cta   = '<p class="se-cta"><span class="se-soon">' . esc_html( $text ) . '</span></p>';
			}
			/** The ticket button or its stand-in. Scout Members swaps it for a join link on members-only events. */
			$out .= (string) apply_filters( 'scout_events_ticket_html', $cta, $id, $e );
		}
		return $out . '</div>';
	}

	/** A small definition list. Values arrive already escaped. */
	private static function facts( array $rows ): string {
		if ( ! $rows ) {
			return '';
		}
		$out = '<dl class="se-facts">';
		foreach ( $rows as $r ) {
			$out .= '<div><dt>' . esc_html( $r[0] ) . '</dt><dd>' . $r[1] . '</dd></div>';
		}
		return $out . '</dl>';
	}

	/* ---------- schedule ---------- */

	/**
	 * The schedule as HTML.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $layout   'list' (time slots down the page, best on phones) or 'grid' (rooms across).
	 */
	public static function schedule( int $event_id, string $layout = 'list' ): string {
		self::styles();
		$days = array();
		foreach ( Scout_Events::session_ids( $event_id ) as $sid ) {
			$s                                  = Scout_Events::session( $sid );
			$days[ $s['date'] ][ $s['start'] ][] = $sid;
		}
		if ( ! $days ) {
			return '';
		}
		// Sessions without a day go last.
		if ( isset( $days[''] ) ) {
			$tba = $days[''];
			unset( $days[''] );
			$days[''] = $tba;
		}
		$out = '<div class="se-schedule is-' . esc_attr( 'grid' === $layout ? 'grid' : 'list' ) . '">';
		foreach ( $days as $day => $slots ) {
			$label = '' !== $day ? Scout_Events::day_label( (string) $day ) : __( 'Day to be announced', 'scout-events' );
			$out  .= '<h3 class="se-day">' . esc_html( $label ) . '</h3>';
			$out  .= 'grid' === $layout ? self::grid( (string) $day, $slots ) : self::slots( (string) $day, $slots );
		}
		return $out . '</div>';
	}

	private static function slots( string $day, array $slots ): string {
		$out = '<ol class="se-slots">';
		foreach ( $slots as $start => $ids ) {
			$time = '' !== $start ? Scout_Events::time_label( Scout_Events::moment( '' !== $day ? $day : '2000-01-01', (string) $start ) ) : __( 'Time to be announced', 'scout-events' );
			$out .= '<li class="se-slot"><p class="se-slot-time">' . esc_html( $time ) . '</p><div class="se-slot-items">';
			foreach ( $ids as $sid ) {
				$out .= self::session_card( $sid );
			}
			$out .= '</div></li>';
		}
		return $out . '</ol>';
	}

	private static function grid( string $day, array $slots ): string {
		$rooms = array();
		foreach ( $slots as $ids ) {
			foreach ( $ids as $sid ) {
				$room           = Scout_Events::session( $sid )['room'];
				$rooms[ $room ] = $room;
			}
		}
		natcasesort( $rooms );
		$out = '<div class="se-grid-wrap" tabindex="0" role="region" aria-label="' . esc_attr__( 'Schedule grid, scrolls sideways', 'scout-events' ) . '"><table class="se-grid-table"><thead><tr><th scope="col">' . esc_html__( 'Time', 'scout-events' ) . '</th>';
		foreach ( $rooms as $room ) {
			$out .= '<th scope="col">' . esc_html( '' !== $room ? $room : __( 'Other', 'scout-events' ) ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $slots as $start => $ids ) {
			$time = '' !== $start ? Scout_Events::time_label( Scout_Events::moment( '' !== $day ? $day : '2000-01-01', (string) $start ) ) : __( 'To be announced', 'scout-events' );
			$out .= '<tr><th scope="row">' . esc_html( $time ) . '</th>';
			foreach ( $rooms as $room ) {
				$out .= '<td>';
				foreach ( $ids as $sid ) {
					if ( Scout_Events::session( $sid )['room'] === $room ) {
						$out .= self::session_card( $sid, false );
					}
				}
				$out .= '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/** One session: title, time and room, who leads it, and the description on demand. */
	private static function session_card( int $sid, bool $show_room = true ): string {
		$s     = Scout_Events::session( $sid );
		$title = esc_html( get_the_title( $sid ) );
		$meta  = array( Scout_Events::session_time_label( $s ) );
		if ( $show_room ) {
			$meta[] = $s['room'];
		}
		if ( '' !== $s['track'] ) {
			$meta[] = $s['track'];
		}
		$meta   = array_filter( $meta );
		$people = self::speaker_links( $s['speakers'] );
		$desc   = trim( (string) get_post_field( 'post_content', $sid ) );

		$out = '<article class="se-session is-' . esc_attr( $s['kind'] ) . '">';
		if ( '' !== $desc ) {
			$out .= '<details><summary><span class="se-session-title">' . $title . '</span></summary>';
			$out .= '<div class="se-session-desc">' . wp_kses_post( wpautop( $desc ) ) . '</div></details>';
		} else {
			$out .= '<p class="se-session-title">' . $title . '</p>';
		}
		if ( $meta ) {
			$out .= '<p class="se-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		}
		if ( '' !== $people ) {
			$out .= '<p class="se-people-line">' . $people . '</p>';
		}
		return $out . '</article>';
	}

	/** Published speakers as linked names, already escaped. */
	private static function speaker_links( array $ids ): string {
		$links = array();
		foreach ( $ids as $pid ) {
			if ( 'publish' === get_post_status( $pid ) ) {
				$links[] = '<a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>';
			}
		}
		return implode( ', ', $links );
	}

	/* ---------- lists ---------- */

	/** Speaker cards. */
	public static function speakers( array $ids ): string {
		self::styles();
		if ( ! $ids ) {
			return '';
		}
		// Each card answers to its speaker's address word (#nick-blum) the first time it appears on a page.
		static $anchored = array();
		$out = '<ul class="se-speakers">';
		foreach ( $ids as $pid ) {
			$p      = Scout_Events::speaker( $pid );
			$anchor = sanitize_title( (string) get_post_field( 'post_name', $pid ) );
			$id_att = '' !== $anchor && ! isset( $anchored[ $anchor ] ) ? ' id="' . esc_attr( $anchor ) . '"' : '';
			$anchored[ $anchor ] = true;
			$out .= '<li class="se-speaker"' . $id_att . '><a href="' . esc_url( get_permalink( $pid ) ) . '">';
			$out .= has_post_thumbnail( $pid )
				? get_the_post_thumbnail( $pid, 'medium', array( 'class' => 'se-speaker-photo', 'alt' => '' ) )
				: '<span class="se-speaker-photo is-empty" aria-hidden="true">' . esc_html( self::initials( get_the_title( $pid ) ) ) . '</span>';
			$out .= '<span class="se-speaker-name">' . esc_html( get_the_title( $pid ) ) . '</span></a>';
			if ( '' !== $p['role'] ) {
				$out .= '<span class="se-speaker-role">' . esc_html( $p['role'] ) . '</span>';
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/** A long list gets month headings on its own. */
	const GROUP_AFTER = 8;

	/**
	 * Event cards. $group is 'month' for month headings, 'none' for one list,
	 * or 'auto' for month headings once the list is longer than GROUP_AFTER.
	 */
	public static function events( array $ids, string $group = 'none' ): string {
		self::styles();
		if ( ! $ids ) {
			return '<p class="se-empty">' . esc_html__( 'New events are on the way. Check back soon.', 'scout-events' ) . '</p>';
		}
		if ( 'month' === $group || ( 'auto' === $group && count( $ids ) > self::GROUP_AFTER ) ) {
			$months = array();
			foreach ( $ids as $id ) {
				$start = Scout_Events::start( Scout_Events::event( $id ) );
				$key   = $start ? wp_date( 'F Y', $start->getTimestamp() ) : __( 'Date to be announced', 'scout-events' );
				$months[ $key ][] = $id;
			}
			$out = '<div class="se-events-by-month">';
			foreach ( $months as $month => $list ) {
				$out .= '<section class="se-month"><h3 class="se-month-title">' . esc_html( $month ) . '</h3>' . self::cards( $list ) . '</section>';
			}
			return $out . '</div>';
		}
		return self::cards( $ids );
	}

	private static function cards( array $ids ): string {
		$out = '<ul class="se-events">';
		foreach ( $ids as $id ) {
			$e     = Scout_Events::event( $id );
			$start = Scout_Events::start( $e );
			$out  .= '<li class="se-event-card">';
			if ( $start ) {
				$out .= '<p class="se-date" aria-hidden="true"><span class="se-date-m">' . esc_html( wp_date( 'M', $start->getTimestamp() ) ) . '</span><span class="se-date-d">' . esc_html( wp_date( 'j', $start->getTimestamp() ) ) . '</span></p>';
			}
			$out .= '<div class="se-event-body"><h3 class="se-event-title"><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3>';
			$line = array_filter( array( Scout_Events::dates_label( $e ), Scout_Events::times_label( $e ), 'online' === $e['mode'] ? __( 'Online', 'scout-events' ) : ( '' !== $e['venue'] ? $e['venue'] : $e['city'] ) ) );
			$out .= '<p class="se-meta">' . esc_html( implode( ' · ', $line ) ) . '</p>';
			$summary = has_excerpt( $id ) ? get_the_excerpt( $id ) : '';
			if ( '' !== $summary ) {
				$out .= '<p class="se-summary">' . esc_html( $summary ) . '</p>';
			}
			$tags = array();
			if ( 'scheduled' !== $e['status'] ) {
				$tags[] = Scout_Events::statuses()[ $e['status'] ];
			} elseif ( 'soldout' === $e['availability'] ) {
				$tags[] = __( 'Sold out', 'scout-events' );
			}
			$price = Scout_Events::price_label( $e );
			if ( '' !== $price ) {
				$tags[] = $price;
			}
			$tags = (array) apply_filters( 'scout_events_card_tags', $tags, $id, $e );
			if ( $tags ) {
				$out .= '<p class="se-tags">' . esc_html( implode( ' · ', $tags ) ) . '</p>';
			}
			$out .= '</div></li>';
		}
		return $out . '</ul>';
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( wp_strip_all_tags( $name ) ) );
		$first = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}

	/* ---------- shortcodes ---------- */

	public static function events_shortcode( $atts ): string {
		$a    = shortcode_atts(
			array(
				'show'   => 'upcoming',
				'limit'  => 0,
				'group'  => 'auto',
				'region' => '',
			),
			(array) $atts,
			'scout_events'
		);
		$show  = in_array( $a['show'], array( 'upcoming', 'past', 'all' ), true ) ? $a['show'] : 'upcoming';
		$group = in_array( $a['group'], array( 'auto', 'month', 'none' ), true ) ? $a['group'] : 'auto';
		if ( '' === $a['region'] ) {
			return self::events( Scout_Events::event_ids( $show, absint( $a['limit'] ) ), $group );
		}
		// One region's events: filter the whole list first, then apply the limit.
		$ids = Scout_Events_Regions::filter( Scout_Events::event_ids( $show ), (string) $a['region'] );
		return self::events( absint( $a['limit'] ) ? array_slice( $ids, 0, absint( $a['limit'] ) ) : $ids, $group );
	}

	public static function event_shortcode( $atts ): string {
		$id = self::event_id( (array) $atts );
		return $id ? self::details( $id ) : '';
	}

	public static function schedule_shortcode( $atts ): string {
		$atts = (array) $atts;
		$id   = self::event_id( $atts );
		return $id ? self::schedule( $id, ( $atts['layout'] ?? '' ) === 'grid' ? 'grid' : 'list' ) : '';
	}

	public static function speakers_shortcode( $atts ): string {
		$id = self::event_id( (array) $atts, false );
		return self::speakers( $id ? Scout_Events::event_speaker_ids( $id ) : Scout_Events::speaker_ids() );
	}

	/** The event a shortcode means: id="", event="", the current event page, or the next upcoming one. */
	private static function event_id( array $atts, bool $fallback = true ): int {
		$want = (string) ( $atts['id'] ?? ( $atts['event'] ?? '' ) );
		$id   = absint( $want );
		if ( ! $id && '' !== $want ) {
			// An event's address word works too: [scout_speakers event="bliss-boogie-2026"].
			$found = get_page_by_path( sanitize_title( $want ), OBJECT, Scout_Events::EVENT );
			$id    = $found ? (int) $found->ID : 0;
		}
		if ( ! $id && is_singular( Scout_Events::EVENT ) ) {
			$id = (int) get_the_ID();
		}
		if ( ! $id && $fallback ) {
			$next = Scout_Events::event_ids( 'upcoming', 1 );
			$id   = $next ? $next[0] : 0;
		}
		return ( $id && Scout_Events::EVENT === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}
}
