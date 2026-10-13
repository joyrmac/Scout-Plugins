<?php
/**
 * The team content model: the scout_team post type, the one profile meta
 * array per person, the site settings, and the trust checklist.
 *
 * Scout Core (1.1.x) has no person type, so this plugin registers its own.
 * Each person is a post: the title is the name, the featured image is the
 * photo, the editor is an optional full bio, and everything else lives in one
 * protected meta value (_scout_team) so a client theme can never drift from it.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team {

	const POST_TYPE = 'scout_team';
	const META      = '_scout_team';
	const OPTION    = 'scout_team';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
	}

	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'                  => __( 'Team', 'scout-team' ),
					'singular_name'         => __( 'Team member', 'scout-team' ),
					'menu_name'             => __( 'Team', 'scout-team' ),
					'add_new'               => __( 'Add team member', 'scout-team' ),
					'add_new_item'          => __( 'Add team member', 'scout-team' ),
					'edit_item'             => __( 'Edit team member', 'scout-team' ),
					'new_item'              => __( 'New team member', 'scout-team' ),
					'view_item'             => __( 'View profile', 'scout-team' ),
					'view_items'            => __( 'View team', 'scout-team' ),
					'search_items'          => __( 'Search team', 'scout-team' ),
					'all_items'             => __( 'All team members', 'scout-team' ),
					'not_found'             => __( 'No team members yet.', 'scout-team' ),
					'not_found_in_trash'    => __( 'No team members in the trash.', 'scout-team' ),
					'featured_image'        => __( 'Photo', 'scout-team' ),
					'set_featured_image'    => __( 'Set photo', 'scout-team' ),
					'remove_featured_image' => __( 'Remove photo', 'scout-team' ),
					'use_featured_image'    => __( 'Use as photo', 'scout-team' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-groups',
				'menu_position' => 26,
				'has_archive'  => 'team',
				'rewrite'      => array(
					'slug'       => 'team',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'revisions' ),
			)
		);
	}

	/* ---------- settings ---------- */

	public static function defaults(): array {
		return array(
			'law'     => 'auto',
			'contact' => 0,
			'columns' => 3,
			'bylines' => 1,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Is this a law firm site? "auto" reads the Scout Core schema type.
	 */
	public static function is_law(): bool {
		$mode = self::settings()['law'];
		if ( 'on' === $mode ) {
			return true;
		}
		if ( 'off' === $mode ) {
			return false;
		}
		if ( function_exists( 'scout_core_business' ) ) {
			$type = (string) ( scout_core_business()['business_type'] ?? '' );
			if ( preg_match( '/legal|attorney|lawyer|law ?firm/i', $type ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- the profile ---------- */

	/** Military branches offered in the editor. */
	public static function branches(): array {
		return array(
			''             => __( 'None', 'scout-team' ),
			'army'         => __( 'U.S. Army', 'scout-team' ),
			'navy'         => __( 'U.S. Navy', 'scout-team' ),
			'air_force'    => __( 'U.S. Air Force', 'scout-team' ),
			'marine_corps' => __( 'U.S. Marine Corps', 'scout-team' ),
			'coast_guard'  => __( 'U.S. Coast Guard', 'scout-team' ),
			'space_force'  => __( 'U.S. Space Force', 'scout-team' ),
			'guard'        => __( 'National Guard', 'scout-team' ),
			'reserve'      => __( 'Reserves', 'scout-team' ),
		);
	}

	/** Columns in each repeatable group. */
	public static function groups(): array {
		return array(
			'bar'       => array( 'state', 'year', 'number', 'url' ),
			'education' => array( 'school', 'degree', 'year' ),
			'awards'    => array( 'name', 'year', 'issuer' ),
		);
	}

	public static function empty_profile(): array {
		return array(
			'role'            => '',
			'bio'             => '',
			'email'           => '',
			'phone'           => '',
			'user'            => 0,
			'attorney'        => 0,
			'military_branch' => '',
			'military_years'  => '',
			'languages'       => '',
			'memberships'     => array(),
			'same_as'         => array(),
			'bar'             => array(),
			'education'       => array(),
			'awards'          => array(),
		);
	}

	/**
	 * The stored profile, always in the full shape.
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function profile( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::empty_profile() );
	}

	/**
	 * Clean a submitted profile.
	 *
	 * @param array $in Raw (unslashed) input.
	 */
	public static function sanitize_profile( array $in ): array {
		$out                    = self::empty_profile();
		$out['role']            = sanitize_text_field( (string) ( $in['role'] ?? '' ) );
		$out['bio']             = sanitize_textarea_field( (string) ( $in['bio'] ?? '' ) );
		$out['email']           = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$out['phone']           = preg_replace( '/[^0-9+().\-\s]/', '', sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
		$out['user']            = absint( $in['user'] ?? 0 );
		$out['attorney']        = ! empty( $in['attorney'] ) ? 1 : 0;
		$branch                 = sanitize_key( (string) ( $in['military_branch'] ?? '' ) );
		$out['military_branch'] = array_key_exists( $branch, self::branches() ) ? $branch : '';
		$out['military_years']  = sanitize_text_field( (string) ( $in['military_years'] ?? '' ) );
		$out['languages']       = sanitize_text_field( (string) ( $in['languages'] ?? '' ) );
		$out['memberships']     = self::lines( (string) ( $in['memberships'] ?? '' ) );

		$urls = array();
		foreach ( self::lines( (string) ( $in['same_as'] ?? '' ) ) as $line ) {
			$url = esc_url_raw( $line, array( 'https', 'http' ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		$out['same_as'] = array_values( array_unique( $urls ) );

		foreach ( self::groups() as $group => $cols ) {
			$rows = array();
			foreach ( (array) ( $in[ $group ] ?? array() ) as $row ) {
				$row   = (array) $row;
				$clean = array();
				foreach ( $cols as $col ) {
					$value = (string) ( $row[ $col ] ?? '' );
					if ( 'url' === $col ) {
						$clean[ $col ] = esc_url_raw( trim( $value ), array( 'https', 'http' ) );
					} elseif ( 'year' === $col ) {
						$clean[ $col ] = preg_match( '/^\d{4}$/', trim( $value ) ) ? trim( $value ) : '';
					} else {
						$clean[ $col ] = sanitize_text_field( $value );
					}
				}
				// A row needs its first column (state, school, award name).
				if ( '' !== $clean[ $cols[0] ] ) {
					$rows[] = $clean;
				}
			}
			$out[ $group ] = array_slice( $rows, 0, 30 );
		}
		return $out;
	}

	/** Split text into trimmed, non-empty lines. */
	public static function lines( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Languages as a list. */
	public static function languages( array $p ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $p['languages'] ) ) ) );
	}

	/** "U.S. Navy, 2004 to 2012" or ''. */
	public static function military_line( array $p ): string {
		if ( '' === $p['military_branch'] ) {
			return '';
		}
		$branch = self::branches()[ $p['military_branch'] ] ?? '';
		return '' !== $p['military_years'] ? $branch . ', ' . $p['military_years'] : $branch;
	}

	/** Team member linked to a WordPress user, for bylines. */
	public static function member_for_user( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}
		static $map = null;
		if ( null === $map ) {
			$map = array();
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$uid = (int) self::profile( (int) $id )['user'];
				if ( $uid && ! isset( $map[ $uid ] ) ) {
					$map[ $uid ] = (int) $id;
				}
			}
		}
		return $map[ $user_id ] ?? 0;
	}

	/**
	 * What a visitor (and Google) looks for to trust a real person. Each check
	 * is key => [ label, note, done ].
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function checks( int $post_id ): array {
		$p      = self::profile( $post_id );
		$checks = array(
			'photo'     => array( __( 'Real photo', 'scout-team' ), __( 'A photo of the person, never stock', 'scout-team' ), has_post_thumbnail( $post_id ) ),
			'bio'       => array( __( 'Bio and role', 'scout-team' ), __( 'A role and a short bio in plain language', 'scout-team' ), '' !== $p['role'] && '' !== $p['bio'] ),
			'byline'    => array( __( 'Byline on posts', 'scout-team' ), __( 'Their posts link back to this profile', 'scout-team' ), $p['user'] > 0 ),
			'education' => array( __( 'Education', 'scout-team' ), __( 'At least one school', 'scout-team' ), ! empty( $p['education'] ) ),
		);
		if ( self::is_law() && $p['attorney'] ) {
			$checks['bar'] = array( __( 'Bar admissions', 'scout-team' ), __( 'Each state, with the year admitted', 'scout-team' ), ! empty( $p['bar'] ) );
		}
		$checks['profiles'] = array( __( 'Profile links', 'scout-team' ), __( 'LinkedIn, a state bar page or another public profile', 'scout-team' ), ! empty( $p['same_as'] ) );
		return $checks;
	}
}
