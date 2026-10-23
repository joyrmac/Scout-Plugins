<?php
/**
 * Regions: for an organisation that runs the same kind of event in several
 * places (six regional camps, a festival in two cities).
 *
 * A region is set once, on the event. Its sessions, speakers and vendors
 * belong to the regions of the events they are part of, so nothing is
 * entered twice; a session, speaker or vendor can also carry its own region.
 *
 *   [scout_regions]                 one card per region: next event and a link
 *   [scout_events region="midwest"] the events list for one region
 *   [scout_directory region="midwest" type="classes"]  see the directory
 *
 * Each region can point at its own page (Events, Regions, edit a region), and
 * the cards link there. events.csv, sessions.csv, speakers.csv and vendors.csv
 * take a "regions" column ("Midwest|Southeast").
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Regions {

	const TAX  = 'scout_region';
	const PAGE = '_scout_region_page';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 39 );
		add_shortcode( 'scout_regions', array( __CLASS__, 'cards_shortcode' ) );
		add_filter( 'scout_events_detail_rows', array( __CLASS__, 'detail_row' ), 5, 2 );
		add_action( 'scout_events_imported_event', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( 'scout_events_imported_session', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( 'scout_events_imported_speaker', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( 'scout_events_imported_vendor', array( __CLASS__, 'imported' ), 10, 2 );
		add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'page_field' ) );
		add_action( self::TAX . '_add_form_fields', array( __CLASS__, 'page_field_new' ) );
		add_action( 'edited_' . self::TAX, array( __CLASS__, 'save_page_field' ) );
		add_action( 'created_' . self::TAX, array( __CLASS__, 'save_page_field' ) );
	}

	public static function register(): void {
		register_taxonomy(
			self::TAX,
			array( Scout_Events::EVENT, Scout_Events::SESSION, Scout_Events::SPEAKER, Scout_Events_Vendors::TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Regions', 'scout-events' ),
					'singular_name' => __( 'Region', 'scout-events' ),
					'search_items'  => __( 'Search regions', 'scout-events' ),
					'all_items'     => __( 'All regions', 'scout-events' ),
					'edit_item'     => __( 'Edit region', 'scout-events' ),
					'add_new_item'  => __( 'Add a region', 'scout-events' ),
					'not_found'     => __( 'No regions yet. Add one if your events run in more than one place.', 'scout-events' ),
					'separate_items_with_commas' => __( 'Separate regions with commas.', 'scout-events' ),
				),
				// Each region gets a real page of the owner's choosing, so no archive pages here.
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

	/** Events, Regions. Scout_Events_Directory::menu() moves it next to Topics, after Speakers. */
	public static function menu(): void {
		add_submenu_page( Scout_Events_Admin::SLUG, __( 'Regions', 'scout-events' ), __( 'Regions', 'scout-events' ), 'manage_categories', 'edit-tags.php?taxonomy=' . self::TAX . '&post_type=' . Scout_Events::EVENT );
	}

	/* ---------- which regions ---------- */

	/** @return array<string,string> slug => name, set on this post itself */
	public static function own( int $id ): array {
		$terms = get_the_terms( $id, self::TAX );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ $t->slug ] = $t->name;
		}
		return $out;
	}

	/**
	 * The regions a post belongs to: its own, plus those of its events (a
	 * session's event, a speaker's sessions' events, a vendor's events).
	 *
	 * @return array<string,string> slug => name
	 */
	public static function of( int $id ): array {
		static $cache = array();
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$out    = self::own( $id );
		$events = array();
		switch ( get_post_type( $id ) ) {
			case Scout_Events::SESSION:
				$events[] = Scout_Events::session( $id )['event'];
				break;
			case Scout_Events::SPEAKER:
				foreach ( Scout_Events::sessions_for_speaker( $id ) as $sid ) {
					$events[] = Scout_Events::session( (int) $sid )['event'];
				}
				break;
			case Scout_Events_Vendors::TYPE:
				$events = Scout_Events_Vendors::vendor( $id )['events'];
				break;
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $events ) ) ) as $event ) {
			$out += self::own( $event );
		}
		$cache[ $id ] = $out;
		return $out;
	}

	/** Every region, in name order. @return array<string,string> slug => name */
	public static function all(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ $t->slug ] = $t->name;
		}
		return $out;
	}

	/** Keep only ids in a region (by slug or name); every id when $region is empty. */
	public static function filter( array $ids, string $region ): array {
		$slug = sanitize_title( $region );
		if ( '' === $slug ) {
			return $ids;
		}
		return array_values( array_filter( $ids, static fn( $id ) => isset( self::of( (int) $id )[ $slug ] ) ) );
	}

	/* ---------- on the site ---------- */

	/** "Region" in an event's details, when it has one. */
	public static function detail_row( $rows, $id ): array {
		$rows   = (array) $rows;
		$region = self::own( (int) $id );
		if ( $region ) {
			$links = array();
			foreach ( $region as $slug => $name ) {
				$url     = self::page_url( $slug );
				$links[] = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
			}
			$rows[] = array( _n( 'Region', 'Regions', count( $region ), 'scout-events' ), implode( ', ', $links ) );
		}
		return $rows;
	}

	public static function page_url( string $slug ): string {
		$term = get_term_by( 'slug', $slug, self::TAX );
		$page = $term ? (int) get_term_meta( $term->term_id, self::PAGE, true ) : 0;
		return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
	}

	/** [scout_regions]: a card per region with its next event, linking to the region's page. */
	public static function cards_shortcode(): string {
		wp_enqueue_style( 'scout-events' );
		$regions = self::all();
		if ( ! $regions ) {
			return '';
		}
		$upcoming = Scout_Events::event_ids( 'upcoming' );
		$out      = '<ul class="se-regions">';
		foreach ( $regions as $slug => $name ) {
			$next = self::filter( $upcoming, $slug );
			$url  = self::page_url( $slug );
			$term = get_term_by( 'slug', $slug, self::TAX );
			$out .= '<li class="se-region">' . ( '' !== $url ? '<a class="se-region-link" href="' . esc_url( $url ) . '">' : '<div class="se-region-link">' );
			$out .= '<span class="se-region-name">' . esc_html( $name ) . '</span>';
			if ( $term && '' !== trim( (string) $term->description ) ) {
				$out .= '<span class="se-region-about">' . esc_html( wp_strip_all_tags( $term->description ) ) . '</span>';
			}
			if ( $next ) {
				$e    = Scout_Events::event( $next[0] );
				$out .= '<span class="se-region-next">' . esc_html( get_the_title( $next[0] ) ) . ' · ' . esc_html( Scout_Events::dates_label( $e ) ) . '</span>';
			} else {
				$out .= '<span class="se-region-next">' . esc_html__( 'Dates coming soon', 'scout-events' ) . '</span>';
			}
			$out .= '' !== $url ? '</a>' : '</div>';
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/* ---------- the region's page, in the dashboard ---------- */

	public static function page_field( $term ): void {
		$page = (int) get_term_meta( $term->term_id, self::PAGE, true );
		echo '<tr class="form-field"><th scope="row"><label for="se-region-page">' . esc_html__( 'Region page', 'scout-events' ) . '</label></th><td>';
		wp_nonce_field( 'scout_region_page', '_se_region' );
		wp_dropdown_pages(
			array(
				'name'              => 'se_region_page',
				'id'                => 'se-region-page',
				'selected'          => $page,
				'show_option_none'  => esc_html__( 'None yet', 'scout-events' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'The page about this region. Region cards and event details link to it.', 'scout-events' ) . '</p></td></tr>';
	}

	public static function page_field_new(): void {
		echo '<div class="form-field"><label for="se-region-page">' . esc_html__( 'Region page', 'scout-events' ) . '</label>';
		wp_nonce_field( 'scout_region_page', '_se_region' );
		wp_dropdown_pages(
			array(
				'name'              => 'se_region_page',
				'id'                => 'se-region-page',
				'show_option_none'  => esc_html__( 'None yet', 'scout-events' ),
				'option_none_value' => '0',
			)
		);
		echo '<p>' . esc_html__( 'The page about this region. Region cards and event details link to it.', 'scout-events' ) . '</p></div>';
	}

	public static function save_page_field( $term_id ): void {
		if ( ! isset( $_POST['_se_region'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_se_region'] ) ), 'scout_region_page' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$page = isset( $_POST['se_region_page'] ) ? absint( $_POST['se_region_page'] ) : 0;
		if ( $page ) {
			update_term_meta( (int) $term_id, self::PAGE, $page );
		} else {
			delete_term_meta( (int) $term_id, self::PAGE );
		}
	}

	/* ---------- spreadsheets ---------- */

	/** A "regions" column ("Midwest|Southeast") on any of the four files replaces that row's own regions. */
	public static function imported( $id, $row ): void {
		$raw = trim( (string) ( ( (array) $row )['regions'] ?? '' ) );
		if ( '' === $raw ) {
			return;
		}
		$names = array_values( array_unique( array_filter( array_map( static fn( $t ) => sanitize_text_field( trim( $t ) ), explode( '|', $raw ) ) ) ) );
		wp_set_object_terms( (int) $id, $names, self::TAX, false );
	}
}
