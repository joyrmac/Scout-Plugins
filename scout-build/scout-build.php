<?php
/**
 * Plugin Name:       Scout Build
 * Plugin URI:        https://scoutraleigh.com
 * Description:       One screen for the site's features: see what is on, turn features on or off (site operators only), roll one back, request a change, and check the site's health at a glance.
 * Version:           0.6.7
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-build
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-build
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prefixed SCOUT_HUB_ on purpose: the Scout Media theme already defines a
// SCOUT_BUILD_MENU constant for its own menu.
define( 'SCOUT_HUB_VERSION', '0.6.7' );
define( 'SCOUT_HUB_FILE', __FILE__ );
define( 'SCOUT_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUT_HUB_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-build.php', 'scout-build' );
	},
	0
);

require_once SCOUT_HUB_DIR . 'includes/class-scout-admin-ui.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-access.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-catalog.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-features.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-requests.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-health.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-robots.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-setup.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-admin.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-mail.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-dashboard.php';

Scout_Hub_Features::boot();
Scout_Hub_Requests::boot();
Scout_Hub_Admin::boot();
Scout_Hub_Health::boot();
Scout_Hub_Robots::boot();
Scout_Hub_Setup::boot();
Scout_Hub_Mail::boot();
Scout_Hub_Dashboard::boot();
