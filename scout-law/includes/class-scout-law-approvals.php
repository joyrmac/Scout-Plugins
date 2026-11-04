<?php
/**
 * Approval before publishing: chosen kinds of content (blog posts and team
 * bios by default, pages when the firm wants) cannot go live until someone
 * records the attorney who approved it. The name, the date and the exact
 * saved version (a revision) are kept as the firm's advertising record.
 *
 * Publishing without an approval saves the item as Pending review instead,
 * from the block editor, the classic editor, quick edit or code. A live item
 * edited after its approval stays live and is listed as "Edited since
 * approval" in the Inbox, so the firm can look at the change.
 *
 * Case results keep their own approval in Scout Case Results.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Approvals {

	const OPTION = 'scout_law_approvals';
	const LOG    = 'scout_law_content_log';
	const BY     = '_scout_law_approved_by';
	const AT     = '_scout_law_approved_at';
	const HASH   = '_scout_law_approved_hash';
	const NONCE  = 'scout_law_approval';

	/** @var array<int,bool> Items the current request is allowed to publish. */
	private static $cleared = array();

	/** @var bool A publish was held back on this request. */
	private static $held = false;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_classic' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'scout_hub_inbox', array( __CLASS__, 'inbox' ) );
		foreach ( self::types() as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( __CLASS__, 'rest_gate' ), 10, 2 );
			add_action( 'rest_after_insert_' . $type, array( __CLASS__, 'rest_record' ), 10, 2 );
			add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	/* ---- Settings ----------------------------------------------------- */

	/** Kinds of content that can need approval: public types with an edit screen, minus media and case results. */
	public static function choices(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'scout_result' ), true ) ) {
				continue;
			}
			$out[ $type->name ] = (string) $type->labels->name;
		}
		return $out;
	}

	/** The kinds of content that need the firm's approval on this site. */
	public static function types(): array {
		$saved = get_option( self::OPTION, null );
		$types = is_array( $saved ) && isset( $saved['types'] ) ? (array) $saved['types'] : array( 'post', 'scout_team' );
		/** Filter the post types that need the firm's approval before publishing. */
		return array_values( array_map( 'strval', (array) apply_filters( 'scout_law_approval_types', $types ) ) );
	}

	public static function gated( string $type ): bool {
		return in_array( $type, self::types(), true );
	}

	/* ---- The approval -------------------------------------------------- */

	/** A fingerprint of what the firm approved: the title and the content. */
	public static function fingerprint( string $title, string $content ): string {
		return md5( trim( $title ) . "\n" . trim( (string) preg_replace( '/\s+/', ' ', $content ) ) );
	}

	/**
	 * Where an item stands with the firm.
	 *
	 * @return string approved | edited | waiting
	 */
	public static function status( WP_Post $post ): string {
		$by   = (string) get_post_meta( $post->ID, self::BY, true );
		$hash = (string) get_post_meta( $post->ID, self::HASH, true );
		if ( '' === $by || '' === $hash ) {
			return 'waiting';
		}
		return hash_equals( $hash, self::fingerprint( $post->post_title, $post->post_content ) ) ? 'approved' : 'edited';
	}

	/** Record the approval of an item as it is saved now, and keep it in the log. */
	public static function record( int $post_id, string $by ): void {
		$post = get_post( $post_id );
		$by   = trim( $by );
		if ( ! $post || '' === $by ) {
			return;
		}
		$now  = time();
		$hash = self::fingerprint( $post->post_title, $post->post_content );
		update_post_meta( $post_id, self::BY, $by );
		update_post_meta( $post_id, self::AT, $now );
		update_post_meta( $post_id, self::HASH, $hash );

		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		$log       = get_option( self::LOG, array() );
		$log       = is_array( $log ) ? $log : array();
		$log[]     = array(
			'post'     => $post_id,
			'type'     => $post->post_type,
			'title'    => $post->post_title,
			'by'       => $by,
			'at'       => $now,
			'user'     => get_current_user_id(),
			'hash'     => $hash,
			'revision' => $revisions ? (int) array_key_first( $revisions ) : 0,
		);
		update_option( self::LOG, $log, false );
		/** The firm's approval of an item was recorded. The Site log can listen. */
		do_action( 'scout_law_content_approved', $post_id, $by );
	}

	/** Every approval on record, newest first. */
	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/* ---- Meta for the block editor ------------------------------------- */

	public static function register_meta(): void {
		foreach ( self::types() as $type ) {
			foreach ( array( self::BY => 'string', self::AT => 'integer' ) as $key => $kind ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'          => $kind,
						'single'        => true,
						'show_in_rest'  => true,
						'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
					)
				);
			}
		}
	}

	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::gated( (string) $screen->post_type ) ) {
			return;
		}
		wp_enqueue_script( 'scout-law-approvals', SCOUT_LAW_URL . 'assets/approvals-editor.js', array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ), SCOUT_LAW_VERSION, true );
	}

	/* ---- The gate ----------------------------------------------------- */

	/**
	 * The approver named in a block editor save: the name sent, or the saved
	 * name when the editor reset the date to 0 (the same attorney approving again).
	 */
	private static function requested_by( $request, int $id ): string {
		$meta = (array) ( $request['meta'] ?? array() );
		$by   = trim( sanitize_text_field( (string) ( $meta[ self::BY ] ?? '' ) ) );
		if ( '' === $by && $id && array_key_exists( self::AT, $meta ) && 0 === (int) $meta[ self::AT ] ) {
			$by = trim( (string) get_post_meta( $id, self::BY, true ) );
		}
		return $by;
	}

	/** Block editor (REST): decide with the values in this save, before WordPress writes the post. */
	public static function rest_gate( $prepared, $request ) {
		if ( ! in_array( $prepared->post_status ?? '', array( 'publish', 'future' ), true ) ) {
			return $prepared;
		}
		$id   = (int) ( $prepared->ID ?? 0 );
		$by   = self::requested_by( $request, $id );
		$post = $id ? get_post( $id ) : null;
		$was  = $post ? $post->post_status : 'new';
		$ok   = '' !== $by || ( $post && 'publish' === $was ) || ( $post && 'approved' === self::status( $post ) );
		if ( $ok ) {
			if ( $id ) {
				self::$cleared[ $id ] = true;
			}
			return $prepared;
		}
		$prepared->post_status = 'pending';
		return $prepared;
	}

	/** Block editor (REST): keep the record once the save is written. */
	public static function rest_record( $post, $request ): void {
		$meta = (array) ( $request['meta'] ?? array() );
		$by   = self::requested_by( $request, (int) $post->ID );
		$asks = array_key_exists( self::BY, $meta ) || ( array_key_exists( self::AT, $meta ) && 0 === (int) $meta[ self::AT ] );
		if ( '' !== $by && $asks ) {
			self::record( (int) $post->ID, $by ); // Every tick is an approval on record, with this exact version.
		}
	}

	/** The values the classic editor's approval box sent, or null when it was not on the form. */
	private static function posted(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in save_classic(); gate() only reads.
		if ( ! isset( $_POST['scout_law_approval_box'] ) ) {
			return null;
		}
		return array(
			'ok' => ! empty( $_POST['scout_law_approve'] ),
			'by' => sanitize_text_field( wp_unslash( (string) ( $_POST['scout_law_approved_by'] ?? '' ) ) ),
		);
		// phpcs:enable
	}

	/** Classic editor, quick edit and code: hold a publish that has no approval. */
	public static function gate( array $data, array $postarr ): array {
		$type = (string) ( $data['post_type'] ?? '' );
		if ( ! self::gated( $type ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id && ! empty( self::$cleared[ $id ] ) ) {
			return $data;
		}
		$post = $id ? get_post( $id ) : null;
		if ( $post && 'publish' === $post->post_status ) {
			return $data; // Already live: an edit stays live and shows as "Edited since approval".
		}
		$posted = self::posted();
		$ok     = null !== $posted ? ( $posted['ok'] && '' !== $posted['by'] ) : ( $post && 'approved' === self::status( $post ) );
		if ( ! $ok ) {
			$data['post_status'] = 'pending';
			self::$held          = true;
		}
		return $data;
	}

	/** Classic editor: keep the approval the box sent. */
	public static function save_classic( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! self::gated( $post->post_type ) ) {
			return;
		}
		$posted = self::posted();
		if ( null === $posted || ! isset( $_POST['scout_law_approval_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['scout_law_approval_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( $posted['ok'] && '' !== $posted['by'] && 'approved' !== self::status( $post ) ) {
			self::record( $post_id, $posted['by'] );
		}
	}

	/** Last line of defence for code paths that skip the filters above. */
	public static function guard_transition( string $new, string $old, WP_Post $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || ! self::gated( $post->post_type ) || ! empty( self::$cleared[ $post->ID ] ) || null !== self::posted() ) {
			return;
		}
		if ( 'approved' === self::status( $post ) || '' !== (string) get_post_meta( $post->ID, self::BY, true ) ) {
			return;
		}
		remove_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10 );
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'pending',
			)
		);
		add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
	}

	/* ---- Classic editor box, notices, list column ---------------------- */

	public static function box( string $type, $post ): void {
		if ( ! self::gated( $type ) ) {
			return;
		}
		add_meta_box( 'scout-law-approval', __( 'Firm approval', 'scout-law' ), array( __CLASS__, 'render_box' ), $type, 'side', 'high', array( '__back_compat_meta_box' => true ) );
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'scout_law_approval_nonce' );
		echo '<input type="hidden" name="scout_law_approval_box" value="1">';
		echo '<p>' . esc_html( self::status_text( $post ) ) . '</p>';
		if ( 'approved' !== self::status( $post ) ) {
			echo '<p><label for="scout-law-by">' . esc_html__( 'Attorney who approved it', 'scout-law' ) . '</label><br><input type="text" id="scout-law-by" name="scout_law_approved_by" class="widefat"></p>';
			echo '<p><label><input type="checkbox" name="scout_law_approve" value="1"> ' . esc_html__( 'The firm approved this version', 'scout-law' ) . '</label></p>';
		}
	}

	/** One plain sentence for an item's status. */
	public static function status_text( WP_Post $post ): string {
		$status = self::status( $post );
		if ( 'approved' === $status ) {
			/* translators: 1: approver's name, 2: date */
			return sprintf( __( 'Approved by %1$s on %2$s.', 'scout-law' ), (string) get_post_meta( $post->ID, self::BY, true ), wp_date( get_option( 'date_format' ), (int) get_post_meta( $post->ID, self::AT, true ) ) );
		}
		if ( 'edited' === $status ) {
			return __( 'Edited since the firm approved it. It stays live; record a new approval when the firm has looked at the change.', 'scout-law' );
		}
		return __( 'Needs the firm\'s approval before it can be published.', 'scout-law' );
	}

	public static function redirect( string $location, int $post_id ): string {
		return self::$held && self::gated( (string) get_post_type( $post_id ) ) ? add_query_arg( 'scout_law_held', '1', $location ) : $location;
	}

	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only.
		if ( empty( $_GET['scout_law_held'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Saved as Pending review. It goes live once the firm\'s approval is recorded in the Firm approval box.', 'scout-law' ) . '</p></div>';
	}

	public static function columns( array $cols ): array {
		$cols['scout_law'] = __( 'Firm approval', 'scout-law' );
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'scout_law' !== $col ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$status = self::status( $post );
		$mark   = 'approved' === $status ? '&#10003; ' : '&#9670; ';
		$text   = array(
			'approved' => (string) get_post_meta( $post_id, self::BY, true ),
			'edited'   => __( 'Edited since approval', 'scout-law' ),
			'waiting'  => __( 'Needs approval', 'scout-law' ),
		);
		echo $mark . esc_html( $text[ $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mark is a fixed entity.
	}

	/* ---- What waits on the firm ---------------------------------------- */

	/**
	 * Items waiting on the firm: pending ones without an approval, and live
	 * ones edited since their approval.
	 *
	 * @return array{waiting:int[],edited:int[]}
	 */
	public static function queues(): array {
		$out = array( 'waiting' => array(), 'edited' => array() );
		if ( ! self::types() ) {
			return $out;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'pending', 'publish' ),
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$status = self::status( $post );
			if ( 'pending' === $post->post_status && 'approved' !== $status ) {
				$out['waiting'][] = $post->ID;
			} elseif ( 'publish' === $post->post_status && 'edited' === $status ) {
				$out['edited'][] = $post->ID;
			}
		}
		return $out;
	}

	public static function inbox( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		$q    = self::queues();
		if ( $q['waiting'] ) {
			$rows[] = array(
				'label' => __( 'Waiting for the firm\'s approval', 'scout-law' ),
				'count' => count( $q['waiting'] ),
				'url'   => Scout_Law_Approvals_Admin::url(),
				'note'  => __( 'Pages and posts that go live once an attorney approves them.', 'scout-law' ),
			);
		}
		if ( $q['edited'] ) {
			$rows[] = array(
				'label' => __( 'Edited since approval', 'scout-law' ),
				'count' => count( $q['edited'] ),
				'url'   => Scout_Law_Approvals_Admin::url(),
				'note'  => __( 'Live pages and posts changed after the firm approved them. They stay live until the firm looks.', 'scout-law' ),
			);
		}
		return $rows;
	}
}
