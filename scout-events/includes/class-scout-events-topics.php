<?php
/**
 * Topic pages: one public page per topic, for search.
 *
 * Someone searching "water purification class" or "solar vendors" should land
 * on a page about exactly that. A topic page is an ordinary WordPress page
 * (so the theme, Scout's SEO box and the sitemap all treat it like any other)
 * with [scout_topic] on it, which lists every class, speaker and vendor with
 * that topic, plus structured data naming them.
 *
 * Nothing is public until someone chooses it: a topic gets a page from
 * Events, Topics (edit a topic, or tick several and use "Make topic pages").
 * New pages start as drafts under the "Topics" page when there is one, with
 * the topic's description as the search description, so a person reads each
 * before it goes live. Topics with only one or two entries make thin pages;
 * the list shows the count to help choose.
 *
 *   [scout_topic topic="water"]                 classes, speakers, vendors
 *   [scout_topic topic="water" show="classes,vendors" speakers="Instructors"]
 *   [scout_topic topic="water" region="midwest"]
 *   [scout_topics]                              cards for every topic with a live page
 *
 * Speaker and class pages link to the live pages of their topics.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Topics {

	const PAGE = '_scout_topic_page';

	public static function boot(): void {
		add_shortcode( 'scout_topic', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'scout_topics', array( __CLASS__, 'index_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'topic_links' ), 20 );
		$tax = Scout_Events_Directory::TOPIC;
		add_action( $tax . '_edit_form_fields', array( __CLASS__, 'field' ) );
		add_action( $tax . '_add_form_fields', array( __CLASS__, 'field_new' ) );
		add_action( 'edited_' . $tax, array( __CLASS__, 'save_field' ) );
		add_action( 'created_' . $tax, array( __CLASS__, 'save_field' ) );
		add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'column' ), 10, 3 );
		add_filter( 'bulk_actions-edit-' . $tax, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . $tax, array( __CLASS__, 'bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------- which page ---------- */

	public static function page_id( int $term_id ): int {
		$page = (int) get_term_meta( $term_id, self::PAGE, true );
		return $page && 'page' === get_post_type( $page ) ? $page : 0;
	}

	/** The topic's page address, only once it is published. */
	public static function url( string $slug ): string {
		$term = get_term_by( 'slug', $slug, Scout_Events_Directory::TOPIC );
		$page = $term ? self::page_id( (int) $term->term_id ) : 0;
		return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
	}

	/** Classes, speakers and vendors with this topic. */
	public static function count( string $slug ): array {
		$out = array();
		foreach ( array( 'classes', 'speakers', 'vendors' ) as $type ) {
			$out[ $type ] = count( Scout_Events_Directory::on_topic( $type, $slug ) );
		}
		return $out;
	}

	/**
	 * Makes a draft page for the topic, unless it has one. Returns the page id.
	 * The page sits under a page with the slug "topics" when there is one.
	 */
	public static function make_page( int $term_id ): int {
		$term = get_term( $term_id, Scout_Events_Directory::TOPIC );
		if ( ! $term instanceof WP_Term ) {
			return 0;
		}
		$have = self::page_id( $term_id );
		if ( $have ) {
			return $have;
		}
		$parent = get_page_by_path( 'topics' );
		$about  = trim( wp_strip_all_tags( (string) $term->description ) );
		$page   = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $term->name,
				'post_name'    => $term->slug,
				'post_parent'  => $parent ? (int) $parent->ID : 0,
				'post_excerpt' => $about,
				'post_content' => '<!-- wp:shortcode -->' . "\n" . '[scout_topic topic="' . esc_attr( $term->slug ) . '"]' . "\n" . '<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $page ) || ! $page ) {
			return 0;
		}
		if ( '' !== $about && defined( 'SCOUT_SEO_META_DESC' ) ) {
			update_post_meta( (int) $page, SCOUT_SEO_META_DESC, wp_html_excerpt( $about, 155, '…' ) );
		}
		update_term_meta( $term_id, self::PAGE, (int) $page );
		return (int) $page;
	}

	/* ---------- on the site ---------- */

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'topic'    => '',
				'region'   => '',
				'show'     => 'classes,speakers,vendors',
				'classes'  => __( 'Classes', 'scout-events' ),
				'speakers' => __( 'Speakers', 'scout-events' ),
				'vendors'  => __( 'Vendors', 'scout-events' ),
				'intro'    => 'yes',
			),
			(array) $atts
		);
		$slug = sanitize_title( $atts['topic'] );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, Scout_Events_Directory::TOPIC ) : false;
		if ( ! $term ) {
			return current_user_can( 'edit_pages' ) ? '<p class="se-empty">' . esc_html__( 'This topic was not found. Check the topic in the shortcode against Events, Topics.', 'scout-events' ) . '</p>' : '';
		}
		wp_enqueue_style( 'scout-events' );
		$out  = '<div class="se-topic">';
		$desc = trim( (string) $term->description );
		if ( 'no' !== $atts['intro'] && '' !== $desc ) {
			$out .= '<div class="se-topic-intro">' . wpautop( wp_kses_post( $desc ) ) . '</div>';
		}
		$listed = array();
		foreach ( array_map( 'trim', explode( ',', (string) $atts['show'] ) ) as $type ) {
			if ( ! in_array( $type, array( 'classes', 'speakers', 'vendors' ), true ) ) {
				continue;
			}
			$items = Scout_Events_Directory::on_topic( $type, $slug, (string) $atts['region'] );
			if ( ! $items ) {
				continue;
			}
			$out   .= '<section class="se-topic-part se-topic-' . esc_attr( $type ) . '"><h2>' . esc_html( (string) $atts[ $type ] ) . '</h2>' . Scout_Events_Directory::cards( $items ) . '</section>';
			$listed = array_merge( $listed, $items );
		}
		if ( ! $listed ) {
			$out .= '<p class="se-empty">' . esc_html__( 'Classes and people for this topic are being added. Check back soon.', 'scout-events' ) . '</p>';
		}
		$out .= '</div>';
		return $out . self::schema( $term, $listed );
	}

	/** An ItemList of what the page lists, so search tools see the page as a collection. */
	private static function schema( WP_Term $term, array $items ): string {
		$list = array();
		$i    = 0;
		foreach ( $items as $it ) {
			if ( '' === $it['url'] || $it['external'] ) {
				continue;
			}
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => ++$i,
				'url'      => $it['url'],
				'name'     => wp_strip_all_tags( $it['title'] ),
			);
		}
		if ( ! $list ) {
			return '';
		}
		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $term->name,
			'numberOfItems'   => count( $list ),
			'itemListElement' => $list,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/** [scout_topics]: a card for every topic with a live page, busiest first. */
	public static function index_shortcode(): string {
		wp_enqueue_style( 'scout-events' );
		$terms = get_terms(
			array(
				'taxonomy'   => Scout_Events_Directory::TOPIC,
				'hide_empty' => false,
				'meta_key'   => self::PAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a short list.
			)
		);
		$cards = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$url = self::url( $t->slug );
			if ( '' === $url ) {
				continue;
			}
			$n     = self::count( $t->slug );
			$parts = array_filter(
				array(
					/* translators: %d: number of classes. */
					$n['classes'] ? sprintf( _n( '%d class', '%d classes', $n['classes'], 'scout-events' ), $n['classes'] ) : '',
					/* translators: %d: number of speakers. */
					$n['speakers'] ? sprintf( _n( '%d speaker', '%d speakers', $n['speakers'], 'scout-events' ), $n['speakers'] ) : '',
					/* translators: %d: number of vendors. */
					$n['vendors'] ? sprintf( _n( '%d vendor', '%d vendors', $n['vendors'], 'scout-events' ), $n['vendors'] ) : '',
				)
			);
			$card  = '<li class="se-region"><a class="se-region-link" href="' . esc_url( $url ) . '"><span class="se-region-name">' . esc_html( $t->name ) . '</span>';
			if ( '' !== trim( (string) $t->description ) ) {
				$card .= '<span class="se-region-about">' . esc_html( wp_trim_words( wp_strip_all_tags( $t->description ), 24 ) ) . '</span>';
			}
			$card   .= $parts ? '<span class="se-region-next">' . esc_html( implode( ' · ', $parts ) ) . '</span>' : '';
			$cards[] = array( array_sum( $n ), $t->name, $card . '</a></li>' );
		}
		if ( ! $cards ) {
			return '';
		}
		usort( $cards, static fn( $a, $b ) => $b[0] <=> $a[0] ?: strcasecmp( $a[1], $b[1] ) );
		return '<ul class="se-regions se-topics">' . implode( '', array_column( $cards, 2 ) ) . '</ul>';
	}

	/** Speaker and class pages link to the live pages of their topics. */
	public static function topic_links( $content ) {
		if ( ! is_singular( array( Scout_Events::SPEAKER, Scout_Events::SESSION ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$links = array();
		foreach ( Scout_Events_Directory::topics_of( (int) get_the_ID() ) as $slug => $name ) {
			$url = self::url( (string) $slug );
			if ( '' !== $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			}
		}
		if ( ! $links ) {
			return $content;
		}
		return $content . '<p class="se-topic-links"><span>' . esc_html( _n( 'Topic:', 'Topics:', count( $links ), 'scout-events' ) ) . '</span> ' . implode( ', ', $links ) . '</p>';
	}

	/* ---------- Events, Topics ---------- */

	public static function field( $term ): void {
		$page = self::page_id( (int) $term->term_id );
		$n    = self::count( (string) $term->slug );
		echo '<tr class="form-field"><th scope="row"><label for="se-topic-page">' . esc_html__( 'Topic page', 'scout-events' ) . '</label></th><td>';
		wp_nonce_field( 'scout_topic_page', '_se_topic' );
		wp_dropdown_pages(
			array(
				'name'              => 'se_topic_page',
				'id'                => 'se-topic-page',
				'selected'          => $page,
				'post_status'       => array( 'publish', 'draft', 'pending', 'private' ),
				'show_option_none'  => esc_html__( 'None yet', 'scout-events' ),
				'option_none_value' => '0',
			)
		);
		if ( $page ) {
			$status = get_post_status( $page );
			echo ' <a href="' . esc_url( (string) get_edit_post_link( $page ) ) . '">' . esc_html( 'publish' === $status ? __( 'Edit the page', 'scout-events' ) : __( 'Edit the draft', 'scout-events' ) ) . '</a>';
		} else {
			echo '<p><label><input type="checkbox" name="se_topic_make" value="1"> ' . esc_html__( 'Make a draft page for this topic', 'scout-events' ) . '</label></p>';
		}
		/* translators: 1: classes, 2: speakers, 3: vendors. */
		echo '<p class="description">' . esc_html( sprintf( __( 'A public page listing everything on this topic, for people searching for it. It has %1$d classes, %2$d speakers and %3$d vendors now. The description above becomes the page\'s introduction and search description.', 'scout-events' ), $n['classes'], $n['speakers'], $n['vendors'] ) ) . '</p></td></tr>';
	}

	public static function field_new(): void {
		echo '<div class="form-field">';
		wp_nonce_field( 'scout_topic_page', '_se_topic' );
		echo '<label><input type="checkbox" name="se_topic_make" value="1"> ' . esc_html__( 'Make a draft page for this topic', 'scout-events' ) . '</label>';
		echo '<p>' . esc_html__( 'A public page listing every class, speaker and vendor on this topic. Write the description first: it becomes the introduction.', 'scout-events' ) . '</p></div>';
	}

	public static function save_field( $term_id ): void {
		if ( ! isset( $_POST['_se_topic'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_se_topic'] ) ), 'scout_topic_page' ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		if ( isset( $_POST['se_topic_page'] ) ) {
			$page = absint( $_POST['se_topic_page'] );
			if ( $page && 'page' === get_post_type( $page ) ) {
				update_term_meta( (int) $term_id, self::PAGE, $page );
			} else {
				delete_term_meta( (int) $term_id, self::PAGE );
			}
		}
		if ( ! empty( $_POST['se_topic_make'] ) ) {
			self::make_page( (int) $term_id );
		}
	}

	public static function columns( $cols ): array {
		$cols                  = (array) $cols;
		$cols['se_topic_page'] = __( 'Page', 'scout-events' );
		return $cols;
	}

	public static function column( $out, $col, $term_id ) {
		if ( 'se_topic_page' !== $col ) {
			return $out;
		}
		$page = self::page_id( (int) $term_id );
		if ( ! $page ) {
			return '<span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'No page', 'scout-events' ) . '</span>';
		}
		$label = 'publish' === get_post_status( $page ) ? __( 'Live', 'scout-events' ) : __( 'Draft', 'scout-events' );
		return '<a href="' . esc_url( (string) get_edit_post_link( $page ) ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function bulk_actions( $actions ): array {
		$actions                       = (array) $actions;
		$actions['se_make_topic_pages'] = __( 'Make topic pages', 'scout-events' );
		return $actions;
	}

	public static function bulk( $redirect, $action, $ids ) {
		if ( 'se_make_topic_pages' !== $action || ! current_user_can( 'edit_pages' ) ) {
			return $redirect;
		}
		$made = 0;
		foreach ( (array) $ids as $id ) {
			$had   = self::page_id( (int) $id );
			$made += ( ! $had && self::make_page( (int) $id ) ) ? 1 : 0;
		}
		return add_query_arg( 'se_topic_pages', $made, $redirect );
	}

	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show after our own redirect.
		if ( ! isset( $_GET['se_topic_pages'] ) || Scout_Events_Directory::TOPIC !== ( $_GET['taxonomy'] ?? '' ) ) {
			return;
		}
		$n = absint( $_GET['se_topic_pages'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %d: number of pages. */
		$msg = $n ? sprintf( _n( '%d draft topic page made. Read it, then publish it from Pages.', '%d draft topic pages made. Read each, then publish them from Pages.', $n, 'scout-events' ), $n ) : __( 'Those topics already have pages.', 'scout-events' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
