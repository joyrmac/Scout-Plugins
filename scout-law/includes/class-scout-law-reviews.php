<?php
/**
 * "Reviewed by" stamps: an attorney on the firm's Team signs a page as
 * reviewed, the page shows who and when, and pages not reviewed in six
 * months (by default) come back on Home and in the Inbox.
 *
 * A stamp is two values on the page: the attorney's Team profile and the
 * date. The page shows "Reviewed by <attorney>, <role>. Last reviewed
 * <date>." linked to the profile, and its schema gains reviewedBy (the
 * attorney's Person node from Scout Team) and lastReviewed. Reviewing a
 * page does not change its words, so it never needs a new approval.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Reviews {

	const OPTION = 'scout_law_reviews';
	const LOG    = 'scout_law_review_log';
	const BY     = '_scout_law_reviewed_by';
	const AT     = '_scout_law_reviewed_at';
	const NONCE  = 'scout_law_review';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_classic' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 30 );
		add_filter( 'scout_schema_graph', array( __CLASS__, 'schema' ), 30 );
		add_filter( 'scout_hub_inbox', array( __CLASS__, 'inbox' ) );
		add_filter( 'scout_hub_today', array( __CLASS__, 'today' ) );
		foreach ( self::types() as $type ) {
			add_action( 'rest_after_insert_' . $type, array( __CLASS__, 'rest_record' ), 10, 2 );
		}
	}

	/* ---- Settings ----------------------------------------------------- */

	/** @return array{types:string[],show:bool,months:int} */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'types'  => isset( $saved['types'] ) ? array_values( array_map( 'strval', (array) $saved['types'] ) ) : array( 'page' ),
			'show'   => isset( $saved['show'] ) ? (bool) $saved['show'] : true,
			'months' => in_array( (int) ( $saved['months'] ?? 6 ), array( 3, 6, 12 ), true ) ? (int) ( $saved['months'] ?? 6 ) : 6,
		);
	}

	/** The kinds of content an attorney signs as reviewed. Pages to start (practice and service pages). */
	public static function types(): array {
		/** Filter the post types that carry a "Reviewed by" stamp. */
		return array_values( array_map( 'strval', (array) apply_filters( 'scout_law_review_types', self::settings()['types'] ) ) );
	}

	public static function stamped( string $type ): bool {
		return in_array( $type, self::types(), true );
	}

	/* ---- Who can sign ---------------------------------------------------- */

	/**
	 * Attorneys who can sign a review: published Team profiles marked as an
	 * attorney. Scout team logins never appear here (they are not on Team).
	 *
	 * @return array<int,string> Team post ID => name.
	 */
	public static function attorneys(): array {
		if ( ! class_exists( 'Scout_Team' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => Scout_Team::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ) ) as $person ) {
			if ( ! empty( Scout_Team::profile( (int) $person->ID )['attorney'] ) ) {
				$out[ (int) $person->ID ] = (string) get_the_title( $person );
			}
		}
		return $out;
	}

	/**
	 * Attorney profiles that are not live yet (a team bio waiting for the firm's
	 * approval, or a draft). They can sign once their profile is published.
	 *
	 * @return array<int,string> Team post ID => name.
	 */
	public static function waiting_attorneys(): array {
		if ( ! class_exists( 'Scout_Team' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => Scout_Team::POST_TYPE, 'post_status' => array( 'pending', 'draft' ), 'numberposts' => 50 ) ) as $person ) {
			if ( ! empty( Scout_Team::profile( (int) $person->ID )['attorney'] ) ) {
				$out[ (int) $person->ID ] = (string) get_the_title( $person );
			}
		}
		return $out;
	}

	/* ---- The stamp ------------------------------------------------------ */

	/** @return array{by:int,at:int} The stamp on a page; by 0 means never reviewed. */
	public static function stamp( int $post_id ): array {
		return array(
			'by' => (int) get_post_meta( $post_id, self::BY, true ),
			'at' => (int) get_post_meta( $post_id, self::AT, true ),
		);
	}

	/**
	 * Whether a review is due: never reviewed, or the last one is older than
	 * the review period. Pure, so it can be tested without WordPress.
	 */
	public static function is_due( int $at, int $months, int $now ): bool {
		if ( $at <= 0 ) {
			return true;
		}
		return $at < self::cutoff( $months, $now );
	}

	/** The time before which a review counts as out of date. */
	public static function cutoff( int $months, int $now ): int {
		return (int) strtotime( '-' . max( 1, $months ) . ' months', $now );
	}

	/** Record a review of a page by an attorney, now, and keep it in the log. */
	public static function record( int $post_id, int $attorney ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! isset( self::attorneys()[ $attorney ] ) ) {
			return false;
		}
		$now = time();
		update_post_meta( $post_id, self::BY, $attorney );
		update_post_meta( $post_id, self::AT, $now );
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'post'     => $post_id,
			'title'    => $post->post_title,
			'attorney' => $attorney,
			'name'     => self::attorneys()[ $attorney ],
			'at'       => $now,
			'user'     => get_current_user_id(),
		);
		update_option( self::LOG, array_slice( $log, -500 ), false );
		/** An attorney's review of a page was recorded. The Site log can listen. */
		do_action( 'scout_law_page_reviewed', $post_id, $attorney );
		return true;
	}

	/** Every review on record, newest first. */
	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/* ---- The block editor and the classic box -------------------------- */

	public static function register_meta(): void {
		foreach ( self::types() as $type ) {
			foreach ( array( self::BY => 0, self::AT => -1 ) as $key => $default ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'          => 'integer',
						'single'        => true,
						// -1 means "never reviewed", so 0 is free to mean "reviewed today" from the editor.
						'default'       => $default,
						'show_in_rest'  => true,
						'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
					)
				);
			}
		}
	}

	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::stamped( (string) $screen->post_type ) ) {
			return;
		}
		wp_enqueue_script( 'scout-law-reviews', SCOUT_LAW_URL . 'assets/reviews-editor.js', array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ), SCOUT_LAW_VERSION, true );
		$people = array();
		foreach ( self::attorneys() as $id => $name ) {
			$people[] = array( 'value' => $id, 'label' => $name );
		}
		wp_localize_script(
			'scout-law-reviews',
			'scoutLawReviews',
			array(
				'attorneys' => $people,
				'months'    => self::settings()['months'],
				'teamUrl'   => class_exists( 'Scout_Team' ) ? admin_url( 'post-new.php?post_type=' . Scout_Team::POST_TYPE ) : '',
			)
		);
	}

	/**
	 * Block editor: a review asks for the date with 0 ("reviewed today"); the
	 * server writes the real time and the log once the save is in.
	 */
	public static function rest_record( $post, $request ): void {
		$meta = (array) ( $request['meta'] ?? array() );
		if ( array_key_exists( self::AT, $meta ) && (int) $meta[ self::AT ] < 0 ) {
			delete_post_meta( (int) $post->ID, self::AT ); // "Reviewed today" was ticked, then unticked: no stamp.
			return;
		}
		if ( ! array_key_exists( self::AT, $meta ) || 0 !== (int) $meta[ self::AT ] ) {
			return;
		}
		$by = (int) ( $meta[ self::BY ] ?? get_post_meta( $post->ID, self::BY, true ) );
		if ( ! self::record( (int) $post->ID, $by ) ) {
			delete_post_meta( (int) $post->ID, self::AT ); // Not a real attorney: leave no half stamp.
		}
	}

	public static function box( string $type, $post ): void {
		if ( ! self::stamped( $type ) ) {
			return;
		}
		add_meta_box( 'scout-law-review', __( 'Reviewed by', 'scout-law' ), array( __CLASS__, 'render_box' ), $type, 'side', 'default', array( '__back_compat_meta_box' => true ) );
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'scout_law_review_nonce' );
		echo '<p>' . esc_html( self::status_text( (int) $post->ID ) ) . '</p>';
		$people = self::attorneys();
		if ( ! $people ) {
			echo '<p class="description">' . esc_html__( 'Add an attorney in People (tick "Attorney") to sign reviews.', 'scout-law' ) . '</p>';
			return;
		}
		echo '<p><label for="scout-law-review-by">' . esc_html__( 'Attorney', 'scout-law' ) . '</label><br><select id="scout-law-review-by" name="scout_law_review_by" class="widefat"><option value="0">' . esc_html__( 'Choose an attorney', 'scout-law' ) . '</option>';
		$current = self::stamp( (int) $post->ID )['by'];
		foreach ( $people as $id => $name ) {
			echo '<option value="' . (int) $id . '"' . selected( $current, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p><p><label><input type="checkbox" name="scout_law_review_now" value="1"> ' . esc_html__( 'Reviewed today', 'scout-law' ) . '</label></p>';
	}

	public static function save_classic( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! self::stamped( $post->post_type ) ) {
			return;
		}
		if ( empty( $_POST['scout_law_review_now'] ) || ! isset( $_POST['scout_law_review_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['scout_law_review_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		self::record( $post_id, absint( $_POST['scout_law_review_by'] ?? 0 ) );
	}

	/** One plain sentence for a page's review status. */
	public static function status_text( int $post_id ): string {
		$s = self::stamp( $post_id );
		if ( ! $s['by'] || ! $s['at'] ) {
			return __( 'Not reviewed by an attorney yet.', 'scout-law' );
		}
		$name = get_the_title( $s['by'] );
		$date = wp_date( get_option( 'date_format' ), $s['at'] );
		if ( self::is_due( $s['at'], self::settings()['months'], time() ) ) {
			/* translators: 1: attorney, 2: date */
			return sprintf( __( 'Last reviewed by %1$s on %2$s. A new review is due.', 'scout-law' ), $name, $date );
		}
		/* translators: 1: attorney, 2: date */
		return sprintf( __( 'Reviewed by %1$s on %2$s.', 'scout-law' ), $name, $date );
	}

	/* ---- On the site ---------------------------------------------------- */

	/** The visible line, or '' when the page has no stamp or the attorney's profile is not public. */
	public static function line( int $post_id ): string {
		$s = self::stamp( $post_id );
		if ( ! $s['by'] || ! $s['at'] || 'publish' !== get_post_status( $s['by'] ) ) {
			return '';
		}
		$role = class_exists( 'Scout_Team' ) ? (string) Scout_Team::profile( $s['by'] )['role'] : '';
		$who  = '<a href="' . esc_url( (string) get_permalink( $s['by'] ) ) . '">' . esc_html( get_the_title( $s['by'] ) ) . '</a>' . ( '' !== $role ? ', ' . esc_html( $role ) : '' );
		$date = '<time datetime="' . esc_attr( gmdate( 'Y-m-d', $s['at'] ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ), $s['at'] ) ) . '</time>';
		/* translators: 1: attorney link and role, 2: date */
		return '<p class="scout-law-reviewed">' . sprintf( __( 'Reviewed by %1$s. Last reviewed %2$s.', 'scout-law' ), $who, $date ) . '</p>';
	}

	/** Below the page's words, unless the theme places the line itself. */
	public static function content( $html ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || current_theme_supports( 'scout-law-reviewed' ) ) {
			return $html;
		}
		$post = get_post();
		if ( ! $post || ! self::stamped( $post->post_type ) || ! self::settings()['show'] ) {
			return $html;
		}
		return $html . self::line( (int) $post->ID );
	}

	/**
	 * reviewedBy and lastReviewed on the page's WebPage node, with the
	 * attorney's Person node. Scout Core adds the WebPage node after this
	 * filter runs, so it is built here with Core's own builder first.
	 */
	public static function schema( $graph ) {
		if ( ! is_array( $graph ) || ! is_singular() || ! class_exists( 'Scout_Team_Schema' ) || ! class_exists( 'Scout_Schema' ) ) {
			return $graph;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! self::stamped( $post->post_type ) || ! self::settings()['show'] ) {
			return $graph;
		}
		$s = self::stamp( (int) $post->ID );
		if ( ! $s['by'] || $s['at'] <= 0 || 'publish' !== get_post_status( $s['by'] ) ) {
			return $graph;
		}
		$graph      = Scout_Schema::add_webpage( $graph );
		$page_types = array( 'WebPage', 'ProfilePage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'FAQPage', 'QAPage' );
		$person_id  = Scout_Team_Schema::person_id( $s['by'] );
		$present    = false;
		foreach ( $graph as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( ( $node['@id'] ?? '' ) === $person_id ) {
				$present = true;
			}
			if ( array_intersect( (array) ( $node['@type'] ?? array() ), $page_types ) && ! isset( $done ) ) {
				$graph[ $key ]['reviewedBy']   = array( '@id' => $person_id );
				$graph[ $key ]['lastReviewed'] = gmdate( 'Y-m-d', $s['at'] );
				$done                          = true;
			}
		}
		if ( ! $present ) {
			$graph[ 'reviewer-' . $s['by'] ] = Scout_Team_Schema::person( $s['by'] );
		}
		return $graph;
	}

	/* ---- What is due ----------------------------------------------------- */

	/**
	 * Live pages that need an attorney's review: never reviewed, or not within
	 * the review period. Oldest first.
	 *
	 * @return int[]
	 */
	public static function due(): array {
		if ( ! self::types() ) {
			return array();
		}
		$months = self::settings()['months'];
		$now    = time();
		$out    = array();
		foreach ( get_posts( array( 'post_type' => self::types(), 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'modified', 'order' => 'ASC', 'no_found_rows' => true ) ) as $post ) {
			if ( (int) get_option( 'wp_page_for_privacy_policy' ) === (int) $post->ID ) {
				continue; // The privacy page gets its own yearly review under Policies.
			}
			if ( self::is_due( self::stamp( (int) $post->ID )['at'], $months, $now ) ) {
				$out[] = (int) $post->ID;
			}
		}
		return $out;
	}

	public static function inbox( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		$due  = self::due();
		if ( $due && self::attorneys() ) {
			$rows[] = array(
				'label' => __( 'Pages due for an attorney\'s review', 'scout-law' ),
				'count' => count( $due ),
				'url'   => Scout_Law_Reviews_Admin::url(),
				'note'  => __( 'Pages no attorney has reviewed in the review period. Each review takes about five minutes.', 'scout-law' ),
			);
		}
		return $rows;
	}

	public static function today( $items ) {
		$items = is_array( $items ) ? $items : array();
		$due   = self::due();
		if ( ! $due || ! self::attorneys() ) {
			return $items;
		}
		$n       = count( $due );
		$items[] = array(
			'id'       => 'law-reviews-due',
			/* translators: %d: pages */
			'label'    => sprintf( _n( 'Review %d page with an attorney', 'Review %d pages with an attorney', $n, 'scout-law' ), $n ),
			'why'      => __( 'Law-firm pages are read by an attorney on a schedule, and these are due.', 'scout-law' ),
			'minutes'  => min( 30, 5 * $n ),
			'url'      => Scout_Law_Reviews_Admin::url(),
			'button'   => __( 'See the pages', 'scout-law' ),
			'priority' => 30,
			'cap'      => 'edit_pages',
		);
		return $items;
	}
}

if ( ! function_exists( 'scout_law_reviewed' ) ) {
	/** Print the "Reviewed by" line where the theme wants it (declare add_theme_support( 'scout-law-reviewed' )). */
	function scout_law_reviewed( int $post_id = 0 ): void {
		$post_id = $post_id ? $post_id : (int) get_the_ID();
		if ( $post_id && Scout_Law_Reviews::stamped( (string) get_post_type( $post_id ) ) && Scout_Law_Reviews::settings()['show'] ) {
			echo Scout_Law_Reviews::line( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}
}
