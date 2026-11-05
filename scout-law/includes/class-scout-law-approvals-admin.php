<?php
/**
 * Scout → Policies → Firm approvals: which kinds of content need the firm's
 * approval before publishing, what is waiting, what changed after approval,
 * and the approval record.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Approvals_Admin {

	const SLUG   = 'scout-approvals';
	const ACTION = 'scout_law_approvals_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_filter( 'scout_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
	}

	/** Firm approvals files under Policies, beside Disclaimers. */
	public static function nav_group( string $group, string $slug ): string {
		return self::SLUG === strtok( $slug, '&' ) ? Scout_Law_Admin::policies_group() : $group;
	}

	public static function url( array $args = array() ): string {
		// admin.php?page= opens the screen wherever its menu is. The parent is unknown while a
		// form saves (admin-post.php builds no menu), and options-general.php?page= then fails.
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( Scout_Law_Admin::parent(), __( 'Firm approvals', 'scout-law' ), __( 'Firm approvals', 'scout-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_LAW_URL . 'assets/scout-admin.css' );
		}
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change approvals.', 'scout-law' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; cleaned below.
		$types = isset( $_POST['scout_law_types'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['scout_law_types'] ) ) : array();
		update_option( Scout_Law_Approvals::OPTION, array( 'types' => array_values( array_intersect( $types, array_keys( Scout_Law_Approvals::choices() ) ) ) ) );
		wp_safe_redirect( add_query_arg( 'scout-law', 'saved', self::url() ) );
		exit;
	}

	/** A short list of items, each linking to its edit screen. */
	private static function items( array $ids, string $empty ): void {
		if ( ! $ids ) {
			echo '<p class="sh-note">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul class="sh-list">';
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$type = get_post_type_object( (string) get_post_type( $id ) );
			printf(
				'<li><a href="%1$s">%2$s</a> <span class="sh-note">%3$s</span></li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ? get_the_title( $id ) : __( '(no title)', 'scout-law' ) ),
				esc_html( $type ? $type->labels->singular_name : '' )
			);
		}
		echo '</ul>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$types = Scout_Law_Approvals::types();
		$q     = Scout_Law_Approvals::queues();
		Scout_Admin_UI::open(
			__( 'Firm approvals', 'scout-law' ),
			__( 'Pages and posts the firm signs off on before they go live, with each approval kept.', 'scout-law' ),
			__( 'Legal pages', 'scout-law' )
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message flag only.
		if ( isset( $_GET['scout-law'] ) && 'saved' === $_GET['scout-law'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'scout-law' ) . '</p></div>';
		}
		$waiting_n = count( $q['waiting'] ) + count( $q['edited'] );
		?>
		<section class="sh-box">
			<p class="sh-status <?php echo $waiting_n ? 'is-warn' : ''; ?>"><span class="sh-dot" aria-hidden="true"></span>
				<?php if ( $waiting_n ) : ?>
					<?php /* translators: %d: pages and posts waiting. */ ?>
					<strong><?php echo esc_html( sprintf( _n( '%d item waits for the firm.', '%d items wait for the firm.', $waiting_n, 'scout-law' ), $waiting_n ) ); ?></strong>
					<span><?php esc_html_e( 'Open each one and record the firm’s approval.', 'scout-law' ); ?></span>
				<?php else : ?>
					<strong><?php esc_html_e( 'All caught up.', 'scout-law' ); ?></strong>
					<span><?php esc_html_e( 'Everything that needs the firm’s approval has it.', 'scout-law' ); ?></span>
				<?php endif; ?>
			</p>
		</section>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Waiting for the firm\'s approval', 'scout-law' ); ?></h2>
			<?php self::items( $q['waiting'], __( 'Nothing is waiting. Anything saved without an approval shows up here.', 'scout-law' ) ); ?>
		</section>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Edited since approval', 'scout-law' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'These stay live. Open each one, have the firm look at the change, and record a new approval.', 'scout-law' ); ?></p>
			<?php self::items( $q['edited'], __( 'Nothing has changed since its approval.', 'scout-law' ) ); ?>
		</section>

		<?php $log = array_slice( Scout_Law_Approvals::log(), 0, 10 ); ?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Approval record', 'scout-law' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No approvals yet. Each one shows here with the date, the attorney and a link to the exact version approved.', 'scout-law' ); ?></p>
			<?php else : ?>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'scout-law' ); ?></th><th><?php esc_html_e( 'Page or post', 'scout-law' ); ?></th><th><?php esc_html_e( 'Approved by', 'scout-law' ); ?></th><th><?php esc_html_e( 'Version', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['title'] ); ?></td>
							<td><?php echo esc_html( (string) $row['by'] ); ?></td>
							<td><?php echo ! empty( $row['revision'] ) ? '<a href="' . esc_url( admin_url( 'revision.php?revision=' . (int) $row['revision'] ) ) . '">' . esc_html__( 'See this version', 'scout-law' ) . '</a>' : '<span class="screen-reader-text">' . esc_html__( 'None', 'scout-law' ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( __( 'More options: what needs approval', 'scout-law' ) );
		} else {
			echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html__( 'More options: what needs approval', 'scout-law' ) . '</summary>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Kinds of content that need approval', 'scout-law' ); ?></legend>
					<?php foreach ( Scout_Law_Approvals::choices() as $type => $label ) : ?>
						<label style="display:block;margin:6px 0"><input type="checkbox" name="scout_law_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $types, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="sh-note"><?php echo esc_html( ( ! method_exists( 'Scout_Admin_UI', 'branded' ) || Scout_Admin_UI::branded() ) ? __( 'Blog posts and bios start on. Turn on Pages once the site is built, since it also holds pages Scout publishes.', 'scout-law' ) : __( 'Blog posts and bios start on. Turn on Pages once the site is built, since it also holds pages your web team publishes.', 'scout-law' ) ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'scout-law' ); ?></button></p>
		</form>

		<?php
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
		} else {
			echo '</details>';
		}
		?>
		<?php
		Scout_Admin_UI::close();
	}
}
