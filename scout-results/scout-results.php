<?php
/**
 * Plugin Name:       Scout Case Results
 * Description:       A law firm's case results with the firm's approval on every one and the state disclaimer printed next to every result, shown in a grid visitors can filter by practice area.
 * Version:           0.1.9
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-results
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-results
 *
 * @package Scout_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_RESULTS_VERSION', '0.1.9' );
define( 'SCOUT_RESULTS_FILE', __FILE__ );
define( 'SCOUT_RESULTS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-results.php', 'scout-results' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-results.php';
require_once __DIR__ . '/includes/class-scout-results-editor.php';
require_once __DIR__ . '/includes/class-scout-results-render.php';
require_once __DIR__ . '/includes/class-scout-results-admin.php';

Scout_Results::boot();
Scout_Results_Editor::boot();
Scout_Results_Render::boot();
Scout_Results_Admin::boot();
