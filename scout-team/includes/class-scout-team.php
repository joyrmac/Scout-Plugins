<?php
/**
 * The people content model: the scout_team post type (shown as People), the
 * Groups taxonomy, the one profile meta array per person, the site settings,
 * the trust checklist and the "needs a look" reasons.
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

	/** Details a person sent through their link, waiting for the owner's OK. Never read by the public side. */
	const PENDING = '_scout_team_pending';

	/** Groups: Team, Speakers, Writers, Members, Attorneys. */
	const TAX = 'scout_people_group';

	/** The Team group's slug. The team page shows this group. */
	const TEAM = 'team';

	/** The source given to credential lines that were saved before sources existed. */
	const LEGACY_SOURCE = 'Added before sources were tracked';

	/** The data version, and the option that records it. */
	const DB_VERSION = 1;
	const DB_OPTION  = 'scout_team_db';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 6 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 20 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'new_person_group' ), 10, 3 );
	}

	/**
	 * A person added any other way (an import, Site Sync, code) with no group
	 * starts in Team, the way people always landed on the team page. The edit
	 * screen sets groups itself, so a person someone takes out of every group
	 * stays out.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    The post.
	 * @param bool    $update  An update rather than a new post.
	 */
	public static function new_person_group( $post_id, $post, $update ): void {
		if ( $update || ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! self::upgraded() ) {
			return;
		}
		$have = wp_get_object_terms( (int) $post_id, self::TAX, array( 'fields' => 'ids' ) );
		$team = self::team_term();
		if ( $team && ! is_wp_error( $have ) && ! $have ) {
			wp_set_object_terms( (int) $post_id, array( $team ), self::TAX, true );
		}
	}

	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'                  => __( 'People', 'scout-team' ),
					'singular_name'         => __( 'Person', 'scout-team' ),
					'menu_name'             => __( 'People', 'scout-team' ),
					'add_new'               => __( 'Add a person', 'scout-team' ),
					'add_new_item'          => __( 'Add a person', 'scout-team' ),
					'edit_item'             => __( 'Edit person', 'scout-team' ),
					'new_item'              => __( 'New person', 'scout-team' ),
					'view_item'             => __( 'View profile', 'scout-team' ),
					'view_items'            => __( 'View the team page', 'scout-team' ),
					'search_items'          => __( 'Search people', 'scout-team' ),
					'all_items'             => __( 'All people', 'scout-team' ),
					'not_found'             => __( 'No people yet.', 'scout-team' ),
					'not_found_in_trash'    => __( 'No people in the trash.', 'scout-team' ),
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

	/** Groups: who a person is to the site. Not public: it sorts people, it makes no pages. */
	public static function register_taxonomy(): void {
		if ( taxonomy_exists( self::TAX ) ) {
			return;
		}
		register_taxonomy(
			self::TAX,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Groups', 'scout-team' ),
					'singular_name' => __( 'Group', 'scout-team' ),
					'menu_name'     => __( 'Groups', 'scout-team' ),
					'all_items'     => __( 'All groups', 'scout-team' ),
					'edit_item'     => __( 'Edit group', 'scout-team' ),
					'add_new_item'  => __( 'Add a group', 'scout-team' ),
					'new_item_name' => __( 'New group name', 'scout-team' ),
					'search_items'  => __( 'Search groups', 'scout-team' ),
					'not_found'     => __( 'No groups yet.', 'scout-team' ),
					'back_to_items' => __( 'Back to groups', 'scout-team' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_tagcloud'     => false,
				'rewrite'           => false,
				'meta_box_cb'       => false, // The person's edit screen has its own Groups box.
			)
		);
	}

	/** The groups every site starts with: slug => [ name, description ]. */
	public static function default_groups(): array {
		return array(
			'team'     => array( __( 'Team', 'scout-team' ), __( 'People who work here. The team page shows this group.', 'scout-team' ) ),
			'speakers' => array( __( 'Speakers', 'scout-team' ), __( 'People who speak or teach at your events.', 'scout-team' ) ),
			'writers'  => array( __( 'Writers', 'scout-team' ), __( 'People who write posts for the site.', 'scout-team' ) ),
			'members'  => array( __( 'Members', 'scout-team' ), __( 'Members who asked to be listed on the site.', 'scout-team' ) ),
		);
	}

	/** The Team group's term ID, or 0 when it is not there. */
	public static function team_term(): int {
		$term = get_term_by( 'slug', self::TEAM, self::TAX );
		return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
	}

	/** Make a group if it is missing. Returns its term ID, or 0. Never deletes anything. */
	private static function ensure_group( string $slug, string $name, string $description ): int {
		$term = get_term_by( 'slug', $slug, self::TAX );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$made = wp_insert_term( $name, self::TAX, array( 'slug' => $slug, 'description' => $description ) );
		return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
	}

	/** Has the one-time move to People (groups, Shows on, sources) run? */
	public static function upgraded(): bool {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
	}

	/**
	 * The one-time move to People. Makes the default groups (and Attorneys when
	 * any profile is an attorney), puts every existing person in Team and each
	 * attorney in Attorneys too, and saves each profile in the new shape: Shows on
	 * set so live pages stay the same, and every credential line already there
	 * marked as added before sources were tracked, so it keeps showing.
	 * Safe to run twice; it never removes a group or a line.
	 */
	public static function maybe_upgrade(): void {
		if ( self::upgraded() || get_transient( 'scout_team_upgrading' ) ) {
			return;
		}
		set_transient( 'scout_team_upgrading', 1, 5 * MINUTE_IN_SECONDS );

		$groups = array();
		foreach ( self::default_groups() as $slug => $info ) {
			$groups[ $slug ] = self::ensure_group( $slug, $info[0], $info[1] );
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$attorneys = 0;
		foreach ( $ids as $id ) {
			$stored = get_post_meta( (int) $id, self::META, true );
			if ( is_array( $stored ) && ! empty( $stored['attorney'] ) ) {
				$attorneys = self::ensure_group( 'attorneys', __( 'Attorneys', 'scout-team' ), __( 'Attorneys at the firm.', 'scout-team' ) );
				break;
			}
		}
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$stored = get_post_meta( $id, self::META, true );
			if ( is_array( $stored ) ) {
				$moved = self::upgrade_profile( $stored );
				if ( $moved !== $stored ) {
					update_post_meta( $id, self::META, $moved );
				}
			}
			if ( $groups['team'] ) {
				wp_set_object_terms( $id, array( $groups['team'] ), self::TAX, true );
			}
			if ( $attorneys && is_array( $stored ) && ! empty( $stored['attorney'] ) ) {
				wp_set_object_terms( $id, array( $attorneys ), self::TAX, true );
			}
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		delete_transient( 'scout_team_upgrading' );
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

	/** Columns in each repeatable credential list. Every line has a source. */
	public static function groups(): array {
		return array(
			'bar'         => array( 'state', 'year', 'number', 'url', 'source' ),
			'education'   => array( 'school', 'degree', 'year', 'source' ),
			'awards'      => array( 'name', 'year', 'issuer', 'source' ),
			'memberships' => array( 'name', 'source' ),
		);
	}

	/** The places a person can show, in the order the editor lists them. */
	public static function show_keys(): array {
		return array( 'bio', 'team', 'card', 'byline', 'speaker', 'chat' );
	}

	/**
	 * Where a person shows when nobody has chosen yet. These match how the site
	 * worked before Shows on existed: a profile page, a spot on the team page,
	 * and a byline once a login is linked.
	 *
	 * @param int $user Linked WordPress user ID, or 0.
	 */
	public static function default_shows( int $user = 0 ): array {
		return array(
			'bio'     => true,
			'team'    => true,
			'card'    => false,
			'byline'  => $user > 0,
			'speaker' => false,
			'chat'    => false,
		);
	}

	public static function empty_profile(): array {
		return array(
			'role'            => '',
			'bio'             => '',
			'email'           => '',
			'phone'           => '',
			'card_phone'      => '',
			'card_email'      => '',
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
			'shows'           => self::default_shows(),
		);
	}

	/**
	 * Bring a stored profile from before People into the new shape, without
	 * changing what a visitor sees. Pure: used by the one-time move and on
	 * every read, so a profile saved by older code or an import still works.
	 *
	 * - Shows on is added when missing: profile page and team page on, byline
	 *   on when a login is linked.
	 * - A credential line with no source field at all predates sources, so it
	 *   gets "Added before sources were tracked" and keeps showing. A line that
	 *   has the field but leaves it empty is new and stays hidden.
	 * - Memberships saved as plain lines of text become lines with a source.
	 *
	 * @param array $p Stored profile.
	 */
	public static function upgrade_profile( array $p ): array {
		if ( isset( $p['memberships'] ) && is_array( $p['memberships'] ) ) {
			$rows = array();
			foreach ( $p['memberships'] as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				} elseif ( '' !== trim( (string) $row ) ) {
					$rows[] = array(
						'name'   => (string) $row,
						'source' => self::LEGACY_SOURCE,
					);
				}
			}
			$p['memberships'] = $rows;
		}
		foreach ( array_keys( self::groups() ) as $group ) {
			if ( empty( $p[ $group ] ) || ! is_array( $p[ $group ] ) ) {
				continue;
			}
			foreach ( $p[ $group ] as $i => $row ) {
				if ( is_array( $row ) && ! array_key_exists( 'source', $row ) ) {
					$p[ $group ][ $i ]['source'] = self::LEGACY_SOURCE;
				}
			}
		}
		if ( ! isset( $p['shows'] ) || ! is_array( $p['shows'] ) ) {
			$p['shows'] = self::default_shows( (int) ( $p['user'] ?? 0 ) );
		}
		return $p;
	}

	/**
	 * The stored profile, always in the full shape.
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function profile( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		return self::shape( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Any profile array in the full shape: every key, every show switch, every
	 * credential line with all its columns.
	 *
	 * @param array $stored Stored or imported profile.
	 */
	public static function shape( array $stored ): array {
		$p          = wp_parse_args( self::upgrade_profile( $stored ), self::empty_profile() );
		$shows      = self::default_shows( (int) $p['user'] );
		foreach ( self::show_keys() as $key ) {
			$shows[ $key ] = isset( $p['shows'][ $key ] ) ? (bool) $p['shows'][ $key ] : $shows[ $key ];
		}
		$p['shows'] = $shows;
		foreach ( self::groups() as $group => $cols ) {
			$rows = array();
			foreach ( (array) $p[ $group ] as $row ) {
				$row = (array) $row;
				$out = array();
				foreach ( $cols as $col ) {
					$out[ $col ] = (string) ( $row[ $col ] ?? '' );
				}
				$rows[] = $out;
			}
			$p[ $group ] = $rows;
		}
		return $p;
	}

	/**
	 * Does this person show in this place?
	 *
	 * @param array  $p   Profile.
	 * @param string $key bio, team, card, byline, speaker or chat.
	 */
	public static function shows( array $p, string $key ): bool {
		return ! empty( $p['shows'][ $key ] );
	}

	/** Does a credential line have a source? */
	public static function sourced( array $row ): bool {
		return '' !== trim( (string) ( $row['source'] ?? '' ) );
	}

	/**
	 * What the public may see of a profile: the profile page, the team page and
	 * the Person schema all read this, never the stored profile. Card only
	 * contact is taken out, and credential lines without a source are left out.
	 *
	 * @param array $p Profile in the full shape.
	 */
	public static function public_profile( array $p ): array {
		unset( $p['card_phone'], $p['card_email'] );
		foreach ( array_keys( self::groups() ) as $group ) {
			$p[ $group ] = array_values( array_filter( (array) $p[ $group ], array( __CLASS__, 'sourced' ) ) );
		}
		return $p;
	}

	/**
	 * What a business card may show of a person, for Scout Cards. Null when the
	 * post is not a person. The card only phone and email are here, because
	 * they are for the card; credential lines are not. Role, bio and links come
	 * through public_profile(), so the card shows nothing the profile page
	 * would hide. 'on' is true when the person is live with Card switched on.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function card( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$p     = self::profile( $post_id );
		$pub   = self::public_profile( $p );
		$thumb = (int) get_post_thumbnail_id( $post_id );
		return array(
			'id'         => (int) $post_id,
			'status'     => (string) $post->post_status,
			'on'         => 'publish' === $post->post_status && self::shows( $p, 'card' ),
			'name'       => html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'slug'       => (string) $post->post_name,
			'role'       => (string) $pub['role'],
			'bio'        => (string) $pub['bio'],
			'email'      => (string) $p['email'],
			'user'       => (int) $p['user'],
			'phone'      => (string) $p['card_phone'],
			'card_email' => (string) $p['card_email'],
			'links'      => array_values( (array) $pub['same_as'] ),
			'photo_id'   => $thumb,
			'alt'        => $thumb ? trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) : '',
		);
	}

	/**
	 * People with Card switched on, in team page order (Order number, then name).
	 *
	 * @param bool $live_only Only people who are live. False adds drafts and pending people.
	 */
	public static function card_ids( bool $live_only = true ): array {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $live_only ? 'publish' : array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::shows( self::profile( (int) $id ), 'card' ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** How many credential lines have no source (saved, but hidden). */
	public static function unsourced( array $p ): int {
		$n = 0;
		foreach ( array_keys( self::groups() ) as $group ) {
			foreach ( (array) ( $p[ $group ] ?? array() ) as $row ) {
				if ( ! self::sourced( (array) $row ) ) {
					++$n;
				}
			}
		}
		return $n;
	}

	/**
	 * Why a person needs a look, in plain words. Empty when they are fine.
	 *
	 * @param array  $p      Profile in the full shape.
	 * @param bool   $photo  Has a photo (featured image).
	 * @param string $status Post status.
	 * @param bool   $alt     The photo has alt text set in the media library.
	 * @param bool   $pending They sent their details through a link and those wait for an OK.
	 */
	public static function look_reasons( array $p, bool $photo, string $status, bool $alt = true, bool $pending = false ): array {
		$out = array();
		if ( $pending ) {
			$out['pending'] = __( 'Sent their details, waiting for your OK', 'scout-team' );
		}
		if ( ! $photo ) {
			$out['photo'] = __( 'No photo', 'scout-team' );
		} elseif ( ! $alt ) {
			$out['alt'] = __( 'Photo needs alt text', 'scout-team' );
		}
		$n = self::unsourced( $p );
		if ( $n ) {
			/* translators: %d: number of credential lines without a source. */
			$out['source'] = sprintf( _n( '%d line needs a source', '%d lines need a source', $n, 'scout-team' ), $n );
		}
		if ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			$out['draft'] = __( 'Not live yet', 'scout-team' );
		}
		return $out;
	}

	/**
	 * Why this person needs a look: details they sent waiting for an OK, no
	 * photo, a photo with no alt text, a credential line with no source, or
	 * not live yet.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function needs_look( int $post_id ): array {
		$thumb   = (int) get_post_thumbnail_id( $post_id );
		$alt     = $thumb && '' !== trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
		$pending = get_post_meta( $post_id, self::PENDING, true );
		return self::look_reasons( self::profile( $post_id ), $thumb > 0, (string) get_post_status( $post_id ), $alt, is_array( $pending ) && $pending );
	}

	/**
	 * Is this person on the team page? Live, in the Team group, and Team page
	 * switched on. Before the Team group exists, everyone live counts.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function on_team_page( int $post_id ): bool {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}
		$p = self::profile( $post_id );
		if ( ! self::shows( $p, 'team' ) ) {
			return false;
		}
		$team = self::upgraded() ? self::team_term() : 0;
		return ! $team || has_term( $team, self::TAX, $post_id );
	}

	/**
	 * Live people whose profile page is switched off. Their page answers "not
	 * found" to visitors and stays out of the sitemap.
	 */
	public static function hidden_profiles(): array {
		static $hidden = null;
		if ( null === $hidden ) {
			$hidden = array();
			$ids    = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( ! self::shows( self::profile( (int) $id ), 'bio' ) ) {
					$hidden[] = (int) $id;
				}
			}
		}
		return $hidden;
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
		$out['phone']           = self::clean_phone( (string) ( $in['phone'] ?? '' ) );
		$out['card_email']      = sanitize_email( (string) ( $in['card_email'] ?? '' ) );
		$out['card_phone']      = self::clean_phone( (string) ( $in['card_phone'] ?? '' ) );
		$out['user']            = absint( $in['user'] ?? 0 );
		$out['attorney']        = ! empty( $in['attorney'] ) ? 1 : 0;
		$branch                 = sanitize_key( (string) ( $in['military_branch'] ?? '' ) );
		$out['military_branch'] = array_key_exists( $branch, self::branches() ) ? $branch : '';
		$out['military_years']  = sanitize_text_field( (string) ( $in['military_years'] ?? '' ) );
		$out['languages']       = sanitize_text_field( (string) ( $in['languages'] ?? '' ) );

		// Shows on: the editor always sends the list (a hidden field marks it), so an
		// unticked box means off. Without the list, keep the defaults.
		if ( isset( $in['shows'] ) && is_array( $in['shows'] ) ) {
			foreach ( self::show_keys() as $key ) {
				$out['shows'][ $key ] = ! empty( $in['shows'][ $key ] );
			}
		} else {
			$out['shows'] = self::default_shows( $out['user'] );
		}

		// Memberships used to be one per line. Lines typed that way have no source yet.
		if ( isset( $in['memberships'] ) && ! is_array( $in['memberships'] ) ) {
			$rows = array();
			foreach ( self::lines( (string) $in['memberships'] ) as $line ) {
				$rows[] = array(
					'name'   => $line,
					'source' => '',
				);
			}
			$in['memberships'] = $rows;
		}

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
				// A row needs its first column (state, school, award name, membership).
				if ( '' !== $clean[ $cols[0] ] ) {
					$rows[] = $clean;
				}
			}
			$out[ $group ] = array_slice( $rows, 0, 30 );
		}
		return $out;
	}

	/** Digits and phone punctuation only. */
	private static function clean_phone( string $raw ): string {
		return (string) preg_replace( '/[^0-9+().\-\s]/', '', sanitize_text_field( $raw ) );
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

	/** The person linked to a WordPress user, for bylines. Only people with Byline on. */
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
				$p   = self::profile( (int) $id );
				$uid = (int) $p['user'];
				if ( $uid && self::shows( $p, 'byline' ) && ! isset( $map[ $uid ] ) ) {
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
		$p      = self::public_profile( self::profile( $post_id ) );
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
