<?php
/**
 * The front door: settings, the Beehiiv key, and the one action every other
 * plugin calls when someone ticks the newsletter box.
 *
 *   do_action( 'scout_newsletter_optin', $email, $first_name, $source, $tags, $context );
 *
 * $source is where they joined: event, ticket, membership, booking or form
 * (anything else is accepted and tagged only with $tags). $tags are extra
 * Beehiiv tags. $context is optional: wording (the exact words next to the
 * box), url (the page), campaign (for example the event's slug), last_name.
 *
 * Calling the action means the person ticked a box. Nothing here ever adds
 * someone without one: imports and "everyone who bought a ticket" are not a
 * thing this plugin does (FOR-KAI #116: existing customers are invited).
 *
 * The call only writes a consent record and queues the send, so a visitor's
 * checkout or form never waits on Beehiiv. The queue does the rest.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter {

	const OPTION     = 'scout_newsletter';
	const KEY_OPTION = 'scout_newsletter_key';
	const LOG        = 'scout_newsletter_log';
	const SUPPRESS   = 'scout_newsletter_unsubscribed';
	const LOG_MAX    = 100;

	/** Tag map keys. Labels come from sources(), which needs translations loaded. */
	const SOURCE_KEYS = array( 'event', 'ticket', 'membership', 'booking', 'form' );

	/** The sources the tag map knows, in the order the settings show them. */
	public static function sources(): array {
		return array(
			'event'      => __( 'Event sign-up', 'scout-newsletter' ),
			'ticket'     => __( 'Paid ticket', 'scout-newsletter' ),
			'membership' => __( 'Membership joined', 'scout-newsletter' ),
			'booking'    => __( 'Booking', 'scout-newsletter' ),
			'form'       => __( 'Form or sign-up box', 'scout-newsletter' ),
		);
	}

	public static function boot(): void {
		add_action( 'scout_newsletter_optin', array( __CLASS__, 'optin' ), 10, 5 );
	}

	/* ---------- settings ---------- */

	public static function defaults(): array {
		return array(
			'enabled'       => 0,
			'publication'   => '',
			'name'          => '',
			'wording'       => '',
			'welcome'       => 0,
			'double_opt'    => 'not_set',
			'automation'    => '',
			'utm_source'    => '',
			'field_first'   => '',
			'field_joined'  => '',
			'field_plan'    => '',
			'keep_current'  => 0,
			'checkout_box'  => 0,
			'members_box'   => '',
			'thanks'        => '',
			'tags'          => array_fill_keys( self::SOURCE_KEYS, '' ),
		);
	}

	public static function settings(): array {
		$s         = (array) get_option( self::OPTION, array() );
		$d         = self::defaults();
		$out       = array_merge( $d, array_intersect_key( $s, $d ) );
		$out['tags'] = array_merge( $d['tags'], array_intersect_key( (array) $out['tags'], $d['tags'] ) );
		if ( defined( 'SCOUT_BEEHIIV_PUBLICATION' ) && '' !== (string) SCOUT_BEEHIIV_PUBLICATION ) {
			$out['publication'] = (string) SCOUT_BEEHIIV_PUBLICATION;
		}
		return $out;
	}

	/** The newsletter's name, for the default wording. */
	public static function name(): string {
		$name = trim( (string) self::settings()['name'] );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** The words next to the box, unless the form that showed it says otherwise. */
	public static function wording(): string {
		$w = trim( (string) self::settings()['wording'] );
		if ( '' !== $w ) {
			return $w;
		}
		/* translators: %s: newsletter name. */
		return sprintf( __( 'Send me the %s newsletter by email. I can unsubscribe at any time.', 'scout-newsletter' ), self::name() );
	}

	/** Where utm_source points: the site's own address by default. */
	public static function utm_source(): string {
		$u = trim( (string) self::settings()['utm_source'] );
		return '' !== $u ? $u : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/* ---------- the key ---------- */

	/** True when the key comes from wp-config.php. */
	public static function key_in_config(): bool {
		return defined( 'SCOUT_BEEHIIV_KEY' ) && '' !== (string) SCOUT_BEEHIIV_KEY;
	}

	public static function key(): string {
		if ( self::key_in_config() ) {
			return (string) SCOUT_BEEHIIV_KEY;
		}
		return self::decrypt( (string) get_option( self::KEY_OPTION, '' ) );
	}

	public static function save_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( self::KEY_OPTION );
			return;
		}
		update_option( self::KEY_OPTION, self::encrypt( $key ), false );
	}

	/** A key and a publication: enough to talk to Beehiiv. */
	public static function connected(): bool {
		return '' !== self::key() && '' !== (string) self::settings()['publication'];
	}

	/** Connected and switched on: sign-ups go out. */
	public static function sending(): bool {
		return self::connected() && ! empty( self::settings()['enabled'] );
	}

	private static function cipher_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'scout-beehiiv', true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p:' ) ) {
			return $raw;
		}
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 17 ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}

	/* ---------- the action ---------- */

	/**
	 * Someone ticked the box. Save the agreement and queue the send.
	 *
	 * @param string               $email      Their email.
	 * @param string               $first_name Their first name, if known.
	 * @param string               $source     event, ticket, membership, booking, form, or another word.
	 * @param array|string         $tags       Extra Beehiiv tags.
	 * @param array<string,string> $context    wording, url, campaign.
	 * @return int The consent record id, or 0 when nothing was saved.
	 */
	public static function optin( $email, $first_name = '', $source = '', $tags = array(), $context = array() ): int {
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$source  = sanitize_key( (string) $source );
		$context = is_array( $context ) ? $context : array();
		$tags    = self::tags_for( $source, is_array( $tags ) ? $tags : explode( ',', (string) $tags ), $email );
		$wording = trim( wp_strip_all_tags( (string) ( $context['wording'] ?? '' ) ) );
		$url     = esc_url_raw( (string) ( $context['url'] ?? '' ) );
		if ( '' === $url ) {
			$ref = wp_get_referer();
			$url = $ref ? esc_url_raw( $ref ) : '';
		}
		try {
			$id = Scout_Newsletter_Store::add(
				array(
					'kind'       => 'optin',
					'email'      => $email,
					'first_name' => sanitize_text_field( (string) $first_name ),
					'source'     => $source,
					'tags'       => $tags,
					'wording'    => '' !== $wording ? $wording : self::wording(),
					'url'        => $url,
					'campaign'   => sanitize_text_field( (string) ( $context['campaign'] ?? '' ) ),
					'status'     => self::sending() ? 'queued' : 'held',
				)
			);
		} catch ( \Throwable $e ) {
			// A newsletter hiccup must never stop a checkout, booking or form.
			return 0;
		}
		if ( $id && self::sending() ) {
			Scout_Newsletter_Queue::schedule();
		}
		return $id;
	}

	/**
	 * Keep someone who already gets the newsletter up to date: a type tag, the
	 * member plan field. Never subscribes anyone; skipped for people who are
	 * not on the list. Only runs when "Keep subscribers up to date" is on.
	 *
	 * @param array<string,string> $fields Beehiiv custom field name => value.
	 */
	public static function update( string $email, array $tags, array $fields = array(), string $source = '' ): int {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || empty( self::settings()['keep_current'] ) || ! self::sending() ) {
			return 0;
		}
		$tags   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tags ) ) ) );
		$fields = array_filter( $fields, static fn( $k ) => '' !== trim( (string) $k ), ARRAY_FILTER_USE_KEY );
		if ( ! $tags && ! $fields ) {
			return 0;
		}
		try {
			$id = Scout_Newsletter_Store::add(
				array(
					'kind'   => 'update',
					'email'  => $email,
					'source' => sanitize_key( $source ),
					'tags'   => $tags,
					'fields' => array_map( 'sanitize_text_field', array_map( 'strval', $fields ) ),
					'status' => 'queued',
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
		if ( $id ) {
			Scout_Newsletter_Queue::schedule();
		}
		return $id;
	}

	/** The tag for a source from the tag map, plus any extra tags. */
	public static function tags_for( string $source, array $extra = array(), string $email = '' ): array {
		$map  = self::settings()['tags'];
		$base = '' !== $source && isset( $map[ $source ] ) ? (string) $map[ $source ] : '';
		$tags = array_merge( explode( ',', $base ), $extra );
		$tags = array_values( array_unique( array_filter( array_map( static fn( $t ) => trim( sanitize_text_field( (string) $t ) ), $tags ) ) ) );
		/**
		 * Change the Beehiiv tags for a sign-up. Beehiiv allows 100 tags per
		 * publication, so tag by type (Event, Ticket), never one per event.
		 */
		return array_values( (array) apply_filters( 'scout_newsletter_tags', $tags, $source, self::hash( $email ) ) );
	}

	/* ---------- privacy helpers ---------- */

	/** A short one-way fingerprint of an email: the only form logs ever hold. */
	public static function hash( string $email ): string {
		return '' === $email ? '' : substr( hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 12 );
	}

	/** "ka•••@example.com", for screens. */
	public static function mask( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		return substr( $email, 0, min( 2, $at ) ) . '•••' . substr( $email, $at );
	}

	/** Someone Beehiiv says unsubscribed: never sent again from this site. */
	public static function suppress( string $email ): void {
		$list                       = (array) get_option( self::SUPPRESS, array() );
		$list[ self::hash( $email ) ] = time();
		update_option( self::SUPPRESS, $list, false );
	}

	public static function suppressed( string $email ): bool {
		return isset( ( (array) get_option( self::SUPPRESS, array() ) )[ self::hash( $email ) ] );
	}

	/**
	 * Add a line to the activity log: when, whose (hashed), what, how it went.
	 * No names, no emails, no API responses.
	 */
	public static function log( string $email, string $what, string $result ): void {
		$log   = (array) get_option( self::LOG, array() );
		$log[] = array(
			't' => time(),
			'h' => self::hash( $email ),
			'w' => sanitize_key( $what ),
			'r' => sanitize_text_field( $result ),
		);
		update_option( self::LOG, array_slice( $log, -self::LOG_MAX ), false );
	}
}
