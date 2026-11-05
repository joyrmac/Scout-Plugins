<?php
/**
 * Bookings -> Session types: the list, and one plain form per type.
 *
 * Each type: name, name on receipts, length, price, what is paid to book
 * (the full price, a deposit, or nothing online), buffer after, hours, "by
 * request only", short intake questions, and a note shown before booking.
 * Saving a type keeps its WooCommerce product in step (Scout_Booking_Woo).
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Types_Admin {

	const QUESTIONS = 6;

	public static function boot(): void {
		add_action( 'admin_post_scout_booking_type_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_scout_booking_type_delete', array( __CLASS__, 'delete' ) );
	}

	public static function url( array $args = array() ): string {
		return Scout_Booking_Admin::url( $args, '', 'types' );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( Scout_Booking_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change session types.', 'scout-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function save(): void {
		self::guard( 'scout_booking_type_save' );
		$in    = isset( $_POST['type'] ) ? (array) wp_unslash( $_POST['type'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Scout_Booking::clean_type().
		$types = Scout_Booking::types();
		$id    = sanitize_key( (string) ( $in['id'] ?? '' ) );
		$old   = $types[ $id ] ?? null;
		if ( '' === trim( (string) ( $in['name'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'sb-msg', 'noname', wp_get_referer() ? wp_get_referer() : self::url( array( 'edit' => 'new' ) ) ) );
			exit;
		}
		$questions = array();
		foreach ( (array) ( $in['questions'] ?? array() ) as $q ) {
			$questions[] = array(
				'label'    => (string) ( $q['label'] ?? '' ),
				'required' => ! empty( $q['required'] ),
				'long'     => ! empty( $q['long'] ),
			);
		}
		$type = Scout_Booking::clean_type(
			array(
				'id'             => $old ? $id : '',
				'name'           => (string) ( $in['name'] ?? '' ),
				'receipt'        => (string) ( $in['receipt'] ?? '' ),
				'length'         => (int) ( $in['length'] ?? 60 ),
				'price'          => (string) ( $in['price'] ?? '0' ),
				'deposit'        => (string) ( $in['deposit'] ?? 'full' ),
				'deposit_amount' => (string) ( $in['deposit_amount'] ?? '0' ),
				'buffer'         => (int) ( $in['buffer'] ?? 0 ),
				'schedule'       => (string) ( $in['schedule'] ?? 'default' ),
				'hours'          => (array) ( $in['hours'] ?? array() ),
				'request'        => ! empty( $in['request'] ),
				'questions'      => $questions,
				'note'           => (string) ( $in['note'] ?? '' ),
				'active'         => ! empty( $in['active'] ),
				'product_id'     => $old ? $old['product_id'] : 0,
			)
		);
		$types[ $type['id'] ] = $type;
		Scout_Booking::save_types( $types );
		/** Session types changed. Scout_Booking_Woo keeps the products in step. */
		do_action( 'scout_booking_types_saved' );
		wp_safe_redirect( self::url( array( 'sb-msg' => 'typesaved' ) ) );
		exit;
	}

	public static function delete(): void {
		self::guard( 'scout_booking_type_delete' );
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$types = Scout_Booking::types();
		if ( isset( $types[ $id ] ) ) {
			if ( class_exists( 'Scout_Booking_Woo' ) && Scout_Booking::payments_on() ) {
				Scout_Booking_Woo::retire( $types[ $id ] );
			}
			unset( $types[ $id ] );
			Scout_Booking::save_types( $types );
		}
		wp_safe_redirect( self::url( array( 'sb-msg' => 'typedeleted' ) ) );
		exit;
	}

	/** Seven rows: open tick, from, to. Shared with the Booking hours on Settings. */
	public static function week_fields( string $name, array $week, string $idp ): void {
		echo '<div class="sbk-week">';
		foreach ( Scout_Booking::days() as $key => $label ) {
			$d  = $week[ $key ] ?? array(
				'open' => false,
				'from' => '10:00',
				'to'   => '18:00',
			);
			$id = $idp . '-' . $key;
			echo '<fieldset class="sbk-weekday"><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>'
				. '<label class="sbk-weekday-name"><input type="checkbox" name="' . esc_attr( $name . '[' . $key . '][open]' ) . '" value="1"' . checked( $d['open'], true, false ) . '> ' . esc_html( $label ) . '</label>'
				/* translators: %s: day name. */
				. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '-f">' . esc_html( sprintf( __( '%s from', 'scout-booking' ), $label ) ) . '</label>'
				. '<input type="time" id="' . esc_attr( $id ) . '-f" name="' . esc_attr( $name . '[' . $key . '][from]' ) . '" value="' . esc_attr( $d['from'] ) . '">'
				. '<span aria-hidden="true">' . esc_html__( 'to', 'scout-booking' ) . '</span>'
				/* translators: %s: day name. */
				. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '-t">' . esc_html( sprintf( __( '%s until', 'scout-booking' ), $label ) ) . '</label>'
				. '<input type="time" id="' . esc_attr( $id ) . '-t" name="' . esc_attr( $name . '[' . $key . '][to]' ) . '" value="' . esc_attr( $d['to'] ) . '">'
				. '</fieldset>';
		}
		echo '</div>';
	}

	public static function render(): void {
		if ( ! current_user_can( Scout_Booking_Admin::CAP ) ) {
			return;
		}
		$edit  = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing what to show.
		$types = Scout_Booking::types();
		Scout_Admin_UI::open(
			__( 'Session types', 'scout-booking' ),
			__( 'The sessions people can book: length, price, what they pay to book, and your questions.', 'scout-booking' ),
			__( 'Bookings', 'scout-booking' ),
			Scout_Booking_Admin::SLUG . '-types'
		);
		$msg   = isset( $_GET['sb-msg'] ) ? sanitize_key( wp_unslash( $_GET['sb-msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'typesaved'   => array( 'success', __( 'Session type saved.', 'scout-booking' ) ),
			'typedeleted' => array( 'success', __( 'Session type removed. Bookings already made keep their details.', 'scout-booking' ) ),
			'noname'      => array( 'error', __( 'Give the session a name, then save again.', 'scout-booking' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
		if ( '' !== $edit ) {
			self::form( 'new' === $edit ? null : ( $types[ $edit ] ?? null ) );
			Scout_Admin_UI::close();
			return;
		}
		?>
		<section class="sh-box">
			<div class="sbk-head">
				<h2 class="sh-title"><?php esc_html_e( 'Your sessions', 'scout-booking' ); ?></h2>
				<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add a session type', 'scout-booking' ); ?></a>
			</div>
			<?php if ( ! $types ) : ?>
				<p class="sbk-empty"><?php esc_html_e( 'No session types yet. Add the first one, such as a 60-minute massage.', 'scout-booking' ); ?></p>
			<?php else : ?>
				<ul class="sbk-types">
					<?php foreach ( $types as $t ) : ?>
						<li class="sbk-type<?php echo $t['active'] ? '' : ' is-off'; ?>">
							<div>
								<p class="sbk-what"><?php echo esc_html( $t['name'] ); ?>
									<span class="sh-pill <?php echo $t['active'] ? 'is-on' : ''; ?>"><?php echo esc_html( $t['active'] ? __( 'On the booking page', 'scout-booking' ) : __( 'Off', 'scout-booking' ) ); ?></span>
									<?php if ( Scout_Booking::is_request( $t ) ) : ?>
										<span class="sh-pill is-received"><?php esc_html_e( 'By request', 'scout-booking' ); ?></span>
									<?php endif; ?>
								</p>
								<p class="sh-note"><?php echo esc_html( Scout_Booking::length_label( $t['length'] ) . ' · ' . Scout_Booking_Front::price_line( $t ) . ( $t['buffer'] ? ' · ' . sprintf( /* translators: %s: buffer length. */ __( '%s buffer after', 'scout-booking' ), Scout_Booking::length_label( $t['buffer'] ) ) : '' ) ); ?></p>
								<p class="sh-note"><?php echo esc_html( Scout_Booking_Slots::source( $t ) ); ?> · <?php esc_html_e( 'Id', 'scout-booking' ); ?> <code><?php echo esc_html( $t['id'] ); ?></code></p>
							</div>
							<a class="button" href="<?php echo esc_url( self::url( array( 'edit' => $t['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'scout-booking' ); ?><span class="screen-reader-text"> <?php echo esc_html( $t['name'] ); ?></span></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Discount codes', 'scout-booking' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					Scout_Booking::payments_on()
						? __( 'Discount codes are WooCommerce coupons (Marketing, Coupons). To limit a code to one session, pick that session\'s product on the coupon.', 'scout-booking' )
						: __( 'Discount codes work once WooCommerce is active, through its coupons.', 'scout-booking' )
				);
				?>
			</p>
		</section>
		<?php
		Scout_Admin_UI::close();
	}

	private static function form( ?array $t ): void {
		$new = null === $t;
		$t   = $t ?? Scout_Booking::clean_type(
			array(
				'id'     => 'new',
				'active' => true,
			)
		);
		$f   = static fn( string $k ): string => 'type[' . $k . ']';
		?>
		<p class="sbk-back"><a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All session types', 'scout-booking' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sbk-typeform">
			<input type="hidden" name="action" value="scout_booking_type_save">
			<input type="hidden" name="<?php echo esc_attr( $f( 'id' ) ); ?>" value="<?php echo esc_attr( $new ? '' : $t['id'] ); ?>">
			<?php wp_nonce_field( 'scout_booking_type_save' ); ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php echo esc_html( $new ? __( 'New session type', 'scout-booking' ) : $t['name'] ); ?></h2>
				<p class="sbk-field"><label for="sbt-name"><?php esc_html_e( 'Name', 'scout-booking' ); ?></label><input type="text" id="sbt-name" name="<?php echo esc_attr( $f( 'name' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $t['name'] ); ?>" required></p>
				<p class="sbk-field"><label for="sbt-receipt"><?php esc_html_e( 'Name on receipts (optional)', 'scout-booking' ); ?></label><input type="text" id="sbt-receipt" name="<?php echo esc_attr( $f( 'receipt' ) ); ?>" class="regular-text" value="<?php echo esc_attr( $t['receipt'] ); ?>"><span class="description"><?php esc_html_e( 'What the checkout and receipt show, such as "Private session, 2 hours". Blank uses the name.', 'scout-booking' ); ?></span></p>
				<p class="sbk-check"><label><input type="checkbox" name="<?php echo esc_attr( $f( 'active' ) ); ?>" value="1" <?php checked( $t['active'] ); ?>> <?php esc_html_e( 'Show this session on the booking page', 'scout-booking' ); ?></label></p>
				<p class="sbk-field"><label for="sbt-note"><?php esc_html_e( 'Note shown before booking (optional)', 'scout-booking' ); ?></label><textarea id="sbt-note" name="<?php echo esc_attr( $f( 'note' ) ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $t['note'] ); ?></textarea><span class="description"><?php esc_html_e( 'A line or two the client reads before picking a time, and again in their emails.', 'scout-booking' ); ?></span></p>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Time and price', 'scout-booking' ); ?></h2>
				<div class="sbk-pair">
					<p class="sbk-field"><label for="sbt-length"><?php esc_html_e( 'Length, in minutes', 'scout-booking' ); ?></label><input type="number" id="sbt-length" name="<?php echo esc_attr( $f( 'length' ) ); ?>" min="15" max="720" step="5" value="<?php echo (int) $t['length']; ?>" class="small-text"></p>
					<p class="sbk-field"><label for="sbt-buffer"><?php esc_html_e( 'Buffer after, in minutes', 'scout-booking' ); ?></label><input type="number" id="sbt-buffer" name="<?php echo esc_attr( $f( 'buffer' ) ); ?>" min="0" max="240" step="5" value="<?php echo (int) $t['buffer']; ?>" class="small-text"><span class="description"><?php esc_html_e( 'Time to reset the room before the next session.', 'scout-booking' ); ?></span></p>
				</div>
				<p class="sbk-field"><label for="sbt-price"><?php esc_html_e( 'Price', 'scout-booking' ); ?></label><input type="number" id="sbt-price" name="<?php echo esc_attr( $f( 'price' ) ); ?>" min="0" step="0.01" value="<?php echo esc_attr( (string) $t['price'] ); ?>" class="small-text"></p>
				<fieldset class="sbk-field">
					<legend><?php esc_html_e( 'Paid to book it', 'scout-booking' ); ?></legend>
					<label><input type="radio" name="<?php echo esc_attr( $f( 'deposit' ) ); ?>" value="full" <?php checked( 'full', $t['deposit'] ); ?>> <?php esc_html_e( 'The full price', 'scout-booking' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $f( 'deposit' ) ); ?>" value="amount" <?php checked( 'amount', $t['deposit'] ); ?>> <?php esc_html_e( 'A deposit of', 'scout-booking' ); ?></label>
					<label class="screen-reader-text" for="sbt-deposit"><?php esc_html_e( 'Deposit amount', 'scout-booking' ); ?></label>
					<input type="number" id="sbt-deposit" name="<?php echo esc_attr( $f( 'deposit_amount' ) ); ?>" min="0" step="0.01" value="<?php echo esc_attr( (string) $t['deposit_amount'] ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'and the rest at the session', 'scout-booking' ); ?></span><br>
					<label><input type="radio" name="<?php echo esc_attr( $f( 'deposit' ) ); ?>" value="none" <?php checked( 'none', $t['deposit'] ); ?>> <?php esc_html_e( 'Nothing online; everything is paid at the session', 'scout-booking' ); ?></label>
				</fieldset>
				<p class="sbk-check"><label><input type="checkbox" name="<?php echo esc_attr( $f( 'request' ) ); ?>" value="1" <?php checked( $t['request'] ); ?>> <?php esc_html_e( 'By request only: I approve each booking before the client pays', 'scout-booking' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'The client sends a request. If you approve it, they get an email with a link to pay and book.', 'scout-booking' ); ?></p>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Days and hours', 'scout-booking' ); ?></h2>
				<fieldset class="sbk-field">
					<legend class="screen-reader-text"><?php esc_html_e( 'Which hours this session follows', 'scout-booking' ); ?></legend>
					<label><input type="radio" name="<?php echo esc_attr( $f( 'schedule' ) ); ?>" value="default" <?php checked( 'default', $t['schedule'] ); ?>> <?php echo esc_html( Scout_Booking::scout_hours_on() ? __( 'Your business hours from Scout Hours', 'scout-booking' ) : __( 'The booking hours on the Settings screen', 'scout-booking' ) ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $f( 'schedule' ) ); ?>" value="own" <?php checked( 'own', $t['schedule'] ); ?>> <?php esc_html_e( 'Its own days and hours:', 'scout-booking' ); ?></label>
				</fieldset>
				<?php self::week_fields( $f( 'hours' ), $t['hours'], 'sbt-h' ); ?>
			</section>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Questions before booking', 'scout-booking' ); ?></h2>
				<p><?php esc_html_e( 'Short questions, such as how they heard about you. Keep health questions in your health intake form.', 'scout-booking' ); ?></p>
				<div class="sbk-questions">
					<?php for ( $i = 0; $i < self::QUESTIONS; $i++ ) : ?>
						<?php $q = $t['questions'][ $i ] ?? array( 'label' => '', 'required' => false, 'long' => false ); ?>
						<div class="sbk-question">
							<label class="screen-reader-text" for="sbt-q<?php echo (int) $i; ?>"><?php /* translators: %d: question number. */ echo esc_html( sprintf( __( 'Question %d', 'scout-booking' ), $i + 1 ) ); ?></label>
							<input type="text" id="sbt-q<?php echo (int) $i; ?>" name="<?php echo esc_attr( $f( 'questions' ) . '[' . $i . '][label]' ); ?>" value="<?php echo esc_attr( $q['label'] ); ?>" placeholder="<?php /* translators: %d: question number. */ echo esc_attr( sprintf( __( 'Question %d', 'scout-booking' ), $i + 1 ) ); ?>">
							<label><input type="checkbox" name="<?php echo esc_attr( $f( 'questions' ) . '[' . $i . '][required]' ); ?>" value="1" <?php checked( $q['required'] ); ?>> <?php esc_html_e( 'Required', 'scout-booking' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $f( 'questions' ) . '[' . $i . '][long]' ); ?>" value="1" <?php checked( $q['long'] ); ?>> <?php esc_html_e( 'Longer answer', 'scout-booking' ); ?></label>
						</div>
					<?php endfor; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Leave a row empty to skip it.', 'scout-booking' ); ?></p>
			</section>

			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save session type', 'scout-booking' ); ?></button></p>
		</form>
		<?php if ( ! $new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sbk-delete">
				<input type="hidden" name="action" value="scout_booking_type_delete">
				<input type="hidden" name="id" value="<?php echo esc_attr( $t['id'] ); ?>">
				<?php wp_nonce_field( 'scout_booking_type_delete' ); ?>
				<details><summary><?php esc_html_e( 'Remove this session type', 'scout-booking' ); ?></summary>
					<p><?php esc_html_e( 'It comes off the booking page. Bookings already made keep their details. To pause it instead, untick "Show this session on the booking page".', 'scout-booking' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Yes, remove it', 'scout-booking' ); ?></button>
				</details>
			</form>
			<?php
		endif;
	}
}
