<?php
/**
 * The events content model: three post types and one protected meta array
 * per post, plus the date, price and readiness helpers everything else uses.
 *
 *   scout_event    An event. Title, description (editor), photo (featured
 *                  image), and _scout_event: dates, venue, tickets, rules.
 *   scout_session  One class, talk or performance inside an event.
 *                  _scout_session: event, day, times, room, track, speakers.
 *   scout_speaker  A presenter. Name, full bio (editor), photo, and
 *                  _scout_speaker: role, short bio, links.
 *
 * A few values are copied to their own meta keys only so lists can be
 * sorted and filtered in the database: _scout_event_start, _scout_event_end,
 * _scout_session_event and _scout_session_start. The arrays stay the source.
 *
 * Dates are "YYYY-MM-DD" and times "HH:MM" (24 hour), both in the site's
 * timezone (Settings, General). None of the types has an archive, so the
 * plugin never takes over an /events/ or /speakers/ page the site already
 * has: lists are placed with shortcodes on ordinary pages.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events {

	const EVENT   = 'scout_event';
	const SESSION = 'scout_session';
	const SPEAKER = 'scout_speaker';

	const OPTION = 'scout_events';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/** @return string[] */
	public static function post_types(): array {
		return array( self::EVENT, self::SESSION, self::SPEAKER );
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'event_slug'   => 'event',
				'speaker_slug' => 'speaker',
				'session_slug' => 'session',
				'currency'     => 'USD',
				'schema'       => 1,
			)
		);
		foreach ( array( 'event_slug', 'speaker_slug', 'session_slug' ) as $key ) {
			$s[ $key ] = sanitize_title( (string) $s[ $key ] );
		}
		$s['currency'] = preg_match( '/^[A-Z]{3}$/', (string) $s['currency'] ) ? $s['currency'] : 'USD';
		$s['schema']   = ! empty( $s['schema'] ) ? 1 : 0;
		return $s;
	}

	public static function register_post_types(): void {
		if ( post_type_exists( self::EVENT ) ) {
			return;
		}
		$s = self::settings();

		register_post_type(
			self::EVENT,
			array(
				'labels'        => array(
					'name'               => __( 'Events', 'scout-events' ),
					'singular_name'      => __( 'Event', 'scout-events' ),
					'menu_name'          => __( 'Events', 'scout-events' ),
					'add_new'            => __( 'Add event', 'scout-events' ),
					'add_new_item'       => __( 'Add event', 'scout-events' ),
					'edit_item'          => __( 'Edit event', 'scout-events' ),
					'new_item'           => __( 'New event', 'scout-events' ),
					'view_item'          => __( 'View event', 'scout-events' ),
					'search_items'       => __( 'Search events', 'scout-events' ),
					'all_items'          => __( 'All events', 'scout-events' ),
					'not_found'          => __( 'No events yet.', 'scout-events' ),
					'not_found_in_trash' => __( 'No events in the trash.', 'scout-events' ),
					'featured_image'     => __( 'Event photo', 'scout-events' ),
					'set_featured_image' => __( 'Set event photo', 'scout-events' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-tickets-alt',
				'menu_position' => 25,
				'has_archive'   => false,
				'rewrite'       => array(
					'slug'       => $s['event_slug'],
					'with_front' => false,
				),
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
			)
		);

		register_post_type(
			self::SESSION,
			array(
				'labels'        => array(
					'name'               => __( 'Sessions', 'scout-events' ),
					'singular_name'      => __( 'Session', 'scout-events' ),
					'menu_name'          => __( 'Sessions', 'scout-events' ),
					'add_new'            => __( 'Add session', 'scout-events' ),
					'add_new_item'       => __( 'Add session', 'scout-events' ),
					'edit_item'          => __( 'Edit session', 'scout-events' ),
					'new_item'           => __( 'New session', 'scout-events' ),
					'view_item'          => __( 'View session', 'scout-events' ),
					'search_items'       => __( 'Search sessions', 'scout-events' ),
					'all_items'          => __( 'All sessions', 'scout-events' ),
					'not_found'          => __( 'No sessions yet.', 'scout-events' ),
					'not_found_in_trash' => __( 'No sessions in the trash.', 'scout-events' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-schedule',
				'has_archive'   => false,
				'rewrite'       => array(
					'slug'       => $s['session_slug'],
					'with_front' => false,
				),
				'supports'      => array( 'title', 'editor', 'revisions' ),
			)
		);

		register_post_type(
			self::SPEAKER,
			array(
				'labels'        => array(
					'name'                  => __( 'Speakers', 'scout-events' ),
					'singular_name'         => __( 'Speaker', 'scout-events' ),
					'menu_name'             => __( 'Speakers', 'scout-events' ),
					'add_new'               => __( 'Add speaker', 'scout-events' ),
					'add_new_item'          => __( 'Add speaker', 'scout-events' ),
					'edit_item'             => __( 'Edit speaker', 'scout-events' ),
					'new_item'              => __( 'New speaker', 'scout-events' ),
					'view_item'             => __( 'View speaker', 'scout-events' ),
					'search_items'          => __( 'Search speakers', 'scout-events' ),
					'all_items'             => __( 'All speakers', 'scout-events' ),
					'not_found'             => __( 'No speakers yet.', 'scout-events' ),
					'not_found_in_trash'    => __( 'No speakers in the trash.', 'scout-events' ),
					'featured_image'        => __( 'Photo', 'scout-events' ),
					'set_featured_image'    => __( 'Set photo', 'scout-events' ),
					'remove_featured_image' => __( 'Remove photo', 'scout-events' ),
					'use_featured_image'    => __( 'Use as photo', 'scout-events' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-microphone',
				'has_archive'   => false,
				'rewrite'       => array(
					'slug'       => $s['speaker_slug'],
					'with_front' => false,
				),
				'supports'      => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'revisions' ),
			)
		);
	}

	/** Flush rewrite rules once after the slugs change or the plugin updates. */
	public static function maybe_flush(): void {
		$s   = self::settings();
		$key = SCOUT_EVENTS_VERSION . '|' . $s['event_slug'] . '|' . $s['session_slug'] . '|' . $s['speaker_slug'];
		if ( get_option( 'scout_events_rewrite' ) !== $key ) {
			flush_rewrite_rules( false );
			update_option( 'scout_events_rewrite', $key, true );
		}
	}

	/* ---------- choices ---------- */

	public static function statuses(): array {
		return array(
			'scheduled'   => __( 'On as planned', 'scout-events' ),
			'postponed'   => __( 'Postponed, new date to come', 'scout-events' ),
			'rescheduled' => __( 'Moved to a new date', 'scout-events' ),
			'cancelled'   => __( 'Cancelled', 'scout-events' ),
		);
	}

	public static function modes(): array {
		return array(
			'offline' => __( 'In person', 'scout-events' ),
			'online'  => __( 'Online', 'scout-events' ),
			'mixed'   => __( 'In person and online', 'scout-events' ),
		);
	}

	public static function availabilities(): array {
		return array(
			'onsale'  => __( 'On sale', 'scout-events' ),
			'notyet'  => __( 'Not on sale yet', 'scout-events' ),
			'soldout' => __( 'Sold out', 'scout-events' ),
			'free'    => __( 'Free, no ticket needed', 'scout-events' ),
			'none'    => __( 'No tickets on this site', 'scout-events' ),
		);
	}

	/** schema.org types an event can be. */
	public static function schema_types(): array {
		return array(
			'Event'          => __( 'Event', 'scout-events' ),
			'EducationEvent' => __( 'Class, workshop or training', 'scout-events' ),
			'Festival'       => __( 'Festival', 'scout-events' ),
			'SocialEvent'    => __( 'Social gathering', 'scout-events' ),
		);
	}

	public static function kinds(): array {
		return array(
			'session'     => __( 'Class or talk', 'scout-events' ),
			'keynote'     => __( 'Keynote', 'scout-events' ),
			'performance' => __( 'Performance', 'scout-events' ),
			'activity'    => __( 'Activity', 'scout-events' ),
			'break'       => __( 'Break or meal', 'scout-events' ),
		);
	}

	/* ---------- events ---------- */

	public static function empty_event(): array {
		return array(
			'start_date'   => '',
			'start_time'   => '',
			'end_date'     => '',
			'end_time'     => '',
			'status'       => 'scheduled',
			'mode'         => 'offline',
			'venue'        => '',
			'street'       => '',
			'city'         => '',
			'region'       => '',
			'postal'       => '',
			'country'      => 'US',
			'online_url'   => '',
			'availability' => 'none',
			'ticket_url'   => '',
			'ticket_label' => '',
			'price_from'   => '',
			'price_to'     => '',
			'sales_start'  => '',
			'sales_end'    => '',
			'min_age'      => 0,
			'capacity'     => 0,
			'know'         => '',
			'schema_type'  => 'Event',
		);
	}

	public static function event( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_scout_event', true );
		return self::sanitize_event( is_array( $raw ) ? $raw : array() );
	}

	public static function sanitize_event( array $in ): array {
		$e   = self::empty_event();
		$out = array();
		foreach ( array( 'start_date', 'end_date', 'sales_start', 'sales_end' ) as $k ) {
			$out[ $k ] = self::clean_date( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'start_time', 'end_time' ) as $k ) {
			$out[ $k ] = self::clean_time( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'venue', 'street', 'city', 'region', 'postal', 'ticket_label' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		$country        = strtoupper( sanitize_text_field( (string) ( $in['country'] ?? 'US' ) ) );
		$out['country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'US';
		foreach ( array( 'online_url', 'ticket_url' ) as $k ) {
			$out[ $k ] = esc_url_raw( trim( (string) ( $in[ $k ] ?? '' ) ), array( 'http', 'https' ) );
		}
		foreach ( array( 'price_from', 'price_to' ) as $k ) {
			$out[ $k ] = self::clean_price( (string) ( $in[ $k ] ?? '' ) );
		}
		$out['status']       = self::pick( $in['status'] ?? '', self::statuses(), $e['status'] );
		$out['mode']         = self::pick( $in['mode'] ?? '', self::modes(), $e['mode'] );
		$out['availability'] = self::pick( $in['availability'] ?? '', self::availabilities(), $e['availability'] );
		$out['schema_type']  = self::pick( $in['schema_type'] ?? '', self::schema_types(), $e['schema_type'] );
		$out['min_age']      = min( 99, absint( $in['min_age'] ?? 0 ) );
		$out['capacity']     = absint( $in['capacity'] ?? 0 );
		$out['know']         = sanitize_textarea_field( (string) ( $in['know'] ?? '' ) );

		// An end before the start is a typo; drop it rather than publish it.
		if ( '' !== $out['end_date'] && '' !== $out['start_date'] && $out['end_date'] < $out['start_date'] ) {
			$out['end_date'] = '';
		}
		// "$30 to $30" reads as one price.
		if ( '' !== $out['price_to'] && $out['price_to'] === $out['price_from'] ) {
			$out['price_to'] = '';
		}
		if ( '' !== $out['price_to'] && '' !== $out['price_from'] && (float) $out['price_to'] < (float) $out['price_from'] ) {
			list( $out['price_from'], $out['price_to'] ) = array( $out['price_to'], $out['price_from'] );
		}
		return array_merge( $e, $out );
	}

	/** Save an event's array and the sortable copies of its dates. */
	public static function save_event( int $post_id, array $e ): void {
		$e = self::sanitize_event( $e );
		update_post_meta( $post_id, '_scout_event', $e );
		$start = self::start( $e );
		$end   = self::end( $e );
		update_post_meta( $post_id, '_scout_event_start', $start ? $start->format( 'Y-m-d H:i' ) : '' );
		update_post_meta( $post_id, '_scout_event_end', $end ? $end->format( 'Y-m-d H:i' ) : '' );
	}

	/** The event's start as a moment in the site timezone, or null. */
	public static function start( array $e ): ?DateTimeImmutable {
		return self::moment( $e['start_date'], '' !== $e['start_time'] ? $e['start_time'] : '00:00' );
	}

	/**
	 * The event's end, or null. Missing pieces fall back to the start: an end
	 * time with no end date means the same day, and no end at all means the
	 * end of the start day.
	 */
	public static function end( array $e ): ?DateTimeImmutable {
		$date = '' !== $e['end_date'] ? $e['end_date'] : $e['start_date'];
		if ( '' === $date ) {
			return null;
		}
		if ( '' !== $e['end_time'] ) {
			$end   = self::moment( $date, $e['end_time'] );
			$start = self::start( $e );
			// A late-night event on one day ends after midnight.
			if ( $end && $start && $end <= $start && '' === $e['end_date'] ) {
				$end = $end->modify( '+1 day' );
			}
			return $end;
		}
		return self::moment( $date, '23:59' );
	}

	/** True once the event has finished. */
	public static function is_past( array $e ): bool {
		$end = self::end( $e );
		return $end && $end < self::now();
	}

	/**
	 * Event ids, soonest first.
	 *
	 * @param string $when 'upcoming' (not finished yet), 'past' or 'all'.
	 * @param int    $limit 0 for all.
	 * @return int[]
	 */
	public static function event_ids( string $when = 'upcoming', int $limit = 0 ): array {
		$args = array(
			'post_type'        => self::EVENT,
			'post_status'      => 'publish',
			'posts_per_page'   => $limit > 0 ? $limit : -1,
			'fields'           => 'ids',
			'meta_key'         => '_scout_event_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small table, sorted list.
			'orderby'          => 'meta_value',
			'order'            => 'past' === $when ? 'DESC' : 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		$now = self::now()->format( 'Y-m-d H:i' );
		if ( 'upcoming' === $when ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table.
				array(
					'key'     => '_scout_event_end',
					'value'   => $now,
					'compare' => '>=',
				),
			);
		} elseif ( 'past' === $when ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table.
				array(
					'key'     => '_scout_event_end',
					'value'   => $now,
					'compare' => '<',
				),
			);
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/** "Friday, September 18, 2026" or "September 18 to 19, 2026". */
	public static function dates_label( array $e ): string {
		$start = self::start( $e );
		if ( ! $start ) {
			return '';
		}
		$end_day = '' !== $e['end_date'] ? self::moment( $e['end_date'], '12:00' ) : null;
		if ( ! $end_day || $end_day->format( 'Y-m-d' ) === $start->format( 'Y-m-d' ) ) {
			return wp_date( _x( 'l, F j, Y', 'one-day event date', 'scout-events' ), $start->getTimestamp() );
		}
		if ( $end_day->format( 'Y-m' ) === $start->format( 'Y-m' ) ) {
			/* translators: 1: month and first day, 2: last day, 3: year. */
			return sprintf( __( '%1$s to %2$s, %3$s', 'scout-events' ), wp_date( 'F j', $start->getTimestamp() ), wp_date( 'j', $end_day->getTimestamp() ), wp_date( 'Y', $start->getTimestamp() ) );
		}
		if ( $end_day->format( 'Y' ) === $start->format( 'Y' ) ) {
			/* translators: 1: first month and day, 2: last month and day, 3: year. */
			return sprintf( __( '%1$s to %2$s, %3$s', 'scout-events' ), wp_date( 'F j', $start->getTimestamp() ), wp_date( 'F j', $end_day->getTimestamp() ), wp_date( 'Y', $start->getTimestamp() ) );
		}
		/* translators: 1: first date, 2: last date. */
		return sprintf( __( '%1$s to %2$s', 'scout-events' ), wp_date( 'F j, Y', $start->getTimestamp() ), wp_date( 'F j, Y', $end_day->getTimestamp() ) );
	}

	/** "7:00 PM to 10:00 PM", "Starts 7:00 PM", or ''. */
	public static function times_label( array $e ): string {
		if ( '' === $e['start_time'] ) {
			return '';
		}
		$start = self::start( $e );
		if ( '' !== $e['end_time'] && '' !== $e['end_date'] && $e['end_date'] !== $e['start_date'] ) {
			$end = self::moment( $e['end_date'], $e['end_time'] );
			/* translators: 1: start weekday, 2: start time, 3: end weekday, 4: end time. */
			return sprintf( __( 'Starts %1$s at %2$s, ends %3$s at %4$s', 'scout-events' ), wp_date( 'l', $start->getTimestamp() ), self::time_label( $start ), wp_date( 'l', $end->getTimestamp() ), self::time_label( $end ) );
		}
		if ( '' !== $e['end_time'] ) {
			$end = self::moment( $e['start_date'], $e['end_time'] );
			/* translators: 1: start time, 2: end time. */
			return sprintf( __( '%1$s to %2$s', 'scout-events' ), self::time_label( $start ), self::time_label( $end ) );
		}
		/* translators: %s: start time. */
		return sprintf( __( 'Starts %s', 'scout-events' ), self::time_label( $start ) );
	}

	/** "$30", "$30 to $150", "Free" or ''. */
	public static function price_label( array $e ): string {
		if ( 'free' === $e['availability'] ) {
			return __( 'Free', 'scout-events' );
		}
		if ( '' === $e['price_from'] ) {
			return '';
		}
		$from = self::money( $e['price_from'] );
		if ( '' === $e['price_to'] ) {
			return $from;
		}
		/* translators: 1: lowest price, 2: highest price. */
		return sprintf( __( '%1$s to %2$s', 'scout-events' ), $from, self::money( $e['price_to'] ) );
	}

	/** One line of the full address, or ''. */
	public static function address_label( array $e ): string {
		$city = trim( implode( ', ', array_filter( array( $e['city'], trim( $e['region'] . ' ' . $e['postal'] ) ) ) ) );
		return implode( ', ', array_filter( array( $e['street'], $city ) ) );
	}

	/** "What to know" as a list of lines. */
	public static function know_lines( array $e ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", $e['know'] ) ) ) );
	}

	/** The ticket button's words. */
	public static function ticket_label( array $e ): string {
		if ( '' !== $e['ticket_label'] ) {
			return $e['ticket_label'];
		}
		return 'free' === $e['availability'] ? __( 'Save your spot', 'scout-events' ) : __( 'Get tickets', 'scout-events' );
	}

	/** Show the ticket button? Only while it can do something. */
	public static function ticket_open( array $e ): bool {
		return '' !== $e['ticket_url'] && in_array( $e['availability'], array( 'onsale', 'free' ), true ) && 'cancelled' !== $e['status'] && ! self::is_past( $e );
	}

	/**
	 * What still needs filling in before an event is ready to share, as
	 * [label, done] pairs. Shown in the editor and on the Events screen.
	 *
	 * @return array<int,array{0:string,1:bool}>
	 */
	public static function checks( int $post_id ): array {
		$e     = self::event( $post_id );
		$place = 'online' === $e['mode'] ? '' !== $e['online_url'] : ( '' !== $e['venue'] && '' !== $e['city'] );
		$sell  = in_array( $e['availability'], array( 'onsale', 'free' ), true );
		return array(
			array( __( 'Start date and time', 'scout-events' ), '' !== $e['start_date'] && '' !== $e['start_time'] ),
			array( __( 'Where it happens', 'scout-events' ), $place ),
			array( __( 'A description', 'scout-events' ), '' !== trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) ) ),
			array( __( 'An event photo', 'scout-events' ), has_post_thumbnail( $post_id ) ),
			array( __( 'Ticket link while on sale', 'scout-events' ), ! $sell || '' !== $e['ticket_url'] ),
			array( __( 'Price, for Google', 'scout-events' ), 'free' === $e['availability'] || 'none' === $e['availability'] || '' !== $e['price_from'] ),
		);
	}

	/* ---------- sessions ---------- */

	public static function empty_session(): array {
		return array(
			'event'    => 0,
			'date'     => '',
			'start'    => '',
			'end'      => '',
			'room'     => '',
			'track'    => '',
			'kind'     => 'session',
			'speakers' => array(),
			'capacity' => 0,
		);
	}

	public static function session( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_scout_session', true );
		return self::sanitize_session( is_array( $raw ) ? $raw : array() );
	}

	public static function sanitize_session( array $in ): array {
		$speakers = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['speakers'] ?? array() ) ) ) ) );
		return array(
			'event'    => absint( $in['event'] ?? 0 ),
			'date'     => self::clean_date( (string) ( $in['date'] ?? '' ) ),
			'start'    => self::clean_time( (string) ( $in['start'] ?? '' ) ),
			'end'      => self::clean_time( (string) ( $in['end'] ?? '' ) ),
			'room'     => sanitize_text_field( (string) ( $in['room'] ?? '' ) ),
			'track'    => sanitize_text_field( (string) ( $in['track'] ?? '' ) ),
			'kind'     => self::pick( $in['kind'] ?? '', self::kinds(), 'session' ),
			'speakers' => $speakers,
			'capacity' => absint( $in['capacity'] ?? 0 ),
		);
	}

	public static function save_session( int $post_id, array $s ): void {
		$s = self::sanitize_session( $s );
		update_post_meta( $post_id, '_scout_session', $s );
		update_post_meta( $post_id, '_scout_session_event', $s['event'] );
		update_post_meta( $post_id, '_scout_session_start', trim( $s['date'] . ' ' . $s['start'] ) );
	}

	/**
	 * Published sessions of one event, in schedule order.
	 *
	 * @return int[]
	 */
	public static function session_ids( int $event_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => self::SESSION,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => '_scout_session_event', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small table.
				'meta_value'       => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small table.
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		return self::sort_sessions( array_map( 'intval', $ids ) );
	}

	/**
	 * Published sessions a speaker appears in, in schedule order.
	 *
	 * @return int[]
	 */
	public static function sessions_for_speaker( int $speaker_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => self::SESSION,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$mine = array();
		foreach ( $ids as $id ) {
			if ( in_array( $speaker_id, self::session( (int) $id )['speakers'], true ) ) {
				$mine[] = (int) $id;
			}
		}
		return self::sort_sessions( $mine );
	}

	/** Sort session ids by day, start time, then room, then title. */
	public static function sort_sessions( array $ids ): array {
		$keys = array();
		foreach ( $ids as $id ) {
			$s           = self::session( $id );
			$keys[ $id ] = ( '' !== $s['date'] ? $s['date'] : '9999-99-99' ) . ' ' . ( '' !== $s['start'] ? $s['start'] : '99:99' ) . ' ' . strtolower( $s['room'] ) . ' ' . strtolower( (string) get_the_title( $id ) );
		}
		asort( $keys, SORT_STRING );
		return array_map( 'intval', array_keys( $keys ) );
	}

	/** "7:00 PM to 8:30 PM", "7:00 PM", or ''. */
	public static function session_time_label( array $s ): string {
		if ( '' === $s['start'] ) {
			return '';
		}
		$day   = '' !== $s['date'] ? $s['date'] : self::now()->format( 'Y-m-d' );
		$start = self::time_label( self::moment( $day, $s['start'] ) );
		if ( '' === $s['end'] ) {
			return $start;
		}
		/* translators: 1: start time, 2: end time. */
		return sprintf( __( '%1$s to %2$s', 'scout-events' ), $start, self::time_label( self::moment( $day, $s['end'] ) ) );
	}

	/** Speaker ids across every session of an event, in first-appearance order. */
	public static function event_speaker_ids( int $event_id ): array {
		$out = array();
		foreach ( self::session_ids( $event_id ) as $sid ) {
			foreach ( self::session( $sid )['speakers'] as $pid ) {
				if ( 'publish' === get_post_status( $pid ) ) {
					$out[ $pid ] = $pid;
				}
			}
		}
		return array_values( $out );
	}

	/* ---------- speakers ---------- */

	public static function empty_speaker(): array {
		return array(
			'role'    => '',
			'short'   => '',
			'website' => '',
			'links'   => array(),
		);
	}

	public static function speaker( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_scout_speaker', true );
		return self::sanitize_speaker( is_array( $raw ) ? $raw : array() );
	}

	public static function sanitize_speaker( array $in ): array {
		$links = $in['links'] ?? array();
		if ( is_string( $links ) ) {
			$links = explode( "\n", $links );
		}
		$clean = array();
		foreach ( (array) $links as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$clean[] = $url;
			}
		}
		return array(
			'role'    => sanitize_text_field( (string) ( $in['role'] ?? '' ) ),
			'short'   => sanitize_textarea_field( (string) ( $in['short'] ?? '' ) ),
			'website' => esc_url_raw( trim( (string) ( $in['website'] ?? '' ) ), array( 'http', 'https' ) ),
			'links'   => array_values( array_unique( $clean ) ),
		);
	}

	/**
	 * Published speakers, in the order set on the speaker list.
	 *
	 * @return int[]
	 */
	public static function speaker_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::SPEAKER,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			)
		);
	}

	/* ---------- small helpers ---------- */

	/** Now, in the site's timezone. Filterable so tests can set the clock. */
	public static function now(): DateTimeImmutable {
		$now = (int) apply_filters( 'scout_events_now', time() );
		return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
	}

	/** A date and time in the site timezone, or null. */
	public static function moment( string $date, string $time ): ?DateTimeImmutable {
		if ( '' === self::clean_date( $date ) ) {
			return null;
		}
		$time = '' !== self::clean_time( $time ) ? self::clean_time( $time ) : '00:00';
		$m    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		return $m ? $m : null;
	}

	/** Site time format, e.g. "7:00 PM". */
	public static function time_label( ?DateTimeInterface $t ): string {
		return $t ? wp_date( (string) get_option( 'time_format', 'g:i a' ), $t->getTimestamp() ) : '';
	}

	/** "Friday, September 18" for a stored date. */
	public static function day_label( string $ymd ): string {
		$d = self::moment( $ymd, '12:00' );
		return $d ? wp_date( _x( 'l, F j', 'schedule day', 'scout-events' ), $d->getTimestamp() ) : '';
	}

	/** "$150" or "$12.50" in the site currency. */
	public static function money( string $amount ): string {
		$n        = (float) $amount;
		$currency = self::settings()['currency'];
		$symbols  = array(
			'USD' => '$',
			'CAD' => '$',
			'AUD' => '$',
			'EUR' => '€',
			'GBP' => '£',
		);
		$number   = floor( $n ) === $n ? number_format_i18n( $n ) : number_format_i18n( $n, 2 );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $number . ' ' . $currency;
	}

	/** "YYYY-MM-DD" or ''. */
	public static function clean_date( string $date ): string {
		$date = trim( $date );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $date;
	}

	/** "HH:MM" (24 hour) or ''. */
	public static function clean_time( string $time ): string {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)/', trim( $time ), $m ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/** "150", "12.5" or '' from whatever was typed ("$150", "150.00"). */
	public static function clean_price( string $price ): string {
		$price = preg_replace( '/[^0-9.]/', '', $price );
		if ( '' === $price || ! is_numeric( $price ) ) {
			return '';
		}
		$n = number_format( round( (float) $price, 2 ), 2, '.', '' );
		return str_ends_with( $n, '.00' ) ? substr( $n, 0, -3 ) : rtrim( $n, '0' );
	}

	/** A key from a list of choices, else the default. */
	private static function pick( $value, array $choices, string $default ): string {
		$value = (string) $value;
		return isset( $choices[ $value ] ) ? $value : $default;
	}
}
