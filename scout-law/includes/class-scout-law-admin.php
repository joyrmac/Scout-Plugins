<?php
/**
 * Scout → Policies → Disclaimers: the firm's lines, where each shows, and
 * the firm's approval of the exact wording, with every approval kept.
 *
 * Saving posts to admin-post.php with a nonce and a manage_options check,
 * then returns to the screen. Disclaimers waiting on the firm add a row to
 * the Scout Inbox.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Admin {

	const SLUG   = 'scout-disclaimers';
	const ACTION = 'scout_law_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_filter( 'scout_hub_inbox', array( __CLASS__, 'inbox' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_LAW_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Scout menu; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	/**
	 * Policies arrived in admin kit 1.8.0. The first plugin to load sets the kit for every
	 * screen, so during a partial rollout or after "Go back a version" an older kit can be in
	 * charge, and it has no Policies group. Then the law screens file under Search, which it has.
	 */
	public static function policies_group(): string {
		$kit = defined( 'Scout_Admin_UI::VERSION' ) ? (string) Scout_Admin_UI::VERSION : '0';
		return version_compare( $kit, '1.8.0', '>=' ) ? 'policies' : 'search';
	}

	public static function url( array $args = array() ): string {
		// admin.php?page= opens the screen wherever its menu is. The parent is unknown while a
		// form saves (admin-post.php builds no menu), and options-general.php?page= then fails.
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Disclaimers', 'scout-law' ), __( 'Disclaimers', 'scout-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_LAW_URL . 'assets/scout-admin.css' );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Disclaimers', 'scout-law' ) . '</a>' );
		return $links;
	}

	/** One Inbox row while any line in use waits on the firm. */
	public static function inbox( $rows ) {
		$rows    = is_array( $rows ) ? $rows : array();
		$waiting = count( Scout_Law_Disclaimers::waiting() );
		if ( $waiting ) {
			$rows[] = array(
				'label' => __( 'Disclaimers to approve', 'scout-law' ),
				'count' => $waiting,
				'url'   => self::url(),
				'note'  => __( 'Wording the firm hasn\'t approved yet. The starting wording shows on the site until then.', 'scout-law' ),
			);
		}
		return $rows;
	}

	/* ---- Saving ------------------------------------------------------- */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the disclaimers.', 'scout-law' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$in    = isset( $_POST['scout_law'] ) ? wp_unslash( (array) $_POST['scout_law'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
		$state = sanitize_key( (string) ( $in['state'] ?? 'nc' ) );
		// phpcs:enable
		$saved = get_option( Scout_Law_Disclaimers::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$saved['state'] = isset( Scout_Law_Disclaimers::states()[ $state ] ) ? $state : 'nc';
		$places         = array_keys( Scout_Law_Disclaimers::places() );
		foreach ( array_keys( Scout_Law_Disclaimers::lines() ) as $key ) {
			$line  = (array) ( $in['lines'][ $key ] ?? array() );
			$text  = trim( sanitize_textarea_field( (string) ( $line['text'] ?? '' ) ) );
			$start = Scout_Law_Disclaimers::starting( $key, $saved['state'] );

			$saved['lines'][ $key ] = array(
				'text'   => $text === $start ? '' : $text, // Matching the starting wording keeps following it.
				'places' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $line['places'] ?? array() ) ), $places ) ),
			);
		}
		update_option( Scout_Law_Disclaimers::OPTION, $saved );

		$by       = trim( sanitize_text_field( (string) ( $in['approved_by'] ?? '' ) ) );
		$approve  = array_intersect( array_map( 'sanitize_key', (array) ( $in['approve'] ?? array() ) ), array_keys( Scout_Law_Disclaimers::lines() ) );
		$msg      = 'saved';
		if ( $approve && '' === $by ) {
			$msg = 'noname';
		} elseif ( $approve ) {
			foreach ( $approve as $key ) {
				Scout_Law_Disclaimers::approve( $key, $by, get_current_user_id() );
			}
			$msg = 'approved';
		}
		wp_safe_redirect( add_query_arg( 'scout-law', $msg, self::url() ) );
		exit;
	}

	/* ---- The screen --------------------------------------------------- */

	private static function message(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message key only.
		$msg  = isset( $_GET['scout-law'] ) ? sanitize_key( (string) $_GET['scout-law'] ) : '';
		$text = array(
			'saved'    => __( 'Saved.', 'scout-law' ),
			'approved' => __( 'Saved, and the approval is on record.', 'scout-law' ),
			'noname'   => __( 'Saved. To record an approval, add the name of the person at the firm who approved it.', 'scout-law' ),
		);
		if ( isset( $text[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'noname' === $msg ? 'warning' : 'success', esc_html( $text[ $msg ] ) );
		}
	}

	/** Words and a shape for a line's status: never color alone. */
	private static function pill( string $key ): string {
		$status   = Scout_Law_Disclaimers::status( $key );
		$approval = Scout_Law_Disclaimers::approval( $key );
		if ( 'approved' === $status ) {
			/* translators: 1: approver's name, 2: date */
			$label = sprintf( __( 'Approved by %1$s on %2$s', 'scout-law' ), (string) $approval['by'], wp_date( get_option( 'date_format' ), (int) $approval['at'] ) );
			return '<span class="sh-pill is-on">&#10003; ' . esc_html( $label ) . '</span>';
		}
		if ( 'changed' === $status ) {
			return '<span class="sh-pill is-warn">' . esc_html__( 'Changed since approval', 'scout-law' ) . '</span>';
		}
		return '<span class="sh-pill is-progress">' . esc_html__( 'Needs the firm\'s approval', 'scout-law' ) . '</span>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Scout_Law_Disclaimers::settings();
		Scout_Admin_UI::open(
			__( 'Disclaimers', 'scout-law' ),
			__( 'The firm’s required lines, set once and approved word for word.', 'scout-law' ),
			__( 'Legal pages', 'scout-law' )
		);
		self::message();
		$waiting = Scout_Law_Disclaimers::waiting();
		echo '<section class="sh-box"><p class="sh-status' . ( $waiting ? ' is-warn' : '' ) . '"><span class="sh-dot" aria-hidden="true"></span>';
		if ( $waiting ) {
			/* translators: %d: lines waiting. */
			echo '<strong>' . esc_html( sprintf( _n( '%d line waits for the firm’s approval.', '%d lines wait for the firm’s approval.', count( $waiting ), 'scout-law' ), count( $waiting ) ) ) . '</strong> <span>' . esc_html__( 'Every approval is kept as the advertising record.', 'scout-law' ) . '</span>';
		} else {
			echo '<strong>' . esc_html__( 'Every line on the site is approved.', 'scout-law' ) . '</strong> <span>' . esc_html__( 'Every approval is kept as the advertising record.', 'scout-law' ) . '</span>';
		}
		echo '</p></section>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Starting wording', 'scout-law' ); ?></h2>
				<p><label for="scout-law-state"><?php esc_html_e( 'The state the firm practices in', 'scout-law' ); ?></label></p>
				<select id="scout-law-state" name="scout_law[state]">
					<?php foreach ( Scout_Law_Disclaimers::states() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="sh-note"><?php esc_html_e( 'Sets the starting wording shown in gray. It isn\'t legal advice: the firm\'s attorneys decide the final words.', 'scout-law' ); ?></p>
			</section>

			<?php foreach ( Scout_Law_Disclaimers::lines() as $key => $def ) : ?>
				<?php $line = $s['lines'][ $key ]; ?>
				<section class="sh-box" id="scout-law-<?php echo esc_attr( $key ); ?>">
					<div class="sh-section-head">
						<h2 class="sh-title"><?php echo esc_html( $def['label'] ); ?></h2>
						<?php echo self::pill( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
					</div>
					<p class="sh-note"><?php echo esc_html( $def['help'] ); ?></p>
					<p><label for="scout-law-text-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Wording', 'scout-law' ); ?></label></p>
					<textarea id="scout-law-text-<?php echo esc_attr( $key ); ?>" class="large-text" rows="3" name="scout_law[lines][<?php echo esc_attr( $key ); ?>][text]" placeholder="<?php echo esc_attr( Scout_Law_Disclaimers::starting( $key, $s['state'] ) ); ?>"><?php echo esc_textarea( $line['text'] ); ?></textarea>
					<p class="sh-note"><?php esc_html_e( 'Leave it blank to use the starting wording.', 'scout-law' ); ?></p>
					<fieldset>
						<legend><?php esc_html_e( 'Shows in', 'scout-law' ); ?></legend>
						<?php foreach ( Scout_Law_Disclaimers::places() as $place => $label ) : ?>
							<label style="display:block;margin:4px 0"><input type="checkbox" name="scout_law[lines][<?php echo esc_attr( $key ); ?>][places][]" value="<?php echo esc_attr( $place ); ?>" <?php checked( in_array( $place, $line['places'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<?php if ( 'approved' !== Scout_Law_Disclaimers::status( $key ) ) : ?>
						<p style="margin-top:12px"><label><input type="checkbox" name="scout_law[approve][]" value="<?php echo esc_attr( $key ); ?>"> <?php esc_html_e( 'The firm approved this exact wording', 'scout-law' ); ?></label></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Who approved it', 'scout-law' ); ?></h2>
				<p><label for="scout-law-by"><?php esc_html_e( 'Name of the attorney who approved the wording you ticked', 'scout-law' ); ?></label></p>
				<input id="scout-law-by" type="text" class="regular-text" name="scout_law[approved_by]" autocomplete="off">
				<p class="sh-note"><?php esc_html_e( 'The name, the date and the exact wording are kept together. Changing the wording later sends the line back for approval.', 'scout-law' ); ?></p>
				<?php submit_button( __( 'Save disclaimers', 'scout-law' ) ); ?>
			</section>
		</form>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Where this shows', 'scout-law' ); ?></h2>
			<ul class="sh-list">
				<li><?php esc_html_e( 'The footer of every page. A theme can place these lines itself instead.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Under every form on the site.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Next to every case result, when Case results is on.', 'scout-law' ); ?></li>
				<li><?php esc_html_e( 'Under the reviews, when Reviews is on.', 'scout-law' ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: 1: shortcode, 2: shortcode */ __( 'Anywhere else with %1$s or %2$s.', 'scout-law' ), '[scout_disclaimers place="footer"]', '[scout_disclaimer line="relationship"]' ) ); ?></li>
			</ul>
		</section>

		<?php $log = array_slice( Scout_Law_Disclaimers::log(), 0, 10 ); ?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Approval record', 'scout-law' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No approvals yet. Each one you record shows here with its date and exact wording.', 'scout-law' ); ?></p>
			<?php else : ?>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'scout-law' ); ?></th><th><?php esc_html_e( 'Line', 'scout-law' ); ?></th><th><?php esc_html_e( 'Approved by', 'scout-law' ); ?></th><th><?php esc_html_e( 'Wording', 'scout-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
							<td><?php echo esc_html( Scout_Law_Disclaimers::lines()[ $row['line'] ]['label'] ?? $row['line'] ); ?></td>
							<td><?php echo esc_html( (string) $row['by'] ); ?></td>
							<td><?php echo esc_html( (string) $row['text'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
		Scout_Admin_UI::close();
	}
}
