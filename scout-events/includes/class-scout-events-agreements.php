<?php
/**
 * Agreements and the newsletter opt-in, for sign-ups and paid tickets.
 *
 * An event can ask each ticket holder to agree before they get a ticket: the
 * site's community agreements (a link set once under Events, Settings), an
 * optional waiver the owner writes on the event, an age confirmation when the
 * event has a minimum age, and a yes or no on photos. They tick, type their
 * name to sign, and the ticket keeps the signature, the date and time, and
 * the version of the words they agreed to.
 *
 * Versions: the waiver, the agreements link and name, the minimum age and the
 * photo question are hashed into a short id. The first time an id is used,
 * the exact words are stored on the event (_scout_event_waivers), so a
 * signature always points at what the person read, even after an edit.
 *
 * The newsletter box is offered only when something listens to
 * scout_newsletter_optin (a newsletter connector), and starts unticked.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Agreements {

	const VERSIONS = '_scout_event_waivers';

	public static function boot(): void {
		add_action( 'admin_post_nopriv_scout_events_agree', array( __CLASS__, 'agree_action' ) );
		add_action( 'admin_post_scout_events_agree', array( __CLASS__, 'agree_action' ) );
	}

	/* ---------- what the event asks ---------- */

	public static function required( array $e ): bool {
		return ! empty( $e['agree'] );
	}

	public static function asks_photo( array $e ): bool {
		return ! empty( $e['photo_ask'] );
	}

	/** Does this event ask anything of each ticket holder? */
	public static function asks( array $e ): bool {
		return self::required( $e ) || self::asks_photo( $e );
	}

	/** Does this ticket still owe an answer? */
	public static function owed( array $row, array $e ): bool {
		return ( self::required( $e ) && empty( $row['agreed'] ) ) || ( self::asks_photo( $e ) && '' === (string) ( $row['photo'] ?? '' ) );
	}

	/**
	 * The version id of what this event asks today. Records the exact words on
	 * the event the first time the id is seen.
	 */
	public static function version( int $event_id, ?array $e = null ): string {
		$e     = $e ?? Scout_Events::event( $event_id );
		$s     = Scout_Events::settings();
		$words = array(
			'waiver'  => trim( (string) $e['waiver'] ),
			'url'     => $s['agreements_url'],
			'label'   => $s['agreements_label'],
			'min_age' => (int) $e['min_age'],
			'photo'   => self::asks_photo( $e ) ? 1 : 0,
		);
		$id  = substr( md5( (string) wp_json_encode( $words ) ), 0, 8 );
		$all = self::versions( $event_id );
		if ( ! isset( $all[ $id ] ) ) {
			$all[ $id ] = $words + array( 'since' => current_time( 'mysql' ) );
			update_post_meta( $event_id, self::VERSIONS, $all );
		}
		return $id;
	}

	/** @return array<string,array> every version this event has used, oldest first */
	public static function versions( int $event_id ): array {
		$all = get_post_meta( $event_id, self::VERSIONS, true );
		return is_array( $all ) ? $all : array();
	}

	/** "Nov 3, 2026 (a1b2c3d4)" for the door list and the spreadsheet. */
	public static function version_label( int $event_id, string $id ): string {
		if ( '' === $id ) {
			return '';
		}
		$v = self::versions( $event_id )[ $id ] ?? null;
		return $v ? mysql2date( 'M j, Y', (string) $v['since'] ) . ' (' . $id . ')' : $id;
	}

	/* ---------- the fields ---------- */

	/** The waiver in a box people can scroll, shown once above the tick boxes. */
	public static function waiver_box( array $e, string $title = '' ): string {
		$text = trim( (string) $e['waiver'] );
		if ( ! self::required( $e ) || '' === $text ) {
			return '';
		}
		$title = '' !== $title ? $title : __( 'Waiver', 'scout-events' );
		return '<div class="se-waiver" tabindex="0" role="region" aria-label="' . esc_attr( $title ) . '"><p class="se-waiver-title">' . esc_html( $title ) . '</p>' . wpautop( esc_html( $text ) ) . '</div>';
	}

	/** One line for the event page: what each ticket holder is asked at checkout. */
	public static function notice( array $e ): string {
		if ( ! self::required( $e ) ) {
			return '';
		}
		$s    = Scout_Events::settings();
		$what = '' !== $s['agreements_url'] ? '<a href="' . esc_url( $s['agreements_url'] ) . '">' . esc_html( $s['agreements_label'] ) . '</a>' : esc_html__( 'the event’s terms', 'scout-events' );
		$text = (int) $e['min_age'] > 0
			/* translators: 1: link to the community agreements, 2: minimum age. */
			? sprintf( __( 'Each ticket holder agrees to the %1$s and confirms they are %2$d or older when they get their ticket.', 'scout-events' ), $what, (int) $e['min_age'] )
			/* translators: %s: link to the community agreements. */
			: sprintf( __( 'Each ticket holder agrees to the %s when they get their ticket.', 'scout-events' ), $what );
		return '<p class="se-agree-notice">' . wp_kses( $text, array( 'a' => array( 'href' => true ) ) ) . '</p>';
	}

	/** The "I agree" words, with the agreements page linked. */
	private static function agree_words( array $e ): string {
		$s      = Scout_Events::settings();
		$link   = '' !== $s['agreements_url'] ? '<a href="' . esc_url( $s['agreements_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['agreements_label'] ) . '</a>' : '';
		$waiver = '' !== trim( (string) $e['waiver'] );
		if ( '' !== $link && $waiver ) {
			/* translators: %s: link to the community agreements. */
			return sprintf( __( 'I have read and agree to the %s and the waiver above.', 'scout-events' ), $link );
		}
		if ( '' !== $link ) {
			/* translators: %s: link to the community agreements. */
			return sprintf( __( 'I have read and agree to the %s.', 'scout-events' ), $link );
		}
		return $waiver ? __( 'I have read and agree to the waiver above.', 'scout-events' ) : __( 'I agree to the event’s terms.', 'scout-events' );
	}

	/**
	 * The tick boxes, photo choice and signature for one ticket holder.
	 *
	 * @param string $name   Form name prefix, such as "se_agree" or "se_att[key][0]".
	 * @param string $id     Id prefix, unique on the page.
	 * @param array  $values What was posted, to fill the fields again.
	 * @param array  $o      'group' => true when one person signs for guests too;
	 *                       'holder' => true at checkout, where the buyer may fill it for someone else.
	 */
	public static function fields( array $e, string $name, string $id, array $values = array(), array $o = array() ): string {
		if ( ! self::asks( $e ) ) {
			return '';
		}
		$out = '<div class="se-agree">';
		if ( self::required( $e ) ) {
			$out .= '<p class="se-agree-row"><label><input type="checkbox" name="' . esc_attr( $name . '[agree]' ) . '" value="1" required' . checked( ! empty( $values['agree'] ), true, false ) . '> <span>' . wp_kses( self::agree_words( $e ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</span></label></p>';
			if ( (int) $e['min_age'] > 0 ) {
				$age  = (int) $e['min_age'];
				$text = ! empty( $o['group'] )
					/* translators: %d: minimum age. */
					? sprintf( __( 'I am %d or older, and so is everyone coming with me.', 'scout-events' ), $age )
					: ( ! empty( $o['holder'] )
						/* translators: %d: minimum age. */
						? sprintf( __( 'The ticket holder is %d or older.', 'scout-events' ), $age )
						/* translators: %d: minimum age. */
						: sprintf( __( 'I am %d or older.', 'scout-events' ), $age ) );
				$out .= '<p class="se-agree-row"><label><input type="checkbox" name="' . esc_attr( $name . '[age]' ) . '" value="1" required' . checked( ! empty( $values['age'] ), true, false ) . '> <span>' . esc_html( $text ) . '</span></label></p>';
			}
		}
		if ( self::asks_photo( $e ) ) {
			$photo = (string) ( $values['photo'] ?? '' );
			$out  .= '<fieldset class="se-agree-photo"><legend>' . esc_html__( 'Photos and video may be taken at this event. Can you be in them?', 'scout-events' ) . '</legend>'
				. '<label><input type="radio" name="' . esc_attr( $name . '[photo]' ) . '" value="yes" required' . checked( 'yes', $photo, false ) . '> ' . esc_html__( 'Yes, I can be in photos', 'scout-events' ) . '</label>'
				. '<label><input type="radio" name="' . esc_attr( $name . '[photo]' ) . '" value="no"' . checked( 'no', $photo, false ) . '> ' . esc_html__( 'No photos of me, please', 'scout-events' ) . '</label></fieldset>';
		}
		if ( self::required( $e ) ) {
			$label = ! empty( $o['holder'] ) ? __( 'Signature: type the ticket holder’s full name', 'scout-events' ) : __( 'Signature: type your full name', 'scout-events' );
			$out  .= '<p class="se-s-field se-agree-sign"><label for="' . esc_attr( $id . '-sign' ) . '">' . esc_html( $label ) . '</label><input type="text" class="input-text" id="' . esc_attr( $id . '-sign' ) . '" name="' . esc_attr( $name . '[sign]' ) . '" autocomplete="off" required value="' . esc_attr( (string) ( $values['sign'] ?? '' ) ) . '"></p>';
		}
		return $out . '</div>';
	}

	/**
	 * Check what was posted for one ticket holder.
	 *
	 * @return array{errors:array<string,string>,data:array<string,mixed>} data holds
	 *         the columns for the ticket: agreed, signature, waiver_version, age_ok, photo.
	 */
	public static function collect( array $in, array $e, int $event_id, string $holder ): array {
		$errors = array();
		$data   = array();
		if ( self::required( $e ) ) {
			$sign = sanitize_text_field( (string) ( $in['sign'] ?? '' ) );
			if ( empty( $in['agree'] ) ) {
				$errors['agree'] = __( 'Please tick that you agree.', 'scout-events' );
			}
			if ( (int) $e['min_age'] > 0 && empty( $in['age'] ) ) {
				/* translators: %d: minimum age. */
				$errors['age'] = sprintf( __( 'Please confirm the age: this event is %d and over.', 'scout-events' ), (int) $e['min_age'] );
			}
			if ( '' === $sign ) {
				$errors['sign'] = __( 'Please type the full name to sign.', 'scout-events' );
			} elseif ( '' !== $holder && self::plain( $sign ) !== self::plain( $holder ) ) {
				/* translators: %s: the name on the ticket. */
				$errors['sign'] = sprintf( __( 'The signature should match the name on the ticket: %s.', 'scout-events' ), $holder );
			}
			$data['agreed']         = current_time( 'mysql' );
			$data['signature']      = mb_substr( $sign, 0, 190 );
			$data['waiver_version'] = self::version( $event_id, $e );
			$data['age_ok']         = (int) $e['min_age'] > 0 ? 1 : 0;
		}
		if ( self::asks_photo( $e ) ) {
			$photo = sanitize_key( (string) ( $in['photo'] ?? '' ) );
			if ( ! in_array( $photo, array( 'yes', 'no' ), true ) ) {
				$errors['photo'] = __( 'Please choose yes or no for photos.', 'scout-events' );
			}
			$data['photo'] = in_array( $photo, array( 'yes', 'no' ), true ) ? $photo : '';
		}
		return array(
			'errors' => $errors,
			'data'   => $errors ? array() : $data,
		);
	}

	/** Names compared without case, extra spaces or punctuation. */
	private static function plain( string $s ): string {
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( remove_accents( $s ) ) );
	}

	/* ---------- signing later, on the ticket page ---------- */

	/**
	 * For a ticket that still owes an answer (bought before the event asked, or
	 * from an order placed without the checkout form), the ticket page asks.
	 */
	public static function ticket_form( array $row, array $e ): string {
		if ( 'going' !== $row['status'] || ! self::owed( $row, $e ) || Scout_Events::is_past( $e ) ) {
			return '';
		}
		$msg = isset( $_GET['se_agree'] ) ? sanitize_key( wp_unslash( $_GET['se_agree'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$out = '<form class="se-ticket-agree" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<p class="se-ticket-warn">' . esc_html__( 'Before the event: please read and answer below. It takes a minute.', 'scout-events' ) . '</p>';
		if ( 'missing' === $msg ) {
			$out .= '<p class="se-s-note is-bad" role="status">' . esc_html__( 'Something was missing. Please tick each box, and type the name exactly as it is on the ticket.', 'scout-events' ) . '</p>';
		}
		$out .= self::waiver_box( $e )
			. '<input type="hidden" name="action" value="scout_events_agree">'
			. '<input type="hidden" name="se_code" value="' . esc_attr( $row['code'] ) . '">'
			. wp_nonce_field( 'scout_events_agree_' . $row['code'], '_se_agree', false, false )
			. self::fields( $e, 'se_agree', 'se-ta', array(), array( 'group' => (int) $row['guests'] > 0 ) )
			. '<p><button type="submit" class="se-button wp-element-button">' . esc_html__( 'Save my answers', 'scout-events' ) . '</button></p></form>';
		return $out;
	}

	/** admin-post: scout_events_agree. The ticket code is the key. */
	public static function agree_action(): void {
		$code  = isset( $_POST['se_code'] ) ? sanitize_text_field( wp_unslash( $_POST['se_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$nonce = isset( $_POST['_se_agree'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_agree'] ) ) : '';
		$row   = Scout_Events_RSVP::find( $code );
		if ( ! $row || ! wp_verify_nonce( $nonce, 'scout_events_agree_' . $code ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and try again.', 'scout-events' ), 400 );
		}
		$event = (int) $row['event_id'];
		$e     = Scout_Events::event( $event );
		$in    = isset( $_POST['se_agree'] ) ? (array) wp_unslash( $_POST['se_agree'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in collect().
		$got   = self::collect( $in, $e, $event, (string) $row['name'] );
		$url   = Scout_Events_RSVP::ticket_url( $row );
		if ( $got['errors'] ) {
			wp_safe_redirect( add_query_arg( 'se_agree', 'missing', $url ) . '#se-ticket' );
			exit;
		}
		Scout_Events_RSVP::update( (int) $row['id'], $got['data'] );
		wp_safe_redirect( add_query_arg( 'se_agree', 'done', $url ) . '#se-ticket' );
		exit;
	}

	/* ---------- the newsletter ---------- */

	/** Offer the box only when a newsletter connector is listening. */
	public static function offer_newsletter(): bool {
		return (bool) apply_filters( 'scout_events_offer_newsletter', (bool) has_action( 'scout_newsletter_optin' ) );
	}

	/** The words on the box, shown to people and saved with their consent. */
	public static function newsletter_label(): string {
		return __( 'Send me the newsletter', 'scout-events' );
	}

	public static function newsletter_field( string $name, string $id, bool $checked = false ): string {
		if ( ! self::offer_newsletter() ) {
			return '';
		}
		return '<p class="se-newsletter"><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( self::newsletter_label() ) . '</label></p>';
	}

	/**
	 * Someone ticked "Send me the newsletter". Fires scout_newsletter_optin for
	 * a connector (such as the Beehiiv one) to pass on; nothing is sent from here.
	 *
	 * @param string   $source "event-signup" or "event-checkout".
	 * @param int[]    $events The events it came from, for the tags.
	 */
	public static function optin( string $email, string $name, string $source, array $events ): void {
		$first = trim( (string) strtok( trim( $name ), ' ' ) );
		$tags  = array( 'event-checkout' === $source ? __( 'Ticket buyer', 'scout-events' ) : __( 'Event sign-up', 'scout-events' ) );
		foreach ( array_unique( array_map( 'intval', $events ) ) as $event ) {
			$tags[] = wp_specialchars_decode( get_the_title( $event ), ENT_QUOTES );
		}
		/** The tags sent with an opt-in: who they are, and the event names. */
		$tags = (array) apply_filters( 'scout_events_newsletter_tags', $tags, $source, $events );
		/**
		 * A person opted in to the newsletter, with consent stored on their ticket or order.
		 *
		 * @param string   $email      Their email.
		 * @param string   $first_name First name, may be empty.
		 * @param string   $source     "event-signup" or "event-checkout".
		 * @param string[] $tags       "Ticket buyer" or "Event sign-up", then the event names.
		 * @param array    $context    wording (the exact box label they ticked), page and url (the
		 *                             event's address). Listeners that take four arguments never see it.
		 */
		$events = array_values( array_unique( array_map( 'intval', $events ) ) );
		$page   = $events ? (string) get_permalink( $events[0] ) : '';
		$context = array(
			'wording' => self::newsletter_label(),
			'page'    => $page,
			'url'     => $page,
		);
		do_action( 'scout_newsletter_optin', strtolower( $email ), $first, $source, array_values( array_filter( array_map( 'strval', $tags ) ) ), $context );
	}
}
