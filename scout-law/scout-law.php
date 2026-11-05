<?php
/**
 * Plugin Name:       Scout Law Firm Tools
 * Description:       The pieces a law firm's website needs to stay careful: the firm's disclaimer lines set once and shown everywhere they belong, approval before publishing for chosen pages and posts, with every approval kept as the firm's advertising record, "Reviewed by" stamps on a review schedule, and form entries that delete themselves after 90 days.
 * Version:           0.4.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-law
 * Update URI:        https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-law
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_LAW_VERSION', '0.4.2' );
define( 'SCOUT_LAW_FILE', __FILE__ );
define( 'SCOUT_LAW_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-law.php', 'scout-law' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-law-disclaimers.php';
require_once __DIR__ . '/includes/class-scout-law-front.php';
require_once __DIR__ . '/includes/class-scout-law-admin.php';
require_once __DIR__ . '/includes/class-scout-law-approvals.php';
require_once __DIR__ . '/includes/class-scout-law-approvals-admin.php';
require_once __DIR__ . '/includes/class-scout-law-reviews.php';
require_once __DIR__ . '/includes/class-scout-law-reviews-admin.php';
require_once __DIR__ . '/includes/class-scout-law-entries.php';
require_once __DIR__ . '/includes/class-scout-law-entries-admin.php';

Scout_Law_Front::boot();
Scout_Law_Admin::boot();
Scout_Law_Approvals::boot();
Scout_Law_Reviews::boot();
Scout_Law_Approvals_Admin::boot();
Scout_Law_Reviews_Admin::boot();
Scout_Law_Entries::boot();
Scout_Law_Entries_Admin::boot();

register_deactivation_hook( __FILE__, array( 'Scout_Law_Entries', 'unschedule' ) );
