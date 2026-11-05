<?php
/**
 * Plugin Name:       Scout Build
 * Plugin URI:        https://scoutraleigh.com
 * Description:       The Scout menu on every site: Home with the Today list, an Inbox with everything waiting on a person, Site health, and for operators Scout tools: features, every update in one place, and the Site log.
 * Version:           0.13.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * Author URI:        https://scoutraleigh.com
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-build
 * Update URI:        https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-build
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prefixed SCOUT_HUB_ on purpose: the Scout Media theme already defines a
// SCOUT_BUILD_MENU constant for its own menu.
define( 'SCOUT_HUB_VERSION', '0.13.0' );
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
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-today.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-inbox.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-mail.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-form-mail.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-dashboard.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-themes.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-rings.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-approval.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-watch.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-wp-health.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-security.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-log.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-log-api.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-privacy.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-brand.php';
require_once SCOUT_HUB_DIR . 'includes/class-scout-hub-report.php';

Scout_Hub_Features::boot();
Scout_Hub_Requests::boot();
Scout_Hub_Admin::boot();
Scout_Hub_Today::boot();
Scout_Hub_Inbox::boot();
Scout_Hub_Health::boot();
Scout_Hub_Robots::boot();
Scout_Hub_Setup::boot();
Scout_Hub_Mail::boot();
Scout_Hub_Form_Mail::boot();
Scout_Hub_Dashboard::boot();
Scout_Hub_Themes::boot();
Scout_Hub_Rings::boot();
Scout_Hub_Approval::boot();
Scout_Hub_Watch::boot();
Scout_Hub_WP_Health::boot();
Scout_Hub_Security::boot();
Scout_Hub_Log::boot();
Scout_Hub_Log_API::boot();
Scout_Hub_Privacy::boot();
Scout_Hub_Brand::boot();
Scout_Hub_Report::boot();

register_deactivation_hook( __FILE__, array( 'Scout_Hub_Watch', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Hub_Inbox', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Hub_Report', 'unschedule' ) );
