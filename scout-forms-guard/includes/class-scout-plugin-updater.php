<?php
/**
 * Self-updating for the Scout plugin suite.
 *
 * Uses WordPress core's own update mechanism for plugins hosted outside
 * wordpress.org: a plugin declares an `Update URI:` header, and core then runs
 * the `update_plugins_<hostname>` filter for it on every update check. There is
 * no third-party updater library and nothing extra to install on a client site.
 * Updates appear on the normal Plugins screen, and WordPress's own per-plugin
 * "enable auto-updates" toggle works as it does for any other plugin.
 *
 * Releases live on the Scout Plugins repo. Each plugin is tagged and released
 * on its own, as `<slug>-v<version>`, carrying a `<slug>.zip` asset.
 *
 * This file ships identically inside every plugin in the suite so each one can
 * update itself even when the others are inactive. The first copy to load wins;
 * they are released together and kept in sync.
 *
 * @package Scout_Plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Scout_Plugin_Updater' ) ) {
	return;
}

/**
 * Points one plugin at its latest GitHub release.
 */
final class Scout_Plugin_Updater {

	/**
	 * Repository holding the suite, as owner/name.
	 *
	 * @var string
	 */
	const REPO = 'joyrmac/Scout-Plugins';

	/**
	 * How long a release lookup is cached. WordPress checks for updates roughly
	 * twice a day, and the unauthenticated GitHub API allows 60 requests an hour
	 * per IP, so this keeps a busy multisite well inside the limit.
	 *
	 * @var int
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Absolute path to the plugin's main file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Plugin folder name, e.g. "scout-seo".
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the plugin's main file.
	 * @param string $slug Plugin folder name.
	 */
	private function __construct( string $file, string $slug ) {
		$this->file = $file;
		$this->slug = $slug;
	}

	/**
	 * Wire a plugin up to the release feed.
	 *
	 * Call from the plugin's main file:
	 * `Scout_Plugin_Updater::boot( __FILE__, 'scout-seo' );`
	 *
	 * @param string $file Absolute path to the plugin's main file (__FILE__).
	 * @param string $slug Plugin folder name.
	 * @return void
	 */
	public static function boot( string $file, string $slug ): void {
		$updater = new self( $file, $slug );

		// Core derives this filter name from the Update URI header's hostname.
		add_filter( 'update_plugins_github.com', array( $updater, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $updater, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $updater, 'flush' ), 10, 0 );
	}

	/**
	 * Offer an update when the newest release is ahead of what is installed.
	 *
	 * @param array|false $update      Update payload from a previous filter.
	 * @param array       $plugin_data The plugin's headers, including Version.
	 * @param string      $plugin_file Plugin file relative to the plugins dir.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( $this->file ) !== $plugin_file ) {
			return $update; // A different Scout plugin is asking; leave it alone.
		}

		$installed = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
		$release   = $this->latest_release();

		if ( '' === $installed || null === $release ) {
			return $update;
		}
		if ( ! version_compare( $release['version'], $installed, '>' ) ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::REPO . '/' . $this->slug,
			'slug'         => $this->slug,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Fill the "View details" modal so it shows the release notes instead of an
	 * error. Only answers for this plugin's slug.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		$headers = get_file_data(
			$this->file,
			array(
				'Name'        => 'Plugin Name',
				'Author'      => 'Author',
				'RequiresPHP' => 'Requires PHP',
				'RequiresWP'  => 'Requires at least',
			)
		);

		return (object) array(
			'name'          => $headers['Name'],
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $headers['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $headers['RequiresWP'],
			'requires_php'  => $headers['RequiresPHP'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Drop the cached lookup after any plugin install or update, so a fresh
	 * check runs instead of re-offering a version that was just applied.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( $this->cache_key() );
	}

	/**
	 * The newest published release for this plugin.
	 *
	 * @return array{version:string,package:string,notes:string}|null Null when
	 *         there is no usable release or the lookup failed.
	 */
	private function latest_release(): ?array {
		$cached = get_transient( $this->cache_key() );
		if ( is_array( $cached ) ) {
			// An empty array is a cached "nothing to offer", which keeps a repo
			// with no releases yet from being polled on every check.
			return array() === $cached ? null : $cached;
		}

		$release = $this->fetch_release();
		set_transient( $this->cache_key(), ( null === $release ) ? array() : $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Ask GitHub for this plugin's releases and pick the newest usable one.
	 *
	 * @return array{version:string,package:string,notes:string}|null
	 */
	private function fetch_release(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases?per_page=100',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					// GitHub rejects API requests that do not identify themselves.
					'User-Agent' => 'ScoutPluginUpdater/' . $this->slug,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return null;
		}

		// Releases come back newest first, so the first match wins.
		$prefix = $this->slug . '-v';
		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				continue;
			}
			if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
				continue;
			}
			if ( 0 !== strpos( (string) $release['tag_name'], $prefix ) ) {
				continue;
			}

			$package = $this->zip_url( $release );
			if ( null === $package ) {
				continue; // Tagged but no built asset; not installable.
			}

			return array(
				'version' => substr( (string) $release['tag_name'], strlen( $prefix ) ),
				'package' => $package,
				'notes'   => isset( $release['body'] ) ? (string) $release['body'] : '',
			);
		}

		return null;
	}

	/**
	 * The download URL of a release's plugin zip.
	 *
	 * @param array $release Release payload from the GitHub API.
	 * @return string|null Null when the release carries no matching asset.
	 */
	private function zip_url( array $release ): ?string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return null;
		}
		foreach ( $release['assets'] as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && $this->slug . '.zip' === $asset['name'] ) {
				return (string) $asset['browser_download_url'];
			}
		}
		return null;
	}

	/**
	 * Transient key for this plugin's cached lookup.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		return 'scout_updater_' . $this->slug;
	}
}
