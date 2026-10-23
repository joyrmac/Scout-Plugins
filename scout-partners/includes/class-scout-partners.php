<?php
/**
 * The partner model.
 *
 * A partner is a scout_partner post: the name is its title, the logo its
 * featured image, and _scout_partner holds the level, link, short line,
 * placements and the dates it runs. Levels and placements are the owner's own
 * lists (Partners, Settings), written in the order they rank, so a Founding
 * partner always comes before a Regional one.
 *
 * Regions come from Scout Events when it is active (the same scout_region tag
 * its events use), so a regional partner shows beside that region's events.
 *
 * Clicks are counted per month when someone follows a partner's link, so the
 * owner can show partners what their placement did. No visitor data is kept,
 * only the number.
 *
 * @package Scout_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Partners {

	const TYPE   = 'scout_partner';
	const META   = '_scout_partner';
	const CLICKS = '_scout_partner_clicks';
	const OPTION = 'scout_partners';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		// Scout Events registers regions on init at 6; partners join them after.
		add_action( 'init', array( __CLASS__, 'join_regions' ), 20 );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'                  => __( 'Partners', 'scout-partners' ),
					'singular_name'         => __( 'Partner', 'scout-partners' ),
					'add_new'               => __( 'Add partner', 'scout-partners' ),
					'add_new_item'          => __( 'Add partner', 'scout-partners' ),
					'edit_item'             => __( 'Edit partner', 'scout-partners' ),
					'all_items'             => __( 'All partners', 'scout-partners' ),
					'search_items'          => __( 'Search partners', 'scout-partners' ),
					'not_found'             => __( 'No partners yet.', 'scout-partners' ),
					'featured_image'        => __( 'Logo', 'scout-partners' ),
					'set_featured_image'    => __( 'Set logo', 'scout-partners' ),
					'remove_featured_image' => __( 'Remove logo', 'scout-partners' ),
					'use_featured_image'    => __( 'Use as logo', 'scout-partners' ),
				),
				// Partners show where they are placed; they have no pages of their own.
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				// Editors and administrators, like pages: placements are paid for.
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
			)
		);
	}

	public static function join_regions(): void {
		if ( taxonomy_exists( 'scout_region' ) ) {
			register_taxonomy_for_object_type( 'scout_region', self::TYPE );
		}
	}

	/* ---------- settings ---------- */

	/** @return array{levels:string,placements:string,on_events:bool} */
	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'levels'     => (string) ( $s['levels'] ?? "Founding\nNational\nRegional" ),
			'placements' => (string) ( $s['placements'] ?? "Home page\nSidebar\nFooter\nEvent pages" ),
			'on_events'  => ! empty( $s['on_events'] ),
		);
	}

	public static function save_settings( array $in ): void {
		update_option(
			self::OPTION,
			array(
				'levels'     => sanitize_textarea_field( (string) ( $in['levels'] ?? '' ) ),
				'placements' => sanitize_textarea_field( (string) ( $in['placements'] ?? '' ) ),
				'on_events'  => ! empty( $in['on_events'] ),
			),
			false
		);
	}

	/** One name per line, keyed by slug, in the order written. */
	private static function list_of( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[ sanitize_title( $line ) ] = $line;
			}
		}
		return $out;
	}

	/** @return array<string,string> slug => name, highest level first */
	public static function levels(): array {
		return self::list_of( self::settings()['levels'] );
	}

	/** @return array<string,string> slug => name */
	public static function placements(): array {
		return self::list_of( self::settings()['placements'] );
	}

	/* ---------- partners ---------- */

	/** @return array{level:string,url:string,line:string,placements:string[],starts:string,ends:string} */
	public static function partner( int $id ): array {
		$p = get_post_meta( $id, self::META, true );
		$p = is_array( $p ) ? $p : array();
		return array(
			'level'      => (string) ( $p['level'] ?? '' ),
			'url'        => (string) ( $p['url'] ?? '' ),
			'line'       => (string) ( $p['line'] ?? '' ),
			'placements' => array_values( array_map( 'strval', (array) ( $p['placements'] ?? array() ) ) ),
			'starts'     => (string) ( $p['starts'] ?? '' ),
			'ends'       => (string) ( $p['ends'] ?? '' ),
		);
	}

	public static function save( int $id, array $in ): void {
		update_post_meta(
			$id,
			self::META,
			array(
				'level'      => sanitize_title( (string) ( $in['level'] ?? '' ) ),
				'url'        => esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ), array( 'http', 'https' ) ),
				'line'       => sanitize_text_field( (string) ( $in['line'] ?? '' ) ),
				'placements' => array_values( array_intersect( array_map( 'sanitize_title', (array) ( $in['placements'] ?? array() ) ), array_keys( self::placements() ) ) ),
				'starts'     => self::clean_date( (string) ( $in['starts'] ?? '' ) ),
				'ends'       => self::clean_date( (string) ( $in['ends'] ?? '' ) ),
			)
		);
	}

	/** Running today: published, and inside its dates when it has them. */
	public static function is_live( int $id ): bool {
		if ( 'publish' !== get_post_status( $id ) ) {
			return false;
		}
		$p     = self::partner( $id );
		$today = wp_date( 'Y-m-d' );
		return ( '' === $p['starts'] || $p['starts'] <= $today ) && ( '' === $p['ends'] || $p['ends'] >= $today );
	}

	/** @return array<string,string> slug => name */
	public static function regions( int $id ): array {
		if ( ! taxonomy_exists( 'scout_region' ) ) {
			return array();
		}
		$terms = get_the_terms( $id, 'scout_region' );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ $t->slug ] = $t->name;
		}
		return $out;
	}

	/**
	 * Live partners, highest level first, then in the order set on the list.
	 *
	 * @param array{level?:string,placement?:string,region?:string,regions?:string[]} $args
	 *        regions: show partners of any of these regions, plus those with no region (national).
	 * @return int[]
	 */
	public static function ids( array $args = array() ): array {
		$ids    = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			)
		);
		$rank   = array_flip( array_keys( self::levels() ) );
		$level  = sanitize_title( (string) ( $args['level'] ?? '' ) );
		$place  = sanitize_title( (string) ( $args['placement'] ?? '' ) );
		$region = sanitize_title( (string) ( $args['region'] ?? '' ) );
		$near   = array_map( 'sanitize_title', (array) ( $args['regions'] ?? array() ) );
		$ids    = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $level, $place, $region, $near ) {
					if ( ! self::is_live( $id ) ) {
						return false;
					}
					$p    = self::partner( $id );
					$mine = self::regions( $id );
					if ( '' !== $level && $p['level'] !== $level ) {
						return false;
					}
					if ( '' !== $place && ! in_array( $place, $p['placements'], true ) ) {
						return false;
					}
					if ( '' !== $region && ! isset( $mine[ $region ] ) ) {
						return false;
					}
					// Beside a region's events: that region's partners and the national ones.
					return ! $near || ! $mine || array_intersect( $near, array_keys( $mine ) );
				}
			)
		);
		$pos = array_flip( $ids );
		usort(
			$ids,
			static function ( $a, $b ) use ( $rank, $pos ) {
				$ra = $rank[ self::partner( $a )['level'] ] ?? 99;
				$rb = $rank[ self::partner( $b )['level'] ] ?? 99;
				return $ra === $rb ? $pos[ $a ] <=> $pos[ $b ] : $ra <=> $rb;
			}
		);
		return $ids;
	}

	/* ---------- clicks ---------- */

	public static function count_click( int $id ): void {
		$c   = get_post_meta( $id, self::CLICKS, true );
		$c   = is_array( $c ) ? $c : array();
		$key = wp_date( 'Y-m' );
		$c[ $key ] = (int) ( $c[ $key ] ?? 0 ) + 1;
		// Two years of months is plenty for a partner report.
		krsort( $c );
		update_post_meta( $id, self::CLICKS, array_slice( $c, 0, 24, true ) );
	}

	/** @return array<string,int> 'YYYY-MM' => clicks, newest first */
	public static function clicks( int $id ): array {
		$c = get_post_meta( $id, self::CLICKS, true );
		$c = is_array( $c ) ? array_map( 'intval', $c ) : array();
		krsort( $c );
		return $c;
	}

	public static function clean_date( string $d ): string {
		$d = trim( $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ? $d : '';
	}
}
