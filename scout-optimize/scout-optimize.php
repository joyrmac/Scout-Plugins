<?php
/**
 * Plugin Name:       Scout Optimize
 * Plugin URI:        https://scoutraleigh.com
 * Description:       Scout-owned media optimization. Compresses, resizes, and reformats images on upload, with batch optimization for existing libraries. Part of the Scout plugin suite.
 * Version:           0.7.0
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

define( 'SCOUT_OPTIMIZE_VERSION', '0.7.0' );
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

require_once SCOUT_OPTIMIZE_DIR . 'includes/class-scout-plugin-updater.php';
Scout_Plugin_Updater::boot( __FILE__, 'scout-optimize' );

require_once SCOUT_OPTIMIZE_DIR . 'includes/class-scout-optimize.php';

register_activation_hook( __FILE__, array( 'Scout_Optimize', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Optimize', 'deactivate' ) );

/**
 * Boot the plugin after all plugins are loaded (suite-friendly ordering).
 */
add_action( 'plugins_loaded', array( 'Scout_Optimize', 'instance' ) );
