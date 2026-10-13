<?php
/**
 * Plugin Name:       Scout Analytics
 * Description:       Google Tag Manager (or a GA4 tag) on the live site with Google consent mode set up first, a small cookie notice that lets visitors opt out, and one way for every Scout feature to report clicks.
 * Version:           0.1.5
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-analytics
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-analytics
 *
 * @package Scout_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_ANALYTICS_VERSION', '0.1.5' );
define( 'SCOUT_ANALYTICS_FILE', __FILE__ );
define( 'SCOUT_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-analytics.php', 'scout-analytics' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-analytics.php';
require_once __DIR__ . '/includes/class-scout-analytics-admin.php';

Scout_Analytics::boot();
Scout_Analytics_Admin::boot();
