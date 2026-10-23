<?php
/**
 * Presenters: apply on the site, get an account, and keep their own profile
 * current, with the owner approving every change before it goes live.
 *
 *   [scout_presenter_portal event="summit-2027"]
 *
 * Logged out, the shortcode shows the application: name, email, what they do,
 * bios, links, a photo, the classes they would like to teach, what they need in
 * the room, a phone number and a photo release. Sending it creates
 *
 *   - a WordPress user with the Presenter role (it can read, nothing more), who
 *     gets WordPress's own "set your password" email;
 *   - a speaker in Pending Review, linked to that user;
 *   - one draft session per class idea, on the event named in the shortcode.
 *
 * Logged in, a presenter sees where their application stands, their sessions,
 * and a form to update their profile. Updates wait in _scout_speaker_pending
 * until the owner approves them on the speaker's edit screen; nothing a
 * presenter types reaches the site on its own. Publishing a pending speaker is
 * the approval, and the presenter is emailed when it happens.
 *
 * Private details (phone, room needs, notes, photo release) are kept in
 * _scout_speaker_private. They show on the speaker's edit screen and nowhere
 * else: not on the site, not in schema, not in the import.
 *
 * Presenters never see the dashboard: wp-admin sends them to their profile
 * page and the toolbar is hidden for them.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Presenters {

	const ROLE    = 'scout_presenter';
	const USER    = '_scout_speaker_user';
	const PRIVATE = '_scout_speaker_private';
	const PENDING = '_scout_speaker_pending';
	const PAGE    = 'scout_events_portal_page';

	/** Seconds a person must spend on the form before it is accepted (bots fill it instantly). */
	const MIN_SECONDS = 4;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'role' ), 6 );
		add_shortcode( 'scout_presenter_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_scout_presenter_apply', array( __CLASS__, 'apply' ) );
		add_action( 'admin_post_scout_presenter_apply', array( __CLASS__, 'apply' ) );
		add_action( 'admin_post_scout_presenter_update', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_scout_presenter_review', array( __CLASS__, 'review' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'approved' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'add_meta_boxes_' . Scout_Events::SPEAKER, array( __CLASS__, 'box' ) );
		add_filter( 'scout_events_needs_you', array( __CLASS__, 'needs_you' ) );
	}

	/** The Presenter role: signed in to manage their own profile, nothing else. */
	public static function role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Presenter', 'scout-events' ), array( 'read' => true ) );
		}
	}

	public static function is_presenter( ?WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'edit_posts' );
	}

	/** The speaker linked to a user, or 0. */
	public static function speaker_for_user( int $user_id ): int {
		$found = get_posts(
			array(
				'post_type'      => Scout_Events::SPEAKER,
				'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
				'meta_key'       => self::USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row per presenter.
				'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	public static function private_info( int $speaker_id ): array {
		$raw = get_post_meta( $speaker_id, self::PRIVATE, true );
		return wp_parse_args(
			is_array( $raw ) ? $raw : array(),
			array(
				'phone'         => '',
				'pronouns'      => '',
				'needs'         => '',
				'notes'         => '',
				'photo_release' => 0,
				'applied'       => '',
			)
		);
	}

	/** The page that holds the portal, remembered the first time it renders. */
	public static function portal_url(): string {
		$id = (int) get_option( self::PAGE );
		$url = $id ? get_permalink( $id ) : '';
		return $url ? $url : home_url( '/' );
	}

	/* ---------- front end ---------- */

	public static function shortcode( $atts ): string {
		$a = shortcode_atts( array( 'event' => '' ), (array) $atts, 'scout_presenter_portal' );
		if ( is_singular() && (int) get_option( self::PAGE ) !== (int) get_the_ID() ) {
			update_option( self::PAGE, (int) get_the_ID(), false );
		}
		wp_enqueue_style( 'scout-events' );
		$out = '<div class="se-portal">' . self::notice();
		$user = wp_get_current_user();
		if ( $user->exists() && self::speaker_for_user( $user->ID ) ) {
			$out .= self::profile( self::speaker_for_user( $user->ID ) );
		} else {
			$out .= self::application_form( (string) $a['event'], $user );
		}
		return $out . '</div>';
	}

	private static function notice(): string {
		$msg   = isset( $_GET['presenter'] ) ? sanitize_key( wp_unslash( $_GET['presenter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'applied'  => __( 'Thank you. Your application is in. Check your email for a link to set your password, so you can come back and update your profile.', 'scout-events' ),
			'updated'  => __( 'Thank you. Your changes are saved and will appear once they are approved.', 'scout-events' ),
			'exists'   => __( 'That email already has an account. Log in to update your profile.', 'scout-events' ),
			'missing'  => __( 'Please fill in your name, email, short bio and at least one class.', 'scout-events' ),
			'consent'  => __( 'Please tick the photo release to continue, or contact us if you would rather not appear in photos.', 'scout-events' ),
			'tooquick' => __( 'That was very fast. Please check your answers and send the form again.', 'scout-events' ),
			'photo'    => __( 'The photo could not be uploaded. Use a JPG or PNG under 8 MB.', 'scout-events' ),
		);
		if ( ! isset( $texts[ $msg ] ) ) {
			return '';
		}
		$good = in_array( $msg, array( 'applied', 'updated' ), true );
		return '<p class="se-portal-note ' . ( $good ? 'is-good' : 'is-bad' ) . '" role="status">' . esc_html( $texts[ $msg ] ) . '</p>';
	}

	private static function field( string $name, string $label, string $value = '', string $type = 'text', bool $required = false, string $help = '' ): string {
		$id  = 'se-p-' . str_replace( array( '[', ']' ), array( '-', '' ), $name );
		$req = $required ? ' required' : '';
		$out = '<p class="se-p-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
		if ( 'textarea' === $type ) {
			$out .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			$out .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req . '>';
		}
		if ( '' !== $help ) {
			$out .= '<span class="se-p-help">' . esc_html( $help ) . '</span>';
		}
		return $out . '</p>';
	}

	private static function guard_fields( string $action ): string {
		return wp_nonce_field( $action, '_se_nonce', true, false )
			. '<input type="hidden" name="se_t" value="' . esc_attr( (string) time() ) . '">'
			. '<p class="se-p-hp" aria-hidden="true"><label for="se-p-website2">' . esc_html__( 'Leave this empty', 'scout-events' ) . '</label><input type="text" id="se-p-website2" name="se_website2" tabindex="-1" autocomplete="off"></p>';
	}

	private static function application_form( string $event, WP_User $user ): string {
		$email = $user->exists() ? $user->user_email : '';
		$name  = $user->exists() ? $user->display_name : '';
		ob_start();
		?>
		<form class="se-p-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="scout_presenter_apply">
			<input type="hidden" name="se_event" value="<?php echo esc_attr( $event ); ?>">
			<?php echo self::guard_fields( 'scout_presenter_apply' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<fieldset class="se-p-group">
				<legend><?php esc_html_e( 'About you', 'scout-events' ); ?></legend>
				<?php
				echo self::field( 'p[name]', __( 'Your name as it should appear', 'scout-events' ), $name, 'text', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[pronouns]', __( 'Pronouns', 'scout-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[email]', __( 'Email', 'scout-events' ), $email, 'email', true, __( 'Your login, and where we write to you. Never shown on the site.', 'scout-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[phone]', __( 'Phone', 'scout-events' ), '', 'tel', false, __( 'For the day of the event only. Never shown on the site.', 'scout-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[role]', __( 'What you do, in a few words', 'scout-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[short]', __( 'Short bio (two or three sentences)', 'scout-events' ), '', 'textarea', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[bio]', __( 'Full bio (optional)', 'scout-events' ), '', 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[website]', __( 'Website', 'scout-events' ), '', 'url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[links]', __( 'Social profiles, one link per line', 'scout-events' ), '', 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<p class="se-p-field"><label for="se-p-photo"><?php esc_html_e( 'A photo of you (JPG or PNG)', 'scout-events' ); ?></label><input type="file" id="se-p-photo" name="se_photo" accept="image/jpeg,image/png,image/webp"></p>
				<p class="se-p-field"><label for="se-p-photos"><?php esc_html_e( 'More photos we can choose from (optional, up to 4)', 'scout-events' ); ?></label><input type="file" id="se-p-photos" name="se_photos[]" accept="image/jpeg,image/png,image/webp" multiple></p>
			</fieldset>
			<fieldset class="se-p-group">
				<legend><?php esc_html_e( 'What you would like to teach', 'scout-events' ); ?></legend>
				<?php for ( $i = 1; $i <= 2; $i++ ) : ?>
					<?php
					/* translators: %d: class number. */
					echo self::field( 'c[' . $i . '][title]', sprintf( __( 'Class %d title', 'scout-events' ), $i ), '', 'text', 1 === $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					/* translators: %d: class number. */
					echo self::field( 'c[' . $i . '][desc]', sprintf( __( 'Class %d description', 'scout-events' ), $i ), '', 'textarea', 1 === $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				<?php endfor; ?>
				<?php echo self::field( 'p[needs]', __( 'What you need in the room', 'scout-events' ), '', 'textarea', false, __( 'For example: a microphone, chairs, a massage table, a speaker for music, floor space.', 'scout-events' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::field( 'p[notes]', __( 'Anything else we should know', 'scout-events' ), '', 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</fieldset>
			<p class="se-p-check"><label for="se-p-release"><input type="checkbox" id="se-p-release" name="p[photo_release]" value="1" required> <?php esc_html_e( 'You may use my name, bio and photo to promote the event, and photos taken of me while I teach.', 'scout-events' ); ?></label></p>
			<p><button type="submit" class="se-button wp-element-button"><?php esc_html_e( 'Send my application', 'scout-events' ); ?></button></p>
			<?php if ( ! is_user_logged_in() ) : ?>
				<p class="se-p-help"><?php esc_html_e( 'Applied before?', 'scout-events' ); ?> <a href="<?php echo esc_url( wp_login_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Log in to update your profile', 'scout-events' ); ?></a></p>
			<?php endif; ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private static function profile( int $speaker_id ): string {
		$p       = Scout_Events::speaker( $speaker_id );
		$pending = get_post_meta( $speaker_id, self::PENDING, true );
		$priv    = self::private_info( $speaker_id );
		$status  = get_post_status( $speaker_id );
		$view    = is_array( $pending ) && $pending ? array_merge( array( 'name' => get_the_title( $speaker_id ), 'bio' => (string) get_post_field( 'post_content', $speaker_id ) ), $p, $pending ) : array_merge( array( 'name' => get_the_title( $speaker_id ), 'bio' => (string) get_post_field( 'post_content', $speaker_id ) ), $p );
		$links   = is_array( $view['links'] ) ? implode( "\n", $view['links'] ) : (string) $view['links'];
		ob_start();
		?>
		<div class="se-p-status">
			<p class="se-p-badge is-<?php echo esc_attr( 'publish' === $status ? 'live' : 'waiting' ); ?>">
				<?php echo esc_html( 'publish' === $status ? __( 'Your profile is live', 'scout-events' ) : __( 'Your application is being reviewed', 'scout-events' ) ); ?>
			</p>
			<?php if ( 'publish' === $status ) : ?>
				<p><a href="<?php echo esc_url( get_permalink( $speaker_id ) ); ?>"><?php esc_html_e( 'See your public profile', 'scout-events' ); ?></a></p>
			<?php endif; ?>
			<?php if ( is_array( $pending ) && $pending ) : ?>
				<p class="se-p-help"><?php esc_html_e( 'Your latest changes are waiting for approval. The form below shows them.', 'scout-events' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$sessions = array_filter(
			get_posts(
				array(
					'post_type'      => Scout_Events::SESSION,
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'posts_per_page' => 50,
					'fields'         => 'ids',
				)
			),
			static fn( $sid ) => in_array( $speaker_id, Scout_Events::session( (int) $sid )['speakers'], true )
		);
		if ( $sessions ) {
			echo '<h3>' . esc_html__( 'Your sessions', 'scout-events' ) . '</h3><ul class="se-session-list">';
			foreach ( $sessions as $sid ) {
				$s    = Scout_Events::session( (int) $sid );
				$when = trim( Scout_Events::day_label( $s['date'] ) . ', ' . Scout_Events::session_time_label( $s ), ', ' );
				echo '<li><span>' . esc_html( get_the_title( $sid ) ) . '</span><span class="se-meta">' . esc_html( 'publish' === get_post_status( $sid ) ? ( '' !== $when ? $when : __( 'Time to be announced', 'scout-events' ) ) : __( 'Proposed, not scheduled yet', 'scout-events' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		?>
		<form class="se-p-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="scout_presenter_update">
			<?php echo self::guard_fields( 'scout_presenter_update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<fieldset class="se-p-group">
				<legend><?php esc_html_e( 'Update your profile', 'scout-events' ); ?></legend>
				<?php
				echo self::field( 'p[name]', __( 'Your name as it should appear', 'scout-events' ), (string) $view['name'], 'text', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[role]', __( 'What you do, in a few words', 'scout-events' ), (string) $view['role'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[short]', __( 'Short bio', 'scout-events' ), (string) $view['short'], 'textarea', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[bio]', __( 'Full bio', 'scout-events' ), wp_strip_all_tags( (string) $view['bio'] ), 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[website]', __( 'Website', 'scout-events' ), (string) $view['website'], 'url' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[links]', __( 'Social profiles, one link per line', 'scout-events' ), $links, 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<p class="se-p-field"><label for="se-p-photo"><?php esc_html_e( 'A new main photo (optional)', 'scout-events' ); ?></label><input type="file" id="se-p-photo" name="se_photo" accept="image/jpeg,image/png,image/webp"></p>
				<p class="se-p-field"><label for="se-p-photos"><?php esc_html_e( 'More photos (optional, up to 4)', 'scout-events' ); ?></label><input type="file" id="se-p-photos" name="se_photos[]" accept="image/jpeg,image/png,image/webp" multiple><span class="se-p-help"><?php esc_html_e( 'Your current photos stay. New ones are added once they are approved.', 'scout-events' ); ?></span></p>
			</fieldset>
			<fieldset class="se-p-group">
				<legend><?php esc_html_e( 'Just for the organizers', 'scout-events' ); ?></legend>
				<?php
				echo self::field( 'p[phone]', __( 'Phone', 'scout-events' ), (string) $priv['phone'], 'tel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::field( 'p[needs]', __( 'What you need in the room', 'scout-events' ), (string) $priv['needs'], 'textarea' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</fieldset>
			<p><button type="submit" class="se-button wp-element-button"><?php esc_html_e( 'Send for approval', 'scout-events' ); ?></button></p>
			<p class="se-p-help"><a href="<?php echo esc_url( wp_logout_url( self::portal_url() ) ); ?>"><?php esc_html_e( 'Log out', 'scout-events' ); ?></a></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- handling the forms ---------- */

	/** Back to the portal with a message. */
	private static function back( string $msg ): void {
		$ref = wp_get_referer();
		$url = $ref ? remove_query_arg( 'presenter', $ref ) : self::portal_url();
		wp_safe_redirect( add_query_arg( 'presenter', $msg, $url ) . '#se-portal' );
		exit;
	}

	/** Nonce, honeypot and time trap. Bots get a quiet "applied" so they learn nothing. */
	private static function guard( string $action ): void {
		$nonce = isset( $_POST['_se_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and send it again.', 'scout-events' ), 400 );
		}
		if ( ! empty( $_POST['se_website2'] ) ) {
			self::back( 'applied' );
		}
		$t = isset( $_POST['se_t'] ) ? (int) $_POST['se_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SECONDS ) {
			self::back( 'tooquick' );
		}
	}

	/** The posted profile fields, cleaned. */
	private static function posted(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); each key is cleaned below.
		$p = isset( $_POST['p'] ) ? (array) wp_unslash( $_POST['p'] ) : array();
		return array(
			'name'          => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'pronouns'      => sanitize_text_field( (string) ( $p['pronouns'] ?? '' ) ),
			'email'         => sanitize_email( (string) ( $p['email'] ?? '' ) ),
			'phone'         => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
			'role'          => sanitize_text_field( (string) ( $p['role'] ?? '' ) ),
			'short'         => sanitize_textarea_field( (string) ( $p['short'] ?? '' ) ),
			'bio'           => sanitize_textarea_field( (string) ( $p['bio'] ?? '' ) ),
			'website'       => esc_url_raw( trim( (string) ( $p['website'] ?? '' ) ), array( 'http', 'https' ) ),
			'links'         => (string) ( $p['links'] ?? '' ),
			'needs'         => sanitize_textarea_field( (string) ( $p['needs'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ),
			'photo_release' => ! empty( $p['photo_release'] ) ? 1 : 0,
		);
	}

	/** Upload the posted photo into the Media Library. 0 when none was sent; -1 on failure. */
	private static function photo( int $parent ): int {
		if ( empty( $_FILES['se_photo']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			return 0;
		}
		$file = $_FILES['se_photo']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handed to WordPress's own upload checks.
		if ( (int) ( $file['size'] ?? 0 ) > 8 * MB_IN_BYTES || ! in_array( wp_check_filetype( (string) $file['name'] )['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return -1;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'se_photo', $parent, array(), array( 'test_form' => false ) );
		return is_wp_error( $id ) ? -1 : (int) $id;
	}

	public static function apply(): void {
		self::guard( 'scout_presenter_apply' );
		$in = self::posted();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); cleaned below.
		$classes = isset( $_POST['c'] ) ? (array) wp_unslash( $_POST['c'] ) : array();
		$first   = (array) ( $classes[1] ?? array() );
		if ( '' === $in['name'] || ! is_email( $in['email'] ) || '' === $in['short'] || '' === trim( (string) ( $first['title'] ?? '' ) ) ) {
			self::back( 'missing' );
		}
		if ( ! $in['photo_release'] ) {
			self::back( 'consent' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			if ( email_exists( $in['email'] ) ) {
				self::back( 'exists' );
			}
			$login   = sanitize_user( strtok( $in['email'], '@' ), true );
			$login   = username_exists( $login ) ? $login . wp_rand( 100, 999 ) : $login;
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $in['email'],
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => $in['name'],
					'role'         => self::ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				self::back( 'missing' );
			}
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}

		$speaker = wp_insert_post(
			array(
				'post_type'    => Scout_Events::SPEAKER,
				'post_status'  => 'pending',
				'post_title'   => $in['name'],
				'post_content' => wpautop( $in['bio'] ),
			),
			true
		);
		if ( is_wp_error( $speaker ) ) {
			self::back( 'missing' );
		}
		update_post_meta( $speaker, self::USER, (int) $user_id );
		update_post_meta( $speaker, '_scout_speaker', Scout_Events::sanitize_speaker( array( 'role' => $in['role'], 'short' => $in['short'], 'website' => $in['website'], 'links' => $in['links'] ) ) );
		update_post_meta(
			$speaker,
			self::PRIVATE,
			array(
				'phone'         => $in['phone'],
				'pronouns'      => $in['pronouns'],
				'needs'         => $in['needs'],
				'notes'         => $in['notes'],
				'photo_release' => 1,
				'applied'       => Scout_Events::now()->format( 'Y-m-d H:i' ),
			)
		);
		$photo = self::photo( (int) $speaker );
		if ( $photo > 0 ) {
			set_post_thumbnail( $speaker, $photo );
		}
		$more = Scout_Events_Photos::upload( 'se_photos', (int) $speaker );
		foreach ( $more['ids'] as $att ) {
			Scout_Events_Photos::add( (int) $speaker, $att );
		}
		if ( $more['failed'] && $photo >= 0 ) {
			$photo = -1;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$event_word = isset( $_POST['se_event'] ) ? sanitize_title( wp_unslash( $_POST['se_event'] ) ) : '';
		$event      = '' !== $event_word ? get_page_by_path( $event_word, OBJECT, Scout_Events::EVENT ) : null;
		foreach ( array( 1, 2 ) as $i ) {
			$c     = (array) ( $classes[ $i ] ?? array() );
			$title = sanitize_text_field( (string) ( $c['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$sid = wp_insert_post(
				array(
					'post_type'    => Scout_Events::SESSION,
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => wpautop( sanitize_textarea_field( (string) ( $c['desc'] ?? '' ) ) ),
				)
			);
			if ( $sid && ! is_wp_error( $sid ) ) {
				Scout_Events::save_session(
					(int) $sid,
					array(
						'event'    => $event ? (int) $event->ID : 0,
						'speakers' => array( (int) $speaker ),
					)
				);
				update_post_meta( (int) $sid, '_scout_session_proposed', 1 );
			}
		}

		do_action(
			'scout_events_person',
			array(
				'email' => $in['email'],
				'name'  => $in['name'],
				'role'  => 'presenter',
				'event' => $event ? (int) $event->ID : 0,
			)
		);
		/* translators: %s: presenter name. */
		$subject = sprintf( __( 'New presenter application: %s', 'scout-events' ), $in['name'] );
		/* translators: %s: link to review. */
		$body = sprintf( __( "A new presenter applied. Review them here:\n%s", 'scout-events' ), admin_url( 'post.php?post=' . (int) $speaker . '&action=edit' ) );
		wp_mail( (string) get_option( 'admin_email' ), $subject, $body );
		self::back( $photo < 0 ? 'photo' : 'applied' );
	}

	public static function update(): void {
		self::guard( 'scout_presenter_update' );
		$speaker = self::speaker_for_user( get_current_user_id() );
		if ( ! $speaker ) {
			self::back( 'missing' );
		}
		$in = self::posted();
		if ( '' === $in['name'] || '' === $in['short'] ) {
			self::back( 'missing' );
		}
		$pending = array(
			'name'    => $in['name'],
			'role'    => $in['role'],
			'short'   => $in['short'],
			'bio'     => wpautop( $in['bio'] ),
			'website' => $in['website'],
			'links'   => Scout_Events::sanitize_speaker( array( 'links' => $in['links'] ) )['links'],
			'sent'    => Scout_Events::now()->format( 'Y-m-d H:i' ),
		);
		$photo = self::photo( $speaker );
		if ( $photo > 0 ) {
			$pending['photo'] = $photo;
		}
		$more = Scout_Events_Photos::upload( 'se_photos', $speaker );
		if ( $more['ids'] ) {
			$pending['photos'] = $more['ids'];
		}
		if ( $more['failed'] && $photo >= 0 ) {
			$photo = -1;
		}
		update_post_meta( $speaker, self::PENDING, $pending );
		// Private details are for the organizers only, so they save straight away.
		$priv          = self::private_info( $speaker );
		$priv['phone'] = $in['phone'];
		$priv['needs'] = $in['needs'];
		update_post_meta( $speaker, self::PRIVATE, $priv );
		self::back( $photo < 0 ? 'photo' : 'updated' );
	}

	/** Approve or discard a presenter's pending changes from the speaker edit screen. */
	public static function review(): void {
		$speaker = isset( $_POST['speaker'] ) ? absint( $_POST['speaker'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'scout_presenter_review_' . $speaker );
		if ( ! $speaker || ! current_user_can( 'edit_post', $speaker ) ) {
			wp_die( esc_html__( 'You do not have permission to review this profile.', 'scout-events' ), 403 );
		}
		$pending = get_post_meta( $speaker, self::PENDING, true );
		if ( is_array( $pending ) && ! empty( $_POST['approve'] ) ) {
			wp_update_post(
				array(
					'ID'           => $speaker,
					'post_title'   => $pending['name'],
					'post_content' => $pending['bio'],
				)
			);
			$p = Scout_Events::speaker( $speaker );
			update_post_meta( $speaker, '_scout_speaker', Scout_Events::sanitize_speaker( array_merge( $p, array_intersect_key( $pending, array_flip( array( 'role', 'short', 'website', 'links' ) ) ) ) ) );
			if ( ! empty( $pending['photo'] ) ) {
				set_post_thumbnail( $speaker, (int) $pending['photo'] );
			}
			foreach ( (array) ( $pending['photos'] ?? array() ) as $att ) {
				Scout_Events_Photos::add( $speaker, (int) $att );
			}
			$user = get_userdata( (int) get_post_meta( $speaker, self::USER, true ) );
			if ( $user ) {
				wp_mail( $user->user_email, __( 'Your profile changes are live', 'scout-events' ), __( 'Thank you for keeping your profile current. Your changes are now on the site.', 'scout-events' ) );
			}
		}
		delete_post_meta( $speaker, self::PENDING );
		wp_safe_redirect( admin_url( 'post.php?post=' . $speaker . '&action=edit&presenter=' . ( ! empty( $_POST['approve'] ) ? 'approved' : 'discarded' ) ) );
		exit;
	}

	/** Publishing a pending applicant is the approval: tell them. */
	public static function approved( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'pending' !== $old || ! $post instanceof WP_Post || Scout_Events::SPEAKER !== $post->post_type ) {
			return;
		}
		$user = get_userdata( (int) get_post_meta( $post->ID, self::USER, true ) );
		if ( ! $user ) {
			return;
		}
		/* translators: %s: site name. */
		$subject = sprintf( __( 'You are confirmed as a presenter at %s', 'scout-events' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		/* translators: 1: public profile link, 2: portal link. */
		$body = sprintf( __( "Welcome. Your profile is now on the site:\n%1\$s\n\nYou can update it any time here:\n%2\$s", 'scout-events' ), get_permalink( $post ), self::portal_url() );
		wp_mail( $user->user_email, $subject, $body );
	}

	/* ---------- keeping presenters out of the dashboard ---------- */

	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow || ! self::is_presenter() ) {
			return;
		}
		wp_safe_redirect( self::portal_url() );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::is_presenter() ? false : $show;
	}

	/* ---------- the owner's side ---------- */

	public static function box( $post ): void {
		add_meta_box( 'scout-speaker-presenter', __( 'Presenter', 'scout-events' ), array( __CLASS__, 'render_box' ), Scout_Events::SPEAKER, 'side', 'high' );
	}

	public static function render_box( $post ): void {
		$user    = get_userdata( (int) get_post_meta( $post->ID, self::USER, true ) );
		$priv    = self::private_info( (int) $post->ID );
		$pending = get_post_meta( $post->ID, self::PENDING, true );
		$msg     = isset( $_GET['presenter'] ) ? sanitize_key( wp_unslash( $_GET['presenter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'approved' === $msg ) {
			echo '<p class="se-chip is-done">' . esc_html__( 'Changes approved and live.', 'scout-events' ) . '</p>';
		} elseif ( 'discarded' === $msg ) {
			echo '<p class="se-chip">' . esc_html__( 'Changes discarded.', 'scout-events' ) . '</p>';
		}
		if ( ! $user ) {
			echo '<p class="se-note">' . esc_html__( 'No presenter account. Speakers who apply on the site get one, so they can update their own profile.', 'scout-events' ) . '</p>';
			return;
		}
		if ( 'pending' === $post->post_status ) {
			echo '<p><strong>' . esc_html__( 'New application.', 'scout-events' ) . '</strong> ' . esc_html__( 'Publish this speaker to approve them; they get an email.', 'scout-events' ) . '</p>';
		}
		echo '<dl class="se-private">';
		$rows = array(
			__( 'Account', 'scout-events' )              => $user->user_email,
			__( 'Phone', 'scout-events' )                => $priv['phone'],
			__( 'Pronouns', 'scout-events' )             => $priv['pronouns'],
			__( 'Needs in the room', 'scout-events' )    => $priv['needs'],
			__( 'Notes', 'scout-events' )                => $priv['notes'],
			__( 'Photo release', 'scout-events' )        => $priv['photo_release'] ? __( 'Yes', 'scout-events' ) : __( 'Not given', 'scout-events' ),
			__( 'Applied', 'scout-events' )              => $priv['applied'],
		);
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( (string) $value ) ) . '</dd>';
			}
		}
		echo '</dl><p class="se-note">' . esc_html__( 'Private: only people who can edit the site see this.', 'scout-events' ) . '</p>';

		if ( is_array( $pending ) && $pending ) {
			$current = array(
				'name'    => get_the_title( $post ),
				'role'    => Scout_Events::speaker( (int) $post->ID )['role'],
				'short'   => Scout_Events::speaker( (int) $post->ID )['short'],
				'website' => Scout_Events::speaker( (int) $post->ID )['website'],
			);
			/* translators: %s: date and time sent. */
			echo '<div class="se-pending"><p><strong>' . esc_html( sprintf( __( 'Changes waiting for you (sent %s)', 'scout-events' ), $pending['sent'] ?? '' ) ) . '</strong></p><dl>';
			$labels = array(
				'name'    => __( 'Name', 'scout-events' ),
				'role'    => __( 'What they do', 'scout-events' ),
				'short'   => __( 'Short bio', 'scout-events' ),
				'website' => __( 'Website', 'scout-events' ),
			);
			foreach ( $labels as $key => $label ) {
				if ( (string) ( $pending[ $key ] ?? '' ) !== (string) $current[ $key ] ) {
					echo '<dt>' . esc_html( $label ) . '</dt><dd><del>' . esc_html( (string) $current[ $key ] ) . '</del><br><ins>' . esc_html( (string) ( $pending[ $key ] ?? '' ) ) . '</ins></dd>';
				}
			}
			if ( wp_strip_all_tags( (string) ( $pending['bio'] ?? '' ) ) !== wp_strip_all_tags( (string) $post->post_content ) ) {
				echo '<dt>' . esc_html__( 'Full bio', 'scout-events' ) . '</dt><dd>' . esc_html__( 'Changed.', 'scout-events' ) . '</dd>';
			}
			if ( ! empty( $pending['photo'] ) ) {
				echo '<dt>' . esc_html__( 'New main photo', 'scout-events' ) . '</dt><dd>' . wp_get_attachment_image( (int) $pending['photo'], array( 80, 80 ) ) . '</dd>';
			}
			if ( ! empty( $pending['photos'] ) ) {
				echo '<dt>' . esc_html__( 'More photos', 'scout-events' ) . '</dt><dd class="se-pending-photos">';
				foreach ( (array) $pending['photos'] as $att ) {
					echo wp_get_attachment_image( (int) $att, array( 64, 64 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
				}
				echo '</dd>';
			}
			echo '</dl>';
			$action = esc_url( admin_url( 'admin-post.php' ) );
			$nonce  = wp_nonce_field( 'scout_presenter_review_' . $post->ID, '_wpnonce', true, false );
			printf(
				'<p class="se-pending-actions"><button type="submit" class="button button-primary" form="se-review-ok">%1$s</button> <button type="submit" class="button" form="se-review-no">%2$s</button></p>',
				esc_html__( 'Approve changes', 'scout-events' ),
				esc_html__( 'Discard', 'scout-events' )
			);
			// The two small forms sit outside the post form so they never submit it.
			add_action(
				'admin_footer',
				static function () use ( $action, $nonce, $post ) {
					foreach ( array( 'ok' => 1, 'no' => 0 ) as $key => $approve ) {
						echo '<form id="se-review-' . esc_attr( $key ) . '" method="post" action="' . $action . '" hidden><input type="hidden" name="action" value="scout_presenter_review"><input type="hidden" name="speaker" value="' . (int) $post->ID . '">' . ( $approve ? '<input type="hidden" name="approve" value="1">' : '' ) . $nonce . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					}
				}
			);
			echo '</div>';
		}
	}

	/** Applications, pending changes and proposed classes on the Events overview. */
	public static function needs_you( $items ): array {
		$items = (array) $items;
		$apps  = get_posts(
			array(
				'post_type'      => Scout_Events::SPEAKER,
				'post_status'    => 'pending',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		if ( $apps ) {
			array_unshift(
				$items,
				array(
					/* translators: %d: number of applications. */
					'text'   => sprintf( _n( '%d new presenter application to review.', '%d new presenter applications to review.', count( $apps ), 'scout-events' ), count( $apps ) ),
					'url'    => admin_url( 'edit.php?post_type=' . Scout_Events::SPEAKER . '&post_status=pending' ),
					'action' => __( 'Review applications', 'scout-events' ),
				)
			);
		}
		$changed = get_posts(
			array(
				'post_type'      => Scout_Events::SPEAKER,
				'post_status'    => array( 'publish', 'pending' ),
				'meta_key'       => self::PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- few rows.
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $changed as $id ) {
			array_unshift(
				$items,
				array(
					/* translators: %s: presenter name. */
					'text'   => sprintf( __( '%s updated their profile. Approve or discard the changes.', 'scout-events' ), get_the_title( $id ) ),
					'url'    => (string) get_edit_post_link( $id, 'raw' ),
					'action' => __( 'Review changes', 'scout-events' ),
				)
			);
		}
		return $items;
	}
}
