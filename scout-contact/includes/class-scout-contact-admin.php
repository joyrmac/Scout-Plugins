<?php
/**
 * Build -> Call buttons: the numbers, the booking link and the wording.
 *
 * @package Scout_Contact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Contact_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	const SLUG = 'scout-contact';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_CONTACT_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Call buttons', 'scout-contact' ), __( 'Call buttons', 'scout-contact' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Scout_Admin_UI::enqueue( SCOUT_CONTACT_URL . 'assets/scout-admin.css' );
		wp_enqueue_media();
		wp_enqueue_script( 'scout-contact-admin', SCOUT_CONTACT_URL . 'assets/admin.js', array(), SCOUT_CONTACT_VERSION, true );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-contact' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'scout_contact_group', Scout_Contact::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in     = (array) $in;
		$photos = array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $in['photos'] ?? '' ) ) ) ) ), 0, 3 );
		$out    = array(
			'enabled'   => ! empty( $in['enabled'] ) ? 1 : 0,
			'phone'     => sanitize_text_field( (string) ( $in['phone'] ?? '' ) ),
			'text'      => sanitize_text_field( (string) ( $in['text'] ?? '' ) ),
			'text_off'  => ! empty( $in['text_off'] ) ? 1 : 0,
			'book_url'  => esc_url_raw( trim( (string) ( $in['book_url'] ?? '' ) ) ),
			'label'     => sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'title'     => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'subtitle'  => sanitize_text_field( (string) ( $in['subtitle'] ?? '' ) ),
			'text_note' => sanitize_text_field( (string) ( $in['text_note'] ?? '' ) ),
			'book_note' => sanitize_text_field( (string) ( $in['book_note'] ?? '' ) ),
			'photos'    => $photos,
			'hide_on'   => sanitize_text_field( (string) ( $in['hide_on'] ?? '' ) ),
		);
		$d = Scout_Contact::defaults();
		foreach ( array( 'label', 'title' ) as $k ) {
			if ( '' === $out[ $k ] ) {
				$out[ $k ] = $d[ $k ];
			}
		}
		if ( '' !== $out['phone'] && '' === Scout_Contact::dial( $out['phone'] ) ) {
			add_settings_error( Scout_Contact::OPTION, 'phone', __( 'That phone number has no digits in it.', 'scout-contact' ) );
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = Scout_Contact::saved();
		$o   = Scout_Contact::OPTION;
		$biz = Scout_Contact::business_phone();
		$a = Scout_Contact::actions();
		$n = count( array_filter( $a ) );
		Scout_Admin_UI::open(
			__( 'Call buttons', 'scout-contact' ),
			__( 'A bar at the bottom of the screen on phones, so visitors can call, text or book with one tap.', 'scout-contact' ),
			__( 'Call buttons', 'scout-contact' )
		);
		settings_errors( $o );
		?>
		<?php
		$on = $s['enabled'] && $n;
		if ( $on ) {
			$headline = __( 'On.', 'scout-contact' );
			/* translators: %d: number of options in the call sheet */
			$detail = sprintf( _n( 'The call bar shows on phones, with %d option in the sheet.', 'The call bar shows on phones, with %d options in the sheet.', $n, 'scout-contact' ), $n );
		} else {
			$headline = __( 'Off.', 'scout-contact' );
			$detail   = $n ? __( 'Tick Show the call bar on phones below.', 'scout-contact' ) : __( 'Add a phone number in Business info or below, or a booking link below.', 'scout-contact' );
		}
		// Headline plus one sentence (DESIGN 2a #2). The rest sits in More under the buttons.
		self::ui_status( $on ? 'good' : 'off', $headline, $detail );
		?>

		<form method="post" action="options.php">
			<?php settings_fields( 'scout_contact_group' ); ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Buttons', 'scout-contact' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show the bar', 'scout-contact' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Show the call bar on phones', 'scout-contact' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="scc-phone"><?php esc_html_e( 'Phone number', 'scout-contact' ); ?></label></th>
						<td><input id="scc-phone" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[phone]" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="<?php echo esc_attr( '' !== $biz ? $biz : '(919) 555-0100' ); ?>">
							<p class="description"><?php esc_html_e( 'Blank uses Business info. A number typed here shows as typed.', 'scout-contact' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scc-text"><?php esc_html_e( 'Texting number', 'scout-contact' ); ?></label></th>
						<td><input id="scc-text" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[text]" value="<?php echo esc_attr( $s['text'] ); ?>" placeholder="<?php esc_attr_e( 'Same as the phone number', 'scout-contact' ); ?>">
							<p><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[text_off]" value="1" <?php checked( $s['text_off'] ); ?>> <?php esc_html_e( 'This number cannot receive texts, so leave Text off', 'scout-contact' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Landlines usually can’t get texts, so turn Text off unless someone reads them.', 'scout-contact' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scc-book"><?php esc_html_e( 'Booking link', 'scout-contact' ); ?></label></th>
						<td><input id="scc-book" type="url" class="regular-text code" name="<?php echo esc_attr( $o ); ?>[book_url]" value="<?php echo esc_attr( $s['book_url'] ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'The page or calendar link where people pick a time. Leave blank to hide "Book a time".', 'scout-contact' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scc-hide"><?php esc_html_e( 'Hide on', 'scout-contact' ); ?></label></th>
						<td><input id="scc-hide" type="text" class="regular-text code" name="<?php echo esc_attr( $o ); ?>[hide_on]" value="<?php echo esc_attr( $s['hide_on'] ); ?>">
							<p class="description"><?php esc_html_e( 'Pages that already have their own buttons, by the first part of their address, separated by commas.', 'scout-contact' ); ?></p></td>
					</tr>
				</table>
				<details class="sh-fold"><summary><?php esc_html_e( 'More', 'scout-contact' ); ?></summary>
					<p class="description"><?php esc_html_e( 'The bar stays hidden on computers, where the header already shows the phone number and booking button.', 'scout-contact' ); ?></p>
					<p class="description"><?php echo esc_html( class_exists( 'Scout_Analytics' ) ? __( 'Taps are counted in Analytics as contact_call, contact_text, contact_book or contact_open.', 'scout-contact' ) : __( 'Taps are not counted until Scout Analytics is on.', 'scout-contact' ) ); ?></p>
				</details>
			</section>

			<?php self::ui_fold_open( __( 'More options: wording & photos', 'scout-contact' ) ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="scc-label"><?php esc_html_e( 'Main button', 'scout-contact' ); ?></label></th>
					<td><input id="scc-label" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="scc-title"><?php esc_html_e( 'Sheet title', 'scout-contact' ); ?></label></th>
					<td><input id="scc-title" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="scc-sub"><?php esc_html_e( 'Line under the title', 'scout-contact' ); ?></label></th>
					<td><input id="scc-sub" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="scc-tnote"><?php esc_html_e( 'Under "Send a text"', 'scout-contact' ); ?></label></th>
					<td><input id="scc-tnote" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[text_note]" value="<?php echo esc_attr( $s['text_note'] ); ?>" placeholder="<?php esc_attr_e( 'Opens a message on your phone.', 'scout-contact' ); ?>">
						<p class="description"><?php esc_html_e( 'Only promise a reply time you will keep.', 'scout-contact' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="scc-bnote"><?php esc_html_e( 'Under "Book a time"', 'scout-contact' ); ?></label></th>
					<td><input id="scc-bnote" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[book_note]" value="<?php echo esc_attr( $s['book_note'] ); ?>" placeholder="<?php esc_attr_e( 'Pick a time that works for you.', 'scout-contact' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Photos in the sheet', 'scout-contact' ); ?></th>
					<td>
						<input type="hidden" id="scc-photos" name="<?php echo esc_attr( $o ); ?>[photos]" value="<?php echo esc_attr( implode( ',', (array) $s['photos'] ) ); ?>">
						<div id="scc-photo-list" class="scc-photo-list" style="display:flex;gap:8px;margin-bottom:8px">
							<?php foreach ( (array) $s['photos'] as $id ) { echo wp_get_attachment_image( (int) $id, array( 48, 48 ), false, array( 'style' => 'width:48px;height:48px;border-radius:50%;object-fit:cover' ) ); } ?>
						</div>
						<button type="button" class="button" id="scc-pick"><?php esc_html_e( 'Choose photos', 'scout-contact' ); ?></button>
						<button type="button" class="button-link" id="scc-clear"><?php esc_html_e( 'Remove photos', 'scout-contact' ); ?></button>
						<p class="description"><?php esc_html_e( 'Up to three head shots of the people who answer. Optional.', 'scout-contact' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::ui_fold_close(); ?>
			<?php submit_button( __( 'Save changes', 'scout-contact' ) ); ?>
		</form>
		<?php
		Scout_Admin_UI::close();
	}
}
