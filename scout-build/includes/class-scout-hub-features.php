<?php
/**
 * Turning features on and off, and rolling one back.
 *
 * - Turn on: install the latest release from GitHub if the plugin is missing,
 *   then activate it (and any feature it requires).
 * - Turn off: deactivate. Nothing is deleted, so settings and content stay.
 * - Go back: reinstall the release before the one installed, and hold
 *   auto-updates for that plugin until a release newer than the one we left.
 *
 * Every action needs an operator (see Scout_Hub_Access) and is written to a
 * short log shown on the Features screen.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Features {

	const ACTION   = 'scout_hub_feature';
	const HOLD_KEY = 'scout_hub_update_holds';
	const LOG_KEY  = 'scout_hub_log';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'scout_hub_refresh_versions', array( __CLASS__, 'refresh' ) );
		add_action( 'admin_init', array( __CLASS__, 'auto_update_sweep' ) );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'hold_updates' ), 20, 2 );
	}

	public static function file( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}

	/**
	 * Status for one feature.
	 *
	 * @return array{state:string, version:string, update:string, previous:string, checked:int}
	 */
	public static function status( string $slug ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file    = self::file( $slug );
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return array( 'state' => 'missing', 'version' => '', 'update' => '', 'previous' => '', 'checked' => 0 );
		}
		$version  = (string) $plugins[ $file ]['Version'];
		$updates  = get_site_transient( 'update_plugins' );
		$update   = ( is_object( $updates ) && isset( $updates->response[ $file ]->new_version ) ) ? (string) $updates->response[ $file ]->new_version : '';
		$previous = '';
		foreach ( Scout_Hub_Catalog::releases( $slug ) as $release ) {
			if ( version_compare( $release['version'], $version, '<' ) ) {
				$previous = $release['version'];
				break;
			}
		}
		return array(
			'state'    => is_plugin_active( $file ) ? 'on' : 'off',
			'version'  => $version,
			'update'   => $update,
			'previous' => $previous,
			// When WordPress last asked for new versions: "Up to date" only shows once it has.
			'checked'  => is_object( $updates ) ? (int) ( $updates->last_checked ?? 0 ) : 0,
		);
	}

	/** WordPress's own one-click update for this feature, or '' when it cannot run here. */
	public static function update_url( string $slug ): string {
		$file = self::file( $slug );
		if ( '' === $file || ! current_user_can( 'update_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return '';
		}
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
	}

	/** The form button for one action, posted to admin-post.php. */
	public static function button( string $slug, string $do, string $label, string $class = 'button' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-inline">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. wp_nonce_field( self::ACTION . $slug . $do, '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Ask again for new features and versions. Each Scout plugin keeps its own
	 * release lookup for six hours, and WordPress keeps its update list for
	 * twelve: drop both, so a release published minutes ago shows as
	 * "Update ready" now.
	 */
	public static function refresh(): void {
		Scout_Hub_Catalog::flush();
		Scout_Hub_Health::forget_scan();
		delete_transient( 'scout_updater_feed' ); // The release list every Scout plugin shares.
		foreach ( array_merge( array( 'scout-build' ), array_keys( Scout_Hub_Catalog::features() ) ) as $feature_slug ) {
			delete_transient( 'scout_updater_' . $feature_slug );
		}
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded when this runs from cron.
		}
		wp_clean_plugins_cache( true );
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();
		// The theme too, when it updates itself from its own repo (Scout_Hub_Themes).
		Scout_Hub_Themes::forget();
		delete_site_transient( 'update_themes' );
		wp_update_themes();
	}

	/** How old the last version check may get before the Features screen asks again by itself. */
	const STALE_AFTER = HOUR_IN_SECONDS;

	/**
	 * When the Features screen opens on a version check older than an hour,
	 * queue a fresh one in the background (WordPress cron), so the page never
	 * waits on it and the "Up to date" marks are current on the next look.
	 */
	public static function refresh_if_stale( int $checked ): void {
		if ( $checked && time() - $checked < self::STALE_AFTER ) {
			return;
		}
		if ( ! wp_next_scheduled( 'scout_hub_refresh_versions' ) ) {
			wp_schedule_single_event( time(), 'scout_hub_refresh_versions' );
		}
	}

	public static function handle(): void {
		$slug = sanitize_key( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) );
		$do   = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );
		check_admin_referer( self::ACTION . $slug . $do );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change its features.', 'scout-build' ), 403 );
		}
		$features = Scout_Hub_Catalog::features();
		if ( 'refresh' === $do ) {
			self::refresh();
			self::back( __( 'Checked for new features and versions.', 'scout-build' ) );
		}
		if ( 'refill' === $do ) {
			// The setup file runs once per theme version. A save from a page that was
			// open before the theme update can blank what it filled (Hair by Patrick,
			// Oct 15), so this lets it run again. It still fills blanks only.
			delete_option( Scout_Hub_Setup::DONE );
			Scout_Hub_Setup::run();
			self::back( __( 'Filled in from the theme\'s setup file. Anything already set stayed as it was.', 'scout-build' ) );
		}
		if ( ! isset( $features[ $slug ] ) ) {
			self::back( __( 'That feature is not in the list.', 'scout-build' ), 'error' );
		}
		$name = $features[ $slug ]['name'];

		switch ( $do ) {
			case 'on':
				$result = self::turn_on( $slug, $features );
				break;
			case 'off':
				deactivate_plugins( self::file( $slug ) );
				$result = true;
				break;
			case 'back':
				$result = self::go_back( $slug );
				break;
			default:
				$result = new WP_Error( 'scout_hub', __( 'Unknown action.', 'scout-build' ) );
		}

		if ( is_wp_error( $result ) ) {
			self::log( $slug, $do, false, $result->get_error_message() );
			/* translators: 1: feature name, 2: error */
			self::back( sprintf( __( '%1$s: %2$s', 'scout-build' ), $name, $result->get_error_message() ), 'error' );
		}
		self::log( $slug, $do, true, '' );
		$done = array(
			/* translators: %s: feature name */
			'on'   => __( '%s is on.', 'scout-build' ),
			/* translators: %s: feature name */
			'off'  => __( '%s is off. Its settings and content are kept.', 'scout-build' ),
			/* translators: %s: feature name */
			'back' => __( '%s is back on the previous version. Automatic updates for it are paused until a newer release comes out.', 'scout-build' ),
		);
		self::back( sprintf( $done[ $do ], $name ) );
	}

	/** @return true|WP_Error */
	private static function turn_on( string $slug, array $features, int $depth = 0 ) {
		if ( ! empty( $features[ $slug ]['blocked'] ) ) {
			return new WP_Error( 'scout_hub', $features[ $slug ]['block_note'] ? $features[ $slug ]['block_note'] : __( 'This feature is not used with this site\'s theme.', 'scout-build' ) );
		}
		if ( $depth > 3 ) {
			return new WP_Error( 'scout_hub', __( 'Too many required features in a row.', 'scout-build' ) );
		}
		foreach ( $features[ $slug ]['requires'] as $need ) {
			if ( isset( $features[ $need ] ) && ! is_plugin_active( self::file( $need ) ) ) {
				$r = self::turn_on( $need, $features, $depth + 1 );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		if ( 'missing' === self::status( $slug )['state'] ) {
			$releases = Scout_Hub_Catalog::releases( $slug );
			if ( ! $releases ) {
				// The release list is remembered for hours; a feature released since
				// then is missing from it. Ask GitHub again once before giving up.
				Scout_Hub_Catalog::flush();
				$releases = Scout_Hub_Catalog::releases( $slug );
			}
			if ( ! $releases ) {
				return Scout_Hub_Catalog::unreachable()
					? new WP_Error( 'scout_hub', __( 'Could not reach GitHub to download it. Try again in a few minutes.', 'scout-build' ) )
					: new WP_Error( 'scout_hub', __( 'No release is published yet, so it cannot be installed from here.', 'scout-build' ) );
			}
			$r = self::install( $releases[0]['package'], false );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$r = activate_plugin( self::file( $slug ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::auto_update( $slug );
		return true;
	}

	/**
	 * Every Scout feature keeps itself current: turning one on also ticks
	 * WordPress's auto-update box for it, the same as the plugins installed
	 * with the site. A site that blocks auto-updates (or file changes) is left alone.
	 */
	public static function auto_update( string $slug ): void {
		$file = self::file( $slug );
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( '' === $file || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			return;
		}
		$on = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( $file, $on, true ) ) {
			$on[] = $file;
			update_site_option( 'auto_update_plugins', $on );
		}
	}

	/**
	 * One time per site: features turned on before Build 0.3.4 never had their
	 * auto-update box ticked. Tick it for every active Scout feature once.
	 */
	public static function auto_update_sweep(): void {
		if ( get_option( 'scout_hub_auto_update_sweep' ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$features = Scout_Hub_Catalog::features();
		if ( ! $features ) {
			return; // Catalog not reachable right now: try again on the next screen.
		}
		foreach ( array_keys( $features ) as $slug ) {
			if ( is_plugin_active( self::file( $slug ) ) ) {
				self::auto_update( $slug );
			}
		}
		self::auto_update( 'scout-build' );
		update_option( 'scout_hub_auto_update_sweep', time(), false );
	}

	/** @return true|WP_Error */
	private static function go_back( string $slug ) {
		$status = self::status( $slug );
		if ( 'missing' === $status['state'] || '' === $status['previous'] ) {
			return new WP_Error( 'scout_hub', __( 'There is no earlier release to go back to.', 'scout-build' ) );
		}
		$package = '';
		foreach ( Scout_Hub_Catalog::releases( $slug ) as $release ) {
			if ( $release['version'] === $status['previous'] ) {
				$package = $release['package'];
			}
		}
		$was_on = 'on' === $status['state'];
		$r      = self::install( $package, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$holds          = (array) get_option( self::HOLD_KEY, array() );
		$holds[ $slug ] = $status['version']; // Skip this version and anything older.
		update_option( self::HOLD_KEY, $holds, false );
		if ( $was_on && ! is_plugin_active( self::file( $slug ) ) ) {
			$a = activate_plugin( self::file( $slug ) );
			if ( is_wp_error( $a ) ) {
				return $a;
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function install( string $package, bool $overwrite ) {
		if ( ! Scout_Hub_Access::can_install() ) {
			return new WP_Error( 'scout_hub', __( 'This site does not allow installing plugins from the dashboard.', 'scout-build' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'scout_hub', __( 'The host needs file access set to direct before plugins can be installed from here.', 'scout-build' ) );
		}
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $package, array( 'overwrite_package' => $overwrite ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			$errors = $upgrader->skin->get_errors();
			return ( $errors instanceof WP_Error && $errors->has_errors() ) ? $errors : new WP_Error( 'scout_hub', __( 'The install did not finish.', 'scout-build' ) );
		}
		wp_clean_plugins_cache( true );
		return true;
	}

	/**
	 * Hold automatic updates for a rolled-back plugin until a release newer
	 * than the one we rolled back from is offered.
	 */
	public static function hold_updates( $update, $item ) {
		$slug  = isset( $item->slug ) ? (string) $item->slug : '';
		$holds = (array) get_option( self::HOLD_KEY, array() );
		if ( '' === $slug || ! isset( $holds[ $slug ] ) ) {
			return $update;
		}
		$offered = isset( $item->new_version ) ? (string) $item->new_version : '';
		if ( '' !== $offered && version_compare( $offered, $holds[ $slug ], '>' ) ) {
			unset( $holds[ $slug ] );
			update_option( self::HOLD_KEY, $holds, false );
			return $update;
		}
		return false;
	}

	public static function held( string $slug ): string {
		$holds = (array) get_option( self::HOLD_KEY, array() );
		return (string) ( $holds[ $slug ] ?? '' );
	}

	private static function log( string $slug, string $do, bool $ok, string $note ): void {
		$log   = (array) get_option( self::LOG_KEY, array() );
		$user  = wp_get_current_user();
		array_unshift(
			$log,
			array(
				'time' => time(),
				'who'  => $user->display_name,
				'slug' => $slug,
				'do'   => $do,
				'ok'   => $ok,
				'note' => $note,
			)
		);
		update_option( self::LOG_KEY, array_slice( $log, 0, 30 ), false );
	}

	public static function recent_log(): array {
		return (array) get_option( self::LOG_KEY, array() );
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'features' ) );
		exit;
	}
}
