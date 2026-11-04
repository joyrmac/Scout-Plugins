<?php
/**
 * Scout → Policies → Form entries: how long the website keeps what people
 * send through its forms, what happens to each form, and a record of each
 * night that deleted something.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Entries_Admin {

	const SLUG = 'scout-form-entries';
	const SAVE = 'scout_law_entries_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
	}

	/** Form entries files under Policies: it is the firm's data policy, and the privacy policy reads it. */
	public static function nav_group( string $group, string $slug ): string {
		return self::SLUG === strtok( $slug, '&' ) ? Scout_Law_Admin::policies_group() : $group;
	}

	public static function url( array $args = array() ): string {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( Scout_Law_Admin::parent(), __( 'Form entries', 'scout-law' ), __( 'Form entries', 'scout-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_LAW_URL . 'assets/scout-admin.css' );
		}
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change how long form entries are kept.', 'scout-law' ), 403 );
		}
		check_admin_referer( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$days = absint( $_POST['scout_law_entry_days'] ?? 90 );
		$keep = isset( $_POST['scout_law_entry_keep'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['scout_law_entry_keep'] ) ) : array();
		$on   = ! empty( $_POST['scout_law_entry_on'] );
		// phpcs:enable
		update_option(
			Scout_Law_Entries::OPTION,
			array(
				'on'   => $on,
				'days' => in_array( $days, Scout_Law_Entries::DAYS, true ) ? $days : 90,
				'keep' => array_values( array_filter( $keep ) ),
			)
		);
		wp_safe_redirect( add_query_arg( 'scout-law', 'saved', self::url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Scout_Law_Entries::settings();
		$gravity  = Scout_Law_Entries::gravity();
		$plan     = Scout_Law_Entries::plan( Scout_Law_Entries::forms(), $settings );
		$starts   = Scout_Law_Entries::starts( Scout_Law_Entries::first_seen( time() ) );
		$state    = get_option( Scout_Law_Entries::STATE, array() );
		$last     = is_array( $state ) ? (int) ( $state['last_run'] ?? 0 ) : 0;
		$date     = get_option( 'date_format' );
		Scout_Admin_UI::open(
			__( 'Form entries', 'scout-law' ),
			__( 'What people send through the website\'s forms is kept for a set time, then deleted from the website. Fewer old entries means less to protect.', 'scout-law' ),
			__( 'Policies', 'scout-law' )
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only.
		if ( isset( $_GET['scout-law'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['scout-law'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'scout-law' ) . '</p></div>';
		}
		?>
		<section class="sh-box">
			<p class="sh-status">
				<?php if ( ! $gravity ) : ?>
					<span aria-hidden="true">&#9675;</span> <strong><?php esc_html_e( 'Nothing to do.', 'scout-law' ); ?></strong>
					<?php esc_html_e( 'Gravity Forms isn\'t active, so the website keeps no form entries.', 'scout-law' ); ?>
				<?php elseif ( ! $settings['on'] ) : ?>
					<span aria-hidden="true">&#9675;</span> <strong><?php esc_html_e( 'Off.', 'scout-law' ); ?></strong>
					<?php esc_html_e( 'Entries stay on the website until someone deletes them.', 'scout-law' ); ?>
				<?php elseif ( time() < $starts ) : ?>
					<span aria-hidden="true">&#9650;</span> <strong><?php esc_html_e( 'Starts soon.', 'scout-law' ); ?></strong>
					<?php
					/* translators: 1: date, 2: days */
					echo esc_html( sprintf( __( 'From %1$s, entries older than %2$d days are deleted each night. Save any old entry you need before then, or star it in Gravity Forms to keep it.', 'scout-law' ), wp_date( $date, $starts ), $settings['days'] ) );
					?>
				<?php else : ?>
					<span aria-hidden="true">&#10003;</span> <strong><?php esc_html_e( 'On.', 'scout-law' ); ?></strong>
					<?php
					/* translators: %d: days */
					echo esc_html( sprintf( __( 'Entries older than %d days are deleted each night.', 'scout-law' ), $settings['days'] ) );
					if ( $last ) {
						echo ' ' . esc_html( sprintf( /* translators: %s: date */ __( 'Last checked %s.', 'scout-law' ), wp_date( $date, $last ) ) );
					}
					?>
				<?php endif; ?>
			</p>
		</section>

		<?php if ( $gravity && $plan ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Each form', 'scout-law' ); ?></h2>
				<table class="sh-table sh-table-plain">
					<thead><tr><th><?php esc_html_e( 'Form', 'scout-law' ); ?></th><th><?php esc_html_e( 'What happens to its entries', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php
					$words = array(
						/* translators: %d: days */
						'delete'   => sprintf( __( 'Deleted after %d days', 'scout-law' ), $settings['days'] ),
						'kept'     => __( 'Kept: you chose to keep this form\'s entries', 'scout-law' ),
						'no-email' => __( 'Kept: this form doesn\'t email anyone, so the entry is the only copy', 'scout-law' ),
						'gravity'  => __( 'Handled by this form\'s own Gravity Forms retention setting', 'scout-law' ),
					);
					foreach ( $plan as $id => $row ) :
						?>
						<tr>
							<td><?php echo esc_html( '' !== $row['title'] ? $row['title'] : sprintf( /* translators: %d: form id */ __( 'Form %d', 'scout-law' ), $id ) ); ?></td>
							<td><?php echo esc_html( $settings['on'] ? $words[ $row['does'] ] : __( 'Kept: deleting is off', 'scout-law' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<aside class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'What this touches', 'scout-law' ); ?></h2>
			<ul class="sh-list">
				<li><?php esc_html_e( 'Only the website\'s copy. The emails each form already sent stay in your inbox, and your case and intake records are untouched.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Files people uploaded with an entry are deleted with it.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Starred entries are kept. Star one in Gravity Forms to keep it.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Spam and trashed entries are deleted on the same schedule.', 'scout-law' ); ?></li>
			</ul>
		</aside>

		<details class="sh-box">
			<summary class="sh-title"><?php esc_html_e( 'More options', 'scout-law' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<p><label><input type="checkbox" name="scout_law_entry_on" value="1" <?php checked( $settings['on'] ); ?>> <?php esc_html_e( 'Delete old form entries from the website', 'scout-law' ); ?></label></p>
				<p>
					<label for="sl-days"><?php esc_html_e( 'Keep entries for', 'scout-law' ); ?></label>
					<select id="sl-days" name="scout_law_entry_days">
						<?php foreach ( Scout_Law_Entries::DAYS as $d ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( $settings['days'], $d ); ?>><?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d day', '%d days', $d, 'scout-law' ), $d ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( $plan ) : ?>
					<fieldset>
						<legend><?php esc_html_e( 'Keep every entry from these forms', 'scout-law' ); ?></legend>
						<?php foreach ( $plan as $id => $row ) : ?>
							<label style="display:block;margin:6px 0"><input type="checkbox" name="scout_law_entry_keep[]" value="<?php echo (int) $id; ?>" <?php checked( in_array( (int) $id, $settings['keep'], true ) ); ?>> <?php echo esc_html( '' !== $row['title'] ? $row['title'] : sprintf( /* translators: %d: form id */ __( 'Form %d', 'scout-law' ), $id ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<p class="sh-note"><?php esc_html_e( 'If you change the time, the privacy policy page should say the same.', 'scout-law' ); ?></p>
				<?php submit_button( __( 'Save', 'scout-law' ), 'secondary' ); ?>
			</form>
		</details>

		<?php $log = array_slice( Scout_Law_Entries::log(), 0, 10 ); ?>
		<?php if ( $log ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Deletion record', 'scout-law' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Night of', 'scout-law' ); ?></th><th><?php esc_html_e( 'Entries deleted', 'scout-law' ); ?></th><th><?php esc_html_e( 'Older than', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr><td><?php echo esc_html( wp_date( $date, (int) $row['at'] ) ); ?></td><td><?php echo (int) $row['total']; ?></td><td><?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d day', '%d days', (int) $row['days'], 'scout-law' ), (int) $row['days'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
		Scout_Admin_UI::close();
	}
}
