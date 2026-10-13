<?php
/**
 * The edit screen for one case result: the fields, the live preview (which
 * reads the same headline field the site shows), the firm's approval, and the
 * rule that keeps an unapproved result from going live.
 *
 * @package Scout_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Results_Editor {

	const NONCE = 'scout_results_save';

	public static function boot(): void {
		add_action( 'add_meta_boxes_' . Scout_Results::POST_TYPE, array( __CLASS__, 'boxes' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 20, 2 );
		add_action( 'save_post_' . Scout_Results::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . Scout_Results::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Scout_Results::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
	}

	/** Set when this request held a result back, so the redirect can say why. */
	private static bool $held = false;

	public static function title_placeholder( string $text, WP_Post $post ): string {
		return Scout_Results::POST_TYPE === $post->post_type ? __( 'Headline, for example "Settlement for a client hurt in a rear-end crash"', 'scout-results' ) : $text;
	}

	public static function after_title( WP_Post $post ): void {
		if ( Scout_Results::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<p class="sr-title-help">' . esc_html__( 'The headline above is what visitors see on the site and in the preview. Keep it plain and factual.', 'scout-results' ) . '</p>';
	}

	public static function boxes(): void {
		add_meta_box( 'scout-result-details', __( 'The result', 'scout-results' ), array( __CLASS__, 'box_details' ), Scout_Results::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'scout-result-approval', __( 'Have the firm approve it', 'scout-results' ), array( __CLASS__, 'box_approval' ), Scout_Results::POST_TYPE, 'side', 'high' );
		add_meta_box( 'scout-result-preview', __( 'Preview on the site', 'scout-results' ), array( __CLASS__, 'box_preview' ), Scout_Results::POST_TYPE, 'normal', 'default' );
	}

	public static function box_details( WP_Post $post ): void {
		$r = Scout_Results::get( $post->ID );
		wp_nonce_field( self::NONCE, '_scout_results_nonce' );
		$areas = get_terms(
			array(
				'taxonomy'   => Scout_Results::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$areas = is_wp_error( $areas ) ? array() : $areas;
		?>
		<div class="sr-fields">
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-area"><?php esc_html_e( 'Practice area', 'scout-results' ); ?></label>
					<select id="sr-area" name="scout_result[area]">
						<option value="0"><?php esc_html_e( 'Choose one', 'scout-results' ); ?></option>
						<?php foreach ( $areas as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $r['area_id'], (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="sr-help">
						<label for="sr-new-area"><?php esc_html_e( 'Or add a new one', 'scout-results' ); ?></label>
						<input id="sr-new-area" type="text" name="scout_result[new_area]" value="" autocomplete="off">
					</span>
				</p>
				<p class="sr-field">
					<label for="sr-type"><?php esc_html_e( 'Result type', 'scout-results' ); ?></label>
					<select id="sr-type" name="scout_result[type]">
						<?php foreach ( Scout_Results::types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-amount"><?php esc_html_e( 'Amount (optional)', 'scout-results' ); ?></label>
					<input id="sr-amount" type="text" name="scout_result[amount]" value="<?php echo esc_attr( $r['amount'] ); ?>" autocomplete="off">
					<span class="sr-help"><?php esc_html_e( 'Leave blank to show the result without a figure. Only enter an amount the firm can document.', 'scout-results' ); ?></span>
				</p>
				<p class="sr-field">
					<label for="sr-date"><?php esc_html_e( 'Date of the result', 'scout-results' ); ?></label>
					<input id="sr-date" type="date" name="scout_result[date]" value="<?php echo esc_attr( $r['date'] ); ?>">
					<span class="sr-help"><?php esc_html_e( 'Shown as month and year.', 'scout-results' ); ?></span>
				</p>
			</div>
			<p class="sr-field">
				<label for="sr-description"><?php esc_html_e( 'Short description', 'scout-results' ); ?></label>
				<textarea id="sr-description" name="scout_result[description]" rows="4" maxlength="600"><?php echo esc_textarea( $r['description'] ); ?></textarea>
				<span class="sr-help"><?php esc_html_e( 'Two or three sentences on what happened. Leave out client names unless the client has agreed in writing.', 'scout-results' ); ?></span>
			</p>
			<div class="sr-flags" id="sr-flags" role="status" aria-live="polite" hidden>
				<p class="sr-flags-title"></p>
				<ul></ul>
			</div>
		</div>
		<?php
	}

	public static function box_approval( WP_Post $post ): void {
		$r = Scout_Results::get( $post->ID );
		?>
		<div class="sr-approval">
			<p class="sr-check">
				<input id="sr-approved" type="checkbox" name="scout_result[approved]" value="1" <?php checked( $r['approved'] ); ?>>
				<label for="sr-approved"><?php esc_html_e( 'Approved by the firm', 'scout-results' ); ?></label>
			</p>
			<p class="sr-field">
				<label for="sr-approver"><?php esc_html_e( 'Approver name', 'scout-results' ); ?></label>
				<input id="sr-approver" type="text" name="scout_result[approver]" value="<?php echo esc_attr( $r['approver'] ); ?>" autocomplete="name">
			</p>
			<p class="sr-field">
				<label for="sr-approved-on"><?php esc_html_e( 'Approval date', 'scout-results' ); ?></label>
				<input id="sr-approved-on" type="date" name="scout_result[approved_on]" value="<?php echo esc_attr( $r['approved_on'] ); ?>">
			</p>
			<p class="sr-help"><?php esc_html_e( 'A result goes live once the firm has approved the exact wording. Until then it saves for review.', 'scout-results' ); ?></p>
		</div>
		<?php
	}

	public static function box_preview( WP_Post $post ): void {
		$r = Scout_Results::get( $post->ID );
		?>
		<div class="sr-preview" id="sr-preview">
			<p class="sr-preview-note"><?php esc_html_e( 'This is how the result reads in the grid. The disclaimer is added to every result on its own.', 'scout-results' ); ?></p>
			<?php
			// The same card the site prints, filled from the saved values; the
			// script then keeps it in step with the fields as you type.
			echo Scout_Results_Render::card( $r, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card().
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	/** The posted fields, sanitized, or null when this request did not come from our form. */
	private static function posted( int $post_id ): ?array {
		if ( ! isset( $_POST['_scout_results_nonce'], $_POST['scout_result'] ) ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_scout_results_nonce'] ) ), self::NONCE ) ) {
			return null;
		}
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		$in   = (array) wp_unslash( $_POST['scout_result'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$type = sanitize_key( (string) ( $in['type'] ?? '' ) );
		$date = sanitize_text_field( (string) ( $in['date'] ?? '' ) );
		$on   = sanitize_text_field( (string) ( $in['approved_on'] ?? '' ) );
		return array(
			'area'        => absint( $in['area'] ?? 0 ),
			'new_area'    => sanitize_text_field( (string) ( $in['new_area'] ?? '' ) ),
			'type'        => isset( Scout_Results::types()[ $type ] ) ? $type : 'other',
			'amount'      => sanitize_text_field( (string) ( $in['amount'] ?? '' ) ),
			'date'        => Scout_Results::valid_date( $date ) ? $date : '',
			'description' => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ),
			'approved'    => ! empty( $in['approved'] ),
			'approver'    => sanitize_text_field( (string) ( $in['approver'] ?? '' ) ),
			'approved_on' => Scout_Results::valid_date( $on ) ? $on : '',
		);
	}

	/**
	 * Keep an unapproved result from going live, however it is saved: the
	 * edit screen, quick edit, bulk edit or code. It is saved as Pending review.
	 */
	public static function gate( array $data, array $postarr ): array {
		if ( Scout_Results::POST_TYPE !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$posted = self::posted( $id );
		if ( null !== $posted ) {
			$ok = Scout_Results::is_approved( $posted['approved'], $posted['approver'] );
		} else {
			$ok = $id && Scout_Results::is_approved( '1' === (string) get_post_meta( $id, Scout_Results::META['approved'], true ), (string) get_post_meta( $id, Scout_Results::META['approver'], true ) );
		}
		if ( ! $ok ) {
			$data['post_status'] = 'pending';
			self::$held          = true;
		}
		return $data;
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$p = self::posted( $post_id );
		if ( null === $p ) {
			return;
		}
		$m = Scout_Results::META;
		update_post_meta( $post_id, $m['type'], $p['type'] );
		update_post_meta( $post_id, $m['amount'], $p['amount'] );
		update_post_meta( $post_id, $m['date'], $p['date'] );
		update_post_meta( $post_id, $m['description'], $p['description'] );
		update_post_meta( $post_id, $m['approver'], $p['approver'] );
		if ( $p['approved'] ) {
			update_post_meta( $post_id, $m['approved'], '1' );
			// Fill in today when the approval is ticked without a date.
			update_post_meta( $post_id, $m['approved_on'], '' !== $p['approved_on'] ? $p['approved_on'] : current_time( 'Y-m-d' ) );
		} else {
			update_post_meta( $post_id, $m['approved'], '0' );
			update_post_meta( $post_id, $m['approved_on'], $p['approved_on'] );
		}

		$term_id = $p['area'];
		if ( '' !== $p['new_area'] && current_user_can( 'manage_categories' ) ) {
			$existing = term_exists( $p['new_area'], Scout_Results::TAXONOMY );
			if ( $existing ) {
				$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			} else {
				$made    = wp_insert_term( $p['new_area'], Scout_Results::TAXONOMY );
				$term_id = is_wp_error( $made ) ? $term_id : (int) $made['term_id'];
			}
		}
		wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), Scout_Results::TAXONOMY );
	}

	/**
	 * Last line of defence for code paths that skip wp_insert_post_data, such
	 * as a scheduled result whose approval was removed before its date.
	 */
	public static function guard_transition( string $new, string $old, WP_Post $post ): void {
		if ( 'publish' !== $new || Scout_Results::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( null !== self::posted( (int) $post->ID ) ) {
			return; // The edit screen; gate() already decided with the posted values.
		}
		$ok = Scout_Results::is_approved( '1' === (string) get_post_meta( $post->ID, Scout_Results::META['approved'], true ), (string) get_post_meta( $post->ID, Scout_Results::META['approver'], true ) );
		if ( ! $ok ) {
			remove_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10 );
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'pending',
				)
			);
			add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
		}
	}

	public static function redirect( string $location, int $post_id ): string {
		if ( Scout_Results::POST_TYPE !== get_post_type( $post_id ) ) {
			return $location;
		}
		if ( self::$held ) {
			$location = add_query_arg( 'scout_result_held', '1', $location );
		}
		$r     = Scout_Results::get( $post_id );
		$flags = Scout_Results::find_flagged( $r['headline'] . ' ' . $r['description'] );
		if ( $flags ) {
			$location = add_query_arg( 'scout_result_words', count( $flags ), $location );
		}
		return $location;
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Scout_Results::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['scout_result_held'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Saved for review. This result is not on the site yet.', 'scout-results' ) . '</strong> ' . esc_html__( 'It can go live once the firm approves it: tick "Approved by the firm", add the approver\'s name, then publish.', 'scout-results' ) . '</p></div>';
		}
		if ( ! empty( $_GET['scout_result_words'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Some wording may read as a promise or a ranking. The suggestions under the description show a plainer way to say it, and the firm makes the final call.', 'scout-results' ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function messages( array $messages ): array {
		$messages[ Scout_Results::POST_TYPE ] = array(
			1  => __( 'Case result updated.', 'scout-results' ),
			4  => __( 'Case result updated.', 'scout-results' ),
			6  => __( 'Case result published. It shows in the results grid with the disclaimer.', 'scout-results' ),
			7  => __( 'Case result saved.', 'scout-results' ),
			8  => __( 'Case result saved for review.', 'scout-results' ),
			9  => __( 'Case result scheduled.', 'scout-results' ),
			10 => __( 'Draft saved.', 'scout-results' ),
		);
		return $messages;
	}

	/* ------------------------------------------------------------------ */
	/* The list of results                                                 */
	/* ------------------------------------------------------------------ */

	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out[ $key ] = __( 'Headline', 'scout-results' );
				$out['sr_type'] = __( 'Type', 'scout-results' );
				continue;
			}
			if ( 'date' === $key ) {
				$out['sr_approval'] = __( 'Firm approval', 'scout-results' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		$r = Scout_Results::get( $post_id );
		if ( 'sr_type' === $col ) {
			echo esc_html( $r['type_label'] );
		} elseif ( 'sr_approval' === $col ) {
			if ( Scout_Results::is_approved( $r['approved'], $r['approver'] ) ) {
				/* translators: 1: approver name, 2: date */
				echo esc_html( sprintf( __( 'Approved by %1$s, %2$s', 'scout-results' ), $r['approver'], $r['approved_on'] ? date_i18n( get_option( 'date_format' ), strtotime( $r['approved_on'] . ' 12:00:00' ) ) : '' ) );
			} else {
				echo '<strong>' . esc_html__( 'Waiting for the firm', 'scout-results' ) . '</strong>';
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Scout_Results::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'scout-results', SCOUT_RESULTS_URL . 'assets/scout-results.css', array(), SCOUT_RESULTS_VERSION );
		wp_enqueue_style( 'scout-results-editor', SCOUT_RESULTS_URL . 'assets/results-editor.css', array( 'scout-results' ), SCOUT_RESULTS_VERSION );
		wp_enqueue_script( 'scout-results-editor', SCOUT_RESULTS_URL . 'assets/results-editor.js', array(), SCOUT_RESULTS_VERSION, true );
		wp_localize_script(
			'scout-results-editor',
			'scoutResultsEditor',
			array(
				'words'      => Scout_Results::flagged_words(),
				'types'      => Scout_Results::types(),
				'oneWord'    => __( 'One word may need a change before the firm approves this', 'scout-results' ),
				/* translators: %d: number of words */
				'manyWords'  => __( '%d words may need a change before the firm approves this', 'scout-results' ),
				'noHeadline' => __( 'Your headline shows here', 'scout-results' ),
			)
		);
	}
}
