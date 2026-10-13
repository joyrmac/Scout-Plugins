<?php
/**
 * Plugin Name:       Scout Optimize
 * Plugin URI:        https://scoutraleigh.com
 * Description:       Scout-owned media optimization. Compresses, resizes, and reformats images on upload, with batch optimization for existing libraries. Part of the Scout plugin suite.
 * Version:           0.8.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           Proprietary - Scout client sites only. Not for redistribution.
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-optimize
 * Text Domain:       scout-optimize
 *
 * @package Scout_Optimize
 */

// Direct access hardening.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_OPTIMIZE_VERSION', '0.8.3' );
define( 'SCOUT_OPTIMIZE_FILE', __FILE__ );
define( 'SCOUT_OPTIMIZE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUT_OPTIMIZE_URL', plugin_dir_url( __FILE__ ) );

/**
 * The capability that gates every Scout Optimize admin surface.
 *
 * Granted on activation to the administrator role by default; per the vision
 * doc (§3.7) the Stage F hardening pass may narrow this to a Scout-only role
 * so client administrators never see the controls.
 */
define( 'SCOUT_OPTIMIZE_CAP', 'manage_scout_optimize' );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-optimize.php', 'scout-optimize' );
	},
	0
);

require_once SCOUT_OPTIMIZE_DIR . 'includes/class-scout-admin-ui.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/class-scout-optimize.php';

register_activation_hook( __FILE__, array( 'Scout_Optimize', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Optimize', 'deactivate' ) );

/**
 * Boot the plugin after all plugins are loaded (suite-friendly ordering).
 */
add_action( 'plugins_loaded', array( 'Scout_Optimize', 'instance' ) );

// The Scout admin look on the optimizer's two screens.
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( false !== strpos( (string) $hook, 'scout-optimize' ) ) {
			Scout_Admin_UI::enqueue( SCOUT_OPTIMIZE_URL . 'assets/scout-admin.css' );
		}
	}
);
