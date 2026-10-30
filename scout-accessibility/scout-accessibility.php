<?php
/**
 * Plugin Name:       Scout Accessibility
 * Description:       A plain, honest accessibility statement page, a footer link to it, and weekly checks for missing image descriptions, skipped headings and unlabeled form fields, plus an at-launch checklist for the checks that need a person.
 * Version:           0.1.12
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-accessibility
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-accessibility
 *
 * @package Scout_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_ACCESSIBILITY_VERSION', '0.1.12' );
define( 'SCOUT_ACCESSIBILITY_FILE', __FILE__ );
define( 'SCOUT_ACCESSIBILITY_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-accessibility.php', 'scout-accessibility' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-accessibility.php';
require_once __DIR__ . '/includes/class-scout-accessibility-checks.php';
require_once __DIR__ . '/includes/class-scout-accessibility-admin.php';

register_activation_hook( __FILE__, array( 'Scout_Accessibility_Checks', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Accessibility_Checks', 'unschedule' ) );

Scout_Accessibility::boot();
Scout_Accessibility_Checks::boot();
Scout_Accessibility_Admin::boot();
