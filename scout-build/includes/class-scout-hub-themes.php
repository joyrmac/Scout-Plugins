<?php
/**
 * Theme updates from a client's private repository.
 *
 * Plugins update from the public Scout Plugins repo. Client themes live in
 * each client's own private repo, so they update the same way with one
 * difference: the site holds a read-only key for that repo.
 *
 * A theme opts in with two lines in its style.css header:
 *
 *   Update URI:    https://github.com/joyrmac/BlissBoogie
 *   Scout Release: blissboogie
 *
 * Its repo publishes each version as a GitHub release tagged
 * <release>-v<version> with a <release>.zip asset (tools/theme-release.yml in
 * the Scout Plugins repo is the workflow that does it). WordPress then asks
 * here on its normal update checks through update_themes_github.com, shows
 * "Update available" on Themes and on Scout → Build, and auto-updates the
 * theme like the Scout plugins.
 *
 * The key is a GitHub fine-grained token with read access to that one repo's
 * contents. It is entered once on Scout → Build and stored encrypted with
 * the site's own salts, or set in wp-config.php as SCOUT_THEME_TOKEN. It is
 * sent to api.github.com only, never to the file download that follows.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Themes {

	const OPTION = 'scout_hub_theme_token';
	const ACTION = 'scout_hub_theme_key';
	const CACHE  = 6 * HOUR_IN_SECONDS;

	public static function boot(): void {
		add_filter( 'extra_theme_headers', array( __CLASS__, 'headers' ) );
		add_filter( 'update_themes_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save_key' ) );
	}

	/** Lets WordPress read "Scout Release:" from style.css. */
	public static function headers( $headers ): array {
		$headers   = (array) $headers;
		$headers[] = 'Scout Release';
		return $headers;
	}

	/* ---------- which theme, which repo ---------- */

	/**
	 * The active theme's update source, or null when it has not opted in.
	 *
	 * @return array{stylesheet:string,name:string,version:string,repo:string,release:string}|null
	 */
	public static function source( ?WP_Theme $theme = null ): ?array {
		$theme = $theme ?? wp_get_theme();
		$uri   = (string) $theme->get( 'UpdateURI' );
		if ( ! preg_match( '#^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $uri, $m ) ) {
			return null;
		}
		$release = sanitize_title( (string) $theme->get( 'Scout Release' ) );
		return array(
			'stylesheet' => $theme->get_stylesheet(),
			'name'       => (string) $theme->get( 'Name' ),
			'version'    => (string) $theme->get( 'Version' ),
			'repo'       => $m[1],
			'release'    => '' !== $release ? $release : $theme->get_stylesheet(),
		);
	}

	/* ---------- the key ---------- */

	public static function token(): string {
		if ( defined( 'SCOUT_THEME_TOKEN' ) && '' !== (string) SCOUT_THEME_TOKEN ) {
			return (string) SCOUT_THEME_TOKEN;
		}
		return self::decrypt( (string) get_option( self::OPTION, '' ) );
	}

	public static function save_key(): void {
		if ( ! current_user_can( 'update_themes' ) || ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'You do not have permission to change theme updates.', 'scout-build' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$key = isset( $_POST['scout_theme_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['scout_theme_key'] ) ) ) : '';
		if ( ! empty( $_POST['forget'] ) ) {
			delete_option( self::OPTION );
		} elseif ( '' !== $key ) {
			update_option( self::OPTION, self::encrypt( $key ), false );
			$src = self::source();
			if ( $src ) {
				self::auto_update( $src['stylesheet'] );
			}
		}
		self::forget();
		Scout_Hub_Features::refresh();
		wp_safe_redirect( admin_url( 'admin.php?page=' . Scout_Hub_Admin::PAGE ) . '#sh-theme' );
		exit;
	}

	/** Theme updates run by themselves, the same as the Scout plugins. */
	private static function auto_update( string $stylesheet ): void {
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ! wp_is_auto_update_enabled_for_type( 'theme' ) ) {
			return;
		}
		$on = (array) get_site_option( 'auto_update_themes', array() );
		if ( ! in_array( $stylesheet, $on, true ) ) {
			$on[] = $stylesheet;
			update_site_option( 'auto_update_themes', $on );
		}
	}

	/* ---------- releases ---------- */

	public static function forget(): void {
		$src = self::source();
		if ( $src ) {
			delete_transient( 'scout_theme_release_' . md5( $src['repo'] . $src['release'] ) );
		}
	}

	/**
	 * The newest release for a theme: version, the asset's API address and
	 * the release notes. Null when there is no key, no release or no answer.
	 *
	 * @return array{version:string,package:string,notes:string,error:string}|null
	 */
	public static function latest( array $src ): ?array {
		$token = self::token();
		if ( '' === $token ) {
			return null;
		}
		$key    = 'scout_theme_release_' . md5( $src['repo'] . $src['release'] );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return '' === $cached['version'] && '' === $cached['error'] ? null : $cached;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $src['repo'] . '/releases?per_page=50',
			array(
				'timeout' => 10,
				'headers' => self::api_headers( $token, 'application/vnd.github+json' ),
			)
		);
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 200 !== $code ) {
			$out = array(
				'version' => '',
				'package' => '',
				'notes'   => '',
				'error'   => 401 === $code || 403 === $code || 404 === $code ? 'key' : 'offline',
			);
			// A failed check is retried sooner than a good one.
			set_transient( $key, $out, 15 * MINUTE_IN_SECONDS );
			return $out;
		}
		$best = array(
			'version' => '',
			'package' => '',
			'notes'   => '',
			'error'   => '',
		);
		$tag  = '/^' . preg_quote( $src['release'], '/' ) . '-v(\d+(?:\.\d+)*)$/';
		foreach ( (array) json_decode( (string) wp_remote_retrieve_body( $response ), true ) as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) || ! preg_match( $tag, (string) ( $rel['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			if ( '' !== $best['version'] && ! version_compare( $m[1], $best['version'], '>' ) ) {
				continue;
			}
			foreach ( (array) ( $rel['assets'] ?? array() ) as $asset ) {
				if ( $src['release'] . '.zip' === ( $asset['name'] ?? '' ) && ! empty( $asset['url'] ) ) {
					$best = array(
						'version' => $m[1],
						'package' => (string) $asset['url'],
						'notes'   => (string) ( $rel['body'] ?? '' ),
						'error'   => '',
					);
				}
			}
		}
		set_transient( $key, $best, self::CACHE );
		return '' === $best['version'] ? null : $best;
	}

	/** WordPress asks about a theme whose Update URI is on github.com. */
	public static function check( $update, $theme_data, $stylesheet ) {
		$theme = wp_get_theme( (string) $stylesheet );
		$src   = $theme->exists() ? self::source( $theme ) : null;
		if ( ! $src ) {
			return $update;
		}
		$rel = self::latest( $src );
		if ( ! $rel || '' === $rel['version'] || ! version_compare( $rel['version'], $src['version'], '>' ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . $src['repo'] . '/' . $src['release'],
			'theme'        => $src['stylesheet'],
			'version'      => $rel['version'],
			'url'          => 'https://github.com/' . $src['repo'],
			'package'      => $rel['package'],
			'requires'     => (string) $theme->get( 'RequiresWP' ),
			'requires_php' => (string) $theme->get( 'RequiresPHP' ),
		);
	}

	/**
	 * Fetch a private release asset. GitHub answers the API address with a
	 * redirect to a signed file address; the key goes only to the first, so
	 * it never leaves for the file host.
	 */
	public static function download( $reply, $package, $upgrader ) {
		$src = self::source();
		if ( false !== $reply || ! $src || ! is_string( $package ) || 0 !== strpos( $package, 'https://api.github.com/repos/' . $src['repo'] . '/releases/assets/' ) ) {
			return $reply;
		}
		$token = self::token();
		if ( '' === $token ) {
			return new WP_Error( 'scout_theme_key', __( 'This site has no key for its theme updates. Add it on Scout → Build.', 'scout-build' ) );
		}
		if ( $upgrader instanceof WP_Upgrader && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		$first = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::api_headers( $token, 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		$code     = (int) wp_remote_retrieve_response_code( $first );
		$location = (string) wp_remote_retrieve_header( $first, 'location' );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && '' !== $location ) {
			// The signed address needs no key, and must not get one.
			return download_url( $location, 300 );
		}
		if ( 200 === $code && '' !== (string) wp_remote_retrieve_body( $first ) ) {
			$file = wp_tempnam( $package );
			if ( $file && false !== file_put_contents( $file, wp_remote_retrieve_body( $first ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file the upgrader unzips and deletes.
				return $file;
			}
		}
		/* translators: %d: HTTP status code. */
		return new WP_Error( 'scout_theme_download', sprintf( __( 'GitHub would not hand over the theme file (status %d). Check the key on Scout → Build.', 'scout-build' ), $code ) );
	}

	private static function api_headers( string $token, string $accept ): array {
		return array(
			'Accept'               => $accept,
			'Authorization'        => 'Bearer ' . $token,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'ScoutBuild/' . SCOUT_HUB_VERSION,
		);
	}

	/* ---------- Scout → Build ---------- */

	/** The Theme box on the Features screen. */
	public static function panel( bool $operator ): void {
		$theme = wp_get_theme();
		$src   = self::source( $theme );
		echo '<section class="sh-box" id="sh-theme"><h2 class="sh-title">' . esc_html__( 'Theme', 'scout-build' ) . '</h2>';
		if ( ! $src ) {
			/* translators: 1: theme name, 2: version. */
			echo '<p>' . esc_html( sprintf( __( '%1$s %2$s. This theme is updated by uploading it; it has not been set up to update itself.', 'scout-build' ), (string) $theme->get( 'Name' ), (string) $theme->get( 'Version' ) ) ) . '</p></section>';
			return;
		}
		$has_key = '' !== self::token();
		$rel     = $has_key ? self::latest( $src ) : null;
		$updates = get_site_transient( 'update_themes' );
		$ready   = is_object( $updates ) && isset( $updates->response[ $src['stylesheet'] ]['new_version'] ) ? (string) $updates->response[ $src['stylesheet'] ]['new_version'] : '';
		echo '<p><strong>' . esc_html( $src['name'] ) . '</strong> ' . esc_html( $src['version'] ) . ' ';
		if ( ! $has_key ) {
			echo '<span class="sh-tag is-update">' . esc_html__( 'Needs its key to update itself', 'scout-build' ) . '</span>';
		} elseif ( $rel && 'key' === $rel['error'] ) {
			echo '<span class="sh-tag is-update">' . esc_html__( 'GitHub turned the key down', 'scout-build' ) . '</span>';
		} elseif ( $rel && 'offline' === $rel['error'] ) {
			echo '<span class="sh-tag is-update">' . esc_html__( 'Could not reach GitHub. Try again in 15 minutes.', 'scout-build' ) . '</span>';
		} elseif ( '' !== $ready || ( $rel && version_compare( $rel['version'], $src['version'], '>' ) ) ) {
			$new = '' !== $ready ? $ready : $rel['version'];
			/* translators: %s: version. */
			echo '<span class="sh-tag is-update">' . esc_html( sprintf( __( '%s ready', 'scout-build' ), $new ) ) . '</span>';
		} else {
			echo '<span class="sh-tag is-current">' . esc_html__( 'Up to date', 'scout-build' ) . '</span>';
		}
		echo '</p>';
		if ( $has_key && '' !== $ready && current_user_can( 'update_themes' ) ) {
			$url = wp_nonce_url( admin_url( 'update.php?action=upgrade-theme&theme=' . rawurlencode( $src['stylesheet'] ) ), 'upgrade-theme_' . $src['stylesheet'] );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Update the theme now', 'scout-build' ) . '</a> <span class="sh-note">' . esc_html__( 'Or leave it: it updates by itself within a few hours.', 'scout-build' ) . '</span></p>';
		}
		if ( $operator && current_user_can( 'update_themes' ) && ! ( defined( 'SCOUT_THEME_TOKEN' ) && '' !== (string) SCOUT_THEME_TOKEN ) ) {
			// Wraps on a phone: the key box takes the full width and the buttons drop below it.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			/* translators: %s: owner/repo. */
			echo '<label for="sh-theme-key" style="flex-basis:100%">' . esc_html( sprintf( __( 'Read-only key for %s', 'scout-build' ), $src['repo'] ) ) . '</label> ';
			echo '<input type="password" id="sh-theme-key" name="scout_theme_key" autocomplete="off" placeholder="' . esc_attr( $has_key ? __( 'Saved. Paste a new one to replace it.', 'scout-build' ) : 'github_pat_…' ) . '" class="regular-text" style="flex:1 1 16em;min-width:0;max-width:100%"> ';
			echo '<button type="submit" class="button">' . esc_html__( 'Save key', 'scout-build' ) . '</button>';
			if ( $has_key ) {
				echo ' <button type="submit" name="forget" value="1" class="button sh-quiet">' . esc_html__( 'Remove key', 'scout-build' ) . '</button>';
			}
			echo '</form><p class="sh-note">' . esc_html__( 'A GitHub fine-grained token with read-only Contents access to this one repository. It is stored encrypted and only ever sent to GitHub.', 'scout-build' ) . '</p>';
		}
		echo '</section>';
	}

	/* ---------- storing the key ---------- */

	private static function cipher_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'scout-theme', true );
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
}
