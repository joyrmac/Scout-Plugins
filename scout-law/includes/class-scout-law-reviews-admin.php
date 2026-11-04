<?php
/**
 * Scout → Policies → Page reviews: which pages are due for an attorney's
 * review, a quick way to record one, the settings, and the review record.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Reviews_Admin {

	const SLUG   = 'scout-page-reviews';
	const SAVE   = 'scout_law_reviews_save';
	const REVIEW = 'scout_law_review_now';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::REVIEW, array( __CLASS__, 'review' ) );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
	}

	/** Page reviews files under Policies, beside Disclaimers and Approvals. */
	public static function nav_group( string $group, string $slug ): string {
		return self::SLUG === strtok( $slug, '&' ) ? 'policies' : $group;
	}

	public static function url( array $args = array() ): string {
		// admin.php?page= opens the screen wherever its menu is. The parent is unknown while a
		// form saves (admin-post.php builds no menu), and options-general.php?page= then fails.
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( Scout_Law_Admin::parent(), __( 'Page reviews', 'scout-law' ), __( 'Page reviews', 'scout-law' ), 'edit_pages', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_LAW_URL . 'assets/scout-admin.css' );
		}
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change review settings.', 'scout-law' ), 403 );
		}
		check_admin_referer( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$types  = isset( $_POST['scout_law_review_types'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['scout_law_review_types'] ) ) : array();
		$months = absint( $_POST['scout_law_review_months'] ?? 6 );
		$show   = ! empty( $_POST['scout_law_review_show'] );
		// phpcs:enable
		update_option(
			Scout_Law_Reviews::OPTION,
			array(
				'types'  => array_values( array_intersect( $types, array_keys( Scout_Law_Approvals::choices() ) ) ),
				'months' => in_array( $months, array( 3, 6, 12 ), true ) ? $months : 6,
				'show'   => $show,
			)
		);
		wp_safe_redirect( add_query_arg( 'scout-law', 'saved', self::url() ) );
		exit;
	}

	/** Record a review from the due list. */
	public static function review(): void {
		$post_id = absint( $_POST['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( self::REVIEW . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to record a review of this page.', 'scout-law' ), 403 );
		}
		$ok = Scout_Law_Reviews::record( $post_id, absint( $_POST['attorney'] ?? 0 ) );
		wp_safe_redirect( add_query_arg( 'scout-law', $ok ? 'reviewed' : 'pick', self::url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$settings = Scout_Law_Reviews::settings();
		$people   = Scout_Law_Reviews::attorneys();
		$due      = Scout_Law_Reviews::due();
		Scout_Admin_UI::open(
			__( 'Page reviews', 'scout-law' ),
			__( 'An attorney reads each page on a schedule and signs it. The page then shows who reviewed it and when.', 'scout-law' ),
			__( 'Policies', 'scout-law' )
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only.
		$flag = isset( $_GET['scout-law'] ) ? sanitize_key( wp_unslash( $_GET['scout-law'] ) ) : '';
		$msgs = array(
			'saved'    => array( 'success', __( 'Saved.', 'scout-law' ) ),
			'reviewed' => array( 'success', __( 'Review recorded. The page shows it now.', 'scout-law' ) ),
			'pick'     => array( 'error', __( 'Choose the attorney who reviewed the page, then record it again.', 'scout-law' ) ),
		);
		if ( isset( $msgs[ $flag ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $msgs[ $flag ][0] ), esc_html( $msgs[ $flag ][1] ) );
		}
		?>
		<section class="sh-box">
			<p class="sh-status">
				<?php if ( ! $people ) : ?>
					<span aria-hidden="true">&#9670;</span> <strong><?php esc_html_e( 'Needs you:', 'scout-law' ); ?></strong>
					<?php
					$waiting = Scout_Law_Reviews::waiting_attorneys();
					echo esc_html(
						$waiting
							/* translators: %s: names */
							? sprintf( __( 'No attorney can sign yet. %s can sign once their Team profile is published (it may be waiting for the firm\'s approval).', 'scout-law' ), implode( ', ', $waiting ) )
							: __( 'No attorney can sign yet. Add one on the Team screen and tick "Attorney".', 'scout-law' )
					);
					?>
				<?php elseif ( $due ) : ?>
					<span aria-hidden="true">&#9670;</span> <strong><?php esc_html_e( 'Needs you:', 'scout-law' ); ?></strong>
					<?php
					/* translators: 1: pages due, 2: months */
					echo esc_html( sprintf( _n( '%1$d page is due. Pages are reviewed every %2$d months.', '%1$d pages are due. Pages are reviewed every %2$d months.', count( $due ), 'scout-law' ), count( $due ), $settings['months'] ) );
					?>
				<?php else : ?>
					<span aria-hidden="true">&#10003;</span> <strong><?php esc_html_e( 'All caught up.', 'scout-law' ); ?></strong>
					<?php
					/* translators: %d: months */
					echo esc_html( sprintf( __( 'Every page has been reviewed in the last %d months.', 'scout-law' ), $settings['months'] ) );
					?>
				<?php endif; ?>
			</p>
		</section>

		<?php if ( $due && $people ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Due for a review', 'scout-law' ); ?></h2>
				<p class="sh-note"><?php esc_html_e( 'Open the page, have the attorney read it, then record the review here. About five minutes each.', 'scout-law' ); ?></p>
				<table class="sh-table sh-table-plain">
					<thead><tr><th><?php esc_html_e( 'Page', 'scout-law' ); ?></th><th><?php esc_html_e( 'Last reviewed', 'scout-law' ); ?></th><th><?php esc_html_e( 'Record a review', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $due, 0, 50 ) as $id ) : ?>
						<?php $s = Scout_Law_Reviews::stamp( $id ); ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ? get_the_title( $id ) : __( '(no title)', 'scout-law' ) ); ?></a></td>
							<td><?php echo $s['at'] ? esc_html( wp_date( get_option( 'date_format' ), $s['at'] ) . ', ' . get_the_title( $s['by'] ) ) : esc_html__( 'Never', 'scout-law' ); ?></td>
							<td>
								<?php if ( current_user_can( 'edit_post', $id ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::REVIEW ); ?>">
										<input type="hidden" name="post" value="<?php echo (int) $id; ?>">
										<?php wp_nonce_field( self::REVIEW . $id ); ?>
										<label class="screen-reader-text" for="sl-att-<?php echo (int) $id; ?>"><?php esc_html_e( 'Attorney', 'scout-law' ); ?></label>
										<select id="sl-att-<?php echo (int) $id; ?>" name="attorney">
											<?php foreach ( $people as $pid => $name ) : ?>
												<option value="<?php echo (int) $pid; ?>" <?php selected( $s['by'], $pid ); ?>><?php echo esc_html( $name ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button"><?php esc_html_e( 'Reviewed today', 'scout-law' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<details class="sh-box">
				<summary class="sh-title"><?php esc_html_e( 'More options', 'scout-law' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
					<?php wp_nonce_field( self::SAVE ); ?>
					<fieldset>
						<legend><?php esc_html_e( 'What gets reviewed', 'scout-law' ); ?></legend>
						<?php foreach ( Scout_Law_Approvals::choices() as $type => $label ) : ?>
							<label style="display:block;margin:6px 0"><input type="checkbox" name="scout_law_review_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $settings['types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<label for="sl-months"><?php esc_html_e( 'Review every', 'scout-law' ); ?></label>
						<select id="sl-months" name="scout_law_review_months">
							<?php foreach ( array( 3, 6, 12 ) as $m ) : ?>
								<option value="<?php echo (int) $m; ?>" <?php selected( $settings['months'], $m ); ?>><?php echo esc_html( sprintf( /* translators: %d: months */ _n( '%d month', '%d months', $m, 'scout-law' ), $m ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><label><input type="checkbox" name="scout_law_review_show" value="1" <?php checked( $settings['show'] ); ?>> <?php esc_html_e( 'Show "Reviewed by" on the page, with the attorney and the date', 'scout-law' ); ?></label></p>
					<?php submit_button( __( 'Save', 'scout-law' ), 'secondary' ); ?>
				</form>
			</details>
		<?php endif; ?>

		<aside class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Where this shows', 'scout-law' ); ?></h2>
			<ul class="sh-list">
				<li><?php esc_html_e( 'Under each reviewed page\'s words: "Reviewed by" the attorney, linked to their profile, with the date.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'In the page\'s search listing data, so Google and AI tools can see who reviewed it.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'On Home and in the Inbox when pages are due.', 'scout-law' ); ?></li>
			</ul>
		</aside>

		<?php $log = array_slice( Scout_Law_Reviews::log(), 0, 10 ); ?>
		<?php if ( $log ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Review record', 'scout-law' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'scout-law' ); ?></th><th><?php esc_html_e( 'Page', 'scout-law' ); ?></th><th><?php esc_html_e( 'Reviewed by', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr><td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td><td><?php echo esc_html( (string) $row['title'] ); ?></td><td><?php echo esc_html( (string) $row['name'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
		Scout_Admin_UI::close();
	}
}
