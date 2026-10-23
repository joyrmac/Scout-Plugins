<?php
/**
 * A speaker directory people can search: [scout_directory].
 *
 * Every published speaker as a card (photo, name, role, short bio, topics),
 * with a search box and topic buttons. It works without JavaScript: the
 * search and the topic buttons are an ordinary form and links
 * (?se_q=breath&se_topic=yoga), so a filtered list can be shared or
 * bookmarked. With JavaScript the list filters as people type or tap.
 *
 * Topics are a speaker tag (scout_topic), edited on each speaker, under
 * Events, Topics, or with a "topics" column in speakers.csv.
 *
 *   [scout_directory]                 everyone, every topic
 *   [scout_directory topic="yoga"]    starts on one topic
 *   [scout_directory event="summit-2026"]  only that event's speakers
 *   [scout_directory search="no"]     topic buttons only
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
	}

	public static function register(): void {
		register_taxonomy(
			self::TOPIC,
			Scout_Events::SPEAKER,
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
		$link  = 'edit-tags.php?taxonomy=' . self::TOPIC . '&post_type=' . Scout_Events::SPEAKER;
		$mine  = array_values( array_filter( $items, static fn( $i ) => $link === ( $i[2] ?? '' ) ) );
		$items = array_values( array_filter( $items, static fn( $i ) => $link !== ( $i[2] ?? '' ) ) );
		$at    = count( $items );
		foreach ( $items as $i => $item ) {
			if ( 'edit.php?post_type=' . Scout_Events::SPEAKER === ( $item[2] ?? '' ) ) {
				$at = $i + 1;
			}
		}
		array_splice( $items, $at, 0, $mine );
		$submenu[ $slug ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu.
	}

	/** A "topics" column in speakers.csv, separated by "|", replaces the speaker's topics. */
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
				'topic'  => '',
				'event'  => '',
				'search' => 'yes',
			),
			(array) $atts
		);
		wp_enqueue_style( 'scout-events' );

		$ids = Scout_Events::speaker_ids();
		if ( '' !== $atts['event'] ) {
			$found = get_page_by_path( sanitize_title( $atts['event'] ), OBJECT, Scout_Events::EVENT );
			$ids   = $found ? array_values( array_intersect( $ids, Scout_Events::event_speaker_ids( (int) $found->ID ) ) ) : array();
		}
		if ( ! $ids ) {
			return '<p class="se-empty">' . esc_html__( 'The directory is being put together. Check back soon.', 'scout-events' ) . '</p>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public search form.
		$q     = isset( $_GET['se_q'] ) ? sanitize_text_field( wp_unslash( $_GET['se_q'] ) ) : '';
		$topic = isset( $_GET['se_topic'] ) ? sanitize_title( wp_unslash( $_GET['se_topic'] ) ) : sanitize_title( $atts['topic'] );
		// phpcs:enable

		// Topics used by the people listed, most used first.
		$count = array();
		$names = array();
		$cards = array();
		foreach ( $ids as $id ) {
			$tops = self::topics_of( $id );
			foreach ( $tops as $slug => $name ) {
				$count[ $slug ] = ( $count[ $slug ] ?? 0 ) + 1;
				$names[ $slug ] = $name;
			}
			$cards[ $id ] = $tops;
		}
		arsort( $count );
		$topic = isset( $names[ $topic ] ) ? $topic : '';

		$shown = 0;
		$list  = '';
		$words = array_filter( preg_split( '/\s+/', self::fold( $q ) ) );
		foreach ( $cards as $id => $tops ) {
			$p    = Scout_Events::speaker( $id );
			$text = self::haystack( $id, $p, $tops );
			$hit  = '' === $topic || isset( $tops[ $topic ] );
			// Every word has to appear somewhere, in any order.
			foreach ( $words as $w ) {
				$hit = $hit && false !== strpos( $text, $w );
			}
			$shown += $hit ? 1 : 0;
			$list .= self::card( $id, $p, $tops, $text, $hit );
		}

		$base = remove_query_arg( array( 'se_q', 'se_topic' ) );
		$out  = '<div class="se-directory" data-se-directory>';
		$out .= '<form class="se-dir-tools" method="get" action="' . esc_url( $base ) . '#se-dir-list" role="search">';
		if ( 'no' !== $atts['search'] ) {
			$out .= '<p class="se-dir-search"><label for="se-dir-q">' . esc_html__( 'Search by name, practice or word', 'scout-events' ) . '</label>'
				. '<span class="se-dir-searchrow"><input type="search" id="se-dir-q" name="se_q" value="' . esc_attr( $q ) . '" autocomplete="off">'
				. '<button type="submit" class="se-button wp-element-button se-dir-go">' . esc_html__( 'Search', 'scout-events' ) . '</button></span></p>';
		}
		if ( '' !== $topic ) {
			$out .= '<input type="hidden" name="se_topic" value="' . esc_attr( $topic ) . '" data-se-topic-field>';
		}
		$out .= '</form>';

		if ( $count ) {
			$out .= '<nav class="se-dir-topics" aria-label="' . esc_attr__( 'Topics', 'scout-events' ) . '"><ul>';
			$out .= self::chip( '', __( 'Everyone', 'scout-events' ), count( $ids ), $topic, $q, $base );
			$i    = 0;
			$more = '';
			foreach ( $count as $slug => $n ) {
				$chip = self::chip( $slug, $names[ $slug ], $n, $topic, $q, $base );
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
		$out .= '<p class="se-dir-count" aria-live="polite" data-se-count data-total="' . count( $ids ) . '">' . esc_html( sprintf( __( 'Showing %1$d of %2$d', 'scout-events' ), $shown, count( $ids ) ) ) . '</p>';
		$out .= '<ul class="se-dir-list" id="se-dir-list">' . $list . '</ul>';
		$out .= '<p class="se-dir-none"' . ( $shown ? ' hidden' : '' ) . ' data-se-none>' . esc_html__( 'No one matches that yet. Try another word or topic.', 'scout-events' ) . '</p>';
		$out .= '</div>';
		self::script();
		return $out;
	}

	private static function chip( string $slug, string $name, int $n, string $current, string $q, string $base ): string {
		$args = array_filter(
			array(
				'se_topic' => $slug,
				'se_q'     => $q,
			)
		);
		$url  = ( $args ? add_query_arg( $args, $base ) : $base ) . '#se-dir-list';
		$on   = $slug === $current;
		return '<li><a class="se-dir-chip" href="' . esc_url( $url ) . '" data-se-topic="' . esc_attr( $slug ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>'
			. esc_html( $name ) . ' <span class="se-dir-n">' . (int) $n . '</span></a></li>';
	}

	/** Lowercase without accents, so "Reiki" and "reiki" and "Réiki" meet. */
	private static function fold( string $t ): string {
		return strtolower( remove_accents( $t ) );
	}

	/** What a search looks through: name, role, short bio, topics, and the start of the full bio. */
	private static function haystack( int $id, array $p, array $tops ): string {
		$bio = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ), 80, '' );
		return self::fold( implode( ' ', array( get_the_title( $id ), $p['role'], $p['short'], implode( ' ', $tops ), $bio ) ) );
	}

	private static function card( int $id, array $p, array $tops, string $text, bool $hit ): string {
		$out = '<li class="se-dir-card" data-se-text="' . esc_attr( $text ) . '" data-se-topics="' . esc_attr( implode( ' ', array_keys( $tops ) ) ) . '"' . ( $hit ? '' : ' hidden' ) . '>';
		$out .= '<a class="se-dir-link" href="' . esc_url( get_permalink( $id ) ) . '">';
		$out .= has_post_thumbnail( $id )
			? get_the_post_thumbnail( $id, 'medium', array( 'class' => 'se-dir-photo', 'alt' => '', 'loading' => 'lazy' ) )
			: '<span class="se-dir-photo is-empty" aria-hidden="true">' . esc_html( self::initials( get_the_title( $id ) ) ) . '</span>';
		$out .= '<span class="se-dir-name">' . esc_html( get_the_title( $id ) ) . '</span></a>';
		if ( '' !== $p['role'] ) {
			$out .= '<span class="se-dir-role">' . esc_html( $p['role'] ) . '</span>';
		}
		if ( '' !== $p['short'] ) {
			$out .= '<span class="se-dir-short">' . esc_html( wp_trim_words( $p['short'], 28 ) ) . '</span>';
		}
		if ( $tops ) {
			// A few topics on the card; search and the buttons still use all of them.
			$more = count( $tops ) - self::CARD_TOPICS;
			$out .= '<span class="se-dir-tags">' . esc_html( implode( ' · ', array_slice( $tops, 0, self::CARD_TOPICS ) ) . ( $more > 0 ? ' · +' . $more : '' ) ) . '</span>';
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
	var q = d.querySelector('#se-dir-q'), cards = d.querySelectorAll('.se-dir-card'),
		count = d.querySelector('[data-se-count]'), none = d.querySelector('[data-se-none]'),
		chips = d.querySelectorAll('[data-se-topic]'), go = d.querySelector('.se-dir-go'),
		cur = d.querySelector('[data-se-topic][aria-current="true"]'), topic = cur ? cur.getAttribute('data-se-topic') : '',
		fold = function (t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); },
		total = count ? count.getAttribute('data-total') : cards.length, template = count ? count.textContent : '';
	if (go) { go.hidden = true; }
	function run() {
		var words = fold(q ? q.value : '').split(/\s+/).filter(Boolean), shown = 0;
		cards.forEach(function (c) {
			var text = c.getAttribute('data-se-text'), tops = ' ' + c.getAttribute('data-se-topics') + ' ';
			var hit = (!topic || tops.indexOf(' ' + topic + ' ') > -1) && words.every(function (w) { return text.indexOf(w) > -1; });
			c.hidden = !hit; shown += hit ? 1 : 0;
		});
		if (count) { count.textContent = template.replace(/\d+/, shown).replace(/\d+(?!.*\d)/, total); }
		if (none) { none.hidden = shown > 0; }
		var u = new URL(window.location.href);
		if (q && q.value) { u.searchParams.set('se_q', q.value); } else { u.searchParams.delete('se_q'); }
		if (topic) { u.searchParams.set('se_topic', topic); } else { u.searchParams.delete('se_topic'); }
		history.replaceState(null, '', u.pathname + u.search + u.hash);
	}
	if (q) { q.addEventListener('input', run); }
	chips.forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			topic = a.getAttribute('data-se-topic');
			chips.forEach(function (b) { if (b === a) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); } });
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
