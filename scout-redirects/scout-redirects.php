<?php
/**
 * Plugin Name:       Scout Redirects
 * Description:       Forward old page addresses to new ones, see which redirects get used, and catch missing pages people ask for so each one can become a redirect in one click.
 * Version:           0.1.6
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-redirects
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-redirects
 *
 * @package Scout_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_REDIRECTS_VERSION', '0.1.6' );
define( 'SCOUT_REDIRECTS_FILE', __FILE__ );
define( 'SCOUT_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-redirects.php', 'scout-redirects' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-redirects-store.php';
require_once __DIR__ . '/includes/class-scout-redirects.php';
require_once __DIR__ . '/includes/class-scout-redirects-admin.php';

register_activation_hook( __FILE__, array( 'Scout_Redirects_Store', 'install' ) );

Scout_Redirects::boot();
Scout_Redirects_Admin::boot();
