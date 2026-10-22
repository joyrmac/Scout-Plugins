<?php
/**
 * The front end: forward old addresses, log missing pages, forward a page
 * automatically when its address changes, and report broken links to Site
 * Health.
 *
 * @package Scout_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Redirects {

	const OPTION = 'scout_redirects_settings';
	const NOTICE = 'scout_redirects_auto_';

	/** Addresses that belong to WordPress itself and are never redirected or logged. */
	const RESERVED = array( 'wp-admin', 'wp-login.php', 'wp-json', 'xmlrpc.php', 'wp-cron.php', 'wp-content', 'wp-includes', 'wp-signup.php', 'wp-activate.php' );

	public static function boot(): void {
		add_action( 'plugins_loaded', array( 'Scout_Redirects_Store', 'maybe_install' ), 5 );
		// parse_request runs before WordPress looks anything up, so a redirect
		// costs one indexed query and nothing else. Priority 0 keeps it ahead of
		// the REST handler, which is why REST paths are excluded by name.
		add_action( 'parse_request', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log_404' ), 99 );
		add_action( 'post_updated', array( __CLASS__, 'address_changed' ), 10, 3 );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'auto_pages' => isset( $s['auto_pages'] ) ? (int) $s['auto_pages'] : 1,
			'watch_404'  => isset( $s['watch_404'] ) ? (int) $s['watch_404'] : 1,
			'log_cap'    => isset( $s['log_cap'] ) ? max( 50, min( 2000, (int) $s['log_cap'] ) ) : 500,
		);
	}

	/* ---------------------------------------------------------------- */
	/* Paths                                                             */
	/* ---------------------------------------------------------------- */

	/** The site's own path prefix, e.g. "" or "/blog" for a WordPress in a folder. */
	private static function home_prefix(): string {
		return untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	private static function home_host(): string {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * Turn an old address into the form redirects are stored and matched in:
	 * the path only, relative to the site, decoded, lowercase, leading slash,
	 * no trailing slash. "https://site/Our-Services/?a=1" becomes "/our-services".
	 *
	 * @return string The path, or '' when it is not usable as an old address.
	 */
	public static function normalize_source( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $raw ) && ! preg_match( '#^https?://#i', $raw ) ) {
			return '';
		}
		if ( preg_match( '#^(https?:)?//#i', $raw ) ) {
			$host = strtolower( (string) wp_parse_url( $raw, PHP_URL_HOST ) );
			if ( $host && $host !== self::home_host() && 'www.' . $host !== self::home_host() && $host !== 'www.' . self::home_host() ) {
				return '';
			}
		}
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$path = preg_replace( '#[\x00-\x1F\x7F]#', '', $path );
		$path = '/' . ltrim( preg_replace( '#/+#', '/', $path ), '/' );
		$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path ) : strtolower( $path );
		$prefix = strtolower( self::home_prefix() );
		if ( '' !== $prefix && ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) ) {
			$path = substr( $path, strlen( $prefix ) );
			$path = '' === $path ? '/' : $path;
		}
		$path = '/' === $path ? '/' : untrailingslashit( $path );
		if ( strlen( $path ) > 255 ) {
			return '';
		}
		return $path;
	}

	/** True for addresses that belong to WordPress itself. */
	public static function is_reserved( string $path ): bool {
		$first    = strtok( ltrim( $path, '/' ), '/' );
		$reserved = self::RESERVED;
		$reserved[] = rest_get_url_prefix();
		return false !== $first && in_array( $first, $reserved, true );
	}

	/**
	 * Clean up a new address: a path on this site ("/services/") or a full
	 * web address. Query strings and #anchors are kept.
	 *
	 * @return string The address, or '' when it is not usable.
	 */
	public static function normalize_target( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$url = esc_url_raw( $raw, array( 'http', 'https' ) );
			// A full address on this site is stored as a path, so it survives a domain move.
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host === self::home_host() ) {
				$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$frag  = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
				$prefix = self::home_prefix();
				if ( '' !== $prefix && str_starts_with( $path, $prefix ) ) {
					$path = substr( $path, strlen( $prefix ) );
				}
				$url = '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' ) . ( '' !== $frag ? '#' . $frag : '' );
			}
			return $url;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $raw ) || str_starts_with( $raw, '//' ) ) {
			return '';
		}
		$url = esc_url_raw( '/' . ltrim( $raw, '/' ) );
		return '' === $url ? '' : $url;
	}

	/** The full address a stored target sends visitors to. */
	public static function target_url( string $target ): string {
		return str_starts_with( $target, '/' ) ? home_url( $target ) : $target;
	}

	/**
	 * Check and store one redirect.
	 *
	 * @return int|WP_Error The id, or what was wrong.
	 */
	public static function add( string $from, string $to, int $type = 301, string $origin = 'manual', int $id = 0 ) {
		$source = self::normalize_source( $from );
		$target = self::normalize_target( $to );
		if ( '' === $source || '/' === $source ) {
			return new WP_Error( 'source', __( 'The old address needs to be a page on this site, like /old-page. The home page cannot be forwarded.', 'scout-redirects' ) );
		}
		if ( self::is_reserved( $source ) ) {
			return new WP_Error( 'reserved', __( 'That old address belongs to WordPress itself (the dashboard, login, files or the site API), so it stays as it is.', 'scout-redirects' ) );
		}
		if ( '' === $target ) {
			return new WP_Error( 'target', __( 'The new address needs to be a path on this site, like /services/, or a full web address starting with https://.', 'scout-redirects' ) );
		}
		if ( str_starts_with( $target, '/' ) && self::normalize_source( $target ) === $source ) {
			return new WP_Error( 'loop', __( 'The old and new addresses are the same page, which would send visitors in a circle.', 'scout-redirects' ) );
		}
		$type = in_array( $type, array( 301, 302 ), true ) ? $type : 301;
		return Scout_Redirects_Store::save( $source, $target, $type, $origin, $id );
	}

	/* ---------------------------------------------------------------- */
	/* Front end                                                         */
	/* ---------------------------------------------------------------- */

	/** The visitor's path, in stored form. */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return self::normalize_source( (string) $uri );
	}

	private static function skip_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| isset( $_GET['rest_route'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	public static function maybe_redirect(): void {
		if ( Scout_Redirects_Store::count() < 1 || self::skip_request() ) {
			return;
		}
		$path = self::request_path();
		if ( '' === $path || '/' === $path || self::is_reserved( $path ) ) {
			return;
		}
		$row = Scout_Redirects_Store::find( $path );
		if ( ! $row ) {
			return;
		}
		$url = self::target_url( (string) $row->target );
		// Carry the visitor's query string (campaign tags and the like) when the new address has none.
		$query = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $query && false === strpos( $url, '?' ) ) {
			$hash = '';
			if ( false !== strpos( $url, '#' ) ) {
				list( $url, $hash ) = explode( '#', $url, 2 );
				$hash = '#' . $hash;
			}
			$url .= '?' . $query . $hash;
		}
		if ( self::normalize_source( $url ) === $path && str_starts_with( (string) $row->target, '/' ) ) {
			return;
		}
		Scout_Redirects_Store::hit( $row );
		/**
		 * Filters the address a redirect sends a visitor to. Return '' to skip it.
		 *
		 * @param string $url The full new address.
		 * @param object $row The stored redirect.
		 */
		$url = (string) apply_filters( 'scout_redirects_target', $url, $row );
		if ( '' === $url ) {
			return;
		}
		// Admins add these by hand, so off-site addresses are allowed on purpose.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		if ( wp_redirect( $url, (int) $row->type, 'Scout Redirects' ) ) {
			exit;
		}
	}

	/** True when the visitor looks like a crawler or a script rather than a person. */
	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|scan|curl|wget|python|httpclient|go-http|java\/|libwww|headless|lighthouse|facebookexternalhit|preview|monitor|uptime|feed|okhttp|axios|node-fetch/', $ua );
	}

	/** True for requests that are noise rather than a page someone wanted. */
	public static function is_noise( string $path ): bool {
		if ( self::is_reserved( $path ) ) {
			return true;
		}
		$noise = '#^/(\.well-known|\.git|\.env|\.aws|cgi-bin|phpmyadmin)(/|$)|^/(apple-touch-icon|favicon|browserconfig|mstile)|\.(php\d?|aspx?|jsp|cgi|env|ini|sql|bak|old|zip|gz|tar|rar|log|yml|yaml|xml|txt|json|map|css|js|mjs|ico|woff2?|ttf|eot|otf|svg|png|jpe?g|gif|webp|avif|bmp|mp4|webm|mp3)$#';
		/**
		 * Filters whether a missing address is left out of the log.
		 *
		 * @param bool   $is_noise True to leave it out.
		 * @param string $path     The normalized path.
		 */
		return (bool) apply_filters( 'scout_redirects_is_noise', (bool) preg_match( $noise, $path ), $path );
	}

	public static function maybe_log_404(): void {
		if ( ! is_404() || self::skip_request() || self::is_bot() ) {
			return;
		}
		$s = self::settings();
		if ( ! $s['watch_404'] ) {
			return;
		}
		$path = self::request_path();
		if ( '' === $path || '/' === $path || self::is_noise( $path ) ) {
			return;
		}
		$ref      = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$internal = false;
		if ( '' !== $ref ) {
			$host     = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
			$internal = $host === self::home_host();
			// Keep a referrer's address without its query string: shorter, and no tracking tokens.
			$ref = strtok( $ref, '?#' );
			$ref = substr( (string) $ref, 0, 255 );
		}
		Scout_Redirects_Store::log_404( $path, $ref, $internal, $s['log_cap'] );
	}

	/* ---------------------------------------------------------------- */
	/* Page address changes                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * When a published page's address changes, forward the old address (and
	 * the old addresses of its child pages) to the new one.
	 *
	 * WordPress already does this for posts and other flat post types through
	 * _wp_old_slug, so only hierarchical types (pages) are covered here.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   The post after the update.
	 * @param WP_Post $before  The post before the update.
	 */
	public static function address_changed( $post_id, $after, $before ): void {
		if ( ! self::settings()['auto_pages'] || ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
			return;
		}
		if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}
		if ( ! is_post_type_hierarchical( $after->post_type ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}
		if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}
		$new_url = (string) get_permalink( $after );
		$old     = self::normalize_source( (string) get_permalink( $before ) );
		$new     = self::normalize_source( $new_url );
		if ( '' === $old || '' === $new || $old === $new || '/' === $old || '/' === $new ) {
			return;
		}
		$made = array();
		if ( self::forward_page( $old, $new, self::normalize_target( $new_url ) ) ) {
			$made[] = array( $old, $new );
		}
		// Child pages moved with it. Cap the walk so a huge tree never stalls a save.
		$children = get_pages(
			array(
				'child_of'    => (int) $post_id,
				'post_type'   => $after->post_type,
				'post_status' => 'publish',
				'number'      => 100,
			)
		);
		foreach ( (array) $children as $child ) {
			$child_url = (string) get_permalink( $child );
			$child_new = self::normalize_source( $child_url );
			if ( str_starts_with( $child_new, $new . '/' ) ) {
				$child_old = $old . substr( $child_new, strlen( $new ) );
				if ( self::forward_page( $child_old, $child_new, self::normalize_target( $child_url ) ) ) {
					$made[] = array( $child_old, $child_new );
				}
			}
		}
		if ( $made && get_current_user_id() ) {
			set_transient( self::NOTICE . get_current_user_id(), array( 'made' => $made, 'shown' => 0 ), HOUR_IN_SECONDS );
		}
	}

	/** Forward one old page address, keeping redirect chains to a single hop. */
	private static function forward_page( string $old, string $new, string $target ): bool {
		if ( '' === $target || self::is_reserved( $old ) ) {
			return false;
		}
		// The new address is a live page now, so nothing may forward it away.
		Scout_Redirects_Store::delete_source( $new );
		// Redirects that pointed at the old address now go straight to the new one.
		Scout_Redirects_Store::retarget( $old, $target );
		return Scout_Redirects_Store::save( $old, $target, 301, 'auto' ) > 0;
	}

	/* ---------------------------------------------------------------- */
	/* Site Health                                                       */
	/* ---------------------------------------------------------------- */

	public static function site_health( array $tests ): array {
		$tests['direct']['scout_redirects_broken_links'] = array(
			'label' => __( 'Links between your pages', 'scout-redirects' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);
		return $tests;
	}

	public static function site_health_test(): array {
		$rows   = Scout_Redirects_Store::log_list( 5, true );
		$screen = Scout_Redirects_Admin::url();
		$result = array(
			'label'       => __( 'Your pages link to pages that exist', 'scout-redirects' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Search', 'scout-redirects' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No visitor has followed a link on this site to a missing page.', 'scout-redirects' ) . '</p>',
			'actions'     => '',
			'test'        => 'scout_redirects_broken_links',
		);
		if ( ! $rows ) {
			return $result;
		}
		$items = '';
		foreach ( $rows as $row ) {
			$from   = self::normalize_source( (string) $row->referrer );
			$items .= '<li>' . sprintf(
				/* translators: 1: page with the link, 2: missing address */
				esc_html__( '%1$s links to %2$s, which is missing', 'scout-redirects' ),
				'<code>' . esc_html( '' !== $from ? $from : __( 'a page', 'scout-redirects' ) ) . '</code>',
				'<code>' . esc_html( $row->path ) . '</code>'
			) . '</li>';
		}
		$result['label']       = __( 'Some of your pages link to pages that are missing', 'scout-redirects' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html__( 'Visitors followed these links and found nothing. Fix each one by adding a redirect or editing the link.', 'scout-redirects' ) . '</p><ul>' . $items . '</ul>';
		$result['actions']     = '<p><a href="' . esc_url( $screen ) . '">' . esc_html__( 'Open Redirects', 'scout-redirects' ) . '</a></p>';
		return $result;
	}
}
