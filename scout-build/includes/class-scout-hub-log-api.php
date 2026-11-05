<?php
/**
 * The read endpoint for the Scout Client Hub: the Site log, the open change
 * requests and the site's versions and health, for the Google Sheet that
 * pulls every Scout site nightly, and for Scout's tools to log a note as work ships.
 *
 * Two keys. Operators make them on Scout → Site log (Hub keys), where they
 * are shown once and stored encrypted, the way the theme key is; a client
 * administrator never sees them again. A site can instead define them in
 * wp-config.php, which wins over the stored pair:
 *
 *   define( 'SCOUT_HUB_READ_TOKEN', '...' );   // read only
 *   define( 'SCOUT_HUB_WRITE_TOKEN', '...' );  // read, plus POST /log for note lines
 *
 * Sent as "Authorization: Bearer <token>". Without a key, the endpoint is off. On a white-label site (SCOUT_HUB_BRAND false) it stays off
 * unless SCOUT_HUB_LOG_API is true, so a partner's site never answers Scout's
 * puller by accident.
 *
 * Nothing here returns form entries, attachments or keys. A change request a
 * visitor sent through the chat keeps their name, but its email addresses and
 * phone numbers are blanked before it leaves the site. The
 * write key can add note lines only: no feature switch, request or setting
 * changes.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Log_API {

	const NS    = 'scout-build/v1';
	const LIMIT = 60; // Calls a minute, per key.

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** The endpoint answers only when a key is set and the site allows it. */
	public static function enabled(): bool {
		if ( ! Scout_Hub_Admin::branded() && ! ( defined( 'SCOUT_HUB_LOG_API' ) && SCOUT_HUB_LOG_API ) ) {
			return false;
		}
		return '' !== self::token( 'read' ) || '' !== self::token( 'write' );
	}

	const OPTION = 'scout_hub_log_keys'; // Keys made on the site, stored encrypted.

	/**
	 * The key for a scope. A constant in wp-config.php wins; otherwise the
	 * keys made on the Site log screen (stored encrypted, like the theme key).
	 */
	private static function token( string $scope ): string {
		$c = 'write' === $scope ? 'SCOUT_HUB_WRITE_TOKEN' : 'SCOUT_HUB_READ_TOKEN';
		$t = defined( $c ) ? trim( (string) constant( $c ) ) : '';
		if ( '' === $t ) {
			$t = (string) ( self::site_keys()[ $scope ] ?? '' );
		}
		return strlen( $t ) >= 20 ? $t : ''; // A short key is no key.
	}

	/** Where the keys come from: 'config' (wp-config.php), 'site' (made on the screen), or ''. */
	public static function keys_source(): string {
		if ( ( defined( 'SCOUT_HUB_READ_TOKEN' ) && '' !== trim( (string) SCOUT_HUB_READ_TOKEN ) ) || ( defined( 'SCOUT_HUB_WRITE_TOKEN' ) && '' !== trim( (string) SCOUT_HUB_WRITE_TOKEN ) ) ) {
			return 'config';
		}
		return self::site_keys() ? 'site' : '';
	}

	/** When the site's own keys were made, or 0. */
	public static function keys_made(): int {
		return (int) ( self::site_keys()['made'] ?? 0 );
	}

	/** @return array{read?:string, write?:string, made?:int} */
	private static function site_keys(): array {
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return array();
		}
		$plain = self::decrypt( $stored );
		$keys  = json_decode( $plain, true );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Make a fresh pair and store it encrypted. Returns the plain keys once,
	 * for the screen to show; they are not readable from the dashboard after.
	 *
	 * @return array{read:string, write:string}
	 */
	public static function make_keys(): array {
		$keys = array( 'read' => self::random_key(), 'write' => self::random_key(), 'made' => time() );
		update_option( self::OPTION, self::encrypt( (string) wp_json_encode( $keys ) ), false );
		return array( 'read' => $keys['read'], 'write' => $keys['write'] );
	}

	public static function forget_keys(): void {
		delete_option( self::OPTION );
	}

	/** 40 characters, letters and digits, from the system's random source. */
	private static function random_key(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$out      = '';
		for ( $i = 0; $i < 40; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	/* ---------- storing the keys (the same scheme as the theme key) ---------- */

	private static function cipher_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'scout-hub-keys', true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $stored ): string {
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

	public static function routes(): void {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::NS,
			'/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_log' ),
					'permission_callback' => static fn( WP_REST_Request $r ) => self::allow( $r, 'read' ),
					'args'                => array(
						'since'    => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
						'month'    => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$' ),
						'client'   => array( 'type' => 'boolean', 'default' => false ),
						'kind'     => array( 'type' => 'string', 'enum' => array_keys( Scout_Hub_Log::KINDS ) ),
						'status'   => array( 'type' => 'string', 'enum' => array_keys( Scout_Hub_Log::STATUSES ) ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
						'per_page' => array( 'type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 200 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_log' ),
					'permission_callback' => static fn( WP_REST_Request $r ) => self::allow( $r, 'write' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/requests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_requests' ),
				'permission_callback' => static fn( WP_REST_Request $r ) => self::allow( $r, 'read' ),
				'args'                => array(
					'status' => array( 'type' => 'string', 'default' => 'received,progress' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/site',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_site' ),
				'permission_callback' => static fn( WP_REST_Request $r ) => self::allow( $r, 'read' ),
			)
		);
	}

	/* ---------- who may ask ---------- */

	/**
	 * @param string $scope 'read' or 'write'. The write key also reads.
	 * @return true|WP_Error
	 */
	private static function allow( WP_REST_Request $request, string $scope ) {
		$given = self::bearer( $request );
		$write = self::token( 'write' );
		$read  = self::token( 'read' );
		$ok    = false;
		$used  = '';
		if ( '' !== $given ) {
			if ( '' !== $write && hash_equals( $write, $given ) ) {
				$ok   = true;
				$used = 'write';
			} elseif ( 'read' === $scope && '' !== $read && hash_equals( $read, $given ) ) {
				$ok   = true;
				$used = 'read';
			}
		}
		if ( ! $ok ) {
			self::note_refusal();
			return new WP_Error( 'scout_hub_key', __( 'This needs the site\'s log key.', 'scout-build' ), array( 'status' => 401 ) );
		}
		$bucket = 'scout_hub_api_' . $used . '_' . gmdate( 'YmdHi' );
		$n      = (int) get_transient( $bucket ) + 1;
		set_transient( $bucket, $n, 2 * MINUTE_IN_SECONDS );
		if ( $n > self::LIMIT ) {
			return new WP_Error( 'scout_hub_busy', __( 'Too many calls this minute. Try again shortly.', 'scout-build' ), array( 'status' => 429 ) );
		}
		$request->set_param( '_scout_scope', $used );
		return true;
	}

	private static function bearer( WP_REST_Request $request ): string {
		$h = (string) $request->get_header( 'authorization' );
		if ( '' === $h && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$h = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with hash_equals only.
		}
		return preg_match( '/^Bearer\s+(\S+)$/i', trim( $h ), $m ) ? $m[1] : '';
	}

	/** A refused call is worth one alert line an hour, never the caller's address. */
	private static function note_refusal(): void {
		if ( get_transient( 'scout_hub_api_refused' ) ) {
			return;
		}
		set_transient( 'scout_hub_api_refused', 1, HOUR_IN_SECONDS );
		Scout_Hub_Log::add(
			array(
				'title'       => __( 'Someone called the site log endpoint without the right key', 'scout-build' ),
				'kind'        => 'alert',
				'topic'       => 'other',
				'source'      => 'Scout Build',
				'show_client' => false,
			)
		);
	}

	/* ---------- the answers ---------- */

	public static function get_log( WP_REST_Request $r ): WP_REST_Response {
		$client = (bool) $r->get_param( 'client' );
		$args   = array(
			'since'    => (string) $r->get_param( 'since' ),
			'month'    => (string) $r->get_param( 'month' ),
			'client'   => $client,
			'kind'     => (string) $r->get_param( 'kind' ),
			'status'   => (string) $r->get_param( 'status' ),
			'page'     => (int) $r->get_param( 'page' ),
			'per_page' => (int) $r->get_param( 'per_page' ),
		);
		$page   = Scout_Hub_Log::query( $args );
		$items  = array();
		foreach ( $page['items'] as $post ) {
			$items[] = Scout_Hub_Log::to_array( $post, $client );
		}
		return new WP_REST_Response(
			array(
				'site'  => self::site_name(),
				'home'  => home_url( '/' ),
				'total' => $page['total'],
				'pages' => $page['pages'],
				'page'  => $args['page'],
				'items' => $items,
			)
		);
	}

	public static function post_log( WP_REST_Request $r ) {
		$in     = (array) $r->get_json_params();
		$fields = array();
		foreach ( array( 'title', 'client_text', 'topic', 'version', 'measure', 'status', 'detail', 'date' ) as $key ) {
			$fields[ $key ] = sanitize_textarea_field( (string) ( $in[ $key ] ?? '' ) );
		}
		$fields['kind']        = 'note';
		$fields['show_client'] = array_key_exists( 'show_client', $in ) ? (bool) $in['show_client'] : true;
		$fields['source']      = 'Scout'; // Only the write key gets here.
		$id                    = Scout_Hub_Log::add( $fields );
		if ( ! $id ) {
			return new WP_Error( 'scout_hub_line', __( 'The line needs a title.', 'scout-build' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( Scout_Hub_Log::to_array( get_post( $id ) ), 201 );
	}

	public static function get_requests( WP_REST_Request $r ): WP_REST_Response {
		$want  = array_filter( array_map( 'sanitize_key', explode( ',', (string) $r->get_param( 'status' ) ) ) );
		$want  = array_values( array_intersect( $want, array_keys( Scout_Hub_Requests::STATUSES ) ) );
		$items = array();
		foreach ( Scout_Hub_Requests::all() as $post ) {
			$status = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_STATUS, true );
			$status = isset( Scout_Hub_Requests::STATUSES[ $status ] ) ? $status : 'received';
			if ( $want && ! in_array( $status, $want, true ) ) {
				continue;
			}
			$when    = (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_WHEN, true );
			$items[] = array(
				'id'     => (int) $post->ID,
				'date'   => get_the_date( 'Y-m-d', $post ),
				'title'  => (string) $post->post_title,
				'what'   => self::without_contact( (string) $post->post_content ),
				'page'   => (string) get_post_meta( $post->ID, Scout_Hub_Requests::META_PAGE, true ),
				'when'   => Scout_Hub_Requests::WHEN[ $when ] ?? '',
				'status' => $status,
				'by'     => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			);
		}
		return new WP_REST_Response( array( 'site' => self::site_name(), 'home' => home_url( '/' ), 'items' => $items ) );
	}

	public static function get_site( WP_REST_Request $r ): WP_REST_Response {
		global $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! Scout_Hub_Log::is_scout_plugin( $data ) ) {
				continue;
			}
			$plugins[] = array(
				'slug'    => dirname( $file ),
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => ( is_object( $updates ) && isset( $updates->response[ $file ]->new_version ) ) ? (string) $updates->response[ $file ]->new_version : '',
			);
		}
		$theme    = wp_get_theme();
		$t_update = get_site_transient( 'update_themes' );
		$sheet    = $theme->get_stylesheet();
		$health   = array();
		foreach ( array_merge( Scout_Hub_Health::checks(), Scout_Hub_Security::rows() ) as $c ) {
			$health[] = array( 'label' => (string) $c['label'], 'value' => (string) $c['value'], 'state' => (string) $c['state'] );
		}
		$ai   = Scout_Hub_Health::ai_checks();
		$plan = Scout_Hub_Access::plan();
		$out  = array(
			'site'       => self::site_name(),
			'home'       => home_url( '/' ),
			'live'       => Scout_Hub_Health::is_live(),
			'plan'       => $plan ? Scout_Hub_Access::PLANS[ $plan ] : '',
			'wordpress'  => (string) $wp_version,
			'php'        => PHP_VERSION,
			'theme'      => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'update'  => ( is_object( $t_update ) && isset( $t_update->response[ $sheet ]['new_version'] ) ) ? (string) $t_update->response[ $sheet ]['new_version'] : '',
			),
			'plugins'    => $plugins,
			'health'     => $health,
			'ai_ready'   => array( 'good' => count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) ), 'total' => count( $ai ) ),
			'open_requests' => count( array_filter( Scout_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Scout_Hub_Requests::META_STATUS, true ) ) ),
			'log_this_month' => Scout_Hub_Log::count_month(),
			'checked_at' => gmdate( 'c' ),
		);
		if ( class_exists( 'Scout_Reviews_Settings' ) ) {
			$totals = array();
			foreach ( (array) ( Scout_Reviews_Settings::get()['profiles'] ?? array() ) as $key => $p ) {
				if ( is_array( $p ) && (int) ( $p['count'] ?? 0 ) > 0 ) {
					$totals[ (string) $key ] = array( 'rating' => (string) ( $p['rating'] ?? '' ), 'count' => (int) $p['count'] );
				}
			}
			$stamp          = (int) get_option( 'scout_reviews_totals_updated', 0 );
			$out['reviews'] = array( 'totals' => $totals, 'updated' => $stamp ? gmdate( 'Y-m-d', $stamp ) : '' );
		}
		if ( class_exists( 'Scout_Forms_Guard' ) && method_exists( 'Scout_Forms_Guard', 'blocked' ) ) {
			$out['spam_blocked'] = array( 'month' => (int) Scout_Forms_Guard::blocked(), 'year' => (int) Scout_Forms_Guard::blocked( 'all' ) );
		}
		return new WP_REST_Response( $out );
	}

	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/** Blank email addresses and phone numbers, so a request a visitor sent never carries them off the site. */
	public static function without_contact( string $text ): string {
		$text = (string) preg_replace( '/<?[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}>?/', '[email]', $text );
		return (string) preg_replace( '/(?<!\d)(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/', '[phone]', $text );
	}
}
