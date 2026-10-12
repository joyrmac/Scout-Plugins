<?php
/**
 * The site health strip: a few facts read from WordPress itself, each with a
 * plain verdict. Nothing is sent anywhere.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Health {

	/**
	 * @return array<int, array{label:string, value:string, state:string, note:string}>
	 *         state is good | warn | bad.
	 */
	public static function checks(): array {
		global $wp_version;
		$out = array();

		$updates = 0;
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$updates += count( (array) $plugins->response );
		}
		$core = get_site_transient( 'update_core' );
		$core_update = false;
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_update = true;
				}
			}
		}

		$out[] = array(
			'label' => __( 'WordPress', 'scout-build' ),
			'value' => (string) $wp_version,
			'state' => $core_update ? 'warn' : 'good',
			'note'  => $core_update ? __( 'A newer version is available.', 'scout-build' ) : __( 'Up to date.', 'scout-build' ),
		);
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$out[]  = array(
			'label' => __( 'PHP', 'scout-build' ),
			'value' => PHP_VERSION,
			'state' => $php_ok ? 'good' : 'warn',
			'note'  => $php_ok ? __( 'Supported.', 'scout-build' ) : __( 'Ask the host to move to PHP 8.1 or newer.', 'scout-build' ),
		);
		$out[] = array(
			'label' => __( 'Updates waiting', 'scout-build' ),
			'value' => (string) $updates,
			'state' => $updates ? 'warn' : 'good',
			'note'  => $updates ? __( 'Plugins with an update ready.', 'scout-build' ) : __( 'Nothing waiting.', 'scout-build' ),
		);
		$https = 0 === strpos( home_url(), 'https://' );
		$out[] = array(
			'label' => __( 'Security lock', 'scout-build' ),
			'value' => $https ? __( 'On', 'scout-build' ) : __( 'Off', 'scout-build' ),
			'state' => $https ? 'good' : 'bad',
			'note'  => $https ? __( 'The site loads over HTTPS.', 'scout-build' ) : __( 'The site address is not HTTPS.', 'scout-build' ),
		);
		$public = '1' === (string) get_option( 'blog_public' );
		$out[]  = array(
			'label' => __( 'Search engines', 'scout-build' ),
			'value' => $public ? __( 'Allowed', 'scout-build' ) : __( 'Hidden', 'scout-build' ),
			'state' => $public ? 'good' : 'warn',
			'note'  => $public ? __( 'Google can find the site.', 'scout-build' ) : __( 'Settings, Reading asks search engines to stay away. Right for staging, wrong for a live site.', 'scout-build' ),
		);

		$health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
		if ( is_array( $health ) ) {
			$critical = (int) ( $health['critical'] ?? 0 );
			$advised  = (int) ( $health['recommended'] ?? 0 );
			$out[]    = array(
				'label' => __( 'Site Health', 'scout-build' ),
				'value' => $critical ? __( 'Needs attention', 'scout-build' ) : __( 'Good', 'scout-build' ),
				'state' => $critical ? 'bad' : ( $advised > 3 ? 'warn' : 'good' ),
				/* translators: 1: critical count, 2: recommended count */
				'note'  => sprintf( __( '%1$d critical, %2$d recommended (WordPress Site Health).', 'scout-build' ), $critical, $advised ),
			);
		} else {
			$out[] = array(
				'label' => __( 'Site Health', 'scout-build' ),
				'value' => __( 'Not checked yet', 'scout-build' ),
				'state' => 'warn',
				'note'  => __( 'Open Tools, Site Health once to run WordPress\'s own check.', 'scout-build' ),
			);
		}
		return $out;
	}
}
