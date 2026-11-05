<?php
/**
 * Reviews > New reviews: new reviews pulled from Google and notes sent from the
 * review page (/review/note/), one row each, with "Approve and add to site"
 * and "Skip".
 * A note the client did not OK for the site can only be marked as read.
 *
 * "Add to site" publishes the review with the checked topics (suggested ones
 * are pre-checked), so it lands on the right pages in one click. "Skip"
 * keeps it as a hidden draft, out of the inbox.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Inbox {

	const PAGE         = 'scout-reviews-inbox';
	const META_SKIPPED = '_scout_review_skipped';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), Scout_Reviews_Menu::PRIORITY + 1 );
		add_action( 'admin_post_scout_reviews_inbox', array( __CLASS__, 'handle' ) );
	}

	public static function url(): string {
		return Scout_Reviews_Menu::url( self::PAGE );
	}

	public static function menu(): void {
		$count = Scout_Reviews_Google_Sync::new_count();
		$build = Scout_Reviews_Menu::build();
		$label = __( 'New reviews', 'scout-reviews' );
		if ( $count ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $count . '</span></span>';
		}
		add_submenu_page(
			Scout_Reviews_Menu::parent(),
			__( 'New reviews', 'scout-reviews' ),
			$label,
			'edit_posts',
			self::PAGE,
			array( __CLASS__, 'page' ),
			'' !== $build ? null : 1 // First under its own Reviews menu; in order inside Build.
		);
	}

	/**
	 * @return WP_Post[]
	 */
	public static function items(): array {
		return get_posts(
			array(
				'post_type'      => Scout_Reviews_Post_Type::TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 100,
				'meta_key'       => Scout_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$items  = self::items();
		$topics = Scout_Reviews_Topics::all();
		$count  = count( $items );

		Scout_Admin_UI::open(
			__( 'New reviews', 'scout-reviews' ),
			__( 'New Google reviews and notes from your review page wait here for your OK.', 'scout-reviews' )
		);

		if ( ! $items ) {
			Scout_Reviews_Menu::status(
				'good',
				__( 'All caught up.', 'scout-reviews' ),
				Scout_Reviews_Google::is_ready()
					? __( 'New Google reviews show up after the daily sync, and review page notes arrive right away.', 'scout-reviews' )
					: __( 'Review page notes show up here, and Google reviews join them once Google is connected.', 'scout-reviews' )
			);
			Scout_Admin_UI::close();
			return;
		}

		// The status line is a paragraph, so its button points at a form printed after it.
		$all = $count > 1
			? '<button type="submit" form="srk-add-all" class="button">' . esc_html__( 'Add all with suggested pages', 'scout-reviews' ) . '</button>'
			: '';
		Scout_Reviews_Menu::status(
			'warn',
			/* translators: %d: number of new reviews and notes. */
			sprintf( _n( '%d new review waiting.', '%d new reviews waiting.', $count, 'scout-reviews' ), $count ),
			$count > 1
				? __( 'Check each one below, or add every one that can be shown to its suggested pages.', 'scout-reviews' )
				: __( 'Look it over below, then add it to the site or skip it.', 'scout-reviews' ),
			$all
		);
		if ( $count > 1 ) :
			?>
			<form id="srk-add-all" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
				<input type="hidden" name="action" value="scout_reviews_inbox" />
				<input type="hidden" name="do" value="add_all" />
				<?php wp_nonce_field( 'scout_reviews_inbox' ); ?>
			</form>
			<?php
		endif;
		?>
		<?php if ( ! $topics ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No topics exist yet, so reviews will be added without one. They still show anywhere that displays all reviews.', 'scout-reviews' ); ?></p></div>
		<?php endif; ?>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Waiting for a look', 'scout-reviews' ); ?></h2>
			<ul class="sh-rows is-stacked srk-inbox">
				<?php foreach ( $items as $post ) : ?>
					<?php self::card( $post, $topics ); ?>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		Scout_Admin_UI::close();
	}

	/**
	 * One row per review: who, stars, platform, a short line of the text and
	 * the date, then one main button. The full text and the pages it shows on
	 * sit in a closed box under the row; those fields belong to the row's form.
	 *
	 * @param WP_Term[] $topics
	 */
	private static function card( WP_Post $post, array $topics ): void {
		$rating    = (int) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_RATING, true );
		$date      = Scout_Reviews_Render::date( (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_DATE, true ) );
		$detail    = (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_DETAIL, true );
		$suggested = Scout_Reviews_Topics::suggest( $post->post_content );
		$website   = Scout_Reviews_Notes::from_website( $post->ID );
		$allowed   = Scout_Reviews_Notes::can_publish( $post->ID );
		$source    = Scout_Reviews_Sources::label( (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_SOURCE, true ) );
		$form      = 'srk-review-' . (int) $post->ID;
		$who       = get_the_title( $post ) . ( '' !== $detail ? ', ' . $detail : '' );
		$picked    = array();
		foreach ( $topics as $term ) {
			if ( in_array( $term->term_id, $suggested, true ) ) {
				$picked[] = $term->name;
			}
		}
		?>
		<li class="sh-row srk-review">
			<div>
				<p class="sh-row-name"><?php echo esc_html( $who ); ?>
					<?php if ( $rating ) : ?>
						<span class="srk-stars" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars */ __( '%d out of 5 stars', 'scout-reviews' ), $rating ) ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></span>
					<?php endif; ?>
				</p>
				<p class="sh-row-sub"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 ) ); ?></p>
			</div>
			<div class="sh-row-facts">
				<span class="sh-tag"><?php echo esc_html( $website ? __( 'From the website', 'scout-reviews' ) : ( $source ? $source : 'Google' ) ); ?></span>
				<?php if ( $date ) : ?>
					<span><?php echo esc_html( $date ); ?></span>
				<?php endif; ?>
				<?php if ( $website ) : ?>
					<?php if ( $allowed ) : ?>
						<span class="sh-pill is-on"><?php esc_html_e( 'OK to show on the site', 'scout-reviews' ); ?></span>
					<?php else : ?>
						<span class="sh-pill"><?php esc_html_e( 'Private: not OK to show', 'scout-reviews' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="sh-row-go">
				<form id="<?php echo esc_attr( $form ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="scout_reviews_inbox" />
					<input type="hidden" name="review" value="<?php echo (int) $post->ID; ?>" />
					<input type="hidden" name="topics_sent" value="1" />
					<?php wp_nonce_field( 'scout_reviews_inbox', '_wpnonce', true, true ); ?>
					<?php if ( $allowed ) : ?>
						<button type="submit" name="do" value="add" class="button"><?php esc_html_e( 'Approve and add to site', 'scout-reviews' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( $post ) ); ?></span></button>
						<button type="submit" name="do" value="skip" class="button sh-quiet"><?php esc_html_e( 'Skip', 'scout-reviews' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( $post ) ); ?></span></button>
					<?php else : ?>
						<button type="submit" name="do" value="skip" class="button"><?php esc_html_e( 'Mark as read', 'scout-reviews' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( $post ) ); ?></span></button>
					<?php endif; ?>
				</form>
			</div>
			<details class="srk-review-more">
				<summary>
					<?php
					if ( $allowed && $topics ) {
						echo esc_html(
							$picked
								/* translators: %s: list of suggested pages. */
								? sprintf( __( 'Read it all and pick pages. Suggested: %s', 'scout-reviews' ), implode( ', ', $picked ) )
								: __( 'Read it all and pick pages', 'scout-reviews' )
						);
					} else {
						esc_html_e( 'Read it all', 'scout-reviews' );
					}
					?>
				</summary>
				<blockquote class="srk-quote"><?php echo wpautop( esc_html( $post->post_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped before wpautop. ?></blockquote>
				<?php if ( $allowed && $topics ) : ?>
					<fieldset class="srk-topics">
						<legend><?php esc_html_e( 'Show on', 'scout-reviews' ); ?></legend>
						<?php foreach ( $topics as $term ) : ?>
							<?php $is = in_array( $term->term_id, $suggested, true ); ?>
							<label>
								<input type="checkbox" form="<?php echo esc_attr( $form ); ?>" name="topics[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( $is ); ?> />
								<?php echo esc_html( $term->name ); ?><?php echo $is ? ' <span class="sh-note">' . esc_html__( '(suggested)', 'scout-reviews' ) . '</span>' : ''; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<?php if ( $allowed ) : ?>
					<label class="srk-featured"><input type="checkbox" form="<?php echo esc_attr( $form ); ?>" name="featured" value="1" /> <?php esc_html_e( 'Featured', 'scout-reviews' ); ?></label>
				<?php endif; ?>
				<?php if ( $website ) : ?>
					<p class="sh-note"><a href="<?php echo esc_url( Scout_Reviews_Menu::url( Scout_Reviews_Requests::PAGE, 'srk-messages' ) ); ?>"><?php esc_html_e( 'Ask for a Google review too ›', 'scout-reviews' ); ?></a></p>
				<?php endif; ?>
			</details>
		</li>
		<?php
	}

	public static function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-reviews' ), 403 );
		}
		check_admin_referer( 'scout_reviews_inbox' );

		$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );

		if ( 'add_all' === $do ) {
			$added = 0;
			foreach ( self::items() as $post ) {
				if ( current_user_can( 'publish_post', $post->ID ) && self::add( $post->ID, Scout_Reviews_Topics::suggest( $post->post_content ), false ) ) {
					++$added;
				}
			}
			/* translators: %d: number of reviews. */
			self::back( sprintf( _n( '%d review added to the site.', '%d reviews added to the site.', $added, 'scout-reviews' ), $added ) );
		}

		$id   = (int) ( $_POST['review'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || Scout_Reviews_Post_Type::TYPE !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			self::back( __( 'That review could not be found.', 'scout-reviews' ), 'error' );
		}

		if ( 'skip' === $do ) {
			delete_post_meta( $id, Scout_Reviews_Google_Sync::META_NEW );
			update_post_meta( $id, self::META_SKIPPED, 1 );
			/* translators: %s: reviewer name. */
			self::back( sprintf( __( 'Skipped the review from %s. It is saved as a hidden draft.', 'scout-reviews' ), get_the_title( $post ) ) );
		}

		if ( 'add' === $do ) {
			if ( ! current_user_can( 'publish_post', $id ) ) {
				self::back( __( 'You do not have permission to publish reviews.', 'scout-reviews' ), 'error' );
			}
			if ( ! Scout_Reviews_Notes::can_publish( $id ) ) {
				self::back( __( 'This note is private. The client did not say it was OK to show it on the site.', 'scout-reviews' ), 'error' );
			}
			$topics = array_map( 'intval', (array) ( $_POST['topics'] ?? array() ) );
			self::add( $id, $topics, ! empty( $_POST['featured'] ) );
			$names = array();
			foreach ( $topics as $tid ) {
				$term = get_term( $tid, Scout_Reviews_Topics::TAX );
				if ( $term instanceof WP_Term ) {
					$names[] = $term->name;
				}
			}
			$where = $names ? implode( ', ', $names ) : __( 'sections that show all reviews', 'scout-reviews' );
			/* translators: 1: reviewer name, 2: list of topics. */
			self::back( sprintf( __( 'Added the review from %1$s. It now shows on: %2$s.', 'scout-reviews' ), get_the_title( $post ), $where ) );
		}

		self::back();
	}

	/**
	 * Publish one review with these topics.
	 *
	 * @param int[] $topic_ids
	 */
	public static function add( int $post_id, array $topic_ids, bool $featured ): bool {
		$valid = array();
		foreach ( $topic_ids as $tid ) {
			if ( get_term( $tid, Scout_Reviews_Topics::TAX ) instanceof WP_Term ) {
				$valid[] = (int) $tid;
			}
		}
		wp_set_object_terms( $post_id, $valid, Scout_Reviews_Topics::TAX );
		if ( $featured ) {
			update_post_meta( $post_id, Scout_Reviews_Post_Type::META_FEATURED, true );
		}
		delete_post_meta( $post_id, self::META_SKIPPED );
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
		return ! is_wp_error( $result ) && $result;
	}

	private static function back( string $message = '', string $type = 'success' ): void {
		if ( '' !== $message ) {
			set_transient( Scout_Reviews_Google_Admin::NOTICE . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url() );
		exit;
	}
}
