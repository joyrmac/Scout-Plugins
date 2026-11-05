<?php
/**
 * Form emails sent: whether Gravity Forms notifications actually went out.
 *
 * On Nov 5 Scout's own Contact form said "sent", but the notice never reached
 * the inbox, and nobody knew until someone checked by hand. "Form emails go
 * to" only shows where the emails are addressed. This notes the result of
 * every notification Gravity Forms tries to send, and Site status reads it.
 *
 * What is kept, in one option that is not autoloaded: the form id, the
 * notification's name, sent or failed, the error with every email address
 * taken out, and the time. The last 50 tries, plus a count per form per day
 * for 30 days. Never the recipient, subject, message, entry or anything a
 * visitor typed.
 *
 * Operators can also send a plain test email from Site status. It goes
 * through wp_mail to the shared notice address and never submits a form.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Form_Mail {

	const OPTION = 'scout_hub_form_mail';      // { since, log: [ {k, f, n, ok, e, t} ], tally: { form: { Y-m-d: [sent, failed] } }, last_fail }.
	const TEST   = 'scout_hub_form_mail_test'; // admin-post action and nonce.
	const CAP    = 50;                         // Tries kept in the log.
	const DAYS   = 30;                         // Days kept in the per-form count.

	/** @var array|null The Gravity Forms notification being sent right now: { f: form id, n: name }. */
	private static $sending = null;

	/** @var string The wp_mail error for that notification, addresses taken out. */
	private static $error = '';

	public static function boot(): void {
		// Late, so a filter that cancels the email has already had its say.
		add_filter( 'gform_pre_send_email', array( __CLASS__, 'before_send' ), 9999, 4 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ) );
		add_action( 'gform_send_email_failed', array( __CLASS__, 'gf_failed' ), 10, 3 );
		add_action( 'gform_after_email', array( __CLASS__, 'after_send' ), 10, 13 );
		add_action( 'admin_post_' . self::TEST, array( __CLASS__, 'send_test' ) );
	}

	/* ---- Noting each try ----------------------------------------------- */

	/**
	 * Gravity Forms is about to send one notification. Note which one, so a
	 * wp_mail failure during it can be counted against it.
	 *
	 * @param array  $email        to, subject, message, headers, attachments, abort_email.
	 * @param string $format       html or text.
	 * @param array  $notification The notification settings (name, id).
	 * @param array  $entry        The entry. Only its form id is read.
	 */
	public static function before_send( $email, $format = 'html', $notification = array(), $entry = array() ) {
		self::$error   = '';
		self::$sending = null;
		if ( is_array( $email ) && empty( $email['abort_email'] ) ) {
			self::$sending = array(
				'f' => (int) ( is_array( $entry ) ? ( $entry['form_id'] ?? 0 ) : 0 ),
				'n' => (string) ( is_array( $notification ) ? ( $notification['name'] ?? '' ) : '' ),
			);
		}
		return $email;
	}

	/** wp_mail failed. Kept only while a Gravity Forms notification is going out. */
	public static function mail_failed( $error ): void {
		if ( null === self::$sending ) {
			return;
		}
		self::$error = self::clean_error( self::message( $error ) );
	}

	/**
	 * Gravity Forms says the email failed. Used when wp_mail gave no reason.
	 *
	 * @param mixed $error A WP_Error or a message.
	 */
	public static function gf_failed( $error = null, $email = array(), $entry = array() ): void {
		if ( '' === self::$error ) {
			self::$error = self::clean_error( self::message( $error ) );
		}
	}

	/**
	 * Gravity Forms finished one email. Its arguments are $is_success, $to,
	 * $subject, $message, $headers, $attachments, $message_format, $from,
	 * $from_name, $bcc, $reply_to, $entry and $cc. Only the first and the
	 * entry's form id are read; older versions pass fewer, so each is checked.
	 */
	public static function after_send(): void {
		$args    = func_get_args();
		$result  = func_num_args() > 0 ? $args[0] : false;
		$entry   = func_num_args() > 11 && is_array( $args[11] ) ? $args[11] : array();
		$ok      = ! self::is_error( $result ) && (bool) $result;
		$sending = self::$sending ?? array( 'f' => 0, 'n' => '' );
		$form    = $sending['f'] ? (int) $sending['f'] : (int) ( $entry['form_id'] ?? 0 );
		$error   = self::$error;
		if ( ! $ok && '' === $error && self::is_error( $result ) ) {
			$error = self::clean_error( self::message( $result ) );
		}
		self::$sending = null;
		self::$error   = '';
		self::record( 'form', $form, (string) $sending['n'], $ok, $error );
	}

	private static function is_error( $thing ): bool {
		return function_exists( 'is_wp_error' ) ? is_wp_error( $thing ) : ( is_object( $thing ) && method_exists( $thing, 'get_error_message' ) );
	}

	/** The message from a WP_Error or a string, or ''. */
	private static function message( $error ): string {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			return (string) $error->get_error_message();
		}
		return is_string( $error ) ? $error : '';
	}

	/** An error message with every email address taken out, one line, 160 characters at most. */
	public static function clean_error( string $error ): string {
		// An address, with or without <angle brackets>. A full stop after it stays.
		$error = (string) preg_replace( '/<?[^\s<>"\'(),;:\[\]@]+@[^\s<>"\'(),;:\[\]@.]+(?:\.[^\s<>"\'(),;:\[\]@.]+)*>?/', '[address]', $error );
		$error = (string) preg_replace( '/<[^>]*>/', ' ', $error );
		$error = trim( (string) preg_replace( '/\s+/', ' ', $error ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 160 ) : substr( $error, 0, 160 );
	}

	/** What is kept, with defaults. */
	public static function data(): array {
		$d = get_option( self::OPTION, array() );
		$d = is_array( $d ) ? $d : array();
		return array(
			'since'     => (int) ( $d['since'] ?? 0 ),
			'log'       => is_array( $d['log'] ?? null ) ? array_values( $d['log'] ) : array(),
			'tally'     => is_array( $d['tally'] ?? null ) ? $d['tally'] : array(),
			'last_fail' => is_array( $d['last_fail'] ?? null ) ? $d['last_fail'] : array(),
		);
	}

	/**
	 * Note one try. $kind is "form" (a Gravity Forms notification) or "test"
	 * (the Send a test button). Tests go in the log only, never the counts.
	 */
	public static function record( string $kind, int $form, string $name, bool $ok, string $error = '', ?int $now = null ): void {
		$now  = $now ?? time();
		$data = self::data();
		$kind = 'test' === $kind ? 'test' : 'form';
		$name = trim( (string) preg_replace( '/[<>]/', '', $name ) );
		$row  = array(
			'k'  => $kind,
			'f'  => max( 0, $form ),
			'n'  => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 ),
			'ok' => $ok,
			'e'  => $ok ? '' : self::clean_error( $error ),
			't'  => $now,
		);
		array_unshift( $data['log'], $row );
		$data['log'] = array_slice( $data['log'], 0, self::CAP );
		if ( 'form' === $kind ) {
			$day = gmdate( 'Y-m-d', $now );
			$f   = (string) $row['f'];
			$cur = $data['tally'][ $f ][ $day ] ?? array( 0, 0 );
			$cur[ $ok ? 0 : 1 ]          = (int) ( $cur[ $ok ? 0 : 1 ] ?? 0 ) + 1;
			$data['tally'][ $f ][ $day ] = array( (int) ( $cur[0] ?? 0 ), (int) ( $cur[1] ?? 0 ) );
			$cut = gmdate( 'Y-m-d', $now - self::DAYS * 86400 );
			foreach ( $data['tally'] as $id => $days ) {
				$days = array_filter( (array) $days, static fn( $d ) => (string) $d >= $cut, ARRAY_FILTER_USE_KEY );
				if ( $days ) {
					$data['tally'][ $id ] = $days;
				} else {
					unset( $data['tally'][ $id ] );
				}
			}
			if ( ! $ok ) {
				$data['last_fail'] = $row;
			}
		}
		if ( ! $data['since'] ) {
			$data['since'] = $now;
		}
		update_option( self::OPTION, $data, false );
	}

	/* ---- Counting ------------------------------------------------------ */

	/**
	 * Form emails sent and failed in the last 7 and 30 days (whole days, UTC,
	 * today included), and whether any failed in the last 24 hours.
	 *
	 * @return array{sent7:int, failed7:int, sent30:int, failed30:int, failed24:bool}
	 */
	public static function counts( array $data, int $now ): array {
		$from7  = gmdate( 'Y-m-d', $now - 6 * 86400 );
		$from30 = gmdate( 'Y-m-d', $now - ( self::DAYS - 1 ) * 86400 );
		$c      = array( 'sent7' => 0, 'failed7' => 0, 'sent30' => 0, 'failed30' => 0, 'failed24' => false );
		foreach ( (array) $data['tally'] as $days ) {
			foreach ( (array) $days as $day => $n ) {
				$day = (string) $day;
				if ( $day >= $from30 ) {
					$c['sent30']   += (int) ( $n[0] ?? 0 );
					$c['failed30'] += (int) ( $n[1] ?? 0 );
				}
				if ( $day >= $from7 ) {
					$c['sent7']   += (int) ( $n[0] ?? 0 );
					$c['failed7'] += (int) ( $n[1] ?? 0 );
				}
			}
		}
		$day_ago = $now - 86400;
		foreach ( (array) $data['log'] as $row ) {
			if ( 'form' === ( $row['k'] ?? 'form' ) && empty( $row['ok'] ) && (int) ( $row['t'] ?? 0 ) >= $day_ago ) {
				$c['failed24'] = true;
			}
		}
		if ( ! empty( $data['last_fail'] ) && (int) ( $data['last_fail']['t'] ?? 0 ) >= $day_ago ) {
			$c['failed24'] = true;
		}
		return $c;
	}

	/** The newest test in the log, or null. */
	public static function last_test( array $data ): ?array {
		foreach ( (array) $data['log'] as $row ) {
			if ( 'test' === ( $row['k'] ?? '' ) ) {
				return $row;
			}
		}
		return null;
	}

	/* ---- The Site status row ------------------------------------------- */

	/**
	 * The "Form emails sent" row, or null when Gravity Forms is not running.
	 * Entries are counted only when no form email was noted in 30 days.
	 */
	public static function row(): ?array {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return null;
		}
		$now  = time();
		$data = self::data();
		if ( ! $data['since'] ) {
			// Counting starts now, so entries from before this never read as missing emails.
			$data['since'] = $now;
			update_option( self::OPTION, $data, false );
		}
		$c       = self::counts( $data, $now );
		$entries = null;
		if ( ! $c['sent30'] && ! $c['failed30'] ) {
			$entries = self::entries_since( max( $now - self::DAYS * 86400, $data['since'] ) );
		}
		$title = static function ( int $id ): string {
			$form = $id && method_exists( 'GFAPI', 'get_form' ) ? GFAPI::get_form( $id ) : false;
			/* translators: %d: form id */
			return is_array( $form ) && '' !== (string) ( $form['title'] ?? '' ) ? (string) $form['title'] : sprintf( __( 'Form %d', 'scout-build' ), $id );
		};
		return self::build_row( $data, $now, $entries, $title );
	}

	/** Entries (not spam, not trash) since a time, or null when Gravity Forms can't say. */
	private static function entries_since( int $from ): ?int {
		if ( ! method_exists( 'GFAPI', 'count_entries' ) || $from >= time() ) {
			return 0;
		}
		try {
			$forms = (array) GFAPI::get_forms();
			if ( ! $forms ) {
				return 0;
			}
			$n = GFAPI::count_entries( 0, array( 'status' => 'active', 'start_date' => wp_date( 'Y-m-d H:i:s', $from ) ) );
			return is_numeric( $n ) ? (int) $n : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * The row from what is kept. Pure apart from the words, so it can be tested.
	 *
	 * @param int|null $entries Entries since counting started, within 30 days. Only read when nothing was noted in 30 days.
	 * @param callable $title   Form id to form title.
	 */
	public static function build_row( array $data, int $now, ?int $entries, callable $title ): array {
		$c    = self::counts( $data, $now );
		$row  = array( 'label' => __( 'Form emails sent', 'scout-build' ) );
		$test = self::last_test( $data );
		$tail = '';
		if ( $test && (int) $test['t'] >= $now - 7 * 86400 ) {
			$tail = ' ' . ( ! empty( $test['ok'] )
				/* translators: %s: date */
				? sprintf( __( 'Last test: sent %s.', 'scout-build' ), self::day( (int) $test['t'] ) )
				/* translators: 1: date, 2: the error */
				: sprintf( __( 'Last test failed %1$s: %2$s.', 'scout-build' ), self::day( (int) $test['t'] ), '' !== (string) $test['e'] ? rtrim( (string) $test['e'], '.' ) : __( 'no reason given', 'scout-build' ) ) );
		}
		$test_failed = $test && empty( $test['ok'] ) && (int) $test['t'] >= $now - 7 * 86400;

		if ( $c['failed7'] ) {
			$total = $c['sent7'] + $c['failed7'];
			$last  = $data['last_fail'];
			$form  = (int) ( $last['f'] ?? 0 );
			/* translators: 1: failed, 2: tried */
			$note = sprintf( __( '%1$d of %2$d failed.', 'scout-build' ), $c['failed7'], $total );
			if ( $last ) {
				$bits = array_filter( array( $title( $form ), (string) ( $last['n'] ?? '' ), self::day( (int) ( $last['t'] ?? $now ) ) ), static fn( $b ) => '' !== $b );
				$why  = '' !== (string) ( $last['e'] ?? '' ) ? rtrim( (string) $last['e'], '.' ) : __( 'no reason given', 'scout-build' );
				/* translators: 1: form, notification and date, 2: the error */
				$note .= ' ' . sprintf( __( 'Last: %1$s: %2$s.', 'scout-build' ), implode( ', ', $bits ), $why );
			}
			$note .= ' ' . __( 'Check the Site email row, then the form\'s notifications.', 'scout-build' );
			return $row + array(
				/* translators: 1: failed, 2: tried */
				'value'     => sprintf( __( '%1$d of %2$d failed', 'scout-build' ), $c['failed7'], $total ),
				'state'     => $c['failed24'] ? 'bad' : 'warn',
				'note'      => $note . $tail,
				'link'      => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . $form ),
				'link_text' => __( 'Notifications', 'scout-build' ),
			);
		}
		$failed_test = array(
			'value' => __( 'Test failed', 'scout-build' ),
			'state' => 'warn',
		);
		if ( $c['sent7'] ) {
			return $row + ( $test_failed ? $failed_test : array(
				/* translators: %d: emails sent */
				'value' => sprintf( _n( '%d sent', '%d sent', $c['sent7'], 'scout-build' ), $c['sent7'] ),
				'state' => 'good',
			) ) + array(
				/* translators: %d: emails sent */
				'note' => sprintf( _n( '%d sent in the last 7 days, none failed.', '%d sent in the last 7 days, none failed.', $c['sent7'], 'scout-build' ), $c['sent7'] ) . $tail,
			);
		}
		if ( $c['sent30'] || $c['failed30'] ) {
			return $row + ( $test_failed ? $failed_test : array(
				'value' => __( 'None this week', 'scout-build' ),
				'state' => 'good',
			) ) + array(
				/* translators: %d: emails sent */
				'note' => sprintf( _n( 'No form emails in the last 7 days. %d sent in the last 30.', 'No form emails in the last 7 days. %d sent in the last 30.', $c['sent30'], 'scout-build' ), $c['sent30'] ) . $tail,
			);
		}
		if ( $entries ) {
			$full = (int) $data['since'] <= $now - self::DAYS * 86400;
			$lead = $full
				? __( 'No form emails in 30 days', 'scout-build' )
				/* translators: %s: date counting started */
				: sprintf( __( 'No form emails since %s', 'scout-build' ), self::day( (int) $data['since'] ) );
			return $row + array(
				'value'     => __( 'None sent', 'scout-build' ),
				'state'     => 'warn',
				/* translators: 1: "No form emails in 30 days" or "since" a date, 2: entries */
				'note'      => sprintf( _n( '%1$s, but the forms got %2$d entry. Notifications may be off.', '%1$s, but the forms got %2$d entries. Notifications may be off.', $entries, 'scout-build' ), $lead, $entries ) . $tail,
				'link'      => admin_url( 'admin.php?page=gf_edit_forms' ),
				'link_text' => __( 'Forms', 'scout-build' ),
			);
		}
		return $row + ( $test_failed ? $failed_test : array(
			'value' => __( 'None yet', 'scout-build' ),
			'state' => 'good',
		) ) + array(
			'note' => __( 'No form emails yet. Each one is counted here when it goes out.', 'scout-build' ) . $tail,
		);
	}

	/** A short date, like "Nov 5". */
	private static function day( int $t ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'M j', $t ) : gmdate( 'M j', $t );
	}

	/* ---- Send a test --------------------------------------------------- */

	/** Where the test goes: the shared notice address. */
	public static function notice_to(): string {
		return function_exists( 'scout_core_notice_email' ) ? (string) scout_core_notice_email() : (string) get_option( 'admin_email' );
	}

	/** True for the people who look after the site. */
	private static function allowed(): bool {
		return current_user_can( 'manage_options' ) && class_exists( 'Scout_Hub_Access' ) && Scout_Hub_Access::is_operator();
	}

	/** The Send a test box on Site status. Operators only, and only with Gravity Forms. */
	public static function render_test(): void {
		if ( ! self::allowed() || ! class_exists( 'GFAPI' ) ) {
			return;
		}
		?>
		<section class="sh-box" id="form-mail-test" style="margin-top:12px">
			<h3 class="sh-title"><?php esc_html_e( 'Test form email', 'scout-build' ); ?></h3>
			<p class="sh-note">
				<?php
				/* translators: %s: email address */
				echo esc_html( sprintf( __( 'Sends a plain email to %s the same way form emails go out. No form is sent and no entry is saved.', 'scout-build' ), self::notice_to() ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST ); ?>">
				<?php wp_nonce_field( self::TEST ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Send a test', 'scout-build' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Send the test through wp_mail and note the result as kind "test". It
	 * touches no form and makes no entry.
	 *
	 * @return bool Whether wp_mail took it.
	 */
	public static function test_send(): bool {
		$to    = self::notice_to();
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$error = '';
		$catch = static function ( $e ) use ( &$error ): void {
			$error = self::message( $e );
		};
		$plain = static fn() => 'text/plain';
		add_action( 'wp_mail_failed', $catch );
		add_filter( 'wp_mail_content_type', $plain );
		$ok = false;
		if ( is_email( $to ) ) {
			/* translators: %s: site name */
			$subject = sprintf( __( 'Test from %s Site status', 'scout-build' ), $site );
			/* translators: 1: site name, 2: site address */
			$body = sprintf( __( "This is a test from Site status on %1\$s (%2\$s).\n\nForm emails go out the same way. No form was sent and no entry was saved.", 'scout-build' ), $site, home_url( '/' ) );
			$ok   = (bool) wp_mail( $to, $subject, $body );
		} else {
			$error = __( 'There is no address to send to.', 'scout-build' );
		}
		remove_filter( 'wp_mail_content_type', $plain );
		remove_action( 'wp_mail_failed', $catch );
		self::record( 'test', 0, '', $ok, $error );
		return $ok;
	}

	/** The Send a test button. */
	public static function send_test(): void {
		check_admin_referer( self::TEST );
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Only the people who look after this site can send a test.', 'scout-build' ), 403 );
		}
		$ok   = self::test_send();
		$last = self::last_test( self::data() );
		$msg  = $ok
			/* translators: %s: email address */
			? sprintf( __( 'Test sent to %s. Check that inbox, and junk the first time.', 'scout-build' ), self::notice_to() )
			/* translators: %s: the error */
			: sprintf( __( 'The test didn\'t go out: %s', 'scout-build' ), '' !== (string) ( $last['e'] ?? '' ) ? (string) $last['e'] : __( 'WordPress gave no reason.', 'scout-build' ) );
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => $ok ? 'success' : 'error' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'health' ) . '#form-mail-test' );
		exit;
	}
}
