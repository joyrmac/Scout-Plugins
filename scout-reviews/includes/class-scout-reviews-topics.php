<?php
/**
 * Review topics: which page a review belongs on.
 *
 * Each place a theme shows reviews (a "slot") is a topic, such as
 * "law-firms" or "local-seo". A review tagged with that topic shows in that
 * slot. Topics come from three places, merged in this order:
 *
 * 1. The theme declares its slots up front with the `scout_reviews_slots`
 *    filter (label + keywords). Preferred: the topics exist in the admin
 *    before anyone visits a page.
 * 2. A slot that renders with a topic nobody declared registers itself, named
 *    after the page it is on. Fallback for themes that skip step 1.
 * 3. Anyone can add or rename topics at Reviews > Topics, and give each one
 *    its own keywords.
 *
 * Keywords drive the suggestions in the inbox: a review that mentions
 * "attorney" gets the law-firms topic pre-checked.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Topics {

	const TAX          = 'scout_review_topic';
	const META_KEYWORD = 'scout_keywords';
	const SEEN_OPTION  = 'scout_reviews_seen_slots';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_declared_terms' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'maybe_flush' ), 10, 4 );
		add_action( 'delete_' . self::TAX, array( __CLASS__, 'forget' ), 10, 3 );

		add_action( self::TAX . '_add_form_fields', array( __CLASS__, 'add_field' ) );
		add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'edit_field' ) );
		add_action( 'created_' . self::TAX, array( __CLASS__, 'save_field' ) );
		add_action( 'edited_' . self::TAX, array( __CLASS__, 'save_field' ) );
	}

	public static function register(): void {
		register_taxonomy(
			self::TAX,
			Scout_Reviews_Post_Type::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Topics', 'scout-reviews' ),
					'singular_name' => __( 'Topic', 'scout-reviews' ),
					'all_items'     => __( 'All topics', 'scout-reviews' ),
					'edit_item'     => __( 'Edit topic', 'scout-reviews' ),
					'add_new_item'  => __( 'Add topic', 'scout-reviews' ),
					'search_items'  => __( 'Search topics', 'scout-reviews' ),
					'not_found'     => __( 'No topics yet.', 'scout-reviews' ),
				),
				'description'       => __( 'Which pages a review shows on.', 'scout-reviews' ),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'hierarchical'      => true, // Checkbox list in the editor, which reads better than a tag box here.
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	/**
	 * Every slot the site uses: slug => { label, keywords }.
	 */
	public static function slots(): array {
		$slots = array();

		// 2. Slots that registered themselves on render.
		$seen = get_option( self::SEEN_OPTION, array() );
		foreach ( is_array( $seen ) ? $seen : array() as $slug => $label ) {
			$slots[ $slug ] = array( 'label' => (string) $label, 'keywords' => array() );
		}

		/**
		 * Declare the review slots a theme uses.
		 *
		 * @param array $slots slug => [ 'label' => string, 'keywords' => string[] ].
		 */
		$declared = (array) apply_filters( 'scout_reviews_slots', array() );
		foreach ( $declared as $slug => $info ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$slots[ $slug ] = array(
				'label'    => (string) ( $info['label'] ?? $slug ),
				'keywords' => array_values( array_filter( array_map( 'strval', (array) ( $info['keywords'] ?? array() ) ) ) ),
			);
		}
		return $slots;
	}

	/**
	 * Create a topic for every declared slot that does not have one yet.
	 * Never renames or deletes: the admin's edits win.
	 */
	public static function ensure_declared_terms(): void {
		foreach ( (array) apply_filters( 'scout_reviews_slots', array() ) as $slug => $info ) {
			$slug = sanitize_title( (string) $slug );
			$info = array( 'label' => (string) ( $info['label'] ?? $slug ) );
			if ( '' !== $slug && ! term_exists( $slug, self::TAX ) ) {
				wp_insert_term( $info['label'], self::TAX, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * A deleted topic is forgotten, so a slot that still uses it can bring it
	 * back on its next render rather than pointing at nothing.
	 */
	public static function forget( $term_id, $tt_id, $deleted ): void {
		$seen = get_option( self::SEEN_OPTION, array() );
		if ( $deleted instanceof WP_Term && is_array( $seen ) && isset( $seen[ $deleted->slug ] ) ) {
			unset( $seen[ $deleted->slug ] );
			update_option( self::SEEN_OPTION, $seen, true );
		}
		Scout_Reviews_Query::flush();
	}

	/**
	 * Called by a rendering slot whose topic is unknown: remember it so it
	 * shows up as a topic in the admin.
	 */
	public static function remember( string $slug, string $label ): void {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return;
		}
		// Known slots cost nothing: one autoloaded option read, no term query.
		$seen = get_option( self::SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( isset( $seen[ $slug ] ) ) {
			return;
		}
		// The theme's declared name wins over the page title.
		$declared = (array) apply_filters( 'scout_reviews_slots', array() );
		if ( ! empty( $declared[ $slug ]['label'] ) ) {
			$label = (string) $declared[ $slug ]['label'];
		}
		if ( ! term_exists( $slug, self::TAX ) ) {
			wp_insert_term( $label ? $label : $slug, self::TAX, array( 'slug' => $slug ) );
		}
		$seen[ $slug ] = $label ? $label : $slug;
		update_option( self::SEEN_OPTION, $seen, true );
	}

	/**
	 * Keywords for one topic: the admin's list when set, else the theme's,
	 * plus the words of the topic's own name.
	 *
	 * @return string[]
	 */
	public static function keywords( WP_Term $term ): array {
		$custom = trim( (string) get_term_meta( $term->term_id, self::META_KEYWORD, true ) );
		if ( '' !== $custom ) {
			$words = array_map( 'trim', explode( ',', $custom ) );
		} else {
			$slots = self::slots();
			$words = $slots[ $term->slug ]['keywords'] ?? array();
		}
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $term->name ) as $part ) {
			if ( mb_strlen( $part ) > 3 ) {
				$words[] = $part;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'mb_strtolower', $words ) ) ) );
	}

	/**
	 * Topics whose keywords appear in a review, best match first.
	 *
	 * @return int[] Term IDs.
	 */
	public static function suggest( string $text ): array {
		$text   = mb_strtolower( $text );
		$scores = array();
		foreach ( self::all() as $term ) {
			$score = 0;
			foreach ( self::keywords( $term ) as $word ) {
				$score += preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/u', $text );
			}
			if ( $score > 0 ) {
				$scores[ $term->term_id ] = $score;
			}
		}
		arsort( $scores );
		return array_map( 'intval', array_keys( $scores ) );
	}

	/**
	 * @return WP_Term[]
	 */
	public static function all(): array {
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name' ) );
		return is_array( $terms ) ? $terms : array();
	}

	public static function maybe_flush( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( self::TAX === $taxonomy ) {
			Scout_Reviews_Query::flush();
		}
	}

	/* ---- Keywords field on Reviews > Topics ---- */

	public static function add_field(): void {
		?>
		<div class="form-field">
			<label for="scout-keywords"><?php esc_html_e( 'Keywords', 'scout-reviews' ); ?></label>
			<input type="text" id="scout-keywords" name="scout_keywords" value="" />
			<p><?php esc_html_e( 'Comma separated. When a new review mentions one of these, the inbox suggests this topic.', 'scout-reviews' ); ?></p>
		</div>
		<?php
	}

	public static function edit_field( WP_Term $term ): void {
		$value = (string) get_term_meta( $term->term_id, self::META_KEYWORD, true );
		$slots = self::slots();
		$theme = implode( ', ', $slots[ $term->slug ]['keywords'] ?? array() );
		?>
		<tr class="form-field">
			<th scope="row"><label for="scout-keywords"><?php esc_html_e( 'Keywords', 'scout-reviews' ); ?></label></th>
			<td>
				<input type="text" id="scout-keywords" name="scout_keywords" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $theme ); ?>" />
				<p class="description"><?php esc_html_e( 'Comma separated. When a new review mentions one of these, the inbox suggests this topic. Leave empty to use the theme\'s list (shown in gray).', 'scout-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_field( int $term_id ): void {
		if ( ! isset( $_POST['scout_keywords'] ) || ! current_user_can( 'manage_categories' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- core verified the term form nonce.
			return;
		}
		$words = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', wp_unslash( (string) $_POST['scout_keywords'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		update_term_meta( $term_id, self::META_KEYWORD, implode( ', ', $words ) );
	}
}
