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
				'search' => 'yes',
				'show'   => 'all',
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
		$items = -1 === $event ? array() : self::items( $type, $event, $fixed, 'upcoming' === $atts['show'] );
		if ( ! $items ) {
			return '<p class="se-empty">' . esc_html__( 'The directory is being put together. Check back soon.', 'scout-events' ) . '</p>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public search form.
		$q      = isset( $_GET['se_q'] ) ? sanitize_text_field( wp_unslash( $_GET['se_q'] ) ) : '';
		$topic  = isset( $_GET['se_topic'] ) ? sanitize_title( wp_unslash( $_GET['se_topic'] ) ) : sanitize_title( $atts['topic'] );
		$region = '' !== $fixed ? '' : ( isset( $_GET['se_region'] ) ? sanitize_title( wp_unslash( $_GET['se_region'] ) ) : '' );
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

		$shown = 0;
		$list  = '';
		$words = array_filter( preg_split( '/\s+/', self::fold( $q ) ) );
		foreach ( $items as $it ) {
			$hit = ( '' === $topic || isset( $it['topics'][ $topic ] ) ) && ( '' === $region || isset( $it['regions'][ $region ] ) );
			// Every word has to appear somewhere, in any order.
			foreach ( $words as $w ) {
				$hit = $hit && false !== strpos( $it['text'], $w );
			}
			$shown += $hit ? 1 : 0;
			$list  .= self::card( $it, $hit );
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
				$out[] = self::item( $id, $v['website'], true, has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, 'medium', array( 'class' => 'se-dir-photo is-logo', 'alt' => '', 'loading' => 'lazy' ) ) : '', array_filter( array( $v['city'], wp_trim_words( '' !== $about ? $about : $v['products'], 24 ) ) ), $v['products'] . ' ' . $about );
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
				$out[] = self::item( $id, (string) get_permalink( $id ), false, self::photo( $id ), array_filter( array( $p['role'], wp_trim_words( $p['short'], 28 ) ) ), $p['short'] . ' ' . $bio );
			}
		}
		return '' === $region ? $out : array_values( array_filter( $out, static fn( $it ) => isset( $it['regions'][ $region ] ) ) );
	}

	private static function item( int $id, string $url, bool $external, string $photo, array $lines, string $more ): array {
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
			'text'     => self::fold( implode( ' ', array_merge( array( get_the_title( $id ) ), $lines, $topics, $regions, array( $more ) ) ) ),
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

	private static function card( array $it, bool $hit ): string {
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
		return $out . '</li>';
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( wp_strip_all_tags( $name ) ) );
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
	var q = d.querySelector('.se-dir-q'), cards = d.querySelectorAll('.se-dir-card'),
		count = d.querySelector('[data-se-count]'), none = d.querySelector('[data-se-none]'),
		chips = d.querySelectorAll('[data-se-param]'), go = d.querySelector('.se-dir-go'),
		pick = { se_topic: '', se_region: '' },
		fold = function (t) { return (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); },
		total = count ? count.getAttribute('data-total') : cards.length, template = count ? count.textContent : '';
	d.querySelectorAll('[data-se-param][aria-current="true"]').forEach(function (a) { pick[a.getAttribute('data-se-param')] = a.getAttribute('data-se-value'); });
	if (go) { go.hidden = true; }
	function has(list, v) { return !v || (' ' + list + ' ').indexOf(' ' + v + ' ') > -1; }
	function run() {
		var words = fold(q ? q.value : '').split(/\s+/).filter(Boolean), shown = 0;
		cards.forEach(function (c) {
			var text = c.getAttribute('data-se-text');
			var hit = has(c.getAttribute('data-se-topics'), pick.se_topic) && has(c.getAttribute('data-se-regions'), pick.se_region)
				&& words.every(function (w) { return text.indexOf(w) > -1; });
			c.hidden = !hit; shown += hit ? 1 : 0;
		});
		if (count) { count.textContent = template.replace(/\d+/, shown).replace(/\d+(?!.*\d)/, total); }
		if (none) { none.hidden = shown > 0; }
		var u = new URL(window.location.href);
		if (q && q.value) { u.searchParams.set('se_q', q.value); } else { u.searchParams.delete('se_q'); }
		Object.keys(pick).forEach(function (k) { if (pick[k]) { u.searchParams.set(k, pick[k]); } else { u.searchParams.delete(k); } });
		history.replaceState(null, '', u.pathname + u.search + u.hash);
	}
	if (q) { q.addEventListener('input', run); }
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
