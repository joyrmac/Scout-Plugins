<?php
/**
 * Plugin Name:       Scout Site Check
 * Description:       A free website check visitors can run from your site. It checks speed, security, page titles, business info for Google, phone layout, AI crawlers, the sitemap and photo sizes, shows the result on the page, emails the full report, and saves every request to an inbox.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-sitecheck
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-sitecheck
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_SITECHECK_VERSION', '0.2.0' );
define( 'SCOUT_SITECHECK_FILE', __FILE__ );
define( 'SCOUT_SITECHECK_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-sitecheck.php', 'scout-sitecheck' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-sitecheck.php';
require_once __DIR__ . '/includes/class-scout-sitecheck-checks.php';
require_once __DIR__ . '/includes/class-scout-sitecheck-report.php';
require_once __DIR__ . '/includes/class-scout-sitecheck-admin.php';

Scout_Sitecheck::boot();
Scout_Sitecheck_Admin::boot();
