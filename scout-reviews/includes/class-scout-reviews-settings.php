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

	/** @var bool True while code (not the form) writes the option. */
	private static $internal_write = false;

	public static function defaults(): array {
		return array(
			'profiles'           => array(), // slug => { rating, count, url, write_url }.
			'disclaimer'         => '',
			'load_styles'        => true,
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
			'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE,
			__( 'Review settings', 'scout-reviews' ),
			__( 'Settings', 'scout-reviews' ),
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
		$out['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		Scout_Reviews_Query::flush();
		return $out;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		$name     = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review settings', 'scout-reviews' ); ?></h1>

			<?php do_action( 'scout_reviews_settings_top' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<h2><?php esc_html_e( 'Your profile on each platform', 'scout-reviews' ); ?></h2>
				<p class="description" style="max-width:720px;">
					<?php esc_html_e( 'Copy the overall rating and total review count exactly as each platform shows them. The rating summary on the site uses these numbers, so visitors see your real totals, not just the reviews you picked. Leave a row empty to skip that platform.', 'scout-reviews' ); ?>
				</p>

				<table class="widefat striped" style="max-width:1100px;margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Platform', 'scout-reviews' ); ?></th>
							<th><?php esc_html_e( 'Overall rating', 'scout-reviews' ); ?></th>
							<th><?php esc_html_e( 'Total reviews', 'scout-reviews' ); ?></th>
							<th><?php esc_html_e( 'Profile link (read all reviews)', 'scout-reviews' ); ?></th>
							<th><?php esc_html_e( 'Leave a review link', 'scout-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( Scout_Reviews_Sources::all() as $slug => $info ) :
							if ( 'direct' === $slug ) {
								continue; // Direct testimonials have no platform profile.
							}
							$p      = self::profile( $slug );
							$base   = $name . '[profiles][' . $slug . ']';
							$synced = 'google' === $slug && Scout_Reviews_Google::is_ready();
							$lock   = $synced ? ' readonly' : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $info['label'] ); ?></strong>
									<?php if ( $synced ) : ?>
										<br /><span class="description"><?php esc_html_e( 'Synced', 'scout-reviews' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $info['stars'] ) : ?>
										<input type="number" step="0.1" min="1" max="5" style="width:80px;"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[rating]' ); ?>" value="<?php echo $p['rating'] ? esc_attr( (string) $p['rating'] ) : ''; ?>" />
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No stars', 'scout-reviews' ); ?></span>
									<?php endif; ?>
								</td>
								<td><input type="number" min="0" step="1" style="width:90px;"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[count]' ); ?>" value="<?php echo $p['count'] ? esc_attr( (string) $p['count'] ) : ''; ?>" /></td>
								<td><input type="url" class="regular-text" placeholder="https://"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[url]' ); ?>" value="<?php echo esc_attr( $p['url'] ); ?>" /></td>
								<td><input type="url" class="regular-text" placeholder="https://"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[write_url]' ); ?>" value="<?php echo esc_attr( $p['write_url'] ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Google\'s "leave a review" link is in your Business Profile under "Ask for reviews." It takes clients straight to the review box.', 'scout-reviews' ); ?>
				</p>

				<h2 style="margin-top:32px;"><?php esc_html_e( 'Display', 'scout-reviews' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scout-reviews-disclaimer"><?php esc_html_e( 'Disclaimer (optional)', 'scout-reviews' ); ?></label></th>
						<td>
							<textarea id="scout-reviews-disclaimer" class="large-text" rows="3" name="<?php echo esc_attr( $name . '[disclaimer]' ); ?>"><?php echo esc_textarea( $settings['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown under every reviews section. Law firms: your state bar may require one with testimonials, for example "Past results do not guarantee a similar outcome." Check your state\'s rules.', 'scout-reviews' ); ?></p>
						</td>
					</tr>
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

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Putting reviews on a page', 'scout-reviews' ); ?></h2>
			<p><?php esc_html_e( 'In the block editor, add the "Scout Reviews" block. Anywhere else, use a shortcode:', 'scout-reviews' ); ?></p>
			<ul style="list-style:disc;padding-left:20px;">
				<li><code>[scout_reviews]</code> <?php esc_html_e( 'shows the rating summary and every published review.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews featured="1" count="3"]</code> <?php esc_html_e( 'shows three featured reviews, good for the home page.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews source="google" layout="row"]</code> <?php esc_html_e( 'shows only Google reviews in a sideways-scrolling row.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews summary="0"]</code> <?php esc_html_e( 'hides the rating summary.', 'scout-reviews' ); ?></li>
				<li><code>[scout_reviews topic="law-firms" count="3"]</code> <?php esc_html_e( 'shows reviews tagged with one topic, or featured reviews until that topic has some.', 'scout-reviews' ); ?></li>
				<li><code>[scout_review_summary]</code> <?php esc_html_e( 'shows only the rating summary.', 'scout-reviews' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Theme developers: call scout_reviews_slot() in a template to show the reviews for that page automatically. See the plugin README.', 'scout-reviews' ); ?></p>
		</div>
		<?php
	}
}
