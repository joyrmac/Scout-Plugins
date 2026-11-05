<?php
/**
 * Searches that found nothing. When a visitor uses the site's own search box
 * and gets no results, the search words are counted, so the business can see
 * which pages or answers people look for and cannot find.
 *
 * What is kept, in one option (`scout_core_empty_searches`, not autoloaded):
 * each phrase with a count and the first and last day it was seen. Never an
 * IP address, a browser, the time of a search or anything tied to a person.
 * Words that look like an email, a phone number or a web address are dropped,
 * and so is anything longer than 8 words. At most 200 phrases; a phrase not
 * searched for 90 days is removed by the daily check.
 *
 * One visitor searching the same words again within 30 minutes counts once.
 * That uses a short-lived transient named by a salted hash of the words and
 * the IP address; the address itself is never stored.
 *
 * The switch is `empty_searches` in `scout_seo_defaults` (Search settings),
 * on unless set to '0'. Turning it off stops the count and clears the list.
 * Nothing on the public site changes: this only records and reports.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Empty_Searches {

	const OPTION   = 'scout_core_empty_searches';
	const SETTINGS = 'scout_seo_defaults';
	const KEY      = 'empty_searches';
	const ACTION   = 'scout_core_clear_empty_searches';
	const MAX      = 200;
	const DAYS     = 90;
	const SHOW     = 20;
	const CHARS    = 80;
	const WORDS    = 8;
	const ONCE_FOR = 1800;

	/** Core's existing daily check (Scout_SEO_Sitemap::CRON), scheduled on admin_init and cleared on deactivate. */
	const DAILY = 'scout_seo_sitemap_check';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'record' ), 99 );
		add_action( self::DAILY, array( __CLASS__, 'prune_saved' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_clear' ) );
		add_action( 'update_option_' . self::SETTINGS, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . self::SETTINGS, array( __CLASS__, 'settings_added' ), 10, 2 );
	}

	/* ---------- The switch ---------- */

	/** Is the list kept? On unless Search settings saved it off. */
	public static function on(): bool {
		$settings = get_option( self::SETTINGS, array() );
		return self::on_in( is_array( $settings ) ? $settings : array() );
	}

	/** @param array<string,mixed> $settings The saved Search settings. */
	public static function on_in( array $settings ): bool {
		return '0' !== (string) ( $settings[ self::KEY ] ?? '1' );
	}

	/** Turning the switch off clears the list. */
	public static function settings_changed( $old, $new ) {
		if ( ! self::on_in( is_array( $new ) ? $new : array() ) ) {
			self::clear();
		}
	}

	/** The first save of Search settings comes through add_option. */
	public static function settings_added( $option, $value ) {
		self::settings_changed( array(), $value );
	}

	/* ---------- Recording ---------- */

	/**
	 * The search words, cleaned: lowercase, trimmed, one space between words,
	 * at most 80 characters. Returns '' for anything not worth keeping.
	 */
	public static function normalize( $raw ): string {
		$text = strip_tags( (string) $raw );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		if ( '' === $text || ! self::keep( $text ) ) {
			return '';
		}
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::CHARS, 'UTF-8' ) : substr( $text, 0, self::CHARS );
		return rtrim( $text );
	}

	/**
	 * Drop anything that could be personal or is junk: an @, seven or more
	 * digits in a row (spaces, dots, dashes or brackets between them still
	 * count, so phone numbers are caught however they are written), a web
	 * address, more than 8 words, or no letters or numbers at all.
	 */
	public static function keep( string $text ): bool {
		if ( false !== strpos( $text, '@' ) ) {
			return false;
		}
		if ( preg_match( '/\d(?:[\s().\-]?\d){6,}/u', $text ) ) {
			return false;
		}
		if ( preg_match( '#(https?:|www\.|://|\b[a-z0-9\-]+\.(?:com|net|org|edu|gov|io|co|us|info|biz|me|app|dev)\b)#i', $text ) ) {
			return false;
		}
		if ( count( preg_split( '/\s+/u', trim( $text ) ) ) > self::WORDS ) {
			return false;
		}
		return (bool) preg_match( '/[\p{L}\p{N}]/u', $text );
	}

	/**
	 * Should this request be counted? Only the main search query on the front
	 * end, page one, with no results, for a visitor who is not a bot and not
	 * logged in to edit the site.
	 *
	 * @param object $query The main WP_Query.
	 */
	public static function wanted( $query ): bool {
		if ( ! is_object( $query ) || ! method_exists( $query, 'is_main_query' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}
		if ( ! $query->is_main_query() || ! $query->is_search() || $query->is_paged() || $query->is_feed() ) {
			return false;
		}
		if ( ! empty( $query->posts ) || (int) $query->found_posts > 0 ) {
			return false;
		}
		return ! self::is_bot( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against a pattern, never stored.
	}

	/** A simple user agent check: no agent, or one that names itself a bot or a tool. */
	public static function is_bot( string $agent ): bool {
		if ( '' === trim( $agent ) ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|scrap|fetch|preview|monitor|lighthouse|headless|curl|wget|python|java\/|go-http|httpclient|facebookexternalhit|bingpreview/i', $agent );
	}

	/** Count the current search when it found nothing. Runs on template_redirect. */
	public static function record( $query = null ) {
		if ( null === $query ) {
			$query = $GLOBALS['wp_query'] ?? null;
		}
		if ( ! self::on() || ! self::wanted( $query ) ) {
			return;
		}
		$phrase = self::normalize( $query->get( 's' ) );
		if ( '' === $phrase || ! self::first_time( $phrase ) ) {
			return;
		}
		$list = self::saved();
		$list = self::prune( $list, self::today() );
		$list = self::add( $list, $phrase, self::today() );
		update_option( self::OPTION, $list, false );
	}

	/**
	 * Has this visitor already searched these words in the last 30 minutes?
	 * The transient name is a salted hash of the words and the IP address; the
	 * address is never stored anywhere.
	 */
	private static function first_time( string $phrase ): bool {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored.
		if ( '' === $ip ) {
			return true;
		}
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : '';
		$key  = 'scout_es_' . substr( hash( 'sha256', $phrase . '|' . $ip . '|' . $salt ), 0, 32 );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::ONCE_FOR );
		return true;
	}

	/**
	 * Add one search to the list. When the list goes past 200, the phrases
	 * searched the fewest times go first, oldest first among those. The phrase
	 * just searched always stays, so a new search can still get in.
	 *
	 * @param array<string,array{n:int,first:string,last:string}> $list
	 * @return array<string,array{n:int,first:string,last:string}>
	 */
	public static function add( array $list, string $phrase, string $today ): array {
		if ( isset( $list[ $phrase ] ) ) {
			$list[ $phrase ]['n']    = (int) $list[ $phrase ]['n'] + 1;
			$list[ $phrase ]['last'] = $today;
		} else {
			$list[ $phrase ] = array( 'n' => 1, 'first' => $today, 'last' => $today );
		}
		if ( count( $list ) <= self::MAX ) {
			return $list;
		}
		$others = $list;
		unset( $others[ $phrase ] );
		uasort(
			$others,
			static function ( $a, $b ) {
				return array( (int) $a['n'], (string) $a['last'] ) <=> array( (int) $b['n'], (string) $b['last'] );
			}
		);
		foreach ( array_slice( array_keys( $others ), 0, count( $list ) - self::MAX ) as $drop ) {
			unset( $list[ $drop ] );
		}
		return $list;
	}

	/**
	 * Remove phrases not searched for more than 90 days.
	 *
	 * @param array<string,array{n:int,first:string,last:string}> $list
	 */
	public static function prune( array $list, string $today ): array {
		$now = strtotime( $today . ' 00:00:00 UTC' );
		foreach ( $list as $phrase => $row ) {
			$last = strtotime( (string) ( $row['last'] ?? '' ) . ' 00:00:00 UTC' );
			if ( false === $last || ( $now - $last ) > self::DAYS * 86400 ) {
				unset( $list[ $phrase ] );
			}
		}
		return $list;
	}

	/**
	 * The most searched phrases, most first; a tie goes to the one seen last.
	 *
	 * @param array<string,array{n:int,first:string,last:string}> $list
	 * @return array<string,array{n:int,first:string,last:string}>
	 */
	public static function top( array $list, int $limit = self::SHOW ): array {
		uasort(
			$list,
			static function ( $a, $b ) {
				return array( (int) $b['n'], (string) $b['last'] ) <=> array( (int) $a['n'], (string) $a['last'] );
			}
		);
		return array_slice( $list, 0, $limit, true );
	}

	/** The daily check: drop old phrases, or the whole list when the switch is off. */
	public static function prune_saved() {
		if ( ! self::on() ) {
			self::clear();
			return;
		}
		$list = self::saved();
		$next = self::prune( $list, self::today() );
		if ( $next === $list ) {
			return;
		}
		if ( $next ) {
			update_option( self::OPTION, $next, false );
		} else {
			delete_option( self::OPTION );
		}
	}

	/** @return array<string,array{n:int,first:string,last:string}> */
	public static function saved(): array {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	private static function today(): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/* ---------- Search settings: the switch row and the box ---------- */

	/** The switch, as a row in the Search settings form. */
	public static function render_switch() {
		$name = self::SETTINGS . '[' . self::KEY . ']';
		echo '<tr><th scope="row">' . esc_html__( 'Searches that found nothing', 'scout-core' ) . '</th><td>';
		printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $name ) );
		printf(
			'<label for="scout-seo-empty-searches"><input type="checkbox" id="scout-seo-empty-searches" name="%1$s" value="1"%2$s aria-describedby="scout-seo-empty-searches-help" /> %3$s</label>',
			esc_attr( $name ),
			self::on() ? ' checked="checked"' : '',
			esc_html__( 'Keep a list of searches that found nothing', 'scout-core' )
		);
		echo '<p class="description" id="scout-seo-empty-searches-help">' . esc_html__( 'Only the search words are kept, for 90 days, with nothing about who searched.', 'scout-core' ) . '</p>';
		echo '</td></tr>';
	}

	/** The box under the Search settings form. */
	public static function render_box() {
		echo '<section class="sh-box" id="scout-empty-searches"><h2 class="sh-title">' . esc_html__( 'Searches that found nothing', 'scout-core' ) . '</h2>';
		if ( ! self::on() ) {
			echo '<p class="sh-note">' . esc_html__( 'This list is off. Turn on “Keep a list of searches that found nothing” above and save.', 'scout-core' ) . '</p></section>';
			return;
		}
		$list = self::prune( self::saved(), self::today() );
		if ( ! $list ) {
			echo '<p class="sh-note">' . esc_html__( 'No empty searches in the last 90 days.', 'scout-core' ) . '</p></section>';
			return;
		}
		echo '<p>' . esc_html__( 'Add a page or an FAQ answer for the top ones.', 'scout-core' ) . '</p>';
		echo '<table class="widefat sh-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Search words', 'scout-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Times', 'scout-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last seen', 'scout-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		$format = (string) get_option( 'date_format', 'F j, Y' );
		foreach ( self::top( $list ) as $phrase => $row ) {
			$last = (string) $row['last'];
			$when = function_exists( 'mysql2date' ) ? (string) mysql2date( $format, $last . ' 00:00:00' ) : $last;
			echo '<tr><td>' . esc_html( (string) $phrase ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['n'] ) ) . '</td><td>' . esc_html( $when ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $list ) > self::SHOW ) {
			/* translators: 1: phrases shown, 2: phrases on the list */
			echo '<p class="sh-note">' . esc_html( sprintf( __( 'Showing the top %1$d of %2$d.', 'scout-core' ), self::SHOW, count( $list ) ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><button type="submit" class="button sh-danger">' . esc_html__( 'Clear the list', 'scout-core' ) . '</button></p>';
		echo '</form></section>';
	}

	/** The Clear the list button. */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		self::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=scout&tab=seo#scout-empty-searches' ) );
		exit;
	}
}
