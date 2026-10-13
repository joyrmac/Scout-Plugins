<?php
/**
 * The client setup file.
 *
 * A Scout client theme can carry `scout-setup.json` with the client's own
 * facts: team, review page, chat, hours. When the matching feature is on,
 * this fills it in so nobody retypes it in the dashboard. Each section runs
 * again only when its contents change (a new theme version), and it only
 * fills blanks: anything someone already set in WordPress stays as it is.
 *
 * Format (every section optional):
 * {
 *   "team":    [ { "name", "role", "bio", "email", "military_branch", "photo", "order" } ],
 *   "reviews": { "business", "headline", "ask", "photo", "card_headline", "google_review_url",
 *                "background", "text_color", "accent", "heading_font", "body_font", "label_font" },
 *   "chat":    { "name", "team", "notify", "book_url", "work_url", "photo_1", "photo_2" },
 *   "hours":   { "confirmed": true, "days": { "tuesday": [ ["09:00", "17:00"] ] } },
 *   "analytics": { "gtm": "GTM-XXXXXXX" }
 * }
 * "photo" values are a path inside the theme (assets/img/joy.webp) or a full https:// address.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Setup {

	const FILE  = 'scout-setup.json';
	const DONE  = 'scout_hub_setup_done';  // section => hash of what was applied.
	const MEDIA = 'scout_hub_setup_media'; // theme path => attachment ID.

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'run' ), 30 );
	}

	/** The theme's setup file (child theme first), or an empty array. */
	public static function data(): array {
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$path = trailingslashit( $dir ) . self::FILE;
			if ( is_readable( $path ) ) {
				$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
				return is_array( $json ) ? $json : array();
			}
		}
		return array();
	}

	public static function run(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		$data = self::data();
		if ( ! $data ) {
			return;
		}
		$done = (array) get_option( self::DONE, array() );
		$jobs = array(
			'team'    => array( __CLASS__, 'team' ),
			'reviews' => array( __CLASS__, 'reviews' ),
			'chat'    => array( __CLASS__, 'chat' ),
			'hours'   => array( __CLASS__, 'hours' ),
			'analytics' => array( __CLASS__, 'analytics' ),
		);
		$changed = false;
		foreach ( $jobs as $section => $job ) {
			if ( empty( $data[ $section ] ) || ! is_array( $data[ $section ] ) ) {
				continue;
			}
			$hash = md5( (string) wp_json_encode( $data[ $section ] ) );
			if ( ( $done[ $section ] ?? '' ) === $hash ) {
				continue;
			}
			// A job returns false while its feature is off, so it runs once the feature is turned on.
			if ( call_user_func( $job, $data[ $section ] ) ) {
				$done[ $section ] = $hash;
				$changed          = true;
			}
		}
		if ( $changed ) {
			update_option( self::DONE, $done, false );
		}
	}

	/* ---------- sections ---------- */

	private static function team( array $people ): bool {
		if ( ! post_type_exists( 'scout_team' ) || ! class_exists( 'Scout_Team' ) ) {
			return false;
		}
		foreach ( $people as $p ) {
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$found = get_posts(
				array(
					'post_type'   => 'scout_team',
					'title'       => $name,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$id    = $found ? (int) $found[0] : 0;
			if ( ! $id ) {
				$id = (int) wp_insert_post(
					array(
						'post_type'    => 'scout_team',
						'post_status'  => 'publish',
						'post_title'   => $name,
						'post_content' => (string) ( $p['bio'] ?? '' ),
						'menu_order'   => (int) ( $p['order'] ?? 0 ),
					)
				);
				if ( ! $id ) {
					continue;
				}
			}
			// Fill only the blanks of an existing profile.
			$profile = Scout_Team::profile( $id );
			$email   = (string) ( $p['email'] ?? '' );
			$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
			$add     = array(
				'role'            => (string) ( $p['role'] ?? '' ),
				'bio'             => (string) ( $p['bio'] ?? '' ),
				'email'           => $email,
				'user'            => $user ? $user->ID : 0,
				'military_branch' => (string) ( $p['military_branch'] ?? '' ),
			);
			foreach ( $add as $key => $value ) {
				if ( empty( $profile[ $key ] ) && ! empty( $value ) ) {
					$profile[ $key ] = $value;
				}
			}
			// The sanitizer takes these two lists as one-per-line text.
			foreach ( array( 'memberships', 'same_as' ) as $list ) {
				$profile[ $list ] = implode( "\n", (array) $profile[ $list ] );
			}
			update_post_meta( $id, Scout_Team::META, Scout_Team::sanitize_profile( $profile ) );
			if ( ! has_post_thumbnail( $id ) && ! empty( $p['photo'] ) ) {
				$att = self::attachment( (string) $p['photo'] );
				if ( $att ) {
					set_post_thumbnail( $id, $att );
				}
			}
		}
		return true;
	}

	private static function reviews( array $in ): bool {
		if ( ! class_exists( 'Scout_Reviews_Requests' ) ) {
			return false;
		}
		$keys = array( 'business', 'headline', 'ask', 'card_headline', 'background', 'text_color', 'accent', 'heading_font', 'body_font', 'label_font' );
		$fill = array_intersect_key( $in, array_flip( $keys ) );
		if ( ! empty( $in['photo'] ) ) {
			$fill['photo'] = self::url( (string) $in['photo'] );
		}
		self::fill_option( Scout_Reviews_Requests::OPTION, array_map( 'sanitize_text_field', $fill ) );

		$write = esc_url_raw( (string) ( $in['google_review_url'] ?? '' ), array( 'https' ) );
		if ( '' !== $write && class_exists( 'Scout_Reviews_Settings' ) ) {
			$saved = get_option( Scout_Reviews_Settings::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			if ( empty( $saved['profiles']['google']['write_url'] ) ) {
				$saved['profiles']['google']['write_url'] = $write;
				update_option( Scout_Reviews_Settings::OPTION, $saved );
			}
		}
		return true;
	}

	private static function chat( array $in ): bool {
		if ( ! class_exists( 'Scout_Chat' ) ) {
			return false;
		}
		$fill = array();
		foreach ( array( 'name', 'team', 'notify', 'book_url', 'work_url', 'photo_1', 'photo_2' ) as $key ) {
			if ( empty( $in[ $key ] ) ) {
				continue;
			}
			$value = (string) $in[ $key ];
			if ( 'notify' === $key ) {
				$value = sanitize_email( $value );
			} elseif ( 0 === strpos( $key, 'photo_' ) ) {
				$value = self::url( $value );
			} elseif ( '_url' === substr( $key, -4 ) ) {
				$value = esc_url_raw( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$fill[ $key ] = $value;
		}
		self::fill_option( Scout_Chat::OPTION, $fill );
		return true;
	}

	private static function hours( array $in ): bool {
		if ( ! class_exists( 'Scout_Hours' ) ) {
			return false;
		}
		// Hours are all or nothing: only fill a site that has none saved yet.
		$saved = get_option( Scout_Hours::OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['days'] ) ) {
			return true;
		}
		$days = array();
		foreach ( (array) ( $in['days'] ?? array() ) as $day => $ranges ) {
			$day = sanitize_key( (string) $day );
			$out = array();
			foreach ( (array) $ranges as $r ) {
				if ( is_array( $r ) && 2 === count( $r ) ) {
					$out[] = array( sanitize_text_field( (string) $r[0] ), sanitize_text_field( (string) $r[1] ) );
				}
			}
			$days[ $day ] = array(
				'open'   => (bool) $out,
				'ranges' => $out,
			);
		}
		update_option(
			Scout_Hours::OPTION,
			array(
				'confirmed' => ! empty( $in['confirmed'] ),
				'bar'       => 'off',
				'days'      => $days,
			)
		);
		return true;
	}

	private static function analytics( array $in ): bool {
		if ( ! class_exists( 'Scout_Analytics' ) ) {
			return false;
		}
		$gtm = strtoupper( trim( (string) ( $in['gtm'] ?? '' ) ) );
		if ( Scout_Analytics::valid_gtm( $gtm ) ) {
			self::fill_option( Scout_Analytics::OPTION, array( 'gtm' => $gtm ) );
		}
		return true;
	}

	/* ---------- helpers ---------- */

	/** Set keys that are missing or blank in an option; leave everything else. */
	private static function fill_option( string $option, array $fill ): void {
		$saved = get_option( $option, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$dirty = false;
		foreach ( $fill as $key => $value ) {
			if ( '' !== $value && ( ! isset( $saved[ $key ] ) || '' === $saved[ $key ] ) ) {
				$saved[ $key ] = $value;
				$dirty         = true;
			}
		}
		if ( $dirty ) {
			update_option( $option, $saved );
		}
	}

	/** A theme path becomes a Media Library item once; a full address is used as is. */
	private static function attachment( string $photo ): int {
		if ( preg_match( '#^https?://#', $photo ) ) {
			$id = attachment_url_to_postid( $photo );
			return $id ? (int) $id : 0;
		}
		$path  = ltrim( str_replace( '..', '', $photo ), '/' );
		$media = (array) get_option( self::MEDIA, array() );
		if ( ! empty( $media[ $path ] ) && get_post( (int) $media[ $path ] ) ) {
			return (int) $media[ $path ];
		}
		$file = get_stylesheet_directory() . '/' . $path;
		if ( ! is_readable( $file ) ) {
			$file = get_template_directory() . '/' . $path;
		}
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_upload_bits( basename( $file ), null, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		if ( ! empty( $up['error'] ) ) {
			return 0;
		}
		$type = wp_check_filetype( $up['file'] );
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $type['type'],
				'post_title'     => sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		$media[ $path ] = (int) $id;
		update_option( self::MEDIA, $media, false );
		return (int) $id;
	}

	private static function url( string $photo ): string {
		if ( preg_match( '#^https?://#', $photo ) ) {
			return esc_url_raw( $photo );
		}
		$id = self::attachment( $photo );
		return $id ? (string) wp_get_attachment_url( $id ) : '';
	}
}
