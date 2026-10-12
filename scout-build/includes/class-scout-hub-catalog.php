<?php
/**
 * The list of features, and the releases each one can be installed from.
 *
 * The list is `catalog.json` at the root of the Scout Plugins repo, fetched
 * from GitHub and cached for 12 hours, so a new feature shows up on every site
 * without a hub release. The copy bundled with the plugin is the fallback when
 * GitHub cannot be reached.
 *
 * Releases are read from the same GitHub releases the updater uses: tags named
 * `<slug>-v<version>`, each carrying a `<slug>.zip` asset.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Catalog {

	const REPO          = 'joyrmac/Scout-Plugins';
	const CATALOG_URL   = 'https://raw.githubusercontent.com/joyrmac/Scout-Plugins/main/catalog.json';
	const CATALOG_KEY   = 'scout_hub_catalog';
	const RELEASES_KEY  = 'scout_hub_releases';
	const CATALOG_TTL   = 12 * HOUR_IN_SECONDS;
	const RELEASES_TTL  = 6 * HOUR_IN_SECONDS;

	/**
	 * Every feature, keyed by plugin slug.
	 *
	 * @return array<string, array{slug:string, name:string, summary:string, tier:string, law:bool, requires:string[], hidden:bool}>
	 */
	public static function features(): array {
		$data = get_transient( self::CATALOG_KEY );
		if ( ! is_array( $data ) ) {
			$data     = null;
			$response = wp_remote_get( self::CATALOG_URL, array( 'timeout' => 8 ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $decoded ) && ! empty( $decoded['features'] ) ) {
					$data = $decoded;
				}
			}
			if ( null === $data ) {
				$local = json_decode( (string) file_get_contents( SCOUT_HUB_DIR . 'catalog.json' ), true );
				$data  = is_array( $local ) ? $local : array( 'features' => array() );
				set_transient( self::CATALOG_KEY, $data, HOUR_IN_SECONDS ); // Retry sooner after a failure.
			} else {
				set_transient( self::CATALOG_KEY, $data, self::CATALOG_TTL );
			}
		}

		$out = array();
		foreach ( (array) ( $data['features'] ?? array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug || 'scout-build' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'     => $slug,
				'name'     => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
				'summary'  => sanitize_text_field( (string) ( $row['summary'] ?? '' ) ),
				'tier'     => sanitize_key( (string) ( $row['tier'] ?? 'addon' ) ),
				'law'      => ! empty( $row['law'] ),
				'requires' => array_map( 'sanitize_key', (array) ( $row['requires'] ?? array() ) ),
				'hidden'   => ! empty( $row['client_specific'] ),
				'blocked'  => in_array( get_template(), array_map( 'sanitize_key', (array) ( $row['not_with_themes'] ?? array() ) ), true ),
				'block_note' => sanitize_text_field( (string) ( $row['not_with_note'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Installable releases for one plugin, newest first.
	 *
	 * @return array<int, array{version:string, package:string}>
	 */
	public static function releases( string $slug ): array {
		$all = self::all_releases();
		return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/** True when the last release lookup could not reach GitHub. */
	public static function unreachable(): bool {
		return ! empty( self::all_releases()['_unreachable'] );
	}

	/** @return array<string, array<int, array{version:string, package:string}>> */
	private static function all_releases(): array {
		$cached = get_transient( self::RELEASES_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out      = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases?per_page=100',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ScoutBuild/' . SCOUT_HUB_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::RELEASES_KEY, array( '_unreachable' => true ), 15 * MINUTE_IN_SECONDS );
			return array( '_unreachable' => true );
		}
		foreach ( (array) json_decode( wp_remote_retrieve_body( $response ), true ) as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
				continue;
			}
			if ( ! preg_match( '/^(scout-[a-z0-9-]+)-v(\d[\w.\-]*)$/', (string) ( $release['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( ( $asset['name'] ?? '' ) === $m[1] . '.zip' && ! empty( $asset['browser_download_url'] ) ) {
					$out[ $m[1] ][] = array(
						'version' => $m[2],
						'package' => (string) $asset['browser_download_url'],
					);
				}
			}
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
		}
		unset( $list );
		set_transient( self::RELEASES_KEY, $out, self::RELEASES_TTL );
		return $out;
	}

	public static function flush(): void {
		delete_transient( self::CATALOG_KEY );
		delete_transient( self::RELEASES_KEY );
	}
}
