<?php
/**
 * Plugin Name:       Scout Partners
 * Description:       Partners and sponsors by level (such as Founding, National, Regional), each with a logo, a link, the regions they back and the places on the site they appear. Change a partner once and every placement follows, with a count of clicks each month to show them.
 * Version:           0.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-partners
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-partners
 *
 * @package Scout_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_PARTNERS_VERSION', '0.1.2' );
define( 'SCOUT_PARTNERS_FILE', __FILE__ );
define( 'SCOUT_PARTNERS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-partners.php', 'scout-partners' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-partners.php';
require_once __DIR__ . '/includes/class-scout-partners-front.php';
require_once __DIR__ . '/includes/class-scout-partners-admin.php';

Scout_Partners::boot();
Scout_Partners_Front::boot();
Scout_Partners_Admin::boot();
