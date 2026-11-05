<?php
/**
 * Add a person: a short walkthrough inside the People section, in three
 * steps on one screen. Who they are, then a photo and short bio, then where
 * they show. It saves through the edit screen's own code
 * (Scout_Team_Editor::store()), so the profile, the groups and the defaults
 * are exactly what the full editor would save. Then it opens the full edit
 * screen for the rest.
 *
 * The screen is the People list's own address with &add=1, so it sits in
 * the People section with no new menu entry. Without JavaScript all three
 * steps show at once and the form still posts everything; the photo picker
 * falls back to a plain upload.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Add {

	/** The admin-post action and its nonce. */
	const ACTION = 'scout_team_add';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/** The walkthrough's address. */
	public static function url(): string {
		return Scout_Team_Admin::people_url( array( 'add' => 1 ) );
	}

	/** Is the People screen showing the walkthrough? */
	public static function is_open(): bool {
		return ! empty( $_GET['add'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * Where a new person shows before anyone picks: the plugin's own defaults,
	 * with no login yet. From the Cards screen (&card=1) the card starts on too.
	 *
	 * @param bool $card Start with Card switched on.
	 */
	public static function form_shows( bool $card = false ): array {
		$shows = Scout_Team::default_shows( 0 );
		if ( $card ) {
			$shows['card'] = true;
		}
		return $shows;
	}

	/** Did the Cards screen open the walkthrough? */
	public static function wants_card(): bool {
		return ! empty( $_GET['card'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * The walkthrough's fields as the edit screen would send them. The one
	 * difference: picking "Writes posts as" turns the byline on. The script
	 * ticks the box itself; without the script a hidden field asks for it here.
	 *
	 * @param array $in          Raw scout_team[] fields.
	 * @param bool  $byline_auto Turn the byline on when a login is picked.
	 */
	public static function raw( array $in, bool $byline_auto ): array {
		if ( $byline_auto && absint( $in['user'] ?? 0 ) > 0 && isset( $in['shows'] ) && is_array( $in['shows'] ) ) {
			$in['shows']['byline'] = '1';
		}
		return $in;
	}

	/* ---------- the screen ---------- */

	/** The step names, in order. */
	public static function steps(): array {
		return array(
			1 => __( 'Who they are', 'scout-team' ),
			2 => __( 'Photo & short bio', 'scout-team' ),
			3 => __( 'Where they show', 'scout-team' ),
		);
	}

	public static function render(): void {
		$type = get_post_type_object( Scout_Team::POST_TYPE );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			return;
		}
		$full  = admin_url( 'post-new.php?post_type=' . Scout_Team::POST_TYPE );
		$steps = self::steps();
		Scout_Admin_UI::open(
			__( 'Add a person', 'scout-team' ),
			__( 'Three short steps. Credentials and links can wait for the full editor.', 'scout-team' ),
			'',
			Scout_Team_Admin::PEOPLE
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['missing'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Add their name, then save again.', 'scout-team' ) . '</p></div>';
		}
		?>
		<form class="st-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-st-add
			data-st-media-title="<?php esc_attr_e( 'Choose a photo', 'scout-team' ); ?>"
			data-st-media-button="<?php esc_attr_e( 'Use this photo', 'scout-team' ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="st_byline_auto" value="1" data-st-byline-auto>
			<?php wp_nonce_field( self::ACTION, '_st_add' ); ?>

			<nav class="st-progress-nav" aria-label="<?php esc_attr_e( 'Steps', 'scout-team' ); ?>">
				<ol class="st-progress">
					<?php foreach ( $steps as $n => $label ) : ?>
						<li class="st-progress-step<?php echo 1 === $n ? ' is-current' : ''; ?>" data-st-progress="<?php echo esc_attr( (string) $n ); ?>"<?php echo 1 === $n ? ' aria-current="step"' : ''; ?>>
							<a href="#st-step-<?php echo esc_attr( (string) $n ); ?>" data-st-goto="<?php echo esc_attr( (string) $n ); ?>"><span class="st-progress-num" aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span> <span class="st-progress-label"><?php echo esc_html( $label ); ?></span></a>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>

			<section class="sh-box st-add-step" id="st-step-1" data-st-step="1" aria-labelledby="st-step-1-title">
				<h2 class="sh-title" id="st-step-1-title" tabindex="-1"><?php echo esc_html( '1. ' . $steps[1] ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="st-name"><?php esc_html_e( 'Full name', 'scout-team' ); ?></label></th>
						<td><input id="st-name" type="text" name="st_name" class="regular-text" required autocomplete="off" data-st-name></td>
					</tr>
					<tr>
						<th scope="row"><label for="st-role"><?php esc_html_e( 'Title or role', 'scout-team' ); ?></label></th>
						<td><input id="st-role" type="text" name="scout_team[role]" class="regular-text" placeholder="<?php esc_attr_e( 'Partner', 'scout-team' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Groups', 'scout-team' ); ?></th>
						<td class="scout-team-form">
							<?php Scout_Team_Editor::group_fields( true, array() ); ?>
							<p class="description"><?php esc_html_e( 'The team page shows the Team group.', 'scout-team' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="sh-actions st-add-go" hidden data-st-js><button type="button" class="button button-primary" data-st-next="2"><?php esc_html_e( 'Next', 'scout-team' ); ?></button></p>
			</section>

			<section class="sh-box st-add-step" id="st-step-2" data-st-step="2" aria-labelledby="st-step-2-title">
				<h2 class="sh-title" id="st-step-2-title" tabindex="-1"><?php echo esc_html( '2. ' . $steps[2] ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Photo', 'scout-team' ); ?></th>
						<td>
							<input type="hidden" name="st_photo" value="" data-st-photo-id>
							<div class="st-add-photo" data-st-photo-preview hidden></div>
							<p hidden data-st-js>
								<button type="button" class="button" data-st-photo-pick><?php esc_html_e( 'Choose a photo', 'scout-team' ); ?></button>
								<button type="button" class="button sh-quiet" data-st-photo-clear hidden><?php esc_html_e( 'Remove photo', 'scout-team' ); ?></button>
							</p>
							<noscript>
								<?php if ( current_user_can( 'upload_files' ) ) : ?>
									<p><label for="st-photo-file"><?php esc_html_e( 'Upload a photo', 'scout-team' ); ?></label><br>
									<input id="st-photo-file" type="file" name="st_photo_file" accept="image/jpeg,image/png,image/webp"></p>
								<?php endif; ?>
							</noscript>
							<p class="description"><?php esc_html_e( 'A real photo of them, never stock.', 'scout-team' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="st-photo-alt"><?php esc_html_e( 'Photo alt text', 'scout-team' ); ?></label></th>
						<td><input id="st-photo-alt" type="text" name="st_photo_alt" class="regular-text" data-st-photo-alt>
							<p class="description"><?php esc_html_e( 'What the photo shows, for people who can’t see it. Empty uses their name.', 'scout-team' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="st-bio"><?php esc_html_e( 'Short bio', 'scout-team' ); ?></label></th>
						<td><textarea id="st-bio" name="scout_team[bio]" rows="4" class="large-text"></textarea>
							<p class="description"><?php esc_html_e( 'Two or three plain sentences. Shows on the team grid and at the top of their page.', 'scout-team' ); ?></p></td>
					</tr>
				</table>
				<p class="sh-actions st-add-go" hidden data-st-js>
					<button type="button" class="button" data-st-next="1"><?php esc_html_e( 'Back', 'scout-team' ); ?></button>
					<button type="button" class="button button-primary" data-st-next="3"><?php esc_html_e( 'Next', 'scout-team' ); ?></button>
				</p>
			</section>

			<section class="sh-box st-add-step" id="st-step-3" data-st-step="3" aria-labelledby="st-step-3-title">
				<h2 class="sh-title" id="st-step-3-title" tabindex="-1"><?php echo esc_html( '3. ' . $steps[3] ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Shows on', 'scout-team' ); ?></th>
						<td class="scout-team-form st-shows"><?php Scout_Team_Editor::shows_fields( self::form_shows( self::wants_card() ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="st-user"><?php esc_html_e( 'Writes posts as', 'scout-team' ); ?></label></th>
						<td><?php Scout_Team_Editor::user_picker( 0 ); ?>
							<p class="description"><?php esc_html_e( 'Pick their login. Their posts then link to this profile, and the byline turns on.', 'scout-team' ); ?></p></td>
					</tr>
				</table>
				<p class="sh-actions st-add-finish">
					<button type="button" class="button" hidden data-st-js data-st-next="2"><?php esc_html_e( 'Back', 'scout-team' ); ?></button>
					<button type="submit" class="button" name="st_finish" value="draft"><?php esc_html_e( 'Save as draft', 'scout-team' ); ?></button>
					<button type="submit" class="button button-primary" name="st_finish" value="publish"><?php esc_html_e( 'Publish', 'scout-team' ); ?></button>
				</p>
				<p class="st-add-link">
					<button type="submit" class="button sh-quiet" name="st_finish" value="link"><?php esc_html_e( 'Save and send them a link', 'scout-team' ); ?></button>
					<span class="description"><?php esc_html_e( 'Saves a draft. They fill in the rest, and you check it before it goes live.', 'scout-team' ); ?></span>
				</p>
			</section>

			<p class="st-add-full"><a class="button sh-quiet" href="<?php echo esc_url( $full ); ?>"><?php esc_html_e( 'Use the full editor', 'scout-team' ); ?></a></p>
		</form>
		<?php
		Scout_Admin_UI::close();
	}

	/* ---------- saving ---------- */

	public static function save(): void {
		check_admin_referer( self::ACTION, '_st_add' );
		$type = get_post_type_object( Scout_Team::POST_TYPE );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			wp_die( esc_html__( 'You can’t add people on this site.', 'scout-team' ), 403 );
		}
		$name   = isset( $_POST['st_name'] ) ? sanitize_text_field( wp_unslash( $_POST['st_name'] ) ) : '';
		$finish = isset( $_POST['st_finish'] ) ? sanitize_key( wp_unslash( $_POST['st_finish'] ) ) : 'draft';
		if ( '' === $name ) {
			wp_safe_redirect( add_query_arg( 'missing', 1, self::url() ) );
			exit;
		}

		// A draft first, so the live page never exists without its profile.
		$id = wp_insert_post(
			array(
				'post_type'   => Scout_Team::POST_TYPE,
				'post_title'  => $name,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		$id = (int) $id;

		// The edit screen's own save: same cleaning, same meta value, same groups rules.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is sanitized in Scout_Team::sanitize_profile().
		$raw = isset( $_POST['scout_team'] ) ? (array) wp_unslash( $_POST['scout_team'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int in store().
		$groups = isset( $_POST['scout_team_groups_sent'] ) ? (array) ( $_POST['scout_team_groups'] ?? array() ) : null;
		Scout_Team_Editor::store( $id, self::raw( $raw, ! empty( $_POST['st_byline_auto'] ) ), $groups );

		self::photo( $id, $name );

		if ( 'publish' === $finish && current_user_can( $type->cap->publish_posts ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		$note = 'added';
		if ( 'link' === $finish ) {
			Scout_Team_Link::create( $id );
			$note = 'link';
		}
		wp_safe_redirect( add_query_arg( Scout_Team_Link::QUERY, $note, (string) get_edit_post_link( $id, 'raw' ) ) );
		exit;
	}

	/**
	 * The photo: one picked from the media library, or (without JavaScript)
	 * one uploaded with the form. Its alt text is what they typed, else their
	 * name, set only when the photo has none or they typed one.
	 *
	 * @param int    $id   Person post ID.
	 * @param string $name Their name.
	 */
	private static function photo( int $id, string $name ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in save().
		$photo = isset( $_POST['st_photo'] ) ? absint( $_POST['st_photo'] ) : 0;
		if ( ! $photo && ! empty( $_FILES['st_photo_file']['name'] ) && current_user_can( 'upload_files' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked here, then handed to WordPress's own upload checks.
			if ( '' === Scout_Team_Link::photo_problem( (array) $_FILES['st_photo_file'] ) ) {
				$made  = Scout_Team_Link::upload( 'st_photo_file', $id );
				$photo = is_wp_error( $made ) ? 0 : (int) $made;
			}
		}
		$typed = isset( $_POST['st_photo_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['st_photo_alt'] ) ) : '';
		// phpcs:enable
		if ( ! $photo || ! wp_attachment_is_image( $photo ) || ! current_user_can( 'read_post', $photo ) ) {
			return;
		}
		set_post_thumbnail( $id, $photo );
		$have = trim( (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ) );
		if ( current_user_can( 'edit_post', $photo ) && ( '' === $have || ( '' !== $typed && $typed !== $have ) ) ) {
			update_post_meta( $photo, '_wp_attachment_image_alt', '' !== $typed ? $typed : $name );
		}
	}
}
