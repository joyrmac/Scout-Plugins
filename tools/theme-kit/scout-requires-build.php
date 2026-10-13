<?php
/**
 * Scout theme kit: every Scout theme needs the Scout Build plugin.
 *
 * Copy this file into a Scout theme (inc/scout-requires-build.php) and
 * require it from functions.php. When Scout Build is missing, admins see a
 * notice with one button: it installs the newest Scout Build release (or turns
 * on a copy that is already there) and the Scout menu appears. Scout Build
 * updates itself after that, so the theme never carries a stale copy.
 *
 * Canonical copy: Scout-Plugins/tools/theme-kit/scout-requires-build.php.
 * Change it there, then copy it into each theme at that theme's next update.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Scout_Theme_Requires_Build' ) ) {

	final class Scout_Theme_Requires_Build {

		const FILE   = 'scout-build/scout-build.php';
		const FEED   = 'https://github.com/joyrmac/Scout-Plugins/releases/download/feed/releases.json';
		const ACTION = 'scout_theme_install_build';

		public static function boot(): void {
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'install' ) );
		}

		private static function active(): bool {
			return defined( 'SCOUT_HUB_VERSION' );
		}

		public static function notice(): void {
			if ( self::active() || ! current_user_can( 'install_plugins' ) ) {
				return;
			}
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
			$err = get_transient( 'scout_theme_build_error' );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'This site needs Scout Build.', 'scout' ) . '</strong> '
				. esc_html__( 'It adds the Scout menu: features, site health, updates and change requests. One click installs it, and it keeps itself up to date.', 'scout' )
				. '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Install Scout Build', 'scout' ) . '</a></p>';
			if ( $err ) {
				delete_transient( 'scout_theme_build_error' );
				echo '<p>' . esc_html( (string) $err ) . '</p>';
			}
			echo '</div>';
		}

		public static function install(): void {
			if ( ! current_user_can( 'install_plugins' ) || ! check_admin_referer( self::ACTION ) ) {
				wp_die( esc_html__( 'Not allowed.', 'scout' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$back = admin_url( 'admin.php?page=site-build' );
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::FILE ) ) {
				$zip = self::zip_url();
				if ( '' === $zip ) {
					self::fail( __( 'Could not reach the Scout Build download. Try again in a few minutes, or upload scout-build.zip under Plugins.', 'scout' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
				$result   = $upgrader->install( $zip );
				if ( true !== $result ) {
					self::fail( __( 'The download did not install. Upload scout-build.zip under Plugins instead.', 'scout' ) );
				}
			}
			$active = activate_plugin( self::FILE );
			if ( is_wp_error( $active ) ) {
				self::fail( $active->get_error_message() );
			}
			wp_safe_redirect( $back );
			exit;
		}

		/** The newest scout-build release zip, read from the release feed. */
		private static function zip_url(): string {
			$res = wp_remote_get( self::FEED, array( 'timeout' => 15 ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return '';
			}
			$best = '';
			$url  = '';
			foreach ( (array) json_decode( (string) wp_remote_retrieve_body( $res ), true ) as $rel ) {
				$tag = (string) ( $rel['tag_name'] ?? '' );
				if ( 0 !== strpos( $tag, 'scout-build-v' ) || ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
					continue;
				}
				$ver = substr( $tag, strlen( 'scout-build-v' ) );
				foreach ( (array) ( $rel['assets'] ?? array() ) as $asset ) {
					if ( 'scout-build.zip' === ( $asset['name'] ?? '' ) && ( '' === $best || version_compare( $ver, $best, '>' ) ) ) {
						$best = $ver;
						$url  = (string) $asset['browser_download_url'];
					}
				}
			}
			return 0 === strpos( $url, 'https://github.com/' ) ? $url : '';
		}

		private static function fail( string $message ): void {
			set_transient( 'scout_theme_build_error', $message, 120 );
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	Scout_Theme_Requires_Build::boot();
}
