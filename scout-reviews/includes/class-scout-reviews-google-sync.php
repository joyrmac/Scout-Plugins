<?php
/**
 * Pulls Google reviews into the Reviews list, once a day and on demand.
 *
 * Rules, so nothing reaches the site without a person looking at it first:
 * - A new review arrives as a draft and is flagged "new" until someone
 *   publishes it or trashes it.
 * - An existing review keeps its published/draft status, featured flag, and
 *   order. Its name, text, stars, date, and photo follow Google, so edits to
 *   those are overwritten on the next sync (Google's terms require showing
 *   review text as written).
 * - A review deleted on Google is unpublished here and flagged "removed".
 * - Star-only reviews with no text are counted in the totals but not
 *   imported: a card with no words says nothing.
 * - The Google rating and review count in Settings are replaced with Google's
 *   own numbers every sync.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Google_Sync {

	const CRON = 'scout_reviews_google_sync';
	const LOCK = 'scout_reviews_google_sync_lock';

	const META_EXTERNAL = '_scout_review_external_id';
	const META_PHOTO    = '_scout_review_photo_url';
	const META_NEW      = '_scout_review_new';
	const META_REMOVED  = '_scout_review_removed';

	const STARS = array(
		'ONE'   => 1,
		'TWO'   => 2,
		'THREE' => 3,
		'FOUR'  => 4,
		'FIVE'  => 5,
	);

	public static function boot(): void {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		// Publishing (or trashing) a new review clears its "new" flag.
		add_action( 'transition_post_status', array( __CLASS__, 'clear_new_flag' ), 10, 3 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function clear_new_flag( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && Scout_Reviews_Post_Type::TYPE === $post->post_type && in_array( $new_status, array( 'publish', 'trash' ), true ) ) {
			delete_post_meta( $post->ID, self::META_NEW );
		}
	}

	/**
	 * Run one full sync.
	 *
	 * @return array|WP_Error { new, updated, removed, skipped, rating, count }.
	 */
	public static function run() {
		if ( ! Scout_Reviews_Google::is_ready() ) {
			return new WP_Error( 'scout_reviews_not_ready', __( 'Connect Google and pick a location first.', 'scout-reviews' ) );
		}
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'scout_reviews_locked', __( 'A sync is already running. Try again in a few minutes.', 'scout-reviews' ) );
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$result = self::sync();

		delete_transient( self::LOCK );
		Scout_Reviews_Google::update(
			array(
				'last_sync'   => time(),
				'last_error'  => is_wp_error( $result ) ? $result->get_error_message() : '',
				'last_result' => is_wp_error( $result ) ? array() : $result,
			)
		);
		Scout_Reviews_Query::flush();
		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function sync() {
		$seen    = array();
		$counts  = array( 'new' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0, 'rating' => 0.0, 'count' => 0 );
		$page    = '';
		$guard   = 0;
		$profile = '';

		// Sign-in problems stop the sync with Google's own explanation.
		$token = Scout_Reviews_Google::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$links = Scout_Reviews_Google::location_links();
		if ( ! is_wp_error( $links ) ) {
			$profile = $links['maps'];
		}

		do {
			$data = Scout_Reviews_Google::reviews_page( $page );
			if ( is_wp_error( $data ) ) {
				return $data; // Nothing is unpublished on a partial read.
			}
			if ( '' === $page ) {
				$counts['rating'] = round( (float) ( $data['averageRating'] ?? 0 ), 1 );
				$counts['count']  = (int) ( $data['totalReviewCount'] ?? 0 );
			}
			foreach ( (array) ( $data['reviews'] ?? array() ) as $review ) {
				$id = (string) ( $review['reviewId'] ?? '' );
				if ( '' === $id ) {
					continue;
				}
				$seen[ $id ] = true;
				$outcome     = self::upsert( $review, $profile );
				++$counts[ $outcome ];
			}
			$page = (string) ( $data['nextPageToken'] ?? '' );
		} while ( '' !== $page && ++$guard < 100 );

		$counts['removed'] = self::retire_missing( $seen );

		Scout_Reviews_Settings::set_profile(
			'google',
			array_filter(
				array(
					'rating'    => $counts['rating'],
					'count'     => $counts['count'],
					'url'       => is_wp_error( $links ) ? '' : $links['maps'],
					'write_url' => is_wp_error( $links ) ? '' : $links['write'],
				),
				static function ( $v ) {
					return '' !== $v;
				}
			)
		);

		return $counts;
	}

	/**
	 * Create or refresh one review.
	 *
	 * @return string new | updated | skipped
	 */
	private static function upsert( array $review, string $profile_url ): string {
		$text = self::clean_comment( (string) ( $review['comment'] ?? '' ) );
		if ( '' === $text ) {
			return 'skipped';
		}

		$reviewer = (array) ( $review['reviewer'] ?? array() );
		$name     = ! empty( $reviewer['isAnonymous'] ) || empty( $reviewer['displayName'] )
			? __( 'A Google user', 'scout-reviews' )
			: (string) $reviewer['displayName'];
		$rating   = self::STARS[ (string) ( $review['starRating'] ?? '' ) ] ?? 0;
		$created  = strtotime( (string) ( $review['createTime'] ?? '' ) );
		$date     = $created ? gmdate( 'Y-m-d', $created ) : '';
		$photo    = esc_url_raw( (string) ( $reviewer['profilePhotoUrl'] ?? '' ), array( 'https' ) );
		$id       = (string) $review['reviewId'];

		$existing = get_posts(
			array(
				'post_type'      => Scout_Reviews_Post_Type::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EXTERNAL, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$postarr = array(
			'post_type'    => Scout_Reviews_Post_Type::TYPE,
			'post_title'   => $name,
			'post_content' => $text,
		);

		if ( $existing ) {
			$post_id         = (int) $existing[0];
			$postarr['ID']   = $post_id;
			$current         = get_post( $post_id );
			$changed         = $current && ( $current->post_title !== $name || $current->post_content !== $text );
			if ( $changed ) {
				wp_update_post( wp_slash( $postarr ) );
			}
			$outcome = 'updated';
		} else {
			$postarr['post_status'] = 'draft';
			$post_id                = (int) wp_insert_post( wp_slash( $postarr ) );
			if ( ! $post_id ) {
				return 'skipped';
			}
			update_post_meta( $post_id, self::META_EXTERNAL, $id );
			update_post_meta( $post_id, self::META_NEW, 1 );
			update_post_meta( $post_id, Scout_Reviews_Post_Type::META_SOURCE, 'google' );
			update_post_meta( $post_id, Scout_Reviews_Post_Type::META_FEATURED, false );
			$outcome = 'new';
		}

		update_post_meta( $post_id, Scout_Reviews_Post_Type::META_RATING, $rating );
		update_post_meta( $post_id, Scout_Reviews_Post_Type::META_DATE, $date );
		update_post_meta( $post_id, self::META_PHOTO, $photo );
		// Google gives no public link to a single review; the profile is where anyone can read it.
		update_post_meta( $post_id, Scout_Reviews_Post_Type::META_URL, esc_url_raw( $profile_url ) );
		delete_post_meta( $post_id, self::META_REMOVED );

		return $outcome;
	}

	/**
	 * Unpublish synced reviews that are no longer on Google.
	 *
	 * @param array<string, true> $seen Review IDs Google returned this sync.
	 */
	private static function retire_missing( array $seen ): int {
		$synced = get_posts(
			array(
				'post_type'      => Scout_Reviews_Post_Type::TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EXTERNAL, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare'   => 'EXISTS',
			)
		);
		$removed = 0;
		foreach ( $synced as $post_id ) {
			$id = (string) get_post_meta( $post_id, self::META_EXTERNAL, true );
			if ( isset( $seen[ $id ] ) || get_post_meta( $post_id, self::META_REMOVED, true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::META_REMOVED, 1 );
			if ( 'publish' === get_post_status( $post_id ) ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			}
			++$removed;
		}
		return $removed;
	}

	/**
	 * Google appends machine translations to some reviews:
	 * "(Translated by Google) ... (Original) ..." or the reverse. Keep only the
	 * reviewer's own words.
	 */
	public static function clean_comment( string $comment ): string {
		$comment = trim( $comment );
		$orig    = strpos( $comment, '(Original)' );
		if ( false !== $orig ) {
			return trim( substr( $comment, $orig + strlen( '(Original)' ) ) );
		}
		$translated = strpos( $comment, '(Translated by Google)' );
		if ( false !== $translated ) {
			return trim( substr( $comment, 0, $translated ) );
		}
		return $comment;
	}

	public static function is_synced( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::META_EXTERNAL, true );
	}

	/**
	 * How many synced reviews are waiting for a first look.
	 */
	public static function new_count(): int {
		$ids = get_posts(
			array(
				'post_type'      => Scout_Reviews_Post_Type::TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return count( $ids );
	}
}
