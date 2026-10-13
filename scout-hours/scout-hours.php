<?php
/**
 * Plugin Name:       Scout Hours
 * Description:       Weekly hours and holiday closures in one place: an hours table and a live "Open now" line for the site, opening hours in the schema Google reads, an optional notice bar on closure days, and a reminder to set the same hours on Google.
 * Version:           0.1.5
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-hours
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-hours
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_HOURS_VERSION', '0.1.5' );
define( 'SCOUT_HOURS_FILE', __FILE__ );
define( 'SCOUT_HOURS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-hours.php', 'scout-hours' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-hours.php';
require_once __DIR__ . '/includes/class-scout-hours-schema.php';
require_once __DIR__ . '/includes/class-scout-hours-front.php';
require_once __DIR__ . '/includes/class-scout-hours-admin.php';
require_once __DIR__ . '/includes/functions.php';

Scout_Hours_Schema::boot();
Scout_Hours_Front::boot();
Scout_Hours_Admin::boot();
