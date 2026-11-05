<?php
/**
 * Written by: a box on the post editor that picks one or more people from
 * People as the post's byline, with or without a WordPress login. Guest
 * writers, speakers with no login and posts written by staff for someone
 * else can all be credited.
 *
 * - Stored on the post as _scout_people_authors: profile IDs, in order.
 *   Registered for the REST API (only people who can edit the post can
 *   change it), so the block editor saves it with the post.
 * - In the block editor the box is a panel in the post sidebar. In the
 *   classic editor, and for a post type without custom fields, it is a meta
 *   box. Both list live people; people whose byline is off can be picked but
 *   carry a note, and are left off the byline until it is on.
 * - What visitors see is in Scout_Team_Front (names and links) and
 *   Scout_Team_Schema (the article's author). With nobody picked, nothing
 *   changes: the post's author works as it always did.
 *
 * Posts only. A site adds other post types with the
 * scout_people_byline_post_types filter.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Byline {

	/** The classic box's nonce action and field. */
	const NONCE       = 'scout_people_byline';
	const NONCE_FIELD = '_scout_people_byline';

	/** Fields the classic box sends. */
	const FIELD     = 'scout_people_authors';
	const FIELD_ADD = 'scout_people_authors_add';

	/** Cached list of posts with people picked: post ID => [ author, [ profile IDs ] ]. */
	const CACHE = 'scout_team_bylines';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 30 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'classic_assets' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
	}

	/* ---------- the stored value ---------- */

	public static function register_meta(): void {
		foreach ( Scout_Team::byline_post_types() as $type ) {
			register_post_meta(
				$type,
				Scout_Team::AUTHORS,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'description'       => __( 'People credited in Written by, in order.', 'scout-team' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						unset( $allowed, $meta_key );
						return current_user_can( 'edit_post', (int) $post_id );
					},
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				)
			);
		}
	}

	/**
	 * Clean a picked list: profile IDs that are people, each once, in order.
	 *
	 * @param mixed $value Sent value.
	 */
	public static function sanitize( $value ): array {
		return array_values(
			array_filter(
				Scout_Team::clean_ids( $value ),
				static function ( $id ) {
					return Scout_Team::POST_TYPE === get_post_type( $id );
				}
			)
		);
	}

	/* ---------- the picker ---------- */

	/**
	 * Live people the picker offers, by name: [ id, name, byline on ].
	 */
	public static function choices(): array {
		$ids = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$out[] = array(
				'id'     => $id,
				'name'   => Scout_Team::name( $id ),
				'byline' => Scout_Team::shows( Scout_Team::profile( $id ), 'byline' ),
			);
		}
		return $out;
	}

	/** Does this post type get the block editor panel (it needs custom fields for its meta)? */
	private static function has_panel( string $type ): bool {
		return post_type_supports( $type, 'custom-fields' ) && function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $type );
	}

	/** The words the box and the panel share. */
	public static function text(): array {
		return array(
			'title'   => __( 'Written by', 'scout-team' ),
			'help'    => __( 'Pick who wrote this. Leave it empty to use the post’s author.', 'scout-team' ),
			'add'     => __( 'Add a person', 'scout-team' ),
			'button'  => __( 'Add', 'scout-team' ),
			'remove'  => __( 'Remove', 'scout-team' ),
			'up'      => __( 'Move up', 'scout-team' ),
			'down'    => __( 'Move down', 'scout-team' ),
			'off'     => __( 'Byline off', 'scout-team' ),
			'offNote' => __( 'Their byline is off in People, so this post leaves them out until it’s on.', 'scout-team' ),
			'gone'    => __( 'Not live in People', 'scout-team' ),
			'none'    => __( 'No people yet. Add them in People.', 'scout-team' ),
			'pick'    => __( 'Choose a person', 'scout-team' ),
			'setting' => Scout_Team::settings()['bylines'] ? '' : __( 'Bylines are off in People settings, so this shows nowhere yet.', 'scout-team' ),
		);
	}

	/**
	 * The classic box. In the block editor it shows only for a post type
	 * without the sidebar panel.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      The post.
	 */
	public static function box( $post_type, $post = null ): void {
		unset( $post );
		$post_type = (string) $post_type;
		if ( ! in_array( $post_type, Scout_Team::byline_post_types(), true ) ) {
			return;
		}
		add_meta_box(
			'scout-people-written-by',
			self::text()['title'],
			array( __CLASS__, 'render_box' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => self::has_panel( $post_type ) )
		);
	}

	/**
	 * The classic box: the picked people in order, each with Remove (and Move
	 * up and Move down with the script), and a list to add one more. Without
	 * the script, untick a person to remove them and pick one to add on save.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function render_box( $post ): void {
		$text    = self::text();
		$picked  = Scout_Team::chosen_authors( (int) $post->ID );
		$choices = self::choices();
		$by_id   = array();
		foreach ( $choices as $c ) {
			$by_id[ $c['id'] ] = $c;
		}
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<div class="scout-people-byline" data-spb data-spb-offnote="' . esc_attr( $text['offNote'] ) . '">';
		echo '<p class="description">' . esc_html( $text['help'] ) . '</p>';
		if ( '' !== $text['setting'] ) {
			echo '<p class="description"><strong>' . esc_html( $text['setting'] ) . '</strong></p>';
		}
		echo '<ul class="spb-list" data-spb-list>';
		foreach ( $picked as $id ) {
			self::row( $id, $by_id[ $id ] ?? null, $text );
		}
		echo '</ul>';
		echo '<template data-spb-template>';
		self::row( 0, null, $text );
		echo '</template>';
		if ( $choices ) {
			echo '<p class="spb-add"><label for="spb-add">' . esc_html( $text['add'] ) . '</label><br>';
			echo '<select id="spb-add" name="' . esc_attr( self::FIELD_ADD ) . '" data-spb-select>';
			echo '<option value="">' . esc_html( $text['pick'] ) . '</option>';
			foreach ( $choices as $c ) {
				$label = $c['byline'] ? $c['name'] : $c['name'] . ' (' . $text['off'] . ')';
				echo '<option value="' . esc_attr( (string) $c['id'] ) . '" data-name="' . esc_attr( $c['name'] ) . '" data-off="' . ( $c['byline'] ? '0' : '1' ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select> <button type="button" class="button" hidden data-spb-add>' . esc_html( $text['button'] ) . '</button></p>';
		} else {
			echo '<p class="description">' . esc_html( $text['none'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One picked person in the classic box.
	 *
	 * @param int        $id     Person post ID, or 0 for the template.
	 * @param array|null $choice The person's choice record, or null when they are not live.
	 * @param array      $text   The box words.
	 */
	private static function row( int $id, ?array $choice, array $text ): void {
		$name = $choice ? $choice['name'] : ( $id ? Scout_Team::name( $id ) : '' );
		$note = '';
		if ( $id && ! $choice ) {
			$note = $text['gone'];
		} elseif ( $choice && ! $choice['byline'] ) {
			$note = $text['offNote'];
		}
		echo '<li class="spb-row" data-spb-row>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '[]" value="' . esc_attr( (string) $id ) . '" checked data-spb-id> <span data-spb-name>' . esc_html( $name ) . '</span></label>';
		echo '<span class="spb-tools" hidden data-spb-tools>';
		echo '<button type="button" class="button-link" data-spb-up>' . esc_html( $text['up'] ) . '</button> ';
		echo '<button type="button" class="button-link" data-spb-down>' . esc_html( $text['down'] ) . '</button> ';
		echo '<button type="button" class="button-link button-link-delete" data-spb-remove>' . esc_html( $text['remove'] ) . '</button>';
		echo '</span>';
		echo '<span class="description spb-note" data-spb-note' . ( '' === $note ? ' hidden' : '' ) . '>' . esc_html( $note ) . '</span>';
		echo '</li>';
	}

	/**
	 * Save the classic box. The block editor saves through the REST API.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    The post.
	 */
	public static function save( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, Scout_Team::byline_post_types(), true ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to whole numbers in picked().
		$ids = self::picked(
			isset( $_POST[ self::FIELD ] ) ? (array) wp_unslash( $_POST[ self::FIELD ] ) : array(),
			isset( $_POST[ self::FIELD_ADD ] ) ? wp_unslash( $_POST[ self::FIELD_ADD ] ) : ''
		);
		// phpcs:enable
		self::store( (int) $post_id, $ids );
	}

	/**
	 * What the classic box sent: the ticked people in order, then the one
	 * picked to add.
	 *
	 * @param array $ticked Ticked profile IDs.
	 * @param mixed $add    The profile picked to add, or ''.
	 */
	public static function picked( array $ticked, $add ): array {
		$ticked[] = is_scalar( $add ) ? $add : 0;
		return Scout_Team::clean_ids( $ticked );
	}

	/**
	 * Store a post's picked people. An empty list removes the value, so the
	 * post works as it always did.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $ids     Profile IDs.
	 */
	public static function store( int $post_id, array $ids ): void {
		$ids = self::sanitize( $ids );
		if ( $ids ) {
			update_post_meta( $post_id, Scout_Team::AUTHORS, $ids );
		} else {
			delete_post_meta( $post_id, Scout_Team::AUTHORS );
		}
	}

	/* ---------- scripts ---------- */

	/** The data both scripts read. */
	private static function script_data(): array {
		return array(
			'key'     => Scout_Team::AUTHORS,
			'types'   => Scout_Team::byline_post_types(),
			'people'  => self::choices(),
			'text'    => self::text(),
		);
	}

	/** The sidebar panel in the block editor, for post types that have it. */
	public static function block_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, Scout_Team::byline_post_types(), true ) || ! self::has_panel( (string) $screen->post_type ) ) {
			return;
		}
		wp_enqueue_script( 'scout-people-byline', SCOUT_TEAM_URL . 'assets/scout-people-byline.js', array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data' ), SCOUT_TEAM_VERSION, true );
		wp_add_inline_script( 'scout-people-byline', 'window.scoutPeopleByline = ' . wp_json_encode( self::script_data() ) . ';', 'before' );
	}

	/** Move up, Move down and Add in the classic box. */
	public static function classic_assets( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( (string) $screen->post_type, Scout_Team::byline_post_types(), true ) ) {
			return;
		}
		wp_enqueue_script( 'scout-people-byline-box', SCOUT_TEAM_URL . 'assets/scout-people-byline-box.js', array(), SCOUT_TEAM_VERSION, true );
	}

	/* ---------- counts for the People screen ---------- */

	/**
	 * Posts with people picked, live, in byline post types: post ID =>
	 * [ author ID, [ profile IDs ] ]. Cached until a pick or a post changes.
	 */
	public static function map(): array {
		$map = get_transient( self::CACHE );
		if ( is_array( $map ) ) {
			return $map;
		}
		$map   = array();
		$posts = get_posts(
			array(
				'post_type'              => Scout_Team::byline_post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 2000,
				'meta_key'               => Scout_Team::AUTHORS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one cached query.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $posts as $post ) {
			$ids = Scout_Team::chosen_authors( (int) $post->ID );
			if ( $ids ) {
				$map[ (int) $post->ID ] = array( (int) $post->post_author, $ids );
			}
		}
		set_transient( self::CACHE, $map, DAY_IN_SECONDS );
		return $map;
	}

	/** Forget the cached list when a pick changes. */
	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( Scout_Team::AUTHORS === $meta_key ) {
			delete_transient( self::CACHE );
		}
	}

	/** Forget the cached list when a post goes live, changes or comes down. */
	public static function status_changed( $new, $old, $post ): void {
		unset( $new, $old );
		if ( $post instanceof WP_Post && in_array( $post->post_type, Scout_Team::byline_post_types(), true ) ) {
			delete_transient( self::CACHE );
		}
	}

	/**
	 * The login whose posts credit this person when nobody is picked: their
	 * linked login, when their byline is on and the login points at them.
	 *
	 * @param int $member Person post ID.
	 */
	public static function login_for( int $member ): int {
		$p    = Scout_Team::profile( $member );
		$user = (int) $p['user'];
		return $user > 0 && Scout_Team::shows( $p, 'byline' ) && Scout_Team::member_for_user( $user ) === $member ? $user : 0;
	}

	/**
	 * Count a person's bylines from the cached list: posts where they are
	 * picked, plus posts by their login where nobody else is picked. A post
	 * that is both counts once. Pure, so it can be checked without WordPress.
	 *
	 * @param int   $member      Person post ID.
	 * @param int   $login       Their byline login, or 0.
	 * @param int   $login_posts Live posts by that login.
	 * @param array $map         post ID => [ author ID, [ picked IDs who can be credited ] ].
	 */
	public static function tally( int $member, int $login, int $login_posts, array $map ): int {
		$n     = 0;
		$taken = 0;
		foreach ( $map as $row ) {
			list( $author, $people ) = $row;
			if ( ! $people ) {
				continue;
			}
			if ( in_array( $member, $people, true ) ) {
				++$n;
			}
			if ( $login && (int) $author === $login ) {
				++$taken;
			}
		}
		return $n + ( $login ? max( 0, $login_posts - $taken ) : 0 );
	}

	/** The cached list with only the picked people who can be credited now. */
	private static function live_map(): array {
		static $live = null;
		if ( null === $live ) {
			$live = array();
			foreach ( self::map() as $post_id => $row ) {
				$live[ $post_id ] = array( (int) $row[0], array_values( array_filter( $row[1], array( 'Scout_Team', 'byline_ready' ) ) ) );
			}
		}
		return $live;
	}

	/**
	 * How many live posts credit this person on their byline.
	 *
	 * @param int $member Person post ID.
	 */
	public static function count_for( int $member ): int {
		$login = self::login_for( $member );
		$posts = $login ? (int) count_user_posts( $login, Scout_Team::byline_post_types(), true ) : 0;
		return self::tally( $member, $login, $posts, self::live_map() );
	}

	/**
	 * The newest live posts that credit this person, for a theme's "posts by"
	 * list: posts where they are picked, plus posts by their login where
	 * nobody else is picked, each once.
	 *
	 * @param int $member Person post ID.
	 * @param int $limit  How many, at most.
	 * @return int[]
	 */
	public static function posts_for( int $member, int $limit = 10 ): array {
		$limit  = max( 1, $limit );
		$login  = self::login_for( $member );
		$picked = array();
		$taken  = array();
		foreach ( self::live_map() as $post_id => $row ) {
			if ( ! $row[1] ) {
				continue;
			}
			if ( in_array( $member, $row[1], true ) ) {
				$picked[] = (int) $post_id;
			} elseif ( $login && $row[0] === $login ) {
				$taken[] = (int) $post_id;
			}
		}
		$own = array();
		if ( $login ) {
			$own = get_posts(
				array(
					'post_type'      => Scout_Team::byline_post_types(),
					'post_status'    => 'publish',
					'author'         => $login,
					'post__not_in'   => $taken, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small list.
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}
		$ids = array_values( array_unique( array_merge( $picked, array_map( 'intval', $own ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Scout_Team::byline_post_types(),
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
	}
}
