<?php
/**
 * IndexNow: tell Bing (and Copilot, and the other engines that share IndexNow)
 * the moment a public page is published or updated, instead of waiting for the
 * next crawl. ChatGPT search draws on Bing among its sources, so this also
 * shortens the path into AI answers.
 *
 * The key is created once and served at /{key}.txt, which proves the site owns
 * the pings. Pings go out in one non-blocking request at the end of the page
 * load, and only from the live site (Scout_AEO::is_live()), so staging never
 * pings. Switch it off on the AI search screen.
 *
 * @package Scout_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_AEO_IndexNow {

	const KEY_OPTION = 'scout_aeo_indexnow_key';
	const ENDPOINT   = 'https://api.indexnow.org/indexnow';

	/** @var string[] URLs queued during this request. */
	private static $queue = array();

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'serve_key' ), 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'queue' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'send' ) );
	}

	public static function enabled(): bool {
		return Scout_AEO::settings()['indexnow'];
	}

	/** The site's key: 32 hex characters, created on first use. */
	public static function key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = md5( wp_generate_password( 64, true, true ) );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	public static function key_url(): string {
		return home_url( '/' . self::key() . '.txt' );
	}

	public static function serve_key(): void {
		if ( ! self::enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		if ( $path !== (string) wp_parse_url( self::key_url(), PHP_URL_PATH ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( self::key() );
		exit;
	}

	/** Queue a public, indexable post when it is published or updated. */
	public static function queue( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! ( $post instanceof WP_Post ) || ! self::enabled() || ! Scout_AEO::is_live() ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || '' !== $post->post_password ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		if ( defined( 'SCOUT_SEO_META_NOINDEX' ) && '1' === (string) get_post_meta( $post->ID, SCOUT_SEO_META_NOINDEX, true ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			self::$queue[] = $url;
		}
	}

	public static function send(): void {
		$urls        = array_values( array_unique( self::$queue ) );
		self::$queue = array();
		if ( ! $urls ) {
			return;
		}
		wp_remote_post(
			self::ENDPOINT,
			array(
				'blocking' => false,
				'timeout'  => 3,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode(
					array(
						'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
						'key'         => self::key(),
						'keyLocation' => self::key_url(),
						'urlList'     => $urls,
					)
				),
			)
		);
	}
}
