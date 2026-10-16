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

// Every Scout plugin ships this file. The class sits inside a conditional on
// purpose: a top-level class can be bound by OPcache when the file loads, even
// behind an early return, which crashed a site when another copy had already
// declared it ("Cannot declare class Scout_Plugin_Updater"). Inside the `if`,
// only the first copy declares it and every other copy is skipped.
if ( ! class_exists( 'Scout_Plugin_Updater', false ) ) :

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
	 * One shared copy of the release list for every Scout plugin on the site.
	 * Each plugin used to download the same list on its own, one after
	 * another, and a page that checked them all could wait past the host's
	 * 60-second limit (a 502 "Bad gateway" on WP Engine). Now the first plugin
	 * to ask downloads it once and the rest read it.
	 * @var string
	 */
	const FEED_KEY = 'scout_updater_feed';

	/** The version list the Release workflow publishes (see .github/workflows/release.yml). */
	const FEED_URL = 'https://github.com/joyrmac/Scout-Plugins/releases/download/feed/releases.json';

	/**
	 * The shared list for this request, so each copy reads it at most once.
	 * @var array|null
	 */
	private static $feed = null;

	/**
	 * Whether the last download worked. A failed one is retried after 15
	 * minutes instead of being remembered as "no update" for six hours.
	 * @var bool
	 */
	private static $feed_ok = true;

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
		add_filter( 'auto_update_plugin', array( $updater, 'auto_update' ), 10, 2 );
	}

	/**
	 * Install this plugin's updates automatically, so a release reaches the
	 * whole roster on WordPress's own twice-daily check with nobody logging in
	 * to press Update. Only ever answers for this plugin's own update offers;
	 * every other plugin's decision passes through untouched.
	 *
	 * Opt a site out by defining SCOUT_DISABLE_AUTO_UPDATES as true in
	 * wp-config.php; updates then wait on the Plugins screen as before.
	 *
	 * @param bool|null $update Whether to auto-update, as decided so far.
	 * @param object    $item   The update offer, carrying the id set by check().
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( ! isset( $item->id ) || 'github.com/' . self::REPO . '/' . $this->slug !== $item->id ) {
			return $update;
		}
		if ( defined( 'SCOUT_DISABLE_AUTO_UPDATES' ) && SCOUT_DISABLE_AUTO_UPDATES ) {
			return $update;
		}
		return true;
	}

	/**
	 * Report the newest release. WordPress offers it as an update when it is
	 * ahead of what is installed, and lists the plugin as up to date otherwise.
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
		// Answer even when nothing is newer: WordPress then lists the plugin as
		// up to date (no_update), which is what lets the Plugins screen show its
		// auto-update state instead of a blank column.
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
		if ( self::$feed_ok ) {
			set_transient( $this->cache_key(), ( null === $release ) ? array() : $release, self::CACHE_TTL );
		}

		return $release;
	}

	/**
	 * Ask GitHub for this plugin's releases and pick the newest usable one.
	 *
	 * @return array{version:string,package:string,notes:string}|null
	 */
	private function fetch_release(): ?array {
		$releases = self::feed();
		// GitHub sorts by tag name as text ("v0.4.9" before "v0.4.11"), so
		// compare version numbers and keep the highest.
		$prefix = $this->slug . '-v';
		$best   = null;
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

			$version = substr( (string) $release['tag_name'], strlen( $prefix ) );
			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$best = array(
					'version' => $version,
					'package' => $package,
					'notes'   => isset( $release['body'] ) ? (string) $release['body'] : '',
				);
			}
		}

		return $best;
	}

	/**
	 * Every release in the suite, newest first, trimmed to what the updater
	 * reads. Downloaded once and shared by all Scout plugins.
	 *
	 * @return array<int, array>
	 */
	private static function feed(): array {
		if ( is_array( self::$feed ) ) {
			return self::$feed;
		}
		$cached = get_transient( self::FEED_KEY );
		if ( is_array( $cached ) && isset( $cached['ok'], $cached['releases'] ) ) {
			self::$feed_ok = (bool) $cached['ok'];
			return self::$feed = (array) $cached['releases'];
		}
		// First choice: the version list the Release workflow attaches to the
		// "feed" release. It is a plain download, so it is not held to GitHub's
		// 60-calls-an-hour API limit that shared hosts run out of.
		$data = null;
		$response = wp_remote_get( self::FEED_URL, array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'ScoutPluginUpdater' ) ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$data    = ( is_array( $decoded ) && $decoded ) ? $decoded : null;
		}
		$ok = is_array( $data );

		// Fallback: the API. GitHub pages the list 100 at a time and sorts it by
		// tag name, not by date, so a plugin's newest release can sit on a later
		// page. Read up to three pages (300 releases); stop at the first short page.
		for ( $page = 1; ! $ok && $page <= 3; $page++ ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases?per_page=100&page=' . $page,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						// GitHub rejects API requests that do not identify themselves.
						'User-Agent' => 'ScoutPluginUpdater',
					),
				)
			);
			$batch = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
			if ( ! is_array( $batch ) ) {
				$ok = 1 < $page; // A later page failing still leaves a usable list.
				break;
			}
			$data = array_merge( (array) $data, $batch );
			if ( count( $batch ) < 100 ) {
				$ok = true;
				break;
			}
			$ok = 3 === $page;
		}
		$data = (array) $data;
		$list = array();
		foreach ( $ok ? $data : array() as $release ) {
			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				continue;
			}
			$assets = array();
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) ) {
					$assets[] = array( 'name' => (string) $asset['name'], 'browser_download_url' => (string) $asset['browser_download_url'] );
				}
			}
			$list[] = array(
				'tag_name'   => (string) $release['tag_name'],
				'draft'      => ! empty( $release['draft'] ),
				'prerelease' => ! empty( $release['prerelease'] ),
				'body'       => substr( (string) ( $release['body'] ?? '' ), 0, 1500 ),
				'assets'     => $assets,
			);
		}
		set_transient( self::FEED_KEY, array( 'ok' => $ok, 'releases' => $list ), $ok ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );
		self::$feed_ok = $ok;
		return self::$feed = $list;
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

endif;
