<?php
/**
 * Send them a link: a one-time page where a person fills in their own
 * profile. The owner makes the link (on the People list, the edit screen or
 * at the end of Add a person) and sends it themselves. Nothing is emailed.
 *
 * - The link is home_url( '/?scout_people=<token>' ). The token is 48 random
 *   hex characters. Only a keyed hash of it is stored, on the person
 *   (_scout_team_link_hash), with when it ends (_scout_team_link). It ends
 *   after 14 days, works once, and allows a few tries an hour.
 * - The page has no login. It takes name, title, short bio, a photo and the
 *   credential lines with their sources, and profile links. Never email,
 *   phone or the card only contact.
 * - What they send waits on the person as pending changes (_scout_team_pending).
 *   The live profile, the public pages and the schema never read it. The
 *   owner approves it on the edit screen (it is copied into the profile) or
 *   discards it. Until then it is a To do item and a "Needs a look" reason.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Link {

	/** The address part that carries the token: /?scout_people=<token>. */
	const QUERY = 'scout_people';

	/** On the person: the hash of their current link's token. */
	const HASH = '_scout_team_link_hash';

	/** On the person: [ created, expires, by, used ] for their latest link. */
	const META = '_scout_team_link';

	/** How long a link works. */
	const DAYS = 14;

	/** Form sends allowed per link in an hour. */
	const TRIES = 8;

	/** The largest photo the page takes, in bytes (5 MB). */
	const MAX_PHOTO = 5242880;

	/** Photo types the page takes. */
	const TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Blank credential lines the page offers under the filled ones. */
	const BLANK = 2;

	/** The admin-post actions (owner side). */
	const ACTION_LINK    = 'scout_team_link';
	const ACTION_PENDING = 'scout_team_pending';

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_action( 'admin_post_' . self::ACTION_LINK, array( __CLASS__, 'handle_link' ) );
		add_action( 'admin_post_' . self::ACTION_PENDING, array( __CLASS__, 'handle_pending' ) );
		add_action( 'add_meta_boxes_' . Scout_Team::POST_TYPE, array( __CLASS__, 'boxes' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'scout_hub_inbox_items', array( __CLASS__, 'inbox' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/* ---------- the token ---------- */

	/** A new random token: 48 hex characters. */
	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/** The stored form of a token. Keyed with the site's salt, so a copy of the database alone cannot rebuild a link. */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/** Is this the shape of a token? */
	public static function well_formed( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{48}$/', $token );
	}

	/** The page address for a token. */
	public static function url( string $token ): string {
		return add_query_arg( self::QUERY, $token, home_url( '/' ) );
	}

	/**
	 * Whether a token opens a person's link, without touching the database.
	 *
	 * @param array  $link   The person's link record (created, expires, used).
	 * @param string $stored The stored hash, '' when there is none.
	 * @param string $token  The token from the address.
	 * @param int    $now    The time to check against.
	 * @return string ok, wrong, used or expired.
	 */
	public static function state( array $link, string $stored, string $token, int $now ): string {
		if ( ! self::well_formed( $token ) || '' === $stored || ! hash_equals( $stored, self::hash( $token ) ) ) {
			return ! empty( $link['used'] ) ? 'used' : 'wrong';
		}
		if ( ! empty( $link['used'] ) ) {
			return 'used';
		}
		if ( (int) ( $link['expires'] ?? 0 ) <= $now ) {
			return 'expired';
		}
		return 'ok';
	}

	/** The person's link record. */
	public static function record( int $post_id ): array {
		$link = get_post_meta( $post_id, self::META, true );
		return is_array( $link ) ? $link : array();
	}

	/**
	 * Does this token open this person's link right now?
	 *
	 * @return string ok, wrong, used or expired.
	 */
	public static function verify( int $post_id, string $token, int $now = 0 ): string {
		return self::state( self::record( $post_id ), (string) get_post_meta( $post_id, self::HASH, true ), $token, $now ? $now : time() );
	}

	/**
	 * Make a new link for a person. Any earlier link stops working. Returns the
	 * address; only its hash is kept.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function create( int $post_id ): string {
		$token = self::new_token();
		$now   = time();
		update_post_meta( $post_id, self::HASH, self::hash( $token ) );
		update_post_meta(
			$post_id,
			self::META,
			array(
				'created' => $now,
				'expires' => $now + self::DAYS * DAY_IN_SECONDS,
				'by'      => (int) get_current_user_id(),
				'used'    => 0,
			)
		);
		$url = self::url( $token );
		// Shown once to the person who made it, on the next screen, then gone.
		set_transient( self::show_key( $post_id ), $url, 10 * MINUTE_IN_SECONDS );
		return $url;
	}

	/** Use the link up: it works once. */
	public static function consume( int $post_id ): void {
		$link         = self::record( $post_id );
		$link['used'] = time();
		update_post_meta( $post_id, self::META, $link );
		delete_post_meta( $post_id, self::HASH );
	}

	/** Turn a link off. */
	public static function revoke( int $post_id ): void {
		delete_post_meta( $post_id, self::HASH );
		delete_post_meta( $post_id, self::META );
	}

	/** Is a link out and still working? */
	public static function active( int $post_id ): bool {
		$link = self::record( $post_id );
		return $link && empty( $link['used'] ) && (int) ( $link['expires'] ?? 0 ) > time() && '' !== (string) get_post_meta( $post_id, self::HASH, true );
	}

	/** The transient that holds a new link for the one screen that shows it. */
	private static function show_key( int $post_id ): string {
		return 'scout_team_link_' . (int) get_current_user_id() . '_' . $post_id;
	}

	/** The person a token belongs to, or 0. */
	public static function find( string $token ): int {
		if ( ! self::well_formed( $token ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => Scout_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::HASH, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => self::hash( $token ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Count one form send on a link. False once it has had too many this hour.
	 *
	 * @param string $token The token.
	 */
	public static function allow_try( string $token ): bool {
		$key   = 'scout_team_tries_' . substr( self::hash( $token ), 0, 32 );
		$tries = (int) get_transient( $key );
		if ( $tries >= self::TRIES ) {
			return false;
		}
		set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------- what the page takes ---------- */

	/**
	 * The fields the page takes. Everything else on a profile (email, phone,
	 * the card only contact, the login, where they show) stays with the owner.
	 */
	public static function fields(): array {
		return array( 'name', 'role', 'bio', 'photo', 'education', 'awards', 'memberships', 'bar', 'same_as' );
	}

	/** The credential lists the page shows, and the columns of each, with plain labels for the person filling it in. */
	public static function columns( bool $bar ): array {
		$out = array(
			'education'   => array(
				'legend' => __( 'Education', 'scout-team' ),
				'cols'   => array(
					'school' => __( 'School', 'scout-team' ),
					'degree' => __( 'Degree', 'scout-team' ),
					'year'   => __( 'Year', 'scout-team' ),
				),
			),
			'awards'      => array(
				'legend' => __( 'Awards and honors', 'scout-team' ),
				'cols'   => array(
					'name'   => __( 'Award', 'scout-team' ),
					'year'   => __( 'Year', 'scout-team' ),
					'issuer' => __( 'Given by', 'scout-team' ),
				),
			),
			'memberships' => array(
				'legend' => __( 'Professional memberships', 'scout-team' ),
				'cols'   => array(
					'name' => __( 'Group you belong to', 'scout-team' ),
				),
			),
		);
		if ( $bar ) {
			$out = array(
				'bar' => array(
					'legend' => __( 'Bar admissions', 'scout-team' ),
					'cols'   => array(
						'state'  => __( 'State or court', 'scout-team' ),
						'year'   => __( 'Year admitted', 'scout-team' ),
						'number' => __( 'Bar number (optional)', 'scout-team' ),
						'url'    => __( 'Bar profile link (optional)', 'scout-team' ),
					),
				),
			) + $out;
		}
		return $out;
	}

	/** Does the page offer bar admissions for this person? Law sites, attorneys only. */
	public static function takes_bar( array $p ): bool {
		return ! empty( $p['attorney'] ) && Scout_Team::is_law();
	}

	/**
	 * Clean what the page sent. Only the page's own fields survive; the lists
	 * go through the same cleaning as the edit screen.
	 *
	 * @param array $in  Raw (unslashed) input.
	 * @param bool  $bar Bar admissions are on the page.
	 */
	public static function clean( array $in, bool $bar = false ): array {
		$raw = array();
		foreach ( array( 'role', 'bio', 'education', 'awards', 'memberships' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$raw[ $key ] = $in[ $key ];
			}
		}
		if ( $bar && isset( $in['bar'] ) ) {
			$raw['bar'] = $in['bar'];
		}
		$links          = $in['same_as'] ?? '';
		$raw['same_as'] = is_array( $links ) ? implode( "\n", array_map( 'strval', $links ) ) : (string) $links;
		foreach ( array( 'role', 'bio' ) as $key ) {
			$raw[ $key ] = is_scalar( $raw[ $key ] ?? '' ) ? (string) ( $raw[ $key ] ?? '' ) : '';
		}
		$p   = Scout_Team::sanitize_profile( $raw );
		$out = array(
			'name'        => self::cut( sanitize_text_field( is_scalar( $in['name'] ?? '' ) ? (string) ( $in['name'] ?? '' ) : '' ), 120 ),
			'role'        => self::cut( $p['role'], 120 ),
			'bio'         => self::cut( $p['bio'], 1200 ),
			'education'   => $p['education'],
			'awards'      => $p['awards'],
			'memberships' => $p['memberships'],
			'same_as'     => array_slice( $p['same_as'], 0, 10 ),
		);
		if ( $bar ) {
			$out['bar'] = $p['bar'];
		}
		return $out;
	}

	/** Shorten text to a length, on a character boundary. */
	private static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max ? trim( mb_substr( $text, 0, $max ) ) : $text;
		}
		return strlen( $text ) > $max ? trim( substr( $text, 0, $max ) ) : $text;
	}

	/**
	 * The profile with what they sent laid over it. An empty title or bio keeps
	 * what is there; the lists and links are taken as sent, because the page
	 * starts filled in with what is there now. Nothing else changes.
	 *
	 * @param array $live    The stored profile, full shape.
	 * @param array $pending What they sent, from clean().
	 */
	public static function merge( array $live, array $pending ): array {
		$out = $live;
		foreach ( array( 'role', 'bio' ) as $key ) {
			if ( '' !== trim( (string) ( $pending[ $key ] ?? '' ) ) ) {
				$out[ $key ] = (string) $pending[ $key ];
			}
		}
		foreach ( array( 'education', 'awards', 'memberships', 'bar', 'same_as' ) as $key ) {
			if ( isset( $pending[ $key ] ) && is_array( $pending[ $key ] ) ) {
				$out[ $key ] = $pending[ $key ];
			}
		}
		return $out;
	}

	/** What they sent and are waiting on, or an empty array. */
	public static function pending( int $post_id ): array {
		$pending = get_post_meta( $post_id, Scout_Team::PENDING, true );
		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Keep what they sent as pending changes. The live profile is not touched.
	 *
	 * @param int   $post_id Person post ID.
	 * @param array $clean   From clean().
	 * @param int   $photo   An uploaded photo's attachment ID, or 0.
	 */
	public static function save_pending( int $post_id, array $clean, int $photo = 0 ): void {
		$old = self::pending( $post_id );
		if ( ! empty( $old['photo'] ) && (int) $old['photo'] !== $photo ) {
			self::drop_photo( $post_id, (int) $old['photo'] );
		}
		$clean['photo'] = $photo;
		$clean['sent']  = time();
		update_post_meta( $post_id, Scout_Team::PENDING, $clean );
	}

	/**
	 * Copy what they sent into the profile: name, title, bio, credentials,
	 * links and photo. Then the pending changes are gone.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function approve( int $post_id ): bool {
		$pending = self::pending( $post_id );
		if ( ! $pending ) {
			return false;
		}
		update_post_meta( $post_id, Scout_Team::META, Scout_Team::shape( self::merge( Scout_Team::profile( $post_id ), $pending ) ) );
		$name = trim( (string) ( $pending['name'] ?? '' ) );
		if ( '' !== $name && get_the_title( $post_id ) !== $name ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $name,
				)
			);
		}
		$photo = (int) ( $pending['photo'] ?? 0 );
		if ( $photo && wp_attachment_is_image( $photo ) ) {
			set_post_thumbnail( $post_id, $photo );
			if ( '' === trim( (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ) ) ) {
				update_post_meta( $photo, '_wp_attachment_image_alt', '' !== $name ? $name : wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
			}
		}
		delete_post_meta( $post_id, Scout_Team::PENDING );
		return true;
	}

	/** Throw away what they sent, and the photo they uploaded with it. */
	public static function discard( int $post_id ): bool {
		$pending = self::pending( $post_id );
		if ( ! $pending ) {
			return false;
		}
		if ( ! empty( $pending['photo'] ) ) {
			self::drop_photo( $post_id, (int) $pending['photo'] );
		}
		delete_post_meta( $post_id, Scout_Team::PENDING );
		return true;
	}

	/** Delete a photo uploaded through the page, unless it has become the person's photo. */
	private static function drop_photo( int $post_id, int $photo ): void {
		if ( $photo && (int) get_post_thumbnail_id( $post_id ) !== $photo && (int) wp_get_post_parent_id( $photo ) === $post_id ) {
			wp_delete_attachment( $photo, true );
		}
	}

	/**
	 * What is wrong with an uploaded photo, in plain words, or '' when it is fine.
	 *
	 * @param array $file One entry of $_FILES.
	 */
	public static function photo_problem( array $file ): string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || (int) ( $file['size'] ?? 0 ) > self::MAX_PHOTO ) {
			return __( 'The photo is over 5 MB. Try a smaller one.', 'scout-team' );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return __( 'The photo did not upload. Try again.', 'scout-team' );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		$size  = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( (string) $file['tmp_name'] ) : false;
		if ( ! in_array( (string) ( $check['type'] ?? '' ), self::TYPES, true ) || ! $size ) {
			return __( 'Use a JPG, PNG or WebP photo.', 'scout-team' );
		}
		return '';
	}

	/**
	 * Put an uploaded photo in the media library, attached to the person.
	 *
	 * @param string $field   The $_FILES key.
	 * @param int    $post_id Person post ID.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function upload( string $field, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		return media_handle_upload( $field, $post_id, array(), array( 'test_form' => false ) );
	}

	/* ---------- the page they fill in ---------- */

	/** The values the page starts with: what they sent before, else what is there now. */
	public static function prefill( int $post_id ): array {
		$live    = Scout_Team::profile( $post_id );
		$pending = self::pending( $post_id );
		$p       = $pending ? self::merge( $live, $pending ) : $live;
		$name    = $pending && '' !== trim( (string) ( $pending['name'] ?? '' ) ) ? (string) $pending['name'] : wp_strip_all_tags( (string) get_the_title( $post_id ) );
		$out     = array(
			'name'    => $name,
			'role'    => (string) $p['role'],
			'bio'     => (string) $p['bio'],
			'same_as' => (array) $p['same_as'],
		);
		foreach ( array( 'education', 'awards', 'memberships', 'bar' ) as $group ) {
			$out[ $group ] = (array) $p[ $group ];
		}
		return $out;
	}

	/**
	 * Handle the address: the link page, the thank-you page, or an ended link.
	 * Runs before anything else on the front of the site and only when the
	 * address carries ?scout_people=.
	 */
	public static function route(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the key; the form checks its nonce.
		if ( is_admin() || ! isset( $_GET[ self::QUERY ] ) || ! is_string( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$raw = strtolower( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) );
		// phpcs:enable
		if ( 'sent' === $raw ) {
			self::page(
				__( 'Thank you', 'scout-team' ),
				'<h1>' . esc_html__( 'Thank you', 'scout-team' ) . '</h1><p>' . esc_html__( 'Your details are in. They show on the site once they are approved.', 'scout-team' ) . '</p>',
				200
			);
		}
		$token = self::well_formed( $raw ) ? $raw : '';
		$id    = '' !== $token ? self::find( $token ) : 0;
		if ( ! $id || 'ok' !== self::verify( $id, $token ) ) {
			self::page(
				__( 'This link has ended', 'scout-team' ),
				'<h1>' . esc_html__( 'This link has ended', 'scout-team' ) . '</h1><p>' . esc_html__( 'It may have expired or already been used. Ask the person who sent it for a new one.', 'scout-team' ) . '</p>',
				404
			);
		}
		$values = self::prefill( $id );
		$errors = array();
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			list( $values, $errors ) = self::submit( $id, $token, $values );
		}
		$bar = self::takes_bar( Scout_Team::profile( $id ) );
		self::page( __( 'Your profile', 'scout-team' ), self::form_html( $id, $values, $errors, $token, $bar, self::record( $id ) ), 200 );
	}

	/**
	 * Take a form send. On success: save as pending, use up the link and go to
	 * the thank-you page. Otherwise give back what they typed and what to fix.
	 *
	 * @param int    $post_id Person post ID.
	 * @param string $token   The token.
	 * @param array  $values  The values the page started with.
	 * @return array [ values, errors ]
	 */
	private static function submit( int $post_id, string $token, array $values ): array {
		$nonce = isset( $_POST['_st_details'] ) ? sanitize_text_field( wp_unslash( $_POST['_st_details'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'scout_team_details_' . $post_id ) ) {
			return array( $values, array( __( 'The page sat open too long. Send it again.', 'scout-team' ) ) );
		}
		// A filled hidden field means a bot. Say thanks and keep nothing.
		if ( ! empty( $_POST['st_website'] ) ) {
			wp_safe_redirect( self::url( 'sent' ) );
			exit;
		}
		if ( ! self::allow_try( $token ) ) {
			return array( $values, array( __( 'Too many tries on this link. Wait an hour and try again.', 'scout-team' ) ) );
		}
		$bar = self::takes_bar( Scout_Team::profile( $post_id ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cleaned in clean().
		$in     = isset( $_POST['st'] ) && is_array( $_POST['st'] ) ? (array) wp_unslash( $_POST['st'] ) : array();
		$clean  = self::clean( $in, $bar );
		$values = array_merge( $values, $clean, array( 'same_as' => $clean['same_as'] ) );
		$errors = array();
		if ( '' === $clean['name'] ) {
			$errors[] = __( 'Add your name.', 'scout-team' );
		}
		$photo = 0;
		if ( ! $errors && ! empty( $_FILES['st_photo']['name'] ) ) {
			$problem = self::photo_problem( (array) $_FILES['st_photo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked here, then handed to WordPress's own upload checks.
			if ( '' === $problem ) {
				$made = self::upload( 'st_photo', $post_id );
				if ( is_wp_error( $made ) ) {
					$problem = __( 'The photo did not upload. Try again.', 'scout-team' );
				} else {
					$photo = (int) $made;
				}
			}
			if ( '' !== $problem ) {
				$errors[] = $problem;
			}
		}
		if ( $errors ) {
			return array( $values, $errors );
		}
		self::save_pending( $post_id, $clean, $photo );
		self::consume( $post_id );
		wp_safe_redirect( self::url( 'sent' ) );
		exit;
	}

	/**
	 * The form, as plain markup. Name, title, bio, photo, credentials with
	 * sources and links. Never email, phone or the card only contact.
	 *
	 * @param int    $post_id Person post ID.
	 * @param array  $values  Values to fill in.
	 * @param array  $errors  What to fix, in plain words.
	 * @param string $token   The token.
	 * @param bool   $bar     Show bar admissions.
	 * @param array  $link    The link record, for the end date.
	 */
	public static function form_html( int $post_id, array $values, array $errors, string $token, bool $bar, array $link = array() ): string {
		$site = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$h    = '<h1>' . esc_html__( 'Your profile', 'scout-team' ) . '</h1>';
		/* translators: %s: the site's name. */
		$h .= '<p class="scout-team-details__lede">' . esc_html( sprintf( __( 'Fill in what you want on your profile on %s. It shows on the site once it is approved.', 'scout-team' ), $site ) ) . '</p>';
		if ( $errors ) {
			$h .= '<div class="scout-team-details__errors" role="alert"><p><strong>' . esc_html__( 'Fix these, then send it again:', 'scout-team' ) . '</strong></p><ul>';
			foreach ( $errors as $error ) {
				$h .= '<li>' . esc_html( $error ) . '</li>';
			}
			$h .= '</ul></div>';
		}
		$h .= '<form class="scout-team-details__form" method="post" action="' . esc_url( self::url( $token ) ) . '" enctype="multipart/form-data">';
		$h .= wp_nonce_field( 'scout_team_details_' . $post_id, '_st_details', false, false );
		$h .= '<p class="scout-team-details__hp" aria-hidden="true"><label for="st-website">' . esc_html__( 'Leave this empty', 'scout-team' ) . '</label><input type="text" id="st-website" name="st_website" value="" tabindex="-1" autocomplete="off"></p>';

		$h .= '<fieldset><legend>' . esc_html__( 'About you', 'scout-team' ) . '</legend>';
		$h .= self::input( 'name', __( 'Your name', 'scout-team' ), (string) ( $values['name'] ?? '' ), 'text', '', true );
		$h .= self::input( 'role', __( 'Your title or role', 'scout-team' ), (string) ( $values['role'] ?? '' ) );
		$h .= '<p class="scout-team-details__field"><label for="st-bio">' . esc_html__( 'Short bio', 'scout-team' ) . '</label>'
			. '<textarea id="st-bio" name="st[bio]" rows="4" maxlength="1200" aria-describedby="st-bio-help">' . esc_textarea( (string) ( $values['bio'] ?? '' ) ) . '</textarea>'
			. '<span class="scout-team-details__help" id="st-bio-help">' . esc_html__( 'Two or three plain sentences about you and your work.', 'scout-team' ) . '</span></p>';
		$h .= '</fieldset>';

		$h .= '<fieldset><legend>' . esc_html__( 'Photo', 'scout-team' ) . '</legend>';
		$h .= '<p class="scout-team-details__field"><label for="st-photo">' . esc_html__( 'A photo of you (optional)', 'scout-team' ) . '</label>'
			. '<input type="file" id="st-photo" name="st_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="st-photo-help">'
			. '<span class="scout-team-details__help" id="st-photo-help">' . esc_html__( 'JPG, PNG or WebP, up to 5 MB. A clear, recent photo of your face works best.', 'scout-team' ) . '</span></p>';
		$h .= '</fieldset>';

		$h .= '<p class="scout-team-details__note">' . esc_html__( 'For each line, add a source: a website or document where someone can check it. Lines with no source stay off the site.', 'scout-team' ) . '</p>';
		foreach ( self::columns( $bar ) as $group => $info ) {
			$rows   = array_values( (array) ( $values[ $group ] ?? array() ) );
			$blank  = array_fill( 0, self::BLANK, array() );
			$rows   = array_merge( $rows, $blank );
			$h     .= '<fieldset class="scout-team-details__list"><legend>' . esc_html( $info['legend'] ) . '</legend>';
			foreach ( $rows as $i => $row ) {
				$h .= '<div class="scout-team-details__row">';
				foreach ( $info['cols'] + array( 'source' => __( 'Source', 'scout-team' ) ) as $col => $label ) {
					$id    = 'st-' . $group . '-' . $i . '-' . $col;
					$type  = 'url' === $col ? 'url' : 'text';
					$extra = 'year' === $col ? ' inputmode="numeric" pattern="[0-9]{4}" maxlength="4"' : '';
					$hint  = 'source' === $col ? ' placeholder="' . esc_attr__( 'Where to check it', 'scout-team' ) . '"' : '';
					$h    .= '<p class="scout-team-details__field is-' . esc_attr( $col ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
						. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'st[' . $group . '][' . $i . '][' . $col . ']' ) . '" value="' . esc_attr( (string) ( $row[ $col ] ?? '' ) ) . '"' . $extra . $hint . '></p>';
				}
				$h .= '</div>';
			}
			$h .= '</fieldset>';
		}

		$h .= '<fieldset><legend>' . esc_html__( 'Links', 'scout-team' ) . '</legend>';
		$h .= '<p class="scout-team-details__field"><label for="st-same-as">' . esc_html__( 'Profile links (optional)', 'scout-team' ) . '</label>'
			. '<textarea id="st-same-as" name="st[same_as]" rows="3" aria-describedby="st-same-as-help" placeholder="https://www.linkedin.com/in/...">' . esc_textarea( implode( "\n", array_map( 'strval', (array) ( $values['same_as'] ?? array() ) ) ) ) . '</textarea>'
			. '<span class="scout-team-details__help" id="st-same-as-help">' . esc_html__( 'One link per line, like LinkedIn or a professional profile.', 'scout-team' ) . '</span></p>';
		$h .= '</fieldset>';

		$h .= '<p><button type="submit" class="scout-team-details__send wp-element-button">' . esc_html__( 'Send my details', 'scout-team' ) . '</button></p>';
		if ( ! empty( $link['expires'] ) ) {
			/* translators: %s: the date the link ends. */
			$h .= '<p class="scout-team-details__help">' . esc_html( sprintf( __( 'This link works once and ends on %s.', 'scout-team' ), wp_date( (string) get_option( 'date_format' ), (int) $link['expires'] ) ) ) . '</p>';
		}
		$h .= '</form>';
		return $h;
	}

	/** One labelled text input named st[$key]. */
	private static function input( string $key, string $label, string $value, string $type = 'text', string $help = '', bool $required = false ): string {
		$id = 'st-' . $key;
		return '<p class="scout-team-details__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'st[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' maxlength="120">'
			. ( '' !== $help ? '<span class="scout-team-details__help">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * Print a page in the theme's frame and stop. Never listed by search
	 * engines, never cached, no referrer, and the site's analytics tag stays off
	 * so the link never leaves the site.
	 *
	 * @param string $title  The page title.
	 * @param string $body   Escaped HTML.
	 * @param int    $status HTTP status.
	 */
	private static function page( string $title, string $body, int $status ): void {
		status_header( $status );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'scout_analytics_is_live', '__return_false', 99 );
		add_filter(
			'pre_get_document_title',
			static function () use ( $title ) {
				return $title;
			}
		);
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'scout-team-details', SCOUT_TEAM_URL . 'assets/scout-team-details.css', array(), SCOUT_TEAM_VERSION );
				if ( function_exists( 'scout_design_css' ) ) {
					wp_add_inline_style( 'scout-team-details', scout_design_css( '--scout-', '.scout-team-details' ) );
				}
			}
		);
		$main = '<main id="scout-team-details" class="scout-team-details">' . $body . '</main>';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'scout-team-details-page' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'header' );
			}
			echo $main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'footer' );
			}
			?>
</div>
<?php wp_footer(); ?>
</body>
</html>
			<?php
		} else {
			get_header();
			echo $main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			get_footer();
		}
		exit;
	}

	/* ---------- the owner's side ---------- */

	/** A signed address that makes a new link (do=new) or turns it off (do=off). */
	public static function action_url( int $post_id, string $what = 'new' ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_LINK . '&do=' . rawurlencode( $what ) . '&post=' . $post_id ), self::ACTION_LINK . '_' . $post_id );
	}

	/** A signed address that approves (do=approve) or discards (do=discard) what they sent. */
	public static function pending_url( int $post_id, string $what ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_PENDING . '&do=' . rawurlencode( $what ) . '&post=' . $post_id ), self::ACTION_PENDING . '_' . $post_id );
	}

	/** The person's edit screen with a note to show. */
	private static function back( int $post_id, string $note ): void {
		wp_safe_redirect( add_query_arg( self::QUERY, $note, (string) get_edit_post_link( $post_id, 'raw' ) ) );
		exit;
	}

	/** The person this request is about, after the nonce and the edit check. */
	private static function checked_post( string $action ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( $action . '_' . $id );
		if ( ! $id || Scout_Team::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You can’t change this person.', 'scout-team' ), 403 );
		}
		return $id;
	}

	public static function handle_link(): void {
		$id   = self::checked_post( self::ACTION_LINK );
		$what = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in checked_post().
		if ( 'off' === $what ) {
			self::revoke( $id );
			self::back( $id, 'off' );
		}
		self::create( $id );
		self::back( $id, 'link' );
	}

	public static function handle_pending(): void {
		$id   = self::checked_post( self::ACTION_PENDING );
		$what = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in checked_post().
		if ( 'approve' === $what ) {
			self::back( $id, self::approve( $id ) ? 'approved' : 'none' );
		}
		self::back( $id, self::discard( $id ) ? 'discarded' : 'none' );
	}

	/** The edit screen: what they sent at the top, the link box on the side. */
	public static function boxes( $post ): void {
		if ( $post instanceof WP_Post && self::pending( (int) $post->ID ) ) {
			add_meta_box( 'scout-team-pending', __( 'Sent their details', 'scout-team' ), array( __CLASS__, 'render_pending' ), Scout_Team::POST_TYPE, 'scout_team_top', 'high' );
		}
		add_meta_box( 'scout-team-link', __( 'Send them a link', 'scout-team' ), array( __CLASS__, 'render_link' ), Scout_Team::POST_TYPE, 'side', 'default' );
	}

	/** One line of a credential list, for the review table. */
	private static function line( string $group, array $row ): string {
		$order = array(
			'education'   => array( 'degree', 'school', 'year' ),
			'awards'      => array( 'name', 'issuer', 'year' ),
			'memberships' => array( 'name' ),
			'bar'         => array( 'state', 'year', 'number', 'url' ),
		);
		$parts = array();
		foreach ( $order[ $group ] ?? array() as $col ) {
			if ( '' !== (string) ( $row[ $col ] ?? '' ) ) {
				$parts[] = (string) $row[ $col ];
			}
		}
		$text = implode( ', ', $parts );
		/* translators: %s: where to check a credential line. */
		return '' !== (string) ( $row['source'] ?? '' ) ? $text . ' (' . sprintf( __( 'source: %s', 'scout-team' ), $row['source'] ) . ')' : $text . ' (' . __( 'no source', 'scout-team' ) . ')';
	}

	/**
	 * The fields that would change: label => [ now, they sent ]. Text only.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function changes( int $post_id ): array {
		$pending = self::pending( $post_id );
		$live    = Scout_Team::profile( $post_id );
		$next    = self::merge( $live, $pending );
		$out     = array();
		$name    = wp_strip_all_tags( (string) get_the_title( $post_id ) );
		if ( '' !== trim( (string) ( $pending['name'] ?? '' ) ) && $name !== $pending['name'] ) {
			$out[ __( 'Name', 'scout-team' ) ] = array( $name, (string) $pending['name'] );
		}
		$labels = array(
			'role'        => __( 'Title', 'scout-team' ),
			'bio'         => __( 'Short bio', 'scout-team' ),
			'bar'         => __( 'Bar admissions', 'scout-team' ),
			'education'   => __( 'Education', 'scout-team' ),
			'awards'      => __( 'Awards and honors', 'scout-team' ),
			'memberships' => __( 'Professional memberships', 'scout-team' ),
			'same_as'     => __( 'Profile links', 'scout-team' ),
		);
		foreach ( $labels as $key => $label ) {
			if ( $live[ $key ] === $next[ $key ] ) {
				continue;
			}
			if ( is_array( $next[ $key ] ) ) {
				$fmt = 'same_as' === $key
					? static fn( array $rows ): string => implode( "\n", array_map( 'strval', $rows ) )
					: static fn( array $rows ): string => implode( "\n", array_map( static fn( $r ) => self::line( $key, (array) $r ), $rows ) );
				$out[ $label ] = array( $fmt( (array) $live[ $key ] ), $fmt( (array) $next[ $key ] ) );
			} else {
				$out[ $label ] = array( (string) $live[ $key ], (string) $next[ $key ] );
			}
		}
		return $out;
	}

	public static function render_pending( $post ): void {
		$id      = (int) $post->ID;
		$pending = self::pending( $id );
		$photo   = (int) ( $pending['photo'] ?? 0 );
		echo '<div class="sh scout-team-form st-pending">';
		$sent = ! empty( $pending['sent'] ) ? wp_date( (string) get_option( 'date_format' ), (int) $pending['sent'] ) : '';
		echo '<p class="sh-status is-warn"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html__( 'Waiting for your OK.', 'scout-team' ) . '</strong> <span>';
		/* translators: %s: date they sent it. */
		echo esc_html( '' !== $sent ? sprintf( __( 'Sent on %s. Nothing changes on the site until you approve it.', 'scout-team' ), $sent ) : __( 'Nothing changes on the site until you approve it.', 'scout-team' ) );
		echo '</span></p>';
		$changes = self::changes( $id );
		if ( $changes || $photo ) {
			echo '<table class="widefat sh-table st-diff"><thead><tr><th scope="col">' . esc_html__( 'Field', 'scout-team' ) . '</th><th scope="col">' . esc_html__( 'Now', 'scout-team' ) . '</th><th scope="col">' . esc_html__( 'They sent', 'scout-team' ) . '</th></tr></thead><tbody>';
			if ( $photo ) {
				$now = get_the_post_thumbnail( $id, array( 96, 96 ), array( 'alt' => '' ) );
				$new = wp_get_attachment_image( $photo, array( 96, 96 ), false, array( 'alt' => '' ) );
				echo '<tr><th scope="row">' . esc_html__( 'Photo', 'scout-team' ) . '</th><td>' . ( $now ? $now : esc_html__( 'None', 'scout-team' ) ) . '</td><td>' . ( $new ? $new : esc_html__( 'Not found', 'scout-team' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
			}
			foreach ( $changes as $label => $pair ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( '' !== $pair[0] ? $pair[0] : __( 'Empty', 'scout-team' ) ) ) . '</td><td>' . nl2br( esc_html( '' !== $pair[1] ? $pair[1] : __( 'Empty', 'scout-team' ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'They sent it back with no changes.', 'scout-team' ) . '</p>';
		}
		echo '<p class="sh-actions st-pending-go"><a class="button" href="' . esc_url( self::pending_url( $id, 'approve' ) ) . '">' . esc_html__( 'Approve', 'scout-team' ) . '</a> ';
		echo '<a class="button sh-quiet sh-danger" href="' . esc_url( self::pending_url( $id, 'discard' ) ) . '">' . esc_html__( 'Discard', 'scout-team' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Approve copies these into the profile. Save your other edits first.', 'scout-team' ) . '</p>';
		echo '</div>';
	}

	public static function render_link( $post ): void {
		$id = (int) $post->ID;
		echo '<div class="sh scout-team-form st-link">';
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the person first. Then you can send them a link to fill in their own details.', 'scout-team' ) . '</p></div>';
			return;
		}
		$key  = self::show_key( $id );
		$url  = (string) get_transient( $key );
		$link = self::record( $id );
		$date = static fn( int $t ): string => wp_date( (string) get_option( 'date_format' ), $t );
		if ( '' !== $url ) {
			delete_transient( $key );
			echo '<p class="st-field"><label for="st-link-url">' . esc_html__( 'Their link', 'scout-team' ) . '</label>';
			echo '<input id="st-link-url" type="text" readonly value="' . esc_attr( $url ) . '"></p>';
			echo '<p><button type="button" class="button" data-st-copy="st-link-url" data-st-done="' . esc_attr__( 'Copied', 'scout-team' ) . '">' . esc_html__( 'Copy the link', 'scout-team' ) . '</button></p>';
			/* translators: %s: the date the link ends. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Send it yourself, by text or email. It works once and ends on %s. You’ll see it only now.', 'scout-team' ), $date( (int) ( $link['expires'] ?? 0 ) ) ) ) . '</p>';
		} elseif ( self::active( $id ) ) {
			/* translators: %s: the date the link ends. */
			echo '<p>' . esc_html( sprintf( __( 'A link is out. It ends on %s.', 'scout-team' ), $date( (int) $link['expires'] ) ) ) . '</p>';
			echo '<p class="sh-actions"><a class="button" href="' . esc_url( self::action_url( $id, 'new' ) ) . '">' . esc_html__( 'Make a new link', 'scout-team' ) . '</a> ';
			echo '<a class="button sh-quiet sh-danger" href="' . esc_url( self::action_url( $id, 'off' ) ) . '">' . esc_html__( 'Turn off the link', 'scout-team' ) . '</a></p>';
			echo '<p class="description">' . esc_html__( 'A new link turns off the old one.', 'scout-team' ) . '</p>';
		} else {
			if ( ! empty( $link['used'] ) ) {
				/* translators: %s: date. */
				echo '<p>' . esc_html( sprintf( __( 'They used their link on %s.', 'scout-team' ), $date( (int) $link['used'] ) ) ) . '</p>';
			} elseif ( ! empty( $link['expires'] ) ) {
				/* translators: %s: date. */
				echo '<p>' . esc_html( sprintf( __( 'Their last link ended on %s.', 'scout-team' ), $date( (int) $link['expires'] ) ) ) . '</p>';
			}
			echo '<p class="description">' . esc_html__( 'They fill in their own name, title, bio, photo, credentials and links. You check it before it goes live.', 'scout-team' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::action_url( $id, 'new' ) ) . '">' . esc_html__( 'Send them a link', 'scout-team' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** One line after an action on the edit screen. */
	public static function notice(): void {
		global $pagenow;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$note = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		if ( 'post.php' !== $pagenow || ! $screen || Scout_Team::POST_TYPE !== $screen->post_type || '' === $note ) {
			return;
		}
		$notes = array(
			'added'     => __( 'Add credentials and links here when you have them.', 'scout-team' ),
			'link'      => __( 'Link made. Copy it from Send them a link and send it yourself.', 'scout-team' ),
			'off'       => __( 'The link is off.', 'scout-team' ),
			'approved'  => __( 'Approved. Their details are in the profile.', 'scout-team' ),
			'discarded' => __( 'Discarded. Nothing on the profile changed.', 'scout-team' ),
			'none'      => __( 'There was nothing waiting.', 'scout-team' ),
		);
		if ( isset( $notes[ $note ] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notes[ $note ] ) . '</p></div>';
		}
	}

	/** People who sent their details and wait for an OK. */
	public static function waiting(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Scout_Team::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Scout_Team::PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/** To do (Scout Build): one item per person waiting for an OK. */
	public static function inbox( $items ) {
		$items = is_array( $items ) ? $items : array();
		foreach ( self::waiting() as $id ) {
			$pending = self::pending( $id );
			$items[] = array(
				'id'      => 'people-details-' . $id,
				'kind'    => 'other',
				'label'   => __( 'Sent their details, waiting for your OK', 'scout-team' ),
				'from'    => wp_strip_all_tags( (string) get_the_title( $id ) ),
				'preview' => __( 'Open their profile to approve or discard what they sent.', 'scout-team' ),
				'when'    => (int) ( $pending['sent'] ?? 0 ),
				'url'     => (string) get_edit_post_link( $id, 'raw' ),
				'waiting' => true,
			);
		}
		return $items;
	}

	/* ---------- privacy tools ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['scout-team-details'] = array(
			'exporter_friendly_name' => __( 'People: details sent by link', 'scout-team' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function eraser( array $erasers ): array {
		$erasers['scout-team-details'] = array(
			'eraser_friendly_name' => __( 'People: details sent by link', 'scout-team' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * People whose details are waiting and who match an email: the profile's
	 * email, its card email, or the email of the login it writes posts as.
	 * The link page asks for no email, so this is how a request finds them.
	 */
	private static function ids_for( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		$out  = array();
		foreach ( self::waiting() as $id ) {
			$p = Scout_Team::profile( $id );
			if ( strtolower( (string) $p['email'] ) === $email || strtolower( (string) $p['card_email'] ) === $email || ( $user && (int) $p['user'] === (int) $user->ID ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		unset( $page );
		$data = array();
		foreach ( self::ids_for( $email ) as $id ) {
			$pending = self::pending( $id );
			$rows    = array();
			foreach ( array( 'name' => __( 'Name', 'scout-team' ), 'role' => __( 'Title', 'scout-team' ), 'bio' => __( 'Short bio', 'scout-team' ) ) as $key => $label ) {
				if ( '' !== (string) ( $pending[ $key ] ?? '' ) ) {
					$rows[] = array( 'name' => $label, 'value' => (string) $pending[ $key ] );
				}
			}
			foreach ( self::columns( true ) as $group => $info ) {
				foreach ( (array) ( $pending[ $group ] ?? array() ) as $row ) {
					$rows[] = array( 'name' => $info['legend'], 'value' => self::line( $group, (array) $row ) );
				}
			}
			if ( ! empty( $pending['same_as'] ) ) {
				$rows[] = array( 'name' => __( 'Profile links', 'scout-team' ), 'value' => implode( ', ', array_map( 'strval', (array) $pending['same_as'] ) ) );
			}
			if ( ! empty( $pending['photo'] ) ) {
				$rows[] = array( 'name' => __( 'Photo', 'scout-team' ), 'value' => (string) wp_get_attachment_url( (int) $pending['photo'] ) );
			}
			if ( ! empty( $pending['sent'] ) ) {
				$rows[] = array( 'name' => __( 'Sent', 'scout-team' ), 'value' => wp_date( 'Y-m-d H:i', (int) $pending['sent'] ) );
			}
			$data[] = array(
				'group_id'    => 'scout-team-details',
				'group_label' => __( 'People: details sent by link', 'scout-team' ),
				'item_id'     => 'person-' . $id,
				'data'        => $rows,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function erase_data( string $email, int $page = 1 ): array {
		unset( $page );
		$ids = self::ids_for( $email );
		foreach ( $ids as $id ) {
			self::discard( $id );
			self::revoke( $id );
		}
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
