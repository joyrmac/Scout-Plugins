<?php
/**
 * Plugin Name:       Scout Writing
 * Description:       A private writing room in the dashboard: the client's writing prompts in steps, a place to answer each one that saves as they type, voice memos, Send to Scout, and a welcome at the top of the dashboard with their next prompt. Prompts and brand come from the theme's scout-setup.json.
 * Version:           0.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-writing
 * Update URI:        https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-writing
 *
 * @package Scout_Writing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_WRITING_VERSION', '0.1.4' );
define( 'SCOUT_WRITING_FILE', __FILE__ );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-writing.php', 'scout-writing' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/room.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/migrate.php';

// Writing from the Bliss Boogie room moves over the moment this is switched on.
register_activation_hook(
	__FILE__,
	static function () {
		if ( scout_writing_has_legacy() ) {
			scout_writing_migrate();
		}
	}
);
