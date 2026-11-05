<?php
/**
 * Reviews > Settings: each platform's overall rating and review count, the
 * "leave us a review" links, and an optional disclaimer.
 *
 * Why the totals are typed in here instead of averaged from the reviews on
 * the site: the site shows a hand-picked set. Averaging only those would
 * overstate the rating, which the FTC treats as a misleading review claim.
 * The summary always shows the platform's own numbers. Google sync (0.2.0)
 * will fill the Google row automatically.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Settings {

	const OPTION = 'scout_reviews_settings';
	const PAGE   = 'scout-reviews-settings';

	/** When the totals were last saved (a Unix time). Scout Build's Site health reads it to flag stale counts. */
	const STAMP = 'scout_reviews_totals_updated';

	/** @var bool True while code (not the form) writes the option. */
	private static $internal_write = false;

	public static function defaults(): array {
		return array(
			'profiles'           => array(), // slug => { rating, count, url, write_url }.
			'disclaimer'         => '',
			'load_styles'        => true,
			'auto_add_five'      => false, // Publish new 5-star Google reviews without the inbox.
			'purge_on_uninstall' => false,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One platform's profile: { rating: float, count: int, url, write_url }.
	 */
	public static function profile( string $slug ): array {
		$settings = self::get();
		$profile  = $settings['profiles'][ $slug ] ?? array();
		return array(
			'rating'    => (float) ( $profile['rating'] ?? 0 ),
			'count'     => (int) ( $profile['count'] ?? 0 ),
			'url'       => (string) ( $profile['url'] ?? '' ),
			'write_url' => (string) ( $profile['write_url'] ?? '' ),
		);
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function menu(): void {
		add_submenu_page(
			Scout_Reviews_Menu::parent(),
			__( 'Review settings', 'scout-reviews' ),
			'' !== Scout_Reviews_Menu::build() ? __( 'Review settings', 'scout-reviews' ) : __( 'Settings', 'scout-reviews' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Replace one platform's totals from code (the Google sync), without going
	 * through the form's rules.
	 */
	public static function set_profile( string $slug, array $profile ): void {
		$settings                      = self::get();
		$settings['profiles'][ $slug ] = array_merge( self::profile( $slug ), $profile );
		self::$internal_write          = true;
		update_option( self::OPTION, $settings );
		self::$internal_write = false;
		update_option( self::STAMP, time(), false );
		Scout_Reviews_Query::flush();
	}

	public static function sanitize( $input ): array {
		if ( self::$internal_write && is_array( $input ) ) {
			return $input;
		}
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		foreach ( Scout_Reviews_Sources::all() as $slug => $info ) {
			// While Google syncs, its row is Google's numbers, not the form's.
			if ( 'google' === $slug && Scout_Reviews_Google::is_ready() ) {
				$current = self::get()['profiles']['google'] ?? null;
				if ( $current ) {
					$out['profiles']['google'] = $current;
				}
				continue;
			}
			$row = $input['profiles'][ $slug ] ?? array();
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rating = round( (float) ( $row['rating'] ?? 0 ), 1 );
			$clean  = array(
				'rating'    => ( $rating >= 1 && $rating <= 5 ) ? $rating : 0,
				'count'     => max( 0, (int) ( $row['count'] ?? 0 ) ),
				'url'       => esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'https', 'http' ) ),
				'write_url' => esc_url_raw( trim( (string) ( $row['write_url'] ?? '' ) ), array( 'https', 'http' ) ),
			);
			if ( $clean['rating'] || $clean['count'] || $clean['url'] || $clean['write_url'] ) {
				$out['profiles'][ $slug ] = $clean;
			}
		}

		$out['disclaimer']         = sanitize_textarea_field( (string) ( $input['disclaimer'] ?? '' ) );
		$out['load_styles']        = ! empty( $input['load_styles'] );
		$out['auto_add_five']      = ! empty( $input['auto_add_five'] );
		$out['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		// Saving the form means someone looked at the totals, so note when.
		update_option( self::STAMP, time(), false );
		Scout_Reviews_Query::flush();
		return $out;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		$name     = self::OPTION;
		$sources  = Scout_Reviews_Sources::all();
		unset( $sources['direct'] ); // Direct testimonials have no platform profile.
		$with_link = array();
		foreach ( $sources as $slug => $info ) {
			if ( '' !== self::profile( $slug )['write_url'] ) {
				$with_link[] = $info['label'];
			}
		}
		$stamp = (int) get_option( self::STAMP, 0 );

		Scout_Admin_UI::open( __( 'Review settings', 'scout-reviews' ), __( 'Your profile on each review platform, how reviews look on the site, and where to put them.', 'scout-reviews' ) );

		if ( ! $with_link ) {
			Scout_Reviews_Menu::status( 'warn', __( 'No review link.', 'scout-reviews' ), __( 'Add one below so the review page and printed cards have somewhere to send people.', 'scout-reviews' ) );
		} else {
			$detail = Scout_Reviews_Google::is_ready()
				? __( 'Google syncs once a day.', 'scout-reviews' )
				: ( $stamp
					/* translators: %s: time since the totals were saved, e.g. "3 days". */
					? sprintf( __( 'Totals last updated %s ago.', 'scout-reviews' ), human_time_diff( $stamp ) )
					: __( 'Check the totals below match each platform.', 'scout-reviews' ) );
			Scout_Reviews_Menu::status(
				'good',
				/* translators: %s: list of platforms, e.g. "Google, Yelp". */
				sprintf( __( 'Review links are set for %s.', 'scout-reviews' ), implode( ', ', $with_link ) ),
				$detail
			);
		}

		do_action( 'scout_reviews_settings_top' );
		?>

		<form method="post" action="options.php" novalidate><?php // Fields sit in closed boxes, where a browser cannot show a field error; the values are cleaned on save. ?>
			<?php settings_fields( self::PAGE ); ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Your profile on each platform', 'scout-reviews' ); ?></h2>
				<p><?php esc_html_e( 'Copy each platform\'s overall rating and review count exactly. The rating summary on the site uses them. Leave blank to skip one.', 'scout-reviews' ); ?></p>
				<ul class="sh-rows srk-platforms">
					<?php
					foreach ( $sources as $slug => $info ) :
						$p      = self::profile( $slug );
						$base   = $name . '[profiles][' . $slug . ']';
						$synced = 'google' === $slug && Scout_Reviews_Google::is_ready();
						$lock   = $synced ? ' readonly' : '';
						$id     = 'srk-p-' . sanitize_html_class( $slug );
						$empty  = ! $p['rating'] && ! $p['count'] && '' === $p['url'] && '' === $p['write_url'];
						?>
						<li class="sh-row srk-platform">
							<details>
								<summary>
									<span class="srk-platform-name">
										<span class="sh-row-name"><?php echo esc_html( $info['label'] ); ?></span>
										<span class="sh-row-sub">
											<?php
											if ( $empty ) {
												esc_html_e( 'Not set up', 'scout-reviews' );
											} else {
												$facts = array();
												if ( $info['stars'] && $p['rating'] ) {
													/* translators: %s: rating, e.g. 4.8 */
													$facts[] = sprintf( __( '%s stars', 'scout-reviews' ), number_format_i18n( $p['rating'], 1 ) );
												}
												if ( $p['count'] ) {
													/* translators: %s: number of reviews */
													$facts[] = sprintf( _n( '%s review', '%s reviews', $p['count'], 'scout-reviews' ), number_format_i18n( $p['count'] ) );
												}
												echo esc_html( $facts ? implode( ', ', $facts ) : __( 'No totals yet', 'scout-reviews' ) );
											}
											?>
										</span>
									</span>
									<span class="sh-row-facts">
										<?php if ( $synced ) : ?>
											<span class="sh-tag is-update"><?php esc_html_e( 'Synced', 'scout-reviews' ); ?></span>
										<?php endif; ?>
										<?php if ( '' !== $p['write_url'] ) : ?>
											<span class="sh-tag is-current"><?php esc_html_e( 'Review link', 'scout-reviews' ); ?></span>
										<?php elseif ( ! $empty ) : ?>
											<span class="sh-tag is-held"><?php esc_html_e( 'No review link', 'scout-reviews' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="sh-row-go"><span class="button"><?php echo $synced ? esc_html__( 'See details', 'scout-reviews' ) : ( $empty ? esc_html__( 'Add', 'scout-reviews' ) : esc_html__( 'Change', 'scout-reviews' ) ); ?></span></span>
								</summary>
								<div class="srk-fields">
									<?php if ( $synced ) : ?>
										<p class="sh-note"><?php esc_html_e( 'Google fills these in on every sync, so they cannot be changed here.', 'scout-reviews' ); ?></p>
									<?php endif; ?>
									<div class="srk-fields-row">
										<?php if ( $info['stars'] ) : ?>
											<span class="srk-field">
												<label for="<?php echo esc_attr( $id . '-rating' ); ?>"><?php esc_html_e( 'Overall rating', 'scout-reviews' ); ?></label>
												<input id="<?php echo esc_attr( $id . '-rating' ); ?>" type="number" step="0.1" min="1" max="5" class="srk-num"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[rating]' ); ?>" value="<?php echo $p['rating'] ? esc_attr( (string) $p['rating'] ) : ''; ?>" />
											</span>
										<?php else : ?>
											<span class="srk-field">
												<span class="srk-field-label"><?php esc_html_e( 'Overall rating', 'scout-reviews' ); ?></span>
												<span class="description"><?php esc_html_e( 'No stars', 'scout-reviews' ); ?></span>
											</span>
										<?php endif; ?>
										<span class="srk-field">
											<label for="<?php echo esc_attr( $id . '-count' ); ?>"><?php esc_html_e( 'Total reviews', 'scout-reviews' ); ?></label>
											<input id="<?php echo esc_attr( $id . '-count' ); ?>" type="number" min="0" step="1" class="srk-num"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[count]' ); ?>" value="<?php echo $p['count'] ? esc_attr( (string) $p['count'] ) : ''; ?>" />
										</span>
									</div>
									<span class="srk-field">
										<label for="<?php echo esc_attr( $id . '-url' ); ?>"><?php esc_html_e( 'Profile link (read all reviews)', 'scout-reviews' ); ?></label>
										<input id="<?php echo esc_attr( $id . '-url' ); ?>" type="url" placeholder="https://"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[url]' ); ?>" value="<?php echo esc_attr( $p['url'] ); ?>" />
									</span>
									<span class="srk-field">
										<label for="<?php echo esc_attr( $id . '-write' ); ?>"><?php esc_html_e( 'Leave a review link', 'scout-reviews' ); ?></label>
										<input id="<?php echo esc_attr( $id . '-write' ); ?>" type="url" placeholder="https://"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[write_url]' ); ?>" value="<?php echo esc_attr( $p['write_url'] ); ?>" />
									</span>
								</div>
							</details>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="sh-note"><?php esc_html_e( 'Google\'s "leave a review" link is in your Business Profile under "Ask for reviews." It takes clients straight to the review box.', 'scout-reviews' ); ?></p>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How reviews look', 'scout-reviews' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scout-reviews-disclaimer"><?php esc_html_e( 'Disclaimer (optional)', 'scout-reviews' ); ?></label></th>
						<td>
							<textarea id="scout-reviews-disclaimer" class="large-text" rows="3" name="<?php echo esc_attr( $name . '[disclaimer]' ); ?>"><?php echo esc_textarea( $settings['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown under every reviews section. Some state bars require one with testimonials, so check your state\'s rules.', 'scout-reviews' ); ?></p>
							<?php if ( class_exists( 'Scout_Law_Disclaimers' ) && in_array( 'reviews', Scout_Law_Disclaimers::places_for( 'reviews' ), true ) ) : ?>
								<p class="description"><strong><?php esc_html_e( 'Law firm tools is on, so the wording in Scout → Legal pages → Disclaimers shows under the reviews instead.', 'scout-reviews' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New 5-star reviews', 'scout-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[auto_add_five]' ); ?>" value="1" <?php checked( ! empty( $settings['auto_add_five'] ) ); ?> /> <?php esc_html_e( 'Add new 5-star Google reviews to the pages they match. The rest wait in New reviews.', 'scout-reviews' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave off for law firms: bar rules expect a person to approve each testimonial.', 'scout-reviews' ); ?></p>
						</td>
					</tr>
				</table>
			</section>

			<?php Scout_Reviews_Menu::fold_open( __( 'More options', 'scout-reviews' ) ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in design', 'scout-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[load_styles]' ); ?>" value="1" <?php checked( $settings['load_styles'] ); ?> /> <?php esc_html_e( 'Load the Scout Reviews stylesheet. Turn off only if your theme styles the reviews itself.', 'scout-reviews' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On delete', 'scout-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[purge_on_uninstall]' ); ?>" value="1" <?php checked( $settings['purge_on_uninstall'] ); ?> /> <?php esc_html_e( 'Remove all reviews and settings when the plugin is deleted. Leave off unless you mean it.', 'scout-reviews' ); ?></label>
						</td>
					</tr>
				</table>
			<?php Scout_Reviews_Menu::fold_close(); ?>

			<?php submit_button(); ?>
		</form>

		<?php Scout_Reviews_Menu::fold_open( __( 'Add to a page', 'scout-reviews' ) ); ?>
			<p><?php esc_html_e( 'In the block editor, add the "Scout Reviews" block. Anywhere else, use a shortcode:', 'scout-reviews' ); ?></p>
			<ul class="srk-codes">
				<li><code>[scout_reviews]</code> <?php esc_html_e( 'shows the rating summary and every published review.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews featured="1" count="3"]</code> <?php esc_html_e( 'shows three featured reviews, good for the home page.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews source="google" layout="row"]</code> <?php esc_html_e( 'shows only Google reviews in a sideways-scrolling row.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews summary="0"]</code> <?php esc_html_e( 'hides the rating summary.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews topic="law-firms" count="3"]</code> <?php esc_html_e( 'shows reviews tagged with one topic, or featured reviews until that topic has some.', 'scout-reviews' ); ?></li>
				<li><code>[scout_review_summary]</code> <?php esc_html_e( 'shows only the rating summary.', 'scout-reviews' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Theme developers: call scout_reviews_slot() in a template to show the reviews for that page automatically. See the plugin README.', 'scout-reviews' ); ?></p>
		<?php Scout_Reviews_Menu::fold_close(); ?>
		<?php
		Scout_Admin_UI::close();
	}
}
