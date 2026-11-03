<?php
/**
 * A directory people can search: [scout_directory].
 *
 * Speakers (the default), classes or vendors as cards, with a search box,
 * topic buttons and, when there is more than one region, region buttons. It
 * works without JavaScript: the search and the buttons are an ordinary form
 * and links (?se_q=breath&se_topic=yoga&se_region=midwest), so a filtered
 * list can be shared or bookmarked. With JavaScript it filters as people type
 * or tap.
 *
 * Topics are tags on speakers, sessions and vendors (scout_topic), edited on
 * each one, under Events, Topics, or with a "topics" column in the CSV files.
 * Regions come from Scout_Events_Regions.
 *
 *   [scout_directory]                         every speaker
 *   [scout_directory type="classes"]          classes, a repeated class once
 *   [scout_directory type="vendors"]          vendors
 *   [scout_directory topic="yoga"]            starts on one topic
 *   [scout_directory region="midwest"]        only one region, no region buttons
 *   [scout_directory event="summit-2026"]     only one event's speakers or classes
 *   [scout_directory type="classes" show="upcoming"]  only classes at events still to come
 *   [scout_directory search="no"]             buttons only
 *   [scout_directory layout="list"]           a contact list: search, a kind-of-work and a
 *                                             where choice, everyone A to Z, each name opens
 *                                             its sheet in place (speakers and vendors)
 *   [scout_directory contact="yes"]           cards also show where they work and how to
 *                                             reach them: website, booking, email, phone,
 *                                             social links (speakers and vendors). For a
 *                                             members-only page; a public directory leaves it off.
 *
 * Put it on a members-only page (Scout Members) for a private directory, or
 * on any page for a public one.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Directory {

	const TOPIC = 'scout_topic';

	/** Topic buttons shown before "More topics". */
	const FIRST_TOPICS = 12;

	/** Topics named on each card. */
	const CARD_TOPICS = 4;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_shortcode( 'scout_directory', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'scout_events_imported_speaker', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( 'scout_events_imported_session', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( 'scout_events_imported_vendor', array( __CLASS__, 'imported' ), 10, 2 );
	}

	public static function register(): void {
		register_taxonomy(
			self::TOPIC,
			array( Scout_Events::SPEAKER, Scout_Events::SESSION, Scout_Events_Vendors::TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Topics', 'scout-events' ),
					'singular_name' => __( 'Topic', 'scout-events' ),
					'search_items'  => __( 'Search topics', 'scout-events' ),
					'all_items'     => __( 'All topics', 'scout-events' ),
					'edit_item'     => __( 'Edit topic', 'scout-events' ),
					'add_new_item'  => __( 'Add a topic', 'scout-events' ),
					'not_found'     => __( 'No topics yet.', 'scout-events' ),
					'separate_items_with_commas' => __( 'Separate topics with commas, such as yoga, breath, healing.', 'scout-events' ),
				),
				// No topic archive pages: the directory is where topics are browsed.
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}

	/** Events, Topics: right after Speakers. */
	public static function menu(): void {
		add_submenu_page( Scout_Events_Admin::SLUG, __( 'Speaker topics', 'scout-events' ), __( 'Topics', 'scout-events' ), 'manage_categories', 'edit-tags.php?taxonomy=' . self::TOPIC . '&post_type=' . Scout_Events::SPEAKER );
		global $submenu;
		$slug = Scout_Events_Admin::SLUG;
		if ( empty( $submenu[ $slug ] ) ) {
			return;
		}
		$items = array_values( $submenu[ $slug ] );
		// Topics, then Regions (added by Scout_Events_Regions before this runs), right after Speakers.
		$links = array( 'edit-tags.php?taxonomy=' . self::TOPIC . '&post_type=' . Scout_Events::SPEAKER, 'edit-tags.php?taxonomy=' . Scout_Events_Regions::TAX . '&post_type=' . Scout_Events::EVENT );
		$mine  = array();
		foreach ( $links as $link ) {
			$mine = array_merge( $mine, array_values( array_filter( $items, static fn( $i ) => $link === ( $i[2] ?? '' ) ) ) );
		}
		$items = array_values( array_filter( $items, static fn( $i ) => ! in_array( $i[2] ?? '', $links, true ) ) );
		$at    = count( $items );
		foreach ( $items as $i => $item ) {
			if ( 'edit.php?post_type=' . Scout_Events::SPEAKER === ( $item[2] ?? '' ) ) {
				$at = $i + 1;
			}
		}
		array_splice( $items, $at, 0, $mine );
		$submenu[ $slug ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu.
	}

	/** A "topics" column ("Yoga|Breath") in speakers.csv, sessions.csv or vendors.csv replaces that row's topics. */
	public static function imported( $id, $row ): void {
		$raw = trim( (string) ( ( (array) $row )['topics'] ?? '' ) );
		if ( '' === $raw ) {
			return;
		}
		$names = array_values( array_unique( array_filter( array_map( static fn( $t ) => sanitize_text_field( trim( $t ) ), explode( '|', $raw ) ) ) ) );
		wp_set_object_terms( (int) $id, $names, self::TOPIC, false );
	}

	/**
	 * A speaker's topics, by name.
	 *
	 * @return array<string,string> slug => name
	 */
	public static function topics_of( int $speaker ): array {
		$terms = get_the_terms( $speaker, self::TOPIC );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ $t->slug ] = $t->name;
		}
		return $out;
	}

	/* ---------- the directory ---------- */

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'   => 'speakers',
				'topic'  => '',
				'region' => '',
				'event'  => '',
				'search'  => 'yes',
				'show'    => 'all',
				'contact' => 'no',
				'layout'  => 'grid',
			),
			(array) $atts
		);
		wp_enqueue_style( 'scout-events' );
		$type  = in_array( $atts['type'], array( 'speakers', 'classes', 'vendors' ), true ) ? $atts['type'] : 'speakers';
		$event = 0;
		if ( '' !== $atts['event'] ) {
			$found = get_page_by_path( sanitize_title( $atts['event'] ), OBJECT, Scout_Events::EVENT );
			$event = $found ? (int) $found->ID : -1;
		}
		$fixed = sanitize_title( $atts['region'] );
		$items   = -1 === $event ? array() : self::items( $type, $event, $fixed, 'upcoming' === $atts['show'] );
		$contact = 'yes' === $atts['contact'] && 'classes' !== $type;
		$as_list = 'list' === $atts['layout'] && 'classes' !== $type;
		if ( $as_list ) {
			// A contact list reads A to Z by name, "Dr." and "The" aside.
			usort( $items, static fn( $a, $b ) => strcmp( self::sort_key( $a['title'] ), self::sort_key( $b['title'] ) ) );
		}
		if ( ! $items ) {
			return '<p class="se-empty">' . esc_html__( 'The directory is being put together. Check back soon.', 'scout-events' ) . '</p>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public search form.
		$q      = isset( $_GET['se_q'] ) ? sanitize_text_field( wp_unslash( $_GET['se_q'] ) ) : '';
		$topic  = isset( $_GET['se_topic'] ) ? sanitize_title( wp_unslash( $_GET['se_topic'] ) ) : sanitize_title( $atts['topic'] );
		$region = '' !== $fixed ? '' : ( isset( $_GET['se_region'] ) ? sanitize_title( wp_unslash( $_GET['se_region'] ) ) : '' );
		$book   = $contact && ! empty( $_GET['se_book'] );
		// phpcs:enable

		// Topics and regions used by what is listed, most used first.
		$tcount = array();
		$tname  = array();
		$rcount = array();
		$rname  = array();
		foreach ( $items as $it ) {
			foreach ( $it['topics'] as $slug => $name ) {
				$tcount[ $slug ] = ( $tcount[ $slug ] ?? 0 ) + 1;
				$tname[ $slug ]  = $name;
			}
			foreach ( $it['regions'] as $slug => $name ) {
				$rcount[ $slug ] = ( $rcount[ $slug ] ?? 0 ) + 1;
				$rname[ $slug ]  = $name;
			}
		}
		arsort( $tcount );
		ksort( $rcount );
		$topic  = isset( $tname[ $topic ] ) ? $topic : '';
		$region = isset( $rname[ $region ] ) ? $region : '';
		$total  = count( $items );

		$shown   = 0;
		$list    = '';
		$letters = array();
		$words   = array_filter( preg_split( '/\s+/', self::fold( $q ) ) );
		foreach ( $items as $it ) {
			$hit = ( '' === $topic || isset( $it['topics'][ $topic ] ) ) && ( '' === $region || isset( $it['regions'][ $region ] ) )
				&& ( ! $book || '' !== (string) ( $it['contact']['booking'] ?? '' ) );
			// Every word has to appear somewhere, in any order.
			foreach ( $words as $w ) {
				$hit = $hit && false !== strpos( $it['text'], $w );
			}
			$shown += $hit ? 1 : 0;
			if ( $as_list ) {
				$letters[ self::letter( $it['title'] ) ][] = self::row( $it, $hit, $contact, $type );
			} else {
				$list .= self::card( $it, $hit, $contact );
			}
		}

		// More than one directory on a page keeps its own ids.
		static $count = 0;
		++$count;
		$sfx  = $count > 1 ? '-' . $count : '';
		$base = remove_query_arg( array( 'se_q', 'se_topic', 'se_region' ) );
		$keep = array_filter(
			array(
				'se_q'      => $q,
				'se_topic'  => $topic,
				'se_region' => $region,
			)
		);
		if ( $as_list ) {
			return self::list_markup( $type, $atts, $q, $topic, $region, $book, $tcount, $tname, $rcount, $rname, $letters, $shown, $total, $base, $sfx, $contact, $items );
		}
		$out  = '<div class="se-directory se-directory-' . esc_attr( $type ) . '" data-se-directory>';
		$out .= '<form class="se-dir-tools" method="get" action="' . esc_url( $base ) . '#se-dir-list' . $sfx . '" role="search">';
		if ( 'no' !== $atts['search'] ) {
			$labels = array(
				'speakers' => __( 'Search by name, practice or word', 'scout-events' ),
				'classes'  => __( 'Search classes by title, teacher or word', 'scout-events' ),
				'vendors'  => __( 'Search vendors by name, product or town', 'scout-events' ),
			);
			$out .= '<p class="se-dir-search"><label for="se-dir-q' . $sfx . '">' . esc_html( $labels[ $type ] ) . '</label>'
				. '<span class="se-dir-searchrow"><input type="search" class="se-dir-q" id="se-dir-q' . $sfx . '" name="se_q" value="' . esc_attr( $q ) . '" autocomplete="off">'
				. '<button type="submit" class="se-button wp-element-button se-dir-go">' . esc_html__( 'Search', 'scout-events' ) . '</button></span></p>';
		}
		foreach ( array( 'se_topic' => $topic, 'se_region' => $region ) as $name => $value ) {
			if ( '' !== $value ) {
				$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		$out .= '</form>';

		if ( count( $rcount ) > 1 ) {
			$out .= '<nav class="se-dir-topics se-dir-regions" aria-label="' . esc_attr__( 'Regions', 'scout-events' ) . '"><ul>';
			$out .= self::chip( 'se_region', '', __( 'Every region', 'scout-events' ), $total, $region, $keep, $base, $sfx );
			foreach ( $rcount as $slug => $n ) {
				$out .= self::chip( 'se_region', $slug, $rname[ $slug ], $n, $region, $keep, $base, $sfx );
			}
			$out .= '</ul></nav>';
		}
		if ( $tcount ) {
			$out .= '<nav class="se-dir-topics" aria-label="' . esc_attr__( 'Topics', 'scout-events' ) . '"><ul>';
			$out .= self::chip( 'se_topic', '', __( 'Everything', 'scout-events' ), $total, $topic, $keep, $base, $sfx );
			$i    = 0;
			$more = '';
			foreach ( $tcount as $slug => $n ) {
				$chip = self::chip( 'se_topic', $slug, $tname[ $slug ], $n, $topic, $keep, $base, $sfx );
				// The chosen topic always shows, even when it is one of the rarer ones.
				if ( $i < self::FIRST_TOPICS || $slug === $topic ) {
					$out .= $chip;
				} else {
					$more .= $chip;
				}
				++$i;
			}
			$out .= '</ul>';
			if ( '' !== $more ) {
				$out .= '<details class="se-dir-more"><summary>' . esc_html__( 'More topics', 'scout-events' ) . '</summary><ul>' . $more . '</ul></details>';
			}
			$out .= '</nav>';
		}

		/* translators: 1: number shown, 2: number in the directory. */
		$out .= '<p class="se-dir-count" aria-live="polite" data-se-count data-total="' . $total . '">' . esc_html( sprintf( __( 'Showing %1$d of %2$d', 'scout-events' ), $shown, $total ) ) . '</p>';
		$out .= '<ul class="se-dir-list" id="se-dir-list' . $sfx . '">' . $list . '</ul>';
		$out .= '<p class="se-dir-none"' . ( $shown ? ' hidden' : '' ) . ' data-se-none>' . esc_html__( 'Nothing matches that yet. Try another word or topic.', 'scout-events' ) . '</p>';
		$out .= '</div>';
		self::script();
		return $out;
	}

	/**
	 * What one topic holds, for a topic page: every speaker, class or vendor
	 * with that topic, in directory order.
	 *
	 * @return array<int,array> items as items() returns them
	 */
	public static function on_topic( string $type, string $topic, string $region = '' ): array {
		$type = in_array( $type, array( 'speakers', 'classes', 'vendors' ), true ) ? $type : 'speakers';
		return array_values( array_filter( self::items( $type, 0, sanitize_title( $region ) ), static fn( $it ) => isset( $it['topics'][ $topic ] ) ) );
	}

	/** Directory cards with no search or buttons, all showing. */
	public static function cards( array $items ): string {
		wp_enqueue_style( 'scout-events' );
		return '<ul class="se-dir-list">' . implode( '', array_map( static fn( $it ) => self::card( $it, true ), $items ) ) . '</ul>';
	}

	/**
	 * What the directory lists, as cards: title, link, photo, a few lines,
	 * topics, regions and the text a search looks through.
	 *
	 * @return array<int,array{title:string,url:string,external:bool,photo:string,lines:string[],topics:array<string,string>,regions:array<string,string>,text:string}>
	 */
	private static function items( string $type, int $event, string $region, bool $upcoming = false ): array {
		$out = array();
		if ( 'vendors' === $type ) {
			foreach ( Scout_Events_Vendors::ids( $event ) as $id ) {
				$v     = Scout_Events_Vendors::vendor( $id );
				$about = wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) );
				$out[] = self::item( $id, $v['website'], true, has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, 'medium', array( 'class' => 'se-dir-photo is-logo', 'alt' => '', 'loading' => 'lazy' ) ) : '', array_filter( array( $v['city'], wp_trim_words( '' !== $about ? $about : $v['products'], 24 ) ) ), $v['products'] . ' ' . $about, array( 'website' => $v['website'], 'email' => $v['email'], 'phone' => $v['phone'], 'links' => $v['links'] ) );
			}
		} elseif ( 'classes' === $type ) {
			$out = self::classes( $event, $upcoming );
		} else {
			$ids = Scout_Events::speaker_ids();
			if ( $event ) {
				$ids = array_values( array_intersect( $ids, Scout_Events::event_speaker_ids( $event ) ) );
			}
			foreach ( $ids as $id ) {
				$p     = Scout_Events::speaker( $id );
				$bio   = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 80, '' );
				$out[] = self::item( $id, (string) get_permalink( $id ), false, self::photo( $id ), array_filter( array( $p['role'], wp_trim_words( $p['short'], 28 ) ) ), $p['short'] . ' ' . $bio, array_intersect_key( $p, array_flip( array( 'city', 'website', 'booking', 'email', 'phone', 'links' ) ) ) );
			}
		}
		return '' === $region ? $out : array_values( array_filter( $out, static fn( $it ) => isset( $it['regions'][ $region ] ) ) );
	}

	private static function item( int $id, string $url, bool $external, string $photo, array $lines, string $more, array $contact = array() ): array {
		$topics  = self::topics_of( $id );
		$regions = Scout_Events_Regions::of( $id );
		return array(
			'title'    => get_the_title( $id ),
			'url'      => $url,
			'external' => $external,
			'photo'    => $photo,
			'lines'    => array_values( $lines ),
			'topics'   => $topics,
			'regions'  => $regions,
			'text'     => self::fold( implode( ' ', array_merge( array( get_the_title( $id ) ), $lines, $topics, $regions, array( $more, (string) ( $contact['city'] ?? '' ) ) ) ) ),
			'contact'  => $contact,
		);
	}

	private static function photo( int $id ): string {
		return has_post_thumbnail( $id )
			? get_the_post_thumbnail( $id, 'medium', array( 'class' => 'se-dir-photo', 'alt' => '', 'loading' => 'lazy' ) )
			: '<span class="se-dir-photo is-empty" aria-hidden="true">' . esc_html( self::initials( get_the_title( $id ) ) ) . '</span>';
	}

	/**
	 * Classes: published sessions that are classes, talks, keynotes,
	 * performances or activities (not breaks). A class taught more than once
	 * (same title, same teachers) is one card listing each time.
	 */
	private static function classes( int $event, bool $upcoming = false ): array {
		$live   = $upcoming ? Scout_Events::event_ids( 'upcoming' ) : array();
		$ids = get_posts(
			array(
				'post_type'      => Scout_Events::SESSION,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids    = Scout_Events::sort_sessions( array_map( 'intval', $ids ) );
		$groups = array();
		foreach ( $ids as $id ) {
			$s = Scout_Events::session( $id );
			if ( 'break' === $s['kind'] || ( $event && $s['event'] !== $event ) || ( $upcoming && ! in_array( $s['event'], $live, true ) ) ) {
				continue;
			}
			$key              = strtolower( trim( (string) get_the_title( $id ) ) ) . '|' . implode( ',', $s['speakers'] );
			$groups[ $key ][] = $id;
		}
		$out = array();
		foreach ( $groups as $list ) {
			$first    = $list[0];
			$s        = Scout_Events::session( $first );
			$teachers = array_unique( array_filter( array_map( static fn( $p ) => 'publish' === get_post_status( $p ) ? get_the_title( $p ) : '', $s['speakers'] ) ) );
			$when     = array();
			foreach ( $list as $sid ) {
				$x      = Scout_Events::session( $sid );
				$day    = '' !== $x['date'] ? wp_date( _x( 'D', 'short weekday', 'scout-events' ), (int) strtotime( $x['date'] . ' 12:00' ) ) : '';
				$time   = '' !== $x['start'] ? Scout_Events::time_label( Scout_Events::moment( '' !== $x['date'] ? $x['date'] : '2000-01-01', $x['start'] ) ) : '';
				$when[] = trim( $day . ' ' . $time . ( '' !== $x['room'] ? ' · ' . $x['room'] : '' ) );
			}
			$lines = array_filter(
				array(
					$teachers ? implode( ', ', $teachers ) : '',
					$s['event'] ? get_the_title( $s['event'] ) : '',
					implode( '; ', array_unique( array_filter( $when ) ) ),
				)
			);
			$about = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $first ) ), 60, '' );
			$item  = self::item( $first, (string) get_permalink( $first ), false, '', $lines, $about );
			// A repeated class counts every copy's topics and regions.
			foreach ( array_slice( $list, 1 ) as $sid ) {
				$item['topics']  += self::topics_of( $sid );
				$item['regions'] += Scout_Events_Regions::of( $sid );
			}
			$out[] = $item;
		}
		return $out;
	}

	private static function chip( string $param, string $slug, string $name, int $n, string $current, array $keep, string $base, string $sfx = '' ): string {
		$args = $keep;
		unset( $args[ $param ] );
		if ( '' !== $slug ) {
			$args[ $param ] = $slug;
		}
		$url = ( $args ? add_query_arg( $args, $base ) : $base ) . '#se-dir-list' . $sfx;
		$on  = $slug === $current;
		return '<li><a class="se-dir-chip" href="' . esc_url( $url ) . '" data-se-param="' . esc_attr( $param ) . '" data-se-value="' . esc_attr( $slug ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>'
			. esc_html( $name ) . ' <span class="se-dir-n">' . (int) $n . '</span></a></li>';
	}

	/** Lowercase without accents, so "Reiki" and "reiki" and "Réiki" meet. */
	private static function fold( string $t ): string {
		return strtolower( remove_accents( $t ) );
	}

	private static function card( array $it, bool $hit, bool $contact = false ): string {
		$out  = '<li class="se-dir-card' . ( '' === $it['photo'] ? ' no-photo' : '' ) . '" data-se-text="' . esc_attr( $it['text'] ) . '" data-se-topics="' . esc_attr( implode( ' ', array_keys( $it['topics'] ) ) ) . '" data-se-regions="' . esc_attr( implode( ' ', array_keys( $it['regions'] ) ) ) . '"' . ( $hit ? '' : ' hidden' ) . '>';
		$name = '<span class="se-dir-name">' . esc_html( $it['title'] ) . '</span>';
		if ( '' !== $it['url'] ) {
			$out .= '<a class="se-dir-link" href="' . esc_url( $it['url'] ) . '"' . ( $it['external'] ? ' rel="noopener"' : '' ) . '>' . $it['photo'] . $name . '</a>';
		} else {
			$out .= '<span class="se-dir-link">' . $it['photo'] . $name . '</span>';
		}
		foreach ( $it['lines'] as $i => $line ) {
			$out .= '<span class="' . ( 0 === $i ? 'se-dir-role' : 'se-dir-short' ) . '">' . esc_html( $line ) . '</span>';
		}
		if ( $it['topics'] ) {
			// A few topics on the card; search and the buttons still use all of them.
			$more = count( $it['topics'] ) - self::CARD_TOPICS;
			$out .= '<span class="se-dir-tags">' . esc_html( implode( ' · ', array_slice( $it['topics'], 0, self::CARD_TOPICS ) ) . ( $more > 0 ? ' · +' . $more : '' ) ) . '</span>';
		}
		if ( $contact ) {
			$out .= self::contact( (array) ( $it['contact'] ?? array() ), $it['title'] );
		}
		return $out . '</li>';
	}

	/** "kai baylis" for "Dr. Kai Baylis", so the list and its letters follow the name. */
	private static function sort_key( string $title ): string {
		return self::fold( trim( (string) preg_replace( '/^(dr\.?|the|mr\.?|ms\.?|mrs\.?)\s+/i', '', wp_strip_all_tags( $title ) ) ) );
	}

	/** The letter a name files under, "#" for a name that starts with a digit or symbol. */
	private static function letter( string $title ): string {
		$k = self::sort_key( $title );
		$c = function_exists( 'mb_substr' ) ? mb_substr( $k, 0, 1 ) : substr( $k, 0, 1 );
		return preg_match( '/[a-z]/', $c ) ? strtoupper( $c ) : '#';
	}

	/**
	 * One entry in the list layout: a summary line (photo, name, a line, a
	 * label) that opens to the sheet (contact, topics, the full short bio and
	 * the link to their page). A <details>, so it opens without JavaScript.
	 */
	private static function row( array $it, bool $hit, bool $contact, string $type ): string {
		$c     = (array) ( $it['contact'] ?? array() );
		$kind  = 'vendors' === $type ? __( 'Vendor', 'scout-events' ) : __( 'Speaker', 'scout-events' );
		$line  = (string) ( $it['lines'][1] ?? $it['lines'][0] ?? '' );
		$place = 'vendors' === $type ? (string) ( $it['lines'][0] ?? '' ) : ( $contact ? (string) ( $c['city'] ?? '' ) : (string) ( $it['lines'][0] ?? '' ) );
		$meta  = array_filter( array( $kind, $place ) );
		foreach ( array_slice( $it['topics'], 0, 2 ) as $t ) {
			$meta[] = $t;
		}
		$out  = '<li class="se-dir-row" data-se-text="' . esc_attr( $it['text'] ) . '" data-se-topics="' . esc_attr( implode( ' ', array_keys( $it['topics'] ) ) ) . '" data-se-regions="' . esc_attr( implode( ' ', array_keys( $it['regions'] ) ) ) . '" data-se-book="' . ( '' !== (string) ( $c['booking'] ?? '' ) ? '1' : '0' ) . '"' . ( $hit ? '' : ' hidden' ) . '>';
		$out .= '<details class="se-dir-entry"><summary class="se-dir-head">' . $it['photo']
			. '<span class="se-dir-who"><span class="se-dir-name">' . esc_html( $it['title'] ) . '</span>'
			. ( '' !== $line ? '<span class="se-dir-line">' . esc_html( $line ) . '</span>' : '' )
			. '<span class="se-dir-meta">' . implode( '<span class="se-dir-sep" aria-hidden="true"></span>', array_map( 'esc_html', $meta ) ) . '</span></span>'
			. '<span class="se-dir-open" aria-hidden="true"></span></summary>';
		$out .= '<div class="se-dir-sheet">';
		if ( $contact ) {
			$out .= self::contact( $c, $it['title'] );
		}
		if ( $it['topics'] ) {
			$out .= '<span class="se-dir-tags">' . esc_html( implode( ' · ', $it['topics'] ) ) . '</span>';
		}
		if ( '' !== $it['url'] ) {
			$out .= '<a class="se-dir-profile" href="' . esc_url( $it['url'] ) . '"' . ( $it['external'] ? ' rel="noopener"' : '' ) . '>' . esc_html( $it['external'] ? __( 'Website', 'scout-events' ) : __( 'Full profile', 'scout-events' ) ) . ' <span aria-hidden="true">›</span><span class="screen-reader-text"> ' . esc_html( $it['title'] ) . '</span></a>';
		}
		return $out . '</div></details></li>';
	}

	/**
	 * The list layout: search, a kind-of-work choice, a where choice (when
	 * there is more than one region), "can book online" in contact mode, a
	 * letter rail, then every entry A to Z. The form works without JavaScript.
	 *
	 * @param array<string,string[]> $letters rows by letter
	 * @param array<int,array>       $items   every item, for the book-online check
	 */
	private static function list_markup( string $type, array $atts, string $q, string $topic, string $region, bool $book, array $tcount, array $tname, array $rcount, array $rname, array $letters, int $shown, int $total, string $base, string $sfx, bool $contact, array $items ): string {
		$id   = 'se-dir' . $sfx;
		$out  = '<div class="se-directory se-directory-' . esc_attr( $type ) . ' se-directory-list" data-se-directory data-se-list>';
		$out .= '<form class="se-dir-tools" method="get" action="' . esc_url( $base ) . '#se-dir-list' . $sfx . '" role="search">';
		if ( 'no' !== $atts['search'] ) {
			$label = 'vendors' === $type ? __( 'Search a name, a product or a town', 'scout-events' ) : __( 'Search a name, a town or a word', 'scout-events' );
			$out  .= '<p class="se-dir-search"><label for="' . $id . '-q">' . esc_html( $label ) . '</label>'
				. '<span class="se-dir-searchrow"><input type="search" class="se-dir-q" id="' . $id . '-q" name="se_q" value="' . esc_attr( $q ) . '" autocomplete="off">'
				. '<button type="submit" class="se-button wp-element-button se-dir-go">' . esc_html__( 'Search', 'scout-events' ) . '</button></span></p>';
		}
		$out .= '<div class="se-dir-picks">';
		if ( $tcount ) {
			$out .= '<p class="se-dir-pick"><label for="' . $id . '-topic">' . esc_html__( 'Kind of work', 'scout-events' ) . '</label><select id="' . $id . '-topic" name="se_topic" data-se-select="se_topic"><option value="">' . esc_html__( 'Any kind of work', 'scout-events' ) . '</option>';
			foreach ( $tcount as $slug => $n ) {
				$out .= '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $topic, false ) . '>' . esc_html( $tname[ $slug ] ) . ' (' . (int) $n . ')</option>';
			}
			$out .= '</select></p>';
		}
		if ( count( $rcount ) > 1 ) {
			$out .= '<p class="se-dir-pick"><label for="' . $id . '-region">' . esc_html__( 'Where', 'scout-events' ) . '</label><select id="' . $id . '-region" name="se_region" data-se-select="se_region"><option value="">' . esc_html__( 'Anywhere', 'scout-events' ) . '</option>';
			foreach ( $rcount as $slug => $n ) {
				$out .= '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $region, false ) . '>' . esc_html( $rname[ $slug ] ) . ' (' . (int) $n . ')</option>';
			}
			$out .= '</select></p>';
		}
		if ( $contact && array_filter( $items, static fn( $it ) => '' !== (string) ( $it['contact']['booking'] ?? '' ) ) ) {
			$out .= '<label class="se-dir-check"><input type="checkbox" name="se_book" value="1" data-se-book' . checked( $book, true, false ) . '> ' . esc_html__( 'Can book online', 'scout-events' ) . '</label>';
		}
		$out .= '</div></form>';
		/* translators: 1: number shown, 2: number in the directory. */
		$out .= '<p class="se-dir-count" aria-live="polite" data-se-count data-total="' . $total . '">' . esc_html( sprintf( __( 'Showing %1$d of %2$d', 'scout-events' ), $shown, $total ) ) . '</p>';
		if ( count( $letters ) > 1 ) {
			$out .= '<nav class="se-dir-rail" aria-label="' . esc_attr__( 'Jump to a letter', 'scout-events' ) . '">';
			foreach ( array_keys( $letters ) as $l ) {
				$out .= '<a href="#' . $id . '-' . esc_attr( $l ) . '" data-se-letter="' . esc_attr( $l ) . '">' . esc_html( $l ) . '</a>';
			}
			$out .= '</nav>';
		}
		$out .= '<div class="se-dir-list se-dir-letters" id="se-dir-list' . $sfx . '">';
		foreach ( $letters as $l => $rows ) {
			$any  = (bool) array_filter( $rows, static fn( $r ) => false === strpos( $r, ' hidden>' ) );
			$out .= '<section class="se-dir-letter" id="' . $id . '-' . esc_attr( $l ) . '" data-se-letter="' . esc_attr( $l ) . '"' . ( $any ? '' : ' hidden' ) . '><h2 class="se-dir-lh"><span>' . esc_html( $l ) . '</span></h2><ul class="se-dir-rows">' . implode( '', $rows ) . '</ul></section>';
		}
		$out .= '</div>';
		$out .= '<p class="se-dir-none"' . ( $shown ? ' hidden' : '' ) . ' data-se-none>' . esc_html__( 'Nothing matches that yet. Try another word or choice.', 'scout-events' ) . '</p>';
		$out .= '</div>';
		self::script();
		return $out;
	}

	/** Where they work and how to reach them, for a members directory. */
	private static function contact( array $c, string $name ): string {
		$links = array();
		// "Website Kai Baylis" to a screen reader, so a list of cards is not a list of identical links.
		$sr    = '<span class="screen-reader-text"> ' . esc_html( $name ) . '</span>';
		if ( '' !== (string) ( $c['website'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $c['website'] ) . '" rel="noopener">' . esc_html__( 'Website', 'scout-events' ) . $sr . '</a>';
		}
		if ( '' !== (string) ( $c['booking'] ?? '' ) && ( $c['booking'] ?? '' ) !== ( $c['website'] ?? '' ) ) {
			$links[] = '<a href="' . esc_url( $c['booking'] ) . '" rel="noopener">' . esc_html__( 'Book', 'scout-events' ) . $sr . '</a>';
		}
		if ( '' !== (string) ( $c['email'] ?? '' ) && is_email( $c['email'] ) ) {
			$mail    = antispambot( $c['email'] );
			$links[] = '<a href="mailto:' . $mail . '">' . esc_html__( 'Email', 'scout-events' ) . $sr . '</a>';
		}
		if ( '' !== (string) ( $c['phone'] ?? '' ) ) {
			$tel     = preg_replace( '/[^0-9+]/', '', (string) $c['phone'] );
			$links[] = '<a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $c['phone'] ) . '</a>';
		}
		foreach ( (array) ( $c['links'] ?? array() ) as $url ) {
			$links[] = '<a href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( self::site_name( (string) $url ) ) . '</a>';
		}
		$city = trim( (string) ( $c['city'] ?? '' ) );
		if ( ! $links && '' === $city ) {
			return '';
		}
		$out = '<span class="se-dir-contact">';
		if ( '' !== $city ) {
			$out .= '<span class="se-dir-where">' . esc_html( $city ) . '</span>';
		}
		if ( $links ) {
			$out .= '<span class="se-dir-reach">' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</span>';
		}
		return $out . '</span>';
	}

	/** "Instagram" for instagram.com, the bare host for anything else. */
	private static function site_name( string $url ): string {
		$host  = strtolower( (string) preg_replace( '/^(www|m)\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$names = array(
			'instagram.com'  => 'Instagram',
			'facebook.com'   => 'Facebook',
			'fb.com'         => 'Facebook',
			'linkedin.com'   => 'LinkedIn',
			'youtube.com'    => 'YouTube',
			'youtu.be'       => 'YouTube',
			'tiktok.com'     => 'TikTok',
			'linktr.ee'      => 'Linktree',
			'x.com'          => 'X',
			'twitter.com'    => 'X',
			'threads.net'    => 'Threads',
			'bsky.app'       => 'Bluesky',
			'etsy.com'       => 'Etsy',
			'patreon.com'    => 'Patreon',
			'substack.com'   => 'Substack',
			'podcasts.apple.com' => 'Podcast',
			'open.spotify.com' => 'Spotify',
			'amazon.com'     => 'Amazon',
		);
		foreach ( $names as $h => $n ) {
			if ( $host === $h || str_ends_with( $host, '.' . $h ) ) {
				return $n;
			}
		}
		return '' !== $host ? $host : $url;
	}

	private static function initials( string $name ): string {
		// "AA" for "Dr. Aakriti Agrawal": the title is not a name.
		$parts = preg_split( '/\s+/', trim( (string) preg_replace( '/^(dr\.?|the|mr\.?|ms\.?|mrs\.?)\s+/i', '', wp_strip_all_tags( $name ) ) ) );
		$out   = '';
		foreach ( array_slice( (array) $parts, 0, 2 ) as $w ) {
			$out .= function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 );
		}
		return strtoupper( $out );
	}

	/** Live filtering, once per page. The links and form above work without it. */
	private static function script(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$js   = <<<'JS'
document.querySelectorAll('[data-se-directory]').forEach(function (d) {
	var q = d.querySelector('.se-dir-q'), cards = d.querySelectorAll('.se-dir-card, .se-dir-row'),
		count = d.querySelector('[data-se-count]'), none = d.querySelector('[data-se-none]'),
		chips = d.querySelectorAll('[data-se-param]'), go = d.querySelector('.se-dir-go'),
		pick = { se_topic: '', se_region: '', se_book: '' }, selects = d.querySelectorAll('[data-se-select]'), book = d.querySelector('[data-se-book]'),
		fold = function (t) { return (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); },
		total = count ? count.getAttribute('data-total') : cards.length, template = count ? count.textContent : '';
	d.querySelectorAll('[data-se-param][aria-current="true"]').forEach(function (a) { pick[a.getAttribute('data-se-param')] = a.getAttribute('data-se-value'); });
	selects.forEach(function (s) { pick[s.getAttribute('data-se-select')] = s.value; });
	if (book) { pick.se_book = book.checked ? '1' : ''; }
	if (go) { go.hidden = true; }
	function has(list, v) { return !v || (' ' + list + ' ').indexOf(' ' + v + ' ') > -1; }
	function run() {
		var words = fold(q ? q.value : '').split(/\s+/).filter(Boolean), shown = 0;
		cards.forEach(function (c) {
			var text = c.getAttribute('data-se-text');
			var hit = has(c.getAttribute('data-se-topics'), pick.se_topic) && has(c.getAttribute('data-se-regions'), pick.se_region)
				&& (!pick.se_book || c.getAttribute('data-se-book') === '1')
				&& words.every(function (w) { return text.indexOf(w) > -1; });
			c.hidden = !hit; shown += hit ? 1 : 0;
		});
		if (count) { count.textContent = template.replace(/\d+/, shown).replace(/\d+(?!.*\d)/, total); }
		if (none) { none.hidden = shown > 0; }
		d.querySelectorAll('.se-dir-letter').forEach(function (sec) {
			var any = sec.querySelector('.se-dir-row:not([hidden])'), l = sec.getAttribute('data-se-letter');
			sec.hidden = !any;
			var a = d.querySelector('.se-dir-rail [data-se-letter="' + l + '"]'); if (a) { a.classList.toggle('is-dim', !any); }
		});
		var u = new URL(window.location.href);
		if (q && q.value) { u.searchParams.set('se_q', q.value); } else { u.searchParams.delete('se_q'); }
		Object.keys(pick).forEach(function (k) { if (pick[k]) { u.searchParams.set(k, pick[k]); } else { u.searchParams.delete(k); } });
		history.replaceState(null, '', u.pathname + u.search + u.hash);
	}
	if (q) { q.addEventListener('input', run); }
	selects.forEach(function (s) { s.addEventListener('change', function () { pick[s.getAttribute('data-se-select')] = s.value; run(); }); });
	if (book) { book.addEventListener('change', function () { pick.se_book = book.checked ? '1' : ''; run(); }); }
	d.querySelectorAll('.se-dir-rail a').forEach(function (a) {
		a.addEventListener('click', function (e) { var t = d.querySelector(a.getAttribute('href')); if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
	});
	chips.forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var param = a.getAttribute('data-se-param');
			pick[param] = a.getAttribute('data-se-value');
			d.querySelectorAll('[data-se-param="' + param + '"]').forEach(function (b) { if (b === a) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); } });
			run();
		});
	});
	var f = d.querySelector('.se-dir-tools');
	if (f) { f.addEventListener('submit', function (e) { e.preventDefault(); run(); }); }
});
JS;
		wp_register_script( 'scout-events-directory', false, array(), SCOUT_EVENTS_VERSION, true );
		wp_enqueue_script( 'scout-events-directory' );
		wp_add_inline_script( 'scout-events-directory', $js );
	}
}
